# Function Map — ProdAdmin

## Cluster: App — Frontend (53 symbols)

> **Last updated:** 2026-07-25 (handover race fix, draft auto-resume, material edit modal, comment relevance audit)

### `assets/app/api.js` — Global State & Utilities

| Function | Signature | Output | Notes |
|----------|-----------|--------|-------|
| `api(path, options)` | `async (string, obj)` | JSON response | fetch + Bearer token, throws on error |
| `saveSession()` | `()` | void | Write state → localStorage (4 keys) |
| `clearSession()` | `()` | void | Reset state + saveSession |
| `fmt(value, digits=2)` | `(any, number)` | string | id-ID locale number format |
| `esc(value)` | `(any)` | string | HTML escape &, <, >, " |
| `toast(icon, title, text)` | `(string, string, string)` | Promise/void | SweetAlert2 popup |
| `currentShiftHours(shift)` | `(string)` | string[] | Shift 1: 07-14, Shift 2: 15-22, Shift 3: 23-06 |
| `getShiftStartIndex(shift)` | `(string)` | number | 0, 8, atau 16 |
| `toggleTheme()` | `()` | void | dark ↔ light, persisted ke localStorage |

**Global export:** `window.ProdApp = { state, api, saveSession, clearSession, fmt, esc, toast, currentShiftHours, getShiftStartIndex }`

**Enter key handler:** Scoped — triggers submit HANYA dari header form fields (tanggal/shift/mesin/size). Fields di `#outputRowsContainer` dan `#tableBody` di-exclude agar Enter tidak accidentally submit.

---

### `assets/app/auth.js` — Authentication

| Function | Signature | Calls | Notes |
|----------|-----------|-------|-------|
| `submitLogin()` | `async ()` | doLogin, toast | Entry point dari form login |
| `doLogin(u,p,m,isAdmin,force)` | `async (...)` | rawJson, saveSession, afterLogin, waitForTakeover | Handles MACHINE_IN_USE + WAITING_APPROVAL |
| `validateExistingSession()` | `async ()` | App.api, saveSession, clearSession, afterLogin | Dipanggil saat DOMContentLoaded |
| `rawJson(path, body)` | `async (string, obj)` | fetch POST JSON | Internal helper |
| `afterLogin()` | `async ()` | updateUserBadge, setAppVisible, loadInitialData, startTakeoverMonitor | Post-login setup — semua data load via `loadInitialData()` |
| `waitForTakeover(u,tempToken,m,isAdmin)` | `async (...)` | App.api poll | Poll setiap 2s, max timeout |
| `showAdminLogin()` | `async ()` | App.api `admin.php?action=verifyAdmin` | Prompt PIN → verifikasi → panggil `enterAdminMode()` |
| `enterAdminMode()` | `async ()` | saveSession, loadInitialData, toast | Set `isAdmin=true` — **tidak ada API call** |
| `exitAdminMode()` | `()` | App.state | Hapus flag isAdmin |
| `startTakeoverMonitor()` | `()` | setInterval | Monitor sesi yang diambil alih |
| `loadLoginMachines()` | `async ()` | App.api | Load LIST_MESIN ke dropdown |
| `setAppVisible(bool)` | `(boolean)` | DOM | Toggle login screen / app screen |
| `updateUserBadge()` | `()` | DOM | Update badge username + mesin |
| `handleLogout()` | `async ()` | App.api, clearSession | window.handleLogout |

---

### `assets/app/form.js` — Form & History

| Function | Signature | Output | Notes |
|----------|-----------|--------|-------|
| `renderHistoryCards(id, rows, isAdmin)` | `(string, array, boolean)` | void → DOM | Render kartu per laporan. Blast radius: 6 outgoing calls |
| `renderMaterialTable()` | `()` | void → DOM | Render tabel input material — inputs pakai `type="text" inputmode="decimal"` + format ribuan. Baris yang belum pernah disentuh default supplier-nya kosong (`defaultMaterialRow(material)`, TANPA prefill supplier pertama — fixed 2026-07-25) |
| `renderOutputs()` | `()` | void → DOM | Render baris output produksi — hanya dipanggil saat SKU berubah |
| `collectOutputs()` | `()` | void | Baca output rows dari DOM → state.outputs (qtyBox/counterPcs via parseIntField), lalu `refreshAnalysis()`. TIDAK memanggil renderOutputs |
| `refreshAnalysis()` | `()` | void → DOM | Render tabel analisa hemat/boros live — 5 kolom: Kategori, Target(Std+Rj), Aktual+bar, Selisih, Status. Kalkulasinya sendiri ada di `computeMaterialAnalysis()` |
| `computeMaterialAnalysis(outputs, materials)` | `(array, array)` | array of analysis rows | Rumus boros/hemat/pas: `diff = grand - (stdTarget + reject)`. Dipakai bersama oleh `refreshAnalysis()` (form live), laporan WA download, dan modal detail riwayat — supaya angkanya konsisten di 3 tempat |
| `snapshotRows()` | `()` | void | Snapshot material rows → state.formRows |
| `defaultMaterialRow(name, supplier="")` | `(string, string?)` | object | Buat row material default |
| `getActiveMaterials()` | `()` | array | Baca material dari DOM — menggunakan `parseIntField()` untuk nilai numerik |
| `refreshMaterialRowCalculations()` | `()` | void → DOM | Hitung ulang Tot.Prod, G.Total, Sisa per baris material |
| `populateFormFromHistory(rpt)` | `(object)` | void | Load history ke form untuk revisi |
| `ensureOutputRows()` | `()` | void | Pastikan ada minimal 1 output row |
| `autoCalcOutputKg(e)` | `(Event)` | void | Auto-hitung total kg dari counter pcs × weight/1000 |
| `buildSubmitPayload()` | `()` | object | Susun payload JSON untuk POST submit/revise |
| `parseLocalizedFloat(str)` | `(string)` | number | Parse angka format id-ID (1.234,56 → 1234.56) |
| `fmtInt(n)` | `(number)` | string | Format integer ke id-ID dengan separator ribuan (1000 → "1.000") |
| `parseIntField(s)` | `(string)` | number | Strip non-digit, parse integer (kebalikan fmtInt) |
| `draftKey()` | `()` | string | Key localStorage: `prodadmin_draft_{username}_{machine}` |
| `saveDraft()` | `()` | void | Serialize form → localStorage. Skip saat mode revisi |
| `autoSaveDraft()` | `()` | void | Debounce 3s sebelum `saveDraft()`. Dipanggil dari input events |
| `restoreDraft(draft)` | `(object)` | void | Set `state.formRows`, `state.outputs`, field form, `renderMaterialTable()` |
| `discardDraft()` | `()` | void | Hapus draft dari localStorage + clear `_draftTimer`. Reset form HANYA jika `_pendingDraft` masih set (user klik "Mulai Baru"). Dipanggil juga dari `submitData()` — di sana TIDAK reset form |
| `dismissDraftBanner()` | `()` | void | Hide notice draft saja — draft SUDAH aktif sejak sebelum banner ini muncul (pre-loaded di `loadInitialData()`), jadi ini bukan gate. Rename dari `resumeDraft()` 2026-07-25 karena nama lama menyesatkan (seolah aksinya yang mengaktifkan draft) |
| `triggerHandover()` | `async ()` | void → DOM | GET `previousStock`, isi `stockAwal` tiap material dari `SISA` shift sebelumnya. Dipanggil saat login (jika `enableHandover`) dan tiap Mesin/Shift/Tanggal berubah — untuk SEMUA role termasuk admin (gate `!state.isAdmin` dihapus 2026-07-25). Pakai sequence-counter guard (`_handoverSeq`) supaya response API yang telat (stale) dari trigger sebelumnya tidak menimpa hasil trigger terbaru |
| `resetData()` | `()` | void | Reset semua state form termasuk `state.materialPhotos = {}` |

---

### `assets/app/admin.js` — Admin Panel

| Function | Signature | Output | Notes |
|----------|-----------|--------|-------|
| `renderConversionTable()` | `()` | void → DOM | Render tabel master produk (SKU, berat, ratio, catBag, catBox). Dipanggil via `filterConversionTable` dari `oninput` HTML (bukan addEventListener — tidak double-fire) |
| `renderMaterialList()` | `()` | void → DOM | Render daftar master material dengan drag-reorder (Sortable.js) + tombol edit (pensil, buka `modalMaterial`) dan delete per baris. Nama material dibungkus `<span class="material-name">` — dipakai `saveMaterialChanges()` untuk extract urutan tanpa ikut menangkap span tombol aksi |
| `openMaterialEditModal(name)` | `(string)` | void | Prefill field Nama + textarea Supplier (satu per baris, dari `state.suppliers.map[name]`), tampilkan `modalMaterial`. Ditambahkan 2026-07-25 — backend `api/materials.php?action=update` sudah lama support rename+ganti supplier, UI-nya baru dibuat |
| `saveMaterialEdit()` | `async ()` | void | POST `api/materials.php?action=update` (rename material + replace daftar supplier) → reload `api/materials.php?action=list` → re-render list + tabel input material |
| `resetConversionModalBtn()` | `()` | void | Reset button Simpan ke enabled state — dipanggil saat modal dibuka (open + edit) |
| `openConversionModal()` | `()` | void | Buka modal tambah produk baru (form kosong + reset button) |
| `saveConversionData()` | `async ()` | void | POST save → reload list → `modal.hide()` → re-enable button. Order: hide dulu, enable button sesudahnya |
| `filterConversionTable()` | `()` | void | Filter tabel produk berdasarkan input search (alias `renderConversionTable`) |
| `addNewMaterial()` | `async ()` | void | POST tambah material baru |
| `saveMaterialChanges()` | `async ()` | void | POST urutan material baru — extract urutan dari `.material-name` span saja (lihat catatan `renderMaterialList()`) |
| `saveMachineSizeConfig()` | `async ()` | void | POST config mesin/size/aliases |
| `saveLockDate()` | `async ()` | void | POST lock date |
| `saveBroadcast()` | `async ()` | void | POST broadcast message |
| `saveAppSettings()` | `async ()` | void | POST setting app (handover) |
| `loadAuditLogs()` | `async ()` | void → DOM | GET audit logs (butuh PIN) |
| `openChangePassword()` | `async ()` | void | Swal dialog ganti password |
| `switchAdminTab(tab)` | `(string)` | void | Switch tab panel admin |

---

## Cluster: Api — Backend (86 symbols)

### `config/database.php` — CRITICAL

| Function | Signature | Output | Risk |
|----------|-----------|--------|------|
| `getDb()` | `()` | PDO | **CRITICAL** — 41 callers, 28 processes |

### `config/auth_helper.php` — CRITICAL

| Function | Signature | Output | Risk |
|----------|-----------|--------|------|
| `requireSession(db)` | `(PDO)` | array (session row) | **CRITICAL** — 14 callers, 6 processes |
| `authFail(msg)` | `(string)` | void (exit) | Kirim 401 + exit |
| `getBearerToken()` | `()` | string | Parse Authorization header |

### `api/auth.php` — Actions

| Function | Input | Output | DB Tables |
|----------|-------|--------|-----------|
| `actionLogin()` | {username, password, machine, force} | token / MACHINE_IN_USE / WAITING_APPROVAL | sessions, users, takeover_requests |
| `actionLogout()` | Bearer token | success | sessions |
| `actionForceLogin()` | {username, password, machine} | token | sessions, takeover_requests |
| `actionValidate()` | Bearer token | {username, isAdmin} | sessions |
| `actionChangePassword()` | {oldPass, newPass} | success | users |
| `actionCheckTakeover()` | {tempToken} | approved? | takeover_requests |
| `actionTakeoverDecision()` | {requestId, decision} | success | takeover_requests, sessions |
| `actionTakeoverStatus()` | Bearer token | status | sessions, takeover_requests |
| `createSession(db,u,m)` | PDO, string, string | token | sessions (INSERT + GC) |
| `deleteSession(db,u,m)` | PDO, string, string | void | sessions (DELETE) |
| `validateToken(token)` | string | `['isValid','username','role']` | sessions (SELECT) — akses DB via `getDb()` internal |
| `generateToken()` | — | string | — (bin2hex 32) |

### `api/init.php` — Initial Data Load

| Output | Isi |
|--------|-----|
| `data.suppliers` | `{ map: {materialName: [suppliers]}, order: [materialNames] }` |
| `data.conversion` | array SKU dari `conversions` — key `catBag`/`catBox` (quoted alias, preserve camelCase) |
| `data.config` | `{ mesin: [], size: [], aliases: "{}" }` dari settings |

> **Catatan:** Alias SQL harus di-quote (`cat_bag AS "catBag"`) karena PostgreSQL fold unquoted alias ke lowercase.

---

### `api/materials.php` — Master Material + Supplier CRUD

| Action | Method | Input | Output |
|--------|--------|-------|--------|
| `list` (default GET) | GET | — | `{map: {materialName: [suppliers]}, order: [materialNames]}` |
| `saveList` | POST | `{order: [names]}` | `true` — reset `display_order` sesuai urutan drag |
| `delete` | POST | `{name}` | `true` — DELETE FROM suppliers (cascade ke material_suppliers) |
| `update` | POST | `{oldName, newName, suppliers: [names]}` | `true` — rename material (jika `oldName` beda dari `newName`) DAN replace seluruh daftar supplier. `oldName=""` berarti insert material baru |

> **Catatan:** action `update` sudah ada sejak awal tapi baru dipakai UI-nya 2026-07-25 lewat modal "Edit Material" di admin panel (`openMaterialEditModal` / `saveMaterialEdit` di atas).

---

### `api/conversions.php` — Master SKU CRUD

| Action | Method | Input | Output |
|--------|--------|-------|--------|
| `list` (default GET) | GET | — | array SKU dengan catBag/catBox/ratio/weight |
| `save` | POST | `{oldMid, mid, name, weight, ratio, catBag, catBox}` | `true` (upsert) |
| `delete` | POST | `{mid}` | `true` |

---

### `api/transactions.php` — Actions

| Function | Input | Output | DB Tables |
|----------|-------|--------|-----------|
| `actionSubmit()` | body JSON | {uuid, count, rev:0} | transactions, transaction_materials, material_hourly_usage, production_outputs, production_reports, audit_logs |
| `actionRevise()` | body JSON + uuid lama | {uuid, count, rev} | semua tabel di atas + UPDATE status=HISTORY — pakai `SELECT FOR UPDATE` untuk lock row (anti race condition) |
| `actionFinalize()` | {uuid} | success | transactions, production_outputs (FINAL) |
| `actionDelete()` | {uuid} admin only | success | transactions (SUPERSEDED) |
| `actionPreviousStock()` | `?mesin=&shift=&date=` | `{material_name: stock_final}` map | transaction_materials (SELECT) — dipanggil oleh `triggerHandover()` untuk carry SISA shift lalu ke STOK AWAL shift baru |
| `actionDiff()` | ?uuid= | {revisions:[]} | transactions, materials, outputs (SELECT) |
| `insertTransaction(db,...)` | 11 params: `$db,$uuid,$tanggal,$shift,$mesin,$size,$revCount,$createdBy,$materials,$outputs,$report` | void | 4-5 tables via PDO transaction |
| `calcMaterial(mat, shift)` | material obj + shift | computed stock values | — (pure calculation) |
| `getLockDate(db)` | PDO | string\|null | settings WHERE key=LOCK_DATE |
| `genUuid()` | — | string | — (UUID v4) |
| `logAction(db,u,a,d)` | PDO+3 strings | void | audit_logs |

---

## Cluster: Python_bot (91 symbols)

Lihat: [python-bot.md](python-bot.md)

---

## Diagram

Lihat: [diagrams/function-map.svg](diagrams/function-map.svg)
