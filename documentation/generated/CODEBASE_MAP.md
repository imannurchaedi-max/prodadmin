# Codebase Map

Generated at: `2026-07-25T05:16:30.915909+00:00`
Repo root: `C:\xampp\htdocs\ProdAdmin`

## GitNexus Snapshot

- Files: `75`
- Nodes: `1851`
- Edges: `2977`
- Communities: `109`
- Processes: `116`
- Embeddings: `0`
- Vector mode: `unavailable` via `exact-scan`

## Layer Summary

### root_runtime
- Files: `2`
- Total lines: `760`
- Largest files: `index.php` (759 lines), `.htaccess` (1 lines)

### other
- Files: `34`
- Total lines: `9258`
- Largest files: `python_bot/setup.php` (969 lines), `python_bot/setup_ASUSVIVO_May-26-130308-2026_Conflict.php` (959 lines), `python_bot/langgraph_trace_center.py` (767 lines)

### project_docs
- Files: `2`
- Total lines: `180`
- Largest files: `documentation/TRACE_CENTER.md` (129 lines), `CLAUDE.md` (51 lines)

### backend_api
- Files: `14`
- Total lines: `3559`
- Largest files: `api/transactions_ASUSVIVO_May-26-130326-2026_Conflict.php` (579 lines), `api/auth.php` (561 lines), `api/transactions.php` (559 lines)

### frontend_assets
- Files: `10`
- Total lines: `406`
- Largest files: `assets/style.css` (315 lines), `assets/xlsx.full.min.js` (22 lines), `assets/html2canvas.min.js` (20 lines)

### backend_config
- Files: `2`
- Total lines: `154`
- Largest files: `config/auth_helper.php` (92 lines), `config/database.php` (62 lines)

### documentation
- Files: `10`
- Total lines: `2057`
- Largest files: `docs/generated/CHARACTER_AUDIT.json` (732 lines), `docs/blast-radius.md` (275 lines), `docs/io-dependency.md` (230 lines)

### database_sql
- Files: `2`
- Total lines: `280`
- Largest files: `sql/setup_fresh.sql` (199 lines), `sql/migration_v2_scale100.sql` (81 lines)

### operations_tools
- Files: `5`
- Total lines: `2327`
- Largest files: `tools/setup.php` (948 lines), `tools/smoke.php` (450 lines), `tools/migrate_photos.php` (442 lines)

### frontend_app
- Files: `4`
- Total lines: `2522`
- Largest files: `assets/app/form.js` (1716 lines), `assets/app/auth.js` (395 lines), `assets/app/admin.js` (278 lines)

## API Surface

- `api/admin.php`: `changePassword`, `logs`, `photoProgress`, `stats`, `verifyAdmin`
- `api/auth.php`: `changePassword`, `checkTakeover`, `forceLogin`, `login`, `logout`, `takeoverDecision`, `takeoverStatus`, `validate`
- `api/auth_ASUSVIVO_May-26-130322-2026_Conflict.php`: `changePassword`, `checkTakeover`, `forceLogin`, `login`, `logout`, `takeoverDecision`, `takeoverStatus`, `validate`
- `api/config.php`: `get`, `save`
- `api/conversions.php`: `delete`, `list`, `save`
- `api/history.php`: `admin`, `paged`
- `api/history_ASUSVIVO_May-26-130325-2026_Conflict.php`: `admin`, `paged`
- `api/init.php`: `(route parsing not detected)`
- `api/materials.php`: `delete`, `list`, `saveList`, `update`
- `api/migration_api.php`: `photo_batch`, `photo_retry`, `photo_stats`, `progress`, `reset_progress`, `run_import`, `run_setup_photos`, `upload`
- `api/photos.php`: `(route parsing not detected)`
- `api/settings.php`: `(route parsing not detected)`
- `api/transactions.php`: `delete`, `diff`, `finalize`, `previousStock`, `revise`, `submit`
- `api/transactions_ASUSVIVO_May-26-130326-2026_Conflict.php`: `delete`, `diff`, `finalize`, `previousStock`, `revise`, `submit`

## Runtime Entrypoints

- `index.php`
- `api/*.php`
- `assets/app/*.js`
- `config/database.php`
- `tools/setup.php`

## Top Files

- `assets/app/form.js` - 1716 lines (frontend_app)
- `python_bot/setup.php` - 969 lines (other)
- `python_bot/setup_ASUSVIVO_May-26-130308-2026_Conflict.php` - 959 lines (other)
- `tools/setup.php` - 948 lines (operations_tools)
- `python_bot/langgraph_trace_center.py` - 767 lines (other)
- `index.php` - 759 lines (root_runtime)
- `docs/generated/CHARACTER_AUDIT.json` - 732 lines (documentation)
- `index_ASUSVIVO_May-26-130321-2026_Conflict.php` - 695 lines (other)
- `python_bot/langgraph_function_db_map.py` - 610 lines (other)
- `api/transactions_ASUSVIVO_May-26-130326-2026_Conflict.php` - 579 lines (backend_api)
- `api/auth.php` - 561 lines (backend_api)
- `api/transactions.php` - 559 lines (backend_api)
- `python_bot/langgraph_dynamic_audit.py` - 535 lines (other)
- `import_excel.py` - 512 lines (other)
- `python_bot/import_excel.py` - 512 lines (other)

## Observations

- Repo menggabungkan aplikasi PHP runtime, dokumen migrasi, aset legacy GAS, SQL schema/migration, dan seed/testing scripts.
- Lapisan runtime utama berada di index.php, api/, config/, dan assets/app/.
- Lapisan referensi migrasi masih penting karena Kode.gs dan file HTML GAS dipakai sebagai sumber kebenaran historis.
- GitNexus sudah menyediakan graph + embeddings lokal; LangGraph di sini dipakai sebagai orchestrator mapping agar output bisa dijalankan ulang.
