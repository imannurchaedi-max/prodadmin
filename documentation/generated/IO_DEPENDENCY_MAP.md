# Input Output Dependency Map

Generated at: `2026-07-07T06:01:07.956019+00:00`

## Coverage

- Endpoint contracts: `23`

## Contracts

### api/admin.php::logs::POST
- Frontend consumers: `assets/app/admin.js::loadAuditLogs`
- Frontend input keys: `body`, `method`
- Backend file: `api/admin.php`
- Backend block: `action:logs`
- Backend input keys: `-`
- Backend response keys: `-`

### api/admin.php::photoProgress::GET
- Frontend consumers: `assets/app/admin.js::loadPhotoMigrationProgress`
- Frontend input keys: `method`
- Backend file: `api/admin.php`
- Backend block: `action:photoProgress`
- Backend input keys: `-`
- Backend response keys: `doneCount`, `failedCount`, `firstAnyDone`, `firstFullyDone`, `fullDays`, `lastAnyDone`, `lastFullyDone`, `latestDays`, `partialDays`, `pendingCount`, `progressPct`, `range`, `summary`, `totalCount`

### api/admin.php::verifyAdmin::POST
- Frontend consumers: `assets/app/auth.js::showAdminLogin`
- Frontend input keys: `Authorization`, `body`, `headers`, `method`
- Backend file: `api/admin.php`
- Backend block: `action:verifyAdmin`
- Backend input keys: `-`
- Backend response keys: `-`

### api/auth.php::changePassword::POST
- Frontend consumers: `assets/app/admin.js::openChangePassword`
- Frontend input keys: `body`, `method`, `newPass`, `oldPass`, `username`
- Backend file: `api/auth.php`
- Backend block: `actionChangePassword`
- Backend input keys: `newPass`, `oldPass`, `username`
- Backend response keys: `-`

### api/auth.php::logout::POST
- Frontend consumers: `assets/app/auth.js::handleLogout`
- Frontend input keys: `body`, `machine`, `method`, `username`
- Backend file: `api/auth.php`
- Backend block: `actionLogout`
- Backend input keys: `machine`
- Backend response keys: `-`

### api/auth.php::validate::GET
- Frontend consumers: `assets/app/auth.js::validateExistingSession`
- Frontend input keys: `method`
- Backend file: `api/auth.php`
- Backend block: `actionValidate`
- Backend input keys: `token`
- Backend response keys: `isAdmin`, `username`

### api/config.php::get::GET
- Frontend consumers: `assets/app/auth.js::loadLoginMachines`
- Frontend input keys: `headers`, `selected`
- Backend file: `api/config.php`
- Backend block: `action:get`
- Backend input keys: `-`
- Backend response keys: `aliases`, `aliasesRaw`, `mesin`, `mesinRaw`, `size`, `sizeRaw`

### api/config.php::save::POST
- Frontend consumers: `assets/app/admin.js::saveMachineSizeConfig`
- Frontend input keys: `body`, `method`
- Backend file: `api/config.php`
- Backend block: `action:save`
- Backend input keys: `-`
- Backend response keys: `-`

### api/conversions.php::delete::POST
- Frontend consumers: `assets/app/admin.js::renderConversionTable`
- Frontend input keys: `body`, `method`
- Backend file: `api/conversions.php`
- Backend block: `action:delete`
- Backend input keys: `-`
- Backend response keys: `-`

### api/conversions.php::list::GET
- Frontend consumers: `assets/app/admin.js::saveConversionData`
- Frontend input keys: `method`
- Backend file: `api/conversions.php`
- Backend block: `action:list`
- Backend input keys: `-`
- Backend response keys: `-`

### api/conversions.php::save::POST
- Frontend consumers: `assets/app/admin.js::saveConversionData`
- Frontend input keys: `body`, `method`
- Backend file: `api/conversions.php`
- Backend block: `action:save`
- Backend input keys: `-`
- Backend response keys: `-`

### api/history.php::paged::GET
- Frontend consumers: `assets/app/form.js::loadHistory`, `assets/app/form.js::loadMoreHistory`
- Frontend input keys: `limit`, `method`, `page`
- Backend file: `api/history.php`
- Backend block: `action:paged`
- Backend input keys: `-`
- Backend response keys: `data`, `page`, `total`, `totalPages`

### api/init.php::__default__::GET
- Frontend consumers: `assets/app/form.js::loadInitialData`
- Frontend input keys: `map`, `method`, `order`
- Backend file: `api/init.php`
- Backend block: `-`
- Backend input keys: `-`
- Backend response keys: `suppliers`, `conversion`, `config`

### api/materials.php::delete::POST
- Frontend consumers: `assets/app/admin.js::renderMaterialList`
- Frontend input keys: `body`, `method`
- Backend file: `api/materials.php`
- Backend block: `action:delete`
- Backend input keys: `-`
- Backend response keys: `-`

### api/materials.php::saveList::POST
- Frontend consumers: `assets/app/admin.js::saveMaterialChanges`
- Frontend input keys: `body`, `method`
- Backend file: `api/materials.php`
- Backend block: `action:saveList`
- Backend input keys: `-`
- Backend response keys: `-`

### api/materials.php::update::POST
- Frontend consumers: `assets/app/admin.js::addNewMaterial`
- Frontend input keys: `body`, `method`, `newName`, `oldName`, `suppliers`
- Backend file: `api/materials.php`
- Backend block: `action:update`
- Backend input keys: `-`
- Backend response keys: `-`

### api/photos.php::__default__::POST
- Frontend consumers: `assets/app/form.js::openPhotoModal`
- Frontend input keys: `body`, `files`, `method`
- Backend file: `api/photos.php`
- Backend block: `-`
- Backend input keys: `-`
- Backend response keys: `-`

### api/settings.php::__default__::GET
- Frontend consumers: `assets/app/form.js::loadInitialData`
- Frontend input keys: `method`
- Backend file: `api/settings.php`
- Backend block: `method:GET`
- Backend input keys: `-`
- Backend response keys: `broadcastActive`, `broadcastMsg`, `enableHandover`, `lockDate`

### api/settings.php::__default__::POST
- Frontend consumers: `assets/app/admin.js::saveAppSettings`, `assets/app/admin.js::saveBroadcast`, `assets/app/admin.js::saveLockDate`
- Frontend input keys: `body`, `broadcastActive`, `broadcastMsg`, `handover`, `lockDate`, `method`
- Backend file: `api/settings.php`
- Backend block: `method:POST`
- Backend input keys: `-`
- Backend response keys: `-`

### api/transactions.php::delete::POST
- Frontend consumers: `assets/app/form.js::renderHistoryCards`
- Frontend input keys: `body`, `method`, `uuid`
- Backend file: `api/transactions.php`
- Backend block: `actionDelete`
- Backend input keys: `-`
- Backend response keys: `-`

### api/transactions.php::diff::GET
- Frontend consumers: `assets/app/form.js::renderHistoryCards`
- Frontend input keys: `method`, `uuid`
- Backend file: `api/transactions.php`
- Backend block: `actionDiff`
- Backend input keys: `uuid`
- Backend response keys: `-`

### api/transactions.php::finalize::POST
- Frontend consumers: `assets/app/form.js::renderHistoryCards`
- Frontend input keys: `body`, `confirmButtonColor`, `icon`, `method`, `showCancelButton`, `text`, `title`, `uuid`
- Backend file: `api/transactions.php`
- Backend block: `actionFinalize`
- Backend input keys: `-`
- Backend response keys: `-`

### api/transactions.php::previousStock::GET
- Frontend consumers: `assets/app/form.js::triggerHandover`
- Frontend input keys: `date`, `mesin`, `method`, `shift`
- Backend file: `api/transactions.php`
- Backend block: `actionPreviousStock`
- Backend input keys: `date`, `mesin`, `shift`
- Backend response keys: `-`
