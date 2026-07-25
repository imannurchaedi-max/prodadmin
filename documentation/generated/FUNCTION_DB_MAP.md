# Function vs DB Map

Generated at: `2026-07-25T05:16:43.682344+00:00`
Repo root: `C:\xampp\htdocs\ProdAdmin`

## Coverage

- Tables in schema: `15`
- PHP/tool blocks scanned: `179`
- Blocks with DB links: `80`
- Blocks without DB links: `99`
- Direct function-to-table links: `142`
- Inherited function-to-table links: `72`
- Effective function-to-table links: `214`
- Call edges analyzed: `283`

## Critical Backend Files

### active/api/auth.php
- No DB-linked function detected.

### active/api/transactions.php
- No DB-linked function detected.

### active/api/history.php
- No DB-linked function detected.

### active/api/admin.php
- No DB-linked function detected.

### active/api/materials.php
- No DB-linked function detected.

### active/api/settings.php
- No DB-linked function detected.

### active/api/conversions.php
- No DB-linked function detected.

### active/api/init.php
- No DB-linked function detected.

### active/api/photos.php
- No DB-linked function detected.

### active/config/auth_helper.php
- No DB-linked function detected.

### python_bot/setup.php
- `__file_scope__` -> `conversions`, `material_hourly_usage`, `material_suppliers`, `photo_migration`, `photo_migration_map`, `production_outputs`, `production_reports`, `suppliers`, `transaction_materials`, `transactions`, `users` (`11` tables; direct `11`)
- `act` -> `users` (`1` tables; direct `1`)

### python_bot/migrate_photos.php
- No DB-linked function detected.

## Orphan Tables

- None

## Heaviest Function/Table Links

- `python_bot/setup.php::__file_scope__` -> `conversions`, `material_hourly_usage`, `material_suppliers`, `photo_migration`, `photo_migration_map`, `production_outputs`, `production_reports`, `suppliers`, `transaction_materials`, `transactions`, `users`
- `api/transactions.php::actionSubmit` -> `audit_logs`, `material_hourly_usage`, `production_outputs`, `production_reports`, `sessions`, `settings`, `transaction_materials`, `transactions`, `users`
- `api/transactions.php::actionRevise` -> `audit_logs`, `material_hourly_usage`, `production_outputs`, `production_reports`, `sessions`, `settings`, `transaction_materials`, `transactions`, `users`
- `api/transactions_ASUSVIVO_May-26-130326-2026_Conflict.php::actionSubmit` -> `audit_logs`, `material_hourly_usage`, `production_outputs`, `production_reports`, `sessions`, `settings`, `transaction_materials`, `transactions`, `users`
- `api/transactions_ASUSVIVO_May-26-130326-2026_Conflict.php::actionRevise` -> `audit_logs`, `material_hourly_usage`, `production_outputs`, `production_reports`, `sessions`, `settings`, `transaction_materials`, `transactions`, `users`
- `api/transactions_ASUSVIVO_May-26-130326-2026_Conflict.php::actionDelete` -> `audit_logs`, `production_outputs`, `production_reports`, `sessions`, `transaction_materials`, `transactions`, `users`
- `api/init.php::__file_scope__` -> `conversions`, `material_suppliers`, `sessions`, `settings`, `suppliers`, `users`
- `api/transactions.php::actionDelete` -> `audit_logs`, `production_outputs`, `production_reports`, `sessions`, `transactions`, `users`
- `api/transactions.php::actionDiff` -> `production_outputs`, `production_reports`, `sessions`, `transaction_materials`, `transactions`, `users`
- `api/transactions_ASUSVIVO_May-26-130326-2026_Conflict.php::actionDiff` -> `production_outputs`, `production_reports`, `sessions`, `transaction_materials`, `transactions`, `users`
- `api/history.php::action:paged` -> `material_hourly_usage`, `production_outputs`, `production_reports`, `transaction_materials`, `transactions`
- `api/history_ASUSVIVO_May-26-130325-2026_Conflict.php::action:paged` -> `material_hourly_usage`, `production_outputs`, `production_reports`, `transaction_materials`, `transactions`
- `api/transactions.php::insertTransaction` -> `material_hourly_usage`, `production_outputs`, `production_reports`, `transaction_materials`, `transactions`
- `api/transactions_ASUSVIVO_May-26-130326-2026_Conflict.php::insertTransaction` -> `material_hourly_usage`, `production_outputs`, `production_reports`, `transaction_materials`, `transactions`
- `api/admin.php::action:photoProgress` -> `photo_migration`, `photo_migration_map`, `transaction_materials`, `transactions`

## GitNexus Context Checks

- `actionSubmit` unresolved or not found
- `actionRevise` unresolved or not found
- `actionFinalize` unresolved or not found
- `actionLogin` unresolved or not found
- `loadAdminScorecard` unresolved or not found
- `renderAdminCharts` unresolved or not found

## Notes

- High confidence berarti ada pola SQL eksplisit seperti SELECT/INSERT/UPDATE/DELETE/JOIN yang mengarah ke tabel schema final.
- Medium confidence berarti fungsi mereferensikan nama tabel tanpa operasi SQL eksplisit, biasanya pada helper, flow migrasi, atau query yang dibangun dinamis.
- Inherited berarti blok tidak men-query tabel secara langsung, tetapi memanggil function lain yang memiliki link DB.
- Mapper ini fokus pada file PHP runtime dan tools setup/migration. Frontend JS tidak dihitung sebagai pemilik akses DB langsung.
