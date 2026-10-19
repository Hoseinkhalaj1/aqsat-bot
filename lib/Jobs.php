<?php
class Reminders {
    /** cron entry */
    public static function run(): array {
        $log = [];
        Repo::ensureAllRecurring();
        $today = date('Y-m-d'); $hour = (int)date('G');
        [$jy, $jm, $jd] = J::today();
        $ym = J::ym($jy, $jm);
        foreach (Repo::allowedUsers() as $u) {
            if ($hour < (int)$u['remind_hour']) continue;
            if ($u['last_remind_date'] !== $today) {
                $n = self::forUser($u);
                DB::q("UPDATE users SET last_remind_date=? WHERE id=?", [$today, $u['id']]);
                $log[] = "user {$u['id']}: $n reminders";
            }
            $ni = IncomeReminders::forUser($u);
            if ($ni) $log[] = "user {$u['id']}: $ni income reminders";
            if ($u['monthly_summary'] && $u['last_monthly'] !== $ym) {
                if ($jd <= 3) { self::monthlySummary($u, $jy, $jm); $log[] = "user {$u['id']}: monthly summary"; }
                DB::q("UPDATE users SET last_monthly=? WHERE id=?", [$ym, $u['id']]);
            }
        }
        // daily backup
        if ($hour >= (int)cfg('backup_hour', 3) && substr((string)DB::setting('last_backup'), 0, 10) !== $today) {
            $f = Backup::run();
            if ($f && cfg('backup_to_telegram', true))
                foreach (cfg('admin_ids', []) as $a) TG::doc($a, $f, '💾 بکاپ خودکار — ' . J::fmt($today));
            $log[] = 'backup: ' . ($f ? basename($f) : 'FAILED');
        }
        DB::q("DELETE FROM reminder_log WHERE sent_date < ?", [date('Y-m-d', strtotime('-60 days'))]);
        DB::q("DELETE FROM income_log WHERE sent_date < ?", [date('Y-m-d', strtotime('-60 days'))]);
        foreach (glob(APP_DIR . '/tmp/*') ?: [] as $f) if (is_file($f) && filemtime($f) < time() - 86400 && basename($f) !== '.htaccess') @unlink($f);
        DB::setting('last_cron', J::fmt($today) . ' ' . date('H:i'));
        return $log;
    }

    /** send due/overdue reminders for one user. $force: test mode (ignore log, don't write log) */
    public static function forUser(array $u, bool $force = false): int {
        $uid = (int)$u['id']; $today = date('Y-m-d');
        $days = array_map('intval', array_filter(explode(',', $u['remind_days']), 'strlen'));
        $items = [];
        if ($days) {
            $maxD = max($days);
            $rows = DB::all("SELECT d.*, o.title, o.type, o.category_id, o.creditor, o.user_id, o.shared_user_id, o.owner_share FROM dues d JOIN obligations o ON o.id=d.obligation_id
                WHERE (o.user_id=? OR o.shared_user_id=?) AND o.status='active' AND d.status<>'paid' AND d.due_date BETWEEN ? AND ? ORDER BY d.due_date",
                [$uid, $uid, $today, date('Y-m-d', strtotime("+$maxD days"))]);
            foreach ($rows as $d) if (in_array(U::daysBetween($today, $d['due_date']), $days, true)) $items[] = $d;
        }
        if ($u['overdue_daily']) foreach (Repo::overdue($uid) as $d) {
            $d['creditor'] = DB::val("SELECT creditor FROM obligations WHERE id=?", [$d['obligation_id']]);
            $items[] = $d;
        }
        $send = [];
        foreach ($items as $d) {
            if (!$force && DB::val("SELECT 1 FROM reminder_log WHERE due_id=? AND user_id=? AND sent_date=?", [$d['id'], $uid, $today])) continue;
            $send[] = $d;
        }
        if (!$send) return 0;
        if (count($send) > 8) { // one combined message
            $t = "🔔 <b>یادآوری پرداخت‌ها</b>\n\n";
            foreach ($send as $d) {
                $rem = ($d['amount'] - $d['paid_amount']) * Repo::share($d, $uid);
                $t .= ($d['due_date'] < $today ? '🔴 ' : '⏳ ') . U::h($d['title']) . ' — ' . J::fmt($d['due_date']) . ' (' . U::relDay($d['due_date']) . ') — ' . U::toman($rem) . "\n";
            }
            TG::send($uid, $t, U::ikb([[U::btn('📅 این ماه', 'm:0'), U::btn('🔴 معوقه‌ها', 'ov')]]));
        } else {
            foreach ($send as $d) TG::send($uid, self::text($d, $uid), U::ikb([[U::btn('✅ پرداخت شد', "d:pay:{$d['id']}:n"), U::btn('💵 جزئی', "d:part:{$d['id']}")], [U::btn('👁 جزئیات', "o:v:{$d['obligation_id']}")]]));
        }
        if (!$force) foreach ($send as $d) DB::q("INSERT IGNORE INTO reminder_log (due_id,user_id,sent_date) VALUES (?,?,?)", [$d['id'], $uid, $today]);
        return count($send);
    }

    private static function text(array $d, int $uid): string {
        $today = date('Y-m-d');
        $rem = $d['amount'] - $d['paid_amount'];
        $sh = Repo::share($d, $uid);
        $over = $d['due_date'] < $today;
        $t = ($over ? "🔴 <b>پرداخت معوقه</b>" : ($d['due_date'] === $today ? "⏰ <b>امروز موعد پرداخت است</b>" : "🔔 <b>یادآوری پرداخت</b>")) . "\n\n";
        $t .= "📝 <b>" . U::h($d['title']) . "</b>" . ($d['type'] === 'loan' ? ' — قسط ' . U::fa($d['seq']) : '') . "\n";
        $t .= "📅 سررسید: " . J::fmt($d['due_date']) . ' ' . J::weekday($d['due_date']) . ' (' . U::relDay($d['due_date']) . ")\n";
        $t .= "💰 مبلغ: <b>" . U::toman($rem) . "</b>" . ($sh < 1 ? "\n👥 سهم شما: " . U::toman($rem * $sh) : '') . "\n";
        $t .= "🗂 " . U::h(Repo::catLabel($d['category_id'] ? (int)$d['category_id'] : null));
        if (!empty($d['creditor'])) $t .= "\n👤 " . U::h($d['creditor']);
        return $t;
    }

    public static function monthlySummary(array $u, int $jy, int $jm) {
        $uid = (int)$u['id'];
        [$from, $to] = J::monthRange($jy, $jm);
        $rows = Repo::duesInRange($uid, $from, $to);
        $t = "🗓 <b>خلاصه پرداخت‌های " . J::monthName($jy, $jm) . "</b>\n\n";
        $sum = 0;
        foreach ($rows as $d) {
            $sh = Repo::share($d, $uid); $sum += $d['amount'] * $sh;
            [, , $dd] = J::fromG($d['due_date']);
            $t .= ($d['status'] === 'paid' ? '✅ ' : '• ') . U::fa($dd) . ' ' . J::MONTHS[$jm] . ' — ' . U::h($d['title']) . ' — ' . U::toman($d['amount'] * $sh) . "\n";
        }
        if (!$rows) $t .= "پرداختی در این ماه ندارید 🎉\n";
        else $t .= "\n💰 جمع کل ماه: <b>" . U::toman($sum) . "</b>";
        $ov = Repo::overdue($uid);
        if ($ov) $t .= "\n🔴 " . U::fa(count($ov)) . " پرداخت معوقه از قبل دارید.";
        TG::send($uid, $t, U::ikb([[U::btn('📅 مشاهده این ماه', 'm:0')]]));
    }
}

class Backup {
    /** dump DB to backups/*.sql.gz, keep last N. returns path or null */
    public static function run(): ?string {
        try {
            $dir = APP_DIR . '/backups';
            if (!is_dir($dir)) @mkdir($dir, 0750, true);
            if (!file_exists("$dir/.htaccess")) @file_put_contents("$dir/.htaccess", "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n");
            $pdo = DB::pdo();
            $sql = "-- Aqsat bot backup " . date('Y-m-d H:i:s') . "\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n";
            foreach (['users', 'categories', 'obligations', 'dues', 'payments', 'reminder_log', 'budget_alerts', 'settings', 'incomes', 'income_overrides', 'alloc_map', 'set_aside', 'income_log', 'deposits'] as $tb) {
                $create = $pdo->query("SHOW CREATE TABLE `$tb`")->fetch(PDO::FETCH_NUM)[1];
                $sql .= "DROP TABLE IF EXISTS `$tb`;\n$create;\n";
                $st = $pdo->query("SELECT * FROM `$tb`");
                $batch = [];
                while ($r = $st->fetch(PDO::FETCH_NUM)) {
                    $batch[] = '(' . implode(',', array_map(fn($v) => $v === null ? 'NULL' : $pdo->quote((string)$v), $r)) . ')';
                    if (count($batch) >= 200) { $sql .= "INSERT INTO `$tb` VALUES " . implode(",\n", $batch) . ";\n"; $batch = []; }
                }
                if ($batch) $sql .= "INSERT INTO `$tb` VALUES " . implode(",\n", $batch) . ";\n";
                $sql .= "\n";
            }
            $sql .= "SET FOREIGN_KEY_CHECKS=1;\n";
            $file = "$dir/aqsat-backup-" . date('Ymd-His') . ".sql.gz";
            file_put_contents($file, gzencode($sql, 9));
            $all = glob("$dir/aqsat-backup-*.sql.gz") ?: [];
            sort($all);
            while (count($all) > (int)cfg('backup_keep', 7)) @unlink(array_shift($all));
            DB::setting('last_backup', date('Y-m-d H:i') . ' | ' . J::fmt(date('Y-m-d')) . ' ' . date('H:i'));
            return $file;
        } catch (Throwable $e) {
            logError('backup: ' . $e->getMessage());
            return null;
        }
    }
}
