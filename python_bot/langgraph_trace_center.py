from __future__ import annotations

import json
import os
import re
import shutil
import subprocess
from collections import defaultdict
from dataclasses import dataclass
from datetime import datetime, timedelta, timezone
from pathlib import Path
from typing import Any, TypedDict

from langgraph.graph import END, START, StateGraph


PROJECT_ROOT = Path(__file__).resolve().parents[1]
ACTIVE_ROOT = PROJECT_ROOT / "active"
API_DIR = ACTIVE_ROOT / "api"
JS_DIR = ACTIVE_ROOT / "assets" / "app"
DOC_DIR = PROJECT_ROOT / "documentation"
GENERATED_DIR = DOC_DIR / "generated"
FUNCTION_DB_JSON = GENERATED_DIR / "FUNCTION_DB_MAP.json"
ENDPOINT_UI_JSON = GENERATED_DIR / "ENDPOINT_UI_MAP.json"
GIT_CMD_DIR = Path(r"C:\Program Files\Git\cmd")
DOT_EXE = "dot"
GITNEXUS_REPO = "prod3-local"

PHP_FUNCTION_REGEX = re.compile(r"function\s+([A-Za-z_][A-Za-z0-9_]*)\s*\(", re.M)
JS_FUNCTION_REGEXES = (
    re.compile(r"async\s+function\s+([A-Za-z_][A-Za-z0-9_]*)\s*\(", re.M),
    re.compile(r"function\s+([A-Za-z_][A-Za-z0-9_]*)\s*\(", re.M),
    re.compile(r"window\.([A-Za-z_][A-Za-z0-9_]*)\s*=\s*async\s+function\s*\(", re.M),
    re.compile(r"window\.([A-Za-z_][A-Za-z0-9_]*)\s*=\s*function\s*\(", re.M),
    re.compile(r"const\s+([A-Za-z_][A-Za-z0-9_]*)\s*=\s*async\s*\([^)]*\)\s*=>", re.M),
    re.compile(r"const\s+([A-Za-z_][A-Za-z0-9_]*)\s*=\s*\([^)]*\)\s*=>", re.M),
)
ACTION_BLOCK_REGEX = re.compile(
    r"""if\s*\(\s*\$action\s*===\s*['"]([^'"]+)['"](?P<tail>.*?)\)\s*\{""",
    re.S,
)
METHOD_BLOCK_REGEX = re.compile(
    r"""if\s*\(\s*\$_SERVER\[['"]REQUEST_METHOD['"]\]\s*===\s*['"]([^'"]+)['"](?P<tail>.*?)\)\s*\{""",
    re.S,
)
PHP_CALL_REGEX = re.compile(r"\b([A-Za-z_][A-Za-z0-9_]*)\s*\(")
JS_CALL_REGEX = re.compile(r"\b([A-Za-z_][A-Za-z0-9_]*)\s*\(")
PHP_PARAM_REGEX = re.compile(r"param\(\s*['\"]([A-Za-z_][A-Za-z0-9_]*)['\"]")
JS_API_REGEX = re.compile(
    r"""(?:App\.api|rawJson)\(\s*([`'"])(.+?)\1(?P<tail>.*?)\)""",
    re.S,
)
QUERY_PARAM_REGEX = re.compile(r"([A-Za-z_][A-Za-z0-9_]*)=([^&]*)")
CALL_EXCLUDE = {
    "if", "for", "while", "switch", "catch", "return", "function", "await", "console",
    "setTimeout", "setInterval", "clearInterval", "fetch", "JSON", "Object", "Array",
    "Number", "String", "Date", "Math", "encodeURIComponent", "decodeURIComponent",
    "alert", "confirm", "prompt", "parseInt", "parseFloat", "isNaN", "trim", "map",
    "forEach", "filter", "find", "join", "push", "replace", "test", "match", "closest",
    "querySelector", "querySelectorAll", "getElementById", "addEventListener", "remove",
    "append", "appendChild", "classList", "textContent", "innerHTML", "click",
}


class TraceState(TypedDict, total=False):
    generated_at: str
    function_db: dict[str, Any]
    endpoint_ui: dict[str, Any]
    php_blocks: list[dict[str, Any]]
    js_blocks: list[dict[str, Any]]
    function_edges: list[dict[str, Any]]
    endpoint_contracts: list[dict[str, Any]]
    io_db_contracts: list[dict[str, Any]]
    gitnexus: dict[str, Any]
    summary: dict[str, Any]
    outputs: dict[str, str]


@dataclass
class CodeBlock:
    file_path: str
    block_name: str
    block_kind: str
    start_line: int
    end_line: int
    body: str


def now_iso() -> str:
    return datetime.now(timezone.utc).isoformat()


def read_json(path: Path) -> dict[str, Any]:
    return json.loads(path.read_text(encoding="utf-8"))


def line_number(text: str, index: int) -> int:
    return text[:index].count("\n") + 1


def find_matching_brace(text: str, brace_start: int) -> int:
    depth = 0
    for idx in range(brace_start, len(text)):
        ch = text[idx]
        if ch == "{":
            depth += 1
        elif ch == "}":
            depth -= 1
            if depth == 0:
                return idx
    return brace_start


def make_block(path: Path, text: str, name: str, kind: str, start_idx: int, end_idx: int) -> CodeBlock:
    return CodeBlock(
        file_path=str(path.relative_to(PROJECT_ROOT)).replace("\\", "/"),
        block_name=name,
        block_kind=kind,
        start_line=line_number(text, start_idx),
        end_line=line_number(text, end_idx),
        body=text[start_idx:end_idx + 1],
    )


def normalize_endpoint(endpoint: str) -> tuple[str, str]:
    endpoint = endpoint.strip()
    action_match = re.search(r"action=([A-Za-z_][A-Za-z0-9_]*)", endpoint)
    base = endpoint.split("?", 1)[0].lstrip("./")
    if action_match:
        return base, action_match.group(1)
    return base, "__default__"


def function_key(file_path: str, block_name: str) -> str:
    return f"{file_path}::{block_name}"


def js_input_keys_from_tail(tail: str) -> list[str]:
    keys = set(re.findall(r'["\']([A-Za-z_][A-Za-z0-9_]*)["\']\s*:', tail))
    keys.update(re.findall(r"\b([A-Za-z_][A-Za-z0-9_]*)\s*:", tail))
    return sorted(keys)


def safe_label(value: str) -> str:
    return value.replace("\\", "\\\\").replace('"', '\\"')


def run_gitnexus_json(args: list[str]) -> dict[str, Any]:
    env = dict(os.environ)
    npm_bin = Path(os.environ.get("APPDATA", "")) / "npm"
    env["PATH"] = ";".join(part for part in [str(npm_bin), str(GIT_CMD_DIR), env.get("PATH", "")] if part)
    gitnexus_bin = shutil.which("gitnexus.cmd", path=env["PATH"]) or shutil.which("gitnexus", path=env["PATH"]) or "gitnexus"
    proc = subprocess.run(
        [gitnexus_bin, *args],
        cwd=str(PROJECT_ROOT),
        capture_output=True,
        text=True,
        timeout=60,
        env=env,
    )
    if proc.returncode != 0:
        return {"error": proc.stderr.strip() or proc.stdout.strip()}
    try:
        return json.loads(proc.stdout)
    except json.JSONDecodeError:
        return {"raw": proc.stdout.strip()}


def run_gitnexus_text(args: list[str]) -> str:
    env = dict(os.environ)
    npm_bin = Path(os.environ.get("APPDATA", "")) / "npm"
    env["PATH"] = ";".join(part for part in [str(npm_bin), str(GIT_CMD_DIR), env.get("PATH", "")] if part)
    gitnexus_bin = shutil.which("gitnexus.cmd", path=env["PATH"]) or shutil.which("gitnexus", path=env["PATH"]) or "gitnexus"
    proc = subprocess.run(
        [gitnexus_bin, *args],
        cwd=str(PROJECT_ROOT),
        capture_output=True,
        text=True,
        timeout=60,
        env=env,
    )
    return proc.stdout.strip() if proc.returncode == 0 else (proc.stderr.strip() or proc.stdout.strip())


def load_existing(state: TraceState) -> TraceState:
    return {
        "function_db": read_json(FUNCTION_DB_JSON),
        "endpoint_ui": read_json(ENDPOINT_UI_JSON),
    }


def collect_php_blocks(state: TraceState) -> TraceState:
    blocks: list[dict[str, Any]] = []
    for path in sorted(API_DIR.glob("*.php")):
        text = path.read_text(encoding="utf-8", errors="ignore")
        named_spans: list[tuple[int, int]] = []
        for match in PHP_FUNCTION_REGEX.finditer(text):
            name = match.group(1)
            brace_start = text.find("{", match.end())
            if brace_start == -1:
                continue
            end_idx = find_matching_brace(text, brace_start)
            block = make_block(path, text, name, "function", match.start(), end_idx)
            blocks.append(block.__dict__)
            named_spans.append((match.start(), end_idx))
        for pattern, kind in ((ACTION_BLOCK_REGEX, "action"), (METHOD_BLOCK_REGEX, "method")):
            for match in pattern.finditer(text):
                start_idx = match.start()
                if any(a <= start_idx <= b for a, b in named_spans):
                    continue
                brace_start = text.find("{", match.end() - 1)
                if brace_start == -1:
                    continue
                end_idx = find_matching_brace(text, brace_start)
                label = f"{kind}:{match.group(1)}"
                blocks.append(make_block(path, text, label, kind, start_idx, end_idx).__dict__)
    blocks.sort(key=lambda item: (item["file_path"], item["start_line"]))
    return {"php_blocks": blocks}


def collect_js_blocks(state: TraceState) -> TraceState:
    blocks: list[dict[str, Any]] = []
    for path in sorted(JS_DIR.glob("*.js")):
        text = path.read_text(encoding="utf-8", errors="ignore")
        seen: set[tuple[str, int]] = set()
        for pattern in JS_FUNCTION_REGEXES:
            for match in pattern.finditer(text):
                name = match.group(1)
                start_idx = match.start()
                if (name, start_idx) in seen:
                    continue
                seen.add((name, start_idx))
                brace_start = text.find("{", match.end())
                if brace_start == -1:
                    continue
                end_idx = find_matching_brace(text, brace_start)
                blocks.append(make_block(path, text, name, "function", start_idx, end_idx).__dict__)
    blocks.sort(key=lambda item: (item["file_path"], item["start_line"]))
    return {"js_blocks": blocks}


def build_function_edges(state: TraceState) -> TraceState:
    php_blocks = state["php_blocks"]
    js_blocks = state["js_blocks"]
    local_php = defaultdict(dict)
    local_js = defaultdict(dict)
    global_js = {}
    for block in php_blocks:
        if block["block_kind"] == "function":
            local_php[block["file_path"]][block["block_name"]] = block
    for block in js_blocks:
        local_js[block["file_path"]][block["block_name"]] = block
        global_js[block["block_name"]] = block

    edges: list[dict[str, Any]] = []
    seen: set[tuple[str, str, str]] = set()

    for block in php_blocks:
        source = function_key(block["file_path"], block["block_name"])
        for match in PHP_CALL_REGEX.finditer(block["body"]):
            callee_name = match.group(1)
            if callee_name in CALL_EXCLUDE:
                continue
            callee = local_php[block["file_path"]].get(callee_name)
            if not callee:
                for file_map in local_php.values():
                    if callee_name in file_map:
                        callee = file_map[callee_name]
                        break
            if not callee:
                continue
            target = function_key(callee["file_path"], callee["block_name"])
            edge = (source, target, "php_call")
            if source == target or edge in seen:
                continue
            seen.add(edge)
            edges.append({
                "source": source,
                "target": target,
                "kind": "php_call",
                "source_file": block["file_path"],
                "target_file": callee["file_path"],
            })

    for block in js_blocks:
        source = function_key(block["file_path"], block["block_name"])
        for match in JS_CALL_REGEX.finditer(block["body"]):
            callee_name = match.group(1)
            if callee_name in CALL_EXCLUDE:
                continue
            callee = local_js[block["file_path"]].get(callee_name) or global_js.get(callee_name)
            if not callee:
                continue
            target = function_key(callee["file_path"], callee["block_name"])
            edge = (source, target, "js_call")
            if source == target or edge in seen:
                continue
            seen.add(edge)
            edges.append({
                "source": source,
                "target": target,
                "kind": "js_call",
                "source_file": block["file_path"],
                "target_file": callee["file_path"],
            })
        for match in JS_API_REGEX.finditer(block["body"]):
            endpoint, action = normalize_endpoint(match.group(2))
            endpoint_key = f"endpoint::{endpoint}::{action}"
            edge = (source, endpoint_key, "api_call")
            if edge in seen:
                continue
            seen.add(edge)
            edges.append({
                "source": source,
                "target": endpoint_key,
                "kind": "api_call",
                "source_file": block["file_path"],
                "target_file": endpoint,
            })

    return {"function_edges": edges}


def build_endpoint_contracts(state: TraceState) -> TraceState:
    endpoint_ui = state["endpoint_ui"]
    backend = endpoint_ui["backend"]
    consumers = endpoint_ui["consumers"]
    php_block_index = {
        function_key(item["file_path"], item["block_name"]): item
        for item in state["php_blocks"]
    }
    contracts: list[dict[str, Any]] = []
    grouped: dict[tuple[str, str, str], list[dict[str, Any]]] = defaultdict(list)
    for consumer in consumers:
        grouped[(consumer["endpoint_base"], consumer["endpoint_action"], consumer["method"])].append(consumer)

    for (endpoint_base, action, method), uses in sorted(grouped.items()):
        backend_file = f"active/{endpoint_base.lstrip('./')}" if endpoint_base.startswith("api/") else f"active/api/{endpoint_base.lstrip('./')}"
        action_meta = backend.get(backend_file, {}).get("actions", {}).get(action)
        if action_meta is None and action == "__default__":
            action_meta = backend.get(backend_file, {}).get("actions", {}).get(method)
        block_name_candidates = [f"action:{action}", f"method:{method}", "__file_scope__"]
        fn_name = f"action{action[:1].upper()}{action[1:]}" if action not in {"__default__"} else "__file_scope__"
        block_name_candidates.append(fn_name)
        block = None
        for candidate in block_name_candidates:
            key = function_key(backend_file, candidate)
            if key in php_block_index:
                block = php_block_index[key]
                break
        backend_inputs = sorted(set(PHP_PARAM_REGEX.findall(block["body"]))) if block else []
        frontend_inputs = sorted({
            key
            for use in uses
            for key in (
                [name for name, _ in QUERY_PARAM_REGEX.findall(use["endpoint"])]
                + js_input_keys_from_tail(use.get("snippet", ""))
            )
            if key not in {"action"}
        })
        contracts.append({
            "endpoint_key": f"{endpoint_base}::{action}::{method}",
            "endpoint_base": endpoint_base,
            "endpoint_action": action,
            "method": method,
            "frontend_consumers": sorted({f"{use['consumer_file']}::{use['consumer_function']}" for use in uses}),
            "frontend_input_keys": frontend_inputs,
            "backend_file": backend_file,
            "backend_block": block["block_name"] if block else None,
            "backend_input_keys": backend_inputs,
            "backend_response_keys": action_meta.get("response_keys", []) if action_meta else [],
            "failure_examples": action_meta.get("fail_examples", []) if action_meta else [],
        })
    return {"endpoint_contracts": contracts}


def build_io_db_contracts(state: TraceState) -> TraceState:
    function_db = state["function_db"]
    link_rows = function_db["links"]
    link_map: dict[tuple[str, str], list[dict[str, Any]]] = defaultdict(list)
    for row in link_rows:
        link_map[(row["file_path"], row["function_name"])].append(row)

    io_db: list[dict[str, Any]] = []
    for contract in state["endpoint_contracts"]:
        file_path = contract["backend_file"]
        block_name = contract["backend_block"] or "__file_scope__"
        candidate_keys = [
            (file_path, block_name),
            (file_path, f"action:{contract['endpoint_action']}"),
            (file_path, f"method:{contract['method']}"),
            (file_path, "__file_scope__"),
            (file_path, f"action{contract['endpoint_action'][:1].upper()}{contract['endpoint_action'][1:]}")
            if contract["endpoint_action"] != "__default__" else (file_path, "__file_scope__"),
        ]
        tables: dict[str, set[str]] = defaultdict(set)
        via: dict[str, set[str]] = defaultdict(set)
        for key in candidate_keys:
            if not isinstance(key, tuple):
                continue
            for link in link_map.get(key, []):
                tables[link["table"]].update(link.get("operations", []))
                for item in link.get("via", []):
                    via[link["table"]].add(item)
        io_db.append({
            "endpoint_key": contract["endpoint_key"],
            "backend_file": file_path,
            "backend_block": block_name,
            "input_keys": contract["backend_input_keys"],
            "response_keys": contract["backend_response_keys"],
            "tables": [
                {
                    "table": table,
                    "operations": sorted(ops),
                    "via": sorted(via.get(table, set())),
                }
                for table, ops in sorted(tables.items())
            ],
        })
    return {"io_db_contracts": io_db}


def enrich_gitnexus(state: TraceState) -> TraceState:
    core_symbols = [
        "actionLogin",
        "actionTakeoverStatus",
        "actionSubmit",
        "actionRevise",
        "actionFinalize",
        "loadInitialData",
        "loadHistory",
        "loadAdminHistory",
        "submitData",
        "buildSubmitPayload",
    ]
    contexts = {symbol: run_gitnexus_json(["context", "-r", GITNEXUS_REPO, symbol]) for symbol in core_symbols}
    impacts = {
        symbol: run_gitnexus_text(["impact", "-r", GITNEXUS_REPO, "--depth", "2", symbol])
        for symbol in ("actionLogin", "actionSubmit", "loadHistory", "loadInitialData")
    }
    return {"gitnexus": {"contexts": contexts, "impacts": impacts}}


def summarize(state: TraceState) -> TraceState:
    function_edges = state["function_edges"]
    endpoint_contracts = state["endpoint_contracts"]
    io_db_contracts = state["io_db_contracts"]
    touched_tables = sorted({t["table"] for contract in io_db_contracts for t in contract["tables"]})
    risk_endpoints = [
        contract for contract in io_db_contracts
        if not contract["tables"] or not contract["input_keys"] or not contract["response_keys"]
    ]
    summary = {
        "generated_at": now_iso(),
        "function_node_count": len(state["php_blocks"]) + len(state["js_blocks"]),
        "function_edge_count": len(function_edges),
        "endpoint_contract_count": len(endpoint_contracts),
        "io_db_contract_count": len(io_db_contracts),
        "db_table_touch_count": len(touched_tables),
        "db_tables_touched": touched_tables,
        "risk_endpoint_count": len(risk_endpoints),
        "risk_endpoints": [item["endpoint_key"] for item in risk_endpoints[:20]],
        "trace_entrypoints": [
            {
                "symptom": "Login / takeover / session",
                "start_with": ["active/api/auth.php", "generated/IO_DB_CONNECTION_MAP.md", "generated/FUNCTION_DEPENDENCY_MAP.md"],
            },
            {
                "symptom": "History / table / photo / loss / berat",
                "start_with": ["active/api/history.php", "generated/IO_DB_CONNECTION_MAP.md", "generated/IO_DEPENDENCY_MAP.md"],
            },
            {
                "symptom": "Submit / revise / finalize",
                "start_with": ["active/api/transactions.php", "generated/FUNCTION_DB_MAP.md", "generated/IO_DB_CONNECTION_MAP.md"],
            },
        ],
    }
    return {"summary": summary}


def render_function_dependency_markdown(state: TraceState) -> str:
    lines = [
        "# Function Dependency Map",
        "",
        f"Generated at: `{state['summary']['generated_at']}`",
        "",
        "## Coverage",
        "",
        f"- Function nodes: `{state['summary']['function_node_count']}`",
        f"- Call edges: `{state['summary']['function_edge_count']}`",
        "",
        "## Core GitNexus Context",
        "",
    ]
    for symbol, payload in state["gitnexus"]["contexts"].items():
        ctx = payload.get("symbol", {})
        if payload.get("status") == "found":
            lines.append(f"- `{symbol}` -> `{ctx.get('filePath', '?')}` lines `{ctx.get('startLine', '?')}-{ctx.get('endLine', '?')}`")
        else:
            lines.append(f"- `{symbol}` unresolved")
    lines.extend(["", "## Sample Edges", ""])
    for edge in state["function_edges"][:80]:
        lines.append(f"- `{edge['source']}` -> `{edge['target']}` ({edge['kind']})")
    lines.append("")
    return "\n".join(lines)


def render_io_dependency_markdown(state: TraceState) -> str:
    lines = [
        "# Input Output Dependency Map",
        "",
        f"Generated at: `{state['summary']['generated_at']}`",
        "",
        "## Coverage",
        "",
        f"- Endpoint contracts: `{state['summary']['endpoint_contract_count']}`",
        "",
        "## Contracts",
        "",
    ]
    for item in state["endpoint_contracts"]:
        lines.append(f"### {item['endpoint_key']}")
        lines.append(f"- Frontend consumers: {', '.join(f'`{x}`' for x in item['frontend_consumers']) or '`-`'}")
        lines.append(f"- Frontend input keys: {', '.join(f'`{x}`' for x in item['frontend_input_keys']) or '`-`'}")
        lines.append(f"- Backend file: `{item['backend_file']}`")
        lines.append(f"- Backend block: `{item['backend_block'] or '-'}`")
        lines.append(f"- Backend input keys: {', '.join(f'`{x}`' for x in item['backend_input_keys']) or '`-`'}")
        lines.append(f"- Backend response keys: {', '.join(f'`{x}`' for x in item['backend_response_keys']) or '`-`'}")
        lines.append("")
    return "\n".join(lines)


def render_io_db_markdown(state: TraceState) -> str:
    lines = [
        "# IO to DB Connection Map",
        "",
        f"Generated at: `{state['summary']['generated_at']}`",
        "",
        "## Coverage",
        "",
        f"- IO->DB contracts: `{state['summary']['io_db_contract_count']}`",
        f"- DB tables touched: `{state['summary']['db_table_touch_count']}`",
        "",
    ]
    for item in state["io_db_contracts"]:
        lines.append(f"### {item['endpoint_key']}")
        lines.append(f"- Backend: `{item['backend_file']}::{item['backend_block']}`")
        lines.append(f"- Input keys: {', '.join(f'`{x}`' for x in item['input_keys']) or '`-`'}")
        lines.append(f"- Response keys: {', '.join(f'`{x}`' for x in item['response_keys']) or '`-`'}")
        if item["tables"]:
            for table in item["tables"]:
                via = f" via {', '.join(f'`{v}`' for v in table['via'])}" if table["via"] else ""
                lines.append(f"- Table `{table['table']}` ops {', '.join(f'`{op}`' for op in table['operations'])}{via}")
        else:
            lines.append("- Table link: `-`")
        lines.append("")
    return "\n".join(lines)


def build_function_dot(state: TraceState) -> str:
    nodes = set()
    lines = ["digraph FunctionDependency {", '  rankdir="LR";', '  node [shape=box, style="rounded,filled", fillcolor="#eef5ff"];']
    for edge in state["function_edges"][:220]:
        nodes.add(edge["source"])
        nodes.add(edge["target"])
        color = {"php_call": "#1d4ed8", "js_call": "#059669", "api_call": "#dc2626"}.get(edge["kind"], "#6b7280")
        lines.append(f'  "{safe_label(edge["source"])}" -> "{safe_label(edge["target"])}" [color="{color}"];')
    lines.append("}")
    return "\n".join(lines)


def build_io_dot(state: TraceState) -> str:
    lines = ["digraph IODependency {", '  rankdir="LR";', '  node [shape=box, style="rounded,filled", fillcolor="#f8fafc"];']
    for item in state["endpoint_contracts"][:80]:
        endpoint_node = f"{item['endpoint_base']}::{item['endpoint_action']}::{item['method']}"
        backend_node = f"{item['backend_file']}::{item['backend_block'] or '-'}"
        lines.append(f'  "{safe_label(endpoint_node)}" [fillcolor="#fee2e2"];')
        lines.append(f'  "{safe_label(backend_node)}" [fillcolor="#dbeafe"];')
        lines.append(f'  "{safe_label(endpoint_node)}" -> "{safe_label(backend_node)}" [color="#dc2626"];')
        for consumer in item["frontend_consumers"][:5]:
            lines.append(f'  "{safe_label(consumer)}" [fillcolor="#dcfce7"];')
            lines.append(f'  "{safe_label(consumer)}" -> "{safe_label(endpoint_node)}" [color="#16a34a"];')
    lines.append("}")
    return "\n".join(lines)


def build_io_db_dot(state: TraceState) -> str:
    lines = ["digraph IODBConnection {", '  rankdir="LR";', '  node [shape=box, style="rounded,filled", fillcolor="#fff7ed"];']
    for item in state["io_db_contracts"][:80]:
        endpoint_node = item["endpoint_key"]
        backend_node = f"{item['backend_file']}::{item['backend_block']}"
        lines.append(f'  "{safe_label(endpoint_node)}" [fillcolor="#fee2e2"];')
        lines.append(f'  "{safe_label(backend_node)}" [fillcolor="#dbeafe"];')
        lines.append(f'  "{safe_label(endpoint_node)}" -> "{safe_label(backend_node)}" [color="#dc2626"];')
        for table in item["tables"]:
            table_node = f"table::{table['table']}"
            label = f"{table['table']}\\n{','.join(table['operations'])}"
            lines.append(f'  "{safe_label(table_node)}" [label="{safe_label(label)}", fillcolor="#fef3c7"];')
            lines.append(f'  "{safe_label(backend_node)}" -> "{safe_label(table_node)}" [color="#2563eb"];')
    lines.append("}")
    return "\n".join(lines)


def write_svg(dot_path: Path, svg_path: Path) -> None:
    subprocess.run([DOT_EXE, "-Tsvg", str(dot_path), "-o", str(svg_path)], check=True, cwd=str(PROJECT_ROOT))


def write_outputs(state: TraceState) -> TraceState:
    GENERATED_DIR.mkdir(parents=True, exist_ok=True)

    function_json = GENERATED_DIR / "FUNCTION_DEPENDENCY_MAP.json"
    function_md = GENERATED_DIR / "FUNCTION_DEPENDENCY_MAP.md"
    function_dot = GENERATED_DIR / "FUNCTION_DEPENDENCY_GRAPH.dot"
    function_svg = GENERATED_DIR / "FUNCTION_DEPENDENCY_GRAPH.svg"

    io_json = GENERATED_DIR / "IO_DEPENDENCY_MAP.json"
    io_md = GENERATED_DIR / "IO_DEPENDENCY_MAP.md"
    io_dot = GENERATED_DIR / "IO_DEPENDENCY_GRAPH.dot"
    io_svg = GENERATED_DIR / "IO_DEPENDENCY_GRAPH.svg"

    iodb_json = GENERATED_DIR / "IO_DB_CONNECTION_MAP.json"
    iodb_md = GENERATED_DIR / "IO_DB_CONNECTION_MAP.md"
    iodb_dot = GENERATED_DIR / "IO_DB_CONNECTION_GRAPH.dot"
    iodb_svg = GENERATED_DIR / "IO_DB_CONNECTION_GRAPH.svg"

    trace_center = DOC_DIR / "TRACE_CENTER.md"

    function_json.write_text(json.dumps({
        "summary": state["summary"],
        "php_blocks": state["php_blocks"],
        "js_blocks": state["js_blocks"],
        "edges": state["function_edges"],
        "gitnexus": state["gitnexus"],
    }, indent=2, ensure_ascii=False), encoding="utf-8")
    function_md.write_text(render_function_dependency_markdown(state), encoding="utf-8")
    function_dot.write_text(build_function_dot(state), encoding="utf-8")
    write_svg(function_dot, function_svg)

    io_json.write_text(json.dumps({
        "summary": state["summary"],
        "contracts": state["endpoint_contracts"],
    }, indent=2, ensure_ascii=False), encoding="utf-8")
    io_md.write_text(render_io_dependency_markdown(state), encoding="utf-8")
    io_dot.write_text(build_io_dot(state), encoding="utf-8")
    write_svg(io_dot, io_svg)

    iodb_json.write_text(json.dumps({
        "summary": state["summary"],
        "contracts": state["io_db_contracts"],
    }, indent=2, ensure_ascii=False), encoding="utf-8")
    iodb_md.write_text(render_io_db_markdown(state), encoding="utf-8")
    iodb_dot.write_text(build_io_db_dot(state), encoding="utf-8")
    write_svg(iodb_dot, iodb_svg)

    trace_center.write_text(render_trace_center(state), encoding="utf-8")

    return {
        "outputs": {
            "function_json": str(function_json),
            "function_md": str(function_md),
            "function_svg": str(function_svg),
            "io_json": str(io_json),
            "io_md": str(io_md),
            "io_svg": str(io_svg),
            "iodb_json": str(iodb_json),
            "iodb_md": str(iodb_md),
            "iodb_svg": str(iodb_svg),
            "trace_center": str(trace_center),
        }
    }


def render_trace_center(state: TraceState) -> str:
    week_ago = (datetime.now() - timedelta(days=7)).strftime("%Y-%m-%d %H:%M")
    lines = [
        "# Trace Center",
        "",
        "Dokumen ini adalah titik masuk utama untuk error tracing PROD3. Pakai ini dulu sebelum baca file satu-satu.",
        "",
        f"Generated at: `{state['summary']['generated_at']}`",
        f"Refreshed after: `{week_ago}` local scan baseline",
        "",
        "## Canonical Artifacts",
        "",
        "- `documentation/generated/FUNCTION_DEPENDENCY_MAP.md`: peta function -> function -> endpoint.",
        "- `documentation/generated/IO_DEPENDENCY_MAP.md`: peta frontend consumer -> endpoint -> backend input/output contract.",
        "- `documentation/generated/FUNCTION_DB_MAP.md`: peta function/backend block -> tabel DB.",
        "- `documentation/generated/IO_DB_CONNECTION_MAP.md`: peta endpoint/input/output -> backend block -> tabel DB.",
        "- `documentation/generated/ENDPOINT_UI_MAP.md`: audit kontrak endpoint vs consumer UI.",
        "- `documentation/generated/DYNAMIC_AUDIT_RUN.md`: hasil audit runtime localhost.",
        "",
        "## How To Trace Errors",
        "",
        "1. Tentukan gejalanya dulu: login, history, submit, admin, photo, atau setup.",
        "2. Buka `IO_DEPENDENCY_MAP.md` untuk lihat endpoint yang dipanggil UI dan input/output yang diharapkan.",
        "3. Buka `IO_DB_CONNECTION_MAP.md` untuk lihat endpoint itu menyentuh tabel mana.",
        "4. Jika perlu detail lebih dalam, buka `FUNCTION_DEPENDENCY_MAP.md` dan `FUNCTION_DB_MAP.md` untuk lihat helper/function apa yang dipanggil di bawahnya.",
        "5. Gunakan hasil `gitnexus context` pada symbol inti untuk validasi callers/callees sebelum menyimpulkan akar masalah.",
        "",
        "## Symptom Entry Points",
        "",
    ]
    for item in state["summary"]["trace_entrypoints"]:
        lines.append(f"### {item['symptom']}")
        for path in item["start_with"]:
            lines.append(f"- `{path}`")
        lines.append("")

    lines.extend([
        "## Risk Endpoints",
        "",
    ])
    if state["summary"]["risk_endpoints"]:
        for endpoint in state["summary"]["risk_endpoints"]:
            lines.append(f"- `{endpoint}`")
    else:
        lines.append("- None")
    lines.extend([
        "",
        "## GitNexus Impact Snapshots",
        "",
    ])
    for symbol, text in state["gitnexus"]["impacts"].items():
        brief = text.splitlines()[:12]
        lines.append(f"### {symbol}")
        lines.append("```text")
        lines.extend(brief if brief else ["(no output)"])
        lines.append("```")
        lines.append("")
    return "\n".join(lines)


def build_graph():
    graph = StateGraph(TraceState)
    graph.add_node("load_existing", load_existing)
    graph.add_node("collect_php_blocks", collect_php_blocks)
    graph.add_node("collect_js_blocks", collect_js_blocks)
    graph.add_node("build_function_edges", build_function_edges)
    graph.add_node("build_endpoint_contracts", build_endpoint_contracts)
    graph.add_node("build_io_db_contracts", build_io_db_contracts)
    graph.add_node("enrich_gitnexus", enrich_gitnexus)
    graph.add_node("summarize", summarize)
    graph.add_node("write_outputs", write_outputs)

    graph.add_edge(START, "load_existing")
    graph.add_edge("load_existing", "collect_php_blocks")
    graph.add_edge("collect_php_blocks", "collect_js_blocks")
    graph.add_edge("collect_js_blocks", "build_function_edges")
    graph.add_edge("build_function_edges", "build_endpoint_contracts")
    graph.add_edge("build_endpoint_contracts", "build_io_db_contracts")
    graph.add_edge("build_io_db_contracts", "enrich_gitnexus")
    graph.add_edge("enrich_gitnexus", "summarize")
    graph.add_edge("summarize", "write_outputs")
    graph.add_edge("write_outputs", END)
    return graph.compile()


def main() -> None:
    app = build_graph()
    result = app.invoke({"generated_at": now_iso()})
    print("Trace center generated:")
    for key, value in result["outputs"].items():
        print(f"- {key}: {value}")


if __name__ == "__main__":
    main()
