<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/database.php';
$db = getDb();

// Quick check if tables exist to determine if migration can be run
$tablesReady = false;
try {
    $db->query("SELECT 1 FROM transactions LIMIT 1");
    $tablesReady = true;
} catch (Exception $e) {}

?><!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Unified Migration Wizard</title>
<link rel="stylesheet" href="../assets/bootstrap.min.css">
<style>
body { background:#f0f2f5; }
.card { border-radius:12px; border:none; box-shadow:0 2px 12px rgba(0,0,0,.08); }
.log-box { background:#1a1a2e; color:#00ff88; font-family:monospace; font-size:.78rem;
           height:240px; overflow-y:auto; padding:10px; border-radius:8px; }
.step-card { border-left: 4px solid #dee2e6; transition: 0.3s; }
.step-card.active { border-left-color: #0d6efd; background-color: #f8f9fa; }
.step-card.done { border-left-color: #198754; opacity: 0.8; }
</style>
</head>
<body>
<div class="container py-4" style="max-width:800px">

  <h4 class="fw-bold mb-1">🚀 Unified Migration Wizard</h4>
  <p class="text-muted small mb-4">
    Migrasi data lama (Excel) ke PostgreSQL dan download foto dari Google Drive.
  </p>

  <?php if (!$tablesReady): ?>
  <div class="alert alert-danger">
      <strong>⚠️ Database belum siap!</strong> Anda harus menjalankan <code>setup_fresh.sql</code> di pgAdmin/DBeaver terlebih dahulu untuk membuat struktur tabel yang diperlukan.
  </div>
  <?php else: ?>

  <!-- Step 1: Upload Excel -->
  <div class="card mb-3 step-card active" id="stepUpload">
    <div class="card-body">
      <h6 class="fw-bold mb-3">1. Upload File Excel (data_lama.xlsx)</h6>
      <div class="d-flex gap-3 align-items-center">
          <input type="file" id="excelFile" class="form-control form-control-sm" accept=".xlsx" style="max-width:300px">
          <button class="btn btn-sm btn-primary" onclick="uploadExcel()">Upload Excel</button>
      </div>
      <div id="uploadResult" class="small mt-2 text-success fw-bold d-none">✅ File Excel siap.</div>
    </div>
  </div>

  <!-- Step 2: Choose Migration Type -->
  <div class="card mb-3 step-card" id="stepAction" style="opacity: 0.5; pointer-events: none;">
    <div class="card-body">
      <h6 class="fw-bold mb-3">2. Pilih Tindakan</h6>
      <div class="d-flex gap-2 flex-wrap">
          <button class="btn btn-primary" onclick="startFlow('excel_only')">Migrasi Excel Saja</button>
          <button class="btn btn-success" onclick="startFlow('all')">Migrasi Excel & Foto</button>
          <button class="btn btn-outline-secondary" onclick="startFlow('photo_only')">Migrasi Foto Saja</button>
      </div>
    </div>
  </div>

  <!-- Step 3: Progress Dashboard -->
  <div class="card mb-3 step-card d-none" id="stepProgress">
    <div class="card-body">
      <h6 class="fw-bold mb-3">3. Progress Migrasi</h6>

      <!-- Sub-step: Excel Parsing -->
      <div id="progExcel" class="mb-3 d-none">
          <div class="d-flex justify-content-between small text-muted mb-1">
              <span class="fw-bold text-primary">📊 Parsing Excel: <span id="excelStepName">Menunggu...</span></span>
              <span><span id="excelCur">0</span> / <span id="excelTot">0</span></span>
          </div>
          <div class="progress" style="height:12px;border-radius:6px;">
              <div id="excelBar" class="progress-bar progress-bar-striped progress-bar-animated bg-primary" style="width:0%"></div>
          </div>
      </div>

      <!-- Sub-step: Photo Download -->
      <div id="progPhoto" class="mb-3 d-none">
          <div class="d-flex justify-content-between small text-muted mb-1">
              <span class="fw-bold text-success">📸 Download Foto: <span id="photoStepName">Menunggu...</span></span>
              <span><span id="photoCur">0</span> / <span id="photoTot">0</span> (<span id="photoPct">0%</span>)</span>
          </div>
          <div class="progress" style="height:12px;border-radius:6px;">
              <div id="photoBar" class="progress-bar progress-bar-striped progress-bar-animated bg-success" style="width:0%"></div>
          </div>
          <div class="d-flex gap-3 small text-muted mt-1">
              <span>✅ <span id="photoOk">0</span></span>
              <span>❌ <span id="photoFail">0</span></span>
          </div>
      </div>

      <div class="log-box mb-3" id="logBox">Sistem siap...</div>
      
      <div id="doneAlert" class="alert alert-success small mb-0 d-none">
          🎉 <strong>Semua proses migrasi telah selesai!</strong> Anda bisa menutup halaman ini.
      </div>
    </div>
  </div>

  <?php endif; ?>

</div>

<script>
let pollingInterval = null;
let currentFlow = null; // 'excel_only', 'all', 'photo_only'
let photoPollingInterval = null;
let photoTotal = 0;

function log(msg, color = '#00ff88') {
    const b = document.getElementById('logBox');
    if (!b) return;
    const ts = new Date().toLocaleTimeString('id-ID');
    b.innerHTML += `<div style="color:${color}">[${ts}] ${msg}</div>`;
    b.scrollTop = b.scrollHeight;
}

async function uploadExcel() {
    const fileInput = document.getElementById('excelFile');
    if (!fileInput.files[0]) {
        alert("Pilih file data_lama.xlsx terlebih dahulu!");
        return;
    }
    
    const formData = new FormData();
    formData.append("file", fileInput.files[0]);
    
    log("Mengupload file...", "#fff");
    try {
        const res = await fetch('../api/migration_api.php?action=upload', { method: 'POST', body: formData }).then(r=>r.json());
        if(res.ok) {
            document.getElementById('uploadResult').classList.remove('d-none');
            document.getElementById('stepUpload').classList.remove('active');
            document.getElementById('stepUpload').classList.add('done');
            
            document.getElementById('stepAction').style.opacity = '1';
            document.getElementById('stepAction').style.pointerEvents = 'auto';
            document.getElementById('stepAction').classList.add('active');
            log("Upload berhasil. Menunggu pilihan tindakan...", "#00ff88");
        } else {
            alert(res.error);
            log("Upload gagal: " + res.error, "#ff4444");
        }
    } catch(e) {
        alert("Error upload: " + e);
        log("Error network upload.", "#ff4444");
    }
}

function startFlow(type) {
    currentFlow = type;
    document.getElementById('stepAction').classList.remove('active');
    document.getElementById('stepAction').classList.add('done');
    
    document.getElementById('stepProgress').classList.remove('d-none');
    document.getElementById('stepProgress').classList.add('active');
    
    if (type === 'excel_only' || type === 'all') {
        document.getElementById('progExcel').classList.remove('d-none');
        runExcelPhase();
    } else if (type === 'photo_only') {
        document.getElementById('progPhoto').classList.remove('d-none');
        runSetupPhotoPhase();
    }
}

// ==========================================
// EXCEL PHASE
// ==========================================
async function runExcelPhase() {
    log("Memulai migrasi Excel (import_excel.py)...", "#fff");
    
    // Reset progress file
    await fetch('../api/migration_api.php?action=reset_progress');
    
    // Start Polling
    pollingInterval = setInterval(pollProgress, 1000);
    
    // Start Background Python
    fetch('../api/migration_api.php?action=run_import').then(r=>r.json()).then(res => {
        clearInterval(pollingInterval);
        if(res.ok) {
            log("Python import_excel.py selesai dengan sukses.", "#00ff88");
            setExcelBar(100, 100, "Selesai");
            if(currentFlow === 'all') {
                document.getElementById('progPhoto').classList.remove('d-none');
                runSetupPhotoPhase();
            } else {
                finishAll();
            }
        } else {
            log("Error pada import_excel.py: " + res.output.join('\n'), "#ff4444");
        }
    }).catch(e => {
        clearInterval(pollingInterval);
        log("Gagal memanggil API run_import", "#ff4444");
    });
}

function pollProgress() {
    fetch('../api/migration_api.php?action=progress').then(r=>r.json()).then(res => {
        if(res.ok && res.data) {
            const d = res.data;
            if(d.script === 'import_excel' || d.script === 'setup_photo_migration') {
                document.getElementById('excelStepName').textContent = d.message || d.step;
                setExcelBar(d.current, d.total, d.message);
                
                // Cuma log kalau step ganti
                if(window.lastStep !== d.step) {
                    log(d.message, "#00ff88");
                    window.lastStep = d.step;
                }
            }
        }
    }).catch(e => {});
}

function setExcelBar(cur, tot, msg) {
    document.getElementById('excelCur').textContent = cur;
    document.getElementById('excelTot').textContent = tot;
    let pct = tot > 0 ? (cur/tot)*100 : 0;
    document.getElementById('excelBar').style.width = pct + '%';
}

// ==========================================
// PHOTO SETUP PHASE (setup_photo_migration.py)
// ==========================================
async function runSetupPhotoPhase() {
    log("Memulai persiapan migrasi foto (setup_photo_migration.py)...", "#fff");
    await fetch('../api/migration_api.php?action=reset_progress');
    
    window.lastStep = null;
    pollingInterval = setInterval(pollProgress, 1000);
    
    fetch('../api/migration_api.php?action=run_setup_photos').then(r=>r.json()).then(res => {
        clearInterval(pollingInterval);
        if(res.ok) {
            log("Setup mapping foto selesai.", "#00ff88");
            setExcelBar(100, 100, "Setup Foto Selesai");
            runPhotoDownloadPhase();
        } else {
            log("Error pada setup_photo_migration.py: " + res.output.join('\n'), "#ff4444");
        }
    }).catch(e => {
        clearInterval(pollingInterval);
        log("Gagal memanggil API run_setup_photos", "#ff4444");
    });
}

// ==========================================
// PHOTO DOWNLOAD PHASE (AJAX Batching)
// ==========================================
async function runPhotoDownloadPhase() {
    log("Memulai download foto dari Google Drive...", "#fff");
    
    // Ambil stat awal
    const st = await fetch('../api/migration_api.php?action=photo_stats').then(r=>r.json());
    if(!st.ok) {
        log("Gagal ambil stat foto.", "#ff4444");
        return;
    }
    
    photoTotal = st.total || 0;
    if(photoTotal === 0) {
        log("Tidak ada foto yang perlu didownload.", "#00ff88");
        finishAll();
        return;
    }
    
    updatePhotoStats(st.stats);
    log("Total antrean foto: " + photoTotal, "#00ff88");
    
    // Mulai loop batch
    processPhotoBatch();
}

async function processPhotoBatch() {
    try {
        const r = await fetch('../api/migration_api.php?action=photo_batch').then(x => x.json());
        
        if (!r.ok) {
            log('Error Batch: ' + (r.error || 'unknown'), '#ff4444');
            return;
        }

        if (r.done) {
            log('🎉 Semua foto didownload dan mapping database diupdate.', '#00ff88');
            
            // Final refresh stat
            const st = await fetch('../api/migration_api.php?action=photo_stats').then(r=>r.json());
            if(st.ok) updatePhotoStats(st.stats);
            
            finishAll();
            return;
        }

        document.getElementById('photoStepName').textContent = "Downloading...";
        
        // Refresh stat setelah tiap batch
        const st = await fetch('../api/migration_api.php?action=photo_stats').then(x=>x.json());
        if(st.ok) updatePhotoStats(st.stats);
        
        setTimeout(processPhotoBatch, 200);

    } catch (e) {
        log('Koneksi terputus, retry 3 detik...', '#ffcc00');
        setTimeout(processPhotoBatch, 3000);
    }
}

function updatePhotoStats(stats) {
    const done = stats['DONE'] || 0;
    const failed = stats['FAILED'] || 0;
    const pending = stats['PENDING'] || 0;
    
    let pct = photoTotal > 0 ? (done / photoTotal)*100 : 0;
    
    document.getElementById('photoCur').textContent = done;
    document.getElementById('photoTot').textContent = photoTotal;
    document.getElementById('photoPct').textContent = pct.toFixed(1) + '%';
    document.getElementById('photoBar').style.width = pct + '%';
    
    document.getElementById('photoOk').textContent = done;
    document.getElementById('photoFail').textContent = failed;
}

function finishAll() {
    document.getElementById('stepProgress').classList.remove('active');
    document.getElementById('stepProgress').classList.add('done');
    document.getElementById('doneAlert').classList.remove('d-none');
    log("Semua flow berhasil diselesaikan!", "#00ff88");
}
</script>
</body>
</html>
