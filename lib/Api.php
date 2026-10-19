<?php
/* ================= Mini App JSON API ================= */
class ApiError extends Exception {}

class Api {
    public static int $uid = 0;
    public static array $u = [];

    /* ---------- auth: Telegram WebApp initData ---------- */
    public static function auth(string $init): array {
        if ($init === '') throw new ApiError('no_auth');
        parse_str($init, $arr);
        $hash = $arr['hash'] ?? '';
        unset($arr['hash']);
        ksort($arr);
        $lines = [];
        foreach ($arr as $k => $v) $lines[] = "$k=$v";
        $secret = hash_hmac('sha256', cfg('bot_token'), 'WebAppData', true);
        if (!$hash || !hash_equals(hash_hmac('sha256', implode("\n", $lines), $secret), $hash)) throw new ApiError('bad_auth');
        if (time() - (int)($arr['auth_date'] ?? 0) > 86400) throw new ApiError('expired');
        $user = json_decode($arr['user'] ?? '', true);
        if (empty($user['id'])) throw new ApiError('bad_auth');
        $u = Repo::touchUser($user);
        if (!$u['is_allowed']) throw new ApiError('forbidden');
        self::$uid = (int)$u['id'];
        self::$u = $u;
        return $u;
    }

    public static function handle(string $action, array $in) {
        $m = 'a_' . preg_replace('/[^a-z_]/', '', $action);
        if (!method_exists(self::class, $m)) throw new ApiError('unknown_action');
        Repo::ensureAllRecurring();
        return self::$m($in);
    }

    /* ---------- helpers ---------- */
    private static function int($v): int { return (int)$v; }
    private static function j(string $g): array { return J::fromG($g); }
    private static function shiftYm(int $off): array { [$jy, $jm] = J::today(); $t = $jy * 12 + $jm - 1 + $off; return [intdiv($t, 12), $t % 12 + 1]; }
    private static function ob(int $id): array { $ob = Repo::ob($id, self::$uid); if (!$ob) throw new ApiError('not_found'); return $ob; }
    private static function owned(int $id): array { $ob = self::ob($id); if ((int)$ob['user_id'] !== self::$uid) throw new ApiError('not_owner'); return $ob; }
    private static function money($v): int { $n = is_numeric($v) ? (int)$v : (U::money((string)$v) ?? 0); if ($n <= 0) throw new ApiError('bad_amount'); return $n; }
    private static function gdate($v): string {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$v)) throw new ApiError('bad_date');
        return $v;
    }

    public static function dueOut(array $d): array {
        $sh = Repo::share($d, self::$uid);
        return [
            'id' => (int)$d['id'], 'ob' => (int)$d['obligation_id'], 'seq' => (int)$d['seq'], 'title' => $d['title'] ?? '', 'type' => $d['type'] ?? '',
            'date' => $d['due_date'], 'amount' => (int)$d['amount'], 'paid' => (int)$d['paid_amount'],
            'mine' => (int)round($d['amount'] * $sh), 'mine_left' => (int)round(($d['amount'] - $d['paid_amount']) * $sh),
            'share' => (int)round($sh * 100), 'status' => $d['status'], 'paid_at' => $d['paid_at'] ?? null,
            'overdue' => $d['status'] !== 'paid' && $d['due_date'] < date('Y-m-d'),
            'cat' => !empty($d['category_id']) ? (int)$d['category_id'] : null, 'shared' => !empty($d['shared_user_id']),
        ];
    }

    private static function manualMap(): array {
        $m = [];
        foreach (DB::all("SELECT a.obligation_id, i.id, i.title FROM alloc_map a JOIN incomes i ON i.id=a.income_id WHERE a.user_id=?", [self::$uid]) as $r)
            $m[(int)$r['obligation_id']] = ['id' => (int)$r['id'], 'title' => $r['title']];
        return $m;
    }

    public static function obOut(array $ob, ?array $manual = null): array {
        $uid = self::$uid;
        $st = Repo::stats((int)$ob['id']);
        $sh = Repo::share($ob, $uid);
        $next = Repo::nextDue((int)$ob['id']);
        $last = $ob['type'] === 'loan' ? DB::val("SELECT MAX(due_date) FROM dues WHERE obligation_id=?", [$ob['id']]) : null;
        $manual = $manual ?? self::manualMap();
        return [
            'id' => (int)$ob['id'], 'type' => $ob['type'], 'title' => $ob['title'], 'cat' => $ob['category_id'] ? (int)$ob['category_id'] : null,
            'creditor' => $ob['creditor'], 'note' => $ob['note'], 'period_unit' => $ob['period_unit'], 'period_n' => (int)$ob['period_n'],
            'amount' => (int)$ob['amount'], 'status' => $ob['status'], 'owner' => (int)$ob['user_id'] === $uid,
            'shared' => !empty($ob['shared_user_id']), 'share' => (int)round($sh * 100),
            'partner' => !empty($ob['shared_user_id']) ? Repo::uname(Repo::partnerOf($ob, $uid)) : null,
            'cnt' => (int)$st['cnt'], 'paid_cnt' => (int)$st['paid_cnt'], 'total' => (int)$st['total'], 'paid' => (int)$st['paid'],
            'left' => (int)$st['total'] - (int)$st['paid'], 'left_mine' => (int)round(($st['total'] - $st['paid']) * $sh),
            'next' => $next && $ob['status'] === 'active' ? self::dueOut($next + ['title' => $ob['title'], 'type' => $ob['type'], 'category_id' => $ob['category_id'],
                'user_id' => $ob['user_id'], 'shared_user_id' => $ob['shared_user_id'], 'owner_share' => $ob['owner_share']]) : null,
            'last_date' => $last, 'source' => $manual[(int)$ob['id']] ?? null,
        ];
    }

    /** dues of one jalali month with the income each one is set aside from */
    private static function monthDues(int $jy, int $jm): array {
        $uid = self::$uid;
        $p = Plan::compute($uid, $jy, $jm);
        [$from, $to] = J::monthRange($jy, $jm);
        $src = [];
        $mk = fn($o, $manual) => ['income_id' => $o['income_id'], 'title' => $o['title'], 'ym' => $o['ym'], 'date' => $o['date'], 'manual' => $manual];
        foreach ($p['occs'] as $o) foreach ($o['items'] as $it) $src[(int)$it['due']['id']] = $mk($o, $it['manual']);
        foreach ($p['prev'] as $it) $src[(int)$it['due']['id']] = $mk($it['source'], false);
        $list = []; $total = 0; $left = 0;
        foreach (Repo::duesInRange($uid, $from, $to) as $d) {
            if ($d['ob_status'] === 'stopped' && $d['status'] !== 'paid') continue;
            $o = self::dueOut($d);
            $o['source'] = $src[(int)$d['id']] ?? null;
            $list[] = $o; $total += $o['mine']; $left += $o['mine_left'];
        }
        return ['jy' => $jy, 'jm' => $jm, 'dues' => $list, 'total' => $total, 'left' => $left];
    }

    private static function planOut(int $jy, int $jm): array {
        $uid = self::$uid; $ym = J::ym($jy, $jm);
        $p = Plan::compute($uid, $jy, $jm);
        $occs = []; $in = 0; $used = 0;
        foreach ($p['occs'] as $o) {
            $items = array_map(fn($it) => self::dueOut($it['due']) + ['alloc' => $it['amount'], 'manual' => $it['manual']], $o['items']);
            usort($items, fn($a, $b) => strcmp($a['date'], $b['date']));
            $dep = Deposit::get($uid, $o['income_id'], $ym);
            $occs[] = ['income_id' => $o['income_id'], 'title' => $o['title'], 'ym' => $ym, 'date' => $o['date'], 'amount' => $o['amount'],
                'overridden' => $o['overridden'], 'used' => $o['used'], 'free' => $o['amount'] - $o['used'], 'items' => $items,
                'deposited' => (bool)$dep, 'set_aside' => Deposit::setAside($uid, $o['income_id'], $ym)];
            $in += $o['amount']; $used += $o['used'];
        }
        $un = array_map(fn($d) => self::dueOut($d), $p['unassigned']);
        $unSum = array_sum(array_column($un, 'mine'));
        $prev = array_map(fn($it) => self::dueOut($it['due']) + ['alloc' => $it['amount'], 'source' => ['title' => $it['source']['title'], 'ym' => $it['source']['ym'], 'date' => $it['source']['date']]], $p['prev']);
        return ['jy' => $jy, 'jm' => $jm, 'occs' => $occs, 'unassigned' => $un, 'prev' => $prev,
            'income' => $in, 'used' => $used, 'unassigned_sum' => $unSum, 'free' => $in - $used - $unSum];
    }

    private static function afterPay(array $d, int $amt) {
        if ($amt <= 0) return;
        $ob = DB::row("SELECT * FROM obligations WHERE id=?", [$d['obligation_id']]);
        $p = Repo::partnerOf($ob, self::$uid);
        if ($p) TG::send($p, "👥 " . U::h(self::$u['name']) . " مبلغ " . U::toman($amt) . " برای «" . U::h($ob['title']) . "» (سررسید " . J::fmt($d['due_date']) . ") پرداخت کرد.");
        $alert = Repo::budgetCheck((int)$ob['user_id'], $ob['category_id'] ? (int)$ob['category_id'] : null);
        if ($alert) TG::send((int)$ob['user_id'], $alert);
    }

    /* ================= Actions ================= */
    private static function a_boot(array $in): array {
        $u = Repo::user(self::$uid);
        $others = array_values(array_map(fn($x) => ['id' => (int)$x['id'], 'name' => $x['name'] ?: (string)$x['id']],
            array_filter(Repo::allowedUsers(), fn($x) => (int)$x['id'] !== self::$uid)));
        return [
            'me' => ['id' => (int)$u['id'], 'name' => $u['name'], 'admin' => (bool)$u['is_admin'], 'alloc_mode' => $u['alloc_mode'],
                'remind_days' => array_map('intval', array_values(array_filter(explode(',', $u['remind_days']), 'strlen'))),
                'remind_hour' => (int)$u['remind_hour'], 'overdue_daily' => (bool)$u['overdue_daily'], 'monthly_summary' => (bool)$u['monthly_summary'],
                'income_remind' => (bool)$u['income_remind']],
            'today' => date('Y-m-d'),
            'cats' => array_map(fn($c) => ['id' => (int)$c['id'], 'name' => $c['name'], 'emoji' => $c['emoji'], 'budget' => (int)$c['budget']], Repo::cats(self::$uid)),
            'incomes' => array_map(fn($i) => ['id' => (int)$i['id'], 'title' => $i['title'], 'amount' => (int)$i['amount'], 'day' => (int)$i['day'], 'active' => (bool)$i['active']], Plan::incomes(self::$uid, true)),
            'others' => $others,
        ];
    }

    private static function a_dash(array $in): array {
        $uid = self::$uid;
        [$jy, $jm, $jd] = J::today();
        $plan = self::planOut($jy, $jm);
        $months = [self::monthDues($jy, $jm)];
        if ($jd >= 20) { [$ny, $nm] = self::shiftYm(1); $months[] = self::monthDues($ny, $nm); }
        // deposit prompts
        $prompts = [];
        foreach (Plan::incomes($uid) as $inc) {
            $o = Deposit::pending($uid, $inc);
            if ($o && $o['date'] <= date('Y-m-d')) $prompts[] = ['income_id' => (int)$inc['id'], 'title' => $inc['title'], 'ym' => $o['ym'], 'date' => $o['date'], 'amount' => $o['amount']];
        }
        $ov = Repo::overdue($uid);
        $ovSum = 0; foreach ($ov as $d) $ovSum += ($d['amount'] - $d['paid_amount']) * Repo::share($d, $uid);
        $debt = 0; $debtFree = null; $loans = 0;
        foreach (Repo::obs($uid, 'loan') as $ob) {
            $st = Repo::stats((int)$ob['id']);
            $debt += ($st['total'] - $st['paid']) * Repo::share($ob, $uid); $loans++;
            $last = DB::val("SELECT MAX(due_date) FROM dues WHERE obligation_id=? AND status<>'paid'", [$ob['id']]);
            if ($last && (!$debtFree || $last > $debtFree)) $debtFree = $last;
        }
        $debtTotal = 0;
        foreach (Repo::obs($uid, 'loan') as $ob) { $st = Repo::stats((int)$ob['id']); $debtTotal += $st['total'] * Repo::share($ob, $uid); }
        $up = array_map(fn($d) => self::dueOut($d), Repo::upcoming($uid, 3));
        return ['plan' => $plan, 'months' => $months, 'prompts' => $prompts,
            'overdue' => ['count' => count($ov), 'sum' => (int)round($ovSum)],
            'debt' => ['left' => (int)round($debt), 'total' => (int)round($debtTotal), 'loans' => $loans, 'free_date' => $debtFree],
            'upcoming' => $up];
    }

    private static function a_obs(array $in): array {
        $uid = self::$uid; $manual = self::manualMap();
        $active = array_map(fn($o) => self::obOut($o, $manual), Repo::obs($uid));
        $arch = array_map(fn($o) => self::obOut($o, $manual), Repo::obs($uid, null, 'archived'));
        usort($active, fn($a, $b) => strcmp($a['next']['date'] ?? '9999', $b['next']['date'] ?? '9999'));
        return ['active' => $active, 'archived' => $arch];
    }

    private static function a_ob(array $in): array {
        $ob = self::ob(self::int($in['id'] ?? 0));
        $dues = [];
        $pays = [];
        foreach (DB::all("SELECT p.* FROM payments p JOIN dues d ON d.id=p.due_id WHERE d.obligation_id=? ORDER BY p.id", [$ob['id']]) as $p)
            $pays[(int)$p['due_id']][] = ['amount' => (int)$p['amount'], 'at' => $p['paid_at'], 'by' => Repo::uname((int)$p['payer_id'])];
        foreach (Repo::dues((int)$ob['id']) as $d) {
            $o = self::dueOut($d + ['title' => $ob['title'], 'type' => $ob['type'], 'category_id' => $ob['category_id'], 'user_id' => $ob['user_id'],
                'shared_user_id' => $ob['shared_user_id'], 'owner_share' => $ob['owner_share']]);
            $o['payments'] = $pays[(int)$d['id']] ?? [];
            $dues[] = $o;
        }
        return ['ob' => self::obOut($ob), 'dues' => $dues];
    }

    private static function a_pay(array $in): array {
        $d = Repo::due(self::int($in['due_id'] ?? 0), self::$uid);
        if (!$d) throw new ApiError('not_found');
        $amt = isset($in['amount']) && $in['amount'] !== null && $in['amount'] !== '' ? self::money($in['amount']) : null;
        $paid = Repo::pay((int)$d['id'], self::$uid, $amt);
        self::afterPay($d, $paid);
        $ob = DB::row("SELECT status,type FROM obligations WHERE id=?", [$d['obligation_id']]);
        return ['paid' => $paid, 'done' => $ob['status'] === 'done' && $ob['type'] === 'loan'];
    }

    private static function a_undo(array $in): array {
        $d = Repo::due(self::int($in['due_id'] ?? 0), self::$uid);
        if (!$d) throw new ApiError('not_found');
        return ['ok' => Repo::undoPay((int)$d['id'])];
    }

    private static function a_settle(array $in): array {
        $ob = self::ob(self::int($in['id'] ?? 0));
        $sum = Repo::settle((int)$ob['id'], self::$uid);
        $p = Repo::partnerOf($ob, self::$uid);
        if ($p) TG::send($p, "🏁 «" . U::h($ob['title']) . "» توسط " . U::h(self::$u['name']) . " تسویه کامل شد (" . U::toman($sum) . ").");
        return ['paid' => $sum];
    }

    private static function a_stop(array $in): array { $ob = self::owned(self::int($in['id'] ?? 0)); Repo::stopRecurring((int)$ob['id']); return []; }
    private static function a_resume(array $in): array { $ob = self::owned(self::int($in['id'] ?? 0)); Repo::resume((int)$ob['id']); return []; }
    private static function a_del(array $in): array { $ob = self::owned(self::int($in['id'] ?? 0)); Repo::deleteOb((int)$ob['id']); return []; }

    private static function a_create(array $in): array {
        $uid = self::$uid;
        $type = $in['type'] ?? '';
        if (!in_array($type, ['loan', 'recurring', 'once'], true)) throw new ApiError('bad_type');
        $title = trim(mb_substr((string)($in['title'] ?? ''), 0, 120));
        if ($title === '') throw new ApiError('bad_title');
        $cat = self::int($in['category_id'] ?? 0);
        if ($cat && !Repo::cat($cat, $uid)) $cat = 0;
        $d = ['type' => $type, 'title' => $title, 'category_id' => $cat ?: null, 'creditor' => trim((string)($in['creditor'] ?? '')) ?: null,
            'first_date' => self::gdate($in['first_date'] ?? ''), 'period_unit' => null, 'period_n' => 1];
        if ($type === 'loan') {
            $amounts = array_values(array_map(fn($v) => self::money($v), (array)($in['amounts'] ?? [])));
            if (!$amounts || count($amounts) > 600) throw new ApiError('bad_amount');
            $d['amounts'] = $amounts; $d['amount'] = $amounts[0];
            $d['period_unit'] = 'month';
            $d['period_n'] = max(1, min(12, self::int($in['period_n'] ?? 1)));
            $d['paid_count'] = max(0, min(count($amounts), self::int($in['paid_count'] ?? 0)));
        } else {
            $d['amount'] = self::money($in['amount'] ?? 0);
            if ($type === 'recurring') {
                $d['period_unit'] = in_array($in['period_unit'] ?? '', ['week', 'month', 'year'], true) ? $in['period_unit'] : 'month';
                $d['period_n'] = max(1, min(12, self::int($in['period_n'] ?? 1)));
            }
        }
        $sid = self::int($in['shared_user_id'] ?? 0);
        if ($sid && $sid !== $uid && ($su = Repo::user($sid)) && $su['is_allowed']) {
            $d['shared_user_id'] = $sid;
            $d['owner_share'] = max(1, min(99, self::int($in['owner_share'] ?? 50)));
        }
        $id = Repo::createOb($uid, $d);
        $inc = self::int($in['income_id'] ?? 0);
        if ($inc && Plan::income($inc, $uid)) DB::q("REPLACE INTO alloc_map (user_id,obligation_id,income_id) VALUES (?,?,?)", [$uid, $id, $inc]);
        if (!empty($d['shared_user_id']))
            TG::send($d['shared_user_id'], "👥 " . U::h(self::$u['name']) . " مورد مشترک «" . U::h($title) . "» را ثبت کرد. سهم شما " . U::fa(100 - (int)$d['owner_share']) . "٪ است.");
        return ['id' => $id];
    }

    private static function a_ob_update(array $in): array {
        $ob = self::owned(self::int($in['id'] ?? 0));
        if (isset($in['title']) && trim($in['title']) !== '') DB::q("UPDATE obligations SET title=? WHERE id=?", [mb_substr(trim($in['title']), 0, 120), $ob['id']]);
        if (array_key_exists('category_id', $in)) { $c = self::int($in['category_id']); DB::q("UPDATE obligations SET category_id=? WHERE id=?", [$c && Repo::cat($c, self::$uid) ? $c : null, $ob['id']]); }
        if (array_key_exists('creditor', $in)) DB::q("UPDATE obligations SET creditor=? WHERE id=?", [trim((string)$in['creditor']) ?: null, $ob['id']]);
        if (array_key_exists('note', $in)) DB::q("UPDATE obligations SET note=? WHERE id=?", [mb_substr(trim((string)$in['note']), 0, 1000) ?: null, $ob['id']]);
        if (!empty($in['amount'])) {
            $amt = self::money($in['amount']);
            DB::q("UPDATE obligations SET amount=? WHERE id=?", [$amt, $ob['id']]);
            foreach (DB::all("SELECT id FROM dues WHERE obligation_id=? AND status='pending'", [$ob['id']]) as $r) Repo::setDueAmount((int)$r['id'], $amt);
        }
        return [];
    }

    private static function a_due_update(array $in): array {
        $d = Repo::due(self::int($in['id'] ?? 0), self::$uid);
        if (!$d || (int)$d['user_id'] !== self::$uid) throw new ApiError('not_owner');
        if (!empty($in['amount'])) Repo::setDueAmount((int)$d['id'], self::money($in['amount']));
        if (!empty($in['date'])) Repo::setDueDate((int)$d['id'], self::gdate($in['date']));
        return [];
    }

    private static function a_plan(array $in): array {
        [$jy, $jm] = self::shiftYm(self::int($in['off'] ?? 0));
        $manual = self::manualMap();
        $obs = array_map(fn($o) => ['id' => (int)$o['id'], 'title' => $o['title'], 'type' => $o['type'], 'source' => $manual[(int)$o['id']] ?? null], Repo::obs(self::$uid));
        return self::planOut($jy, $jm) + ['mode' => Repo::user(self::$uid)['alloc_mode'], 'obs' => $obs];
    }

    private static function a_assign(array $in): array {
        $ob = self::ob(self::int($in['ob_id'] ?? 0));
        $inc = self::int($in['income_id'] ?? 0);
        if ($inc && Plan::income($inc, self::$uid)) DB::q("REPLACE INTO alloc_map (user_id,obligation_id,income_id) VALUES (?,?,?)", [self::$uid, $ob['id'], $inc]);
        else DB::q("DELETE FROM alloc_map WHERE user_id=? AND obligation_id=?", [self::$uid, $ob['id']]);
        return [];
    }

    private static function a_mode(array $in): array {
        $m = ($in['mode'] ?? '') === 'manual' ? 'manual' : 'auto';
        DB::q("UPDATE users SET alloc_mode=? WHERE id=?", [$m, self::$uid]);
        return ['mode' => $m];
    }

    private static function a_income_save(array $in): array {
        $title = trim(mb_substr((string)($in['title'] ?? ''), 0, 100));
        $day = self::int($in['day'] ?? 0);
        if ($title === '' || $day < 1 || $day > 31) throw new ApiError('bad_input');
        $amt = self::money($in['amount'] ?? 0);
        $id = self::int($in['id'] ?? 0);
        if ($id) {
            if (!Plan::income($id, self::$uid)) throw new ApiError('not_found');
            DB::q("UPDATE incomes SET title=?, amount=?, day=?, active=? WHERE id=?", [$title, $amt, $day, empty($in['active']) && isset($in['active']) ? 0 : 1, $id]);
        } else $id = DB::insert("INSERT INTO incomes (user_id,title,amount,day,created_at) VALUES (?,?,?,?,NOW())", [self::$uid, $title, $amt, $day]);
        return ['id' => $id];
    }

    private static function a_income_del(array $in): array {
        DB::q("DELETE FROM incomes WHERE id=? AND user_id=?", [self::int($in['id'] ?? 0), self::$uid]);
        return [];
    }

    private static function a_income_override(array $in): array {
        $inc = Plan::income(self::int($in['id'] ?? 0), self::$uid);
        $ym = (string)($in['ym'] ?? '');
        if (!$inc || !preg_match('/^\d{4}-\d{2}$/', $ym)) throw new ApiError('bad_input');
        if (empty($in['amount'])) DB::q("DELETE FROM income_overrides WHERE income_id=? AND ym=?", [$inc['id'], $ym]);
        else DB::q("REPLACE INTO income_overrides (income_id,ym,amount) VALUES (?,?,?)", [$inc['id'], $ym, self::money($in['amount'])]);
        return [];
    }

    private static function a_deposit(array $in): array {
        $inc = Plan::income(self::int($in['income_id'] ?? 0), self::$uid);
        $ym = (string)($in['ym'] ?? '');
        if (!$inc || !preg_match('/^\d{4}-\d{2}$/', $ym)) throw new ApiError('bad_input');
        $amt = !empty($in['amount']) ? self::money($in['amount']) : Plan::amountFor($inc, $ym);
        Deposit::record(self::$uid, $inc, $ym, $amt);
        [$jy, $jm] = array_map('intval', explode('-', $ym));
        $plan = self::planOut($jy, $jm);
        $occ = null;
        foreach ($plan['occs'] as $o) if ($o['income_id'] === (int)$inc['id']) $occ = $o;
        return ['occ' => $occ];
    }

    private static function a_set_aside(array $in): array {
        $inc = Plan::income(self::int($in['income_id'] ?? 0), self::$uid);
        $ym = (string)($in['ym'] ?? '');
        if (!$inc || !preg_match('/^\d{4}-\d{2}$/', $ym)) throw new ApiError('bad_input');
        if (!empty($in['undo'])) DB::q("DELETE FROM set_aside WHERE user_id=? AND income_id=? AND ym=?", [self::$uid, $inc['id'], $ym]);
        else DB::q("REPLACE INTO set_aside (user_id,income_id,ym,at) VALUES (?,?,?,NOW())", [self::$uid, $inc['id'], $ym]);
        return [];
    }

    private static function a_calendar(array $in): array {
        $uid = self::$uid;
        $jy = self::int($in['jy'] ?? 0); $jm = self::int($in['jm'] ?? 0);
        if ($jy < 1300 || $jm < 1 || $jm > 12) [$jy, $jm] = J::today();
        $md = self::monthDues($jy, $jm);
        $ym = J::ym($jy, $jm);
        $incs = [];
        foreach (Plan::incomes($uid) as $inc) {
            $o = Deposit::occ($inc, $jy, $jm);
            $incs[] = ['income_id' => (int)$inc['id'], 'title' => $inc['title'], 'date' => $o['date'], 'amount' => $o['amount'], 'deposited' => (bool)Deposit::get($uid, (int)$inc['id'], $ym)];
        }
        return ['jy' => $jy, 'jm' => $jm, 'dues' => $md['dues'], 'incomes' => $incs, 'total' => $md['total'], 'left' => $md['left']];
    }

    private static function monthlyEq(array $ob): float {
        $n = max(1, (int)$ob['period_n']);
        if ($ob['type'] === 'loan') { $nx = Repo::nextDue((int)$ob['id']); return $nx ? $nx['amount'] / $n : 0; }
        if ($ob['type'] !== 'recurring') return 0;
        if ($ob['period_unit'] === 'week') return $ob['amount'] * 52 / 12 / $n;
        if ($ob['period_unit'] === 'year') return $ob['amount'] / 12 / $n;
        return $ob['amount'] / $n;
    }

    private static function a_reports(array $in): array {
        $uid = self::$uid;
        $off = self::int($in['off'] ?? 0);
        [$jy, $jm] = self::shiftYm($off);
        [$from, $to] = J::monthRange($jy, $jm);
        $plan = self::planOut($jy, $jm);
        $md = self::monthDues($jy, $jm);
        // cash paid in month (my share)
        $cash = 0;
        foreach (DB::all("SELECT p.amount, o.user_id, o.shared_user_id, o.owner_share FROM payments p JOIN dues d ON d.id=p.due_id JOIN obligations o ON o.id=d.obligation_id
            WHERE (o.user_id=? OR o.shared_user_id=?) AND p.paid_at BETWEEN ? AND ?", [$uid, $uid, "$from 00:00:00", "$to 23:59:59"]) as $p) $cash += $p['amount'] * Repo::share($p, $uid);
        // by category
        $cats = [];
        foreach ($md['dues'] as $d) { $k = $d['cat'] ?? 0; $cats[$k] = ($cats[$k] ?? 0) + $d['mine']; }
        arsort($cats);
        $catOut = [];
        foreach ($cats as $cid => $v) {
            $c = $cid ? DB::row("SELECT name,emoji,budget FROM categories WHERE id=?", [$cid]) : null;
            $spent = $cid && $c && $c['budget'] > 0 ? Repo::catSpent($uid, (int)$cid, $jy, $jm) : null;
            $catOut[] = ['id' => (int)$cid, 'name' => $c['name'] ?? 'بدون دسته', 'emoji' => $c['emoji'] ?? '📦', 'amount' => (int)round($v),
                'budget' => (int)($c['budget'] ?? 0), 'spent' => $spent];
        }
        // forecast 6 months (from selected month)
        $fc = [];
        for ($k = 0; $k < 6; $k++) {
            [$y, $m] = self::shiftYm($off + $k);
            $ym = J::ym($y, $m);
            $inc = 0; foreach (Plan::incomes($uid) as $i) $inc += Plan::amountFor($i, $ym);
            $mm = self::monthDues($y, $m);
            $fc[] = ['jy' => $y, 'jm' => $m, 'income' => $inc, 'due' => $mm['total'], 'free' => $inc - $mm['total']];
        }
        // on-time ratio, last 6 months
        [$sy, $sm] = self::shiftYm(-5);
        $onTime = 0; $count = 0;
        foreach (Repo::duesInRange($uid, J::toG($sy, $sm, 1), date('Y-m-d', strtotime('-1 day'))) as $d) {
            $count++;
            if ($d['status'] === 'paid' && substr((string)$d['paid_at'], 0, 10) <= $d['due_date']) $onTime++;
        }
        // top commitments & loans timeline
        $manual = self::manualMap();
        $top = []; $loans = []; $debt = 0; $leftCnt = 0;
        foreach (Repo::obs($uid) as $ob) {
            $sh = Repo::share($ob, $uid);
            $eq = self::monthlyEq($ob) * $sh;
            if ($eq > 0) $top[] = ['id' => (int)$ob['id'], 'title' => $ob['title'], 'type' => $ob['type'], 'monthly' => (int)round($eq),
                'period_unit' => $ob['period_unit'], 'period_n' => (int)$ob['period_n'], 'source' => $manual[(int)$ob['id']]['title'] ?? null,
                'until' => $ob['type'] === 'loan' ? DB::val("SELECT MAX(due_date) FROM dues WHERE obligation_id=?", [$ob['id']]) : null, 'share' => (int)round($sh * 100)];
            if ($ob['type'] === 'loan') {
                $st = Repo::stats((int)$ob['id']);
                $lc = (int)$st['cnt'] - (int)$st['paid_cnt'];
                $debt += ($st['total'] - $st['paid']) * $sh; $leftCnt += $lc;
                $loans[] = ['id' => (int)$ob['id'], 'title' => $ob['title'], 'end' => DB::val("SELECT MAX(due_date) FROM dues WHERE obligation_id=?", [$ob['id']]),
                    'left_cnt' => $lc, 'left' => (int)round(($st['total'] - $st['paid']) * $sh), 'monthly' => (int)round($eq), 'share' => (int)round($sh * 100)];
            }
        }
        usort($top, fn($a, $b) => $b['monthly'] <=> $a['monthly']);
        usort($loans, fn($a, $b) => strcmp($a['end'], $b['end']));
        // yearly
        $year = [];
        for ($m = 1; $m <= 12; $m++) {
            [$f, $t] = J::monthRange($jy, $m);
            $due = 0; $paid = 0;
            foreach (Repo::duesInRange($uid, $f, $t) as $d) { $s = Repo::share($d, $uid); $due += $d['amount'] * $s; $paid += $d['paid_amount'] * $s; }
            $year[] = ['jm' => $m, 'due' => (int)round($due), 'paid' => (int)round($paid)];
        }
        return [
            'jy' => $jy, 'jm' => $jm,
            'kpi' => ['due' => $md['total'], 'paid' => $md['total'] - $md['left'], 'cash' => (int)round($cash), 'income' => $plan['income'],
                'committed' => $plan['used'] + $plan['unassigned_sum'], 'debt' => (int)round($debt), 'loans' => count($loans), 'left_cnt' => $leftCnt,
                'on_time' => $onTime, 'count' => $count],
            'cats' => $catOut, 'forecast' => $fc, 'top' => array_slice($top, 0, 8), 'loans' => $loans, 'year' => $year,
            'split' => array_map(fn($o) => ['title' => $o['title'], 'amount' => $o['amount'], 'free' => $o['free'],
                'parts' => array_values(array_reduce($o['items'], function ($acc, $it) { $k = $it['ob']; $acc[$k] = ['title' => $it['title'], 'amount' => ($acc[$k]['amount'] ?? 0) + $it['alloc']]; return $acc; }, []))], $plan['occs']),
        ];
    }

    private static function a_excel(array $in): array {
        $jy = self::int($in['year'] ?? 0);
        Reports::excel(self::$u, $jy ?: null);
        return [];
    }

    private static function a_send_summary(array $in): array {
        [$jy, $jm] = self::shiftYm(self::int($in['off'] ?? 0));
        Reminders::monthlySummary(self::$u, $jy, $jm);
        return [];
    }

    private static function a_settings(array $in): array {
        $days = array_values(array_unique(array_filter(array_map('intval', (array)($in['remind_days'] ?? [])), fn($d) => $d >= 0 && $d <= 30)));
        rsort($days);
        DB::q("UPDATE users SET remind_days=?, remind_hour=?, overdue_daily=?, monthly_summary=?, income_remind=?, last_remind_date=IF(remind_hour<>?, NULL, last_remind_date) WHERE id=?", [
            implode(',', $days), max(0, min(23, self::int($in['remind_hour'] ?? 9))), empty($in['overdue_daily']) ? 0 : 1,
            empty($in['monthly_summary']) ? 0 : 1, empty($in['income_remind']) ? 0 : 1, max(0, min(23, self::int($in['remind_hour'] ?? 9))), self::$uid]);
        return [];
    }

    private static function a_cat_save(array $in): array {
        $name = trim(mb_substr((string)($in['name'] ?? ''), 0, 60));
        if ($name === '') throw new ApiError('bad_input');
        $emoji = trim(mb_substr((string)($in['emoji'] ?? ''), 0, 8)) ?: '📁';
        $budget = max(0, self::int($in['budget'] ?? 0));
        $id = self::int($in['id'] ?? 0);
        if ($id) {
            if (!Repo::cat($id, self::$uid)) throw new ApiError('not_found');
            DB::q("UPDATE categories SET name=?, emoji=?, budget=? WHERE id=?", [$name, $emoji, $budget, $id]);
        } else {
            $sort = (int)DB::val("SELECT COALESCE(MAX(sort),0)+1 FROM categories WHERE user_id=?", [self::$uid]);
            $id = DB::insert("INSERT INTO categories (user_id,name,emoji,budget,sort) VALUES (?,?,?,?,?)", [self::$uid, $name, $emoji, $budget, $sort]);
        }
        return ['id' => $id];
    }

    private static function a_cat_del(array $in): array {
        $id = self::int($in['id'] ?? 0);
        if (!Repo::cat($id, self::$uid)) throw new ApiError('not_found');
        DB::q("UPDATE obligations SET category_id=NULL WHERE category_id=?", [$id]);
        DB::q("DELETE FROM categories WHERE id=? AND user_id=?", [$id, self::$uid]);
        return [];
    }
}
