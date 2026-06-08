<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/database.php';

function jsonOut(mixed $data) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data);
    exit;
}

$action = $_GET['action'] ?? '';

// =========================================================================
// 1. UPLOAD EXCEL
// =========================================================================
if ($action === 'upload') {
    if (!isset($_FILES['file'])) jsonOut(['ok' => false, 'error' => 'No file uploaded']);
    $tmpName = $_FILES['file']['tmp_name'];
    $targetPath = dirname(__DIR__, 2) . '/data_lama.xlsx'; // Root folder, as python expects
    
    if (move_uploaded_file($tmpName, $targetPath)) {
        jsonOut(['ok' => true, 'message' => 'File berhasil diupload.']);
    } else {
        jsonOut(['ok' => false, 'error' => 'Gagal memindahkan file upload.']);
    }
}

// =========================================================================
// 2. PROGRESS POLLING
// =========================================================================
if ($action === 'progress') {
    $progressFile = dirname(__DIR__, 2) . '/python_bot/migration_progress.json';
    if (!file_exists($progressFile)) {
        jsonOut(['ok' => true, 'status' => 'waiting', 'message' => 'Waiting for script to start...']);
    }
    $content = file_get_contents($progressFile);
    $data = json_decode($content, true);
    if (!$data) {
        jsonOut(['ok' => true, 'status' => 'waiting', 'message' => 'Invalid JSON']);
    }
    jsonOut(['ok' => true, 'data' => $data]);
}

if ($action === 'reset_progress') {
    $progressFile = dirname(__DIR__, 2) . '/python_bot/migration_progress.json';
    if (file_exists($progressFile)) unlink($progressFile);
    jsonOut(['ok' => true]);
}

// =========================================================================
// 3. RUN PYTHON SCRIPTS (Blocking - Caller must call asynchronously)
// =========================================================================
if ($action === 'run_import') {
    $script = dirname(__DIR__, 2) . '/python_bot/import_excel.py';
    $cmd = escapeshellcmd("python \"$script\"");
    exec($cmd . ' 2>&1', $output, $return_var);
    
    jsonOut([
        'ok' => $return_var === 0,
        'return_code' => $return_var,
        'output' => $output
    ]);
}

if ($action === 'run_setup_photos') {
    $script = dirname(__DIR__, 2) . '/python_bot/setup_photo_migration.py';
    $cmd = escapeshellcmd("python \"$script\"");
    exec($cmd . ' 2>&1', $output, $return_var);
    
    jsonOut([
        'ok' => $return_var === 0,
        'return_code' => $return_var,
        'output' => $output
    ]);
}

// =========================================================================
// 4. PHOTO MIGRATION DOWNLOAD LOGIC (Copied from migrate_photos.php)
// =========================================================================
define('UPLOAD_BASE', dirname(__DIR__) . '/uploads/labels/migrated/');
define('UPLOAD_URL',  'migrated/');
define('BATCH_SIZE',  50);
define('DL_URL', 'https://drive.google.com/uc?export=download&id=');

function downloadFile(string $gdriveId): array {
    $url = DL_URL . urlencode($gdriveId);
    $ch  = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_USERAGENT      => 'Mozilla/5.0',
        CURLOPT_COOKIEFILE     => '',
        CURLOPT_COOKIEJAR      => '',
        CURLOPT_HTTPHEADER     => ['Accept: image/*,*/*'],
    ]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $type = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);

    if ($code !== 200 || !$body) {
        return ['ok' => false, 'error' => "HTTP $code"];
    }

    if (str_contains($type, 'text/html') && str_contains((string)$body, 'confirm=')) {
        preg_match('/confirm=([^&"\']+)/', (string)$body, $m);
        $confirm = $m[1] ?? 't';
        $url2 = $url . '&confirm=' . $confirm;
        $ch2  = curl_init($url2);
        curl_setopt_array($ch2, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_USERAGENT      => 'Mozilla/5.0',
        ]);
        $body = curl_exec($ch2);
        $code = (int)curl_getinfo($ch2, CURLINFO_HTTP_CODE);
        $type = (string)curl_getinfo($ch2, CURLINFO_CONTENT_TYPE);
        curl_close($ch2);

        if ($code !== 200 || !$body || str_contains($type, 'text/html')) {
            return ['ok' => false, 'error' => 'File terlalu besar atau tidak public'];
        }
    }

    $ext = 'jpg';
    if (str_contains($type, 'png'))  $ext = 'png';
    elseif (str_contains($type, 'gif'))  $ext = 'gif';
    elseif (str_contains($type, 'webp')) $ext = 'webp';
    elseif (str_contains($type, 'pdf'))  $ext = 'pdf';

    return ['ok' => true, 'data' => $body, 'ext' => $ext, 'mime' => $type, 'size' => strlen((string)$body)];
}

if ($action === 'photo_stats') {
    $db = getDb();
    try {
        $rows = $db->query("SELECT status, COUNT(*) AS n FROM photo_migration GROUP BY status")->fetchAll();
        $s = [];
        foreach ($rows as $r) $s[$r['status']] = (int)$r['n'];
        jsonOut(['ok' => true, 'stats' => $s, 'total' => array_sum($s)]);
    } catch (Exception $e) {
        jsonOut(['ok' => false, 'error' => $e->getMessage()]);
    }
}

if ($action === 'photo_retry') {
    $db = getDb();
    $n = $db->exec("UPDATE photo_migration SET status='PENDING', attempts=0, error_msg=NULL, updated_at=NOW() WHERE status IN ('FAILED','SKIPPED')");
    jsonOut(['ok' => true, 'reset' => $n]);
}

if ($action === 'photo_batch') {
    if (!is_dir(UPLOAD_BASE)) mkdir(UPLOAD_BASE, 0755, true);
    $db = getDb();
    $stmt = $db->prepare(
        "SELECT id, gdrive_id FROM photo_migration
         WHERE status = 'PENDING'
         ORDER BY id LIMIT " . BATCH_SIZE
    );
    $stmt->execute();
    $rows = $stmt->fetchAll();

    if (empty($rows)) {
        $db->exec("
            UPDATE transaction_materials tm
            SET label_photos = sub.paths
            FROM (
                SELECT pmm.tm_id,
                       STRING_AGG(pm.local_path, ',' ORDER BY pmm.position) AS paths
                FROM photo_migration_map pmm
                JOIN photo_migration pm ON pm.gdrive_id = pmm.gdrive_id
                WHERE pm.status = 'DONE'
                GROUP BY pmm.tm_id
            ) sub
            WHERE tm.id = sub.tm_id
        ");
        jsonOut(['ok' => true, 'done' => true]);
    }

    $ok = $fail = 0;
    foreach ($rows as $row) {
        $gid  = $row['gdrive_id'];
        $pmId = (int)$row['id'];
        $existing = glob(UPLOAD_BASE . $gid . '.*');
        if ($existing) {
            $fname     = basename($existing[0]);
            $localPath = UPLOAD_URL . $fname;
            $db->prepare("UPDATE photo_migration SET status='DONE', local_path=:p, updated_at=NOW() WHERE id=:id")
               ->execute([':p' => $localPath, ':id' => $pmId]);
            $ok++;
            continue;
        }

        $result = downloadFile($gid);
        if (!$result['ok']) {
            $db->prepare("UPDATE photo_migration SET status='FAILED', error_msg=:e, attempts=attempts+1, updated_at=NOW() WHERE id=:id")
               ->execute([':e' => $result['error'], ':id' => $pmId]);
            $fail++;
            continue;
        }

        $fname     = $gid . '.' . $result['ext'];
        $fpath     = UPLOAD_BASE . $fname;
        $localPath = UPLOAD_URL . $fname;
        file_put_contents($fpath, $result['data']);
        $db->prepare("UPDATE photo_migration SET status='DONE', local_path=:p, mime_type=:m, file_size=:s, updated_at=NOW() WHERE id=:id")
           ->execute([':p' => $localPath, ':m' => $result['mime'], ':s' => $result['size'], ':id' => $pmId]);
        $ok++;
    }

    $remaining = (int)$db->query("SELECT COUNT(*) FROM photo_migration WHERE status='PENDING'")->fetchColumn();
    jsonOut(['ok' => true, 'done' => false, 'downloaded' => $ok, 'failed' => $fail, 'remaining' => $remaining]);
}

jsonOut(['ok' => false, 'error' => 'Unknown action']);
