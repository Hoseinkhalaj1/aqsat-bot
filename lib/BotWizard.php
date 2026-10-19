<?php
trait BotWizard {
    private static array $STEPS = [
        'loan'      => ['title', 'cat', 'creditor', 'count', 'mode', 'amount', 'date', 'period', 'paidcount', 'share', 'confirm'],
        'recurring' => ['title', 'cat', 'amount', 'rperiod', 'date', 'share', 'confirm'],
        'once'      => ['title', 'cat', 'amount', 'date', 'share', 'confirm'],
    ];
    private static array $WTITLE = ['loan' => '🏦 وام / خرید قسطی جدید', 'recurring' => '🔁 پرداخت دوره‌ای جدید', 'once' => '📌 پرداخت یک‌باره جدید'];

    public static function addMenu() {
        self::out("➕ <b>چه چیزی اضافه کنیم؟</b>\n\n🏦 <b>وام/قسط</b>: تعداد اقساط مشخص (وام بانکی، خرید قسطی، قرض)\n🔁 <b>دوره‌ای</b>: هر ماه/هفته/سال تکرار می‌شود (اجاره، اینترنت، اشتراک)\n📌 <b>یک‌باره</b>: یک پرداخت با تاریخ مشخص",
            U::ikb([[U::btn('🏦 وام / قسط', 'add:loan')], [U::btn('🔁 پرداخت دوره‌ای', 'add:recurring')], [U::btn('📌 پرداخت یک‌باره', 'add:once')]]));
    }

    private static function wizStart(?string $type) {
        if (!isset(self::$STEPS[$type])) { self::addMenu(); return; }
        $w = ['type' => $type, 'step' => 'title'];
        self::setState('wiz', $w);
        self::wizAsk($w);
    }

    private static function others(): array {
        return array_values(array_filter(Repo::allowedUsers(), fn($u) => (int)$u['id'] !== self::uid()));
    }

    private static function nextStep(array $w): string {
        if ($w['step'] === 'share' && !empty($w['shared_user_id'])) return 'sharepct';
        if ($w['step'] === 'sharepct') return 'confirm';
        $steps = self::$STEPS[$w['type']];
        $i = array_search($w['step'], $steps, true);
        $next = $steps[$i + 1] ?? 'confirm';
        if ($next === 'share' && !self::others()) $next = 'confirm';
        if ($next === 'paidcount' && ($w['first_date'] ?? '') >= date('Y-m-d')) $next = self::others() ? 'share' : 'confirm';
        return $next;
    }

    private static function wizAsk(array $w, string $err = '') {
        $type = $w['type'];
        $h = ($err ? "❗️ $err\n\n" : '') . '<b>' . self::$WTITLE[$type] . "</b>\n";
        if (!empty($w['title']) && $w['step'] !== 'title') $h .= '📝 ' . U::h($w['title']) . "\n";
        $h .= "\n";
        $cancel = [U::btn(self::CANCEL, 'w:cancel')];
        $kb = [];
        switch ($w['step']) {
            case 'title':
                $ex = ['loan' => 'وام بانک ملت، گوشی موبایل', 'recurring' => 'اجاره خانه، اینترنت', 'once' => 'بیمه ماشین، شهریه ترم'][$type];
                $t = "عنوان را بنویسید:\n<i>مثال: $ex</i>";
                break;
            case 'cat':
                $t = "🗂 دسته‌بندی را انتخاب کنید:\n<i>(یا نام دسته جدید را بنویسید)</i>";
                $kb = self::catButtons(self::uid(), 'w:c:');
                break;
            case 'creditor':
                $t = "👤 طرف حساب (بانک، فروشگاه یا شخص) را بنویسید:";
                $kb[] = [U::btn('⏭ رد شدن', 'w:skip')];
                break;
            case 'count':
                $t = "🔢 تعداد کل اقساط را بنویسید یا انتخاب کنید:";
                $kb[] = array_map(fn($n) => U::btn(U::fa($n), "w:n:$n"), [3, 6, 10, 12]);
                $kb[] = array_map(fn($n) => U::btn(U::fa($n), "w:n:$n"), [18, 24, 36, 60]);
                break;
            case 'mode':
                $t = "💰 مبلغ اقساط چطور است؟";
                $kb = [[U::btn('مبلغ هر قسط را می‌دانم (مساوی)', 'w:m:each')], [U::btn('مبلغ کل را می‌دانم (تقسیم مساوی)', 'w:m:total')], [U::btn('اقساط مبالغ متفاوت دارند', 'w:m:manual')]];
                break;
            case 'amount':
                $mode = $w['mode'] ?? '';
                if ($type === 'loan' && $mode === 'manual') {
                    $t = "مبلغ " . U::fa($w['count']) . " قسط را به ترتیب بنویسید؛ هر مبلغ در یک خط (یا جدا با +).\nبرای تکرار از * استفاده کنید:\n<code>5000000*6\n3000000*6</code>\n<i>یعنی ۶ قسط ۵ میلیونی و ۶ قسط ۳ میلیونی</i>";
                } else {
                    $what = $type === 'loan' ? ($mode === 'total' ? 'مبلغ کل (به تومان)' : 'مبلغ هر قسط (به تومان)') : ($type === 'recurring' ? 'مبلغ هر دوره (به تومان)' : 'مبلغ (به تومان)');
                    $t = "💰 $what را بنویسید:\n<i>مثال: 2500000 یا ۲.۵ میلیون یا 800 هزار</i>";
                }
                break;
            case 'date':
                $t = ($type === 'loan' ? '📅 تاریخ سررسید <b>اولین قسط</b>' : ($type === 'recurring' ? '📅 تاریخ <b>اولین پرداخت</b> (یا نزدیک‌ترین پرداخت بعدی)' : '📅 تاریخ پرداخت')) .
                     " را به شمسی بنویسید:\n<i>مثال: " . U::fa('1405/08/15') . " یا " . U::fa('8/15') . "</i>\nامروز: " . J::fmt(date('Y-m-d'));
                $kb[] = [U::btn('امروز', 'w:today')];
                break;
            case 'period':
                $t = "🔁 فاصله بین اقساط:";
                $kb = [[U::btn('ماهانه', 'w:p:1'), U::btn('هر ۲ ماه', 'w:p:2'), U::btn('هر ۳ ماه', 'w:p:3')], [U::btn('هر ۶ ماه', 'w:p:6'), U::btn('سالانه', 'w:p:12')]];
                break;
            case 'rperiod':
                $t = "🔁 هر چند وقت تکرار می‌شود؟";
                $kb = [[U::btn('هفتگی', 'w:r:week:1'), U::btn('ماهانه', 'w:r:month:1'), U::btn('هر ۲ ماه', 'w:r:month:2')],
                       [U::btn('هر ۳ ماه', 'w:r:month:3'), U::btn('هر ۶ ماه', 'w:r:month:6'), U::btn('سالانه', 'w:r:year:1')]];
                break;
            case 'paidcount':
                $past = 0;
                [$jy, $jm, $jd] = J::fromG($w['first_date']);
                for ($i = 0; $i < $w['count']; $i++) if (J::addMonths($jy, $jm, $jd, $i * $w['period_n']) < date('Y-m-d')) $past++;
                $t = "✅ تاکنون چند قسط پرداخت شده است؟\n<i>(" . U::fa($past) . " قسط سررسیدش گذشته است)</i>";
                $kb[] = $past ? [U::btn('هیچ‌کدام', 'w:n:0'), U::btn(U::fa($past) . ' قسط', "w:n:$past")] : [U::btn('هیچ‌کدام', 'w:n:0')];
                break;
            case 'share':
                $t = "👥 این مورد مشترک است؟";
                $kb[] = [U::btn('فقط خودم', 'w:s:0')];
                foreach (self::others() as $o) $kb[] = [U::btn('مشترک با ' . $o['name'], 'w:s:' . $o['id'])];
                break;
            case 'sharepct':
                $t = "سهم <b>شما</b> چند درصد است؟ (عدد بنویسید یا انتخاب کنید)";
                $kb[] = [U::btn('۵۰٪', 'w:n:50'), U::btn('۳۰٪', 'w:n:30'), U::btn('۷۰٪', 'w:n:70')];
                break;
            case 'confirm':
                $t = self::wizSummary($w) . "\nثبت شود؟";
                $kb[] = [U::btn('✅ ثبت', 'w:ok')];
                break;
            default: $t = '';
        }
        $kb[] = $cancel;
        self::out($h . $t, U::ikb($kb));
    }

    private static function wizSummary(array $w): string {
        $t = "📋 <b>خلاصه</b>\n";
        $t .= "عنوان: " . U::h($w['title']) . "\n";
        $t .= "دسته: " . U::h(Repo::catLabel($w['category_id'] ?? null)) . "\n";
        if (!empty($w['creditor'])) $t .= "طرف حساب: " . U::h($w['creditor']) . "\n";
        if ($w['type'] === 'loan') {
            $a = $w['amounts']; $total = array_sum($a);
            $t .= "تعداد اقساط: " . U::fa(count($a)) . "\n";
            if (count(array_unique($a)) === 1) $t .= "مبلغ هر قسط: " . U::toman($a[0]) . "\n";
            else {
                $groups = []; $prev = null;
                foreach ($a as $v) { if ($prev !== null && $groups[count($groups) - 1][0] === $v) $groups[count($groups) - 1][1]++; else $groups[] = [$v, 1]; $prev = $v; }
                $t .= "اقساط: " . implode('، ', array_map(fn($g) => U::fa($g[1]) . '×' . U::n($g[0]), array_slice($groups, 0, 6))) . (count($groups) > 6 ? '، ...' : '') . "\n";
            }
            $t .= "مبلغ کل: <b>" . U::toman($total) . "</b>\n";
            [$jy, $jm, $jd] = J::fromG($w['first_date']);
            $last = J::addMonths($jy, $jm, $jd, (count($a) - 1) * $w['period_n']);
            $t .= "اولین قسط: " . J::fmt($w['first_date']) . "\nآخرین قسط: " . J::fmt($last) . "\n";
            $t .= "فاصله: " . self::periodLabel('month', (int)$w['period_n']) . "\n";
            if (!empty($w['paid_count'])) $t .= "پرداخت‌شده تاکنون: " . U::fa($w['paid_count']) . " قسط\n";
        } else {
            $t .= ($w['type'] === 'recurring' ? "مبلغ هر دوره: " : "مبلغ: ") . U::toman($w['amount']) . "\n";
            if ($w['type'] === 'recurring') $t .= "دوره: " . self::periodLabel($w['period_unit'], (int)$w['period_n']) . "\n";
            $t .= ($w['type'] === 'recurring' ? "اولین پرداخت: " : "تاریخ: ") . J::fmt($w['first_date']) . "\n";
        }
        if (!empty($w['shared_user_id'])) $t .= "👥 مشترک با " . U::h(Repo::uname((int)$w['shared_user_id'])) . " — سهم شما " . U::fa($w['owner_share']) . "٪\n";
        return $t;
    }

    private static function parseAmounts(string $s): ?array {
        $out = [];
        foreach (preg_split('/[\n\r+]+/u', U::num($s)) as $tok) {
            $tok = trim($tok); if ($tok === '') continue;
            $n = 1;
            if (preg_match('/^(.+?)\s*[*×xX]\s*(\d+)$/u', $tok, $m)) { $tok = $m[1]; $n = (int)$m[2]; }
            $v = U::money($tok);
            if (!$v || $n < 1 || $n > 600) return null;
            for ($i = 0; $i < $n; $i++) $out[] = $v;
        }
        return $out ?: null;
    }

    /** $text: typed input, $cb: callback payload after "w:" */
    private static function wizard(?string $text, ?string $cb) {
        if ($cb === 'cancel') { self::setState(null); self::out('❌ لغو شد.'); return; }
        if (self::state() !== 'wiz') { self::out('این فرم منقضی شده است. دوباره از «' . self::M_ADD . '» شروع کنید.'); return; }
        $w = self::sdata();
        $err = '';
        $cbv = $cb !== null ? explode(':', $cb) : [];
        $ok = false;
        switch ($w['step']) {
            case 'title':
                if ($text !== null && $text !== '') { $w['title'] = mb_substr($text, 0, 120); $ok = true; } else $err = 'عنوان را بنویسید.';
                break;
            case 'cat':
                if (($cbv[0] ?? '') === 'c') { $w['category_id'] = (int)$cbv[1]; $ok = true; }
                elseif ($text !== null && $text !== '') {
                    [$e, $n] = self::parseCat($text);
                    $sort = (int)DB::val("SELECT COALESCE(MAX(sort),0)+1 FROM categories WHERE user_id=?", [self::uid()]);
                    $w['category_id'] = DB::insert("INSERT INTO categories (user_id,name,emoji,sort) VALUES (?,?,?,?)", [self::uid(), $n, $e ?: '📁', $sort]);
                    $ok = true;
                }
                break;
            case 'creditor':
                if ($cb === 'skip') { $w['creditor'] = null; $ok = true; }
                elseif ($text !== null && $text !== '') { $w['creditor'] = mb_substr($text, 0, 120); $ok = true; }
                break;
            case 'count':
                $n = ($cbv[0] ?? '') === 'n' ? (int)$cbv[1] : ($text !== null ? U::int($text) : null);
                if ($n && $n >= 1 && $n <= 600) { $w['count'] = $n; $ok = true; } else $err = 'تعداد نامعتبر است (۱ تا ۶۰۰).';
                break;
            case 'mode':
                if (($cbv[0] ?? '') === 'm') { $w['mode'] = $cbv[1]; $ok = true; }
                break;
            case 'amount':
                if ($text === null) break;
                if ($w['type'] === 'loan') {
                    if ($w['mode'] === 'manual') {
                        $a = self::parseAmounts($text);
                        if (!$a) $err = 'فرمت مبالغ نامعتبر است.';
                        elseif (count($a) !== (int)$w['count']) $err = 'تعداد مبالغ (' . U::fa(count($a)) . ') با تعداد اقساط (' . U::fa($w['count']) . ') برابر نیست.';
                        else { $w['amounts'] = $a; $ok = true; }
                    } else {
                        $v = U::money($text);
                        if (!$v) $err = 'مبلغ نامعتبر است.';
                        else {
                            $n = (int)$w['count'];
                            if ($w['mode'] === 'total') { $each = intdiv($v, $n); $a = array_fill(0, $n, $each); $a[$n - 1] += $v - $each * $n; }
                            else $a = array_fill(0, $n, $v);
                            $w['amounts'] = $a; $w['amount'] = $a[0]; $ok = true;
                        }
                    }
                } else {
                    $v = U::money($text);
                    if ($v) { $w['amount'] = $v; $ok = true; } else $err = 'مبلغ نامعتبر است.';
                }
                break;
            case 'date':
                $g = $cb === 'today' ? date('Y-m-d') : ($text !== null ? J::parse($text) : null);
                if ($g) { $w['first_date'] = $g; $ok = true; } else $err = 'تاریخ نامعتبر است. مثل ' . U::fa('1405/08/15') . ' بنویسید.';
                break;
            case 'period':
                if (($cbv[0] ?? '') === 'p') { $w['period_unit'] = 'month'; $w['period_n'] = (int)$cbv[1]; $ok = true; }
                break;
            case 'rperiod':
                if (($cbv[0] ?? '') === 'r') { $w['period_unit'] = $cbv[1]; $w['period_n'] = (int)$cbv[2]; $ok = true; }
                break;
            case 'paidcount':
                $n = ($cbv[0] ?? '') === 'n' ? (int)$cbv[1] : ($text !== null ? U::int($text) : null);
                if ($n !== null && $n >= 0 && $n <= (int)$w['count']) { $w['paid_count'] = $n; $ok = true; } else $err = 'عدد نامعتبر است.';
                break;
            case 'share':
                if (($cbv[0] ?? '') === 's') {
                    $sid = (int)$cbv[1];
                    $w['shared_user_id'] = $sid && Repo::user($sid) ? $sid : null;
                    $w['owner_share'] = $w['shared_user_id'] ? 50 : 100;
                    $ok = true;
                }
                break;
            case 'sharepct':
                $n = ($cbv[0] ?? '') === 'n' ? (int)$cbv[1] : ($text !== null ? U::int(str_replace(['%', '٪'], '', $text)) : null);
                if ($n && $n >= 1 && $n <= 99) { $w['owner_share'] = $n; $ok = true; } else $err = 'عددی بین ۱ تا ۹۹ بنویسید.';
                break;
            case 'confirm':
                if ($cb === 'ok') {
                    $w['period_unit'] = $w['period_unit'] ?? null;
                    $w['period_n'] = $w['period_n'] ?? 1;
                    $id = Repo::createOb(self::uid(), $w);
                    self::setState(null);
                    if (!empty($w['shared_user_id']))
                        TG::send((int)$w['shared_user_id'], "👥 " . U::h(self::$u['name']) . " مورد مشترک «" . U::h($w['title']) . "» را ثبت کرد. سهم شما " . U::fa(100 - (int)$w['owner_share']) . "٪ است.",
                            U::ikb([[U::btn('👁 مشاهده', "o:v:$id")]]));
                    self::obView($id, "✅ ثبت شد.\n\n");
                    return;
                }
                break;
        }
        if ($ok) $w['step'] = self::nextStep($w);
        elseif (!$err) $err = $text !== null ? 'لطفاً از دکمه‌ها استفاده کنید.' : '';
        self::setState('wiz', $w);
        self::wizAsk($w, $err);
    }
}
