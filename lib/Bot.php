<?php
class Bot {
    use BotWizard, BotPanels, BotIncome, BotIncomeStats;
    const M_DASH = '📊 داشبورد', M_MONTH = '📅 این ماه', M_ADD = '➕ افزودن', M_LIST = '📋 لیست من', M_INC = '💵 درآمد و برنامه',
          M_REP = '📈 گزارش‌ها', M_CAT = '🗂 دسته‌بندی‌ها', M_SET = '⚙️ تنظیمات', M_ADMIN = '🛠 مدیریت', CANCEL = '❌ انصراف';
    const STATUS_ICON = ['paid' => '✅', 'partial' => '🟡', 'pending' => '⏳'];

    public static array $u;          // current user row
    public static int $chat;
    public static ?int $msgId = null; // set when handling a callback → edit instead of send
    public static ?string $cbId = null;

    /* ================= Entry ================= */
    public static function appUrl(): ?string {
        $b = rtrim((string)cfg('base_url'), '/');
        return strpos($b, 'https://') === 0 && is_file(APP_DIR . '/app/index.html') ? $b . '/app/' : null;
    }
    private static function ensureMenuButton() {
        $url = self::appUrl();
        if (!$url || DB::setting('menu_button') === $url) return;
        $r = TG::call('setChatMenuButton', ['menu_button' => ['type' => 'web_app', 'text' => 'اپ اقساط', 'web_app' => ['url' => $url]]]);
        if (!empty($r['ok'])) DB::setting('menu_button', $url);
    }

    public static function handle(array $up) {
        self::ensureMenuButton();
        if (isset($up['callback_query'])) {
            $cb = $up['callback_query'];
            self::$chat = (int)$cb['message']['chat']['id'];
            self::$msgId = (int)$cb['message']['message_id'];
            self::$cbId = $cb['id'];
            self::$u = Repo::touchUser($cb['from']);
            if (!self::$u['is_allowed'] && strpos($cb['data'], 'a:') !== 0) { TG::answer($cb['id'], 'دسترسی ندارید', true); return; }
            self::callback($cb['data']);
            if (self::$cbId) TG::answer(self::$cbId);
            return;
        }
        $m = $up['message'] ?? null;
        if (!$m || ($m['chat']['type'] ?? '') !== 'private') return;
        self::$chat = (int)$m['chat']['id'];
        self::$u = Repo::touchUser($m['from']);
        $text = trim($m['text'] ?? '');
        if (!self::$u['is_allowed']) { self::denied($m['from']); return; }
        self::message($text);
    }

    public static function out(string $text, $kb = null) {
        if (self::$msgId) TG::edit(self::$chat, self::$msgId, $text, $kb);
        else TG::send(self::$chat, $text, $kb);
    }
    public static function toastPublic(string $t, bool $alert = false) { self::toast($t, $alert); }
    private static function toast(string $t, bool $alert = false) { if (self::$cbId) { TG::answer(self::$cbId, $t, $alert); self::$cbId = null; } }
    private static function uid(): int { return (int)self::$u['id']; }
    private static function state(): ?string { return self::$u['state']; }
    private static function sdata(): array { return self::$u['state_data'] ? json_decode(self::$u['state_data'], true) : []; }
    private static function setState(?string $s, array $d = []) { Repo::setState(self::uid(), $s, $d); self::$u['state'] = $s; self::$u['state_data'] = $s ? json_encode($d, JSON_UNESCAPED_UNICODE) : null; }

    public static function mainKb(array $u): array {
        $rows = [[self::M_DASH, self::M_MONTH], [self::M_ADD, self::M_LIST], [self::M_INC, self::M_REP], [self::M_CAT, self::M_SET]];
        if ($u['is_admin']) $rows[] = [self::M_ADMIN];
        return ['keyboard' => array_map(fn($r) => array_map(fn($t) => ['text' => $t], $r), $rows), 'resize_keyboard' => true, 'is_persistent' => true];
    }
    private static function cancelKb(): array { return U::ikb([[U::btn(self::CANCEL, 'x')]]); }

    /* ================= Access ================= */
    private static function denied(array $from) {
        $id = (int)$from['id'];
        TG::send($id, "⛔️ این ربات خصوصی است.\nشناسه شما: <code>$id</code>\nدرخواست دسترسی برای مدیر ارسال شد.");
        $key = 'req_' . $id;
        if (DB::setting($key) === date('Y-m-d')) return;
        DB::setting($key, date('Y-m-d'));
        $name = U::h(trim(($from['first_name'] ?? '') . ' ' . ($from['last_name'] ?? '')));
        $un = isset($from['username']) ? ' @' . $from['username'] : '';
        foreach (cfg('admin_ids', []) as $a)
            TG::send($a, "🔐 درخواست دسترسی\n👤 $name$un\n🆔 <code>$id</code>", U::ikb([[U::btn('✅ تأیید', "a:ok:$id"), U::btn('❌ رد', "a:no:$id")]]));
    }

    /* ================= Messages ================= */
    private static function message(string $text) {
        if (in_array($text, ['/start', '/menu', '/cancel', self::CANCEL], true) || strpos($text, '/start') === 0) {
            self::setState(null);
            TG::send(self::$chat, $text === '/cancel' || $text === self::CANCEL ? 'لغو شد.' : "سلام " . U::h(self::$u['name']) . " 👋\nبه ربات مدیریت اقساط و پرداخت‌ها خوش آمدید.", self::mainKb(self::$u));
            if (strpos($text, '/start') === 0) {
                if ($url = self::appUrl()) TG::send(self::$chat, "📱 نسخه گرافیکی: داشبورد، تقویم، گزارش‌ها و برنامه درآمدها", ['inline_keyboard' => [[['text' => '📱 باز کردن اپ', 'web_app' => ['url' => $url]]]]]);
                self::dashboard();
            }
            return;
        }
        if ($text === '/id') { TG::send(self::$chat, 'شناسه شما: <code>' . self::uid() . '</code>'); return; }

        $menu = [self::M_DASH => 'dashboard', self::M_MONTH => 'month', self::M_ADD => 'addMenu', self::M_LIST => 'listMenu',
                 self::M_REP => 'reportsMenu', self::M_INC => 'incomeMenu', self::M_CAT => 'catList', self::M_SET => 'settings', self::M_ADMIN => 'adminMenu'];
        if (isset($menu[$text])) {
            self::setState(null);
            if ($text === self::M_ADMIN && !self::$u['is_admin']) return;
            $fn = $menu[$text];
            $fn === 'month' ? self::month(0) : self::$fn();
            return;
        }
        if ($text === '') return;
        if (self::state()) { self::stateInput($text); return; }
        TG::send(self::$chat, 'از منوی پایین یکی را انتخاب کنید.', self::mainKb(self::$u));
    }

    /* ================= Callback router ================= */
    private static function callback(string $data) {
        $p = explode(':', $data);
        $a = $p[0];
        $i1 = isset($p[1]) ? $p[1] : null;
        switch ($a) {
            case 'x': self::setState(null); self::out('لغو شد.'); return;
            case 'w': self::wizard(null, implode(':', array_slice($p, 1))); return;
            case 'dash': self::dashboard(); return;
            case 'm': self::month((int)$i1); return;
            case 'ov': self::overdueView(); return;
            case 'add': self::wizStart($i1); return;
            case 'l': self::listMenu($i1); return;
            case 'o': self::obAction($i1, (int)$p[2], $p[3] ?? null); return;
            case 'd': self::dueAction($i1, (int)$p[2], $p[3] ?? null); return;
            case 'e': self::editAction($i1, (int)$p[2], $p[3] ?? null); return;
            case 'c': self::catAction($i1, (int)($p[2] ?? 0)); return;
            case 's': self::settingsAction($i1, $p[2] ?? null); return;
            case 'i': self::incomeAction($i1, $p[2] ?? null, $p[3] ?? null); return;
            case 'r': Reports::action(self::$u, $i1, $p[2] ?? null); return;
            case 'a': if (self::$u['is_admin']) self::adminAction($i1, $p[2] ?? null); return;
        }
    }

    /* ================= Dashboard ================= */
    public static function dashboard() {
        $uid = self::uid();
        Repo::ensureAllRecurring();
        $obs = Repo::obs($uid);
        $remain = 0; $paid = 0; $loanLines = [];
        foreach ($obs as $ob) {
            if ($ob['type'] !== 'loan') continue;
            $st = Repo::stats((int)$ob['id']); $sh = Repo::share($ob, $uid);
            $remain += ($st['total'] - $st['paid']) * $sh; $paid += $st['paid'] * $sh;
            $loanLines[] = '• ' . U::h($ob['title']) . "\n   " . U::bar($st['total'] ? $st['paid'] / $st['total'] : 0) . ' (' . U::fa($st['paid_cnt']) . '/' . U::fa($st['cnt']) . ')';
        }
        [$jy, $jm] = J::today();
        [$from, $to] = J::monthRange($jy, $jm);
        $mDue = 0; $mPaid = 0;
        foreach (Repo::duesInRange($uid, $from, $to) as $d) { $sh = Repo::share($d, $uid); $mDue += $d['amount'] * $sh; $mPaid += $d['paid_amount'] * $sh; }
        $ov = Repo::overdue($uid);
        $ovSum = 0; foreach ($ov as $d) $ovSum += ($d['amount'] - $d['paid_amount']) * Repo::share($d, $uid);

        $t = "📊 <b>داشبورد</b> — " . J::fmt(date('Y-m-d'), true) . "\n\n";
        $t .= "💳 بدهی باقیمانده وام‌ها: <b>" . U::toman($remain) . "</b>\n";
        $t .= "✅ پرداخت‌شده وام‌ها: " . U::toman($paid) . "\n\n";
        $t .= "📅 <b>" . J::monthName($jy, $jm) . "</b>\n";
        $t .= "   کل این ماه: " . U::toman($mDue) . "\n   پرداخت‌شده: " . U::toman($mPaid) . "\n   مانده: " . U::toman(max(0, $mDue - $mPaid)) . "\n";
        if ($ov) $t .= "\n🔴 <b>" . U::fa(count($ov)) . " پرداخت معوقه</b> — " . U::toman($ovSum) . "\n";
        $up = Repo::upcoming($uid, 5);
        if ($up) {
            $t .= "\n⏭ <b>پرداخت‌های پیش رو</b>\n";
            foreach ($up as $d) $t .= '• ' . J::fmt($d['due_date']) . ' (' . U::relDay($d['due_date']) . ') — ' . U::h($d['title']) . ' — ' . U::toman(($d['amount'] - $d['paid_amount']) * Repo::share($d, $uid)) . "\n";
        }
        if ($loanLines) $t .= "\n🏦 <b>پیشرفت وام‌ها</b>\n" . implode("\n", $loanLines) . "\n";
        if (!$obs) $t .= "\nهنوز موردی ثبت نکرده‌اید. از «" . self::M_ADD . "» شروع کنید.";
        $kb = [[U::btn('📅 این ماه', 'm:0'), U::btn('📋 لیست من', 'l:all')]];
        if ($ov) $kb[] = [U::btn('🔴 معوقه‌ها', 'ov')];
        $kb[] = [U::btn('🔄 بروزرسانی', 'dash')];
        self::out($t, U::ikb($kb));
    }

    /* ================= This month ================= */
    public static function month(int $off) {
        $uid = self::uid();
        Repo::ensureAllRecurring();
        [$jy, $jm] = J::today();
        $t0 = $jy * 12 + $jm - 1 + $off; $jy = intdiv($t0, 12); $jm = $t0 % 12 + 1;
        [$from, $to] = J::monthRange($jy, $jm);
        $rows = Repo::duesInRange($uid, $from, $to);
        $today = date('Y-m-d');
        $t = "📅 <b>پرداخت‌های " . J::monthName($jy, $jm) . "</b>\n\n";
        $sum = 0; $paid = 0; $btns = [];
        foreach ($rows as $d) {
            $sh = Repo::share($d, $uid);
            $sum += $d['amount'] * $sh; $paid += $d['paid_amount'] * $sh;
            $icon = $d['status'] === 'paid' ? '✅' : ($d['due_date'] < $today ? '🔴' : self::STATUS_ICON[$d['status']]);
            [, , $jd] = J::fromG($d['due_date']);
            $t .= "$icon " . U::fa($jd) . ' ' . J::MONTHS[$jm] . ' — ' . U::h($d['title']) . ' — ' . U::toman($d['amount'] * $sh);
            if ($d['status'] === 'partial') $t .= ' (پرداخت‌شده ' . U::n($d['paid_amount'] * $sh) . ')';
            $t .= "\n";
            if ($d['status'] !== 'paid' && count($btns) < 16) $btns[] = [U::btn('✅ ' . mb_substr($d['title'], 0, 22) . ' ' . U::fa($jd) . ' ' . J::MONTHS[$jm], "d:pay:{$d['id']}:m$off")];
        }
        if (!$rows) $t .= "موردی در این ماه نیست.\n";
        else $t .= "\n💰 جمع: <b>" . U::toman($sum) . "</b>\n✅ پرداخت‌شده: " . U::toman($paid) . "\n⏳ مانده: " . U::toman(max(0, $sum - $paid));
        if ($btns) $t .= "\n\nبرای ثبت پرداخت روی هر مورد بزنید:";
        $btns[] = [U::btn('◀️ ماه قبل', 'm:' . ($off - 1)), U::btn('ماه جاری', 'm:0'), U::btn('ماه بعد ▶️', 'm:' . ($off + 1))];
        self::out($t, U::ikb($btns));
    }

    public static function overdueView() {
        $uid = self::uid();
        $ov = Repo::overdue($uid);
        $t = "🔴 <b>پرداخت‌های معوقه</b>\n\n"; $btns = [];
        foreach ($ov as $d) {
            $rem = ($d['amount'] - $d['paid_amount']) * Repo::share($d, $uid);
            $t .= '• ' . U::h($d['title']) . ' — ' . J::fmt($d['due_date']) . ' (' . U::relDay($d['due_date']) . ') — ' . U::toman($rem) . "\n";
            if (count($btns) < 16) $btns[] = [U::btn('✅ ' . mb_substr($d['title'], 0, 25) . ' ' . J::fmt($d['due_date']), "d:pay:{$d['id']}:ov")];
        }
        if (!$ov) $t .= 'هیچ پرداخت معوقه‌ای ندارید 👌';
        $btns[] = [U::btn('🔙 داشبورد', 'dash')];
        self::out($t, U::ikb($btns));
    }

    /* ================= List ================= */
    public static function listMenu(?string $f = 'all') {
        $uid = self::uid(); $f = $f ?: 'all';
        $obs = $f === 'arch' ? Repo::obs($uid, null, 'archived') : Repo::obs($uid, $f === 'all' ? null : $f);
        $title = ['all' => 'همه موارد فعال', 'loan' => 'وام‌ها و اقساط', 'recurring' => 'پرداخت‌های دوره‌ای', 'once' => 'پرداخت‌های یک‌باره', 'arch' => 'تمام‌شده / متوقف'][$f] ?? '';
        $t = "📋 <b>$title</b>\n";
        $btns = [];
        foreach ($obs as $ob) {
            $next = Repo::nextDue((int)$ob['id']);
            $icon = explode(' ', Repo::TYPES[$ob['type']])[0];
            $lbl = $icon . ' ' . mb_substr($ob['title'], 0, 28) . ($next ? ' • ' . J::fmt($next['due_date']) : '') . ($ob['shared_user_id'] ? ' 👥' : '');
            $btns[] = [U::btn($lbl, "o:v:{$ob['id']}")];
        }
        if (!$obs) $t .= "\nموردی وجود ندارد.";
        else $t .= "\nروی هر مورد بزنید تا جزئیات و گزینه‌ها را ببینید.";
        $btns[] = [U::btn('🏦 وام‌ها', 'l:loan'), U::btn('🔁 دوره‌ای', 'l:recurring'), U::btn('📌 یک‌باره', 'l:once')];
        $btns[] = [U::btn('📋 همه', 'l:all'), U::btn('🗄 بایگانی', 'l:arch'), U::btn('➕ افزودن', 'add:menu')];
        self::out($t, U::ikb($btns));
    }

    /* ================= Obligation view & actions ================= */
    private static function obText(array $ob): string {
        $uid = self::uid();
        $st = Repo::stats((int)$ob['id']);
        $sh = Repo::share($ob, $uid);
        $t = Repo::TYPES[$ob['type']] . "\n<b>" . U::h($ob['title']) . "</b>\n\n";
        $t .= "🗂 دسته: " . U::h(Repo::catLabel($ob['category_id'] ? (int)$ob['category_id'] : null)) . "\n";
        if ($ob['creditor']) $t .= "👤 طرف حساب: " . U::h($ob['creditor']) . "\n";
        if ($ob['shared_user_id']) $t .= "👥 مشترک با " . U::h(Repo::uname(Repo::partnerOf($ob, $uid))) . " — سهم شما " . U::fa((int)round($sh * 100)) . "٪\n";
        $status = ['active' => '🟢 فعال', 'done' => '🏁 تسویه‌شده', 'stopped' => '⏸ متوقف'][$ob['status']];
        $t .= "وضعیت: $status\n";
        if ($ob['type'] === 'loan') {
            $t .= "\n💰 مبلغ کل: " . U::toman($st['total']) . "\n✅ پرداخت‌شده: " . U::toman($st['paid']) . "\n⏳ مانده: <b>" . U::toman($st['total'] - $st['paid']) . "</b>\n";
            if ($sh < 1) $t .= "   (سهم شما از مانده: " . U::toman(($st['total'] - $st['paid']) * $sh) . ")\n";
            $t .= "🔢 اقساط: " . U::fa($st['paid_cnt']) . ' از ' . U::fa($st['cnt']) . "\n" . U::bar($st['total'] ? $st['paid'] / $st['total'] : 0, 12) . "\n";
            $tl = ''; $today = date('Y-m-d');
            foreach (Repo::dues((int)$ob['id'], 0, 60) as $i => $d) {
                $tl .= $d['status'] === 'paid' ? '🟩' : ($d['due_date'] < $today ? '🟥' : ($d['status'] === 'partial' ? '🟨' : '⬜️'));
                if (($i + 1) % 12 === 0) $tl .= "\n";
            }
            $t .= rtrim($tl) . "\n";
            $t .= "<i>🟩 پرداخت‌شده  🟨 جزئی  🟥 معوقه  ⬜️ آینده</i>\n";
        } elseif ($ob['type'] === 'recurring') {
            $per = self::periodLabel($ob['period_unit'], (int)$ob['period_n']);
            $t .= "\n💰 مبلغ هر دوره: " . U::toman($ob['amount']) . "\n🔁 دوره: $per\n";
            $t .= "✅ تعداد پرداخت‌شده: " . U::fa((int)$st['paid_cnt']) . " — جمع " . U::toman($st['paid']) . "\n";
        } else {
            $t .= "\n💰 مبلغ: " . U::toman($st['total']) . "\n";
        }
        $next = Repo::nextDue((int)$ob['id']);
        if ($next && $ob['status'] === 'active') {
            $rem = $next['amount'] - $next['paid_amount'];
            $t .= "\n⏭ <b>پرداخت بعدی:</b> " . J::fmt($next['due_date']) . ' ' . J::weekday($next['due_date']) . ' (' . U::relDay($next['due_date']) . ")\n   مبلغ: " . U::toman($rem);
            if ($sh < 1) $t .= ' — سهم شما ' . U::toman($rem * $sh);
            $t .= "\n";
        }
        if ($ob['note']) $t .= "\n📝 " . U::h($ob['note']) . "\n";
        return $t;
    }

    public static function obView(int $id, string $prefix = '') {
        $ob = Repo::ob($id, self::uid());
        if (!$ob) { self::out('مورد یافت نشد.'); return; }
        Repo::ensureRecurring($ob);
        $owner = (int)$ob['user_id'] === self::uid();
        $next = Repo::nextDue($id);
        $kb = [];
        if ($next && $ob['status'] === 'active') $kb[] = [U::btn('✅ پرداخت ' . ($ob['type'] === 'loan' ? 'قسط بعدی' : 'بعدی'), "d:pay:{$next['id']}:o"), U::btn('💵 پرداخت جزئی', "d:part:{$next['id']}")];
        $kb[] = [U::btn($ob['type'] === 'loan' ? '📋 لیست اقساط' : '📋 سوابق پرداخت', "o:dues:$id:0")];
        $row = [];
        if ($ob['type'] === 'loan' && $ob['status'] === 'active') $row[] = U::btn('💰 تسویه کامل', "o:settle:$id");
        if ($ob['type'] === 'recurring' && $owner) $row[] = $ob['status'] === 'active' ? U::btn('⏸ توقف', "o:stop:$id") : U::btn('▶️ ادامه', "o:resume:$id");
        if ($row) $kb[] = $row;
        if ($owner) $kb[] = [U::btn('✏️ ویرایش', "o:edit:$id"), U::btn('🗑 حذف', "o:del:$id")];
        if ($ob['status'] === 'active') $kb[] = [U::btn('🔗 از کدام درآمد؟', "i:ao:$id")];
        $kb[] = [U::btn('🔙 لیست', 'l:all')];
        self::out($prefix . self::obText($ob), U::ikb($kb));
    }

    private static function obAction(string $act, int $id, ?string $x) {
        $uid = self::uid();
        $ob = Repo::ob($id, $uid);
        if (!$ob) { self::out('مورد یافت نشد.'); return; }
        $owner = (int)$ob['user_id'] === $uid;
        switch ($act) {
            case 'v': self::obView($id); return;
            case 'dues': self::duesList($ob, (int)$x); return;
            case 'settle':
                $st = Repo::stats($id);
                self::out("💰 تسویه کامل «" . U::h($ob['title']) . "»\nمبلغ باقیمانده " . U::toman($st['total'] - $st['paid']) . " به‌عنوان پرداخت‌شده ثبت می‌شود. مطمئنید؟",
                    U::ikb([[U::btn('✅ بله، تسویه', "o:settle2:$id"), U::btn('🔙 خیر', "o:v:$id")]]));
                return;
            case 'settle2':
                $sum = Repo::settle($id, $uid);
                self::notifyPartner($ob, "🏁 «" . U::h($ob['title']) . "» توسط " . U::h(self::$u['name']) . " تسویه کامل شد (" . U::toman($sum) . ").");
                self::obView($id, "🏁 تسویه شد.\n\n");
                return;
            case 'stop': if ($owner) Repo::stopRecurring($id); self::obView($id, "⏸ متوقف شد.\n\n"); return;
            case 'resume': if ($owner) Repo::resume($id); self::obView($id, "▶️ فعال شد.\n\n"); return;
            case 'del':
                if (!$owner) return;
                self::out("🗑 «" . U::h($ob['title']) . "» و همه سوابقش حذف شود؟", U::ikb([[U::btn('🗑 بله، حذف', "o:del2:$id"), U::btn('🔙 خیر', "o:v:$id")]]));
                return;
            case 'del2':
                if (!$owner) return;
                Repo::deleteOb($id);
                self::toast('حذف شد');
                self::listMenu('all');
                return;
            case 'edit': if ($owner) self::editMenu($ob); return;
        }
    }

    private static function duesList(array $ob, int $page) {
        $id = (int)$ob['id']; $per = 10; $today = date('Y-m-d');
        $total = (int)DB::val("SELECT COUNT(*) FROM dues WHERE obligation_id=?", [$id]);
        $pages = max(1, (int)ceil($total / $per)); $page = max(0, min($page, $pages - 1));
        $t = "📋 <b>" . U::h($ob['title']) . "</b>\nصفحه " . U::fa($page + 1) . ' از ' . U::fa($pages) . "\nبرای جزئیات و ویرایش روی هر مورد بزنید:";
        $kb = [];
        foreach (Repo::dues($id, $page * $per, $per) as $d) {
            $icon = $d['status'] === 'paid' ? '✅' : ($d['due_date'] < $today ? '🔴' : self::STATUS_ICON[$d['status']]);
            $kb[] = [U::btn("$icon " . U::fa($d['seq']) . ') ' . J::fmt($d['due_date']) . ' — ' . U::n($d['amount']), "d:v:{$d['id']}:$page")];
        }
        $nav = [];
        if ($page > 0) $nav[] = U::btn('◀️ قبلی', "o:dues:$id:" . ($page - 1));
        if ($page < $pages - 1) $nav[] = U::btn('بعدی ▶️', "o:dues:$id:" . ($page + 1));
        if ($nav) $kb[] = $nav;
        $kb[] = [U::btn('🔙 بازگشت', "o:v:$id")];
        self::out($t, U::ikb($kb));
    }

    /* ================= Dues ================= */
    private static function dueView(array $d, string $page = '0', string $prefix = '') {
        $uid = self::uid(); $sh = Repo::share($d, $uid);
        $today = date('Y-m-d');
        $st = $d['status'] === 'paid' ? '✅ پرداخت‌شده' : ($d['due_date'] < $today ? '🔴 معوقه' : ($d['status'] === 'partial' ? '🟡 پرداخت جزئی' : '⏳ در انتظار'));
        $t = $prefix . "🧾 <b>" . U::h($d['title']) . "</b> — " . ($d['type'] === 'loan' ? 'قسط ' : 'مورد ') . U::fa($d['seq']) . "\n\n";
        $t .= "📅 سررسید: " . J::fmt($d['due_date']) . ' ' . J::weekday($d['due_date']) . ' (' . U::relDay($d['due_date']) . ")\n";
        $t .= "💰 مبلغ: " . U::toman($d['amount']) . ($sh < 1 ? ' — سهم شما ' . U::toman($d['amount'] * $sh) : '') . "\n";
        $t .= "✅ پرداخت‌شده: " . U::toman($d['paid_amount']) . "\nوضعیت: $st\n";
        $pays = DB::all("SELECT * FROM payments WHERE due_id=? ORDER BY id", [$d['id']]);
        if ($pays) {
            $t .= "\n<b>سوابق پرداخت:</b>\n";
            foreach ($pays as $p) $t .= '• ' . J::fmt($p['paid_at']) . ' — ' . U::toman($p['amount']) . ' — ' . U::h(Repo::uname((int)$p['payer_id'])) . "\n";
        }
        $kb = [];
        if ($d['status'] !== 'paid') $kb[] = [U::btn('✅ پرداخت کامل', "d:pay:{$d['id']}:d$page"), U::btn('💵 پرداخت جزئی', "d:part:{$d['id']}")];
        if ($pays) $kb[] = [U::btn('↩️ لغو آخرین پرداخت', "d:undo:{$d['id']}:$page")];
        if ((int)$d['user_id'] === $uid) $kb[] = [U::btn('✏️ تغییر مبلغ', "d:amt:{$d['id']}"), U::btn('📅 تغییر تاریخ', "d:date:{$d['id']}")];
        $kb[] = [U::btn('🔙 لیست', "o:dues:{$d['obligation_id']}:$page")];
        self::out($t, U::ikb($kb));
    }

    private static function dueAction(string $act, int $id, ?string $ctx) {
        $uid = self::uid();
        $d = Repo::due($id, $uid);
        if (!$d) { self::out('مورد یافت نشد.'); return; }
        switch ($act) {
            case 'v': self::dueView($d, $ctx ?? '0'); return;
            case 'pay':
                $amt = Repo::pay($id, $uid);
                self::afterPay($d, $amt);
                self::toast($amt ? '✅ ثبت شد: ' . U::n($amt) . ' تومان' : 'قبلاً پرداخت شده');
                self::returnTo($d, $ctx, $amt ? "✅ پرداخت «" . U::h($d['title']) . "» ثبت شد.\n\n" : '');
                return;
            case 'part':
                self::setState('d_part', ['id' => $id]);
                self::out("💵 مبلغ پرداختی برای «" . U::h($d['title']) . "» را بنویسید:\n(مانده: " . U::toman($d['amount'] - $d['paid_amount']) . ")", self::cancelKb());
                return;
            case 'undo':
                Repo::undoPay($id);
                self::toast('↩️ آخرین پرداخت لغو شد');
                self::dueView(Repo::due($id, $uid), $ctx ?? '0');
                return;
            case 'amt':
                self::setState('d_amt', ['id' => $id]);
                self::out("✏️ مبلغ جدید قسط " . U::fa($d['seq']) . " را بنویسید:\n(فعلی: " . U::toman($d['amount']) . ")", self::cancelKb());
                return;
            case 'date':
                self::setState('d_date', ['id' => $id]);
                self::out("📅 تاریخ جدید را به شمسی بنویسید (مثل " . U::fa('1405/08/15') . "):\n(فعلی: " . J::fmt($d['due_date']) . ")", self::cancelKb());
                return;
        }
    }

    private static function returnTo(array $d, ?string $ctx, string $prefix = '') {
        $ctx = $ctx ?? 'o';
        if ($ctx[0] === 'm') { self::month((int)substr($ctx, 1)); return; }
        if ($ctx === 'ov') { self::overdueView(); return; }
        if ($ctx[0] === 'd') { self::dueView(Repo::due((int)$d['id'], self::uid()), substr($ctx, 1) ?: '0', $prefix); return; }
        if ($ctx === 'n') { // from reminder message: keep message, just mark
            self::out($prefix . '🧾 ' . U::h($d['title']) . ' — ' . J::fmt($d['due_date']), U::ikb([[U::btn('↩️ لغو', "d:undo:{$d['id']}:0"), U::btn('👁 مشاهده', "o:v:{$d['obligation_id']}")]]));
            return;
        }
        self::obView((int)$d['obligation_id'], $prefix);
    }

    private static function afterPay(array $d, int $amt) {
        if ($amt <= 0) return;
        $ob = DB::row("SELECT * FROM obligations WHERE id=?", [$d['obligation_id']]);
        self::notifyPartner($ob, "👥 " . U::h(self::$u['name']) . " مبلغ " . U::toman($amt) . " برای «" . U::h($ob['title']) . "» (سررسید " . J::fmt($d['due_date']) . ") پرداخت کرد.");
        $alert = Repo::budgetCheck((int)$ob['user_id'], $ob['category_id'] ? (int)$ob['category_id'] : null);
        if ($alert) TG::send((int)$ob['user_id'], $alert);
        if ($ob['status'] === 'done' && $ob['type'] === 'loan') TG::send(self::$chat, "🎉 تبریک! همه اقساط «" . U::h($ob['title']) . "» پرداخت شد.");
    }

    private static function notifyPartner(array $ob, string $msg) {
        $p = Repo::partnerOf($ob, self::uid());
        if ($p) TG::send($p, $msg);
    }

    public static function periodLabel(?string $unit, int $n): string {
        if ($unit === 'week') return $n === 1 ? 'هفتگی' : 'هر ' . U::fa($n) . ' هفته';
        if ($unit === 'year') return $n === 1 ? 'سالانه' : 'هر ' . U::fa($n) . ' سال';
        return $n === 1 ? 'ماهانه' : 'هر ' . U::fa($n) . ' ماه';
    }

    /* ================= Text-input states ================= */
    private static function stateInput(string $text) {
        $s = self::state(); $d = self::sdata(); $uid = self::uid();
        if ($s === 'wiz') { self::wizard($text, null); return; }
        if (strpos($s, 'inc_') === 0) { self::incomeInput($s, $text, $d); return; }
        $back = fn($cb) => U::ikb([[U::btn('🔙 بازگشت', $cb)]]);
        switch ($s) {
            case 'd_part': case 'd_amt':
                $amt = U::money($text);
                $due = Repo::due((int)$d['id'], $uid);
                if (!$amt || !$due) { TG::send(self::$chat, '❗️ مبلغ نامعتبر است. دوباره بنویسید (مثل 1500000 یا ۱.۵ میلیون):', self::cancelKb()); return; }
                self::setState(null);
                if ($s === 'd_part') {
                    $paid = Repo::pay((int)$d['id'], $uid, $amt);
                    self::afterPay($due, $paid);
                    self::dueView(Repo::due((int)$d['id'], $uid), '0', "💵 پرداخت " . U::toman($paid) . " ثبت شد.\n\n");
                } else {
                    Repo::setDueAmount((int)$d['id'], $amt);
                    self::dueView(Repo::due((int)$d['id'], $uid), '0', "✏️ مبلغ بروزرسانی شد.\n\n");
                }
                return;
            case 'd_date':
                $g = J::parse($text);
                if (!$g || !Repo::due((int)$d['id'], $uid)) { TG::send(self::$chat, '❗️ تاریخ نامعتبر است. مثل ' . U::fa('1405/08/15') . ' بنویسید:', self::cancelKb()); return; }
                self::setState(null);
                Repo::setDueDate((int)$d['id'], $g);
                self::dueView(Repo::due((int)$d['id'], $uid), '0', "📅 تاریخ بروزرسانی شد.\n\n");
                return;
            case 'e_title': case 'e_cred': case 'e_note': case 'e_amt':
                $ob = Repo::ob((int)$d['id'], $uid);
                if (!$ob || (int)$ob['user_id'] !== $uid) { self::setState(null); return; }
                if ($s === 'e_amt') {
                    $amt = U::money($text);
                    if (!$amt) { TG::send(self::$chat, '❗️ مبلغ نامعتبر است:', self::cancelKb()); return; }
                    DB::q("UPDATE obligations SET amount=? WHERE id=?", [$amt, $ob['id']]);
                    $ids = DB::all("SELECT id FROM dues WHERE obligation_id=? AND status='pending'", [$ob['id']]);
                    foreach ($ids as $r) Repo::setDueAmount((int)$r['id'], $amt);
                } else {
                    $col = ['e_title' => 'title', 'e_cred' => 'creditor', 'e_note' => 'note'][$s];
                    $val = mb_substr($text, 0, $col === 'note' ? 1000 : 120);
                    if ($text === '-') $val = $col === 'title' ? $ob['title'] : null;
                    DB::q("UPDATE obligations SET $col=? WHERE id=?", [$val, $ob['id']]);
                }
                self::setState(null);
                self::$msgId = null;
                self::obView((int)$ob['id'], "✏️ ذخیره شد.\n\n");
                return;
            case 'cat_add': case 'cat_ren':
                [$emoji, $name] = self::parseCat($text);
                if ($name === '') { TG::send(self::$chat, '❗️ نام را بنویسید:', self::cancelKb()); return; }
                if ($s === 'cat_add') {
                    $sort = (int)DB::val("SELECT COALESCE(MAX(sort),0)+1 FROM categories WHERE user_id=?", [$uid]);
                    $cid = DB::insert("INSERT INTO categories (user_id,name,emoji,sort) VALUES (?,?,?,?)", [$uid, $name, $emoji ?: '📁', $sort]);
                } else {
                    $cid = (int)$d['id'];
                    DB::q("UPDATE categories SET name=?, emoji=COALESCE(?,emoji) WHERE id=? AND user_id=?", [$name, $emoji, $cid, $uid]);
                }
                self::setState(null);
                if (!empty($d['wiz'])) { // came from wizard
                    $w = $d['wiz']; $w['category_id'] = $cid; $w['step'] = self::nextStep($w);
                    self::setState('wiz', $w); self::wizAsk($w); return;
                }
                self::catView($cid, "✅ ذخیره شد.\n\n");
                return;
            case 'cat_bud':
                $txt = U::num(trim($text));
                $amt = $txt === '0' ? 0 : U::money($text);
                if ($amt === null) { TG::send(self::$chat, '❗️ مبلغ نامعتبر است (برای حذف بودجه 0 بنویسید):', self::cancelKb()); return; }
                DB::q("UPDATE categories SET budget=? WHERE id=? AND user_id=?", [$amt, $d['id'], $uid]);
                self::setState(null);
                self::catView((int)$d['id'], "✅ بودجه ذخیره شد.\n\n");
                return;
            case 'adm_add':
                if (!self::$u['is_admin']) return;
                $parts = preg_split('/\s+/u', trim($text), 2);
                $id = U::int($parts[0] ?? '');
                if (!$id) { TG::send(self::$chat, '❗️ شناسه عددی را بنویسید (مثل 123456789 علی):', self::cancelKb()); return; }
                Repo::allow($id, $parts[1] ?? '');
                self::setState(null);
                TG::send($id, "✅ دسترسی شما به ربات فعال شد. /start را بزنید.");
                self::adminUsers("✅ کاربر اضافه شد.\n\n");
                return;
            case 'adm_bc':
                if (!self::$u['is_admin']) return;
                self::setState(null);
                $n = 0;
                foreach (Repo::allowedUsers() as $u) { if ((int)$u['id'] !== $uid) { TG::send($u['id'], "📣 " . U::h($text)); $n++; } }
                TG::send(self::$chat, "📣 برای " . U::fa($n) . " کاربر ارسال شد.", self::mainKb(self::$u));
                return;
        }
        self::setState(null);
    }

    private static function parseCat(string $t): array {
        $t = trim($t);
        if (preg_match('/^(\X)\s+(.+)$/u', $t, $m) && !preg_match('/[\p{L}\p{N}]/u', $m[1])) return [$m[1], mb_substr(trim($m[2]), 0, 60)];
        return [null, mb_substr($t, 0, 60)];
    }

    /* ================= Edit ================= */
    private static function editMenu(array $ob) {
        $id = $ob['id'];
        $kb = [[U::btn('✏️ عنوان', "e:title:$id"), U::btn('🗂 دسته', "e:cat:$id")],
               [U::btn('👤 طرف حساب', "e:cred:$id"), U::btn('📝 یادداشت', "e:note:$id")]];
        if ($ob['type'] !== 'once') $kb[] = [U::btn($ob['type'] === 'loan' ? '💰 مبلغ همه اقساط باقیمانده' : '💰 مبلغ هر دوره', "e:amt:$id")];
        if ($ob['type'] === 'loan') $kb[] = [U::btn('📋 ویرایش تک‌تک اقساط', "o:dues:$id:0")];
        $kb[] = [U::btn('🔙 بازگشت', "o:v:$id")];
        self::out("✏️ ویرایش «" . U::h($ob['title']) . "»\nچه چیزی را تغییر دهید؟", U::ikb($kb));
    }

    private static function editAction(string $f, int $id, ?string $x) {
        $ob = Repo::ob($id, self::uid());
        if (!$ob || (int)$ob['user_id'] !== self::uid()) return;
        $prompts = ['title' => 'عنوان جدید را بنویسید:', 'cred' => 'طرف حساب را بنویسید (برای حذف - بفرستید):',
                    'note' => 'یادداشت را بنویسید (برای حذف - بفرستید):', 'amt' => 'مبلغ جدید را بنویسید (روی اقساط/دوره‌های پرداخت‌نشده اعمال می‌شود):'];
        if ($f === 'cat') {
            if ($x !== null) { DB::q("UPDATE obligations SET category_id=? WHERE id=?", [(int)$x ?: null, $id]); self::obView($id, "🗂 دسته تغییر کرد.\n\n"); return; }
            $kb = self::catButtons(self::uid(), "e:cat:$id:");
            $kb[] = [U::btn('🔙 بازگشت', "o:edit:$id")];
            self::out('🗂 دسته جدید را انتخاب کنید:', U::ikb($kb));
            return;
        }
        if (!isset($prompts[$f])) return;
        self::setState('e_' . $f, ['id' => $id]);
        self::out('✏️ ' . $prompts[$f], self::cancelKb());
    }

    public static function catButtons(int $uid, string $prefix): array {
        $kb = []; $row = [];
        foreach (Repo::cats($uid) as $c) { $row[] = U::btn($c['emoji'] . ' ' . $c['name'], $prefix . $c['id']); if (count($row) === 2) { $kb[] = $row; $row = []; } }
        if ($row) $kb[] = $row;
        return $kb;
    }
}
