<?php
/**
 * migrate_photos.php — Migrasi foto public Google Drive → lokal.
 * Foto bersifat public → tidak perlu OAuth, langsung download via URL publik.
 *
 * PRASYARAT:
 *   python seeds/setup_photo_migration.py   (populate tabel photo_migration)
 *
 * Akses: http://localhost/ProdAdmin/tools/migrate_photos.php
 */

declare(strict_types=1);
ini_set('display_errors', '1');
error_reporting(E_ALL);
set_time_limit(0);

require_once __DIR__ . '/../config/database.php';

define('UPLOAD_BASE', dirname(__DIR__) . '/uploads/labels/migrated/');
define('UPLOAD_URL',  'migrated/');
define('BATCH_SIZE',  50);

// URL download langsung (tanpa auth)
// Untuk file publik Google Drive, ini bekerja tanpa API key / OAuth
define('DL_URL', 'https://drive.google.com/uc?export=download&id=');

if (!is_dir(UPLOAD_BASE)) mkdir(UPLOAD_BASE, 0755, true);

$db = getDb();

// ── Helper ────────────────────────────────────────────────────────────────────

function jsonOut(mixed $d): void {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($d);
    exit;
}

function downloadFile(string $gdriveId): array {
    $url = DL_URL . urlencode($gdriveId);
    $ch  = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_USERAGENT      => 'Mozilla/5.0',
        CURLOPT_COOKIEFILE     => '',   // enable cookie engine
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

    // Deteksi apakah response adalah halaman HTML virus-scan warning
    // (terjadi untuk file > ~25MB)
    if (str_contains($type, 'text/html') && str_contains((string)$body, 'confirm=')) {
        // Parse confirm token dan retry
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

    // Tentukan ekstensi dari Content-Type
    $ext = 'jpg';
    if (str_contains($type, 'png'))  $ext = 'png';
    elseif (str_contains($type, 'gif'))  $ext = 'gif';
    elseif (str_contains($type, 'webp')) $ext = 'webp';
    elseif (str_contains($type, 'pdf'))  $ext = 'pdf';

    return ['ok' => true, 'data' => $body, 'ext' => $ext, 'mime' => $type, 'size' => strlen((string)$body)];
}

// ── AJAX: stats ───────────────────────────────────────────────────────────────
if (isset($_GET['action']) && $_GET['action'] === 'stats') {
    $rows = $db->query("SELECT status, COUNT(*) AS n FROM photo_migration GROUP BY status")->fetchAll();
    $s = [];
    foreach ($rows as $r) $s[$r['status']] = (int)$r['n'];
    jsonOut(['stats' => $s, 'total' => array_sum($s)]);
}

// ── AJAX: retry failed ────────────────────────────────────────────────────────
if (isset($_GET['action']) && $_GET['action'] === 'retry') {
    $n = $db->exec("UPDATE photo_migration SET status='PENDING', attempts=0, error_msg=NULL, updated_at=NOW() WHERE status IN ('FAILED','SKIPPED')");
    jsonOut(['ok' => true, 'reset' => $n]);
}

// ── AJAX: process one batch ───────────────────────────────────────────────────
if (isset($_GET['action']) && $_GET['action'] === 'batch') {
    $stmt = $db->prepare(
        "SELECT id, gdrive_id FROM photo_migration
         WHERE status = 'PENDING'
         ORDER BY id LIMIT " . BATCH_SIZE
    );
    $stmt->execute();
    $rows = $stmt->fetchAll();

    if (empty($rows)) {
        // Semua selesai — update label_photos di DB
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

    $ok = $fail = $skip = 0;

    foreach ($rows as $row) {
        $gid  = $row['gdrive_id'];
        $pmId = (int)$row['id'];

        // Cek jika file sudah ada di disk (bisa terjadi kalau sebelumnya partial)
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

// ── AJAX: quick test (coba download 1 foto) ───────────────────────────────────
if (isset($_GET['action']) && $_GET['action'] === 'test') {
    $row = $db->query("SELECT gdrive_id FROM photo_migration WHERE status='PENDING' LIMIT 1")->fetch();
    if (!$row) jsonOut(['ok' => false, 'message' => 'Tidak ada data PENDING']);

    $gid    = $row['gdrive_id'];
    $result = downloadFile($gid);
    jsonOut([
        'ok'      => $result['ok'],
        'gdrive'  => $gid,
        'mime'    => $result['mime']    ?? null,
        'size_kb' => isset($result['size']) ? round($result['size']/1024, 1) : null,
        'error'   => $result['error']  ?? null,
        'message' => $result['ok']
            ? "✅ Berhasil download {$gid} ({$result['size']} bytes, {$result['mime']})"
            : "❌ Gagal: {$result['error']}",
    ]);
}

// ── Halaman utama ─────────────────────────────────────────────────────────────
$tableExists = false;
$total = $done = $pending = $failed = 0;
try {
    $db->query("SELECT 1 FROM photo_migration LIMIT 1");
    $tableExists = true;
    $rows2 = $db->query("SELECT status, COUNT(*) AS n FROM photo_migration GROUP BY status")->fetchAll();
    foreach ($rows2 as $r) {
        $total += (int)$r['n'];
        if ($r['status'] === 'DONE')    $done    = (int)$r['n'];
        if ($r['status'] === 'PENDING') $pending = (int)$r['n'];
        if ($r['status'] === 'FAILED')  $failed  = (int)$r['n'];
    }
} catch (\Exception $e) {}

$pct = $total > 0 ? round($done / $total * 100, 1) : 0;

?><!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Migrasi Foto GDrive</title>
<link rel="stylesheet" href="../assets/bootstrap.min.css">
<style>
body { background:#f0f2f5; }
.card { border-radius:12px; border:none; box-shadow:0 2px 12px rgba(0,0,0,.08); }
.log-box { background:#1a1a2e; color:#00ff88; font-family:monospace; font-size:.78rem;
           height:240px; overflow-y:auto; padding:10px; border-radius:8px; }
</style>
</head>
<body>
<div class="container py-4" style="max-width:740px">

  <h4 class="fw-bold mb-1">📸 Migrasi Foto Google Drive → Lokal</h4>
  <p class="text-muted small mb-4">
    Foto bersifat <strong>public</strong> — download langsung tanpa OAuth / Google Cloud Console.
  </p>

  <?php if (!$tableExists): ?>
  <!-- Belum setup -->
  <div class="card mb-3">
    <div class="card-body">
      <h6 class="fw-bold text-danger">⚠️ Tabel migrasi belum dibuat</h6>
      <p class="small mb-2">Jalankan perintah ini di terminal dulu:</p>
      <div class="log-box" style="height:auto;padding:8px 12px">
        cd "C:\Users\imann\SynologyDrive\APP SCRIPT\0. PROD ADMIN 3"<br>
        python seeds/setup_photo_migration.py
      </div>
      <p class="small text-muted mt-2 mb-0">Script ini membaca <code>data_lama.xlsx</code> dan mendaftarkan semua GDrive IDs (~73 ribu) ke database. Estimasi: 2–5 menit.</p>
    </div>
  </div>

  <?php else: ?>

  <!-- Step 1: Test koneksi -->
  <div class="card mb-3">
    <div class="card-body">
      <h6 class="fw-bold mb-2">🔍 Test Koneksi</h6>
      <p class="small text-muted mb-2">Pastikan foto bisa didownload sebelum mulai batch besar.</p>
      <button class="btn btn-sm btn-outline-primary" onclick="testOne()">Coba Download 1 Foto</button>
      <div id="testResult" class="small mt-2"></div>
    </div>
  </div>

  <!-- Step 2: Progress & kontrol -->
  <div class="card mb-3">
    <div class="card-body">
      <h6 class="fw-bold mb-3">📥 Download Foto</h6>

      <div class="mb-3">
        <div class="d-flex justify-content-between small text-muted mb-1">
          <span>Progress: <strong id="doneCount"><?= number_format($done) ?></strong> / <?= number_format($total) ?> file</span>
          <span><strong id="pctVal"><?= $pct ?>%</strong></span>
        </div>
        <div class="progress" style="height:18px;border-radius:8px;">
          <div id="progressBar" class="progress-bar progress-bar-striped progress-bar-animated bg-success"
               style="width:<?= $pct ?>%" role="progressbar"></div>
        </div>
        <div class="d-flex gap-3 small text-muted mt-1 flex-wrap">
          <span>✅ <span id="cntDone"><?= number_format($done) ?></span></span>
          <span>⏳ <span id="cntPending"><?= number_format($pending) ?></span> tersisa</span>
          <span>❌ <span id="cntFailed"><?= number_format($failed) ?></span> gagal</span>
          <span class="ms-auto" id="speedLabel"></span>
        </div>
      </div>

      <div class="log-box mb-3" id="logBox">Klik Mulai untuk memulai...</div>

      <div class="d-flex gap-2 flex-wrap align-items-center">
        <?php if ($pending > 0): ?>
        <button id="btnStart" class="btn btn-success fw-bold" onclick="startMigration()">▶ Mulai Download</button>
        <button id="btnPause" class="btn btn-warning fw-bold d-none"        onclick="pauseMigration()">⏸ Pause</button>
        <?php endif; ?>

        <?php if ($failed > 0): ?>
        <button class="btn btn-outline-warning" onclick="retryFailed()">🔄 Retry Gagal (<?= $failed ?>)</button>
        <?php endif; ?>

        <?php if ($pending === 0 && $done > 0): ?>
        <span class="badge bg-success fs-6">🎉 Semua selesai!</span>
        <?php endif; ?>
      </div>

      <?php if ($pending === 0 && $done > 0): ?>
      <div class="alert alert-success mt-3 mb-0 small">
        ✅ Semua <strong><?= number_format($done) ?></strong> foto berhasil didownload ke
        <code>uploads/labels/migrated/</code> dan <code>label_photos</code> di database
        sudah diupdate dengan path lokal.
      </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- Info batch size -->
  <div class="card mb-3 border-0 bg-light">
    <div class="card-body py-2 px-3">
      <p class="small text-muted mb-1"><strong>Info:</strong> Download <?= BATCH_SIZE ?> file per batch.
        Estimasi total waktu untuk <?= number_format($total) ?> file:
        <strong>~<?= number_format($total / BATCH_SIZE / 60 * (BATCH_SIZE * 1.5 / 60), 1) ?> jam</strong>
        (tergantung kecepatan internet).</p>
      <p class="small text-muted mb-0">Bisa ditinggal — jika browser ditutup lalu dibuka lagi, klik
        <em>Mulai</em> dan akan lanjut dari file terakhir yang belum didownload.</p>
    </div>
  </div>

  <?php endif; ?>

</div>

<script src="../assets/bootstrap.bundle.min.js"></script>
<script>
let running   = false;
let startTime = null;
let doneAtStart = 0;

function log(msg, color = '#00ff88') {
    const b  = document.getElementById('logBox');
    if (!b) return;
    const ts = new Date().toLocaleTimeString('id-ID');
    b.innerHTML += `<div style="color:${color}">[${ts}] ${msg}</div>`;
    b.scrollTop  = b.scrollHeight;
}

async function testOne() {
    const el = document.getElementById('testResult');
    el.textContent = '⏳ Mencoba...';
    const r = await fetch('?action=test').then(x => x.json());
    el.innerHTML = r.ok
        ? `<span class="text-success">${r.message}</span>`
        : `<span class="text-danger">${r.message}</span><br><small class="text-muted">Kemungkinan foto tidak public atau ID tidak valid.</small>`;
}

async function startMigration() {
    if (running) return;
    running     = true;
    startTime   = Date.now();
    doneAtStart = parseInt(document.getElementById('cntDone').textContent.replace(/\D/g,'')) || 0;

    document.getElementById('btnStart')?.classList.add('d-none');
    document.getElementById('btnPause')?.classList.remove('d-none');
    log('Mulai download batch...');
    await runBatch();
}

function pauseMigration() {
    running = false;
    document.getElementById('btnPause')?.classList.add('d-none');
    document.getElementById('btnStart')?.classList.remove('d-none');
    log('⏸ Dijeda.', '#ffcc00');
}

async function runBatch() {
    if (!running) return;
    try {
        const r = await fetch('?action=batch').then(x => x.json());

        if (!r.ok) {
            log('❌ Error: ' + (r.message || 'unknown'), '#ff4444');
            running = false;
            return;
        }

        if (r.done) {
            log('🎉 SELESAI! Semua foto didownload dan database diupdate.', '#00ff88');
            running = false;
            document.getElementById('btnPause')?.classList.add('d-none');
            updateStats();
            return;
        }

        log(`✅ +${r.downloaded} OK  ❌ ${r.failed} gagal  ⏳ ${r.remaining} tersisa`);
        updateStats();
        setTimeout(runBatch, 200);

    } catch (e) {
        log('⚠️ Koneksi error, coba lagi 3 detik...', '#ffcc00');
        setTimeout(runBatch, 3000);
    }
}

async function retryFailed() {
    const r = await fetch('?action=retry').then(x => x.json());
    log(`🔄 Reset ${r.reset} file gagal → PENDING`, '#ffcc00');
    updateStats();
}

function updateStats() {
    fetch('?action=stats').then(x => x.json()).then(d => {
        const total   = d.total || 1;
        const done    = d.stats['DONE']    || 0;
        const pending = d.stats['PENDING'] || 0;
        const failed  = d.stats['FAILED']  || 0;
        const pct     = Math.round(done / total * 1000) / 10;

        document.getElementById('progressBar').style.width = pct + '%';
        document.getElementById('doneCount').textContent   = done.toLocaleString('id-ID');
        document.getElementById('pctVal').textContent      = pct + '%';
        document.getElementById('cntDone').textContent     = done.toLocaleString('id-ID');
        document.getElementById('cntPending').textContent  = pending.toLocaleString('id-ID');
        document.getElementById('cntFailed').textContent   = failed.toLocaleString('id-ID');

        // Hitung kecepatan
        if (startTime && doneAtStart !== null) {
            const elapsed = (Date.now() - startTime) / 1000;
            const dlCount = done - doneAtStart;
            if (elapsed > 5 && dlCount > 0) {
                const speed = (dlCount / elapsed).toFixed(1);
                const etaSec = Math.round(pending / (dlCount / elapsed));
                const etaStr = etaSec > 3600
                    ? Math.round(etaSec/3600) + ' jam'
                    : etaSec > 60
                        ? Math.round(etaSec/60) + ' menit'
                        : etaSec + ' detik';
                const sp = document.getElementById('speedLabel');
                if (sp) sp.textContent = `🚀 ${speed} file/s · ETA ${etaStr}`;
            }
        }
    });
}

setInterval(() => { if (running) updateStats(); }, 5000);
</script>
</body>
</html>
