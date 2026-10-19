<?php
/* ---------- Bootstrap ---------- */
if (!defined('APP_DIR')) define('APP_DIR', dirname(__DIR__));
if (!file_exists(APP_DIR . '/config.php')) { http_response_code(500); exit('config.php not found'); }
$GLOBALS['CFG'] = require APP_DIR . '/config.php';
date_default_timezone_set($GLOBALS['CFG']['timezone'] ?? 'Asia/Tehran');
mb_internal_encoding('UTF-8');

function cfg($k, $d = null) { return $GLOBALS['CFG'][$k] ?? $d; }
/** a configured secret is usable only if it was changed from the sample and is long enough */
function secretOk(string $k): bool { $v = (string)cfg($k, ''); return strlen($v) >= 16 && stripos($v, 'CHANGE-ME') !== 0; }
/** constant-time check of a request-supplied value against a configured secret */
function secretMatch(string $k, $given): bool { return secretOk($k) && is_string($given) && hash_equals((string)cfg($k), $given); }

function logError($msg) {
    @file_put_contents(APP_DIR . '/logs/error.log', '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n", FILE_APPEND);
}
set_exception_handler(function ($e) { logError($e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine()); });

/* ---------- Database ---------- */
class DB {
    public static ?PDO $pdo = null;
    public static function pdo(): PDO {
        if (!self::$pdo) {
            $c = cfg('db');
            self::$pdo = new PDO("mysql:host={$c['host']};dbname={$c['name']};charset=utf8mb4", $c['user'], $c['pass'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
            self::$pdo->exec("SET time_zone = '" . date('P') . "'");
        }
        return self::$pdo;
    }
    public static function q(string $sql, array $p = []): PDOStatement { $s = self::pdo()->prepare($sql); $s->execute($p); return $s; }
    public static function row(string $sql, array $p = []) { return self::q($sql, $p)->fetch() ?: null; }
    public static function all(string $sql, array $p = []): array { return self::q($sql, $p)->fetchAll(); }
    public static function val(string $sql, array $p = []) { $v = self::q($sql, $p)->fetchColumn(); return $v === false ? null : $v; }
    public static function insert(string $sql, array $p = []): int { self::q($sql, $p); return (int)self::pdo()->lastInsertId(); }
    public static function setting(string $k, $v = null) {
        if (func_num_args() === 1) return self::val("SELECT v FROM settings WHERE k=?", [$k]);
        self::q("REPLACE INTO settings (k,v) VALUES (?,?)", [$k, $v]);
    }
}

/* ---------- Telegram API ---------- */
class TG {
    public static ?array $mock = null; // for tests: collects calls instead of sending
    public static function call(string $method, array $params = []) {
        if (self::$mock !== null) { self::$mock[] = [$method, $params]; return ['ok' => true, 'result' => ['message_id' => count(self::$mock)]]; }
        foreach ($params as $k => $v) if (is_array($v)) $params[$k] = json_encode($v, JSON_UNESCAPED_UNICODE);
        $ch = curl_init('https://api.telegram.org/bot' . cfg('bot_token') . '/' . $method);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => $params, CURLOPT_TIMEOUT => 30]);
        $res = curl_exec($ch);
        if ($res === false) { logError("TG $method curl: " . curl_error($ch)); return null; }
        $j = json_decode($res, true);
        if (empty($j['ok'])) logError("TG $method: " . $res);
        return $j;
    }
    public static function send($chat, string $text, $kb = null, array $extra = []) {
        $p = ['chat_id' => $chat, 'text' => $text, 'parse_mode' => 'HTML', 'disable_web_page_preview' => true] + $extra;
        if ($kb) $p['reply_markup'] = $kb;
        return self::call('sendMessage', $p);
    }
    public static function edit($chat, $msgId, string $text, $kb = null) {
        $p = ['chat_id' => $chat, 'message_id' => $msgId, 'text' => $text, 'parse_mode' => 'HTML', 'disable_web_page_preview' => true];
        if ($kb) $p['reply_markup'] = $kb;
        $r = self::call('editMessageText', $p);
        if ($r && empty($r['ok']) && strpos($r['description'] ?? '', 'not modified') === false) return self::send($chat, $text, $kb);
        return $r;
    }
    public static function answer($cbId, string $text = '', bool $alert = false) {
        return self::call('answerCallbackQuery', ['callback_query_id' => $cbId, 'text' => $text, 'show_alert' => $alert]);
    }
    public static function doc($chat, string $path, string $caption = '', string $name = null) {
        if (self::$mock !== null) { self::$mock[] = ['sendDocument', ['chat_id' => $chat, 'file' => $path, 'caption' => $caption]]; return ['ok' => true]; }
        return self::call('sendDocument', ['chat_id' => $chat, 'caption' => $caption, 'parse_mode' => 'HTML',
            'document' => new CURLFile($path, 'application/octet-stream', $name ?: basename($path))]);
    }
}

/* ---------- Jalali calendar ---------- */
class J {
    const MONTHS = [1 => 'فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'مرداد', 'شهریور', 'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند'];
    const WEEKDAYS = ['یکشنبه', 'دوشنبه', 'سه‌شنبه', 'چهارشنبه', 'پنجشنبه', 'جمعه', 'شنبه'];

    public static function g2j(int $gy, int $gm, int $gd): array {
        $g_d_m = [0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334];
        $gy2 = ($gm > 2) ? ($gy + 1) : $gy;
        $days = 355666 + (365 * $gy) + intdiv($gy2 + 3, 4) - intdiv($gy2 + 99, 100) + intdiv($gy2 + 399, 400) + $gd + $g_d_m[$gm - 1];
        $jy = -1595 + (33 * intdiv($days, 12053)); $days %= 12053;
        $jy += 4 * intdiv($days, 1461); $days %= 1461;
        if ($days > 365) { $jy += intdiv($days - 1, 365); $days = ($days - 1) % 365; }
        if ($days < 186) { $jm = 1 + intdiv($days, 31); $jd = 1 + ($days % 31); }
        else { $jm = 7 + intdiv($days - 186, 30); $jd = 1 + (($days - 186) % 30); }
        return [$jy, $jm, $jd];
    }
    public static function j2g(int $jy, int $jm, int $jd): array {
        $jy += 1595;
        $days = -355668 + (365 * $jy) + (intdiv($jy, 33) * 8) + intdiv(($jy % 33) + 3, 4) + $jd + (($jm < 7) ? ($jm - 1) * 31 : (($jm - 7) * 30) + 186);
        $gy = 400 * intdiv($days, 146097); $days %= 146097;
        if ($days > 36524) { $days--; $gy += 100 * intdiv($days, 36524); $days %= 36524; if ($days >= 365) $days++; }
        $gy += 4 * intdiv($days, 1461); $days %= 1461;
        if ($days > 365) { $gy += intdiv($days - 1, 365); $days = ($days - 1) % 365; }
        $gd = $days + 1;
        $sal = [0, 31, (($gy % 4 == 0 && $gy % 100 != 0) || ($gy % 400 == 0)) ? 29 : 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];
        for ($gm = 0; $gm < 13 && $gd > $sal[$gm]; $gm++) $gd -= $sal[$gm];
        return [$gy, $gm, $gd];
    }
    public static function isLeap(int $jy): bool { [$y, $m, $d] = self::j2g($jy, 12, 30); return self::g2j($y, $m, $d) === [$jy, 12, 30]; }
    public static function monthLen(int $jy, int $jm): int { return $jm <= 6 ? 31 : ($jm <= 11 ? 30 : (self::isLeap($jy) ? 30 : 29)); }

    /** gregorian 'Y-m-d' -> [jy,jm,jd] */
    public static function fromG(string $g): array { [$y, $m, $d] = array_map('intval', explode('-', substr($g, 0, 10))); return self::g2j($y, $m, $d); }
    /** jalali -> gregorian 'Y-m-d' */
    public static function toG(int $jy, int $jm, int $jd): string { [$y, $m, $d] = self::j2g($jy, $jm, $jd); return sprintf('%04d-%02d-%02d', $y, $m, $d); }
    public static function today(): array { return self::fromG(date('Y-m-d')); }
    public static function addMonths(int $jy, int $jm, int $day, int $n): string {
        $t = ($jy * 12 + ($jm - 1)) + $n; $y = intdiv($t, 12); $m = $t % 12 + 1;
        return self::toG($y, $m, min($day, self::monthLen($y, $m)));
    }
    /** month range [startG, endG] */
    public static function monthRange(int $jy, int $jm): array { return [self::toG($jy, $jm, 1), self::toG($jy, $jm, self::monthLen($jy, $jm))]; }
    public static function ym(int $jy, int $jm): string { return sprintf('%04d-%02d', $jy, $jm); }

    /** parse "1405/8/15", "۱۴۰۵-۰۸-۱۵", "8/15" (current year) */
    public static function parse(string $s): ?string {
        $s = U::num($s);
        if (!preg_match('~^(?:(\d{2,4})[/\-\.\s])?(\d{1,2})[/\-\.\s](\d{1,2})$~', trim($s), $m)) return null;
        $jy = $m[1] !== '' ? (int)$m[1] : self::today()[0];
        if ($jy < 100) $jy += 1400;
        $jm = (int)$m[2]; $jd = (int)$m[3];
        if ($jy < 1300 || $jy > 1500 || $jm < 1 || $jm > 12 || $jd < 1 || $jd > self::monthLen($jy, $jm)) return null;
        return self::toG($jy, $jm, $jd);
    }
    public static function fmt(?string $g, bool $long = false): string {
        if (!$g) return '-';
        [$y, $m, $d] = self::fromG($g);
        if ($long) return U::fa($d) . ' ' . self::MONTHS[$m] . ' ' . U::fa($y);
        return U::fa(sprintf('%04d/%02d/%02d', $y, $m, $d));
    }
    public static function weekday(string $g): string { return self::WEEKDAYS[(int)date('w', strtotime($g))]; }
    public static function monthName(int $jy, int $jm): string { return self::MONTHS[$jm] . ' ' . U::fa($jy); }
}

/* ---------- Utils ---------- */
class U {
    public static function fa($s): string { return strtr((string)$s, ['0' => '۰', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴', '5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹']); }
    public static function num(string $s): string {
        return strtr($s, ['۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9']);
    }
    /** parse money: "1,500,000" / "۱.۵ میلیون" / "2m" / "500 هزار" -> int toman */
    public static function money(string $s): ?int {
        $s = trim(str_replace([',', '٬', '،', ' ', 'تومان', 'تومن'], '', self::num($s)));
        $mul = 1;
        if (preg_match('~(میلیون|ملیون|m)$~iu', $s)) { $mul = 1000000; $s = preg_replace('~(میلیون|ملیون|m)$~iu', '', $s); }
        elseif (preg_match('~(هزار|k)$~iu', $s)) { $mul = 1000; $s = preg_replace('~(هزار|k)$~iu', '', $s); }
        elseif (preg_match('~(میلیارد|b)$~iu', $s)) { $mul = 1000000000; $s = preg_replace('~(میلیارد|b)$~iu', '', $s); }
        $s = str_replace('/', '.', $s);
        if (!is_numeric($s)) return null;
        $v = (int)round((float)$s * $mul);
        return $v > 0 ? $v : null;
    }
    public static function int(string $s): ?int { $s = trim(self::num($s)); return ctype_digit($s) ? (int)$s : null; }
    public static function toman($v): string { return self::fa(number_format((int)$v)) . ' تومان'; }
    public static function n($v): string { return self::fa(number_format((int)$v)); }
    public static function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
    public static function bar(float $ratio, int $len = 10): string {
        $ratio = max(0, min(1, $ratio)); $f = (int)round($ratio * $len);
        return str_repeat('▰', $f) . str_repeat('▱', $len - $f) . ' ' . self::fa((int)round($ratio * 100)) . '٪';
    }
    public static function daysBetween(string $a, string $b): int { return (int)round((strtotime($b) - strtotime($a)) / 86400); }
    public static function relDay(string $due): string {
        $d = self::daysBetween(date('Y-m-d'), $due);
        if ($d === 0) return 'امروز';
        if ($d === 1) return 'فردا';
        if ($d > 1) return self::fa($d) . ' روز دیگر';
        return self::fa(-$d) . ' روز گذشته';
    }
    public static function ikb(array $rows): array { return ['inline_keyboard' => array_values(array_filter($rows))]; }
    public static function btn(string $t, string $d): array { return ['text' => $t, 'callback_data' => $d]; }
}
