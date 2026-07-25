# Python Bot — LangGraph Architecture

**Cluster:** Python_bot | **Symbols:** 91 | **Cohesion:** 97%
**Semua file:** `python_bot/`
**Last updated:** 2026-07-07 (comprehensive audit — path fixes + regex fix)

---

## Files

| File | Fungsi | LangGraph |
|------|--------|-----------|
| `langgraph_trace_center.py` | Trace call graph + I/O DB map → 9 output files | StateGraph |
| `langgraph_audit_protocol.py` | 4-phase audit protocol (preflight/arch/deps/contracts) | StateGraph (AuditState) |
| `langgraph_codebase_map.py` | Peta file + statistik codebase + API surface per endpoint | StateGraph |
| `langgraph_function_db_map.py` | Peta function → tabel DB (180 fn, 15 tabel) | StateGraph |
| `langgraph_endpoint_ui_map.py` | Audit kontrak endpoint ↔ UI (23 endpoints, 47 calls) | StateGraph |
| `langgraph_dynamic_audit.py` | Dynamic audit runtime localhost | StateGraph |
| `character_audit.py` | Audit karakter / encoding file | Standalone |
| `db_config.py` | DB connection config (`prod_admin` database) | Utility |

---

## Cara Menjalankan

Semua script dijalankan dari folder `python_bot/`:

```bash
cd python_bot

python langgraph_codebase_map.py        # harus dijalankan PERTAMA
python langgraph_function_db_map.py
python langgraph_endpoint_ui_map.py
python langgraph_audit_protocol.py      # butuh CODEBASE_MAP.json
python langgraph_trace_center.py        # butuh FUNCTION_DB_MAP + ENDPOINT_UI_MAP
```

> ⚠️ **Urutan penting:** `codebase_map` → `function_db_map` + `endpoint_ui_map` → `audit_protocol` → `trace_center`

---

## AuditState Schema (langgraph_audit_protocol.py)

```python
class AuditState(TypedDict, total=False):
    repo_root: str
    generated_at: str
    reports_dir: str
    phases: list[PhaseResult]
    outputs: dict[str, str]
```

### Audit Phases (4 phase)

| Phase | ID | Checks |
|-------|-----|--------|
| Preflight & Index Integrity | `phase_0` | gitnexus status, gitnexus doctor, artifact hadir (CODEBASE_MAP.md) |
| Architecture Conformance | `phase_1` | required layers, gitnexus context actionSubmit + doLogin |
| Dependency & Runtime | `phase_2` | python/node/npm/gitnexus version, LangGraph import, PHP lint (30 files) |
| Function Contract Audit | `phase_3` | contract docs, core action coverage, function trace 4 critical symbols |

**Required architecture layers:** `root_runtime`, `backend_api`, `frontend_app`, `backend_config`, `database_sql`, `operations_tools`

**Critical symbols di-trace:** `actionSubmit`, `actionRevise`, `actionFinalize`, `doLogin`

---

## LangGraph Flow — trace_center

```
START
  └─► collect_consumers  (scan JS/PHP untuk App.api() calls)
  └─► collect_backend    (scan PHP files untuk action handlers)
       └─► enrich_gitnexus()
            └─ gitnexus context/impact per core symbol (-r ProdAdmin)
           └─► summarize()
                └─► write_outputs()  ← 9 files ke documentation/generated/
                     └─► END
```

---

## Output Files

Semua di-generate ke `documentation/generated/`:

| File | Script | Isi |
|------|--------|-----|
| `CODEBASE_MAP.json/.md` | `langgraph_codebase_map.py` | Inventori file + API surface |
| `FUNCTION_DB_MAP.json/.md` | `langgraph_function_db_map.py` | 180 fn → 15 tabel DB, 214 links |
| `ENDPOINT_UI_MAP.json/.md` | `langgraph_endpoint_ui_map.py` | 23 endpoints, 47 calls, MEDIUM findings |
| `AUDIT_RUN.json/.md` | `langgraph_audit_protocol.py` | Hasil 4-phase audit |
| `FUNCTION_DEPENDENCY_MAP.json/.md/.svg` | `langgraph_trace_center.py` | Call graph function → function |
| `IO_DEPENDENCY_MAP.json/.md/.svg` | `langgraph_trace_center.py` | Frontend → endpoint → backend I/O |
| `IO_DB_CONNECTION_MAP.json/.md/.svg` | `langgraph_trace_center.py` | Endpoint → DB table |
| `TRACE_CENTER.md` | `langgraph_trace_center.py` | Entry point tracing per symptom |

---

## Regex Patterns (dari source — setelah bug fix)

```python
# PHP: endpoint detection dari match($action) — FIXED 2026-07-07
# Bug lama: \n\}; tidak match indent. Fix: \n\s*\};
for block in re.findall(r"match\(\$action\)\s*\{(.*?)\n\s*\};", text, re.S):
    endpoints.update(re.findall(r"^\s*'([A-Za-z0-9_]+)'\s*=>", block, re.M))

# PHP functions
PHP_FUNCTION_REGEX = r"function\s+([A-Za-z_][A-Za-z0-9_]*)\s*\("

# JS API calls
JS_API_REGEX = r"(?:App\.api|rawJson)\(\s*([`'\"])(.+?)\1"
```

---

## Bug Fixes — 2026-07-07

Bug-bug ini ditemukan saat comprehensive audit dan sudah diperbaiki di semua script:

| # | File | Bug | Fix |
|---|------|-----|-----|
| 1 | Semua 4 scripts | `ACTIVE_ROOT = PROJECT_ROOT / "active"` — folder tidak ada | `ACTIVE_ROOT = PROJECT_ROOT` |
| 2 | `trace_center.py` | `GITNEXUS_REPO = "prod3-local"` — repo name salah | Ganti ke `"ProdAdmin"` |
| 3 | `audit_protocol.py`, `codebase_map.py` | `--repo default="."` → resolve ke `python_bot/` | Default ke `str(ROOT)` |
| 4 | `endpoint_ui_map.py`, `trace_center.py` | `backend_file = f"active/{path}"` — prefix salah | Strip prefix, pakai path langsung |
| 5 | `audit_protocol.py`, `endpoint_ui_map.py` | `gitnexus context` tanpa `-r ProdAdmin` → error multi-repo | Tambah flag `-r ProdAdmin` |
| 6 | `codebase_map.py` | Regex `\n\};` tidak match `\n    \};` → auth.php 0 actions | Fix ke `\n\s*\};` |
| 7 | `audit_protocol.py` | Required `legacy_gas_reference` layer — sisa era GAS | Hapus dari required list |
| 8 | `audit_protocol.py` | Cek artifact `ENDPOINT_CONTRACT.md` + `GAS_VS_PHP_MAPPING.md` | Ganti ke `ENDPOINT_UI_MAP.md` + `FUNCTION_DB_MAP.md` |

---

## Prerequisites

| Tool | Version | Status | Catatan |
|------|---------|--------|---------|
| Python | 3.14.5 | ✅ | `python --version` |
| LangGraph | 1.2.4 | ✅ | `pip show langgraph` |
| LangGraph SDK | 0.4.2 | ✅ | `pip show langgraph-sdk` |
| Graphviz (Python) | 0.21 | ✅ | `pip show graphviz` |
| Graphviz CLI (`dot`) | 15.0.0 | ✅ | `C:\Program Files\Graphviz\bin\` — di PATH |
| psycopg2-binary | 2.9.12 | ✅ | `pip show psycopg2-binary` |
| Anthropic SDK | 0.107.1 | ✅ | `pip show anthropic` |
| Node.js | v24.14.0 | ✅ | untuk GitNexus CLI |
| GitNexus CLI | 1.6.5 | ✅ | `npx gitnexus --version` |

**DB Config:** `db_config.py` → `prod_admin` database (bukan `prodadmin`)

---

## Known Limitations

| Endpoint | Status | Keterangan |
|----------|--------|------------|
| `api/init.php` | `[]` | Tidak pakai `match($action)` — serve GET tanpa action routing |
| `api/photos.php` | `[]` | Dispatch via `REQUEST_METHOD`, bukan `$action` |
| `api/settings.php` | `[]` | Dispatch via `REQUEST_METHOD` (GET/POST) |
| `actionLogin`/`actionSubmit` gitnexus | Ambiguous | Ada `_Conflict.php` duplikat. Hilang jika conflict files dihapus |
| `loadHistory`/`loadInitialData` gitnexus | Not found | JS identifier tidak match gitnexus index — bukan bug fungsional |

---

Diagram: [diagrams/python-bot.svg](diagrams/python-bot.svg)
