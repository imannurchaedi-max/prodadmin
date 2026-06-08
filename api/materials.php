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
function fail(string $m, int $c = 200): void { http_response_code($c); echo json_encode(['success' => false, 'message' => $m]); exit; }

function body(): array {
    static $b = null;
    if ($b === null) { $raw = file_get_contents('php://input'); $b = json_decode($raw ?: '{}', true) ?? []; }
    return $b;
}

function requireAuth(PDO $db): array {
    return requireSession($db);
}

$db      = getDb();
$session = requireAuth($db);
$b       = body();
$action  = trim((string)($_GET['action'] ?? $b['action'] ?? ''));

// -- GET: daftar material + supplier ------------------------------------------
if ($action === 'list' || $_SERVER['REQUEST_METHOD'] === 'GET') {
    $rows = $db->query(
        "SELECT s.material_name, ms.supplier_name, s.display_order
         FROM suppliers s
         LEFT JOIN material_suppliers ms ON ms.material_name = s.material_name
         ORDER BY s.display_order, s.material_name, ms.display_order"
    )->fetchAll();

    $map = []; $order = [];
    foreach ($rows as $r) {
        $mat = $r['material_name'];
        if (!isset($map[$mat])) { $order[] = $mat; $map[$mat] = []; }
        if ($r['supplier_name'] !== null) $map[$mat][] = $r['supplier_name'];
    }
    ok(['map' => $map, 'order' => $order]);
}

// -- POST: saveList - simpan urutan baru ---------------------------------------
if ($action === 'saveList') {
    $orderList = $b['order'] ?? [];
    if (!is_array($orderList)) fail('order harus array.');

    $db->beginTransaction();
    try {
        // Reset display_order dari daftar yang dikirim
        $stmtUps = $db->prepare(
            "INSERT INTO suppliers (material_name, display_order)
             VALUES (:n, :o)
             ON CONFLICT (material_name) DO UPDATE SET display_order = EXCLUDED.display_order"
        );
        foreach ($orderList as $i => $name) {
            $stmtUps->execute([':n' => strtoupper(trim((string)$name)), ':o' => $i]);
        }
        $db->commit();
    } catch (Throwable $e) { $db->rollBack(); fail('Gagal menyimpan urutan: ' . $e->getMessage()); }
    ok(true);
}

// -- POST: delete --------------------------------------------------------------
if ($action === 'delete') {
    $name = strtoupper(trim((string)($b['name'] ?? '')));
    if (!$name) fail('name wajib diisi.');

    $del = $db->prepare("DELETE FROM suppliers WHERE material_name = :n");
    $del->execute([':n' => $name]);
    if (!$del->rowCount()) fail('Material tidak ditemukan.');
    ok(true);
}

// -- POST: update - rename + ganti supplier ------------------------------------
if ($action === 'update') {
    $oldName   = strtoupper(trim((string)($b['oldName']   ?? '')));
    $newName   = strtoupper(trim((string)($b['newName']   ?? '')));
    $suppliers = array_filter(array_map('trim', (array)($b['suppliers'] ?? [])));

    if (!$newName) fail('newName wajib diisi.');

    $db->beginTransaction();
    try {
        if ($oldName && $oldName !== $newName) {
            // Rename material
            $db->prepare("UPDATE suppliers SET material_name = :new WHERE material_name = :old")
               ->execute([':new' => $newName, ':old' => $oldName]);
        } elseif (!$oldName) {
            // Insert baru
            $maxOrder = $db->query("SELECT COALESCE(MAX(display_order),0)+1 FROM suppliers")->fetchColumn();
            $db->prepare("INSERT INTO suppliers (material_name, display_order) VALUES (:n, :o) ON CONFLICT DO NOTHING")
               ->execute([':n' => $newName, ':o' => $maxOrder]);
        }

        // Replace supplier list
        $db->prepare("DELETE FROM material_suppliers WHERE material_name = :n")->execute([':n' => $newName]);
        $stmtSup = $db->prepare(
            "INSERT INTO material_suppliers (material_name, supplier_name, display_order) VALUES (:mat, :sup, :ord)"
        );
        foreach (array_values($suppliers) as $i => $sup) {
            $stmtSup->execute([':mat' => $newName, ':sup' => $sup, ':ord' => $i]);
        }
        $db->commit();
    } catch (Throwable $e) { $db->rollBack(); fail('Gagal update: ' . $e->getMessage()); }
    ok(true);
}

fail("Action '$action' tidak dikenal.", 400);
