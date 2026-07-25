# IO to DB Connection Map

Generated at: `2026-07-07T06:01:07.956019+00:00`

## Coverage

- IO->DB contracts: `23`
- DB tables touched: `15`

### api/admin.php::logs::POST
- Backend: `api/admin.php::action:logs`
- Input keys: `-`
- Response keys: `-`
- Table `audit_logs` ops `SELECT`
- Table `settings` ops `SELECT`

### api/admin.php::photoProgress::GET
- Backend: `api/admin.php::action:photoProgress`
- Input keys: `-`
- Response keys: `doneCount`, `failedCount`, `firstAnyDone`, `firstFullyDone`, `fullDays`, `lastAnyDone`, `lastFullyDone`, `latestDays`, `partialDays`, `pendingCount`, `progressPct`, `range`, `summary`, `totalCount`
- Table `photo_migration` ops `SELECT`
- Table `photo_migration_map` ops `JOIN`
- Table `transaction_materials` ops `JOIN`
- Table `transactions` ops `JOIN`

### api/admin.php::verifyAdmin::POST
- Backend: `api/admin.php::action:verifyAdmin`
- Input keys: `-`
- Response keys: `-`
- Table `settings` ops `SELECT`

### api/auth.php::changePassword::POST
- Backend: `api/auth.php::actionChangePassword`
- Input keys: `newPass`, `oldPass`, `username`
- Response keys: `-`
- Table `users` ops `SELECT`, `UPDATE`

### api/auth.php::logout::POST
- Backend: `api/auth.php::actionLogout`
- Input keys: `machine`
- Response keys: `-`
- Table `sessions` ops `DELETE`, `SELECT`
- Table `takeover_requests` ops `DELETE`

### api/auth.php::validate::GET
- Backend: `api/auth.php::actionValidate`
- Input keys: `token`
- Response keys: `isAdmin`, `username`
- Table `sessions` ops `CALL` via `api/auth.php::validateToken`
- Table `users` ops `CALL` via `api/auth.php::validateToken`

### api/config.php::get::GET
- Backend: `api/config.php::action:get`
- Input keys: `-`
- Response keys: `aliases`, `aliasesRaw`, `mesin`, `mesinRaw`, `size`, `sizeRaw`
- Table `settings` ops `REFERENCE`

### api/config.php::save::POST
- Backend: `api/config.php::action:save`
- Input keys: `-`
- Response keys: `-`
- Table `sessions` ops `CALL` via `config/auth_helper.php::requireSession`
- Table `settings` ops `INSERT`
- Table `users` ops `CALL` via `config/auth_helper.php::requireSession`

### api/conversions.php::delete::POST
- Backend: `api/conversions.php::action:delete`
- Input keys: `-`
- Response keys: `-`
- Table `conversions` ops `DELETE`

### api/conversions.php::list::GET
- Backend: `api/conversions.php::action:list`
- Input keys: `-`
- Response keys: `-`
- Table `conversions` ops `SELECT`

### api/conversions.php::save::POST
- Backend: `api/conversions.php::action:save`
- Input keys: `-`
- Response keys: `-`
- Table `conversions` ops `DELETE`, `INSERT`, `SELECT`

### api/history.php::paged::GET
- Backend: `api/history.php::action:paged`
- Input keys: `-`
- Response keys: `data`, `page`, `total`, `totalPages`
- Table `material_hourly_usage` ops `SELECT`
- Table `production_outputs` ops `SELECT`
- Table `production_reports` ops `SELECT`
- Table `transaction_materials` ops `JOIN`
- Table `transactions` ops `JOIN`, `SELECT`

### api/init.php::__default__::GET
- Backend: `api/init.php::__file_scope__`
- Input keys: `-`
- Response keys: `suppliers`, `conversion`, `config`
- Table `conversions` ops `SELECT`
- Table `material_suppliers` ops `JOIN`
- Table `sessions` ops `CALL` via `config/auth_helper.php::requireSession`
- Table `settings` ops `SELECT`
- Table `suppliers` ops `SELECT`
- Table `users` ops `CALL` via `config/auth_helper.php::requireSession`

### api/materials.php::delete::POST
- Backend: `api/materials.php::action:delete`
- Input keys: `-`
- Response keys: `-`
- Table `suppliers` ops `DELETE`

### api/materials.php::saveList::POST
- Backend: `api/materials.php::action:saveList`
- Input keys: `-`
- Response keys: `-`
- Table `suppliers` ops `INSERT`

### api/materials.php::update::POST
- Backend: `api/materials.php::action:update`
- Input keys: `-`
- Response keys: `-`
- Table `material_suppliers` ops `DELETE`, `INSERT`
- Table `suppliers` ops `INSERT`, `SELECT`, `UPDATE`

### api/photos.php::__default__::POST
- Backend: `api/photos.php::__file_scope__`
- Input keys: `-`
- Response keys: `-`
- Table link: `-`

### api/settings.php::__default__::GET
- Backend: `api/settings.php::method:GET`
- Input keys: `-`
- Response keys: `broadcastActive`, `broadcastMsg`, `enableHandover`, `lockDate`
- Table `settings` ops `SELECT`

### api/settings.php::__default__::POST
- Backend: `api/settings.php::method:POST`
- Input keys: `-`
- Response keys: `-`
- Table `sessions` ops `CALL` via `config/auth_helper.php::requireSession`
- Table `settings` ops `INSERT`
- Table `users` ops `CALL` via `config/auth_helper.php::requireSession`

### api/transactions.php::delete::POST
- Backend: `api/transactions.php::actionDelete`
- Input keys: `-`
- Response keys: `-`
- Table `audit_logs` ops `CALL` via `api/transactions.php::logAction`
- Table `production_outputs` ops `UPDATE`
- Table `production_reports` ops `UPDATE`
- Table `sessions` ops `CALL` via `config/auth_helper.php::requireSession`
- Table `transactions` ops `UPDATE`
- Table `users` ops `CALL` via `config/auth_helper.php::requireSession`

### api/transactions.php::diff::GET
- Backend: `api/transactions.php::actionDiff`
- Input keys: `uuid`
- Response keys: `-`
- Table `production_outputs` ops `SELECT`
- Table `production_reports` ops `SELECT`
- Table `sessions` ops `CALL` via `config/auth_helper.php::requireSession`
- Table `transaction_materials` ops `JOIN`
- Table `transactions` ops `SELECT`
- Table `users` ops `CALL` via `config/auth_helper.php::requireSession`

### api/transactions.php::finalize::POST
- Backend: `api/transactions.php::actionFinalize`
- Input keys: `-`
- Response keys: `-`
- Table `audit_logs` ops `CALL` via `api/transactions.php::logAction`
- Table `sessions` ops `CALL` via `config/auth_helper.php::requireSession`
- Table `transactions` ops `SELECT`, `UPDATE`
- Table `users` ops `CALL` via `config/auth_helper.php::requireSession`

### api/transactions.php::previousStock::GET
- Backend: `api/transactions.php::actionPreviousStock`
- Input keys: `date`, `mesin`, `shift`
- Response keys: `-`
- Table `sessions` ops `CALL` via `config/auth_helper.php::requireSession`
- Table `transaction_materials` ops `JOIN`
- Table `transactions` ops `SELECT`
- Table `users` ops `CALL` via `config/auth_helper.php::requireSession`
