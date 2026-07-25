# Function Dependency Map

Generated at: `2026-07-25T05:17:07.849903+00:00`

## Coverage

- Function nodes: `278`
- Call edges: `413`

## Core GitNexus Context

- `actionLogin` unresolved
- `actionTakeoverStatus` unresolved
- `actionSubmit` unresolved
- `actionRevise` unresolved
- `actionFinalize` unresolved
- `loadInitialData` -> `documentation/TRACE_CENTER.md` lines `115-130`
- `loadHistory` -> `documentation/TRACE_CENTER.md` lines `99-114`
- `loadAdminHistory` unresolved
- `submitData` unresolved
- `buildSubmitPayload` -> `assets/app/form.js` lines `609-656`

## Sample Edges

- `api/admin.php::action:verifyAdmin` -> `api/admin.php::fail` (php_call)
- `api/admin.php::action:verifyAdmin` -> `api/admin.php::body` (php_call)
- `api/admin.php::action:verifyAdmin` -> `api/admin.php::ok` (php_call)
- `api/admin.php::action:stats` -> `api/admin.php::ok` (php_call)
- `api/admin.php::action:logs` -> `api/admin.php::body` (php_call)
- `api/admin.php::action:logs` -> `api/admin.php::ok` (php_call)
- `api/admin.php::action:photoProgress` -> `api/admin.php::ok` (php_call)
- `api/admin.php::action:changePassword` -> `api/admin.php::body` (php_call)
- `api/admin.php::action:changePassword` -> `api/admin.php::fail` (php_call)
- `api/admin.php::action:changePassword` -> `api/admin.php::ok` (php_call)
- `api/auth.php::param` -> `api/auth.php::body` (php_call)
- `api/auth.php::createSession` -> `api/auth.php::generateToken` (php_call)
- `api/auth.php::actionLogin` -> `api/auth.php::param` (php_call)
- `api/auth.php::actionLogin` -> `api/auth.php::fail` (php_call)
- `api/auth.php::actionLogin` -> `api/auth.php::getTakeoverTimeoutCount` (php_call)
- `api/auth.php::actionLogin` -> `api/auth.php::generateToken` (php_call)
- `api/auth.php::actionLogin` -> `api/auth.php::clearTakeoverRequests` (php_call)
- `api/auth.php::actionLogin` -> `api/auth.php::clearTakeoverHistory` (php_call)
- `api/auth.php::actionLogin` -> `api/auth.php::createSession` (php_call)
- `api/auth.php::actionLogin` -> `api/auth.php::ok` (php_call)
- `api/auth.php::actionLogout` -> `api/auth.php::param` (php_call)
- `api/auth.php::actionLogout` -> `api/auth.php::ok` (php_call)
- `api/auth.php::actionForceLogin` -> `api/auth.php::param` (php_call)
- `api/auth.php::actionForceLogin` -> `api/auth.php::fail` (php_call)
- `api/auth.php::actionForceLogin` -> `api/auth.php::getAdminPin` (php_call)
- `api/auth.php::actionForceLogin` -> `api/auth.php::deleteSession` (php_call)
- `api/auth.php::actionForceLogin` -> `api/auth.php::clearTakeoverHistory` (php_call)
- `api/auth.php::actionForceLogin` -> `api/auth.php::createSession` (php_call)
- `api/auth.php::actionForceLogin` -> `api/auth.php::ok` (php_call)
- `api/auth.php::actionChangePassword` -> `api/auth.php::param` (php_call)
- `api/auth.php::actionChangePassword` -> `api/auth.php::fail` (php_call)
- `api/auth.php::actionChangePassword` -> `api/auth.php::ok` (php_call)
- `api/auth.php::actionValidate` -> `api/auth.php::fail` (php_call)
- `api/auth.php::actionValidate` -> `api/auth.php::validateToken` (php_call)
- `api/auth.php::actionValidate` -> `api/auth.php::ok` (php_call)
- `api/auth.php::actionCheckTakeover` -> `api/auth.php::param` (php_call)
- `api/auth.php::actionCheckTakeover` -> `api/auth.php::body` (php_call)
- `api/auth.php::actionCheckTakeover` -> `api/auth.php::fail` (php_call)
- `api/auth.php::actionCheckTakeover` -> `api/auth.php::getActiveSession` (php_call)
- `api/auth.php::actionCheckTakeover` -> `api/auth.php::ok` (php_call)
- `api/auth.php::actionCheckTakeover` -> `api/auth.php::getPendingTakeover` (php_call)
- `api/auth.php::actionTakeoverDecision` -> `api/auth.php::param` (php_call)
- `api/auth.php::actionTakeoverDecision` -> `api/auth.php::fail` (php_call)
- `api/auth.php::actionTakeoverDecision` -> `api/auth.php::getPendingTakeover` (php_call)
- `api/auth.php::actionTakeoverDecision` -> `api/auth.php::ok` (php_call)
- `api/auth.php::actionTakeoverStatus` -> `api/auth.php::param` (php_call)
- `api/auth.php::actionTakeoverStatus` -> `api/auth.php::fail` (php_call)
- `api/auth.php::actionTakeoverStatus` -> `api/auth.php::getActiveSession` (php_call)
- `api/auth.php::actionTakeoverStatus` -> `api/auth.php::clearTakeoverHistory` (php_call)
- `api/auth.php::actionTakeoverStatus` -> `api/auth.php::ok` (php_call)
- `api/auth_ASUSVIVO_May-26-130322-2026_Conflict.php::param` -> `api/auth_ASUSVIVO_May-26-130322-2026_Conflict.php::body` (php_call)
- `api/auth_ASUSVIVO_May-26-130322-2026_Conflict.php::actionLogin` -> `api/auth_ASUSVIVO_May-26-130322-2026_Conflict.php::param` (php_call)
- `api/auth_ASUSVIVO_May-26-130322-2026_Conflict.php::actionLogin` -> `api/auth_ASUSVIVO_May-26-130322-2026_Conflict.php::fail` (php_call)
- `api/auth_ASUSVIVO_May-26-130322-2026_Conflict.php::actionLogin` -> `api/auth_ASUSVIVO_May-26-130322-2026_Conflict.php::getActiveSession` (php_call)
- `api/auth_ASUSVIVO_May-26-130322-2026_Conflict.php::actionLogin` -> `api/auth_ASUSVIVO_May-26-130322-2026_Conflict.php::createSession` (php_call)
- `api/auth_ASUSVIVO_May-26-130322-2026_Conflict.php::actionLogin` -> `api/auth_ASUSVIVO_May-26-130322-2026_Conflict.php::ok` (php_call)
- `api/auth_ASUSVIVO_May-26-130322-2026_Conflict.php::actionLogout` -> `api/auth_ASUSVIVO_May-26-130322-2026_Conflict.php::param` (php_call)
- `api/auth_ASUSVIVO_May-26-130322-2026_Conflict.php::actionLogout` -> `api/auth_ASUSVIVO_May-26-130322-2026_Conflict.php::deleteSession` (php_call)
- `api/auth_ASUSVIVO_May-26-130322-2026_Conflict.php::actionLogout` -> `api/auth_ASUSVIVO_May-26-130322-2026_Conflict.php::ok` (php_call)
- `api/auth_ASUSVIVO_May-26-130322-2026_Conflict.php::actionForceLogin` -> `api/auth_ASUSVIVO_May-26-130322-2026_Conflict.php::param` (php_call)
- `api/auth_ASUSVIVO_May-26-130322-2026_Conflict.php::actionForceLogin` -> `api/auth_ASUSVIVO_May-26-130322-2026_Conflict.php::fail` (php_call)
- `api/auth_ASUSVIVO_May-26-130322-2026_Conflict.php::actionForceLogin` -> `api/auth_ASUSVIVO_May-26-130322-2026_Conflict.php::getAdminPin` (php_call)
- `api/auth_ASUSVIVO_May-26-130322-2026_Conflict.php::actionForceLogin` -> `api/auth_ASUSVIVO_May-26-130322-2026_Conflict.php::deleteSession` (php_call)
- `api/auth_ASUSVIVO_May-26-130322-2026_Conflict.php::actionForceLogin` -> `api/auth_ASUSVIVO_May-26-130322-2026_Conflict.php::createSession` (php_call)
- `api/auth_ASUSVIVO_May-26-130322-2026_Conflict.php::actionForceLogin` -> `api/auth_ASUSVIVO_May-26-130322-2026_Conflict.php::ok` (php_call)
- `api/auth_ASUSVIVO_May-26-130322-2026_Conflict.php::actionChangePassword` -> `api/auth_ASUSVIVO_May-26-130322-2026_Conflict.php::param` (php_call)
- `api/auth_ASUSVIVO_May-26-130322-2026_Conflict.php::actionChangePassword` -> `api/auth_ASUSVIVO_May-26-130322-2026_Conflict.php::fail` (php_call)
- `api/auth_ASUSVIVO_May-26-130322-2026_Conflict.php::actionChangePassword` -> `api/auth_ASUSVIVO_May-26-130322-2026_Conflict.php::ok` (php_call)
- `api/auth_ASUSVIVO_May-26-130322-2026_Conflict.php::actionValidate` -> `api/auth_ASUSVIVO_May-26-130322-2026_Conflict.php::bearerToken` (php_call)
- `api/auth_ASUSVIVO_May-26-130322-2026_Conflict.php::actionValidate` -> `api/auth_ASUSVIVO_May-26-130322-2026_Conflict.php::param` (php_call)
- `api/auth_ASUSVIVO_May-26-130322-2026_Conflict.php::actionValidate` -> `api/auth_ASUSVIVO_May-26-130322-2026_Conflict.php::fail` (php_call)
- `api/auth_ASUSVIVO_May-26-130322-2026_Conflict.php::actionValidate` -> `api/auth_ASUSVIVO_May-26-130322-2026_Conflict.php::validateToken` (php_call)
- `api/auth_ASUSVIVO_May-26-130322-2026_Conflict.php::actionValidate` -> `api/auth_ASUSVIVO_May-26-130322-2026_Conflict.php::ok` (php_call)
- `api/auth_ASUSVIVO_May-26-130322-2026_Conflict.php::actionCheckTakeover` -> `api/auth_ASUSVIVO_May-26-130322-2026_Conflict.php::param` (php_call)
- `api/auth_ASUSVIVO_May-26-130322-2026_Conflict.php::actionCheckTakeover` -> `api/auth_ASUSVIVO_May-26-130322-2026_Conflict.php::bearerToken` (php_call)
- `api/auth_ASUSVIVO_May-26-130322-2026_Conflict.php::actionCheckTakeover` -> `api/auth_ASUSVIVO_May-26-130322-2026_Conflict.php::fail` (php_call)
- `api/auth_ASUSVIVO_May-26-130322-2026_Conflict.php::actionCheckTakeover` -> `api/auth_ASUSVIVO_May-26-130322-2026_Conflict.php::getActiveSession` (php_call)
- `api/auth_ASUSVIVO_May-26-130322-2026_Conflict.php::actionCheckTakeover` -> `api/auth_ASUSVIVO_May-26-130322-2026_Conflict.php::ok` (php_call)
- `api/auth_ASUSVIVO_May-26-130322-2026_Conflict.php::actionCheckTakeover` -> `api/auth_ASUSVIVO_May-26-130322-2026_Conflict.php::getPendingTakeover` (php_call)
- `api/auth_ASUSVIVO_May-26-130322-2026_Conflict.php::actionTakeoverDecision` -> `api/auth_ASUSVIVO_May-26-130322-2026_Conflict.php::param` (php_call)
