# Input/Output Dependency Map — ProdAdmin

## API Endpoints

### Auth API — `api/auth.php`

#### POST `?action=login`
**Input:**
```json
{ "username": "string", "password": "string", "machine": "string", "force": false }
```
**Output (success):**
```json
{ "success": true, "data": { "token": "hex64", "username": "string", "takeoverDirect": false } }
```
**Output (machine in use):**
```json
{ "success": false, "status": "MACHINE_IN_USE", "activeUser": "string" }
```
**Output (waiting approval):**
```json
{ "success": false, "status": "WAITING_APPROVAL", "tempToken": "string" }
```
**DB READ:** `users (WHERE username)`, `sessions (WHERE machine_id)`
**DB WRITE:** `sessions (INSERT)`, `sessions GC (DELETE expired)`, `takeover_requests`

---

#### GET `?action=validate`
**Input:** `Authorization: Bearer {token}` header
**Output:**
```json
{ "success": true, "data": { "username": "string", "isAdmin": false } }
```
**DB READ:** `sessions (WHERE token AND expires_at > NOW())`

---

#### POST `?action=logout`
**Input:** Bearer token
**Output:** `{ "success": true }`
**DB WRITE:** `sessions (DELETE WHERE token)`

---

### Transactions API — `api/transactions.php`

#### POST `?action=submit`
**Input:**
```json
{
  "tanggal": "YYYY-MM-DD",
  "shift": "1|2|3",
  "mesin": "Mesin BHP 1",
  "size": "Size M",
  "materialsJson": "[{name, supplier, stockAwal, masuk, retur, reject, hours:[24], photos:[]}]",
  "outputsJson": "[{mid, name, qtyBox, catBag, catBox, counterPcs, totalKg, lossKg}]",
  "reportJson": "{counterKg, lossKg, lossPct, downtimeMin, downtimePct, speed, trouble, nearMiss, notes, rejectPrintingKg}"
}
```
**Output:**
```json
{ "success": true, "data": { "uuid": "v4-uuid", "count": 3, "rev": 0 } }
```
**DB READ:** `settings (LOCK_DATE)`, `sessions`
**DB WRITE:** `transactions`, `transaction_materials`, `material_hourly_usage`, `production_outputs`, `production_reports`, `audit_logs`
**Guard:** tanggal > lockDate OR isAdmin

---

#### POST `?action=revise`
**Input:** sama seperti submit + `"uuid": "existing-uuid"`
**Output:**
```json
{ "success": true, "data": { "uuid": "same-uuid", "count": 3, "rev": 2 } }
```
**DB READ:** `transactions (WHERE uuid, status NOT IN HISTORY/SUPERSEDED)`
**DB WRITE:**
- `transactions SET status=HISTORY` (record lama)
- `production_outputs SET status=HISTORY`
- `production_reports SET status=HISTORY`
- Insert baru semua tabel (UUID sama, rev+1)
**Guards:** isFINAL? → reject | rev>=3 (non-admin)? → reject | bukan owner? → reject

---

#### POST `?action=finalize`
**Input:** `{ "uuid": "v4-uuid" }`
**Output:** `{ "success": true }`
**DB WRITE:** `transactions SET status=FINAL`, `production_outputs SET status=FINAL`, `audit_logs`

---

#### POST `?action=delete` *(Admin only)*
**Input:** `{ "uuid": "v4-uuid" }`
**Output:** `{ "success": true }`
**DB WRITE:** `transactions SET status=HISTORY`, `production_outputs SET status=HISTORY`, `production_reports SET status=HISTORY`
> ⚠️ Delete set ke `HISTORY` (bukan SUPERSEDED). Record tetap bisa dilihat di `diff` view.

---

#### GET `?action=previousStock&mesin=X&shift=Y&date=Z`
**Output:**
```json
{ "success": true, "data": { "MaterialName": 125.5, "MaterialLain": 0.0 } }
```
Map `{ [material_name]: stock_final_float }` — hanya material yang terdaftar di shift sebelumnya.
**DB READ:** `transaction_materials JOIN transactions WHERE mesin=? AND shift=prevShift AND date=prevDate AND status NOT IN ('HISTORY','SUPERSEDED') ORDER BY revision_count DESC`

---

#### GET `?action=diff&uuid=X`
**Output:**
```json
{ "success": true, "data": { "revisions": [{ "rev": 0, "materials": [], "outputs": [] }] } }
```
**DB READ:** `transactions`, `transaction_materials`, `production_outputs` — semua revisi UUID

---

### Init API — `api/init.php`

#### GET (no action param)
**Output:**
```json
{
  "success": true,
  "data": {
    "suppliers": { "map": {"KARDUS BHP/AHP LARGE": ["Supplier A"]}, "order": ["KARDUS BHP/AHP LARGE", "..."] },
    "conversion": [{ "mid": "50050", "name": "BABY HAPPY PANTS M32", "catBag": "PRINTING BHP/AHP LARGE", "catBox": "KARDUS BHP/AHP LARGE", "ratio": 4, "weight": 28.02 }],
    "config": { "mesin": ["Mesin BHP 1", "..."], "size": ["Size S", "..."], "aliases": "{}" }
  }
}
```
**DB READ:** `suppliers`, `material_suppliers`, `conversions`, `settings`
**Note:** alias SQL di-quote (`AS "catBag"`) agar PostgreSQL preserve camelCase

---

### Conversions API — `api/conversions.php`

#### GET `?action=list`
**Output:** `{ "success": true, "data": [{ "mid", "name", "catBag", "catBox", "ratio", "weight" }] }`
**DB READ:** `conversions ORDER BY item_name`

#### POST `?action=save`
**Input:** `{ "oldMid": "...", "mid": "...", "name": "...", "weight": 0, "ratio": 0, "catBag": "...", "catBox": "..." }`
**DB WRITE:** `conversions` (upsert ON CONFLICT mid). Rename MID: DELETE lama + INSERT baru.

#### POST `?action=delete`
**Input:** `{ "mid": "..." }`
**DB WRITE:** `DELETE FROM conversions WHERE mid = ?`

---

### Config API — `api/config.php`

#### GET `?action=get` *(No auth required — dipakai saat login screen)*
**Output:**
```json
{ "success": true, "data": { "mesin": ["Mesin BHP 1", ...], "size": ["Size S", ...], "aliases": "{}" } }
```
**DB READ:** `settings WHERE key IN (LIST_MESIN, LIST_SIZE, LIST_ALIASES)`

#### POST `?action=save` *(Admin)*
**Input:** `{ "mesin": "Mesin A,Mesin B", "size": "Size S,Size M", "aliases": "{}" }`
**DB WRITE:** `settings (UPSERT 3 rows)`

---

### Settings API — `api/settings.php`

#### GET *(Auth required)*
**Output:**
```json
{
  "success": true,
  "data": {
    "enableHandover": true,
    "lockDate": "2026-06-01",
    "broadcastMsg": "...",
    "broadcastActive": false
  }
}
```
**DB READ:** `settings WHERE key IN (ENABLE_HANDOVER, LOCK_DATE, BROADCAST_MSG, BROADCAST_ACTIVE, ADMIN_PIN)`
> ⚠️ Key yang dikembalikan adalah `enableHandover` (bukan `handover`). Frontend harus menggunakan `state.settings.enableHandover`.

#### POST *(Admin)*
**Input fields (any combination):**
```json
{ "handover": true, "lockDate": "2026-06-01", "broadcastMsg": "...", "broadcastActive": true }
```
> NB: POST terima `handover` (bukan `enableHandover`) — inconsistency disengaja antara GET/POST key names.
**DB WRITE:** `settings (UPSERT per field)`

---

## Frontend → API Call Map

| Frontend Function | File | Calls API |
|-------------------|------|-----------|
| `doLogin()` | auth.js | POST auth.php?action=login |
| `validateExistingSession()` | auth.js | GET auth.php?action=validate |
| `handleLogout()` | auth.js | POST auth.php?action=logout |
| `showAdminLogin()` | auth.js | POST admin.php?action=verifyAdmin (PIN verify) → lalu `enterAdminMode()` |
| `waitForTakeover()` | auth.js | POST auth.php?action=takeoverStatus |
| `startTakeoverMonitor()` | auth.js | POST auth.php?action=checkTakeover (polling 5s) |
| `loadLoginMachines()` | auth.js | GET config.php?action=get |
| `loadInitialData()` | form.js | GET init.php (suppliers+conversions+config) → GET history.php → GET settings.php (enableHandover/broadcast) |
| `triggerHandover()` | form.js | GET transactions.php?action=previousStock (jika enableHandover=true) |
| `loadHistory()` | form.js | GET history.php |
| form submit button | form.js | POST transactions.php?action=submit |
| edit/revise button | form.js | POST transactions.php?action=revise |
| finalize button | form.js | POST transactions.php?action=finalize |
| delete button | form.js | POST transactions.php?action=delete |
| load previous | form.js | GET transactions.php?action=previousStock&mesin=X&shift=Y&date=Z |
| diff button | form.js | GET transactions.php?action=diff |
| `saveConversionData()` | admin.js | POST conversions.php?action=save → GET conversions.php?action=list |
| delete product | admin.js | POST conversions.php?action=delete |
| `addNewMaterial()` | admin.js | POST materials.php?action=update (oldName="", suppliers=[]) |
| `saveMaterialChanges()` | admin.js | POST materials.php?action=saveList |
| `saveMaterialEdit()` | admin.js | POST materials.php?action=update (rename + replace suppliers) → GET materials.php?action=list |
| delete material | admin.js | POST materials.php?action=delete |

---

## Diagram

Lihat: [diagrams/io-dependency.svg](diagrams/io-dependency.svg)
