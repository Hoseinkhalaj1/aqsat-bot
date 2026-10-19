<?php
/* Mini App JSON API */
define('APP_DIR', __DIR__);
require __DIR__ . '/lib/app.php';
require __DIR__ . '/lib/Api.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit('{"ok":false}'); }

$in = json_decode(file_get_contents('php://input'), true);
if (!is_array($in)) $in = [];
try {
    Api::auth((string)($_SERVER['HTTP_X_INIT_DATA'] ?? ($in['_init'] ?? '')));
    $data = Api::handle((string)($in['action'] ?? ''), $in);
    echo json_encode(['ok' => true, 'data' => $data], JSON_UNESCAPED_UNICODE);
} catch (ApiError $e) {
    http_response_code(in_array($e->getMessage(), ['no_auth', 'bad_auth', 'expired', 'forbidden'], true) ? 401 : 400);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
} catch (Throwable $e) {
    logError('api: ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'server']);
}
