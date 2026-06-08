# setup_database.ps1
# Jalankan setelah PostgreSQL terinstall.
# Membuat database prod_admin dan semua tabel dari nol.
#
# PRASYARAT:
#   - PostgreSQL 16 atau 17 terinstall
#   - Password postgres = SASMU123

param(
    [string]$PgVersion = "",
    [string]$PgPass    = "SASMU123"
)

# ── Cari psql.exe ────────────────────────────────────────────────────────────
$candidates = @(
    'C:\Program Files\PostgreSQL\17\bin\psql.exe',
    'C:\Program Files\PostgreSQL\16\bin\psql.exe',
    'C:\Program Files\PostgreSQL\15\bin\psql.exe',
    'C:\Program Files\PostgreSQL\14\bin\psql.exe'
)

$PSQL = $candidates | Where-Object { Test-Path $_ } | Select-Object -First 1

if (-not $PSQL) {
    # Coba cari dari PATH
    try { $PSQL = (Get-Command psql -ErrorAction Stop).Source } catch {}
}

if (-not $PSQL) {
    Write-Host ""
    Write-Host "ERROR: psql.exe tidak ditemukan." -ForegroundColor Red
    Write-Host "Pastikan PostgreSQL sudah terinstall dan PATH sudah ditambahkan." -ForegroundColor Yellow
    Write-Host ""
    Write-Host "Download PostgreSQL 17: https://www.enterprisedb.com/downloads/postgres-postgresql-downloads" -ForegroundColor Cyan
    Write-Host "Pilih Windows x86-64, install dengan password: SASMU123" -ForegroundColor Cyan
    exit 1
}

$PSQL_DIR  = Split-Path $PSQL
$CREATEDB  = Join-Path $PSQL_DIR 'createdb.exe'
$PSQL_CMD  = $PSQL

Write-Host ""
Write-Host "psql ditemukan: $PSQL_CMD" -ForegroundColor Green
& $PSQL_CMD --version

# Set password ke environment agar tidak perlu input manual
$env:PGPASSWORD = $PgPass

$SCRIPT_DIR = Split-Path $MyInvocation.MyCommand.Path
$SCHEMA_SQL = Join-Path $SCRIPT_DIR 'sql\setup_fresh.sql'
$PHP_SEEDER = Join-Path $SCRIPT_DIR 'seeds\rehash_users.php'
$PHP_EXE    = 'C:\xampp\php\php.exe'

# ── 1. Buat database prod_admin ──────────────────────────────────────────────
Write-Host ""
Write-Host "=== LANGKAH 1: Buat database prod_admin ===" -ForegroundColor Cyan

# Cek apakah sudah ada
$exists = & $PSQL_CMD -U postgres -tAc "SELECT 1 FROM pg_database WHERE datname='prod_admin'" postgres 2>&1
if ($exists -match '1') {
    Write-Host "Database prod_admin sudah ada, lanjut ke schema..." -ForegroundColor Yellow
} else {
    & $CREATEDB -U postgres -E UTF8 --lc-collate=C --lc-ctype=C -T template0 prod_admin
    if ($LASTEXITCODE -eq 0) {
        Write-Host "Database prod_admin berhasil dibuat." -ForegroundColor Green
    } else {
        Write-Host "GAGAL membuat database." -ForegroundColor Red
        exit 1
    }
}

# ── 2. Aktifkan ekstensi pgcrypto (gen_random_uuid) ─────────────────────────
Write-Host ""
Write-Host "=== LANGKAH 2: Aktifkan ekstensi ===" -ForegroundColor Cyan
& $PSQL_CMD -U postgres -d prod_admin -c "CREATE EXTENSION IF NOT EXISTS pgcrypto;"
if ($LASTEXITCODE -ne 0) {
    # pgcrypto gagal — coba pg_crypto atau uuid-ossp (fallback)
    Write-Host "pgcrypto gagal, coba uuid-ossp..." -ForegroundColor Yellow
    & $PSQL_CMD -U postgres -d prod_admin -c "CREATE EXTENSION IF NOT EXISTS `"uuid-ossp`";"
}
Write-Host "Ekstensi OK." -ForegroundColor Green

# ── 3. Jalankan schema ───────────────────────────────────────────────────────
Write-Host ""
Write-Host "=== LANGKAH 3: Jalankan setup_fresh.sql ===" -ForegroundColor Cyan
& $PSQL_CMD -U postgres -d prod_admin -f $SCHEMA_SQL
if ($LASTEXITCODE -eq 0) {
    Write-Host "Schema berhasil dibuat." -ForegroundColor Green
} else {
    Write-Host "GAGAL menjalankan schema SQL." -ForegroundColor Red
    exit 1
}

# ── 4. Seed users ────────────────────────────────────────────────────────────
Write-Host ""
Write-Host "=== LANGKAH 4: Seed users (bcrypt) ===" -ForegroundColor Cyan
if (Test-Path $PHP_EXE) {
    & $PHP_EXE $PHP_SEEDER
    if ($LASTEXITCODE -eq 0) {
        Write-Host "Users berhasil dibuat." -ForegroundColor Green
    } else {
        Write-Host "Seeder gagal — cek koneksi DB." -ForegroundColor Red
    }
} else {
    Write-Host "PHP tidak ditemukan di $PHP_EXE" -ForegroundColor Yellow
    Write-Host "Jalankan manual: php seeds\rehash_users.php" -ForegroundColor Yellow
}

# ── 5. Verifikasi tabel ───────────────────────────────────────────────────────
Write-Host ""
Write-Host "=== LANGKAH 5: Verifikasi tabel ===" -ForegroundColor Cyan
& $PSQL_CMD -U postgres -d prod_admin -c @"
SELECT tablename AS tabel,
       (SELECT COUNT(*) FROM information_schema.columns
        WHERE table_name = t.tablename AND table_schema = 'public') AS kolom
FROM pg_tables t
WHERE schemaname = 'public'
ORDER BY tablename;
"@

Write-Host ""
Write-Host "=== VERIFIKASI USERS ===" -ForegroundColor Cyan
& $PSQL_CMD -U postgres -d prod_admin -c "SELECT username, role FROM users ORDER BY role DESC, username;"

Write-Host ""
Write-Host "=== VERIFIKASI SETTINGS ===" -ForegroundColor Cyan
& $PSQL_CMD -U postgres -d prod_admin -c "SELECT key, value FROM settings ORDER BY key;"

# ── Selesai ───────────────────────────────────────────────────────────────────
$env:PGPASSWORD = ""
Write-Host ""
Write-Host "================================================================" -ForegroundColor Green
Write-Host "  DATABASE SETUP SELESAI" -ForegroundColor Green
Write-Host "  DB: prod_admin | User: postgres | Pass: SASMU123" -ForegroundColor Green
Write-Host "================================================================" -ForegroundColor Green
Write-Host ""
Write-Host "Langkah selanjutnya:" -ForegroundColor Cyan
Write-Host "  1. Copy file ke XAMPP:  .\deploy_to_xampp.ps1" -ForegroundColor White
Write-Host "  2. Start Apache di XAMPP Control Panel" -ForegroundColor White
Write-Host "  3. Buka browser: http://localhost/ProdAdmin/" -ForegroundColor White
Write-Host ""
