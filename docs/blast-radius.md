# Blast Radius Report — ProdAdmin

**Generated:** 2026-06-08 | **Last Updated:** 2026-07-07C (comment cleanup + bearerToken security fix) | **Tool:** GitNexus Impact Analysis
**Index:** 1.812 nodes · 2.934 edges · 114 flows · 108 clusters

---

## ⚠️ PERATURAN

> **JANGAN ubah simbol CRITICAL atau HIGH** tanpa lebih dulu menjalankan:
> ```
> gitnexus_impact({target: "symbolName", direction: "upstream", repo: "ProdAdmin"})
> ```
> dan melaporkan blast radius ke user.

---

## ⚠️ CATATAN: _Conflict.php Files

File `api/auth_ASUSVIVO_May-26-130322-2026_Conflict.php` dan `api/transactions_ASUSVIVO_May-26-130326-2026_Conflict.php` adalah **duplikat Git conflict** yang masih ter-index oleh GitNexus. Ini **menggelembungkan angka blast radius** (getDb: 47 total vs 33 production-only). Jangan gunakan file ini di production — gunakan `api/auth.php` dan `api/transactions.php` versi utama.

---

## 🔴 CRITICAL — getDb()

**File:** `config/database.php`
**Fungsi:** PDO singleton — satu-satunya koneksi ke PostgreSQL

**✅ Security fixes applied (2026-06-08):**
- Hapus hardcoded password fallback `'SASMU123'` — sekarang fail-fast jika `PRODADMIN_DB_PASS` env var tidak di-set
- Hapus `$e->getMessage()` dari HTTP error response (information disclosure)
- Hapus `PDO::ATTR_PERSISTENT => true` (connection state leakage antar request)

| Metric | Value |
|--------|-------|
| Risk | **CRITICAL** |
| Total impacted (incl. Conflict files) | **47** |
| Direct callers — production files only | **~21** |
| Processes affected | **28** |
| Modules affected | Api (32 direct hits) |

**d=1 — WILL BREAK (production files):**

*api/auth.php:* `getAdminPin · validateToken · actionLogin · actionLogout · actionForceLogin · actionChangePassword · actionCheckTakeover · actionTakeoverDecision · actionTakeoverStatus`

*api/transactions.php:* `actionSubmit · actionRevise · actionFinalize · actionDelete · actionPreviousStock · actionDiff`

*api files (direct):* `settings.php · history.php · materials.php · admin.php · photos.php · init.php · conversions.php · config.php · migration_api.php`

*python_bot:* `rehash_users.php`

**d=2 — LIKELY AFFECTED:**
`actionValidate` (via validateToken), `File: auth.php`, `File: transactions.php`

---

## 🔴 CRITICAL — requireSession()

**File:** `config/auth_helper.php`
**Fungsi:** Validasi Bearer token di setiap protected endpoint

**✅ Security fixes applied (2026-06-08):**
- Hapus token fallback `$_GET['token']` dan `$_POST['token']` — token hanya diterima via `Authorization: Bearer`
- Tambah format validation: token harus hex string 32–128 karakter sebelum DB query

| Metric | Value |
|--------|-------|
| Risk | **CRITICAL** |
| Direct callers (d=1) | **14** |
| Processes affected | **6** |
| Modules affected | Api (6 direct hits) |

**d=1 — WILL BREAK:**

*api/transactions.php:* `actionSubmit · actionRevise · actionFinalize · actionDelete · actionPreviousStock · actionDiff`

*api files:* `settings.php · history.php · materials.php · admin.php · photos.php · init.php · conversions.php · config.php`

**⚠️ Update 2026-06-19:** `init.php`, `conversions.php`, `config.php` sekarang juga memanggil `requireSession` — ditambahkan setelah docs awal dibuat.

**✅ Security fix 2026-07-07C:** Local `bearerToken()` di `api/auth.php` dihapus — fungsi ini punya fallback `$_GET['token']` yang memungkinkan token bocor ke URL (server log, browser history, Referer header). Semua callernya kini pakai `getBearerToken()` dari `auth_helper.php` (header-only) atau baca dari `body()['token']` (POST body).

**Processes broken (step 1):** ActionDiff (4 steps), ActionPreviousStock (4 steps), ActionSubmit (3 steps), ActionRevise (3 steps), ActionDelete (3 steps), ActionFinalize (3 steps)

---

## 🟠 HIGH — refreshAnalysis()

**File:** `assets/app/form.js`
**Fungsi:** Hitung & render analisa hemat/boros kardus+kantong berdasarkan catBox/catBag dari conversions

> **Baru ditambahkan 2026-06-11** — belum ada di docs lama.

| Metric | Value |
|--------|-------|
| Risk | **HIGH** |
| Direct callers (d=1) | **4** |
| Processes affected | **3** |
| Modules affected | App |

**d=1 — WILL BREAK:**
`collectOutputs`, `restoreDraft`, `populateFormFromHistory`, `form.js` (DOMContentLoaded event)

**d=2 — LIKELY AFFECTED:**
`renderHistoryCards` (via collectOutputs → 7 processes, 14 hits)

**Processes:**
- `renderHistoryCards` → 7 processes affected (earliest step: 1)
- `restoreDraft` → 6 processes affected
- `collectOutputs` → 3 processes affected

**⚠️ Catatan:** Jika `refreshAnalysis` break, analisa HEMAT/BOROS tidak tampil dan form submit masih bisa berjalan (tidak blocking).

---

## 🟢 LOW — insertTransaction()

**File:** `api/transactions.php:98`

| Metric | Value |
|--------|-------|
| Risk | **LOW** |
| Direct callers (d=1) | **2** |
| Processes affected | **2** |

**d=1:** `actionSubmit`, `actionRevise`
**d=2:** `File: transactions.php`

**File:** `api/transactions.php:99` (11 params)
**⚠️ Update 2026-06-11:** `actionRevise` sekarang pakai `SELECT FOR UPDATE` di dalam transaction sebelum memanggil `insertTransaction` — race condition protection.

---

## 🟢 LOW — doLogin()

**File:** `assets/app/auth.js:233`

| Metric | Value |
|--------|-------|
| Risk | **LOW** |
| Direct callers (d=1) | **1** |
| Processes affected | **1 → 5 sub-processes** |

**d=1:** `submitLogin` → 5 execution flows: SubmitLogin→RawJson, SubmitLogin→Toast, SubmitLogin→SaveSession, SubmitLogin→UpdateUserBadge, SubmitLogin→SetAppVisible

---

## 🟢 LOW — submitLogin()

**File:** `assets/app/auth.js:286`

| Metric | Value |
|--------|-------|
| Risk | **LOW** |
| Direct callers (d=1) | **1** |
| Processes affected | **0** |

**d=1:** `File: auth.js` (event listener)

---

## Diagram

Lihat: [diagrams/blast-radius.svg](diagrams/blast-radius.svg)

---

## Quick Reference

```
Sebelum edit getDb()          → STOP. Dampak ke SELURUH API layer (21+ production callers, 28 processes)
Sebelum edit requireSession() → STOP. Semua 14 endpoint protected akan break
Sebelum edit refreshAnalysis()→ HATI-HATI (HIGH). collectOutputs + restoreDraft + populateFormFromHistory break
Sebelum edit insertTransaction()→ low risk, test actionSubmit + actionRevise
Sebelum edit doLogin()        → low risk, pastikan test login flow E2E
```

---

---

## 📊 detect_changes — 2026-07-07 (comprehensive audit snapshot)

**`gitnexus_detect_changes({scope: "all", repo: "ProdAdmin"})`**

| Metric | Nilai |
|--------|-------|
| Changed symbols | **149** |
| Affected symbols | **66** |
| Changed files | **17** |
| Risk level | **CRITICAL** |

> Angka CRITICAL ini adalah akumulasi semua perubahan sesi (termasuk bug fixes, draft restore, analisa output, WA screenshot, auto-kg, dll). Semua perubahan telah di-review dan diverifikasi. Setelah commit, detect_changes akan kembali ke 0.

**Audit Result 2026-07-07 — 4 Phase Protocol:**

| Phase | Status |
|-------|--------|
| Phase 0 — Preflight & Index Integrity | ✅ PASS |
| Phase 1 — Architecture Conformance | ✅ PASS |
| Phase 2 — Dependency & Runtime | ✅ PASS — 30 PHP files lint bersih |
| Phase 3 — Function Contract Audit | ✅ PASS — semua actions auth.php + transactions.php detected |

---

## 📊 detect_changes — 2026-06-23 (pre-commit snapshot)

**`gitnexus_detect_changes({scope: "all", repo: "ProdAdmin"})`**

| Metric | Nilai |
|--------|-------|
| Changed symbols | **149** |
| Affected symbols | **66** |
| Changed files | **17** |
| Risk level | **CRITICAL** |

**Execution flows terpengaruh (key):**

| Flow | Steps berubah |
|------|--------------|
| `SubmitLogin → RawJson` | step 2 — doLogin |
| `RenderHistoryCards → ParseIntField` | semua 5 steps |
| `RestoreDraft → ParseIntField` | semua 4 steps (flow BARU) |
| `RestoreDraft → FmtInt` | semua 3 steps (flow BARU) |
| `CollectOutputs → ParseIntField` | semua 4 steps |
| `BuildSubmitPayload → ParseIntField` | steps 2-3 (flow BARU) |
| `SnapshotRows → ParseIntField` | steps 2-3 (flow BARU) |
| `ActionSubmit → GetBearerToken` | step 2 — requireSession |
| `ActionRevise → GetBearerToken` | step 2 — requireSession |
| `RenderConversionTable → Esc` | steps 1-2 (flow BARU) |

**Simbol baru yang ter-index:**
- `resetConversionModalBtn` (admin.js)
- `fmtInt`, `parseIntField`, `autoSaveDraft`, `restoreDraft`, `draftKey` (form.js)
- `inOutputRow`, `inTableBody` (api.js — Enter key scope)
- `wasBannerVisible` (form.js — discardDraft logic)
- `stdTarget`, `targetAdj`, `linkedCount`, `barColor`, `barWidth` (form.js — refreshAnalysis)

---

## 🔍 Temuan Deep Audit 2026-06-22 — Sudah Diperbaiki

| # | Severity | File | Bug | Fix |
|---|----------|------|-----|-----|
| 1 | 🔴 CRITICAL | `assets/app/api.js:110` | Enter di output row (Qty Box) trigger submit form | Scoped Enter handler exclude `[data-output-row]` dan `#tableBody` |
| 2 | 🔴 HIGH | `assets/app/form.js:683,824,1016` | `lossPct` API returns float, tampil tanpa `%` suffix | Format dengan `App.fmt(lossPct) + '%'` di 3 lokasi |
| 3 | 🟠 MEDIUM | `assets/app/form.js:discardDraft` | `_draftTimer` tidak di-clear saat discard → auto-save ulang draft kosong | Tambah `clearTimeout(_draftTimer)` di `discardDraft()` |
| 4 | 🟠 MEDIUM | `assets/app/form.js:1279` | `discardDraft()` (dengan form-reset block) dipanggil sebelum `resetData()` | Reset form hanya jika `_pendingDraft` masih set (Abaikan path) |
| 5 | 🟠 MEDIUM | `assets/app/admin.js:218` | `renderConversionTable` dipanggil 2x per keystroke | Hapus duplicate `addEventListener` — cukup `oninput` HTML |
| 6 | 🟠 MEDIUM | `assets/app/admin.js:110` | `saveConversionData` enable button sebelum modal.hide() → 300ms double-click window | Swap: `modal.hide()` dulu, enable button sesudahnya |
| 7 | 🟡 LOW | `assets/app/admin.js:86,66` | Button state modal tidak di-reset saat modal dibuka ulang | Tambah `resetConversionModalBtn()` di open + edit handler |
| 8 | 🟡 LOW | `assets/app/form.js:544` | `output-qty`/`output-counter` menerima float (1.5) | Ganti `Number()` → `parseIntField()` di `collectOutputs()` |
| 9 | 🟡 LOW | `assets/app/form.js:1546` | Output row splice pakai DOM index yang bisa stale setelah `renderOutputs` | Panggil `collectOutputs()` sebelum splice untuk sync index |
| 10 | 🟡 LOW | `api/auth.php:570`, `api/transactions.php:283,379,440` | PDO `$e->getMessage()` terekspos ke client (schema/constraint info disclosure) | Ganti semua dengan pesan generik |

**Dipertahankan (by design / low risk):**
- `parseIntField` strips minus sign → field negatif tidak valid secara bisnis
- `actionDelete` set HISTORY (bukan SUPERSEDED) → acceptable audit trail
- `previousStock` bisa return DRAFT → low probability, short window
- Machine binding tidak di-enforce server-side → local network app by design
