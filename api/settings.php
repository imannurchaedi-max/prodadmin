<?php
declare(strict_types=1);

ini_set('display_errors', '0');
error_reporting(0);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth_helper.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

function ok(mixed $d): void { echo json_encode(['success' => true, 'data' => $d]); exit; }
function fail(string $m): void { echo json_encode(['success' => false, 'message' => $m]); exit; }

$db = getDb();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $rows = $db->query(
        "SELECT key, value FROM settings
         WHERE key IN ('ENABLE_HANDOVER','LOCK_DATE','BROADCAST_MSG','BROADCAST_ACTIVE','ADMIN_PIN')"
    )->fetchAll();
    $s = [];
    foreach ($rows as $r) $s[$r['key']] = $r['value'];

    ok([
        'enableHandover'  => ($s['ENABLE_HANDOVER']  ?? '') === 'TRUE',
        'lockDate'        => $s['LOCK_DATE']        ?? '',
        'broadcastMsg'    => $s['BROADCAST_MSG']    ?? '',
        'broadcastActive' => ($s['BROADCAST_ACTIVE'] ?? '') === 'TRUE',
    ]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireSession($db);
    $raw = file_get_contents('php://input');
    $b   = json_decode($raw ?: '{}', true) ?? [];

    $stmt = $db->prepare(
        "INSERT INTO settings (key, value) VALUES (:k, :v)
         ON CONFLICT (key) DO UPDATE SET value = EXCLUDED.value"
    );

    $map = [
        'handover'       => ['ENABLE_HANDOVER',  fn($v) => $v ? 'TRUE' : 'FALSE'],
        'lockDate'       => ['LOCK_DATE',         fn($v) => (string)$v],
        'broadcastMsg'   => ['BROADCAST_MSG',     fn($v) => (string)$v],
        'broadcastActive'=> ['BROADCAST_ACTIVE',  fn($v) => $v ? 'TRUE' : 'FALSE'],
    ];

    foreach ($map as $field => [$key, $transform]) {
        if (array_key_exists($field, $b)) {
            $stmt->execute([':k' => $key, ':v' => $transform($b[$field])]);
        }
    }
    ok(true);
}

fail('Method tidak didukung.');
