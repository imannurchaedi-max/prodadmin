<!DOCTYPE html>
<html lang="id" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1, user-scalable=0">
    <title>Production Report Pro</title>

    <link rel="icon" href="https://raw.githubusercontent.com/KANAN-lab/WFG-DAM/refs/heads/main/DAM%20LOGO.ico">

    <!-- Library CSS (lokal - tidak ada CDN, app jalan di LAN) -->
    <link href="assets/bootstrap.min.css" rel="stylesheet">
    <link href="assets/fontawesome.min.css" rel="stylesheet">
    <link href="assets/animate.min.css" rel="stylesheet">

    <!-- App CSS (termasuk @font-face Inter lokal) -->
    <link href="assets/style.css?v=<?=time()?>" rel="stylesheet">
</head>
<body>

    <!-- -- Animated background (login only) ---------------------------------- -->
    <div class="gooey-bg" id="globalBackground">
        <div class="blob blob-1"></div>
        <div class="blob blob-2"></div>
        <div class="blob blob-3"></div>
    </div>

    <!-- -- Login wrapper ------------------------------------------------------ -->
    <div id="login-wrapper">
        <div class="card card-custom border-0 shadow-lg" style="max-width:400px;width:100%;">
            <div class="card-body p-5 text-center">
                <div class="mb-4">
                    <img src="https://raw.githubusercontent.com/KANAN-lab/WFG-DAM/refs/heads/main/DAM%20LOGO.ico"
                         alt="Logo" style="width:80px;height:80px;object-fit:contain;">
                </div>
                <h4 class="fw-bold text-primary mb-1">Production Pro</h4>
                <p class="text-muted small mb-4">Silakan login untuk memulai sesi</p>

                <form id="loginForm">
                    <div class="form-floating mb-3 text-start">
                        <select class="form-select fw-bold" id="loginUserSelect">
                            <option value="Group A">Group A</option>
                            <option value="Group B">Group B</option>
                            <option value="Group C">Group C</option>
                            <option value="Group D">Group D</option>
                        </select>
                        <label for="loginUserSelect">User Group</label>
                    </div>

                    <div class="form-floating mb-3 text-start">
                        <select class="form-select fw-bold" id="loginMachineSelect">
                            <option value="" disabled selected>... Memuat daftar mesin...</option>
                        </select>
                        <label for="loginMachineSelect">Pilih Mesin</label>
                    </div>

                    <div class="form-floating mb-4 text-start">
                        <input type="password" class="form-control fw-bold" id="loginPasswordInput"
                               placeholder="Password" autocomplete="current-password">
                        <label for="loginPasswordInput">Password</label>
                    </div>

                    <button type="submit" class="btn btn-primary w-100 py-2 fw-bold rounded-pill shadow-sm" id="btnLoginAction">
                        <i class="fa-solid fa-right-to-bracket me-2"></i> MASUK
                    </button>

                    <button type="button" class="btn btn-outline-danger w-100 py-2 fw-bold rounded-pill shadow-sm mt-3" onclick="showAdminLogin()">
                        <i class="fa-solid fa-user-shield me-2"></i> LOGIN ADMIN
                    </button>
                </form>

                <div class="mt-4 pt-3 border-top">
                    <small class="text-muted" style="font-size:0.7rem;">
                        <i class="fa-solid fa-shield-halved me-1"></i> Multi-Session Access (Per Machine)
                    </small>
                </div>
            </div>
        </div>
    </div>

    <!-- -- App wrapper -------------------------------------------------------- -->
    <div id="app-wrapper" style="display:none;">

        <!-- Navbar -->
        <nav class="navbar fixed-top">
            <div class="container-fluid max-w-1200 px-3">
                <a class="navbar-brand d-flex align-items-center gap-2 me-auto" href="#" onclick="location.reload(); return false;">
                    <div class="lh-1">
                        <div class="d-none d-md-block">
                            <div class="fw-bold fs-6">LAPORAN PEMAKAIAN MATERIAL - BABY &amp; ADULT PANTS</div>
                            <div class="small fw-normal opacity-75" style="font-size:0.7rem;">PT. DAYA ANUGRAH MULYA</div>
                        </div>
                        <div class="d-block d-md-none">
                            <div class="fw-bold fs-6 text-primary" style="letter-spacing:-0.5px;">PRO REPORT - BHP</div>
                            <div class="small fw-bold text-muted" style="font-size:0.6rem;">Production App</div>
                        </div>
                    </div>
                </a>
                <div class="d-flex align-items-center gap-2">
                    <div id="userProfileContainer" class="d-none d-md-flex flex-column align-items-end me-2">
                        <span class="text-muted" style="font-size:0.65rem;">Login sebagai:</span>
                        <div class="d-flex align-items-center gap-1">
                            <i class="fa-solid fa-circle-user text-primary"></i>
                            <span id="navUserName" class="fw-bold text-dark small">-</span>
                        </div>
                    </div>
                    <button class="btn btn-theme-toggle shadow-sm text-danger border-danger border-opacity-25 bg-danger bg-opacity-10"
                            onclick="handleLogout()" title="Keluar">
                        <i class="fa-solid fa-power-off"></i>
                    </button>
                    <button class="btn btn-theme-toggle shadow-sm" onclick="toggleTheme()" id="themeBtn" style="width:38px;height:38px;">
                        <i class="fa-solid fa-moon"></i>
                    </button>
                    <button class="btn btn-theme-toggle shadow-sm text-primary border-primary border-opacity-25 bg-primary bg-opacity-10"
                            onclick="showAdminLogin()" style="width:38px;height:38px;" title="Admin">
                        <i class="fa-solid fa-user-gear"></i>
                    </button>
                </div>
            </div>
        </nav>

        <!-- Main content (FASE 3 akan mengisi ini) -->
        <div class="container-fluid content-wrapper pb-5" id="mainApp">

            <!-- Broadcast banner -->
            <div id="broadcastBanner"
                 class="alert alert-info border-0 shadow-sm d-none mb-3 d-flex align-items-center animate__animated animate__fadeInDown mx-auto">
                <div class="bg-info text-white rounded-circle d-flex align-items-center justify-content-center me-3"
                     style="min-width:40px;height:40px;">
                    <i class="fa-solid fa-bullhorn"></i>
                </div>
                <div>
                    <strong class="d-block text-uppercase small text-info" style="letter-spacing:1px;font-size:0.7rem;">PENGUMUMAN ADMIN</strong>
                    <span id="broadcastText" class="fw-bold text-dark"></span>
                </div>
            </div>

            <!-- Tabs -->
            <div class="sticky-top-custom animate__animated animate__fadeInDown">
                <div class="nav-pill-group shadow-sm">
                    <div class="nav-pill-item active" id="tabInput" onclick="switchView('input')">
                        <i class="fa-solid fa-pen-nib me-2"></i>INPUT
                    </div>
                    <div class="nav-pill-item" id="tabData" onclick="switchView('data')">
                        <i class="fa-solid fa-clock-rotate-left me-2"></i>RIWAYAT
                    </div>
                </div>
            </div>

            <div id="userView">

                <!-- --- VIEW INPUT ------------------------------------------- -->
                <div id="viewInput" class="animate__animated animate__fadeIn">

                    <!-- Draft auto-resume notice: data is already loaded into the form below;
                         this is informational only, not a gate the user must click through. -->
                    <div id="draftBanner" class="alert alert-info border-0 shadow-sm d-none mb-3 d-flex align-items-center justify-content-between">
                        <div>
                            <strong class="text-info-emphasis"><i class="fa-solid fa-clock-rotate-left me-2"></i>Draft dilanjutkan otomatis</strong>
                            <div class="small text-muted">Terakhir disimpan <span id="draftBannerAge"></span></div>
                        </div>
                        <div class="d-flex gap-2">
                            <button class="btn btn-sm btn-outline-secondary" onclick="discardDraft()">Mulai Baru</button>
                            <button class="btn-close" aria-label="Tutup" onclick="dismissDraftBanner()"></button>
                        </div>
                    </div>

                    <!-- Edit mode banner -->
                    <div id="editModeBanner" class="alert alert-warning border-0 shadow-sm d-none mb-3 d-flex align-items-center justify-content-between">
                        <div>
                            <strong class="text-warning-emphasis"><i class="fa-solid fa-pen-to-square me-2"></i>MODE REVISI</strong>
                            <div class="small text-muted" id="editModeInfo">Mengedit Transaksi ID: ...</div>
                        </div>
                        <button class="btn btn-sm btn-outline-warning text-dark fw-bold" onclick="cancelEditMode()">Batal</button>
                    </div>

                    <!-- Config shift -->
                    <div class="card card-custom mb-4">
                        <div class="card-body p-4">
                            <div class="d-flex justify-content-between align-items-center mb-3">
                                <h6 class="text-uppercase fw-bold text-muted m-0 small letter-spacing-1">
                                    <i class="fa-solid fa-sliders me-2 text-primary"></i>Konfigurasi Shift
                                </h6>
                                <button id="btnHandover" class="btn btn-sm btn-outline-success fw-bold d-none" onclick="triggerHandover()">
                                    <i class="fa-solid fa-download me-1"></i>Tarik Stok Shift Lalu
                                </button>
                            </div>
                            <div class="row g-3">
                                <div class="col-6 col-md-3">
                                    <label class="form-label small fw-bold text-muted">Tanggal</label>
                                    <input type="date" id="inputTanggal" class="form-control fw-bold" autocomplete="off">
                                </div>
                                <div class="col-6 col-md-3">
                                    <label class="form-label small fw-bold text-muted">Shift</label>
                                    <select id="inputShift" class="form-select fw-bold" onchange="renderTable()">
                                        <option value="1">Shift 1 (Pagi)</option>
                                        <option value="2">Shift 2 (Siang)</option>
                                        <option value="3">Shift 3 (Malam)</option>
                                    </select>
                                </div>
                                <div class="col-6 col-md-3">
                                    <label class="form-label small fw-bold text-muted">Mesin</label>
                                    <select id="inputMesin" class="form-select fw-bold"><option>Loading...</option></select>
                                </div>
                                <div class="col-6 col-md-3">
                                    <label class="form-label small fw-bold text-muted">Size</label>
                                    <select id="inputSize" class="form-select fw-bold"><option>Loading...</option></select>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Tabel material -->
                    <div class="card card-custom mb-4 border-primary border-opacity-25" style="border-width:2px;">
                        <div id="headerGroup" class="card-header bg-soft-primary border-bottom p-3 sticky-card-header">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <div class="d-flex align-items-center gap-2">
                                    <div class="bg-primary text-white rounded p-1"><i class="fa-solid fa-layer-group"></i></div>
                                    <span class="fw-bold text-primary">Input Material</span>
                                </div>
                                <span class="badge bg-primary shadow-sm" id="shiftBadge">SHIFT 1</span>
                            </div>
                            <div class="input-group input-group-sm">
                                <span class="input-group-text bg-white border-end-0"><i class="fa-solid fa-search text-muted"></i></span>
                                <input type="text" id="inputSearchMaterial" class="form-control border-start-0" placeholder="Cari material..." onkeyup="filterMaterialTable()">
                            </div>
                        </div>
                        <div class="table-responsive" style="max-height: 68vh; overflow-y: auto;">
                            <table class="table table-custom align-middle mb-0" id="mainTable">
                                <thead><tr></tr></thead>
                                <tbody id="tableBody"></tbody>
                            </table>
                        </div>
                        <div class="card-footer bg-transparent p-3 border-top">
                            <button class="btn btn-outline-secondary btn-sm fw-bold w-100" onclick="addCustomMaterial()">
                                <i class="fa-solid fa-plus me-1"></i>Tambah Material Lain
                            </button>
                        </div>
                    </div>

                    <!-- Analisis output produksi -->
                    <div class="card card-custom mb-4 border-success border-opacity-25" style="border-width:2px;">
                        <div class="card-header bg-success bg-opacity-10 border-bottom p-3">
                            <div class="d-flex justify-content-between align-items-center">
                                <div class="d-flex align-items-center gap-2">
                                    <div class="bg-success text-white rounded p-1"><i class="fa-solid fa-calculator"></i></div>
                                    <span class="fw-bold text-success">Analisis Output Produksi</span>
                                </div>
                                <button class="btn btn-sm btn-outline-success fw-bold" onclick="addOutputRow()">
                                    <i class="fa-solid fa-plus me-1"></i>Tambah Output
                                </button>
                            </div>
                        </div>
                        <div class="card-body p-3">
                            <div class="alert alert-light border small text-muted mb-3">
                                <i class="fa-solid fa-info-circle me-1"></i>Masukkan hasil output (Box)...
                            </div>
                            <div id="outputRowsContainer"></div>
                            <div class="table-responsive mt-4 rounded border">
                                <table class="table table-sm table-striped mb-0" id="analysisTable">
                                    <thead class="table-light fw-bold text-secondary small text-uppercase text-center"><tr>
                                        <th class="text-start ps-3">Kategori Material</th>
                                        <th>Target (STD)</th>
                                        <th>Aktual (Input)</th>
                                        <th>Selisih</th>
                                        <th>Status</th>
                                    </tr></thead>
                                    <tbody id="analysisBody">
                                        <tr><td colspan="5" class="text-muted fst-italic py-3">Belum ada data output.</td></tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    <!-- Info hasil produksi (laporan) -->
                    <div class="card card-custom mb-4 border-danger border-opacity-25" style="border-width:2px;">
                        <div class="card-header bg-danger bg-opacity-10 border-bottom p-3">
                            <div class="d-flex align-items-center gap-2">
                                <div class="bg-danger text-white rounded p-1 shadow-sm"><i class="fa-solid fa-clipboard-list"></i></div>
                                <span class="fw-bold text-danger">INFO HASIL PRODUKSI</span>
                            </div>
                        </div>
                        <div class="card-body p-4">

                            <h6 class="text-secondary small fw-bold text-uppercase border-bottom pb-2 mb-3">
                                <i class="fa-solid fa-boxes-stacked me-2 text-primary"></i>1. Detail Output
                            </h6>
                            <div id="dynamicProductionContainer" class="mb-3">
                                <div class="text-center text-muted py-5 border-2 border-dashed rounded-3 bg-light bg-opacity-50">
                                    <div class="mb-2 text-primary opacity-25"><i class="fa-solid fa-box-open fa-3x"></i></div>
                                    <small class="fw-bold">Belum ada output.</small><br>
                                    <span style="font-size:0.7rem;">Tambahkan item di tabel Analisis Output di atas.</span>
                                </div>
                            </div>

                            <!-- Scorecard -->
                            <div class="stats-dashboard-container mb-4 p-0 overflow-hidden">
                                <div class="row g-0">
                                    <div class="col-4 border-end position-relative stats-col-hover">
                                        <div class="p-3 text-center position-relative" style="z-index:2;">
                                            <div class="d-flex justify-content-center align-items-center gap-2 mb-1">
                                                <div class="stats-icon-circle bg-primary bg-opacity-10 text-primary"><i class="fa-solid fa-weight-hanging"></i></div>
                                                <div class="stats-label text-primary">TOTAL HASIL</div>
                                            </div>
                                            <div class="stats-value text-dark mt-1"><span id="grandTotalKg">0.00</span> <small class="unit-text text-muted">Kg</small></div>
                                        </div>
                                        <i class="fa-solid fa-boxes-stacked stats-icon-bg text-primary"></i>
                                    </div>
                                    <div class="col-4 border-end position-relative stats-col-hover">
                                        <div class="p-3 text-center position-relative" style="z-index:2;">
                                            <div class="d-flex justify-content-center align-items-center gap-2 mb-1">
                                                <div class="stats-icon-circle bg-danger bg-opacity-10 text-danger"><i class="fa-solid fa-scissors"></i></div>
                                                <div class="stats-label text-danger">TOTAL LOSS</div>
                                            </div>
                                            <div class="stats-value text-dark mt-1"><span id="grandTotalLoss">0.00</span> <small class="unit-text text-muted">Kg</small></div>
                                        </div>
                                        <i class="fa-solid fa-trash-arrow-up stats-icon-bg text-danger"></i>
                                    </div>
                                    <div class="col-4 position-relative stats-col-hover">
                                        <div class="p-3 text-center position-relative" style="z-index:2;">
                                            <div class="d-flex justify-content-center align-items-center gap-2 mb-1">
                                                <div class="stats-icon-circle bg-warning bg-opacity-10 text-warning-emphasis"><i class="fa-solid fa-gauge-high"></i></div>
                                                <div class="stats-label text-warning-emphasis">LOSS RATIO</div>
                                            </div>
                                            <div class="stats-value text-dark mt-1"><span id="grandTotalLossPct">0.00%</span></div>
                                        </div>
                                        <i class="fa-solid fa-percent stats-icon-bg text-warning"></i>
                                    </div>
                                </div>
                            </div>

                            <h6 class="text-secondary small fw-bold text-uppercase border-bottom pb-2 mb-3 mt-4">
                                <i class="fa-solid fa-sliders me-2 text-warning"></i>2. Parameter Shift
                            </h6>
                            <div class="row g-3 mb-4">
                                <div class="col-6 col-md-3">
                                    <div class="input-group has-validation shadow-sm">
                                        <span class="input-group-text input-group-text-custom"><i class="fa-solid fa-print"></i></span>
                                        <div class="form-floating"><input type="number" class="form-control fw-bold text-danger" id="rptRejectPrinting" placeholder="0"><label for="rptRejectPrinting">Reject Print (Kg)</label></div>
                                    </div>
                                </div>
                                <div class="col-6 col-md-4">
                                    <div class="input-group shadow-sm">
                                        <span class="input-group-text input-group-text-custom"><i class="fa-solid fa-gauge-high"></i></span>
                                        <div class="form-floating"><input type="number" class="form-control fw-bold text-dark" id="rptSpeed" placeholder="480"><label for="rptSpeed">Avg Speed (ppm)</label></div>
                                    </div>
                                </div>
                                <div class="col-12 col-md-5">
                                    <div class="input-group shadow-sm">
                                        <span class="input-group-text input-group-text-custom"><i class="fa-solid fa-stopwatch"></i></span>
                                        <div class="form-floating"><input type="number" class="form-control fw-bold text-dark" id="rptDowntimeMin" placeholder="0" oninput="calcShiftMetrics()"><label for="rptDowntimeMin">Downtime (Min)</label></div>
                                        <div class="form-floating" style="max-width:100px;"><input type="text" class="form-control fw-bold form-control-plaintext-custom text-center text-secondary" id="rptDowntimePct" placeholder="0%" readonly><label for="rptDowntimePct">Ratio</label></div>
                                    </div>
                                </div>
                            </div>

                            <h6 class="text-secondary small fw-bold text-uppercase border-bottom pb-2 mb-3 mt-4">
                                <i class="fa-regular fa-pen-to-square me-2 text-success"></i>3. Catatan Operasional
                            </h6>
                            <div class="mb-3">
                                <div class="smart-input-group">
                                    <div class="smart-toolbar">
                                        <label class="small fw-bold text-danger"><i class="fa-solid fa-triangle-exclamation me-1"></i>Trouble / Kendala</label>
                                        <a href="javascript:void(0)" class="btn-toolbar-action" onclick="addSmartBullet('rptTrouble')"><i class="fa-solid fa-list-ul me-1"></i> + Point</a>
                                    </div>
                                    <textarea id="rptTrouble" class="form-control smart-textarea" rows="3" placeholder="Ketik kendala mesin disini..." onkeydown="handleSmartEnter(event)" oninput="autoResize(this)"></textarea>
                                </div>
                            </div>
                            <div class="mb-3">
                                <div class="smart-input-group">
                                    <div class="smart-toolbar">
                                        <label class="small fw-bold text-success"><i class="fa-solid fa-circle-info me-1"></i>Near Miss</label>
                                        <a href="javascript:void(0)" class="btn-toolbar-action" onclick="addSmartBullet('rptNearMiss')"><i class="fa-solid fa-list-ul me-1"></i> + Point</a>
                                    </div>
                                    <textarea id="rptNearMiss" class="form-control smart-textarea" rows="2" placeholder="Catat kejadian hampir celaka..." onkeydown="handleSmartEnter(event)" oninput="autoResize(this)"></textarea>
                                </div>
                            </div>
                            <div class="mb-3">
                                <div class="input-group shadow-sm">
                                    <span class="input-group-text input-group-text-custom"><i class="fa-solid fa-note-sticky"></i></span>
                                    <div class="form-floating"><input type="text" class="form-control fw-bold" id="rptNotes" placeholder="Catatan tambahan..."><label for="rptNotes">Catatan Tambahan</label></div>
                                </div>
                            </div>

                            <!-- Galeri foto -->
                            <div id="galleryContainer" class="mt-3"></div>

                        </div>
                    </div>

                    <!-- Tombol submit -->
                    <div class="d-flex gap-2 mb-2">
                        <button class="btn btn-primary flex-grow-1 fw-bold py-3 shadow-sm" onclick="submitData()" id="btnSubmit">
                            <i class="fa-solid fa-paper-plane me-2"></i>SUBMIT LAPORAN
                        </button>
                        <button class="btn btn-outline-secondary fw-bold py-3" onclick="saveDraft()" title="Simpan Draft">
                            <i class="fa-solid fa-floppy-disk"></i>
                        </button>
                        <button class="btn btn-outline-primary fw-bold py-3" onclick="resetData()" id="btnReset">
                            <i class="fa-solid fa-rotate-left me-2"></i>RESET
                        </button>
                    </div>
                    <div class="text-end mb-4">
                        <small id="draftSavedAt" class="text-muted d-none"></small>
                    </div>

                </div><!-- /viewInput -->

                <!-- --- VIEW DATA / RIWAYAT ----------------------------------- -->
                <div id="viewData" class="d-none animate__animated animate__fadeIn">
                    <div class="card card-custom mb-3">
                        <div class="card-body p-3">
                            <div class="row g-2 align-items-center">
                                <div class="col-md-4">
                                    <input type="date" id="filterStartDate" class="form-control form-control-sm fw-bold" autocomplete="off">
                                    <div class="small text-muted mt-1">Dari Tanggal</div>
                                </div>
                                <div class="col-md-4">
                                    <input type="date" id="filterEndDate" class="form-control form-control-sm fw-bold" autocomplete="off">
                                    <div class="small text-muted mt-1">Sampai Tanggal</div>
                                </div>
                                <div class="col-md-4">
                                    <button class="btn btn-primary btn-sm w-100 fw-bold" onclick="loadHistory()">
                                        <i class="fa-solid fa-magnifying-glass me-1"></i>Cari
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div id="reportGrid" class="mb-3"></div>
                    <div class="text-center mb-4">
                        <button id="btnLoadMoreHistory" class="btn btn-outline-secondary btn-sm d-none" onclick="loadMoreHistory()">
                            <i class="fa-solid fa-chevron-down me-1"></i>Muat Lebih
                        </button>
                    </div>
                </div><!-- /viewData -->

            </div><!-- /userView -->

            <!-- --- ADMIN VIEW ----------------------------------------------- -->
            <div id="adminView" class="d-none animate__animated animate__fadeIn">

                <!-- Admin header bar -->
                <div class="d-flex justify-content-between align-items-center mb-4 p-3 bg-danger bg-opacity-10 rounded border border-danger border-opacity-25">
                    <div>
                        <span class="fw-bold text-danger"><i class="fa-solid fa-user-shield me-2"></i>ADMIN PANEL</span>
                        <span class="badge bg-danger ms-2">Full Access</span>
                    </div>
                    <button class="btn btn-sm btn-outline-danger fw-bold" onclick="logoutAdmin()">
                        <i class="fa-solid fa-right-from-bracket me-1"></i>Keluar Admin
                    </button>
                </div>

                <!-- Admin tabs -->
                <div class="d-flex gap-2 mb-4 flex-wrap">
                    <?php foreach ([
                        ['laporan',  'fa-chart-bar',     'Laporan'],
                        ['material', 'fa-layer-group',   'Material'],
                        ['produk',   'fa-box',            'Produk'],
                        ['setting',  'fa-sliders',        'Pengaturan'],
                        ['log',      'fa-list-check',    'Log Aktivitas'],
                    ] as [$tab, $icon, $label]): ?>
                    <button class="btn btn-sm btn-outline-secondary fw-bold admin-tab-btn <?= $tab==='laporan'?'active':'' ?>"
                            data-tab="<?= $tab ?>" onclick="switchAdminTab('<?= $tab ?>')">
                        <i class="fa-solid <?= $icon ?> me-1"></i><?= $label ?>
                    </button>
                    <?php endforeach; ?>
                </div>

                <!-- -- Tab: Laporan -- -->
                <div id="adminTab_laporan" class="admin-tab-pane">
                    <!-- Scorecard -->
                    <div class="row g-3 mb-4">
                        <div class="col-md-4">
                            <div class="card card-custom p-3 text-center">
                                <div class="stats-label text-primary mb-1" id="scoreTotalLabel">TOTAL PRODUKSI HARI INI</div>
                                <div class="stats-value" id="scoreTotal">-</div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="card card-custom p-3 text-center">
                                <div class="stats-label text-success mb-1">TOP MATERIAL</div>
                                <div class="stats-value small" id="scoreTop">-</div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="card card-custom p-3 text-center">
                                <div class="stats-label text-danger mb-1">STOK MINUS</div>
                                <div class="stats-value text-danger" id="scoreAlert">-</div>
                            </div>
                        </div>
                    </div>
                    <!-- Charts -->
                    <div class="row g-3 mb-4">
                        <div class="col-md-6"><div class="card card-custom p-3"><div class="stats-label mb-2">Top Material Usage</div><div style="height:180px"><canvas id="chartMain"></canvas></div></div></div>
                        <div class="col-md-3"><div class="card card-custom p-3"><div class="stats-label mb-2">Distribusi Shift</div><div style="height:180px"><canvas id="chartMini"></canvas></div></div></div>
                        <div class="col-md-3"><div class="card card-custom p-3"><div class="stats-label mb-2">Top Reject</div><div style="height:180px"><canvas id="chartReject"></canvas></div></div></div>
                    </div>
                    <!-- Filter + grid -->
                    <div class="card card-custom mb-3 p-3">
                        <div class="row g-2 align-items-center">
                            <div class="col-md-4"><input type="date" id="filterStartDateAdmin" class="form-control form-control-sm fw-bold" autocomplete="off"></div>
                            <div class="col-md-4"><input type="date" id="filterEndDateAdmin"   class="form-control form-control-sm fw-bold" autocomplete="off"></div>
                            <div class="col-md-4"><button class="btn btn-danger btn-sm w-100 fw-bold" onclick="loadAdminHistory()"><i class="fa-solid fa-magnifying-glass me-1"></i>Cari</button></div>
                        </div>
                    </div>
                    <div id="adminReportGrid" class="mb-4"></div>
                </div>

                <!-- -- Tab: Material -- -->
                <div id="adminTab_material" class="admin-tab-pane d-none">
                    <div class="card card-custom p-4 mb-3">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h6 class="fw-bold m-0"><i class="fa-solid fa-layer-group me-2 text-primary"></i>Daftar Material</h6>
                            <button class="btn btn-sm btn-success fw-bold" onclick="saveMaterialChanges()"><i class="fa-solid fa-floppy-disk me-1"></i>Simpan Urutan</button>
                        </div>
                        <div class="alert alert-light border small text-muted mb-3 py-2">
                            <i class="fa-solid fa-circle-info me-1"></i>Geser <i class="fa-solid fa-grip-vertical mx-1"></i> untuk mengurutkan. Klik <i class="fa-solid fa-pen mx-1"></i> untuk edit nama/supplier.
                        </div>
                        <ul class="list-group mb-3" id="materialSortableList"></ul>
                        <div class="input-group input-group-sm">
                            <input type="text" id="newMaterialInput" class="form-control fw-bold" placeholder="Nama material baru (HURUF BESAR)...">
                            <button class="btn btn-primary fw-bold" onclick="addNewMaterial()"><i class="fa-solid fa-plus"></i></button>
                        </div>
                    </div>
                </div>

                <!-- -- Tab: Produk (Conversion) -- -->
                <div id="adminTab_produk" class="admin-tab-pane d-none">
                    <div class="card card-custom p-4 mb-3">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h6 class="fw-bold m-0"><i class="fa-solid fa-box me-2 text-primary"></i>Database Produk</h6>
                            <button class="btn btn-sm btn-primary fw-bold" onclick="openConversionModal()"><i class="fa-solid fa-plus me-1"></i>Tambah</button>
                        </div>
                        <div class="input-group input-group-sm mb-3">
                            <span class="input-group-text"><i class="fa-solid fa-search text-muted"></i></span>
                            <input type="text" id="searchConversion" class="form-control" placeholder="Cari MID / Nama..." oninput="filterConversionTable()">
                        </div>
                        <div class="table-responsive">
                            <table class="table table-sm table-hover" id="tableConversion">
                                <thead class="table-light fw-bold text-secondary small text-uppercase">
                                    <tr><th>MID</th><th>Nama</th><th>Berat (g)</th><th>Ratio</th><th>Cat Bag</th><th>Cat Box</th><th></th></tr>
                                </thead>
                                <tbody></tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- -- Tab: Pengaturan -- -->
                <div id="adminTab_setting" class="admin-tab-pane d-none">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <div class="card card-custom p-4">
                                <h6 class="fw-bold mb-3"><i class="fa-solid fa-computer me-2 text-primary"></i>Config Mesin &amp; Ukuran</h6>
                                <label class="small fw-bold text-muted">Daftar Mesin (pisah koma)</label>
                                <textarea id="adminListMesin" class="form-control form-control-sm mb-2 fw-bold" rows="3"></textarea>
                                <label class="small fw-bold text-muted">Daftar Size (pisah koma)</label>
                                <textarea id="adminListSize" class="form-control form-control-sm mb-2 fw-bold" rows="2"></textarea>
                                <label class="small fw-bold text-muted">Aliases Mesin (JSON)</label>
                                <textarea id="adminListAlias" class="form-control form-control-sm mb-3 fw-bold font-monospace" rows="3" placeholder='{"Nama Baru": "Nama Lama"}'></textarea>
                                <button class="btn btn-sm btn-primary w-100 fw-bold" onclick="saveMachineSizeConfig()"><i class="fa-solid fa-floppy-disk me-1"></i>Simpan Config</button>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="card card-custom p-4 mb-3">
                                <h6 class="fw-bold mb-3"><i class="fa-solid fa-lock me-2 text-warning"></i>Lock Tanggal</h6>
                                <div class="input-group input-group-sm mb-2">
                                    <span class="input-group-text"><i class="fa-solid fa-calendar"></i></span>
                                    <input type="date" id="adminLockDate" class="form-control fw-bold">
                                    <button class="btn btn-warning fw-bold" onclick="saveLockDate()">Simpan</button>
                                </div>
                                <small class="text-muted">Data pada tanggal <= tanggal ini tidak bisa diedit user biasa.</small>
                            </div>
                            <div class="card card-custom p-4 mb-3">
                                <h6 class="fw-bold mb-3"><i class="fa-solid fa-bullhorn me-2 text-info"></i>Broadcast Pesan</h6>
                                <textarea id="adminBroadcastMsg" class="form-control form-control-sm mb-2" rows="2" placeholder="Pesan untuk semua user..."></textarea>
                                <div class="form-check form-switch mb-2">
                                    <input class="form-check-input" type="checkbox" id="switchBroadcast" onchange="saveBroadcast(true)">
                                    <label class="form-check-label small fw-bold" for="switchBroadcast">Aktifkan Broadcast</label>
                                </div>
                                <button class="btn btn-sm btn-info w-100 fw-bold text-white" onclick="saveBroadcast()"><i class="fa-solid fa-paper-plane me-1"></i>Simpan Pesan</button>
                            </div>
                            <div class="card card-custom p-4">
                                <h6 class="fw-bold mb-3"><i class="fa-solid fa-download me-2 text-success"></i>Fitur Handover</h6>
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" id="switchHandover" onchange="saveAppSettings()">
                                    <label class="form-check-label small fw-bold" for="switchHandover">Aktifkan Tarik Stok Shift Lalu</label>
                                </div>
                            </div>
                            <div class="card card-custom p-4 mt-3">
                                <div class="d-flex justify-content-between align-items-center mb-2">
                                    <h6 class="fw-bold m-0"><i class="fa-solid fa-images me-2 text-primary"></i>Progres Foto GDrive</h6>
                                    <button class="btn btn-sm btn-outline-primary fw-bold" onclick="loadPhotoMigrationProgress()">Refresh</button>
                                </div>
                                <div class="small text-muted mb-2" id="photoProgressStatus">Belum dimuat.</div>
                                <div class="small fw-bold text-dark mb-1" id="photoProgressRange">-</div>
                                <div class="small text-muted mb-3" id="photoProgressSummary">-</div>
                                <div class="table-responsive" style="max-height:280px;overflow-y:auto;">
                                    <table class="table table-sm table-striped mb-0" id="photoProgressTable">
                                        <thead class="table-light small text-uppercase">
                                            <tr>
                                                <th>Tanggal</th>
                                                <th class="text-end">Total</th>
                                                <th class="text-end">Done</th>
                                                <th class="text-end">Pending</th>
                                                <th class="text-end">Failed</th>
                                                <th class="text-end">%</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <tr><td colspan="6" class="text-center text-muted">Belum dimuat.</td></tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- -- Tab: Log Aktivitas -- -->
                <div id="adminTab_log" class="admin-tab-pane d-none">
                    <div class="card card-custom p-4">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h6 class="fw-bold m-0"><i class="fa-solid fa-list-check me-2 text-secondary"></i>Log Aktivitas (50 Terbaru)</h6>
                            <div class="input-group input-group-sm" style="max-width:260px;">
                                <input type="password" id="adminPinForLogs" class="form-control" placeholder="PIN Admin">
                                <button class="btn btn-secondary fw-bold" onclick="loadAuditLogs()">Load</button>
                            </div>
                        </div>
                        <div class="table-responsive" style="max-height:400px;overflow-y:auto;">
                            <table class="table table-sm table-striped" id="auditLogTable">
                                <thead class="table-dark sticky-top text-uppercase small"><tr><th>Waktu</th><th>User</th><th>Aksi</th><th>Detail</th></tr></thead>
                                <tbody><tr><td colspan="4" class="text-center text-muted">Masukkan PIN untuk melihat log.</td></tr></tbody>
                            </table>
                        </div>
                    </div>
                    <div class="card card-custom p-4 mt-3">
                        <h6 class="fw-bold mb-3"><i class="fa-solid fa-key me-2 text-warning"></i>Ganti Password User</h6>
                        <button class="btn btn-sm btn-outline-warning fw-bold" onclick="openChangePassword()"><i class="fa-solid fa-pen me-1"></i>Ganti Password Saya</button>
                    </div>
                </div>

            </div><!-- /adminView -->

        </div><!-- /mainApp -->

        <!-- Footer -->
        <footer class="gooey-footer">
            <div class="footer-text">
                <h1>Production Report <span>Pro v1.0</span></h1>
                <small>&copy; 2026 PT. Daya Anugrah Mulya</small>
            </div>
            <div class="waves-container">
                <svg class="waves" xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink"
                     viewBox="0 24 150 28" preserveAspectRatio="none" shape-rendering="auto">
                    <defs>
                        <path id="gentle-wave" d="M-160 44c30 0 58-18 88-18s 58 18 88 18 58-18 88-18 58 18 88 18 v44h-352z"/>
                    </defs>
                    <g class="parallax">
                        <use xlink:href="#gentle-wave" x="48" y="0" fill="rgba(112,112,112,0.7)"/>
                        <use xlink:href="#gentle-wave" x="48" y="3" fill="rgba(46,156,202,0.5)"/>
                        <use xlink:href="#gentle-wave" x="48" y="5" fill="rgba(46,156,202,0.3)"/>
                        <use xlink:href="#gentle-wave" x="48" y="7" fill="#2E9CCA"/>
                    </g>
                </svg>
            </div>
            <div class="gooey-animations" id="gooeyContainer"></div>
        </footer>

        <svg style="width:0;height:0;position:absolute;" aria-hidden="true">
            <defs>
                <filter id="goo">
                    <feGaussianBlur in="SourceGraphic" stdDeviation="10" result="blur"/>
                    <feColorMatrix in="blur" mode="matrix" values="1 0 0 0 0  0 1 0 0 0  0 0 1 0 0  0 0 0 19 -9" result="goo"/>
                    <feComposite in="SourceGraphic" in2="goo" operator="atop"/>
                </filter>
            </defs>
        </svg>
    </div>

    <!-- -- Loader -------------------------------------------------------------- -->
    <div id="loader">
        <svg class="liquid-spinner" viewBox="0 0 50 50">
            <circle cx="25" cy="25" r="20"></circle>
        </svg>
        <div class="loading-text fs-5">MEMUAT SISTEM...</div>
    </div>

    <!-- -- Library JS (lokal - tidak ada CDN) --------------------------------- -->
    <script src="assets/bootstrap.bundle.min.js"></script>
    <script src="assets/sweetalert2.all.min.js"></script>
    <script src="assets/chart.min.js"></script>
    <script src="assets/html2canvas.min.js?v=1.4.1"></script>
    <script src="assets/Sortable.min.js"></script>

    <!-- -- Modal: Conversion CRUD -------------------------------------------- -->
    <div class="modal fade" id="modalConversion" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content card-custom border-0 shadow-lg">
                <div class="modal-header border-bottom">
                    <h6 class="modal-title fw-bold" id="modalConvTitle">Tambah Produk Baru</h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <input type="hidden" id="convOldMid">
                    <div class="row g-3">
                        <div class="col-6"><label class="small fw-bold text-muted">MID *</label><input type="text" id="convMid" class="form-control fw-bold" placeholder="M001"></div>
                        <div class="col-6"><label class="small fw-bold text-muted">Nama Produk *</label><input type="text" id="convName" class="form-control fw-bold" placeholder="PRODUK A"></div>
                        <div class="col-6"><label class="small fw-bold text-muted">Berat/pcs (gram)</label><input type="number" id="convWeight" class="form-control fw-bold" placeholder="0"></div>
                        <div class="col-6"><label class="small fw-bold text-muted">Ratio <span class="text-muted fw-normal">(pcs kantong/box)</span></label><input type="number" id="convRatio" class="form-control fw-bold" placeholder="0" step="0.0001"></div>
                        <div class="col-6"><label class="small fw-bold text-muted">Cat Bag <span class="text-muted fw-normal">(material kantong)</span></label><input type="text" id="convCatBag" class="form-control" list="materialDatalistConv" placeholder="Pilih nama material kantong..." onfocus="this.select()"></div>
                        <div class="col-6"><label class="small fw-bold text-muted">Cat Box <span class="text-muted fw-normal">(material kardus)</span></label><input type="text" id="convCatBox" class="form-control" list="materialDatalistConv" placeholder="Pilih nama material kardus..." onfocus="this.select()"></div>
                        <datalist id="materialDatalistConv"></datalist>
                    </div>
                </div>
                <div class="modal-footer border-top">
                    <button class="btn btn-outline-secondary" data-bs-dismiss="modal">Batal</button>
                    <button id="btnSaveConversion" class="btn btn-primary fw-bold" onclick="saveConversionData()"><i class="fa-solid fa-floppy-disk me-1"></i>Simpan</button>
                </div>
            </div>
        </div>
    </div>

    <!-- -- Modal: Edit Material (nama + daftar supplier) -- -->
    <div class="modal fade" id="modalMaterial" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content card-custom border-0 shadow-lg">
                <div class="modal-header border-bottom">
                    <h6 class="modal-title fw-bold">Edit Material</h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <input type="hidden" id="matOldName">
                    <div class="mb-3">
                        <label class="small fw-bold text-muted">Nama</label>
                        <input type="text" id="matName" class="form-control fw-bold">
                    </div>
                    <div>
                        <label class="small fw-bold text-muted">Supplier (Per baris)</label>
                        <textarea id="matSuppliers" class="form-control" rows="6" placeholder="Satu nama supplier per baris..."></textarea>
                    </div>
                </div>
                <div class="modal-footer border-top">
                    <button class="btn btn-outline-secondary" data-bs-dismiss="modal">Batal</button>
                    <button id="btnSaveMaterial" class="btn btn-primary fw-bold" onclick="saveMaterialEdit()"><i class="fa-solid fa-floppy-disk me-1"></i>Simpan</button>
                </div>
            </div>
        </div>
    </div>

    <!-- -- App JS (urutan: api -> auth -> form -> admin) ------------------------- -->
    <script src="assets/app/api.js?v=<?=time()?>"></script>
    <script src="assets/app/auth.js?v=<?=time()?>"></script>
    <script src="assets/app/form.js?v=<?=time()?>"></script>
    <script src="assets/app/admin.js?v=<?=time()?>"></script>

</body>
</html>
