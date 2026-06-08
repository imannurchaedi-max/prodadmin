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

function ok(mixed $data): void { echo json_encode(['success' => true, 'data' => $data]); exit; }
function fail(string $msg): void { echo json_encode(['success' => false, 'message' => $msg]); exit; }

function getSettingValues(PDO $db, array $keys): array {
    $placeholders = implode(',', array_fill(0, count($keys), '?'));
    $stmt = $db->prepare("SELECT key, value FROM settings WHERE key IN ($placeholders)");
    $stmt->execute(array_values($keys));
    $result = [];
    foreach ($stmt->fetchAll() as $row) $result[$row['key']] = $row['value'];
    return $result;
}

$action = trim((string) ($_GET['action'] ?? 'get'));

if ($action === 'get') {
    $db   = getDb();
    $cfg  = getSettingValues($db, ['LIST_MESIN', 'LIST_SIZE', 'LIST_ALIASES']);

    $defaultMesin = 'Mesin BHP 1,Mesin BHP 2,Mesin BHP 3,Mesin AHP 1,Mesin BHP 4,Mesin BHP 5,Mesin NAP 1,Mesin PAN 1';
    $defaultSize  = 'Size S,Size M,Size L,Size XL,Size XXL';

    $mesinStr = $cfg['LIST_MESIN'] ?? $defaultMesin;
    $sizeStr  = $cfg['LIST_SIZE']  ?? $defaultSize;
    $aliases  = $cfg['LIST_ALIASES'] ?? '{}';

    ok([
        'mesin'   => array_map('trim', explode(',', $mesinStr)),
        'size'    => array_map('trim', explode(',', $sizeStr)),
        'aliases' => json_decode($aliases, true) ?? [],
        // Raw strings juga dikembalikan untuk admin settings form
        'mesinRaw'   => $mesinStr,
        'sizeRaw'    => $sizeStr,
        'aliasesRaw' => $aliases,
    ]);
}

if ($action === 'save') {
    $db = getDb();
    requireSession($db);

    // Butuh auth token — validasi dulu
    $raw = file_get_contents('php://input');
    $body = json_decode($raw ?: '{}', true) ?? [];

    $mesinStr   = trim((string) ($body['mesin']   ?? ''));
    $sizeStr    = trim((string) ($body['size']    ?? ''));
    $aliasesStr = trim((string) ($body['aliases'] ?? '{}'));

    if ($mesinStr === '' || $sizeStr === '') fail('mesin dan size tidak boleh kosong.');

    $stmt = $db->prepare(
        "INSERT INTO settings (key, value) VALUES (:k, :v)
         ON CONFLICT (key) DO UPDATE SET value = EXCLUDED.value"
    );
    foreach ([
        'LIST_MESIN'   => $mesinStr,
        'LIST_SIZE'    => $sizeStr,
        'LIST_ALIASES' => $aliasesStr,
    ] as $k => $v) {
        $stmt->execute([':k' => $k, ':v' => $v]);
    }
    ok(true);
}

fail("Action '$action' tidak dikenal.");
