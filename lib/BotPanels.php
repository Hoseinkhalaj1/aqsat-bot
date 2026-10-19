<?php
trait BotPanels {
    /* ================= Categories ================= */
    public static function catList(string $prefix = '') {
        $uid = self::uid();
        [$jy, $jm] = J::today();
        $t = $prefix . "🗂 <b>دسته‌بندی‌ها</b> — " . J::monthName($jy, $jm) . "\n\n";
        foreach (Repo::cats($uid) as $c) {
            $n = (int)DB::val("SELECT COUNT(*) FROM obligations WHERE category_id=? AND status='active'", [$c['id']]);
            $t .= $c['emoji'] . ' <b>' . U::h($c['name']) . '</b> (' . U::fa($n) . ' مورد)';
            if ($c['budget'] > 0) {
                $sp = Repo::catSpent($uid, (int)$c['id'], $jy, $jm);
                $t .= "\n   " . U::bar($sp / $c['budget'], 8) . ' — ' . U::n($sp) . ' از ' . U::n($c['budget']) . ($sp > $c['budget'] ? ' ⚠️' : '');
            }
            $t .= "\n";
        }
        $t .= "\nبرای ویرایش نام، تعیین سقف بودجه ماهانه یا حذف، روی دسته بزنید.";
        $kb = self::catButtons($uid, 'c:v:');
        $kb[] = [U::btn('➕ دسته جدید', 'c:add')];
        self::out($t, U::ikb($kb));
    }

    public static function catView(int $id, string $prefix = '') {
        $uid = self::uid();
        $c = Repo::cat($id, $uid);
        if (!$c) { self::catList(); return; }
        [$jy, $jm] = J::today();
        $sp = Repo::catSpent($uid, $id, $jy, $jm);
        $obs = DB::all("SELECT title FROM obligations WHERE category_id=? AND status='active' LIMIT 15", [$id]);
        $t = $prefix . $c['emoji'] . ' <b>' . U::h($c['name']) . "</b>\n\n";
        $t .= "💸 پرداختی " . J::monthName($jy, $jm) . ': ' . U::toman($sp) . "\n";
        $t .= "🎯 سقف بودجه ماهانه: " . ($c['budget'] > 0 ? U::toman($c['budget']) . "\n" . U::bar($sp / $c['budget']) : 'تعیین نشده') . "\n";
        if ($obs) $t .= "\n<b>موارد فعال:</b>\n" . implode("\n", array_map(fn($o) => '• ' . U::h($o['title']), $obs));
        self::out($t, U::ikb([
            [U::btn('✏️ تغییر نام/ایموجی', "c:ren:$id"), U::btn('🎯 سقف بودجه', "c:bud:$id")],
            [U::btn('🗑 حذف دسته', "c:del:$id")],
            [U::btn('🔙 دسته‌ها', 'c:list')],
        ]));
    }

    private static function catAction(string $act, int $id) {
        $uid = self::uid();
        switch ($act) {
            case 'list': self::catList(); return;
            case 'v': self::catView($id); return;
            case 'add':
                self::setState('cat_add');
                self::out("➕ ایموجی و نام دسته جدید را بنویسید:\n<i>مثال: 🏋️ باشگاه</i>", self::cancelKb());
                return;
            case 'ren':
                self::setState('cat_ren', ['id' => $id]);
                self::out("✏️ نام جدید را بنویسید (می‌توانید ایموجی را هم اول آن بنویسید):\n<i>مثال: 🏠 اجاره</i>", self::cancelKb());
                return;
            case 'bud':
                self::setState('cat_bud', ['id' => $id]);
                self::out("🎯 سقف بودجه ماهانه این دسته را به تومان بنویسید.\nاگر پرداختی‌های ماه از آن بیشتر شود هشدار می‌گیرید.\n<i>برای حذف بودجه 0 بنویسید.</i>", self::cancelKb());
                return;
            case 'del':
                $c = Repo::cat($id, $uid); if (!$c) return;
                self::out("🗑 دسته «" . U::h($c['name']) . "» حذف شود؟\nموارد این دسته بدون دسته می‌شوند (حذف نمی‌شوند).", U::ikb([[U::btn('🗑 بله', "c:del2:$id"), U::btn('🔙 خیر', "c:v:$id")]]));
                return;
            case 'del2':
                if (!Repo::cat($id, $uid)) return;
                DB::q("UPDATE obligations SET category_id=NULL WHERE category_id=?", [$id]);
                DB::q("DELETE FROM categories WHERE id=? AND user_id=?", [$id, $uid]);
                self::catList("🗑 حذف شد.\n\n");
                return;
        }
    }

    /* ================= Settings ================= */
    public static function settings(string $prefix = '') {
        $u = Repo::user(self::uid()); self::$u = array_merge(self::$u, $u);
        $days = array_map('intval', array_filter(explode(',', $u['remind_days']), 'strlen'));
        $dl = $days ? implode('، ', array_map(fn($d) => $d === 0 ? 'روز سررسید' : U::fa($d) . ' روز قبل', $days)) : 'خاموش';
        $t = $prefix . "⚙️ <b>تنظیمات یادآوری</b>\n\n";
        $t .= "🔔 زمان یادآوری: $dl\n";
        $t .= "⏰ ساعت ارسال: " . U::fa(sprintf('%02d:00', $u['remind_hour'])) . "\n";
        $t .= "🔴 یادآوری روزانه معوقه‌ها: " . ($u['overdue_daily'] ? 'روشن' : 'خاموش') . "\n";
        $t .= "🗓 خلاصه اول هر ماه: " . ($u['monthly_summary'] ? 'روشن' : 'خاموش') . "\n";
        $t .= "💵 پرسش «واریز شد؟» برای درآمدها: " . ($u['income_remind'] ? 'روشن' : 'خاموش') . "\n";
        $t .= "\nروی روزها بزنید تا روشن/خاموش شوند:";
        $row = [];
        foreach ([7, 5, 3, 2, 1, 0] as $d) $row[] = U::btn((in_array($d, $days, true) ? '✅ ' : '') . ($d === 0 ? 'روز موعد' : U::fa($d) . ' روز'), "s:d:$d");
        self::out($t, U::ikb([
            array_slice($row, 0, 3), array_slice($row, 3),
            [U::btn('⏰ تغییر ساعت', 's:h')],
            [U::btn(($u['overdue_daily'] ? '✅' : '⬜️') . ' یادآوری معوقه', 's:o'), U::btn(($u['monthly_summary'] ? '✅' : '⬜️') . ' خلاصه ماهانه', 's:m')],
            [U::btn(($u['income_remind'] ? '✅' : '⬜️') . ' پرسش واریز درآمد', 's:i')],
            [U::btn('🧪 تست یادآوری (همین الان)', 's:t')],
        ]));
    }

    private static function settingsAction(string $act, ?string $v) {
        $uid = self::uid(); $u = Repo::user($uid);
        switch ($act) {
            case 'd':
                $days = array_map('intval', array_filter(explode(',', $u['remind_days']), 'strlen'));
                $d = (int)$v;
                $days = in_array($d, $days, true) ? array_values(array_diff($days, [$d])) : array_merge($days, [$d]);
                rsort($days);
                DB::q("UPDATE users SET remind_days=? WHERE id=?", [implode(',', $days), $uid]);
                break;
            case 'h':
                if ($v === null) {
                    $kb = []; $row = [];
                    for ($h = 6; $h <= 23; $h++) { $row[] = U::btn(($h == $u['remind_hour'] ? '✅' : '') . U::fa($h), "s:h:$h"); if (count($row) === 6) { $kb[] = $row; $row = []; } }
                    $kb[] = [U::btn('🔙 بازگشت', 's:back')];
                    self::out('⏰ ساعت ارسال یادآوری‌ها را انتخاب کنید (به وقت تهران):', U::ikb($kb));
                    return;
                }
                DB::q("UPDATE users SET remind_hour=?, last_remind_date=NULL WHERE id=?", [max(0, min(23, (int)$v)), $uid]);
                break;
            case 'o': DB::q("UPDATE users SET overdue_daily=1-overdue_daily WHERE id=?", [$uid]); break;
            case 'm': DB::q("UPDATE users SET monthly_summary=1-monthly_summary WHERE id=?", [$uid]); break;
            case 'i': DB::q("UPDATE users SET income_remind=1-income_remind WHERE id=?", [$uid]); break;
            case 't':
                $n = Reminders::forUser(Repo::user($uid), true) + IncomeReminders::forUser(Repo::user($uid), true);
                self::toast($n ? U::fa($n) . ' یادآوری ارسال شد' : 'فعلاً یادآوری‌ای برای ارسال نیست', !$n);
                return;
        }
        self::settings();
    }

    /* ================= Reports menu ================= */
    public static function reportsMenu() { Reports::menu(self::$u); }

    /* ================= Admin ================= */
    public static function adminMenu(string $prefix = '') {
        self::out($prefix . "🛠 <b>پنل مدیریت</b>", U::ikb([
            [U::btn('👥 کاربران', 'a:u'), U::btn('➕ افزودن کاربر', 'a:add')],
            [U::btn('📊 آمار', 'a:st'), U::btn('💾 بکاپ فوری', 'a:bk')],
            [U::btn('📣 پیام همگانی', 'a:bc')],
        ]));
    }

    private static function adminUsers(string $prefix = '') {
        $t = $prefix . "👥 <b>کاربران مجاز</b>\n\n"; $kb = [];
        foreach (Repo::allowedUsers() as $u) {
            $t .= ($u['is_admin'] ? '👑 ' : '👤 ') . U::h($u['name'] ?: '-') . ($u['username'] ? ' @' . U::h($u['username']) : '') . " — <code>{$u['id']}</code>\n";
            if (!$u['is_admin']) $kb[] = [U::btn('❌ حذف دسترسی ' . ($u['name'] ?: $u['id']), "a:rm:{$u['id']}")];
        }
        $kb[] = [U::btn('➕ افزودن کاربر', 'a:add'), U::btn('🔙 مدیریت', 'a:m')];
        self::out($t, U::ikb($kb));
    }

    private static function adminAction(string $act, ?string $v) {
        switch ($act) {
            case 'm': self::adminMenu(); return;
            case 'u': self::adminUsers(); return;
            case 'add':
                self::setState('adm_add');
                self::out("➕ شناسه عددی تلگرام و نام کاربر را بنویسید:\n<code>123456789 علی</code>\n<i>کاربر می‌تواند با دستور /id شناسه‌اش را ببیند.</i>", self::cancelKb());
                return;
            case 'ok':
                Repo::allow((int)$v);
                TG::send((int)$v, "✅ دسترسی شما به ربات تأیید شد. /start را بزنید.");
                self::out("✅ کاربر <code>$v</code> تأیید شد.");
                return;
            case 'no': self::out("❌ درخواست <code>$v</code> رد شد."); return;
            case 'rm':
                $id = (int)$v;
                if (in_array($id, array_map('intval', cfg('admin_ids', [])), true)) return;
                DB::q("UPDATE users SET is_allowed=0 WHERE id=?", [$id]);
                self::adminUsers("❌ دسترسی حذف شد (اطلاعاتش باقی می‌ماند).\n\n");
                return;
            case 'st':
                $t = "📊 <b>آمار</b>\n\n";
                $t .= "کاربران مجاز: " . U::fa(DB::val("SELECT COUNT(*) FROM users WHERE is_allowed=1")) . "\n";
                $t .= "موارد فعال: " . U::fa(DB::val("SELECT COUNT(*) FROM obligations WHERE status='active'")) . "\n";
                $t .= "کل اقساط/پرداخت‌ها: " . U::fa(DB::val("SELECT COUNT(*) FROM dues")) . "\n";
                $t .= "پرداخت‌های ثبت‌شده: " . U::fa(DB::val("SELECT COUNT(*) FROM payments")) . "\n";
                $t .= "آخرین اجرای کرون: " . (($c = DB::setting('last_cron')) ? U::fa($c) : '❗️ هنوز اجرا نشده') . "\n";
                $t .= "آخرین بکاپ: " . (($b = DB::setting('last_backup')) ? U::fa(explode(' | ', $b)[1] ?? $b) : '-') . "\n";
                self::out($t, U::ikb([[U::btn('🔙 مدیریت', 'a:m')]]));
                return;
            case 'bk':
                self::toast('در حال تهیه بکاپ...');
                $f = Backup::run();
                if ($f) TG::doc(self::$chat, $f, '💾 بکاپ دیتابیس — ' . J::fmt(date('Y-m-d')) . ' ' . U::fa(date('H:i')));
                else TG::send(self::$chat, '❗️ خطا در تهیه بکاپ. لاگ را بررسی کنید.');
                return;
            case 'bc':
                self::setState('adm_bc');
                self::out('📣 متن پیام همگانی را بنویسید:', self::cancelKb());
                return;
        }
    }
}
