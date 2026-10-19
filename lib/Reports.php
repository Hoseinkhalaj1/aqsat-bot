<?php
class Reports {
    public static function menu(array $u) {
        [$jy] = J::today();
        Bot::out("📈 <b>گزارش‌ها</b>\nنوع گزارش را انتخاب کنید:", U::ikb([
            [U::btn('📅 گزارش ماهانه (به تفکیک دسته)', 'r:m:0')],
            [U::btn('📆 گزارش سالانه ' . U::fa($jy), 'r:y:0')],
            [U::btn('📥 خروجی اکسل (همه)', 'r:x:all'), U::btn('📥 اکسل سال ' . U::fa($jy), 'r:x:' . $jy)],
        ]));
    }

    public static function action(array $u, string $act, ?string $x) {
        switch ($act) {
            case 'menu': self::menu($u); return;
            case 'm': self::monthly($u, (int)$x); return;
            case 'y': self::yearly($u, (int)$x); return;
            case 'x': self::excel($u, $x === 'all' ? null : (int)$x); return;
        }
    }

    private static function monthly(array $u, int $off) {
        $uid = (int)$u['id'];
        Repo::ensureAllRecurring();
        [$jy, $jm] = J::today();
        $t0 = $jy * 12 + $jm - 1 + $off; $jy = intdiv($t0, 12); $jm = $t0 % 12 + 1;
        [$from, $to] = J::monthRange($jy, $jm);
        $byCat = []; $byType = ['loan' => 0, 'recurring' => 0, 'once' => 0]; $due = 0; $paid = 0; $over = 0;
        foreach (Repo::duesInRange($uid, $from, $to) as $d) {
            $sh = Repo::share($d, $uid);
            $cid = (int)$d['category_id'];
            $byCat[$cid] = $byCat[$cid] ?? ['due' => 0, 'paid' => 0];
            $byCat[$cid]['due'] += $d['amount'] * $sh; $byCat[$cid]['paid'] += $d['paid_amount'] * $sh;
            $byType[$d['type']] += $d['amount'] * $sh;
            $due += $d['amount'] * $sh; $paid += $d['paid_amount'] * $sh;
            if ($d['status'] !== 'paid' && $d['due_date'] < date('Y-m-d')) $over += ($d['amount'] - $d['paid_amount']) * $sh;
        }
        // actual cash paid during this month (any due date)
        $cash = 0;
        foreach (DB::all("SELECT p.amount, o.user_id, o.shared_user_id, o.owner_share FROM payments p JOIN dues d ON d.id=p.due_id JOIN obligations o ON o.id=d.obligation_id
            WHERE (o.user_id=? OR o.shared_user_id=?) AND p.paid_at BETWEEN ? AND ?", [$uid, $uid, "$from 00:00:00", "$to 23:59:59"]) as $p) $cash += $p['amount'] * Repo::share($p, $uid);

        $t = "📅 <b>گزارش " . J::monthName($jy, $jm) . "</b>\n\n";
        $t .= "💰 جمع سررسیدهای ماه: <b>" . U::toman($due) . "</b>\n✅ پرداخت‌شده: " . U::toman($paid) . "\n⏳ مانده: " . U::toman(max(0, $due - $paid)) . "\n";
        if ($over > 0) $t .= "🔴 معوقه این ماه: " . U::toman($over) . "\n";
        $t .= "💸 کل پرداختی واقعی در این ماه: " . U::toman($cash) . "\n";
        if ($due > 0) $t .= U::bar($paid / $due, 12) . "\n";
        $t .= "\n<b>به تفکیک نوع:</b>\n🏦 اقساط: " . U::toman($byType['loan']) . "\n🔁 دوره‌ای: " . U::toman($byType['recurring']) . "\n📌 یک‌باره: " . U::toman($byType['once']) . "\n";
        if ($byCat) {
            uasort($byCat, fn($a, $b) => $b['due'] <=> $a['due']);
            $t .= "\n<b>به تفکیک دسته:</b>\n";
            foreach ($byCat as $cid => $v) {
                $t .= Repo::catLabel($cid ?: null) . ': ' . U::n($v['due']) . ($v['paid'] < $v['due'] ? ' (پرداخت‌شده ' . U::n($v['paid']) . ')' : ' ✅') . ' — ' . U::fa($due ? round($v['due'] / $due * 100) : 0) . "٪\n";
                $c = $cid ? DB::row("SELECT budget FROM categories WHERE id=? AND user_id=?", [$cid, $uid]) : null;
                if ($c && $c['budget'] > 0) {
                    $sp = Repo::catSpent($uid, $cid, $jy, $jm);
                    $t .= '   🎯 بودجه: ' . U::bar($sp / $c['budget'], 8) . ($sp > $c['budget'] ? ' ⚠️ عبور از سقف' : '') . "\n";
                }
            }
        } else $t .= "\nموردی در این ماه نیست.\n";
        Bot::out($t, U::ikb([
            [U::btn('◀️ ماه قبل', 'r:m:' . ($off - 1)), U::btn('ماه جاری', 'r:m:0'), U::btn('ماه بعد ▶️', 'r:m:' . ($off + 1))],
            [U::btn('🔙 گزارش‌ها', 'r:menu')],
        ]));
    }

    private static function yearly(array $u, int $off) {
        $uid = (int)$u['id'];
        [$jy] = J::today(); $jy += $off;
        $rows = []; $tDue = 0; $tPaid = 0; $max = 1;
        for ($m = 1; $m <= 12; $m++) {
            [$from, $to] = J::monthRange($jy, $m);
            $due = 0; $paid = 0;
            foreach (Repo::duesInRange($uid, $from, $to) as $d) { $sh = Repo::share($d, $uid); $due += $d['amount'] * $sh; $paid += $d['paid_amount'] * $sh; }
            $rows[$m] = [$due, $paid]; $tDue += $due; $tPaid += $paid; $max = max($max, $due);
        }
        $t = "📆 <b>گزارش سال " . U::fa($jy) . "</b>\n\n";
        foreach ($rows as $m => [$due, $paid]) {
            $len = (int)round($due / $max * 8);
            $t .= J::MONTHS[$m] . ' ' . str_repeat('█', $len) . ($len ? ' ' : '') . U::n($due) . ($due > 0 && $paid >= $due ? ' ✅' : ($paid > 0 ? ' 🟡' : '')) . "\n";
        }
        $t .= "\n💰 جمع سال: <b>" . U::toman($tDue) . "</b>\n✅ پرداخت‌شده: " . U::toman($tPaid) . "\n⏳ مانده: " . U::toman(max(0, $tDue - $tPaid)) . "\n";
        $t .= "📊 میانگین ماهانه: " . U::toman($tDue / 12) . "\n";
        // by category for the year
        [$from] = J::monthRange($jy, 1); [, $to] = J::monthRange($jy, 12);
        $byCat = [];
        foreach (Repo::duesInRange($uid, $from, $to) as $d) { $cid = (int)$d['category_id']; $byCat[$cid] = ($byCat[$cid] ?? 0) + $d['amount'] * Repo::share($d, $uid); }
        if ($byCat) {
            arsort($byCat);
            $t .= "\n<b>به تفکیک دسته:</b>\n";
            foreach ($byCat as $cid => $v) $t .= Repo::catLabel($cid ?: null) . ': ' . U::n($v) . ' (' . U::fa($tDue ? round($v / $tDue * 100) : 0) . "٪)\n";
        }
        Bot::out($t, U::ikb([
            [U::btn('◀️ سال قبل', 'r:y:' . ($off - 1)), U::btn('سال بعد ▶️', 'r:y:' . ($off + 1))],
            [U::btn('📥 اکسل این سال', 'r:x:' . $jy), U::btn('🔙 گزارش‌ها', 'r:menu')],
        ]));
    }

    public static function excel(array $u, ?int $jy) {
        $uid = (int)$u['id'];
        $sql = "SELECT d.*, o.title, o.type, o.category_id, o.creditor, o.user_id, o.shared_user_id, o.owner_share FROM dues d JOIN obligations o ON o.id=d.obligation_id WHERE (o.user_id=? OR o.shared_user_id=?)";
        $p = [$uid, $uid];
        if ($jy) { [$from] = J::monthRange($jy, 1); [, $to] = J::monthRange($jy, 12); $sql .= " AND d.due_date BETWEEN ? AND ?"; $p[] = $from; $p[] = $to; }
        $rows = DB::all($sql . " ORDER BY d.due_date, o.id, d.seq", $p);
        $types = ['loan' => 'وام/قسط', 'recurring' => 'دوره‌ای', 'once' => 'یک‌باره'];
        $st = ['paid' => 'پرداخت‌شده', 'partial' => 'جزئی', 'pending' => 'در انتظار'];
        $data = [['ردیف', 'عنوان', 'نوع', 'دسته', 'طرف حساب', 'شماره قسط', 'سررسید (شمسی)', 'ماه', 'مبلغ', 'سهم من', 'پرداخت‌شده', 'مانده', 'وضعیت', 'تاریخ پرداخت', 'مشترک با']];
        $i = 0; $today = date('Y-m-d');
        foreach ($rows as $d) {
            $sh = Repo::share($d, $uid);
            [$y, $m] = J::fromG($d['due_date']);
            $status = $st[$d['status']] . ($d['status'] !== 'paid' && $d['due_date'] < $today ? ' (معوقه)' : '');
            $data[] = [++$i, $d['title'], $types[$d['type']], preg_replace('/^\S+\s/u', '', Repo::catLabel($d['category_id'] ? (int)$d['category_id'] : null)), (string)$d['creditor'],
                (int)$d['seq'], sprintf('%04d/%02d/%02d', ...J::fromG($d['due_date'])), J::MONTHS[$m] . ' ' . $y,
                (int)$d['amount'], (int)round($d['amount'] * $sh), (int)$d['paid_amount'], (int)($d['amount'] - $d['paid_amount']), $status,
                $d['paid_at'] ? sprintf('%04d/%02d/%02d', ...J::fromG($d['paid_at'])) : '', $d['shared_user_id'] ? Repo::uname(Repo::partnerOf($d, $uid)) : ''];
        }
        if ($i === 0) { Bot::toastPublic('داده‌ای برای خروجی وجود ندارد', true); return; }
        $name = 'aqsat-' . ($jy ?: 'all') . '-' . date('Ymd-His') . '.xlsx';
        $path = APP_DIR . '/tmp/' . $name;
        $ok = Xlsx::write($path, $data, 'اقساط و پرداخت‌ها');
        if (!$ok) { $path = substr($path, 0, -5) . '.csv'; Xlsx::csv($path, $data); }
        Bot::toastPublic('در حال ارسال فایل...');
        TG::doc($u['id'], $path, '📥 خروجی ' . ($jy ? 'سال ' . U::fa($jy) : 'کامل') . ' — ' . U::fa($i) . ' ردیف');
        if (TG::$mock === null) @unlink($path);
    }
}

/** Minimal XLSX writer (no dependencies) */
class Xlsx {
    public static function write(string $path, array $rows, string $sheet = 'Sheet1'): bool {
        if (!class_exists('ZipArchive')) return false;
        $esc = fn($s) => htmlspecialchars((string)$s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $col = function ($i) { $s = ''; $i++; while ($i > 0) { $m = ($i - 1) % 26; $s = chr(65 + $m) . $s; $i = intdiv($i - 1, 26); } return $s; };
        $xml = '';
        foreach ($rows as $r => $row) {
            $xml .= '<row r="' . ($r + 1) . '">';
            foreach (array_values($row) as $c => $v) {
                $ref = $col($c) . ($r + 1);
                $style = $r === 0 ? ' s="1"' : (is_int($v) && $v >= 1000 ? ' s="2"' : '');
                if (is_int($v) || is_float($v)) $xml .= "<c r=\"$ref\"$style><v>$v</v></c>";
                else $xml .= "<c r=\"$ref\" t=\"inlineStr\"$style><is><t>" . $esc($v) . '</t></is></c>';
            }
            $xml .= '</row>';
        }
        $widths = ''; $n = count($rows[0]);
        for ($c = 0; $c < $n; $c++) {
            $w = 10; foreach ($rows as $row) $w = max($w, mb_strlen((string)array_values($row)[$c]) + 3);
            $widths .= '<col min="' . ($c + 1) . '" max="' . ($c + 1) . '" width="' . min($w, 45) . '" customWidth="1"/>';
        }
        $last = $col($n - 1) . count($rows);
        $sheetXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheetViews><sheetView workbookViewId="0" rightToLeft="1"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
            . "<cols>$widths</cols><sheetData>$xml</sheetData><autoFilter ref=\"A1:$last\"/></worksheet>";
        $styles = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<numFmts count="1"><numFmt numFmtId="164" formatCode="#,##0"/></numFmts>'
            . '<fonts count="2"><font><sz val="11"/><name val="Tahoma"/></font><font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Tahoma"/></font></fonts>'
            . '<fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FF2F5597"/></patternFill></fill></fills>'
            . '<borders count="1"><border/></borders><cellStyleXfs count="1"><xf/></cellStyleXfs>'
            . '<cellXfs count="3"><xf/><xf fontId="1" fillId="2" applyFont="1" applyFill="1"><alignment horizontal="center"/></xf><xf numFmtId="164" applyNumberFormat="1"/></cellXfs></styleSheet>';
        $z = new ZipArchive();
        if ($z->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) return false;
        $z->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/></Types>');
        $z->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
        $z->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><bookViews><workbookView/></bookViews><sheets><sheet name="' . $esc(mb_substr($sheet, 0, 31)) . '" sheetId="1" r:id="rId1"/></sheets><definedNames><definedName name="_xlnm._FilterDatabase" localSheetId="0" hidden="1">\'' . $esc(mb_substr($sheet, 0, 31)) . "'!\$A\$1:\$" . preg_replace('/\d+/', '', $last) . '$' . count($rows) . '</definedName></definedNames></workbook>');
        $z->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>');
        $z->addFromString('xl/worksheets/sheet1.xml', $sheetXml);
        $z->addFromString('xl/styles.xml', $styles);
        return $z->close();
    }
    public static function csv(string $path, array $rows) {
        $f = fopen($path, 'w'); fwrite($f, "\xEF\xBB\xBF");
        foreach ($rows as $r) fputcsv($f, $r);
        fclose($f);
    }
}
