# Transaction Flow — ProdAdmin

## Actions Summary

| Action | Method | Endpoint | Auth | Status Result |
|--------|--------|----------|------|---------------|
| submit | POST | `api/transactions.php?action=submit` | requireSession | DRAFT |
| revise | POST | `api/transactions.php?action=revise` | requireSession | DRAFT (new rev) |
| finalize | POST | `api/transactions.php?action=finalize` | requireSession | FINAL |
| delete | POST | `api/transactions.php?action=delete` | requireSession + admin only | HISTORY |
| previousStock | GET | `api/transactions.php?action=previousStock&mesin=X&shift=Y&date=Z` | requireSession | — |
| diff | GET | `api/transactions.php?action=diff&uuid=X` | requireSession | — |

---

## Status Lifecycle

```
DRAFT ──(finalize)──► FINAL
  │                      │
  │(revise)              │(revise by admin)
  ▼                      ▼
HISTORY              HISTORY
  │
  │(delete admin) ← juga set status=HISTORY (bukan SUPERSEDED)
  ▼
HISTORY

NB: SUPERSEDED tidak pernah di-set oleh action manapun.
    Dipakai hanya sebagai guard di SELECT ... NOT IN ('HISTORY','SUPERSEDED').
```

---

## `insertTransaction()` — Core Function

**File:** [api/transactions.php:99](../api/transactions.php#L99)
**Params (11):** `$db, $uuid, $tanggal, $shift, $mesin, $size, $revCount, $createdBy, $materials, $outputs, $report`
**Blast risk:** LOW — hanya dipanggil oleh `actionSubmit` dan `actionRevise`

Fungsi ini menulis **4 tabel sekaligus** dalam satu transaction PDO:

1. `INSERT transactions` — header (uuid, date, shift, mesin, size, rev, status=DRAFT)
2. `INSERT transaction_materials` × N — per material, dihitung via `calcMaterial()`
3. `INSERT material_hourly_usage` × M — per jam > 0 per material
4. `INSERT production_outputs` × P — jika ada output
5. `INSERT production_reports` — jika ada report

### `calcMaterial(mat, shift)` — [api/transactions.php](../api/transactions.php)
**Input:**
```json
{
  "name": "Material A",
  "supplier": "Supplier X",
  "stockAwal": 100,
  "masuk": 50,
  "retur": 5,
  "reject": 2,
  "hours": [0, 10, 12, 8, 0, 0, 0, 0],
  "photos": ["uuid1.jpg"]
}
```
**Output:** `{stockAwal, masuk, retur, reject, totalProd, totalPakai, sisa, hours24[24]}`

---

## Revise Logic

`actionRevise()` memakai **UUID yang sama** (bukan UUID baru):
1. `beginTransaction()` + `SELECT ... FOR UPDATE` pada row lama — lock untuk cegah race condition (double-click)
2. Validasi: status FINAL → reject (non-admin) | rev >= 3 → reject (non-admin) | bukan owner → reject
3. `UPDATE transactions SET status=HISTORY` (record lama)
4. `insertTransaction(db, oldUuid, ..., nextRev)` — UUID identik, revision+1
5. `commit()` — release lock
6. Max revisi: 3x (non-admin). Admin tidak dibatasi.

**Frontend guard:** tombol SUBMIT di-disable saat klik, re-enable setelah response (success & error) — cegah double-submit.

---

## Lock Date

`getLockDate(db)` → baca `settings.key='LOCK_DATE'`
- Jika `tanggal <= lockDate` dan bukan admin → `fail()`
- Admin selalu bisa input tanggal manapun

---

## Frontend Functions

### `collectOutputs()` — [assets/app/form.js](../assets/app/form.js)
Kumpulkan output rows dari DOM → array untuk `outputsJson`

### `snapshotRows()` — [assets/app/form.js](../assets/app/form.js)
Snapshot material rows sebelum submit

### `renderHistoryCards(targetId, rows, isAdmin)` — [assets/app/form.js](../assets/app/form.js)
Render kartu riwayat laporan. Tiap kartu punya:
- Group badge (A/B/C/D) berdasarkan `rpt.owner`
- Status: DRAFT / FINAL
- Revision badge (v1, v2, v3)
- Loss % highlight (>2% = merah)
- Actions: Edit/Final (user) | Revisi/Delete (admin)

### `populateFormFromHistory(rpt)` — [assets/app/form.js](../assets/app/form.js)
Load data dari history card ke form input untuk revisi

### `resetData()` — [assets/app/form.js](../assets/app/form.js)
Reset form setelah submit berhasil atau cancel edit. Clears: `state.editUuid`, `state.formRows`, `state.outputs`, **`state.materialPhotos`** (foto label ikut terhapus agar tidak carry-over ke laporan berikutnya), semua field RPT.

### Draft Auto-save — [assets/app/form.js](../assets/app/form.js)
- `saveDraft()` — serialize form ke localStorage dengan debounce 3s
- `loadInitialData()` — deteksi draft **sebelum** `renderMaterialTable()`, pre-load `state.formRows` + semua field → nilai tampil langsung saat banner muncul
- `resumeDraft()` — hanya hide banner (state sudah di-load)
- `discardDraft()` — hapus localStorage + reset form ke kosong

---

## Diagram

Lihat: [diagrams/transaction-flow.svg](diagrams/transaction-flow.svg)
