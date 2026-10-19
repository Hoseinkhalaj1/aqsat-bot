<?php
/* Telegram webhook endpoint */
define('APP_DIR', __DIR__);
require __DIR__ . '/lib/app.php';

if (!secretMatch('webhook_secret', $_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '')) { http_response_code(403); exit; }

$update = json_decode(file_get_contents('php://input'), true);
if (!is_array($update)) exit;

// ignore duplicate deliveries
$uid = (int)($update['update_id'] ?? 0);
$last = (int)DB::setting('last_update_id');
if ($uid && $uid <= $last && $last - $uid < 100000) exit;
DB::setting('last_update_id', $uid);

try {
    Bot::handle($update);
} catch (Throwable $e) {
    logError('webhook: ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
    $chat = $update['message']['chat']['id'] ?? $update['callback_query']['message']['chat']['id'] ?? null;
    if ($chat) TG::send($chat, '❗️ خطایی رخ داد. دوباره تلاش کنید.');
}
