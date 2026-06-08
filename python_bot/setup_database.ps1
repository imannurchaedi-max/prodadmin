# setup_database.ps1
# Jalankan setelah PostgreSQL terinstall.
# Membuat database ProdAdmin dari nol dengan konfigurasi dari environment
# PRODADMIN_DB_* atau fallback default lokal.

param(
    [string]$PgPass = "",
    [string]$DbName = "",
    [string]$DbUser = "",
    [string]$DbHost = "",
    [int]$DbPort = 0
)

if (-not $PgPass) { $PgPass = if ($env:PRODADMIN_DB_PASS) { $env:PRODADMIN_DB_PASS } else { "SASMU123" } }
if (-not $DbName) { $DbName = if ($env:PRODADMIN_DB_NAME) { $env:PRODADMIN_DB_NAME } else { "prod_admin" } }
if (-not $DbUser) { $DbUser = if ($env:PRODADMIN_DB_USER) { $env:PRODADMIN_DB_USER } else { "postgres" } }
if (-not $DbHost) { $DbHost = if ($env:PRODADMIN_DB_HOST) { $env:PRODADMIN_DB_HOST } else { "localhost" } }
if (-not $DbPort) { $DbPort = if ($env:PRODADMIN_DB_PORT) { [int]$env:PRODADMIN_DB_PORT } else { 5432 } }

$candidates = @(
    'C:\Program Files\PostgreSQL\18\bin\psql.exe',
    'C:\Program Files\PostgreSQL\17\bin\psql.exe',
    'C:\Program Files\PostgreSQL\16\bin\psql.exe',
    'C:\Program Files\PostgreSQL\15\bin\psql.exe',
    'C:\Program Files\PostgreSQL\14\bin\psql.exe'
)

$PSQL = $candidates | Where-Object { Test-Path $_ } | Select-Object -First 1
if (-not $PSQL) {
    try { $PSQL = (Get-Command psql -ErrorAction Stop).Source } catch {}
}

if (-not $PSQL) {
    Write-Host ""
    Write-Host "ERROR: psql.exe tidak ditemukan." -ForegroundColor Red
    Write-Host "Pastikan PostgreSQL sudah terinstall atau set PRODADMIN_PSQL." -ForegroundColor Yellow
    exit 1
}

$PSQL_DIR = Split-Path $PSQL
$CREATEDB = Join-Path $PSQL_DIR 'createdb.exe'
$PSQL_CMD = $PSQL

Write-Host ""
Write-Host "psql ditemukan: $PSQL_CMD" -ForegroundColor Green
& $PSQL_CMD --version

$env:PGPASSWORD = $PgPass

$SCRIPT_DIR = Split-Path $MyInvocation.MyCommand.Path
$SCHEMA_SQL = Join-Path $SCRIPT_DIR '..\active\sql\setup_fresh.sql'
$PHP_SEEDER = Join-Path $SCRIPT_DIR 'rehash_users.php'
$PHP_EXE = 'C:\xampp\php\php.exe'

Write-Host ""
Write-Host "=== LANGKAH 1: Buat database $DbName ===" -ForegroundColor Cyan
$exists = & $PSQL_CMD -h $DbHost -p $DbPort -U $DbUser -tAc "SELECT 1 FROM pg_database WHERE datname='$DbName'" postgres 2>&1
if ($exists -match '1') {
    Write-Host "Database $DbName sudah ada, lanjut ke schema..." -ForegroundColor Yellow
} else {
    & $CREATEDB -h $DbHost -p $DbPort -U $DbUser -E UTF8 --lc-collate=C --lc-ctype=C -T template0 $DbName
    if ($LASTEXITCODE -eq 0) {
        Write-Host "Database $DbName berhasil dibuat." -ForegroundColor Green
    } else {
        Write-Host "GAGAL membuat database." -ForegroundColor Red
        exit 1
    }
}

Write-Host ""
Write-Host "=== LANGKAH 2: Aktifkan ekstensi ===" -ForegroundColor Cyan
& $PSQL_CMD -h $DbHost -p $DbPort -U $DbUser -d $DbName -c "CREATE EXTENSION IF NOT EXISTS pgcrypto;"
if ($LASTEXITCODE -ne 0) {
    Write-Host "pgcrypto gagal, coba uuid-ossp..." -ForegroundColor Yellow
    & $PSQL_CMD -h $DbHost -p $DbPort -U $DbUser -d $DbName -c "CREATE EXTENSION IF NOT EXISTS `"uuid-ossp`";"
}
Write-Host "Ekstensi OK." -ForegroundColor Green

Write-Host ""
Write-Host "=== LANGKAH 3: Jalankan setup_fresh.sql ===" -ForegroundColor Cyan
& $PSQL_CMD -h $DbHost -p $DbPort -U $DbUser -d $DbName -f $SCHEMA_SQL
if ($LASTEXITCODE -eq 0) {
    Write-Host "Schema berhasil dibuat." -ForegroundColor Green
} else {
    Write-Host "GAGAL menjalankan schema SQL." -ForegroundColor Red
    exit 1
}

Write-Host ""
Write-Host "=== LANGKAH 4: Seed users (bcrypt) ===" -ForegroundColor Cyan
if (Test-Path $PHP_EXE) {
    & $PHP_EXE $PHP_SEEDER
    if ($LASTEXITCODE -eq 0) {
        Write-Host "Users berhasil dibuat." -ForegroundColor Green
    } else {
        Write-Host "Seeder gagal, cek koneksi DB." -ForegroundColor Red
    }
} else {
    Write-Host "PHP tidak ditemukan di $PHP_EXE" -ForegroundColor Yellow
}

Write-Host ""
Write-Host "=== LANGKAH 5: Verifikasi tabel ===" -ForegroundColor Cyan
& $PSQL_CMD -h $DbHost -p $DbPort -U $DbUser -d $DbName -c @"
SELECT tablename AS tabel,
       (SELECT COUNT(*) FROM information_schema.columns
        WHERE table_name = t.tablename AND table_schema = 'public') AS kolom
FROM pg_tables t
WHERE schemaname = 'public'
ORDER BY tablename;
"@

Write-Host ""
Write-Host "=== VERIFIKASI USERS ===" -ForegroundColor Cyan
& $PSQL_CMD -h $DbHost -p $DbPort -U $DbUser -d $DbName -c "SELECT username, role FROM users ORDER BY role DESC, username;"

Write-Host ""
Write-Host "=== VERIFIKASI SETTINGS ===" -ForegroundColor Cyan
& $PSQL_CMD -h $DbHost -p $DbPort -U $DbUser -d $DbName -c "SELECT key, value FROM settings ORDER BY key;"

$env:PGPASSWORD = ""
Write-Host ""
Write-Host "================================================================" -ForegroundColor Green
Write-Host "  DATABASE SETUP SELESAI" -ForegroundColor Green
Write-Host "  DB: $DbName | User: $DbUser | Host: $DbHost | Port: $DbPort" -ForegroundColor Green
Write-Host "================================================================" -ForegroundColor Green
