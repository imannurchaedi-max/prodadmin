# Codebase Map

Generated at: `2026-07-07T05:58:15.599648+00:00`
Repo root: `C:\xampp\htdocs\ProdAdmin\python_bot`

## Layer Summary

### other
- Files: `25`
- Total lines: `7363`
- Largest files: `setup.php` (969 lines), `setup_ASUSVIVO_May-26-130308-2026_Conflict.php` (959 lines), `langgraph_trace_center.py` (767 lines)

### root_runtime
- Files: `1`
- Total lines: `111`
- Largest files: `deploy_to_xampp.ps1` (111 lines)

## API Surface

- No API actions detected.

## Runtime Entrypoints

- `index.php`
- `api/*.php`
- `assets/app/*.js`
- `config/database.php`
- `tools/setup.php`

## Top Files

- `setup.php` - 969 lines (other)
- `setup_ASUSVIVO_May-26-130308-2026_Conflict.php` - 959 lines (other)
- `langgraph_trace_center.py` - 767 lines (other)
- `langgraph_function_db_map.py` - 610 lines (other)
- `langgraph_dynamic_audit.py` - 535 lines (other)
- `import_excel.py` - 512 lines (other)
- `langgraph_endpoint_ui_map.py` - 478 lines (other)
- `langgraph_audit_protocol.py` - 405 lines (other)
- `langgraph_codebase_map.py` - 377 lines (other)
- `setup_photo_migration.py` - 214 lines (other)
- `test_e2e.py` - 190 lines (other)
- `test_transactions.php` - 154 lines (other)
- `character_audit.py` - 149 lines (other)
- `setup_database_ASUSVIVO_May-26-130315-2026_Conflict.ps1` - 142 lines (other)
- `test_transactions_ASUSVIVO_May-26-130309-2026_Conflict.php` - 136 lines (other)

## Observations

- Repo menggabungkan aplikasi PHP runtime, dokumen migrasi, aset legacy GAS, SQL schema/migration, dan seed/testing scripts.
- Lapisan runtime utama berada di index.php, api/, config/, dan assets/app/.
- Lapisan referensi migrasi masih penting karena Kode.gs dan file HTML GAS dipakai sebagai sumber kebenaran historis.
- GitNexus sudah menyediakan graph + embeddings lokal; LangGraph di sini dipakai sebagai orchestrator mapping agar output bisa dijalankan ulang.
