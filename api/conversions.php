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

$db      = getDb();
$session = requireSession($db);
$b       = body();
$action  = trim((string)($_GET['action'] ?? $b['action'] ?? ''));

// GET / list
if ($action === 'list' || ($action === '' && $_SERVER['REQUEST_METHOD'] === 'GET')) {
    $rows = $db->query(
        "SELECT mid, item_name AS name, cat_bag AS \"catBag\", cat_box AS \"catBox\",
                ratio::float, weight_grams::float AS weight
         FROM conversions ORDER BY item_name"
    )->fetchAll();
    ok($rows);
}

// POST: save (upsert)
if ($action === 'save') {
    $oldMid = trim((string)($b['oldMid'] ?? ''));
    $mid    = trim((string)($b['mid']    ?? ''));
    $name   = strtoupper(trim((string)($b['name'] ?? '')));

    if (!$mid || !$name) fail('MID dan Nama wajib diisi.');

    // Cek duplikat MID (jika bukan rename)
    if (!$oldMid || $oldMid !== $mid) {
        $dup = $db->prepare("SELECT 1 FROM conversions WHERE mid = :m");
        $dup->execute([':m' => $mid]);
        if ($dup->fetchColumn()) fail('MID sudah terdaftar!');
    }

    $db->beginTransaction();
    try {
        if ($oldMid && $oldMid !== $mid) {
            // Hapus lama, insert baru (rename MID)
            $db->prepare("DELETE FROM conversions WHERE mid = :m")->execute([':m' => $oldMid]);
        }
        $db->prepare(
            "INSERT INTO conversions (mid, item_name, cat_bag, cat_box, ratio, weight_grams)
             VALUES (:mid, :name, :catbag, :catbox, :ratio, :weight)
             ON CONFLICT (mid) DO UPDATE SET
               item_name    = EXCLUDED.item_name,
               cat_bag      = EXCLUDED.cat_bag,
               cat_box      = EXCLUDED.cat_box,
               ratio        = EXCLUDED.ratio,
               weight_grams = EXCLUDED.weight_grams"
        )->execute([
            ':mid'    => $mid,
            ':name'   => $name,
            ':catbag' => strtoupper(trim((string)($b['catBag'] ?? ''))),
            ':catbox' => strtoupper(trim((string)($b['catBox'] ?? ''))),
            ':ratio'  => (float)($b['ratio']  ?? 0),
            ':weight' => (float)($b['weight'] ?? 0),
        ]);
        $db->commit();
    } catch (Throwable $e) { $db->rollBack(); fail('Gagal menyimpan: ' . $e->getMessage()); }
    ok(true);
}

// POST: delete
if ($action === 'delete') {
    $mid = trim((string)($b['mid'] ?? $_GET['mid'] ?? ''));
    if (!$mid) fail('mid wajib diisi.');
    $del = $db->prepare("DELETE FROM conversions WHERE mid = :m");
    $del->execute([':m' => $mid]);
    if (!$del->rowCount()) fail('Data tidak ditemukan.');
    ok(true);
}

fail("Action '$action' tidak dikenal.", 400);
