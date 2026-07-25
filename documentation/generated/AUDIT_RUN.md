# Audit Run

Generated at: `2026-07-25T05:16:53.168167+00:00`
Repo root: `C:\xampp\htdocs\ProdAdmin`

## phase_0 - Preflight & Index Integrity

- Status: `PASS`
- Objective: Validate audit tooling, graph index, and baseline architecture artifacts.
- Next step: Advance to architecture audit only if all preflight checks pass.

### gitnexus_status
- Status: `PASS`
- Details: GitNexus index must exist and be up-to-date.
- Evidence: `{"stdout": "Repository: C:\\xampp\\htdocs\\ProdAdmin\nIndexed: 25/07/2026, 12.15.50\nIndexed commit: 8b8b3e9\nCurrent commit: 8b8b3e9\nStatus: ✅ up-to-date", "stderr": ""}`

### gitnexus_doctor
- Status: `PASS`
- Details: GitNexus doctor must confirm graph and full-text search availability.
- Evidence: `{"stdout": "GitNexus Doctor\n\nRuntime\n  OS:        win32/x64\n  Node:      v24.14.0\n  GitNexus:  1.6.5\n  LadybugDB: unknown\n  ONNX:      1.26.0\n\nCapabilities\n  Graph store:     available\n  Full-text search: available\n  VECTOR index:    unavailable\n  Semantic mode:   exact-scan\n  Exact scan limit:    10000 chunks\n  Note:            LadybugDB VECTOR is disabled on this platform; semantic search uses exact scan when embeddings exist.\n\nEmbeddings\n  Backend:   local\n  Device:    auto`

### audit_artifacts_present
- Status: `PASS`
- Details: Meta index and codebase map must exist before the audit advances.
- Evidence: `{"meta_json": "C:\\xampp\\htdocs\\ProdAdmin\\.gitnexus\\meta.json", "codebase_map": "C:\\xampp\\htdocs\\ProdAdmin\\documentation\\generated\\CODEBASE_MAP.md"}`

## phase_1 - Architecture Conformance

- Status: `PASS`
- Objective: Confirm that runtime boundaries, major layers, and critical symbols align with the documented architecture.
- Next step: Advance to dependency audit only if runtime layers and critical symbols are visible.

### required_architecture_layers
- Status: `PASS`
- Details: All architecture layers required by the runtime model must be discoverable.
- Evidence: `{"missing_layers": [], "present_layers": ["backend_api", "backend_config", "database_sql", "documentation", "frontend_app", "frontend_assets", "operations_tools", "other", "project_docs", "root_runtime"]}`

### context_actionSubmit
- Status: `PASS`
- Details: Critical workflow symbol `Function:api/transactions.php:actionSubmit` must be traceable in the graph.
- Evidence: `{"stdout": "{\n  \"status\": \"found\",\n  \"symbol\": {\n    \"uid\": \"Function:api/transactions.php:actionSubmit\",\n    \"name\": \"actionSubmit\",\n    \"kind\": \"Function\",\n    \"filePath\": \"api/transactions.php\",\n    \"startLine\": 222,\n    \"endLine\": 287\n  },\n  \"incoming\": {\n    \"calls\": [\n      {\n        \"uid\": \"File:api/transactions.php\",\n        \"name\": \"transactions.php\",\n        \"filePath\": \"api/transactions.php\"\n      }\n    ]\n  },\n  \"outgoing\"`

### context_doLogin
- Status: `PASS`
- Details: Critical workflow symbol `Function:assets/app/auth.js:doLogin` must be traceable in the graph.
- Evidence: `{"stdout": "{\n  \"status\": \"found\",\n  \"symbol\": {\n    \"uid\": \"Function:assets/app/auth.js:doLogin\",\n    \"name\": \"doLogin\",\n    \"kind\": \"Function\",\n    \"filePath\": \"assets/app/auth.js\",\n    \"startLine\": 232,\n    \"endLine\": 283\n  },\n  \"incoming\": {\n    \"calls\": [\n      {\n        \"uid\": \"Function:assets/app/auth.js:doLogin\",\n        \"name\": \"doLogin\",\n        \"filePath\": \"assets/app/auth.js\"\n      },\n      {\n        \"uid\": \"Function:asse`

## phase_2 - Dependency & Runtime Audit

- Status: `PASS`
- Objective: Validate interpreter/tool availability and syntax health for critical runtime files.
- Next step: Advance to contract audit only if runtime dependencies are present and syntax checks are clean.

### python_version
- Status: `PASS`
- Details: python_version must be available for the audit/runtime workflow.
- Evidence: `{"stdout": "Python 3.14.5", "stderr": ""}`

### node_version
- Status: `PASS`
- Details: node_version must be available for the audit/runtime workflow.
- Evidence: `{"stdout": "v24.14.0", "stderr": ""}`

### npm_version
- Status: `PASS`
- Details: npm_version must be available for the audit/runtime workflow.
- Evidence: `{"stdout": "11.9.0", "stderr": ""}`

### gitnexus_version
- Status: `PASS`
- Details: gitnexus_version must be available for the audit/runtime workflow.
- Evidence: `{"stdout": "1.6.5", "stderr": ""}`

### langgraph_stack
- Status: `PASS`
- Details: LangGraph core and required checkpoint/prebuilt packages must import cleanly.
- Evidence: `{"stdout": "LANGGRAPH_STACK_OK", "stderr": ""}`

### php_lint
- Status: `PASS`
- Details: All PHP files must pass syntax linting.
- Evidence: `{"files_checked": 30, "failures": []}`

## phase_3 - Function Contract Audit

- Status: `PASS`
- Objective: Validate that core function entrypoints and documented API contracts remain visible and consistent.
- Next step: Advance to dynamic workflow audit once core function contracts are intact.

### contract_docs_present
- Status: `PASS`
- Details: Function map and endpoint contract must exist before I/O audit.
- Evidence: `{"endpoint_contract": "C:\\xampp\\htdocs\\ProdAdmin\\documentation\\generated\\ENDPOINT_UI_MAP.md", "function_map": "C:\\xampp\\htdocs\\ProdAdmin\\documentation\\generated\\FUNCTION_DB_MAP.md"}`

### core_action_coverage
- Status: `PASS`
- Details: Core auth and transaction actions must be discoverable from the current codebase map.
- Evidence: `{"missing_actions": {}, "detected_actions": {"api/auth.php": ["changePassword", "checkTakeover", "forceLogin", "login", "logout", "takeoverDecision", "takeoverStatus", "validate"], "api/transactions.php": ["delete", "diff", "finalize", "previousStock", "revise", "submit"], "api/migration_api.php": ["photo_batch", "photo_retry", "photo_stats", "progress", "reset_progress", "run_import", "run_setup_photos", "upload"]}}`

### function_trace_actionSubmit
- Status: `PASS`
- Details: Critical workflow symbol `Function:api/transactions.php:actionSubmit` must remain traceable for I/O and outcome audit.
- Evidence: `{"stdout": "{\n  \"status\": \"found\",\n  \"symbol\": {\n    \"uid\": \"Function:api/transactions.php:actionSubmit\",\n    \"name\": \"actionSubmit\",\n    \"kind\": \"Function\",\n    \"filePath\": \"api/transactions.php\",\n    \"startLine\": 222,\n    \"endLine\": 287\n  },\n  \"incoming\": {\n    \"calls\": [\n      {\n        \"uid\": \"File:api/transactions.php\",\n        \"name\": \"transactions.php\",\n        \"filePath\": \"api/transactions.php\"\n      }\n    ]\n  },\n  \"outgoing\"`

### function_trace_actionRevise
- Status: `PASS`
- Details: Critical workflow symbol `Function:api/transactions.php:actionRevise` must remain traceable for I/O and outcome audit.
- Evidence: `{"stdout": "{\n  \"status\": \"found\",\n  \"symbol\": {\n    \"uid\": \"Function:api/transactions.php:actionRevise\",\n    \"name\": \"actionRevise\",\n    \"kind\": \"Function\",\n    \"filePath\": \"api/transactions.php\",\n    \"startLine\": 290,\n    \"endLine\": 383\n  },\n  \"incoming\": {\n    \"calls\": [\n      {\n        \"uid\": \"File:api/transactions.php\",\n        \"name\": \"transactions.php\",\n        \"filePath\": \"api/transactions.php\"\n      }\n    ]\n  },\n  \"outgoing\"`

### function_trace_actionFinalize
- Status: `PASS`
- Details: Critical workflow symbol `Function:api/transactions.php:actionFinalize` must remain traceable for I/O and outcome audit.
- Evidence: `{"stdout": "{\n  \"status\": \"found\",\n  \"symbol\": {\n    \"uid\": \"Function:api/transactions.php:actionFinalize\",\n    \"name\": \"actionFinalize\",\n    \"kind\": \"Function\",\n    \"filePath\": \"api/transactions.php\",\n    \"startLine\": 386,\n    \"endLine\": 413\n  },\n  \"incoming\": {\n    \"calls\": [\n      {\n        \"uid\": \"File:api/transactions.php\",\n        \"name\": \"transactions.php\",\n        \"filePath\": \"api/transactions.php\"\n      }\n    ]\n  },\n  \"outgoi`

### function_trace_doLogin
- Status: `PASS`
- Details: Critical workflow symbol `Function:assets/app/auth.js:doLogin` must remain traceable for I/O and outcome audit.
- Evidence: `{"stdout": "{\n  \"status\": \"found\",\n  \"symbol\": {\n    \"uid\": \"Function:assets/app/auth.js:doLogin\",\n    \"name\": \"doLogin\",\n    \"kind\": \"Function\",\n    \"filePath\": \"assets/app/auth.js\",\n    \"startLine\": 232,\n    \"endLine\": 283\n  },\n  \"incoming\": {\n    \"calls\": [\n      {\n        \"uid\": \"Function:assets/app/auth.js:doLogin\",\n        \"name\": \"doLogin\",\n        \"filePath\": \"assets/app/auth.js\"\n      },\n      {\n        \"uid\": \"Function:asse`
