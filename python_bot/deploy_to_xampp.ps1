# deploy_to_xampp.ps1
# Menyalin HANYA file produksi ke c:\xampp\htdocs\ProdAdmin\
# TIDAK menyertakan: file GAS (.gs, .html sumber), .md, sql/, docs/, seeds/, data_lama.xlsx

$SRC  = "C:\Users\imann\SynologyDrive\APP SCRIPT\0. PROD ADMIN 3"
$DEST = "C:\xampp\htdocs\ProdAdmin"

Write-Host ""
Write-Host "=== Deploy ProdAdmin ke XAMPP ===" -ForegroundColor Cyan
Write-Host "Sumber : $SRC"
Write-Host "Tujuan : $DEST"
Write-Host ""

# Buat folder tujuan jika belum ada
if (-not (Test-Path $DEST)) {
    New-Item -ItemType Directory -Path $DEST -Force | Out-Null
    Write-Host "Folder tujuan dibuat: $DEST" -ForegroundColor Yellow
}

# ── Bersihkan file GAS lama dari DEST ──────────────────────────────────────
Write-Host "=== Membersihkan file lama dari tujuan ===" -ForegroundColor Cyan

# File/folder yang TIDAK boleh ada di produksi
$toRemove = @(
    "*.gs", "*.html",           # GAS source (kecuali yang kita deploy tidak ada .html)
    "data_lama.xlsx",
    "CLAUDE.md","MIGRATION_REBUILD.md","AGENTS.md","DEPLOYMENT_SOP.md",
    "DOKUMENTASI_TEKNIS.md","SKILL.md",
    ".claude", "skills", "docs", "sql", "seeds"
)

foreach ($pat in $toRemove) {
    $found = Get-ChildItem -Path $DEST -Filter $pat -ErrorAction SilentlyContinue
    foreach ($f in $found) {
        Remove-Item -Path $f.FullName -Recurse -Force -ErrorAction SilentlyContinue
        Write-Host "  Hapus: $($f.Name)" -ForegroundColor DarkYellow
    }
    # Cek juga di root langsung
    $rootFile = Join-Path $DEST $pat
    if ((Test-Path $rootFile) -and -not $pat.Contains('*')) {
        Remove-Item -Path $rootFile -Recurse -Force -ErrorAction SilentlyContinue
        Write-Host "  Hapus: $pat" -ForegroundColor DarkYellow
    }
}
Write-Host ""

# ── File & folder yang di-copy ──────────────────────────────────────────────
$items = @(
    "index.php",
    ".htaccess",
    "config",
    "api",
    "assets",
    "uploads"
)

foreach ($item in $items) {
    $srcPath  = Join-Path $SRC  $item
    $destPath = Join-Path $DEST $item

    if (-not (Test-Path $srcPath)) {
        Write-Host "  SKIP (tidak ada): $item" -ForegroundColor DarkGray
        continue
    }

    if (Test-Path $srcPath -PathType Container) {
        # Folder: robocopy (mirror isi, jangan hapus dest jika tidak ada di sumber)
        $result = robocopy $srcPath $destPath /E /NFL /NDL /NJH /NJS /NC /NS 2>&1
        Write-Host "  [FOLDER] $item" -ForegroundColor Green
    } else {
        # File tunggal
        Copy-Item -Path $srcPath -Destination $destPath -Force
        Write-Host "  [FILE]   $item" -ForegroundColor Green
    }
}

# ── Pastikan folder uploads ada di tujuan ───────────────────────────────────
$uploadsPath = Join-Path $DEST "uploads\labels"
if (-not (Test-Path $uploadsPath)) {
    New-Item -ItemType Directory -Path $uploadsPath -Force | Out-Null
    Write-Host "  [CREATE] uploads\labels\" -ForegroundColor Yellow
}

# ── Verifikasi: tidak ada file GAS yang nyangkut ────────────────────────────
Write-Host ""
Write-Host "=== Verifikasi (tidak ada file GAS) ===" -ForegroundColor Cyan

$gasFiles = Get-ChildItem -Path $DEST -Recurse -Include "*.gs","*.xlsx","*.md" -ErrorAction SilentlyContinue
if ($gasFiles.Count -gt 0) {
    Write-Host "PERINGATAN: File berikut sebaiknya tidak ada di DEST:" -ForegroundColor Red
    $gasFiles | ForEach-Object { Write-Host "  $_" -ForegroundColor Red }
} else {
    Write-Host "  OK — Tidak ada file .gs/.xlsx/.md di tujuan" -ForegroundColor Green
}

$pattern = 'google\.script\.run'
$gasScan = Get-ChildItem -Path (Join-Path $DEST "api") -Recurse -Include "*.php","*.js" -ErrorAction SilentlyContinue |
           Select-String -Pattern $pattern -ErrorAction SilentlyContinue
if ($gasScan) {
    Write-Host "PERINGATAN: Ditemukan pola GAS di file PHP/JS:" -ForegroundColor Red
    $gasScan | ForEach-Object { Write-Host "  $($_.Filename):$($_.LineNumber)" -ForegroundColor Red }
} else {
    Write-Host "  OK — Tidak ada polyfill GAS di kode PHP/JS" -ForegroundColor Green
}

# ── Ringkasan ────────────────────────────────────────────────────────────────
Write-Host ""
Write-Host "=== Selesai ===" -ForegroundColor Cyan
Write-Host "Buka http://localhost/ProdAdmin/ di browser." -ForegroundColor White
Write-Host "Pastikan PostgreSQL & Apache sudah jalan di XAMPP." -ForegroundColor White
Write-Host ""
