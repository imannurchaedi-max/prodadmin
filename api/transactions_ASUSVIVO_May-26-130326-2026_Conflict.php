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

// -- Helpers ------------------------------------------------------------------

function ok(mixed $data): void { echo json_encode(['success' => true, 'data' => $data]); exit; }
function fail(string $msg, int $code = 200): void {
    http_response_code($code);
    echo json_encode(['success' => false, 'message' => $msg]);
    exit;
}

function body(): array {
    $raw = file_get_contents('php://input');
    if (!$raw) return [];
    return json_decode($raw, true) ?? [];
}

function param(string $key, mixed $default = null): mixed {
    if (isset($_GET[$key]))  return $_GET[$key];
    if (isset($_POST[$key])) return $_POST[$key];
    if (!isset($GLOBALS['_B'])) $GLOBALS['_B'] = body();
    return $GLOBALS['_B'][$key] ?? $default;
}

function bearerToken(): string {
    $h = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if (!$h && function_exists('getallheaders')) {
        $headers = array_change_key_case(getallheaders(), CASE_LOWER);
        $h = $headers['authorization'] ?? '';
    }
    if ($h && preg_match('/Bearer\s+(.+)/i', $h, $m)) return trim($m[1]);
    return (string) param('token', '');
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
    if (!$row) fail('Sesi tidak valid atau telah berakhir.', 401);
    return $row;
}

function getLockDate(PDO $db): string {
    $s = $db->query("SELECT value FROM settings WHERE key='LOCK_DATE'")->fetchColumn();
    return (string) ($s ?? '');
}

function genUuid(): string {
    return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0,0xffff), mt_rand(0,0xffff), mt_rand(0,0xffff),
        mt_rand(0,0x0fff)|0x4000, mt_rand(0,0x3fff)|0x8000,
        mt_rand(0,0xffff), mt_rand(0,0xffff), mt_rand(0,0xffff)
    );
}

// SHIFT_MAP: shift -> hour24 start index (matches GAS exactly)
const SHIFT_MAP = ['1' => 0, '2' => 8, '3' => 16];

function logAction(PDO $db, string $actor, string $action, string $details): void {
    try {
        $db->prepare(
            "INSERT INTO audit_logs (actor, action, details) VALUES (:a, :ac, :d)"
        )->execute([':a' => $actor, ':ac' => $action, ':d' => $details]);
    } catch (Throwable) {}
}

// -- Kalkulasi server-side (mirror logika GAS handleTransaction_) -------------
function calcMaterial(array $mat, string $shift): array {
    $stockAwal  = max(0, (float)($mat['stockAwal'] ?? 0));
    $masuk      = max(0, (float)($mat['masuk']     ?? 0));
    $retur      = max(0, (float)($mat['retur']     ?? 0));
    $reject     = max(0, (float)($mat['reject']    ?? 0));
    $hours8     = array_map(fn($v) => max(0, (float)$v), (array)($mat['hours'] ?? []));

    $hours24    = array_fill(0, 24, 0.0);
    $start      = SHIFT_MAP[$shift] ?? 0;
    foreach ($hours8 as $i => $v) {
        if ($i < 8) $hours24[$start + $i] = $v;
    }

    // Server menghitung ulang totalProd (jangan percaya client)
    $totalProd  = array_sum($hours24);
    $totalPakai = $totalProd + $reject;
    $sisaRaw    = $stockAwal + $masuk - $retur - $totalPakai;
    $sisa       = max(0.0, $sisaRaw);

    return [
        'stockAwal'  => $stockAwal,
        'masuk'      => $masuk,
        'retur'      => $retur,
        'reject'     => $reject,
        'hours24'    => $hours24,
        'totalProd'  => $totalProd,
        'totalPakai' => $totalPakai,
        'sisa'       => $sisa,
    ];
}

// -- Insert satu transaksi lengkap (dipakai submit & revise) ------------------
function insertTransaction(
    PDO    $db,
    string $uuid,
    string $tanggal,
    string $shift,
    string $mesin,
    string $size,
    int    $revCount,
    string $createdBy,
    array  $materials,
    array  $outputs,
    ?array $report
): void {
    // 1. transactions header - get back serial id
    $stmtTxn = $db->prepare(
        "INSERT INTO transactions (uuid, production_date, shift_id, machine_id, product_size,
                                   revision_count, created_by, status)
         VALUES (:uuid, :date, :shift, :mesin, :size, :rev, :by, 'DRAFT')
         RETURNING id"
    );
    $stmtTxn->execute([
        ':uuid'  => $uuid, ':date'  => $tanggal, ':shift' => (int)$shift,
        ':mesin' => $mesin, ':size' => $size, ':rev' => $revCount, ':by' => $createdBy,
    ]);
    $txnId = (int)$stmtTxn->fetchColumn();

    // 2. transaction_materials + material_hourly_usage
    $stmtMat = $db->prepare(
        "INSERT INTO transaction_materials
         (transaction_uuid, transaction_id, material_name, supplier_name, stock_initial, stock_in,
          stock_return, reject_amount, production_amount, total_usage, stock_final, label_photos)
         VALUES (:uuid,:txnid,:name,:sup,:si,:sin,:sr,:rej,:prod,:tot,:fin,:photos)
         RETURNING id"
    );
    $stmtHour = $db->prepare(
        "INSERT INTO material_hourly_usage (transaction_material_id, hour_idx, amount)
         VALUES (:mid, :h, :amt)"
    );

    foreach ($materials as $mat) {
        $c = calcMaterial($mat, $shift);
        $photos = is_array($mat['photos'] ?? null)
            ? implode(',', array_filter($mat['photos']))
            : (string)($mat['photos'] ?? '');

        $stmtMat->execute([
            ':uuid'  => $uuid,
            ':txnid' => $txnId,
            ':name'  => (string)($mat['name']     ?? ''),
            ':sup'   => (string)($mat['supplier'] ?? ''),
            ':si'   => $c['stockAwal'],
            ':sin'  => $c['masuk'],
            ':sr'   => $c['retur'],
            ':rej'  => $c['reject'],
            ':prod' => $c['totalProd'],
            ':tot'  => $c['totalPakai'],
            ':fin'  => $c['sisa'],
            ':photos' => $photos,
        ]);
        $matId = (int)$db->lastInsertId();

        foreach ($c['hours24'] as $hIdx => $amt) {
            if ($amt > 0) {
                $stmtHour->execute([':mid' => $matId, ':h' => $hIdx, ':amt' => $amt]);
            }
        }
    }

    // 3. production_outputs
    if (!empty($outputs)) {
        $stmtOut = $db->prepare(
            "INSERT INTO production_outputs
             (transaction_uuid, production_date, mid, product_name, qty_box,
              category_bag, category_box, counter_pcs, total_weight_kg, loss_kg,
              revision_count, status)
             VALUES (:uuid,:date,:mid,:name,:qty,:catbag,:catbox,:cnt,:kg,:loss,:rev,'ACTIVE')"
        );
        foreach ($outputs as $o) {
            $stmtOut->execute([
                ':uuid'   => $uuid,  ':date'   => $tanggal,
                ':mid'    => (string)($o['mid']    ?? ''),
                ':name'   => (string)($o['name']   ?? ''),
                ':qty'    => (int)($o['qtyBox']    ?? 0),
                ':catbag' => (string)($o['catBag']  ?? ''),
                ':catbox' => (string)($o['catBox']  ?? ''),
                ':cnt'    => (int)($o['counterPcs'] ?? 0),
                ':kg'     => (float)($o['totalKg']  ?? 0),
                ':loss'   => (float)($o['lossKg']   ?? 0),
                ':rev'    => $revCount,
            ]);
        }
    }

    // 4. production_reports
    if ($report !== null) {
        $db->prepare(
            "INSERT INTO production_reports
             (transaction_uuid, production_date, shift_id, machine_id,
              total_output_kg, total_loss_kg, avg_loss_pct, downtime_min, downtime_pct,
              speed_ppm, trouble_logs, near_miss, notes, reject_printing_kg,
              revision_count, status)
             VALUES (:uuid,:date,:shift,:mesin,
                     :kg,:loss,:losspct,:dtmin,:dtpct,
                     :speed,:trouble,:nearmiss,:notes,:rprint,
                     :rev,'ACTIVE')"
        )->execute([
            ':uuid'    => $uuid,     ':date'    => $tanggal,
            ':shift'   => (int)$shift, ':mesin' => $mesin,
            ':kg'      => (float)str_replace(',', '.', (string)($report['counterKg']       ?? 0)),
            ':loss'    => (float)str_replace(',', '.', (string)($report['lossKg']          ?? 0)),
            ':losspct' => (float)rtrim((string)($report['lossPct'] ?? 0), '%'),
            ':dtmin'   => (int)($report['downtimeMin']   ?? 0),
            ':dtpct'   => (float)rtrim((string)($report['downtimePct'] ?? 0), '%'),
            ':speed'   => (int)($report['speed']         ?? 0),
            ':trouble' => (string)($report['trouble']    ?? ''),
            ':nearmiss'=> (string)($report['nearMiss']   ?? ''),
            ':notes'   => (string)($report['notes']      ?? ''),
            ':rprint'  => (float)($report['rejectPrintingKg'] ?? 0),
            ':rev'     => $revCount,
        ]);
    }
}

// -- Action: submit ------------------------------------------------------------
function actionSubmit(): void {
    $db      = getDb();
    $session = requireAuth($db);
    $b       = body();

    $tanggal = trim((string)($b['tanggal'] ?? ''));
    $shift   = trim((string)($b['shift']   ?? ''));
    $mesin   = trim((string)($b['mesin']   ?? ''));
    $size    = trim((string)($b['size']    ?? ''));

    if (!$tanggal || !$shift || !$mesin) fail('tanggal, shift, dan mesin wajib diisi.');
    if (!in_array($shift, ['1','2','3'])) fail('shift tidak valid.');

    $lockDate = getLockDate($db);
    $isAdmin  = ($session['role'] === 'admin');
    if ($lockDate && !$isAdmin && $tanggal <= $lockDate) {
        fail("Data terkunci. Tidak bisa input tanggal <= {$lockDate}");
    }

    // Parse JSON strings
    $materialsJson = (string)($b['materialsJson'] ?? '[]');
    $outputsJson   = (string)($b['outputsJson']   ?? '[]');
    $reportJson    = (string)($b['reportJson']    ?? '');

    if (json_last_error() !== JSON_ERROR_NONE && ($materials = null) === null) {}
    $materials = json_decode($materialsJson, true);
    if (!is_array($materials)) fail('materialsJson tidak valid: ' . json_last_error_msg());

    $outputs = json_decode($outputsJson, true);
    if (!is_array($outputs)) $outputs = [];

    $report = null;
    if ($reportJson !== '' && $reportJson !== '{}') {
        $report = json_decode($reportJson, true);
        if (!is_array($report)) fail('reportJson tidak valid: ' . json_last_error_msg());
        // Gabungkan itemsDetail ke dalam outputs array
        if (!empty($report['itemsDetail'])) {
            foreach ($outputs as &$o) {
                foreach ($report['itemsDetail'] as $d) {
                    if ($d['mid'] === $o['mid']) {
                        $o['counterPcs'] = $d['counterPcs'] ?? 0;
                        $o['totalKg']    = $d['totalKg']    ?? 0;
                        $o['lossKg']     = $d['lossKg']     ?? 0;
                        break;
                    }
                }
            }
            unset($o);
        }
    }

    $uuid = genUuid();
    $db->beginTransaction();
    try {
        insertTransaction($db, $uuid, $tanggal, $shift, $mesin, $size, 0,
            $isAdmin ? 'Admin' : $session['username'],
            $materials, $outputs, $report
        );
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        fail('Gagal menyimpan: ' . $e->getMessage());
    }

    logAction($db, $session['username'], 'SUBMIT', "ID: {$uuid}");
    ok(['uuid' => $uuid, 'count' => count($materials), 'rev' => 0]);
}

// -- Action: revise ------------------------------------------------------------
function actionRevise(): void {
    $db      = getDb();
    $session = requireAuth($db);
    $b       = body();

    $oldUuid = trim((string)($b['uuid']    ?? ''));
    $tanggal = trim((string)($b['tanggal'] ?? ''));
    $shift   = trim((string)($b['shift']   ?? ''));
    $mesin   = trim((string)($b['mesin']   ?? ''));
    $size    = trim((string)($b['size']    ?? ''));

    if (!$oldUuid || !$tanggal || !$shift || !$mesin) fail('uuid, tanggal, shift, mesin wajib diisi.');
    if (!in_array($shift, ['1','2','3'])) fail('shift tidak valid.');

    $lockDate = getLockDate($db);
    $isAdmin  = ($session['role'] === 'admin');
    if ($lockDate && !$isAdmin && $tanggal <= $lockDate) {
        fail("Data terkunci. Tidak bisa edit tanggal <= {$lockDate}");
    }

    // Cek transaksi lama
    $stmtOld = $db->prepare(
        "SELECT created_by, status, revision_count FROM transactions
         WHERE uuid = :u AND status NOT IN ('HISTORY','SUPERSEDED')
         ORDER BY revision_count DESC LIMIT 1"
    );
    $stmtOld->execute([':u' => $oldUuid]);
    $old = $stmtOld->fetch();
    if (!$old) fail('Data lama tidak ditemukan.');

    if (!$isAdmin) {
        if ($old['status'] === 'FINAL') fail('Laporan sudah FINAL. Tidak bisa diedit.');
        if ($old['created_by'] !== $session['username']) fail('Anda tidak bisa mengedit data Grup lain.');
        if ((int)$old['revision_count'] >= 3) fail('Batas revisi habis (Maks 3x).');
    }

    $nextRev = (int)$old['revision_count'] + 1;

    $materialsJson = (string)($b['materialsJson'] ?? '[]');
    $outputsJson   = (string)($b['outputsJson']   ?? '[]');
    $reportJson    = (string)($b['reportJson']    ?? '');

    $materials = json_decode($materialsJson, true);
    if (!is_array($materials)) fail('materialsJson tidak valid: ' . json_last_error_msg());

    $outputs = json_decode($outputsJson, true);
    if (!is_array($outputs)) $outputs = [];

    $report = null;
    if ($reportJson !== '' && $reportJson !== '{}') {
        $report = json_decode($reportJson, true);
        if (!is_array($report)) fail('reportJson tidak valid.');
        if (!empty($report['itemsDetail'])) {
            foreach ($outputs as &$o) {
                foreach ($report['itemsDetail'] as $d) {
                    if ($d['mid'] === $o['mid']) {
                        $o['counterPcs'] = $d['counterPcs'] ?? 0;
                        $o['totalKg']    = $d['totalKg']    ?? 0;
                        $o['lossKg']     = $d['lossKg']     ?? 0;
                        break;
                    }
                }
            }
            unset($o);
        }
    }

    $db->beginTransaction();
    try {
        // Mark old records as HISTORY
        $db->prepare(
            "UPDATE transactions SET status='HISTORY' WHERE uuid=:u AND status NOT IN ('HISTORY','SUPERSEDED')"
        )->execute([':u' => $oldUuid]);
        $db->prepare(
            "UPDATE production_outputs SET status='HISTORY' WHERE transaction_uuid=:u AND status='ACTIVE'"
        )->execute([':u' => $oldUuid]);
        $db->prepare(
            "UPDATE production_reports SET status='HISTORY' WHERE transaction_uuid=:u AND status='ACTIVE'"
        )->execute([':u' => $oldUuid]);

        // Insert new with same UUID
        insertTransaction($db, $oldUuid, $tanggal, $shift, $mesin, $size, $nextRev,
            $isAdmin ? 'Admin' : $session['username'],
            $materials, $outputs, $report
        );
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        fail('Gagal menyimpan revisi: ' . $e->getMessage());
    }

    logAction($db, $session['username'], 'REVISI', "ID: {$oldUuid} rev{$nextRev}");
    ok(['uuid' => $oldUuid, 'count' => count($materials), 'rev' => $nextRev]);
}

// -- Action: finalize ----------------------------------------------------------
function actionFinalize(): void {
    $db      = getDb();
    $session = requireAuth($db);
    $b       = body();

    $uuid    = trim((string)($b['uuid'] ?? ''));
    if (!$uuid) fail('uuid wajib diisi.');

    $isAdmin = ($session['role'] === 'admin');

    if (!$isAdmin) {
        $row = $db->prepare(
            "SELECT created_by FROM transactions WHERE uuid=:u AND status NOT IN ('HISTORY','SUPERSEDED') LIMIT 1"
        );
        $row->execute([':u' => $uuid]);
        $r = $row->fetch();
        if (!$r) fail('Data tidak ditemukan.');
        if ($r['created_by'] !== $session['username']) fail('Anda tidak berhak memfinalisasi data grup lain.');
    }

    $db->prepare(
        "UPDATE transactions SET status='FINAL'
         WHERE uuid=:u AND status NOT IN ('FINAL','HISTORY','SUPERSEDED')"
    )->execute([':u' => $uuid]);

    logAction($db, $session['username'], 'FINALIZE', "ID: {$uuid}");
    ok(true);
}

// -- Action: delete ------------------------------------------------------------
function actionDelete(): void {
    $db      = getDb();
    $session = requireAuth($db);
    $b       = body();

    $id = trim((string)($b['id'] ?? ''));
    if (!$id) fail('id wajib diisi.');

    $db->beginTransaction();
    try {
        $db->prepare("DELETE FROM production_reports WHERE transaction_uuid=:u")->execute([':u' => $id]);
        $db->prepare("DELETE FROM production_outputs  WHERE transaction_uuid=:u")->execute([':u' => $id]);
        // transaction_materials + hourly_usage cascade via FK
        $del = $db->prepare("DELETE FROM transactions WHERE uuid=:u");
        $del->execute([':u' => $id]);
        if ($del->rowCount() === 0) { $db->rollBack(); fail('Data tidak ditemukan.'); }
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        fail('Gagal menghapus: ' . $e->getMessage());
    }

    logAction($db, $session['username'], 'DELETE', "ID: {$id}");
    ok(true);
}

// -- Action: previousStock -----------------------------------------------------
function actionPreviousStock(): void {
    $db      = getDb();
    requireAuth($db);

    $mesin  = trim((string) param('mesin',  ''));
    $shift  = trim((string) param('shift',  ''));
    $date   = trim((string) param('date',   ''));

    if (!$mesin || !$shift || !$date) fail('mesin, shift, date wajib diisi.');

    // Tentukan shift & tanggal sebelumnya
    $prevShift = '';
    $prevDate  = $date;
    if ($shift === '1') {
        $prevShift = '3';
        $prevDate  = date('Y-m-d', strtotime($date . ' -1 day'));
    } elseif ($shift === '2') {
        $prevShift = '1';
    } else {
        $prevShift = '2';
    }

    $stmt = $db->prepare(
        "SELECT tm.material_name, tm.stock_final
         FROM transactions t
         JOIN transaction_materials tm ON tm.transaction_id = t.id
         WHERE t.production_date = :date
           AND t.shift_id = :shift
           AND t.machine_id = :mesin
           AND t.status NOT IN ('HISTORY','SUPERSEDED')
         ORDER BY t.revision_count DESC, tm.id"
    );
    $stmt->execute([':date' => $prevDate, ':shift' => (int)$prevShift, ':mesin' => $mesin]);

    $result = [];
    foreach ($stmt->fetchAll() as $row) {
        // Ambil nilai tertinggi revisinya (first hit karena ORDER BY rev DESC)
        if (!isset($result[$row['material_name']])) {
            $result[$row['material_name']] = (float)$row['stock_final'];
        }
    }

    ok($result);
}

// -- Action: diff --------------------------------------------------------------
function actionDiff(): void {
    $db   = getDb();
    requireAuth($db);
    $uuid = trim((string) param('uuid', ''));
    if (!$uuid) fail('uuid wajib diisi.');

    // Materials per revision - join via transaction_id
    $mRows = $db->prepare(
        "SELECT t.revision_count, t.created_at,
                tm.material_name, tm.production_amount
         FROM transactions t
         JOIN transaction_materials tm ON tm.transaction_id = t.id
         WHERE t.uuid = :u
         ORDER BY t.revision_count, tm.material_name"
    );
    $mRows->execute([':u' => $uuid]);

    // Outputs per revision
    $oRows = $db->prepare(
        "SELECT revision_count, product_name AS name, qty_box AS qty
         FROM production_outputs WHERE transaction_uuid = :u"
    );
    $oRows->execute([':u' => $uuid]);

    // Reports per revision
    $rRows = $db->prepare(
        "SELECT revision_count, total_loss_kg AS lossKg, avg_loss_pct AS lossPct,
                downtime_min AS downMin, speed_ppm AS speed, reject_printing_kg AS rejectPrint
         FROM production_reports WHERE transaction_uuid = :u"
    );
    $rRows->execute([':u' => $uuid]);

    $versions = [];
    foreach ($mRows->fetchAll() as $r) {
        $rev = (int)$r['revision_count'];
        if (!isset($versions[$rev])) {
            $versions[$rev] = ['items' => [], 'outputs' => [], 'report' => [], 'totalMat' => 0, 'time' => $r['created_at']];
        }
        $versions[$rev]['items'][]    = ['mat' => $r['material_name'], 'total' => (float)$r['production_amount']];
        $versions[$rev]['totalMat'] += (float)$r['production_amount'];
    }
    foreach ($oRows->fetchAll() as $r) {
        $rev = (int)$r['revision_count'];
        if (isset($versions[$rev])) $versions[$rev]['outputs'][] = ['name' => $r['name'], 'qty' => $r['qty']];
    }
    foreach ($rRows->fetchAll() as $r) {
        $rev = (int)$r['revision_count'];
        if (isset($versions[$rev])) $versions[$rev]['report'] = $r;
    }

    ok($versions);
}

// -- Router --------------------------------------------------------------------
$action = trim((string)($_GET['action'] ?? param('action', '')));

match($action) {
    'submit'        => actionSubmit(),
    'revise'        => actionRevise(),
    'finalize'      => actionFinalize(),
    'delete'        => actionDelete(),
    'previousStock' => actionPreviousStock(),
    'diff'          => actionDiff(),
    default         => (function() use ($action) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => "Action '{$action}' tidak dikenal."]);
        exit;
    })(),
};
