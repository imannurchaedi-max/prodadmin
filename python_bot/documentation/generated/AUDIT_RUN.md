# Audit Run

Generated at: `2026-07-07T05:56:22.825904+00:00`
Repo root: `C:\xampp\htdocs\ProdAdmin\python_bot`

## phase_0 - Preflight & Index Integrity

- Status: `FAIL`
- Objective: Validate audit tooling, graph index, and baseline architecture artifacts.
- Next step: Advance to architecture audit only if all preflight checks pass.

### gitnexus_status
- Status: `PASS`
- Details: GitNexus index must exist and be up-to-date.
- Evidence: `{"stdout": "Repository: C:\\xampp\\htdocs\\ProdAdmin\nIndexed: 23/06/2026, 05.05.40\nIndexed commit: cf03123\nCurrent commit: cf03123\nStatus: ✅ up-to-date", "stderr": ""}`

### gitnexus_doctor
- Status: `PASS`
- Details: GitNexus doctor must confirm graph and full-text search availability.
- Evidence: `{"stdout": "GitNexus Doctor\n\nRuntime\n  OS:        win32/x64\n  Node:      v24.14.0\n  GitNexus:  1.6.5\n  LadybugDB: unknown\n  ONNX:      1.26.0\n\nCapabilities\n  Graph store:     available\n  Full-text search: available\n  VECTOR index:    unavailable\n  Semantic mode:   exact-scan\n  Exact scan limit:    10000 chunks\n  Note:            LadybugDB VECTOR is disabled on this platform; semantic search uses exact scan when embeddings exist.\n\nEmbeddings\n  Backend:   local\n  Device:    auto`

### audit_artifacts_present
- Status: `FAIL`
- Details: Meta index and codebase map must exist before the audit advances.
- Evidence: `{"meta_json": "C:\\xampp\\htdocs\\ProdAdmin\\python_bot\\.gitnexus\\meta.json", "codebase_map": "C:\\xampp\\htdocs\\ProdAdmin\\python_bot\\documentation\\generated\\CODEBASE_MAP.md"}`

## phase_1 - Architecture Conformance

- Status: `FAIL`
- Objective: Confirm that runtime boundaries, major layers, and critical symbols align with the documented architecture.
- Next step: Advance to dependency audit only if runtime layers and critical symbols are visible.

### required_architecture_layers
- Status: `FAIL`
- Details: Codebase map JSON is missing, so architecture layering could not be verified.

### context_actionSubmit
- Status: `FAIL`
- Details: Critical workflow symbol `Function:active/api/transactions.php:actionSubmit` must be traceable in the graph.
- Evidence: `{"stdout": "", "stderr": "file:///C:/Users/DAM-PM-P/AppData/Roaming/npm/node_modules/gitnexus/dist/mcp/local/local-backend.js:329\n        throw new Error(`Multiple repositories indexed. Specify which one with the \"repo\" parameter. Available: ${labels.join(', ')}`);\n              ^\n\nError: Multiple repositories indexed. Specify which one with the \"repo\" parameter. Available: machine_dashboard, ProdAdmin\n    at LocalBackend.resolveRepo (file:///C:/Users/DAM-PM-P/AppData/Roaming/npm/node_m`

### context_doLogin
- Status: `FAIL`
- Details: Critical workflow symbol `Function:active/assets/app/auth.js:doLogin` must be traceable in the graph.
- Evidence: `{"stdout": "", "stderr": "file:///C:/Users/DAM-PM-P/AppData/Roaming/npm/node_modules/gitnexus/dist/mcp/local/local-backend.js:329\n        throw new Error(`Multiple repositories indexed. Specify which one with the \"repo\" parameter. Available: ${labels.join(', ')}`);\n              ^\n\nError: Multiple repositories indexed. Specify which one with the \"repo\" parameter. Available: machine_dashboard, ProdAdmin\n    at LocalBackend.resolveRepo (file:///C:/Users/DAM-PM-P/AppData/Roaming/npm/node_m`

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
- Evidence: `{"files_checked": 7, "failures": []}`

## phase_3 - Function Contract Audit

- Status: `FAIL`
- Objective: Validate that core function entrypoints and documented API contracts remain visible and consistent.
- Next step: Advance to dynamic workflow audit once core function contracts are intact.

### contract_docs_present
- Status: `FAIL`
- Details: Function map and endpoint contract must exist before I/O audit.
- Evidence: `{"endpoint_contract": "C:\\xampp\\htdocs\\ProdAdmin\\python_bot\\documentation\\ENDPOINT_CONTRACT.md", "function_map": "C:\\xampp\\htdocs\\ProdAdmin\\python_bot\\documentation\\GAS_VS_PHP_MAPPING.md"}`

### core_action_coverage
- Status: `FAIL`
- Details: Codebase map JSON missing; could not compare router actions.

### function_trace_actionSubmit
- Status: `FAIL`
- Details: Critical workflow symbol `Function:active/api/transactions.php:actionSubmit` must remain traceable for I/O and outcome audit.
- Evidence: `{"stdout": "", "stderr": "file:///C:/Users/DAM-PM-P/AppData/Roaming/npm/node_modules/gitnexus/dist/mcp/local/local-backend.js:329\n        throw new Error(`Multiple repositories indexed. Specify which one with the \"repo\" parameter. Available: ${labels.join(', ')}`);\n              ^\n\nError: Multiple repositories indexed. Specify which one with the \"repo\" parameter. Available: machine_dashboard, ProdAdmin\n    at LocalBackend.resolveRepo (file:///C:/Users/DAM-PM-P/AppData/Roaming/npm/node_m`

### function_trace_actionRevise
- Status: `FAIL`
- Details: Critical workflow symbol `Function:active/api/transactions.php:actionRevise` must remain traceable for I/O and outcome audit.
- Evidence: `{"stdout": "", "stderr": "file:///C:/Users/DAM-PM-P/AppData/Roaming/npm/node_modules/gitnexus/dist/mcp/local/local-backend.js:329\n        throw new Error(`Multiple repositories indexed. Specify which one with the \"repo\" parameter. Available: ${labels.join(', ')}`);\n              ^\n\nError: Multiple repositories indexed. Specify which one with the \"repo\" parameter. Available: machine_dashboard, ProdAdmin\n    at LocalBackend.resolveRepo (file:///C:/Users/DAM-PM-P/AppData/Roaming/npm/node_m`

### function_trace_actionFinalize
- Status: `FAIL`
- Details: Critical workflow symbol `Function:active/api/transactions.php:actionFinalize` must remain traceable for I/O and outcome audit.
- Evidence: `{"stdout": "", "stderr": "file:///C:/Users/DAM-PM-P/AppData/Roaming/npm/node_modules/gitnexus/dist/mcp/local/local-backend.js:329\n        throw new Error(`Multiple repositories indexed. Specify which one with the \"repo\" parameter. Available: ${labels.join(', ')}`);\n              ^\n\nError: Multiple repositories indexed. Specify which one with the \"repo\" parameter. Available: machine_dashboard, ProdAdmin\n    at LocalBackend.resolveRepo (file:///C:/Users/DAM-PM-P/AppData/Roaming/npm/node_m`

### function_trace_doLogin
- Status: `FAIL`
- Details: Critical workflow symbol `Function:active/assets/app/auth.js:doLogin` must remain traceable for I/O and outcome audit.
- Evidence: `{"stdout": "", "stderr": "file:///C:/Users/DAM-PM-P/AppData/Roaming/npm/node_modules/gitnexus/dist/mcp/local/local-backend.js:329\n        throw new Error(`Multiple repositories indexed. Specify which one with the \"repo\" parameter. Available: ${labels.join(', ')}`);\n              ^\n\nError: Multiple repositories indexed. Specify which one with the \"repo\" parameter. Available: machine_dashboard, ProdAdmin\n    at LocalBackend.resolveRepo (file:///C:/Users/DAM-PM-P/AppData/Roaming/npm/node_m`
