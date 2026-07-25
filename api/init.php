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
function fail(string $msg, int $code = 200): void {
    http_response_code($code);
    echo json_encode(['success' => false, 'authFailed' => ($code === 401), 'message' => $msg]);
    exit;
}

// -- Validasi token (pakai helper yang robust di Apache) -----------------------
$db      = getDb();
$session = requireSession($db);

// -- Suppliers -----------------------------------------------------------------
$supplierMap   = [];
$supplierOrder = [];

$rows = $db->query(
    "SELECT s.material_name, ms.supplier_name
     FROM suppliers s
     LEFT JOIN material_suppliers ms ON ms.material_name = s.material_name
     ORDER BY s.display_order, s.material_name, ms.display_order"
)->fetchAll();

foreach ($rows as $row) {
    $mat = $row['material_name'];
    if (!isset($supplierMap[$mat])) {
        $supplierOrder[] = $mat;
        $supplierMap[$mat] = [];
    }
    if ($row['supplier_name'] !== null) {
        $supplierMap[$mat][] = $row['supplier_name'];
    }
}

// -- Conversions ---------------------------------------------------------------
$convStmt = $db->query(
    "SELECT mid, item_name AS name, cat_bag AS \"catBag\", cat_box AS \"catBox\",
            ratio::float, weight_grams::float AS weight
     FROM conversions ORDER BY item_name"
);
$conversions = $convStmt->fetchAll();

// -- Config (mesin / size / aliases) ------------------------------------------
$cfgStmt = $db->prepare(
    "SELECT key, value FROM settings WHERE key IN ('LIST_MESIN','LIST_SIZE','LIST_ALIASES')"
);
$cfgStmt->execute();
$cfg = [];
foreach ($cfgStmt->fetchAll() as $row) $cfg[$row['key']] = $row['value'];

$defaultMesin = 'Mesin BHP 1,Mesin BHP 2,Mesin BHP 3,Mesin AHP 1,Mesin BHP 4,Mesin BHP 5,Mesin NAP 1,Mesin PAN 1';
$defaultSize  = 'Size S,Size M,Size L,Size XL,Size XXL';

$mesinStr = $cfg['LIST_MESIN'] ?? $defaultMesin;
$sizeStr  = $cfg['LIST_SIZE']  ?? $defaultSize;
$aliases  = $cfg['LIST_ALIASES'] ?? '{}';

ok([
    'suppliers'  => ['map' => $supplierMap, 'order' => $supplierOrder],
    'conversion' => $conversions,
    'config'     => [
        'mesin'   => array_map('trim', explode(',', $mesinStr)),
        'size'    => array_map('trim', explode(',', $sizeStr)),
        'aliases' => $aliases,
    ],
]);
