<?php
/* ================= Deposits (actual arrival of an income) ================= */
class Deposit {
    public static function occ(array $inc, int $jy, int $jm): array {
        $ym = J::ym($jy, $jm);
        return ['ym' => $ym, 'jy' => $jy, 'jm' => $jm, 'date' => J::toG($jy, $jm, min((int)$inc['day'], J::monthLen($jy, $jm))), 'amount' => Plan::amountFor($inc, $ym)];
    }
    public static function get(int $uid, int $incId, string $ym): ?array {
        return DB::row("SELECT * FROM deposits WHERE user_id=? AND income_id=? AND ym=?", [$uid, $incId, $ym]);
    }
    public static function setAside(int $uid, int $incId, string $ym): bool {
        return (bool)DB::val("SELECT 1 FROM set_aside WHERE user_id=? AND income_id=? AND ym=?", [$uid, $incId, $ym]);
    }
    /** nearest not-yet-deposited occurrence (previous month within 10 days, this month, or next month for early deposits) */
    public static function pending(int $uid, array $inc): ?array {
        [$jy, $jm] = J::today();
        $limit = date('Y-m-d', strtotime('-10 days'));
        foreach ([-1, 0, 1] as $k) {
            $t = $jy * 12 + $jm - 1 + $k;
            $o = self::occ($inc, intdiv($t, 12), $t % 12 + 1);
            if ($o['date'] < $limit || self::get($uid, (int)$inc['id'], $o['ym'])) continue;
            return $o;
        }
        return null;
    }
    public static function record(int $uid, array $inc, string $ym, int $amount) {
        if ($amount !== (int)$inc['amount']) DB::q("REPLACE INTO income_overrides (income_id,ym,amount) VALUES (?,?,?)", [$inc['id'], $ym, $amount]);
        else DB::q("DELETE FROM income_overrides WHERE income_id=? AND ym=?", [$inc['id'], $ym]);
        DB::q("REPLACE INTO deposits (user_id,income_id,ym,amount,at) VALUES (?,?,?,?,NOW())", [$uid, $inc['id'], $ym, $amount]);
    }
    /** the plan occurrence (with allocated items) of one income in one month */
    public static function planOcc(int $uid, int $incId, string $ym): ?array {
        [$jy, $jm] = array_map('intval', explode('-', $ym));
        foreach (Plan::compute($uid, $jy, $jm)['occs'] as $o) if ($o['income_id'] === $incId) return $o;
        return null;
    }
    /** "set aside X for Y" message after a deposit */
    public static function breakdown(int $uid, array $inc, string $ym): array {
        $o = self::planOcc($uid, (int)$inc['id'], $ym);
        [$jy, $jm] = array_map('intval', explode('-', $ym));
        $t = "💰 <b>واریز «" . U::h($inc['title']) . "» ثبت شد</b> — " . J::monthName($jy, $jm) . "\n💵 مبلغ: " . U::toman($o['amount'] ?? 0) . "\n\n";
        $items = array_values(array_filter($o['items'] ?? [], fn($it) => $it['due']['status'] !== 'paid'));
        usort($items, fn($a, $b) => strcmp($a['due']['due_date'], $b['due']['due_date']));
        $kb = [];
        if ($items) {
            $t .= "🔒 <b>از این واریز کنار بگذارید:</b>\n";
            foreach ($items as $it) $t .= '• ' . U::h($it['due']['title']) . ' — <b>' . U::toman($it['amount']) . '</b> (سررسید ' . J::fmt($it['due']['due_date']) . ', ' . U::relDay($it['due']['due_date']) . ")\n";
            $sum = array_sum(array_column($items, 'amount'));
            $free = $o['amount'] - $o['used'];
            $t .= "\n🔒 جمع کنار گذاشتن: <b>" . U::toman($sum) . "</b>\n" . ($free >= 0 ? '🟢 قابل خرج: <b>' . U::toman($free) . '</b>' : '⚠️ کسری: <b>' . U::toman(-$free) . '</b> — این واریز برای اقساط متصل کافی نیست.');
            if (!self::setAside($uid, (int)$inc['id'], $ym)) $kb[] = [U::btn('✅ کنار گذاشتم', "i:sa:{$inc['id']}:$ym")];
        } else {
            $t .= "قسط پرداخت‌نشده‌ای به این واریز متصل نیست؛ کل مبلغ آزاد است 🟢";
        }
        $kb[] = [U::btn('📊 آمار درآمدها', 'i:st:0'), U::btn('📋 برنامه ماه', 'i:p:0')];
        return [$t, U::ikb($kb)];
    }
    public static function askText(array $inc, array $o): array {
        $rel = $o['date'] <= date('Y-m-d') ? U::relDay($o['date']) : U::relDay($o['date']) . ' — زودتر از موعد';
        $t = "💵 <b>«" . U::h($inc['title']) . "» واریز شد؟</b>\n📅 موعد: " . J::fmt($o['date'], true) . " ($rel)\n💰 مبلغ: " . U::toman($o['amount']);
        return [$t, U::ikb([
            [U::btn('✅ بله، واریز شد', "i:dp:{$inc['id']}:{$o['ym']}")],
            [U::btn('✏️ مبلغ دیگری واریز شد', "i:dpa:{$inc['id']}:{$o['ym']}"), U::btn('⏳ هنوز نه', "i:dn:{$inc['id']}")],
        ])];
    }
}

/* ================= Deposit reminder: asks "was it deposited?" from due day until confirmed (max 10 days) ================= */
class IncomeReminders {
    public static function forUser(array $u, bool $force = false): int {
        if (!$force && empty($u['income_remind'])) return 0;
        $uid = (int)$u['id']; $today = date('Y-m-d');
        $limit = date('Y-m-d', strtotime('-10 days'));
        [$jy, $jm] = J::today();
        $n = 0;
        foreach (Plan::incomes($uid) as $inc) {
            if (!$force && DB::val("SELECT 1 FROM income_log WHERE user_id=? AND income_id=? AND sent_date=?", [$uid, $inc['id'], $today])) continue;
            foreach ([-1, 0] as $k) {
                $t = $jy * 12 + $jm - 1 + $k;
                $o = Deposit::occ($inc, intdiv($t, 12), $t % 12 + 1);
                if ($o['date'] > $today || $o['date'] < $limit || Deposit::get($uid, (int)$inc['id'], $o['ym'])) continue;
                [$txt, $kb] = Deposit::askText($inc, $o);
                TG::send($uid, $txt, $kb);
                if (!$force) DB::q("INSERT IGNORE INTO income_log (user_id,income_id,sent_date) VALUES (?,?,?)", [$uid, $inc['id'], $today]);
                $n++;
                break;
            }
        }
        return $n;
    }
}

/* ================= Income statistics & forecast ================= */
trait BotIncomeStats {
    private static function shiftYm(int $off): array { [$jy, $jm] = J::today(); $t = $jy * 12 + $jm - 1 + $off; return [intdiv($t, 12), $t % 12 + 1]; }

    private static function incomeStats(int $off) {
        $uid = self::uid();
        Repo::ensureAllRecurring();
        [$jy, $jm] = self::shiftYm($off); $ym = J::ym($jy, $jm);
        $p = Plan::compute($uid, $jy, $jm);
        $t = "📊 <b>آمار درآمدها — " . J::monthName($jy, $jm) . "</b>\n";
        $in = 0; $used = 0; $def = []; $kb = [];
        foreach ($p['occs'] as $o) {
            $in += $o['amount']; $used += $o['used'];
            $free = $o['amount'] - $o['used'];
            if ($free < 0) $def[] = U::h($o['title']) . ': ' . U::toman(-$free);
            $dep = Deposit::get($uid, $o['income_id'], $ym); $sa = Deposit::setAside($uid, $o['income_id'], $ym);
            $t .= "\n💵 <b>" . U::h($o['title']) . '</b> — ' . J::fmt($o['date']) . "\n";
            $t .= '   مبلغ: ' . U::n($o['amount']) . ' | متصل: ' . U::n($o['used']) . ' (' . U::fa(count($o['items'])) . " مورد)\n";
            $t .= '   ' . ($free >= 0 ? '🟢 مانده آزاد: <b>' . U::n($free) . '</b>' : '⚠️ <b>کسری: ' . U::n(-$free) . '</b>') . "\n";
            $t .= '   ' . U::bar($o['amount'] ? $o['used'] / $o['amount'] : 1, 10) . " تخصیص\n";
            $t .= '   ' . ($dep ? '💰 واریز شد (' . J::fmt($dep['at']) . ')' : ($o['date'] <= date('Y-m-d') ? '⏳ منتظر تأیید واریز' : '🗓 هنوز موعدش نرسیده'))
                . ' | ' . ($sa ? '🔒 کنار گذاشته شد' : '🔓 کنار گذاشته نشده') . "\n";
            $kb[] = [U::btn('🔎 ' . $o['title'] . ' — اقساط متصل', "i:sc:{$o['income_id']}:$off")];
        }
        if (!$p['occs']) $t .= "\nهنوز درآمدی ثبت نکرده‌اید.\n";
        else {
            $un = 0; foreach ($p['unassigned'] as $d) $un += $d['amount'] * Repo::share($d, $uid);
            $bal = $in - $used - $un;
            $t .= "\n━━━━━━━━━━\n<b>خلاصه کل</b>\n💰 کل درآمد: " . U::toman($in) . "\n🔒 کل تخصیص: " . U::toman($used) . "\n";
            if ($un) $t .= "⚪️ بدون منبع: " . U::toman($un) . "\n";
            $t .= ($bal >= 0 ? '🟢 مانده آزاد کل: <b>' . U::toman($bal) . '</b>' : '⚠️ <b>کسری کل: ' . U::toman(-$bal) . '</b>') . "\n";
            $t .= U::bar($in ? ($used + $un) / $in : 1, 12) . " از درآمد متعهد شده\n";
            if ($def) $t .= "\n⚠️ <b>درآمدهای دارای کسری:</b>\n" . implode("\n", array_map(fn($x) => '• ' . $x, $def)) . "\n";
        }
        $kb[] = [U::btn('🔮 پیش‌بینی ۳ ماه', 'i:fc:3'), U::btn('🔮 پیش‌بینی ۶ ماه', 'i:fc:6')];
        $kb[] = [U::btn('◀️ ماه قبل', 'i:st:' . ($off - 1)), U::btn('ماه جاری', 'i:st:0'), U::btn('ماه بعد ▶️', 'i:st:' . ($off + 1))];
        $kb[] = [U::btn('📋 برنامه', 'i:p:' . $off), U::btn('🔙 درآمدها', 'i:menu')];
        self::out($t, U::ikb($kb));
    }

    private static function incomeCard(int $id, int $off) {
        $uid = self::uid();
        $inc = Plan::income($id, $uid);
        if (!$inc) { self::incomeStats(0); return; }
        [$jy, $jm] = self::shiftYm($off); $ym = J::ym($jy, $jm);
        $o = Deposit::planOcc($uid, $id, $ym);
        $t = "🔎 <b>" . U::h($inc['title']) . "</b> — " . J::monthName($jy, $jm) . "\n";
        if (!$o) { $t .= "\nاین درآمد غیرفعال است."; self::out($t, U::ikb([[U::btn('🔙 آمار', "i:st:$off")]])); return; }
        $free = $o['amount'] - $o['used'];
        $dep = Deposit::get($uid, $id, $ym); $sa = Deposit::setAside($uid, $id, $ym);
        $t .= "📅 واریز: " . J::fmt($o['date']) . " | 💰 " . U::toman($o['amount']) . "\n";
        $t .= ($dep ? '💰 واریز شد' : '⏳ واریز ثبت نشده') . ' | ' . ($sa ? '🔒 کنار گذاشته شد' : '🔓 کنار گذاشته نشده') . "\n\n";
        $items = $o['items'];
        usort($items, fn($a, $b) => strcmp($a['due']['due_date'], $b['due']['due_date']));
        $paid = 0;
        if ($items) {
            $t .= "<b>اقساط متصل به این واریز:</b>\n";
            foreach ($items as $it) {
                $d = $it['due'];
                $isPaid = $d['status'] === 'paid'; if ($isPaid) $paid += $it['amount'];
                $t .= ($isPaid ? '✅ ' : ($d['due_date'] < date('Y-m-d') ? '🔴 ' : '⏳ ')) . U::h($d['title']) . ($d['type'] === 'loan' ? ' (قسط ' . U::fa($d['seq']) . ')' : '')
                    . "\n     " . J::fmt($d['due_date']) . ' — ' . U::toman($it['amount']) . ($it['manual'] ? ' 🔗' : ' 🤖') . "\n";
            }
        } else $t .= "قسطی به این واریز متصل نیست.\n";
        $t .= "\n🔒 جمع متصل: " . U::toman($o['used']) . "\n✅ از آن پرداخت‌شده: " . U::toman($paid) . "\n⏳ هنوز باید بماند: " . U::toman($o['used'] - $paid) . "\n";
        $t .= ($free >= 0 ? '🟢 مانده آزاد: <b>' . U::toman($free) . '</b>' : '⚠️ <b>کسری: ' . U::toman(-$free) . '</b>') . "\n" . U::bar($o['amount'] ? $o['used'] / $o['amount'] : 1, 12);
        $t .= "\n\n<i>🔗 دستی  🤖 خودکار</i>";
        $kb = [];
        $row = [];
        if (!$dep) $row[] = U::btn('💰 ثبت واریز', "i:dq:$id");
        if (!$sa && $items) $row[] = U::btn('✅ کنار گذاشتم', "i:sa:$id:$ym");
        if ($row) $kb[] = $row;
        $kb[] = [U::btn('◀️ ماه قبل', "i:sc:$id:" . ($off - 1)), U::btn('ماه بعد ▶️', "i:sc:$id:" . ($off + 1))];
        $kb[] = [U::btn('🔙 آمار', "i:st:$off")];
        self::out($t, U::ikb($kb));
    }

    private static function forecast(int $n) {
        $uid = self::uid();
        Repo::ensureAllRecurring();
        $n = max(1, min(12, $n));
        $t = "🔮 <b>پیش‌بینی " . U::fa($n) . " ماه آینده</b>\n";
        $worst = null; $rows = [];
        for ($k = 0; $k < $n; $k++) {
            [$jy, $jm] = self::shiftYm($k);
            $p = Plan::compute($uid, $jy, $jm);
            $in = 0; $used = 0; $un = 0; $per = [];
            foreach ($p['occs'] as $o) {
                $in += $o['amount']; $used += $o['used'];
                $f = $o['amount'] - $o['used'];
                $per[] = U::h($o['title']) . ': ' . ($f >= 0 ? U::n($f) : '⚠️ -' . U::n(-$f));
            }
            foreach ($p['unassigned'] as $d) $un += $d['amount'] * Repo::share($d, $uid);
            $free = $in - $used - $un;
            if ($worst === null || $free < $worst[1]) $worst = [J::monthName($jy, $jm), $free];
            $t .= "\n<b>" . J::monthName($jy, $jm) . "</b> " . ($free >= 0 ? '🟢' : '⚠️') . "\n   درآمد " . U::n($in) . ' | تعهد ' . U::n($used + $un) . ' | آزاد <b>' . ($free >= 0 ? U::n($free) : '-' . U::n(-$free)) . "</b>\n";
            if (count($per) > 1) $t .= '   ' . implode(' • ', $per) . "\n";
        }
        if ($worst) $t .= "\n📉 بیشترین فشار: <b>" . $worst[0] . '</b> (آزاد ' . ($worst[1] >= 0 ? U::toman($worst[1]) : '-' . U::toman(-$worst[1])) . ")\n";
        $loans = DB::all("SELECT o.id, o.title, MAX(d.due_date) last_date, SUM(d.status<>'paid') left_cnt, o.user_id, o.shared_user_id, o.owner_share,
            SUM(CASE WHEN d.status<>'paid' THEN d.amount END) left_amt
            FROM obligations o JOIN dues d ON d.obligation_id=o.id WHERE (o.user_id=? OR o.shared_user_id=?) AND o.type='loan' AND o.status='active' GROUP BY o.id ORDER BY last_date", [$uid, $uid]);
        if ($loans) {
            $t .= "\n🏁 <b>پایان اقساط</b>\n";
            foreach ($loans as $l) $t .= '• ' . U::h($l['title']) . ': ' . J::fmt($l['last_date']) . ' (' . U::fa($l['left_cnt']) . ' قسط، ' . U::n($l['left_amt'] * Repo::share($l, $uid)) . ")\n";
            $t .= "<i>بعد از تمام شدن هر وام، مبلغ قسطش به مانده آزاد اضافه می‌شود.</i>";
        }
        self::out($t, U::ikb([
            [U::btn('۳ ماه', 'i:fc:3'), U::btn('۶ ماه', 'i:fc:6'), U::btn('۱۲ ماه', 'i:fc:12')],
            [U::btn('🔙 آمار', 'i:st:0')],
        ]));
    }

    private static function incomeStatsAction(string $act, ?string $a, ?string $b) {
        $uid = self::uid(); $id = (int)$a;
        switch ($act) {
            case 'st': self::incomeStats($id); return;
            case 'sc': self::incomeCard($id, (int)$b); return;
            case 'fc': self::forecast($id); return;
            case 'dq':
                $inc = Plan::income($id, $uid); if (!$inc) return;
                $o = Deposit::pending($uid, $inc);
                if (!$o) { self::out("✅ واریزهای اخیر «" . U::h($inc['title']) . "» همه ثبت شده‌اند.", U::ikb([[U::btn('🔙 بازگشت', "i:v:$id")]])); return; }
                [$t, $kb] = Deposit::askText($inc, $o);
                self::out($t, $kb);
                return;
            case 'dp':
                $inc = Plan::income($id, $uid); if (!$inc || !preg_match('/^\d{4}-\d{2}$/', (string)$b)) return;
                Deposit::record($uid, $inc, $b, Plan::amountFor($inc, $b));
                self::toast('💰 واریز ثبت شد');
                [$t, $kb] = Deposit::breakdown($uid, Plan::income($id, $uid), $b);
                self::out($t, $kb);
                return;
            case 'dpa':
                if (!Plan::income($id, $uid) || !preg_match('/^\d{4}-\d{2}$/', (string)$b)) return;
                self::setState('inc_dep', ['id' => $id, 'ym' => $b]);
                self::out('✏️ مبلغی که واقعاً واریز شد را به تومان بنویسید:', self::cancelKb());
                return;
            case 'dn':
                self::out('⏳ باشه، فردا دوباره می‌پرسم. هر وقت واریز شد از «💵 درآمد و برنامه» هم می‌توانید ثبتش کنید.');
                return;
        }
    }

    private static function depositAmountInput(string $text, array $d) {
        $uid = self::uid();
        $amt = U::money($text);
        $inc = Plan::income((int)$d['id'], $uid);
        if (!$inc) { self::setState(null); return; }
        if (!$amt) { TG::send(self::$chat, '❗️ مبلغ نامعتبر است. دوباره بنویسید:', self::cancelKb()); return; }
        self::setState(null);
        Deposit::record($uid, $inc, $d['ym'], $amt);
        [$t, $kb] = Deposit::breakdown($uid, $inc, $d['ym']);
        TG::send(self::$chat, $t, $kb);
    }
}
