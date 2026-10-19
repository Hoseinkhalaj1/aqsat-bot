<?php
/* ================= Schema migration (v2: incomes & allocation) ================= */
class Migrate {
    const VERSION = 3;
    public static function run() {
        try {
            if ((int)DB::setting('schema_v') >= self::VERSION) return;
        } catch (Throwable $e) { return; } // tables not installed yet
        $pdo = DB::pdo();
        $e = "ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        if ((int)DB::setting('schema_v') >= 2) { self::v3($pdo, $e); return; }
        $cols = array_column(DB::all("SHOW COLUMNS FROM users"), 'Field');
        if (!in_array('alloc_mode', $cols, true)) $pdo->exec("ALTER TABLE users ADD alloc_mode ENUM('auto','manual') NOT NULL DEFAULT 'auto'");
        if (!in_array('income_remind', $cols, true)) $pdo->exec("ALTER TABLE users ADD income_remind TINYINT(1) NOT NULL DEFAULT 1");
        $e = "ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        $pdo->exec("CREATE TABLE IF NOT EXISTS incomes (id INT AUTO_INCREMENT PRIMARY KEY, user_id BIGINT NOT NULL, title VARCHAR(100) NOT NULL,
            amount BIGINT NOT NULL, day TINYINT NOT NULL, active TINYINT(1) NOT NULL DEFAULT 1, created_at DATETIME NOT NULL, INDEX (user_id)) $e");
        $pdo->exec("CREATE TABLE IF NOT EXISTS income_overrides (income_id INT NOT NULL, ym VARCHAR(7) NOT NULL, amount BIGINT NOT NULL,
            PRIMARY KEY (income_id, ym), CONSTRAINT fk_iov FOREIGN KEY (income_id) REFERENCES incomes(id) ON DELETE CASCADE) $e");
        $pdo->exec("CREATE TABLE IF NOT EXISTS alloc_map (user_id BIGINT NOT NULL, obligation_id INT NOT NULL, income_id INT NOT NULL,
            PRIMARY KEY (user_id, obligation_id),
            CONSTRAINT fk_am_ob FOREIGN KEY (obligation_id) REFERENCES obligations(id) ON DELETE CASCADE,
            CONSTRAINT fk_am_inc FOREIGN KEY (income_id) REFERENCES incomes(id) ON DELETE CASCADE) $e");
        $pdo->exec("CREATE TABLE IF NOT EXISTS set_aside (user_id BIGINT NOT NULL, income_id INT NOT NULL, ym VARCHAR(7) NOT NULL, at DATETIME NOT NULL,
            PRIMARY KEY (user_id, income_id, ym), CONSTRAINT fk_sa_inc FOREIGN KEY (income_id) REFERENCES incomes(id) ON DELETE CASCADE) $e");
        $pdo->exec("CREATE TABLE IF NOT EXISTS income_log (user_id BIGINT NOT NULL, income_id INT NOT NULL, sent_date DATE NOT NULL,
            PRIMARY KEY (user_id, income_id, sent_date)) $e");
        self::v3($pdo, $e);
    }
    private static function v3(PDO $pdo, string $e) {
        $pdo->exec("CREATE TABLE IF NOT EXISTS deposits (user_id BIGINT NOT NULL, income_id INT NOT NULL, ym VARCHAR(7) NOT NULL, amount BIGINT NOT NULL, at DATETIME NOT NULL,
            PRIMARY KEY (user_id, income_id, ym), CONSTRAINT fk_dep_inc FOREIGN KEY (income_id) REFERENCES incomes(id) ON DELETE CASCADE) $e");
        DB::setting('schema_v', self::VERSION);
    }
}

/* ================= Allocation engine ================= */
class Plan {
    public static function incomes(int $uid, bool $all = false): array {
        return DB::all("SELECT * FROM incomes WHERE user_id=?" . ($all ? '' : " AND active=1") . " ORDER BY day, id", [$uid]);
    }
    public static function income(int $id, int $uid): ?array { return DB::row("SELECT * FROM incomes WHERE id=? AND user_id=?", [$id, $uid]); }

    /** amount of an income in a given jalali month (override or default) */
    public static function amountFor(array $inc, string $ym): int {
        $o = DB::val("SELECT amount FROM income_overrides WHERE income_id=? AND ym=?", [$inc['id'], $ym]);
        return $o !== null ? (int)$o : (int)$inc['amount'];
    }

    /** deposit occurrences of all active incomes for one month */
    private static function occs(array $incomes, int $jy, int $jm): array {
        $ym = J::ym($jy, $jm); $out = [];
        foreach ($incomes as $inc) {
            $out[] = ['key' => $inc['id'] . '|' . $ym, 'income_id' => (int)$inc['id'], 'title' => $inc['title'], 'ym' => $ym,
                'date' => J::toG($jy, $jm, min((int)$inc['day'], J::monthLen($jy, $jm))), 'amount' => self::amountFor($inc, $ym),
                'overridden' => self::amountFor($inc, $ym) !== (int)$inc['amount'], 'items' => [], 'used' => 0];
        }
        return $out;
    }
    private static function shift(int $jy, int $jm, int $n): array { $t = $jy * 12 + $jm - 1 + $n; return [intdiv($t, 12), $t % 12 + 1]; }

    /**
     * Each deposit funds the dues from its date until the same income's next deposit.
     * returns ['occs' => deposits of month M (with items), 'unassigned' => dues of month M without a source]
     */
    public static function compute(int $uid, int $jy, int $jm): array {
        $u = Repo::user($uid);
        $incomes = self::incomes($uid);
        $occs = [];
        for ($k = -2; $k <= 1; $k++) { [$y, $m] = self::shift($jy, $jm, $k); foreach (self::occs($incomes, $y, $m) as $o) $occs[$o['key']] = $o; }
        [$y0, $m0] = self::shift($jy, $jm, -1); [$y1, $m1] = self::shift($jy, $jm, 1);
        $dues = Repo::duesInRange($uid, J::toG($y0, $m0, 1), J::monthRange($y1, $m1)[1]);
        $map = [];
        foreach (DB::all("SELECT a.obligation_id, a.income_id FROM alloc_map a JOIN incomes i ON i.id=a.income_id WHERE a.user_id=? AND i.active=1", [$uid]) as $r)
            $map[(int)$r['obligation_id']] = (int)$r['income_id'];

        $latest = function (int $incId, string $d) use (&$occs) {
            $best = null;
            foreach ($occs as $k => $o) if ($o['income_id'] === $incId && $o['date'] <= $d && (!$best || $o['date'] > $occs[$best]['date'])) $best = $k;
            return $best;
        };
        $assigned = [];
        $add = function ($key, $d, $amt, $manual) use (&$occs, &$assigned) {
            $occs[$key]['items'][] = ['due' => $d, 'amount' => $amt, 'manual' => $manual];
            $occs[$key]['used'] += $amt; $assigned[$d['id']] = $key;
        };
        // pass 1: manual assignments
        foreach ($dues as $d) {
            $inc = $map[(int)$d['obligation_id']] ?? null;
            if (!$inc) continue;
            $key = $latest($inc, $d['due_date']);
            if ($key) $add($key, $d, (int)round($d['amount'] * Repo::share($d, $uid)), true);
        }
        // pass 2: automatic
        if (($u['alloc_mode'] ?? 'auto') === 'auto') {
            foreach ($dues as $d) {
                if (isset($assigned[$d['id']]) || isset($map[(int)$d['obligation_id']])) continue;
                $amt = (int)round($d['amount'] * Repo::share($d, $uid));
                $cands = [];
                foreach ($incomes as $inc) { $k = $latest((int)$inc['id'], $d['due_date']); if ($k) $cands[] = $k; }
                if (!$cands) continue;
                usort($cands, fn($a, $b) => strcmp($occs[$b]['date'], $occs[$a]['date']));
                $pick = null;
                foreach ($cands as $k) if ($occs[$k]['amount'] - $occs[$k]['used'] >= $amt) { $pick = $k; break; }
                if (!$pick) { usort($cands, fn($a, $b) => ($occs[$b]['amount'] - $occs[$b]['used']) <=> ($occs[$a]['amount'] - $occs[$a]['used'])); $pick = $cands[0]; }
                $add($pick, $d, $amt, false);
            }
        }
        $ym = J::ym($jy, $jm);
        [$from, $to] = J::monthRange($jy, $jm);
        $month = array_values(array_filter($occs, fn($o) => $o['ym'] === $ym));
        $inMonth = fn($d) => $d['due_date'] >= $from && $d['due_date'] <= $to;
        $un = array_values(array_filter($dues, fn($d) => !isset($assigned[$d['id']]) && $inMonth($d)));
        $prev = [];
        foreach ($dues as $d) if (isset($assigned[$d['id']]) && $inMonth($d) && $occs[$assigned[$d['id']]]['ym'] < $ym)
            $prev[] = ['due' => $d, 'amount' => (int)round($d['amount'] * Repo::share($d, $uid)), 'source' => $occs[$assigned[$d['id']]]];
        return ['occs' => $month, 'unassigned' => $un, 'prev' => $prev];
    }
}

/* ================= Bot UI: incomes & plan ================= */
trait BotIncome {
    public static function incomeMenu(string $prefix = '') {
        $uid = self::uid();
        $u = Repo::user($uid);
        [$jy, $jm] = J::today(); $ym = J::ym($jy, $jm);
        $incs = Plan::incomes($uid, true);
        $t = $prefix . "💵 <b>درآمدهای ثابت ماهانه</b>\n\n";
        $sum = 0;
        foreach ($incs as $i) {
            $amt = Plan::amountFor($i, $ym);
            if ($i['active']) $sum += $amt;
            $t .= ($i['active'] ? '🟢 ' : '⏸ ') . '<b>' . U::h($i['title']) . '</b> — ' . U::toman($amt) . ' — روز ' . U::fa($i['day']) . ($amt !== (int)$i['amount'] ? ' (این ماه)' : '') . "\n";
        }
        if (!$incs) $t .= "هنوز درآمدی ثبت نکرده‌اید.\n";
        else $t .= "\n💰 جمع درآمد " . J::monthName($jy, $jm) . ': <b>' . U::toman($sum) . "</b>\n";
        $t .= "\n⚙️ حالت تخصیص: <b>" . ($u['alloc_mode'] === 'auto' ? 'خودکار' : 'دستی') . "</b>\n<i>" .
            ($u['alloc_mode'] === 'auto' ? 'هر قسط خودکار به آخرین واریزی قبل از سررسیدش که جا دارد وصل می‌شود؛ تخصیص‌های دستی اولویت دارند.' : 'فقط اقساطی که خودتان به درآمدی وصل کرده‌اید در برنامه می‌آیند.') . '</i>';
        $kb = [];
        foreach ($incs as $i) $kb[] = [U::btn(($i['active'] ? '💵 ' : '⏸ ') . $i['title'], "i:v:{$i['id']}")];
        $kb[] = [U::btn('➕ درآمد جدید', 'i:add'), U::btn('📋 برنامه این ماه', 'i:p:0')];
        $kb[] = [U::btn('📊 آمار درآمدها', 'i:st:0'), U::btn('🔮 پیش‌بینی ۶ ماه', 'i:fc:6')];
        $kb[] = [U::btn('🔗 تخصیص اقساط به درآمد', 'i:as'), U::btn('⚙️ حالت: ' . ($u['alloc_mode'] === 'auto' ? 'خودکار' : 'دستی'), 'i:mode')];
        self::out($t, U::ikb($kb));
    }

    private static function incomeView(int $id, string $prefix = '') {
        $uid = self::uid();
        $i = Plan::income($id, $uid);
        if (!$i) { self::incomeMenu(); return; }
        [$jy, $jm] = J::today(); $ym = J::ym($jy, $jm);
        $amt = Plan::amountFor($i, $ym);
        $t = $prefix . "💵 <b>" . U::h($i['title']) . "</b>\n\n";
        $t .= "💰 مبلغ ماهانه: " . U::toman($i['amount']) . "\n";
        if ($amt !== (int)$i['amount']) $t .= "📌 مبلغ واقعی " . J::monthName($jy, $jm) . ': ' . U::toman($amt) . "\n";
        $t .= "📅 روز واریز: " . U::fa($i['day']) . "ام هر ماه\nوضعیت: " . ($i['active'] ? '🟢 فعال' : '⏸ غیرفعال') . "\n";
        $links = DB::all("SELECT o.title FROM alloc_map a JOIN obligations o ON o.id=a.obligation_id WHERE a.user_id=? AND a.income_id=? AND o.status='active'", [$uid, $id]);
        if ($links) $t .= "\n🔗 <b>تخصیص دستی:</b>\n" . implode("\n", array_map(fn($r) => '• ' . U::h($r['title']), $links)) . "\n";
        self::out($t, U::ikb([
            [U::btn('✏️ عنوان', "i:et:$id"), U::btn('💰 مبلغ', "i:ea:$id"), U::btn('📅 روز واریز', "i:ed:$id")],
            [U::btn('💰 ثبت واریز', "i:dq:$id"), U::btn('📌 مبلغ واقعی این ماه', "i:ov:$id")],
            [U::btn($i['active'] ? '⏸ غیرفعال' : '▶️ فعال', "i:tg:$id"), U::btn('🗑 حذف', "i:del:$id")],
            [U::btn('🔙 درآمدها', 'i:menu')],
        ]));
    }

    public static function planView(int $off) {
        $uid = self::uid();
        Repo::ensureAllRecurring();
        [$jy, $jm] = J::today();
        $t0 = $jy * 12 + $jm - 1 + $off; $jy = intdiv($t0, 12); $jm = $t0 % 12 + 1;
        $ym = J::ym($jy, $jm);
        $p = Plan::compute($uid, $jy, $jm);
        $t = "📋 <b>برنامه واریزهای " . J::monthName($jy, $jm) . "</b>\n<i>از هر واریز، اقساطی که تا واریز بعدی همان درآمد سررسید می‌شوند کنار گذاشته می‌شود.</i>\n";
        $totIn = 0; $totOut = 0; $kb = [];
        if (!$p['occs']) $t .= "\nهنوز درآمدی ثبت نکرده‌اید.\n";
        foreach ($p['occs'] as $o) {
            $totIn += $o['amount']; $totOut += $o['used'];
            $done = DB::val("SELECT 1 FROM set_aside WHERE user_id=? AND income_id=? AND ym=?", [$uid, $o['income_id'], $ym]);
            $dep = DB::val("SELECT 1 FROM deposits WHERE user_id=? AND income_id=? AND ym=?", [$uid, $o['income_id'], $ym]);
            $t .= "\n💵 <b>" . U::h($o['title']) . '</b> — ' . J::fmt($o['date']) . ' — ' . U::toman($o['amount']) . ($dep ? ' 💰' : '') . ($done ? ' 🔒' : '') . "\n";
            usort($o['items'], fn($a, $b) => strcmp($a['due']['due_date'], $b['due']['due_date']));
            foreach ($o['items'] as $it) {
                $d = $it['due'];
                $t .= '   ' . ($d['status'] === 'paid' ? '✅' : '•') . ' ' . U::h($d['title']) . ' (' . J::fmt($d['due_date']) . ') — ' . U::n($it['amount']) . ($it['manual'] ? ' 🔗' : '') . "\n";
            }
            if (!$o['items']) $t .= "   — موردی تخصیص نیافته\n";
            $free = $o['amount'] - $o['used'];
            $t .= '   ' . ($free >= 0 ? '🔒 کنار گذاشتن: ' . U::n($o['used']) . ' | 🟢 آزاد: ' . U::n($free) : '⚠️ <b>کسری: ' . U::toman(-$free) . '</b>') . "\n";
            if (!$done && $o['items']) $kb[] = [U::btn('✅ از ' . $o['title'] . ' کنار گذاشتم', "i:sa:{$o['income_id']}:$off")];
        }
        if ($p['prev']) {
            $t .= "\n📎 <b>این ماه، از واریزهای ماه قبل</b>\n";
            foreach ($p['prev'] as $it) {
                $d = $it['due'];
                $t .= '   ' . ($d['status'] === 'paid' ? '✅' : '•') . ' ' . U::h($d['title']) . ' (' . J::fmt($d['due_date']) . ') — ' . U::n($it['amount']) . ' ← ' . U::h($it['source']['title']) . ' ' . J::fmt($it['source']['date']) . "\n";
            }
        }
        $totUn = 0;
        if ($p['unassigned']) {
            $t .= "\n⚪️ <b>بدون منبع</b>\n";
            foreach ($p['unassigned'] as $d) {
                $amt = $d['amount'] * Repo::share($d, $uid); $totUn += $amt;
                $t .= '   • ' . U::h($d['title']) . ' (' . J::fmt($d['due_date']) . ') — ' . U::n($amt) . "\n";
            }
        }
        if ($p['occs']) {
            $bal = $totIn - $totOut - $totUn;
            $t .= "\n━━━━━━━━━━\n💰 کل واریزهای ماه: " . U::toman($totIn) . "\n🔒 کنار گذاشتن از این واریزها: " . U::toman($totOut) . "\n" .
                ($totUn ? '⚪️ بدون منبع: ' . U::toman($totUn) . "\n" : '') .
                ($bal >= 0 ? '🟢 مانده آزاد: <b>' . U::toman($bal) . '</b>' : '⚠️ <b>کسری کل: ' . U::toman(-$bal) . '</b>');
        }
        $t .= "\n\n<i>🔗 تخصیص دستی  💰 واریز شد  🔒 کنار گذاشته شد</i>";
        $kb[] = [U::btn('◀️ ماه قبل', 'i:p:' . ($off - 1)), U::btn('ماه جاری', 'i:p:0'), U::btn('ماه بعد ▶️', 'i:p:' . ($off + 1))];
        $kb[] = [U::btn('📊 آمار', 'i:st:' . $off), U::btn('🔗 تخصیص اقساط', 'i:as'), U::btn('🔙 درآمدها', 'i:menu')];
        self::out($t, U::ikb($kb));
    }

    private static function assignList(string $prefix = '') {
        $uid = self::uid();
        $map = [];
        foreach (DB::all("SELECT a.obligation_id, i.title FROM alloc_map a JOIN incomes i ON i.id=a.income_id WHERE a.user_id=?", [$uid]) as $r) $map[(int)$r['obligation_id']] = $r['title'];
        $mode = Repo::user($uid)['alloc_mode'];
        $t = $prefix . "🔗 <b>تخصیص اقساط به درآمد</b>\nروی هر مورد بزنید و درآمد منبعش را انتخاب کنید.\n<i>موارد بدون انتخاب: " . ($mode === 'auto' ? 'خودکار' : 'بدون منبع') . '</i>';
        $kb = [];
        foreach (Repo::obs($uid) as $ob) {
            $kb[] = [U::btn(mb_substr($ob['title'], 0, 24) . ' ← ' . ($map[(int)$ob['id']] ?? ($mode === 'auto' ? '🤖 خودکار' : '⚪️ —')), "i:ao:{$ob['id']}")];
        }
        if (count($kb) === 0) $t .= "\n\nموردی وجود ندارد.";
        $kb[] = [U::btn('🔙 درآمدها', 'i:menu')];
        self::out($t, U::ikb($kb));
    }

    private static function incomeAction(string $act, ?string $a, ?string $b) {
        $uid = self::uid(); $id = (int)$a;
        if (in_array($act, ['st', 'sc', 'fc', 'dq', 'dp', 'dpa', 'dn'], true)) { self::incomeStatsAction($act, $a, $b); return; }
        switch ($act) {
            case 'menu': self::incomeMenu(); return;
            case 'v': self::incomeView($id); return;
            case 'p': self::planView($id); return;
            case 'as': self::assignList(); return;
            case 'mode':
                DB::q("UPDATE users SET alloc_mode=IF(alloc_mode='auto','manual','auto') WHERE id=?", [$uid]);
                self::incomeMenu();
                return;
            case 'add':
                self::setState('inc_title', []);
                self::out("➕ <b>درآمد جدید</b>\nعنوان را بنویسید:\n<i>مثال: حقوق، اجاره مغازه</i>", self::cancelKb());
                return;
            case 'et': case 'ea': case 'ed': case 'ov':
                $i = Plan::income($id, $uid); if (!$i) return;
                $p = ['et' => 'عنوان جدید را بنویسید:', 'ea' => 'مبلغ ماهانه جدید را به تومان بنویسید:', 'ed' => 'روز واریز در ماه را بنویسید (۱ تا ۳۱):',
                      'ov' => "مبلغ واقعی این درآمد در " . J::monthName(...J::today()) . " را بنویسید:\n<i>(برای برگشت به مبلغ ثابت - بفرستید)</i>"][$act];
                self::setState('inc_' . $act, ['id' => $id]);
                self::out('✏️ ' . $p, self::cancelKb());
                return;
            case 'tg': DB::q("UPDATE incomes SET active=1-active WHERE id=? AND user_id=?", [$id, $uid]); self::incomeView($id); return;
            case 'del':
                $i = Plan::income($id, $uid); if (!$i) return;
                self::out("🗑 درآمد «" . U::h($i['title']) . "» حذف شود؟\nتخصیص‌های دستی به آن هم حذف می‌شوند.", U::ikb([[U::btn('🗑 بله', "i:del2:$id"), U::btn('🔙 خیر', "i:v:$id")]]));
                return;
            case 'del2': DB::q("DELETE FROM incomes WHERE id=? AND user_id=?", [$id, $uid]); self::incomeMenu("🗑 حذف شد.\n\n"); return;
            case 'ao': // choose source for an obligation
                $ob = Repo::ob($id, $uid); if (!$ob) return;
                $cur = DB::val("SELECT income_id FROM alloc_map WHERE user_id=? AND obligation_id=?", [$uid, $id]);
                $kb = [];
                foreach (Plan::incomes($uid) as $i) $kb[] = [U::btn(((int)$cur === (int)$i['id'] ? '✅ ' : '') . $i['title'] . ' (روز ' . U::fa($i['day']) . ')', "i:aset:$id:{$i['id']}")];
                $kb[] = [U::btn((!$cur ? '✅ ' : '') . '🤖 بدون تخصیص دستی (طبق حالت کلی)', "i:aset:$id:0")];
                $kb[] = [U::btn('🔙 بازگشت', 'i:as')];
                self::out("🔗 «" . U::h($ob['title']) . "» از کدام درآمد پرداخت شود؟" . (Plan::incomes($uid) ? '' : "\n\nابتدا یک درآمد ثبت کنید."), U::ikb($kb));
                return;
            case 'aset':
                if (!Repo::ob($id, $uid)) return;
                $inc = (int)$b;
                if ($inc && Plan::income($inc, $uid)) DB::q("REPLACE INTO alloc_map (user_id,obligation_id,income_id) VALUES (?,?,?)", [$uid, $id, $inc]);
                else DB::q("DELETE FROM alloc_map WHERE user_id=? AND obligation_id=?", [$uid, $id]);
                self::toast('ذخیره شد');
                self::assignList();
                return;
            case 'sa': // set aside confirmed (from plan view or reminder)
                if (!Plan::income($id, $uid)) return;
                [$jy, $jm] = J::today(); $off = (int)$b;
                $t0 = $jy * 12 + $jm - 1 + $off;
                $saYm = preg_match('/^\d{4}-\d{2}$/', (string)$b) ? $b : J::ym(intdiv($t0, 12), $t0 % 12 + 1);
                DB::q("REPLACE INTO set_aside (user_id,income_id,ym,at) VALUES (?,?,?,NOW())", [$uid, $id, $saYm]);
                if (strpos((string)$b, '-') === 4) { self::out("✅ مبالغ این واریز کنار گذاشته شد.", U::ikb([[U::btn('📊 آمار درآمدها', 'i:st:0'), U::btn('📋 برنامه ماه', 'i:p:0')]])); return; }
                self::toast('✅ ثبت شد');
                if ($b === 'n') { self::out("✅ مبالغ این واریز کنار گذاشته شد.", U::ikb([[U::btn('📋 برنامه ماه', 'i:p:0')]])); return; }
                self::planView($off);
                return;
        }
    }

    private static function incomeInput(string $s, string $text, array $d) {
        $uid = self::uid();
        if ($s === 'inc_dep') { self::depositAmountInput($text, $d); return; }
        switch ($s) {
            case 'inc_title':
                self::setState('inc_amount', ['title' => mb_substr($text, 0, 100)]);
                TG::send(self::$chat, "💰 مبلغ ماهانه را به تومان بنویسید:\n<i>مثال: 25000000 یا ۲۵ میلیون</i>", self::cancelKb());
                return;
            case 'inc_amount':
                $amt = U::money($text);
                if (!$amt) { TG::send(self::$chat, '❗️ مبلغ نامعتبر است:', self::cancelKb()); return; }
                self::setState('inc_day', $d + ['amount' => $amt]);
                TG::send(self::$chat, "📅 روز واریز در ماه را بنویسید (۱ تا ۳۱):\n<i>مثال: 25</i>", self::cancelKb());
                return;
            case 'inc_day': case 'inc_ed':
                $day = U::int($text);
                if (!$day || $day < 1 || $day > 31) { TG::send(self::$chat, '❗️ عددی بین ۱ تا ۳۱ بنویسید:', self::cancelKb()); return; }
                self::setState(null);
                if ($s === 'inc_day') {
                    $id = DB::insert("INSERT INTO incomes (user_id,title,amount,day,created_at) VALUES (?,?,?,?,NOW())", [$uid, $d['title'], $d['amount'], $day]);
                    self::incomeView($id, "✅ درآمد ثبت شد.\n\n");
                } else {
                    DB::q("UPDATE incomes SET day=? WHERE id=? AND user_id=?", [$day, $d['id'], $uid]);
                    self::incomeView((int)$d['id'], "✅ ذخیره شد.\n\n");
                }
                return;
            case 'inc_et':
                DB::q("UPDATE incomes SET title=? WHERE id=? AND user_id=?", [mb_substr($text, 0, 100), $d['id'], $uid]);
                self::setState(null); self::incomeView((int)$d['id'], "✅ ذخیره شد.\n\n");
                return;
            case 'inc_ea': case 'inc_ov':
                [$jy, $jm] = J::today();
                if ($s === 'inc_ov' && trim($text) === '-') {
                    DB::q("DELETE FROM income_overrides WHERE income_id=? AND ym=?", [$d['id'], J::ym($jy, $jm)]);
                    self::setState(null); self::incomeView((int)$d['id'], "✅ به مبلغ ثابت برگشت.\n\n");
                    return;
                }
                $amt = U::money($text);
                if (!$amt) { TG::send(self::$chat, '❗️ مبلغ نامعتبر است:', self::cancelKb()); return; }
                if (!Plan::income((int)$d['id'], $uid)) { self::setState(null); return; }
                if ($s === 'inc_ea') DB::q("UPDATE incomes SET amount=? WHERE id=?", [$amt, $d['id']]);
                else DB::q("REPLACE INTO income_overrides (income_id,ym,amount) VALUES (?,?,?)", [$d['id'], J::ym($jy, $jm), $amt]);
                self::setState(null); self::incomeView((int)$d['id'], "✅ ذخیره شد.\n\n");
                return;
        }
        self::setState(null);
    }
}

