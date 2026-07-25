# ProdAdmin — Documentation Index

**Generated:** 2026-06-08 | **Last updated:** 2026-07-07 (comprehensive audit + function mapping) | **GitNexus:** 1.812 nodes · 2.934 edges · 114 flows

---

## Files

| File | Isi |
|------|-----|
| [architecture-overview.md](architecture-overview.md) | High-level arsitektur 3-cluster + layer diagram |
| [auth-flow.md](auth-flow.md) | Auth, session, takeover flow lengkap |
| [transaction-flow.md](transaction-flow.md) | Submit / Revise / Finalize / Delete flow |
| [function-map.md](function-map.md) | Peta seluruh function per cluster & file |
| [io-dependency.md](io-dependency.md) | Input → Output dependency per endpoint |
| [blast-radius.md](blast-radius.md) | Blast radius semua symbol kritis |
| [python-bot.md](python-bot.md) | LangGraph Python bot — audit & trace architecture |

## Diagrams (SVG rendered)

| Diagram | File |
|---------|------|
| Architecture Overview | [diagrams/architecture.svg](diagrams/architecture.svg) |
| Auth Flow | [diagrams/auth-flow.svg](diagrams/auth-flow.svg) |
| Transaction Flow | [diagrams/transaction-flow.svg](diagrams/transaction-flow.svg) |
| Function Dependency Map | [diagrams/function-map.svg](diagrams/function-map.svg) |
| I/O Dependency | [diagrams/io-dependency.svg](diagrams/io-dependency.svg) |
| Blast Radius | [diagrams/blast-radius.svg](diagrams/blast-radius.svg) |
| Python Bot LangGraph | [diagrams/python-bot.svg](diagrams/python-bot.svg) |

## Generated Artifacts (auto-generated, do not edit manually)

> Di-generate oleh LangGraph Python bot. Re-generate dengan: `cd python_bot && python langgraph_<script>.py`

| File | Script | Isi |
|------|--------|-----|
| [generated/AUDIT_RUN.md](../documentation/generated/AUDIT_RUN.md) | `langgraph_audit_protocol.py` | 4-phase audit: preflight, arsitektur, deps, contracts |
| [generated/CODEBASE_MAP.md](../documentation/generated/CODEBASE_MAP.md) | `langgraph_codebase_map.py` | Inventori seluruh file + API surface per endpoint |
| [generated/FUNCTION_DB_MAP.md](../documentation/generated/FUNCTION_DB_MAP.md) | `langgraph_function_db_map.py` | Peta function → tabel DB (180 fn, 15 tabel, 214 links) |
| [generated/ENDPOINT_UI_MAP.md](../documentation/generated/ENDPOINT_UI_MAP.md) | `langgraph_endpoint_ui_map.py` | Audit kontrak 23 endpoint vs 47 frontend calls |
| [generated/FUNCTION_DEPENDENCY_MAP.md](../documentation/generated/FUNCTION_DEPENDENCY_MAP.md) | `langgraph_trace_center.py` | Call graph function → function → endpoint |
| [generated/IO_DEPENDENCY_MAP.md](../documentation/generated/IO_DEPENDENCY_MAP.md) | `langgraph_trace_center.py` | Frontend consumer → endpoint → backend I/O contract |
| [generated/IO_DB_CONNECTION_MAP.md](../documentation/generated/IO_DB_CONNECTION_MAP.md) | `langgraph_trace_center.py` | Endpoint → backend block → tabel DB |
| [TRACE_CENTER.md](../documentation/TRACE_CENTER.md) | `langgraph_trace_center.py` | Entry point tracing per symptom (login/history/submit) |

---

## Clusters

| Cluster | Symbols | Cohesion | File Utama |
|---------|---------|----------|------------|
| **App** | 53 | 76% | `assets/app/api.js`, `auth.js`, `form.js`, `admin.js` |
| **Api** | 84 | 90% | `api/auth.php`, `api/transactions.php`, `api/history.php` |
| **Python_bot** | 91 | 97% | `python_bot/langgraph_*.py` |

## Critical Symbols (JANGAN ubah tanpa blast analysis)

| Symbol | File | Blast Risk | Impact |
|--------|------|-----------|--------|
| `getDb` | `config/database.php` | **CRITICAL** | 47 symbols, 28 processes |
| `requireSession` | `config/auth_helper.php` | **CRITICAL** | 15 symbols, 6 processes |
| `insertTransaction` | `api/transactions.php` | LOW | actionSubmit + actionRevise |
| `doLogin` | `assets/app/auth.js` | LOW | submitLogin → 5 processes |
