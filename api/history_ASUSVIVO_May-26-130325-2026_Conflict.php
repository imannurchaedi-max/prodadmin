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

function bearerToken(): string {
    $h = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if (!$h && function_exists('getallheaders')) {
        $headers = array_change_key_case(getallheaders(), CASE_LOWER);
        $h = $headers['authorization'] ?? '';
    }
    if ($h && preg_match('/Bearer\s+(.+)/i', $h, $m)) return trim($m[1]);
    return (string)($_GET['token'] ?? '');
}

function requireAuth(PDO $db): array {
    $token = bearerToken();
    if (!$token) fail('Token tidak diberikan.', 401);
    $stmt = $db->prepare(
        "SELECT s.username, u.role FROM sessions s JOIN users u ON u.username = s.username
         WHERE s.token = :t AND s.expires_at > NOW()"
    );
    $stmt->execute([':t' => $token]);
    $row = $stmt->fetch();
    if (!$row) fail('Sesi tidak valid.', 401);
    return $row;
}

$action = trim((string)($_GET['action'] ?? 'paged'));
$db     = getDb();
$session = requireAuth($db);

if ($action === 'paged' || $action === 'admin') {
    $page  = max(1, (int)($_GET['page']  ?? 1));
    $limit = min(100, max(1, (int)($_GET['limit'] ?? 10)));

    // Admin: tanpa filter tanggal; User: filter dari request
    if ($action === 'admin') {
        $startDate = '1970-01-01';
        $endDate   = '2099-12-31';
    } else {
        $startDate = (string)($_GET['startDate'] ?? date('Y-m-d', strtotime('-7 days')));
        $endDate   = (string)($_GET['endDate']   ?? date('Y-m-d'));
    }

    // Ambil semua UUID unik yang lolos filter (bukan HISTORY/SUPERSEDED)
    $stmtIds = $db->prepare(
        "SELECT DISTINCT t.uuid, MAX(t.created_at) AS latest
         FROM transactions t
         WHERE t.production_date BETWEEN :s AND :e
           AND t.status NOT IN ('HISTORY','SUPERSEDED')
         GROUP BY t.uuid
         ORDER BY latest DESC"
    );
    $stmtIds->execute([':s' => $startDate, ':e' => $endDate]);
    $allIds = array_column($stmtIds->fetchAll(), 'uuid');

    $total    = count($allIds);
    $pages    = (int)ceil($total / $limit);
    $pageIds  = array_slice($allIds, ($page - 1) * $limit, $limit);

    if (empty($pageIds)) {
        ok(['data' => [], 'total' => $total, 'page' => $page, 'totalPages' => $pages]);
    }

    $placeholders = implode(',', array_fill(0, count($pageIds), '?'));

    // Materials — join via transaction_id (FK ke transactions.id, bukan uuid)
    $stmtMat = $db->prepare(
        "SELECT t.uuid, t.production_date, t.created_at, t.shift_id, t.machine_id,
                t.product_size, t.revision_count, t.status, t.created_by,
                tm.material_name, tm.supplier_name, tm.stock_initial, tm.stock_in,
                tm.stock_return, tm.reject_amount, tm.production_amount, tm.total_usage,
                tm.stock_final, tm.label_photos, tm.id AS mat_id
         FROM transactions t
         JOIN transaction_materials tm ON tm.transaction_id = t.id
         WHERE t.uuid IN ($placeholders)
           AND t.status NOT IN ('HISTORY','SUPERSEDED')
         ORDER BY t.created_at DESC, tm.id"
    );
    $stmtMat->execute($pageIds);

    // Hourly usage
    $stmtHour = $db->prepare(
        "SELECT mhu.transaction_material_id, mhu.hour_idx, mhu.amount
         FROM material_hourly_usage mhu
         JOIN transaction_materials tm ON tm.id = mhu.transaction_material_id
         JOIN transactions t ON t.id = tm.transaction_id
         WHERE t.uuid IN ($placeholders)
           AND t.status NOT IN ('HISTORY','SUPERSEDED')"
    );
    $stmtHour->execute($pageIds);
    $hourMap = [];
    foreach ($stmtHour->fetchAll() as $h) {
        $hourMap[$h['transaction_material_id']][$h['hour_idx']] = (float)$h['amount'];
    }

    // Outputs
    $stmtOut = $db->prepare(
        "SELECT transaction_uuid, mid, product_name AS name, qty_box AS qty,
                counter_pcs AS counter, total_weight_kg AS kg, loss_kg AS loss
         FROM production_outputs
         WHERE transaction_uuid IN ($placeholders) AND status='ACTIVE'"
    );
    $stmtOut->execute($pageIds);
    $outMap = [];
    foreach ($stmtOut->fetchAll() as $o) {
        $outMap[$o['transaction_uuid']][] = [
            'mid' => $o['mid'], 'name' => $o['name'], 'qty' => (int)$o['qty'],
            'counter' => (int)$o['counter'], 'kg' => (float)$o['kg'], 'loss' => (float)$o['loss'],
        ];
    }

    // Reports
    $stmtRep = $db->prepare(
        "SELECT transaction_uuid, total_output_kg AS counterKg, total_loss_kg AS lossKg,
                avg_loss_pct AS lossPct, downtime_min AS downtimeMin, downtime_pct AS downtimePct,
                speed_ppm AS speed, trouble_logs AS trouble, near_miss AS nearMiss,
                notes, reject_printing_kg AS rejectPrintingKg
         FROM production_reports
         WHERE transaction_uuid IN ($placeholders) AND status='ACTIVE'"
    );
    $stmtRep->execute($pageIds);
    $repMap = [];
    foreach ($stmtRep->fetchAll() as $r) {
        $uid = $r['transaction_uuid'];
        $repMap[$uid] = [
            'counterKg' => $r['counterkg'] ?? 0,
            'lossKg' => $r['losskg'] ?? 0,
            'lossPct' => $r['losspct'] ?? 0,
            'downtimeMin' => $r['downtimemin'] ?? 0,
            'downtimePct' => $r['downtimepct'] ?? 0,
            'speed' => $r['speed'] ?? '',
            'trouble' => $r['trouble'] ?? '',
            'nearMiss' => $r['nearmiss'] ?? '',
            'notes' => $r['notes'] ?? '',
            'rejectPrintingKg' => $r['rejectprintingkg'] ?? 0,
        ];
    }

    // Group data
    $grouped = [];
    foreach ($stmtMat->fetchAll() as $row) {
        $uid = $row['uuid'];
        if (!isset($grouped[$uid])) {
            $d = $row['production_date'];
            $grouped[$uid] = [
                'id'         => $uid,
                'date'       => date('d-m-Y', strtotime($d)),
                'rawDate'    => $d,
                'timestamp'  => substr((string)$row['created_at'], 11, 5),
                'shift'      => (string)$row['shift_id'],
                'mesin'      => $row['machine_id'],
                'size'       => $row['product_size'],
                'revision'   => (int)$row['revision_count'],
                'status'     => $row['status'],
                'owner'      => $row['created_by'],
                'items'      => [],
                'totalUsage' => 0,
                'outputs'    => $outMap[$uid] ?? [],
                'logs'       => $repMap[$uid] ?? null,
            ];
        }

        $matId   = (int)$row['mat_id'];
        $hours24 = array_fill(0, 24, 0.0);
        foreach ($hourMap[$matId] ?? [] as $hIdx => $amt) $hours24[$hIdx] = $amt;

        $grouped[$uid]['items'][] = [
            'material' => $row['material_name'],
            'supplier' => $row['supplier_name'],
            'stock'    => (float)$row['stock_initial'],
            'in'       => (float)$row['stock_in'],
            'retur'    => (float)$row['stock_return'],
            'reject'   => (float)$row['reject_amount'],
            'total'    => (float)$row['total_usage'],
            'sisa'     => (float)$row['stock_final'],
            'hours'    => $hours24,
            'photos'   => $row['label_photos'] ? explode(',', $row['label_photos']) : [],
        ];
        $grouped[$uid]['totalUsage'] += (float)$row['total_usage'];
    }

    // Preserve order from $pageIds
    $data = [];
    foreach ($pageIds as $id) {
        if (isset($grouped[$id])) $data[] = $grouped[$id];
    }

    ok(['data' => $data, 'total' => $total, 'page' => $page, 'totalPages' => $pages]);
}

fail("Action '{$action}' tidak dikenal.", 400);
