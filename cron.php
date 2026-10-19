<?php
/* Run every 15 minutes:  php /home/USER/aqsat-bot/cron.php
   or via URL:            https://your-domain/aqsat-bot/cron.php?key=CRON_KEY */
define('APP_DIR', __DIR__);
require __DIR__ . '/lib/app.php';

if (PHP_SAPI !== 'cli' && !secretMatch('cron_key', $_GET['key'] ?? '')) { http_response_code(403); exit('forbidden'); }

$lock = fopen(__DIR__ . '/tmp/cron.lock', 'c');
if (!flock($lock, LOCK_EX | LOCK_NB)) exit("already running\n");
set_time_limit(300);
$log = Reminders::run();
echo date('Y-m-d H:i:s') . " OK\n" . implode("\n", $log) . "\n";
flock($lock, LOCK_UN);
