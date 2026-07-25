# Trace Center

Dokumen ini adalah titik masuk utama untuk error tracing PROD3. Pakai ini dulu sebelum baca file satu-satu.

Generated at: `2026-07-25T05:17:07.849903+00:00`
Refreshed after: `2026-07-18 12:17` local scan baseline

## Canonical Artifacts

- `documentation/generated/FUNCTION_DEPENDENCY_MAP.md`: peta function -> function -> endpoint.
- `documentation/generated/IO_DEPENDENCY_MAP.md`: peta frontend consumer -> endpoint -> backend input/output contract.
- `documentation/generated/FUNCTION_DB_MAP.md`: peta function/backend block -> tabel DB.
- `documentation/generated/IO_DB_CONNECTION_MAP.md`: peta endpoint/input/output -> backend block -> tabel DB.
- `documentation/generated/ENDPOINT_UI_MAP.md`: audit kontrak endpoint vs consumer UI.
- `documentation/generated/DYNAMIC_AUDIT_RUN.md`: hasil audit runtime localhost.

## How To Trace Errors

1. Tentukan gejalanya dulu: login, history, submit, admin, photo, atau setup.
2. Buka `IO_DEPENDENCY_MAP.md` untuk lihat endpoint yang dipanggil UI dan input/output yang diharapkan.
3. Buka `IO_DB_CONNECTION_MAP.md` untuk lihat endpoint itu menyentuh tabel mana.
4. Jika perlu detail lebih dalam, buka `FUNCTION_DEPENDENCY_MAP.md` dan `FUNCTION_DB_MAP.md` untuk lihat helper/function apa yang dipanggil di bawahnya.
5. Gunakan hasil `gitnexus context` pada symbol inti untuk validasi callers/callees sebelum menyimpulkan akar masalah.

## Symptom Entry Points

### Login / takeover / session
- `api/auth.php`
- `generated/IO_DB_CONNECTION_MAP.md`
- `generated/FUNCTION_DEPENDENCY_MAP.md`

### History / table / photo / loss / berat
- `api/history.php`
- `generated/IO_DB_CONNECTION_MAP.md`
- `generated/IO_DEPENDENCY_MAP.md`

### Submit / revise / finalize
- `api/transactions.php`
- `generated/FUNCTION_DB_MAP.md`
- `generated/IO_DB_CONNECTION_MAP.md`

## Risk Endpoints

- `api/admin.php::logs::POST`
- `api/admin.php::photoProgress::GET`
- `api/admin.php::verifyAdmin::POST`
- `api/auth.php::changePassword::POST`
- `api/auth.php::logout::POST`
- `api/auth.php::validate::GET`
- `api/config.php::get::GET`
- `api/config.php::save::POST`
- `api/conversions.php::delete::POST`
- `api/conversions.php::list::GET`
- `api/conversions.php::save::POST`
- `api/history.php::paged::GET`
- `api/init.php::__default__::GET`
- `api/materials.php::delete::POST`
- `api/materials.php::list::GET`
- `api/materials.php::saveList::POST`
- `api/materials.php::update::POST`
- `api/photos.php::__default__::POST`
- `api/settings.php::__default__::GET`
- `api/settings.php::__default__::POST`

## GitNexus Impact Snapshots

### actionLogin
```text
{
  "status": "ambiguous",
  "message": "Found 3 symbols matching 'actionLogin'. Use target_uid, file_path, or kind to disambiguate.",
  "target": {
    "name": "actionLogin"
  },
  "direction": "upstream",
  "impactedCount": 0,
  "risk": "UNKNOWN",
  "candidates": [
    {
      "uid": "Function:api/auth.php:actionLogin",
```

### actionSubmit
```text
{
  "status": "ambiguous",
  "message": "Found 3 symbols matching 'actionSubmit'. Use target_uid, file_path, or kind to disambiguate.",
  "target": {
    "name": "actionSubmit"
  },
  "direction": "upstream",
  "impactedCount": 0,
  "risk": "UNKNOWN",
  "candidates": [
    {
      "uid": "Function:api/transactions.php:actionSubmit",
```

### loadHistory
```text
{
  "target": {
    "id": "Section:documentation/TRACE_CENTER.md:L99:loadHistory",
    "name": "loadHistory",
    "type": "",
    "filePath": "documentation/TRACE_CENTER.md"
  },
  "direction": "upstream",
  "impactedCount": 0,
  "risk": "LOW",
  "summary": {
    "direct": 0,
```

### loadInitialData
```text
{
  "target": {
    "id": "Section:documentation/TRACE_CENTER.md:L115:loadInitialData",
    "name": "loadInitialData",
    "type": "",
    "filePath": "documentation/TRACE_CENTER.md"
  },
  "direction": "upstream",
  "impactedCount": 0,
  "risk": "LOW",
  "summary": {
    "direct": 0,
```
