# Endpoint to UI Contract Map

Generated at: `2026-07-25T05:16:38.453442+00:00`

## Coverage

- Frontend API calls discovered: `51`
- Unique endpoint contracts: `24`
- Consumers with silent fail pattern: `0`
- Consumers with silent catch pattern: `7`
- Consumers with blank-state rendering: `0`

## Priority Endpoints


## Findings

- `MEDIUM` Endpoint dipanggil via template/dynamic expression dan perlu review manual shape response -> `api/history.php::paged::GET` | assets/app/form.js::loadHistory; assets/app/form.js::loadHistory; assets/app/form.js::loadMoreHistory; assets/app/form.js::loadMoreHistory
- `MEDIUM` Consumer memiliki catch silent yang bisa menyembunyikan failed data call -> `api/init.php::__default__::GET` | assets/app/form.js::loadInitialData line 1556
- `MEDIUM` Consumer memiliki catch silent yang bisa menyembunyikan failed data call -> `api/init.php::__default__::GET` | assets/app/form.js::loadInitialData line 1556
- `MEDIUM` Consumer memiliki catch silent yang bisa menyembunyikan failed data call -> `api/settings.php::__default__::GET` | assets/app/form.js::loadInitialData line 1556
- `MEDIUM` Consumer memiliki catch silent yang bisa menyembunyikan failed data call -> `api/settings.php::__default__::GET` | assets/app/form.js::loadInitialData line 1556
- `MEDIUM` Consumer memiliki catch silent yang bisa menyembunyikan failed data call -> `api/transactions.php::delete::POST` | assets/app/form.js::renderHistoryCards line 707
- `MEDIUM` Endpoint dipanggil via template/dynamic expression dan perlu review manual shape response -> `api/transactions.php::diff::GET` | assets/app/form.js::renderHistoryCards
- `MEDIUM` Consumer memiliki catch silent yang bisa menyembunyikan failed data call -> `api/transactions.php::diff::GET` | assets/app/form.js::renderHistoryCards line 707
- `MEDIUM` Consumer memiliki catch silent yang bisa menyembunyikan failed data call -> `api/transactions.php::finalize::POST` | assets/app/form.js::renderHistoryCards line 707
- `MEDIUM` Endpoint dipanggil via template/dynamic expression dan perlu review manual shape response -> `api/transactions.php::previousStock::GET` | assets/app/form.js::triggerHandover; assets/app/form.js::triggerHandover

## GitNexus Context Checks

- `loadAppData` unresolved
- `loadHistory` found in `documentation/TRACE_CENTER.md` lines `99-114`
- `loadAdminHistory` unresolved
- `loadAdminScorecard` unresolved
- `callApi` unresolved
- `actionSubmit` unresolved
- `actionValidate` unresolved
