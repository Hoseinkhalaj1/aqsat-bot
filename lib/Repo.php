<?php
class Repo {
    const DEFAULT_CATS = [['🏦', 'وام'], ['🛒', 'خرید قسطی'], ['🏠', 'اجاره و مسکن'], ['💡', 'قبوض'], ['📱', 'اشتراک‌ها'], ['🎓', 'آموزش و شهریه'], ['🚗', 'خودرو'], ['📦', 'سایر']];
    const TYPES = ['loan' => '🏦 وام/قسط', 'recurring' => '🔁 دوره‌ای', 'once' => '📌 یک‌باره'];

    /* ===== Users ===== */
    public static function user(int $id): ?array { return DB::row("SELECT * FROM users WHERE id=?", [$id]); }

    public static function touchUser(array $from): array {
        $id = (int)$from['id'];
        $name = trim(($from['first_name'] ?? '') . ' ' . ($from['last_name'] ?? ''));
        $u = self::user($id);
        $isAdmin = in_array($id, array_map('intval', cfg('admin_ids', [])), true) ? 1 : 0;
        if (!$u) {
            DB::q("INSERT INTO users (id,name,username,is_allowed,is_admin,created_at) VALUES (?,?,?,?,?,NOW())", [$id, $name, $from['username'] ?? null, $isAdmin, $isAdmin]);
            if ($isAdmin) self::seedCats($id);
        } else {
            DB::q("UPDATE users SET username=?, is_admin=?, is_allowed=IF(?=1,1,is_allowed) WHERE id=?", [$from['username'] ?? null, $isAdmin, $isAdmin, $id]);
            if ($u['name'] === '') DB::q("UPDATE users SET name=? WHERE id=?", [$name, $id]);
        }
        return self::user($id);
    }

    public static function allow(int $id, string $name = '') {
        if (!self::user($id)) DB::q("INSERT INTO users (id,name,is_allowed,created_at) VALUES (?,?,1,NOW())", [$id, $name]);
        else DB::q("UPDATE users SET is_allowed=1" . ($name !== '' ? ", name=" . DB::pdo()->quote($name) : '') . " WHERE id=?", [$id]);
        if (!DB::val("SELECT COUNT(*) FROM categories WHERE user_id=?", [$id])) self::seedCats($id);
    }
    public static function allowedUsers(): array { return DB::all("SELECT * FROM users WHERE is_allowed=1 ORDER BY created_at"); }
    public static function setState(int $uid, ?string $state, array $data = []) {
        DB::q("UPDATE users SET state=?, state_data=? WHERE id=?", [$state, $state ? json_encode($data, JSON_UNESCAPED_UNICODE) : null, $uid]);
    }
    public static function uname(?int $id): string { if (!$id) return '-'; $n = DB::val("SELECT name FROM users WHERE id=?", [$id]); return $n ?: (string)$id; }

    /* ===== Categories ===== */
    public static function seedCats(int $uid) {
        foreach (self::DEFAULT_CATS as $i => [$e, $n]) DB::q("INSERT INTO categories (user_id,name,emoji,sort) VALUES (?,?,?,?)", [$uid, $n, $e, $i]);
    }
    public static function cats(int $uid): array { return DB::all("SELECT * FROM categories WHERE user_id=? ORDER BY sort,id", [$uid]); }
    public static function cat(int $id, int $uid): ?array { return DB::row("SELECT * FROM categories WHERE id=? AND user_id=?", [$id, $uid]); }
    public static function catLabel(?int $id): string {
        if (!$id) return '📦 بدون دسته';
        $c = DB::row("SELECT name,emoji FROM categories WHERE id=?", [$id]);
        return $c ? $c['emoji'] . ' ' . $c['name'] : '📦 بدون دسته';
    }

    /* ===== Obligations ===== */
    public static function ob(int $id, int $uid): ?array {
        return DB::row("SELECT * FROM obligations WHERE id=? AND (user_id=? OR shared_user_id=?)", [$id, $uid, $uid]);
    }
    public static function obs(int $uid, ?string $type = null, string $status = 'active'): array {
        $sql = "SELECT * FROM obligations WHERE (user_id=? OR shared_user_id=?) AND status" . ($status === 'active' ? "='active'" : "<>'active'");
        $p = [$uid, $uid];
        if ($type) { $sql .= " AND type=?"; $p[] = $type; }
        return DB::all($sql . " ORDER BY type, id DESC", $p);
    }
    /** user's share ratio of an obligation */
    public static function share(array $ob, int $uid): float {
        if (!$ob['shared_user_id']) return 1.0;
        return ((int)$ob['user_id'] === $uid ? (int)$ob['owner_share'] : 100 - (int)$ob['owner_share']) / 100;
    }
    public static function partnerOf(array $ob, int $uid): ?int {
        if (!$ob['shared_user_id']) return null;
        return (int)$ob['user_id'] === $uid ? (int)$ob['shared_user_id'] : (int)$ob['user_id'];
    }

    /**
     * $d: type,title,category_id,creditor,amount,amounts[] (loan),first_date (G),period_unit,period_n,paid_count,shared_user_id,owner_share
     */
    public static function createOb(int $uid, array $d): int {
        $first = $d['first_date'];
        [$jy, $jm, $jd] = J::fromG($first);
        $id = DB::insert("INSERT INTO obligations (user_id,type,title,category_id,creditor,period_unit,period_n,anchor_day,amount,shared_user_id,owner_share,created_at)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,NOW())", [
            $uid, $d['type'], $d['title'], $d['category_id'] ?: null, $d['creditor'] ?? null,
            $d['period_unit'] ?? null, (int)($d['period_n'] ?? 1), $jd, (int)($d['amount'] ?? 0),
            $d['shared_user_id'] ?? null, (int)($d['owner_share'] ?? 100),
        ]);
        if ($d['type'] === 'loan') {
            $paidCount = (int)($d['paid_count'] ?? 0);
            foreach ($d['amounts'] as $i => $amt) {
                $date = J::addMonths($jy, $jm, $jd, $i * (int)$d['period_n']);
                $paid = $i < $paidCount;
                $dueId = DB::insert("INSERT INTO dues (obligation_id,seq,due_date,amount,paid_amount,status,paid_at) VALUES (?,?,?,?,?,?,?)",
                    [$id, $i + 1, $date, $amt, $paid ? $amt : 0, $paid ? 'paid' : 'pending', $paid ? $date . ' 12:00:00' : null]);
                if ($paid) DB::q("INSERT INTO payments (due_id,payer_id,amount,paid_at) VALUES (?,?,?,?)", [$dueId, $uid, $amt, $date . ' 12:00:00']);
            }
            self::checkDone($id);
        } elseif ($d['type'] === 'once') {
            DB::q("INSERT INTO dues (obligation_id,seq,due_date,amount) VALUES (?,?,?,?)", [$id, 1, $first, (int)$d['amount']]);
        } else {
            DB::q("INSERT INTO dues (obligation_id,seq,due_date,amount) VALUES (?,?,?,?)", [$id, 1, $first, (int)$d['amount']]);
            self::ensureRecurring(DB::row("SELECT * FROM obligations WHERE id=?", [$id]));
        }
        return $id;
    }

    public static function nextDate(array $ob, string $after, int $seq): string {
        $n = max(1, (int)$ob['period_n']);
        if ($ob['period_unit'] === 'week') return date('Y-m-d', strtotime("$after +" . (7 * $n) . " days"));
        $months = $ob['period_unit'] === 'year' ? 12 * $n : $n;
        $first = DB::val("SELECT due_date FROM dues WHERE obligation_id=? ORDER BY seq LIMIT 1", [$ob['id']]) ?: $after;
        [$jy, $jm] = J::fromG($first);
        return J::addMonths($jy, $jm, (int)$ob['anchor_day'], $months * $seq);
    }

    /** keep recurring dues generated ~45 days ahead */
    public static function ensureRecurring(array $ob) {
        if ($ob['type'] !== 'recurring' || $ob['status'] !== 'active') return;
        $horizon = date('Y-m-d', strtotime('+200 days'));
        $last = DB::row("SELECT seq,due_date FROM dues WHERE obligation_id=? ORDER BY seq DESC LIMIT 1", [$ob['id']]);
        $guard = 0;
        while ($last && $last['due_date'] < $horizon && $guard++ < 120) {
            $seq = (int)$last['seq'] + 1;
            $date = self::nextDate($ob, $last['due_date'], $seq - 1);
            DB::q("INSERT INTO dues (obligation_id,seq,due_date,amount) VALUES (?,?,?,?)", [$ob['id'], $seq, $date, $ob['amount']]);
            $last = ['seq' => $seq, 'due_date' => $date];
        }
    }
    public static function ensureAllRecurring() {
        foreach (DB::all("SELECT * FROM obligations WHERE type='recurring' AND status='active'") as $ob) self::ensureRecurring($ob);
    }

    public static function checkDone(int $obId) {
        $ob = DB::row("SELECT * FROM obligations WHERE id=?", [$obId]);
        if (!$ob || $ob['type'] === 'recurring') return;
        $left = (int)DB::val("SELECT COUNT(*) FROM dues WHERE obligation_id=? AND status<>'paid'", [$obId]);
        DB::q("UPDATE obligations SET status=? WHERE id=? AND status<>'stopped'", [$left ? 'active' : 'done', $obId]);
    }

    public static function stats(int $obId): array {
        return DB::row("SELECT COUNT(*) cnt, SUM(status='paid') paid_cnt, COALESCE(SUM(amount),0) total, COALESCE(SUM(paid_amount),0) paid,
            MIN(CASE WHEN status<>'paid' THEN due_date END) next_date FROM dues WHERE obligation_id=?", [$obId]);
    }

    public static function deleteOb(int $id) { DB::q("DELETE FROM obligations WHERE id=?", [$id]); }

    public static function stopRecurring(int $id) {
        DB::q("UPDATE obligations SET status='stopped' WHERE id=?", [$id]);
        DB::q("DELETE FROM dues WHERE obligation_id=? AND status='pending' AND due_date>?", [$id, date('Y-m-d')]);
    }
    public static function resume(int $id) {
        DB::q("UPDATE obligations SET status='active' WHERE id=?", [$id]);
        $ob = DB::row("SELECT * FROM obligations WHERE id=?", [$id]);
        if ($ob['type'] === 'recurring') {
            // restart from next period after today
            $last = DB::row("SELECT seq,due_date FROM dues WHERE obligation_id=? ORDER BY seq DESC LIMIT 1", [$id]);
            $seq = (int)$last['seq'];
            $date = $last['due_date'];
            while ($date < date('Y-m-d')) { $date = self::nextDate($ob, $date, $seq); $seq++; }
            if ($date !== $last['due_date']) DB::q("INSERT INTO dues (obligation_id,seq,due_date,amount) VALUES (?,?,?,?)", [$id, $seq + 1, $date, $ob['amount']]);
            self::ensureRecurring($ob);
        } else self::checkDone($id);
    }

    /* ===== Dues & payments ===== */
    public static function due(int $id, int $uid): ?array {
        $d = DB::row("SELECT d.*, o.user_id, o.shared_user_id, o.owner_share, o.title, o.type, o.category_id, o.creditor FROM dues d JOIN obligations o ON o.id=d.obligation_id
            WHERE d.id=? AND (o.user_id=? OR o.shared_user_id=?)", [$id, $uid, $uid]);
        return $d;
    }
    public static function dues(int $obId, int $offset = 0, int $limit = 1000): array {
        return DB::all("SELECT * FROM dues WHERE obligation_id=? ORDER BY seq LIMIT $limit OFFSET $offset", [$obId]);
    }
    public static function nextDue(int $obId): ?array { return DB::row("SELECT * FROM dues WHERE obligation_id=? AND status<>'paid' ORDER BY due_date,seq LIMIT 1", [$obId]); }

    /** pay a due; $amount null = remaining. returns paid amount */
    public static function pay(int $dueId, int $payer, ?int $amount = null): int {
        $d = DB::row("SELECT * FROM dues WHERE id=?", [$dueId]);
        $rem = (int)$d['amount'] - (int)$d['paid_amount'];
        if ($rem <= 0) return 0;
        $amt = $amount === null ? $rem : min($amount, $rem);
        DB::q("INSERT INTO payments (due_id,payer_id,amount,paid_at) VALUES (?,?,?,NOW())", [$dueId, $payer, $amt]);
        self::recalc($dueId);
        return $amt;
    }
    public static function undoPay(int $dueId): bool {
        $p = DB::row("SELECT id FROM payments WHERE due_id=? ORDER BY id DESC LIMIT 1", [$dueId]);
        if (!$p) return false;
        DB::q("DELETE FROM payments WHERE id=?", [$p['id']]);
        self::recalc($dueId);
        return true;
    }
    public static function recalc(int $dueId) {
        $d = DB::row("SELECT * FROM dues WHERE id=?", [$dueId]);
        $paid = (int)DB::val("SELECT COALESCE(SUM(amount),0) FROM payments WHERE due_id=?", [$dueId]);
        $st = $paid >= (int)$d['amount'] ? 'paid' : ($paid > 0 ? 'partial' : 'pending');
        $at = $st === 'paid' ? DB::val("SELECT MAX(paid_at) FROM payments WHERE due_id=?", [$dueId]) : null;
        DB::q("UPDATE dues SET paid_amount=?, status=?, paid_at=? WHERE id=?", [$paid, $st, $at, $dueId]);
        DB::q("UPDATE obligations SET status='active' WHERE id=? AND status='done'", [$d['obligation_id']]);
        self::checkDone((int)$d['obligation_id']);
    }
    public static function settle(int $obId, int $payer): int {
        $sum = 0;
        foreach (DB::all("SELECT id FROM dues WHERE obligation_id=? AND status<>'paid'", [$obId]) as $d) $sum += self::pay((int)$d['id'], $payer);
        return $sum;
    }
    public static function setDueAmount(int $dueId, int $amt) { DB::q("UPDATE dues SET amount=? WHERE id=?", [$amt, $dueId]); self::recalc($dueId); }
    public static function setDueDate(int $dueId, string $g) { DB::q("UPDATE dues SET due_date=? WHERE id=?", [$g, $dueId]); DB::q("DELETE FROM reminder_log WHERE due_id=?", [$dueId]); }

    /** dues of a user in a date range (owned or shared) */
    public static function duesInRange(int $uid, string $from, string $to): array {
        return DB::all("SELECT d.*, o.title, o.type, o.category_id, o.user_id, o.shared_user_id, o.owner_share, o.status ob_status FROM dues d JOIN obligations o ON o.id=d.obligation_id
            WHERE (o.user_id=? OR o.shared_user_id=?) AND d.due_date BETWEEN ? AND ? ORDER BY d.due_date, d.id", [$uid, $uid, $from, $to]);
    }
    public static function overdue(int $uid): array {
        return DB::all("SELECT d.*, o.title, o.type, o.category_id, o.user_id, o.shared_user_id, o.owner_share FROM dues d JOIN obligations o ON o.id=d.obligation_id
            WHERE (o.user_id=? OR o.shared_user_id=?) AND o.status='active' AND d.status<>'paid' AND d.due_date<? ORDER BY d.due_date", [$uid, $uid, date('Y-m-d')]);
    }
    public static function upcoming(int $uid, int $limit = 5): array {
        return DB::all("SELECT d.*, o.title, o.type, o.category_id, o.user_id, o.shared_user_id, o.owner_share FROM dues d JOIN obligations o ON o.id=d.obligation_id
            WHERE (o.user_id=? OR o.shared_user_id=?) AND o.status='active' AND d.status<>'paid' AND d.due_date>=? ORDER BY d.due_date LIMIT $limit", [$uid, $uid, date('Y-m-d')]);
    }

    /* ===== Budget ===== */
    /** returns [spent, budget] for category in current jalali month (user's share of payments) */
    public static function catSpent(int $uid, int $catId, int $jy, int $jm): int {
        [$from, $to] = J::monthRange($jy, $jm);
        $rows = DB::all("SELECT p.amount, o.user_id, o.shared_user_id, o.owner_share FROM payments p JOIN dues d ON d.id=p.due_id JOIN obligations o ON o.id=d.obligation_id
            WHERE o.category_id=? AND (o.user_id=? OR o.shared_user_id=?) AND p.paid_at BETWEEN ? AND ?", [$catId, $uid, $uid, "$from 00:00:00", "$to 23:59:59"]);
        $s = 0; foreach ($rows as $r) $s += $r['amount'] * self::share($r, $uid);
        return (int)round($s);
    }
    /** after a payment: alert owner if category budget exceeded this month */
    public static function budgetCheck(int $uid, ?int $catId): ?string {
        if (!$catId) return null;
        $c = self::cat($catId, $uid);
        if (!$c || $c['budget'] <= 0) return null;
        [$jy, $jm] = J::today();
        $spent = self::catSpent($uid, $catId, $jy, $jm);
        if ($spent <= $c['budget']) return null;
        $ym = J::ym($jy, $jm);
        if (DB::val("SELECT 1 FROM budget_alerts WHERE user_id=? AND category_id=? AND ym=?", [$uid, $catId, $ym])) return null;
        DB::q("INSERT INTO budget_alerts (user_id,category_id,ym) VALUES (?,?,?)", [$uid, $catId, $ym]);
        return "⚠️ <b>هشدار بودجه</b>\nپرداختی دسته {$c['emoji']} {$c['name']} در " . J::monthName($jy, $jm) . " از سقف بودجه گذشت.\n"
            . "بودجه: " . U::toman($c['budget']) . "\nپرداخت‌شده: " . U::toman($spent);
    }
}
