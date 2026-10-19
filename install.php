<?php
/* One-time installer: open https://your-domain/aqsat-bot/install.php?key=CRON_KEY
   then DELETE this file. */
define('APP_DIR', __DIR__);
require __DIR__ . '/lib/app.php';
header('Content-Type: text/html; charset=utf-8');
if (PHP_SAPI !== 'cli' && !secretMatch('cron_key', $_GET['key'] ?? '')) { http_response_code(403); exit('forbidden: set a random cron_key (16+ chars) in config.php'); }
if (!secretOk('webhook_secret')) exit('❌ webhook_secret را در config.php به یک رشته تصادفی ۱۶+ کاراکتری تغییر دهید.');

$out = [];
try {
    foreach (array_filter(array_map('trim', explode(';', file_get_contents(__DIR__ . '/lib/schema.sql')))) as $q) DB::pdo()->exec($q);
    Migrate::run();
    $out[] = '✅ جداول دیتابیس ساخته شد';
} catch (Throwable $e) { exit('❌ خطای دیتابیس: ' . htmlspecialchars($e->getMessage())); }

foreach (cfg('admin_ids', []) as $a) {
    if (!Repo::user((int)$a)) { DB::q("INSERT INTO users (id,name,is_allowed,is_admin,created_at) VALUES (?,?,1,1,NOW())", [(int)$a, '']); Repo::seedCats((int)$a); }
}
$out[] = '✅ مدیر(ها) ثبت شد';

$url = rtrim(cfg('base_url'), '/') . '/webhook.php';
$r = TG::call('setWebhook', ['url' => $url, 'secret_token' => cfg('webhook_secret'), 'allowed_updates' => ['message', 'callback_query'], 'drop_pending_updates' => true]);
$out[] = !empty($r['ok']) ? "✅ وبهوک تنظیم شد: $url" : '❌ خطای وبهوک: ' . htmlspecialchars(json_encode($r, JSON_UNESCAPED_UNICODE));

TG::call('setMyCommands', ['commands' => [
    ['command' => 'start', 'description' => 'شروع / منوی اصلی'],
    ['command' => 'menu', 'description' => 'نمایش منو'],
    ['command' => 'cancel', 'description' => 'لغو عملیات جاری'],
    ['command' => 'id', 'description' => 'نمایش شناسه من'],
]]);
$me = TG::call('getMe');
$out[] = !empty($me['ok']) ? '✅ ربات: @' . $me['result']['username'] : '❌ توکن ربات نامعتبر است';
$out[] = '<br><b>⚠️ حالا فایل install.php را از هاست حذف کنید.</b>';
echo '<div dir="rtl" style="font-family:tahoma;line-height:2">' . implode('<br>', $out) . '</div>';
