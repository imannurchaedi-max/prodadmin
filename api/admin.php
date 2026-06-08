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

// H1: bearerToken() and requireAuth() removed â€” use requireSession() from auth_helper.php

$db     = getDb();
$action = trim((string)($_GET['action'] ?? body()['action'] ?? ''));

// -- verifyAdmin - tidak butuh session token -----------------------------------
if ($action === 'verifyAdmin') {
    // C3: rate-limit PIN attempts â€” max 5 per IP per 5 minutes (via APCu)
    $ip    = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $rlKey = 'admin_verify_' . hash('sha256', $ip);
    if (function_exists('apcu_fetch')) {
        $attempts = (int) apcu_fetch($rlKey);
        if ($attempts >= 5) {
            fail('Terlalu banyak percobaan. Coba lagi dalam 5 menit.', 429);
        }
        apcu_store($rlKey, $attempts + 1, 300);
    }
    // H6: read PIN from request body only â€” never from URL query params
    $pin    = (string)(body()['pin'] ?? '');
    $stored = $db->query("SELECT value FROM settings WHERE key='ADMIN_PIN'")->fetchColumn();
    $ok     = $pin === (string)$stored;
    if ($ok && function_exists('apcu_delete')) apcu_delete($rlKey); // reset counter on success
    ok($ok);
}

// Semua action lain butuh auth
$session = requireSession($db);

// -- stats (dashboard scorecard + chart data) ----------------------------------
if ($action === 'stats') {
    $today  = (new DateTime('now', new DateTimeZone('Asia/Jakarta')))->format('Y-m-d');
    $startDate = trim((string)($_GET['startDate'] ?? ''));
    $endDate   = trim((string)($_GET['endDate']   ?? ''));

    // Jika user belum memberi filter, fallback ke tanggal transaksi aktif terbaru.
    // Ini mencegah dashboard/chart kosong saat tidak ada data "hari ini"
    // tetapi data historis sebenarnya ada di database.
    $hasStart = preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate);
    $hasEnd   = preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate);
    if (!$hasStart || !$hasEnd) {
        $latestDate = $db->query(
            "SELECT MAX(production_date)::text
             FROM transactions
             WHERE status NOT IN ('HISTORY','SUPERSEDED')"
        )->fetchColumn();
        $fallbackDate = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$latestDate)
            ? (string)$latestDate
            : $today;
        if (!$hasStart) $startDate = $fallbackDate;
        if (!$hasEnd)   $endDate   = $fallbackDate;
    }
    if ($endDate < $startDate) $endDate = $startDate;

    $rows = $db->prepare(
        "SELECT t.shift_id, tm.material_name, tm.production_amount, tm.reject_amount, tm.stock_final
         FROM transactions t
         JOIN transaction_materials tm ON tm.transaction_id = t.id
         WHERE t.production_date BETWEEN :s AND :e
           AND t.status NOT IN ('HISTORY','SUPERSEDED')"
    );
    $rows->execute([':s' => $startDate, ':e' => $endDate]);
    $data = $rows->fetchAll();

    $totalToday   = 0;
    $alertCount   = 0;
    $prodMap      = [];
    $rejectMap    = [];
    $shiftArr     = [1=>0, 2=>0, 3=>0];

    foreach ($data as $r) {
        $prod   = (float)$r['production_amount'];
        $reject = (float)$r['reject_amount'];
        $sisa   = (float)$r['stock_final'];
        $mat    = $r['material_name'];
        $shift  = (int)$r['shift_id'];

        $totalToday += $prod;
        if ($sisa < 0) $alertCount++;
        if (isset($shiftArr[$shift])) $shiftArr[$shift] += $prod;
        if ($prod > 0)   $prodMap[$mat]   = ($prodMap[$mat]   ?? 0) + $prod;
        if ($reject > 0) $rejectMap[$mat] = ($rejectMap[$mat] ?? 0) + $reject;
    }

    arsort($prodMap);   $topMats = array_slice($prodMap,   0, 5, true);
    arsort($rejectMap); $topRej  = array_slice($rejectMap, 0, 5, true);

    ok([
        'totalToday'  => $totalToday,
        'alertCount'  => $alertCount,
        'topMaterial' => $topMats ? array_key_first($topMats) : '-',
        'isToday'     => ($startDate === $today && $endDate === $today),
        'dateRange'   => $startDate === $endDate ? $startDate : "$startDate s/d $endDate",
        'chartData'   => [
            'topMats' => ['labels' => array_keys($topMats),  'values' => array_values($topMats)],
            'rejects' => ['labels' => array_keys($topRej),   'values' => array_values($topRej)],
            'shifts'  => ['labels' => ['S1','S2','S3'],       'values' => [$shiftArr[1],$shiftArr[2],$shiftArr[3]]],
        ],
    ]);
}

// -- logs (audit) --------------------------------------------------------------
if ($action === 'logs') {
    // H6: PIN must come from request body â€” never from URL query params
    $pin    = (string)(body()['pin'] ?? '');
    $stored = $db->query("SELECT value FROM settings WHERE key='ADMIN_PIN'")->fetchColumn();
    if ($pin !== (string)$stored) ok([]); // kembalikan kosong jika PIN salah (sama dengan GAS)

    $logs = $db->query(
        "SELECT to_char(timestamp,'DD-MM HH24:MI') AS time, actor, action, details
         FROM audit_logs ORDER BY timestamp DESC LIMIT 50"
    )->fetchAll();
    ok($logs);
}

// -- photoProgress (tracking foto migrasi Google Drive) -----------------------
if ($action === 'photoProgress') {
    $summary = $db->query(
        "SELECT
            COUNT(*) FILTER (WHERE status='DONE')    AS done_count,
            COUNT(*) FILTER (WHERE status='PENDING') AS pending_count,
            COUNT(*) FILTER (WHERE status='FAILED')  AS failed_count,
            COUNT(*)                                 AS total_count
         FROM photo_migration"
    )->fetch() ?: [];

    $range = $db->query(
        "WITH per_day AS (
            SELECT
                t.production_date,
                COUNT(DISTINCT pm.gdrive_id) AS total_photos,
                COUNT(DISTINCT CASE WHEN pm.status='DONE' THEN pm.gdrive_id END) AS done_photos,
                COUNT(DISTINCT CASE WHEN pm.status='PENDING' THEN pm.gdrive_id END) AS pending_photos,
                COUNT(DISTINCT CASE WHEN pm.status='FAILED' THEN pm.gdrive_id END) AS failed_photos
            FROM photo_migration pm
            JOIN photo_migration_map pmm ON pmm.gdrive_id = pm.gdrive_id
            JOIN transaction_materials tm ON tm.id = pmm.tm_id
            JOIN transactions t ON t.id = tm.transaction_id
            GROUP BY t.production_date
         )
         SELECT
            MIN(production_date) FILTER (WHERE done_photos > 0) AS first_any_done,
            MAX(production_date) FILTER (WHERE done_photos > 0) AS last_any_done,
            MIN(production_date) FILTER (WHERE done_photos = total_photos AND total_photos > 0) AS first_fully_done,
            MAX(production_date) FILTER (WHERE done_photos = total_photos AND total_photos > 0) AS last_fully_done,
            COUNT(*) FILTER (WHERE done_photos = total_photos AND total_photos > 0) AS full_days,
            COUNT(*) FILTER (WHERE done_photos > 0 AND done_photos < total_photos) AS partial_days
         FROM per_day"
    )->fetch() ?: [];

    $latestDaysStmt = $db->query(
        "WITH per_day AS (
            SELECT
                t.production_date,
                COUNT(DISTINCT pm.gdrive_id) AS total_photos,
                COUNT(DISTINCT CASE WHEN pm.status='DONE' THEN pm.gdrive_id END) AS done_photos,
                COUNT(DISTINCT CASE WHEN pm.status='PENDING' THEN pm.gdrive_id END) AS pending_photos,
                COUNT(DISTINCT CASE WHEN pm.status='FAILED' THEN pm.gdrive_id END) AS failed_photos
            FROM photo_migration pm
            JOIN photo_migration_map pmm ON pmm.gdrive_id = pm.gdrive_id
            JOIN transaction_materials tm ON tm.id = pmm.tm_id
            JOIN transactions t ON t.id = tm.transaction_id
            GROUP BY t.production_date
         )
         SELECT
            production_date::text AS production_date,
            total_photos,
            done_photos,
            pending_photos,
            failed_photos
         FROM per_day
         WHERE done_photos > 0
         ORDER BY production_date DESC
         LIMIT 15"
    );
    $latestDays = $latestDaysStmt ? $latestDaysStmt->fetchAll() : [];

    $doneCount = (int)($summary['done_count'] ?? 0);
    $pendingCount = (int)($summary['pending_count'] ?? 0);
    $failedCount = (int)($summary['failed_count'] ?? 0);
    $totalCount = (int)($summary['total_count'] ?? 0);
    $progressPct = $totalCount > 0 ? round(($doneCount / $totalCount) * 100, 2) : 0;

    ok([
        'summary' => [
            'doneCount' => $doneCount,
            'pendingCount' => $pendingCount,
            'failedCount' => $failedCount,
            'totalCount' => $totalCount,
            'progressPct' => $progressPct,
        ],
        'range' => [
            'firstAnyDone' => $range['first_any_done'] ?? null,
            'lastAnyDone' => $range['last_any_done'] ?? null,
            'firstFullyDone' => $range['first_fully_done'] ?? null,
            'lastFullyDone' => $range['last_fully_done'] ?? null,
            'fullDays' => (int)($range['full_days'] ?? 0),
            'partialDays' => (int)($range['partial_days'] ?? 0),
        ],
        'latestDays' => array_map(static function(array $row): array {
            $total = (int)($row['total_photos'] ?? 0);
            $done = (int)($row['done_photos'] ?? 0);
            return [
                'productionDate' => $row['production_date'] ?? '',
                'totalPhotos' => $total,
                'donePhotos' => $done,
                'pendingPhotos' => (int)($row['pending_photos'] ?? 0),
                'failedPhotos' => (int)($row['failed_photos'] ?? 0),
                'progressPct' => $total > 0 ? round(($done / $total) * 100, 2) : 0,
            ];
        }, $latestDays),
    ]);
}

// -- changePassword (admin bisa reset password siapapun) -----------------------
if ($action === 'changePassword') {
    $b        = body();
    $username = trim((string)($b['username'] ?? ''));
    $oldPass  = (string)($b['oldPass'] ?? '');
    $newPass  = (string)($b['newPass'] ?? '');

    if (!$username || !$oldPass || !$newPass) fail('username, oldPass, newPass wajib.');

    // H7: removed dead code ($row = ... ? null : null) that was here
    $stmt = $db->prepare("SELECT password_hash FROM users WHERE username=:u");
    $stmt->execute([':u' => $username]);
    $user = $stmt->fetch();

    if (!$user) fail('User tidak ditemukan.');

    $isAdmin = ($session['role'] === 'admin');
    if (!$isAdmin && !password_verify($oldPass, $user['password_hash'])) {
        fail('Password lama salah.');
    }

    $db->prepare("UPDATE users SET password_hash=:h WHERE username=:u")
       ->execute([':h' => password_hash($newPass, PASSWORD_BCRYPT), ':u' => $username]);
    ok(true);
}

fail("Action '$action' tidak dikenal.", 400);
