from __future__ import annotations

import json
import re
import subprocess
from collections import defaultdict
from dataclasses import dataclass
from datetime import datetime, timezone
from pathlib import Path
from typing import Any, TypedDict

from langgraph.graph import END, START, StateGraph


PROJECT_ROOT = Path(__file__).resolve().parents[1]
ACTIVE_ROOT = PROJECT_ROOT  # no "active" subdir; files live directly in project root
ASSETS_DIR = ACTIVE_ROOT / "assets" / "app"
API_DIR = ACTIVE_ROOT / "api"
INDEX_PATH = ACTIVE_ROOT / "index.php"
GENERATED_DIR = PROJECT_ROOT / "documentation" / "generated"
GIT_CMD_DIR = Path(r"C:\Program Files\Git\cmd")


class AuditState(TypedDict, total=False):
    generated_at: str
    consumers: list[dict[str, Any]]
    backend: dict[str, dict[str, Any]]
    gitnexus_context: dict[str, Any]
    summary: dict[str, Any]
    outputs: dict[str, str]


@dataclass
class JsFunction:
    file_path: str
    function_name: str
    start_line: int
    end_line: int
    body: str


FUNCTION_DECL_REGEX = re.compile(r"function\s+([A-Za-z_][A-Za-z0-9_]*)\s*\(", re.M)
ASYNC_FUNCTION_DECL_REGEX = re.compile(r"async\s+function\s+([A-Za-z_][A-Za-z0-9_]*)\s*\(", re.M)
CALL_API_REGEX = re.compile(
    r"""(?:callApi|App\.api)\(\s*([`'"])(.+?)\1(?P<tail>.*?)\)""",
    re.S,
)
DATA_PATH_REGEX = re.compile(r"res\.data((?:\?|\.)[A-Za-z_][A-Za-z0-9_]*)+")
OPTIONAL_CHAIN_DATA_REGEX = re.compile(r"res\.data\?\.[A-Za-z_][A-Za-z0-9_]*(?:\?\.[A-Za-z_][A-Za-z0-9_]*)*")
PHP_FUNCTION_REGEX = re.compile(r"function\s+([A-Za-z_][A-Za-z0-9_]*)\s*\(", re.M)
ACTION_BLOCK_REGEX = re.compile(
    r"""if\s*\(\s*\$action\s*===\s*['"]([^'"]+)['"](?P<tail>.*?)\)\s*\{""",
    re.S,
)
METHOD_BLOCK_REGEX = re.compile(
    r"""if\s*\(\s*\$_SERVER\[['"]REQUEST_METHOD['"]\]\s*===\s*['"]([^'"]+)['"](?P<tail>.*?)\)\s*\{""",
    re.S,
)


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


def line_number(text: str, index: int) -> int:
    return text[:index].count("\n") + 1


def parse_js_functions(path: Path) -> list[JsFunction]:
    text = path.read_text(encoding="utf-8", errors="ignore")
    funcs: list[JsFunction] = []
    seen: set[tuple[str, int]] = set()
    for pattern in (ASYNC_FUNCTION_DECL_REGEX, FUNCTION_DECL_REGEX):
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
            funcs.append(
                JsFunction(
                    file_path=str(path.relative_to(PROJECT_ROOT)).replace("\\", "/"),
                    function_name=name,
                    start_line=line_number(text, start_idx),
                    end_line=line_number(text, end_idx),
                    body=text[start_idx:end_idx + 1],
                )
            )
    funcs.sort(key=lambda item: item.start_line)
    return funcs


def normalize_endpoint(endpoint: str) -> tuple[str, str]:
    endpoint = endpoint.strip()
    action_match = re.search(r"action=([A-Za-z_][A-Za-z0-9_]*)", endpoint)
    if action_match:
        base = endpoint.split("?", 1)[0]
        return base, action_match.group(1)
    if "?" in endpoint:
        base, query = endpoint.split("?", 1)
        params = dict(part.split("=", 1) for part in query.split("&") if "=" in part)
        action = params.get("action", "__default__")
    else:
        base = endpoint
        action = "__default__"
    return base, action


def extract_response_keys(block: str) -> list[str]:
    keys = re.findall(r"['\"]([A-Za-z_][A-Za-z0-9_]*)['\"]\s*=>", block)
    return sorted(set(keys))


def run_gitnexus_context(symbol: str) -> dict[str, Any]:
    env = dict(**subprocess.os.environ)
    env["PATH"] = str(GIT_CMD_DIR) + ";" + env.get("PATH", "")
    proc = subprocess.run(
        f'gitnexus context -r ProdAdmin {symbol}',
        cwd=str(PROJECT_ROOT),
        shell=True,
        capture_output=True,
        text=True,
        timeout=60,
        env=env,
    )
    if proc.returncode != 0:
        return {"symbol": symbol, "error": proc.stderr.strip() or proc.stdout.strip()}
    try:
        return {"symbol": symbol, "context": json.loads(proc.stdout)}
    except json.JSONDecodeError:
        return {"symbol": symbol, "raw": proc.stdout.strip()}


def collect_consumers(_: AuditState) -> AuditState:
    consumers: list[dict[str, Any]] = []
    for path in sorted(ASSETS_DIR.glob("*.js")):
        for fn in parse_js_functions(path):
            for match in CALL_API_REGEX.finditer(fn.body):
                endpoint_expr = match.group(2)
                endpoint = endpoint_expr
                tail = match.group("tail") or ""
                method_match = re.search(r"""method\s*:\s*['"]([A-Z]+)['"]""", tail, re.I)
                method = (method_match.group(1) if method_match else "GET").upper()
                base_file, action = normalize_endpoint(endpoint)
                is_dynamic = "${" in endpoint
                snippet = fn.body[match.start(): min(len(fn.body), match.start() + 900)]
                data_paths = sorted(set(DATA_PATH_REGEX.findall(snippet) + OPTIONAL_CHAIN_DATA_REGEX.findall(snippet)))
                silent_fail = bool(
                    re.search(r"if\s*\(\s*!res\.success\s*\)\s*return\b", snippet)
                    or re.search(r"if\s*\(\s*!res\.success\b.*?return;", snippet, re.S)
                )
                silent_catch = bool(re.search(r"catch\s*(?:\([^)]+\))?\s*\{\s*(?:/\*.*?silent.*?\*/)?\s*\}", fn.body, re.S | re.I))
                renders_blank_state = bool(
                    "innerHTML" in snippet and (
                        "Tidak ada data" in snippet
                        or "text-danger" in snippet
                        or "spinner-border" in snippet
                    )
                )
                auth_recovery = "authFailed" in snippet or "localStorage.clear()" in snippet
                consumers.append(
                    {
                        "endpoint": endpoint,
                        "endpoint_base": base_file,
                        "endpoint_action": action,
                        "method": method,
                        "is_dynamic": is_dynamic,
                        "consumer_file": fn.file_path,
                        "consumer_function": fn.function_name,
                        "start_line": fn.start_line,
                        "data_paths": data_paths,
                        "silent_fail_pattern": silent_fail,
                        "silent_catch_pattern": silent_catch,
                        "renders_blank_state": renders_blank_state,
                        "auth_recovery": auth_recovery,
                        "snippet": snippet[:600],
                    }
                )
    return {"consumers": consumers}


def collect_backend(_: AuditState) -> AuditState:
    backend: dict[str, dict[str, Any]] = {}
    for path in sorted(API_DIR.glob("*.php")):
        text = path.read_text(encoding="utf-8", errors="ignore")
        file_key = str(path.relative_to(PROJECT_ROOT)).replace("\\", "/")
        file_info: dict[str, Any] = {"actions": {}}

        for pattern, block_kind in ((ACTION_BLOCK_REGEX, "action"), (METHOD_BLOCK_REGEX, "method")):
            for match in pattern.finditer(text):
                action_name = match.group(1)
                brace_start = text.find("{", match.end() - 1)
                if brace_start == -1:
                    continue
                end_idx = find_matching_brace(text, brace_start)
                block = text[match.start():end_idx + 1]
                ok_payloads = re.findall(r"ok\((.*?)\);", block, re.S)
                fail_payloads = re.findall(r"fail\((.*?)\);", block, re.S)
                file_info["actions"][action_name] = {
                    "block_kind": block_kind,
                    "start_line": line_number(text, match.start()),
                    "response_keys": sorted(set(k for payload in ok_payloads for k in extract_response_keys(payload))),
                    "ok_payload_examples": [payload.strip()[:220] for payload in ok_payloads[:3]],
                    "fail_examples": [payload.strip()[:220] for payload in fail_payloads[:3]],
                }

        for match in PHP_FUNCTION_REGEX.finditer(text):
            name = match.group(1)
            if not name.startswith("action") or len(name) <= 6:
                continue
            brace_start = text.find("{", match.end())
            if brace_start == -1:
                continue
            end_idx = find_matching_brace(text, brace_start)
            block = text[match.start():end_idx + 1]
            action_name = name[6:]
            action_name = action_name[0].lower() + action_name[1:]
            ok_payloads = re.findall(r"ok\((.*?)\);", block, re.S)
            fail_payloads = re.findall(r"fail\((.*?)\);", block, re.S)
            file_info["actions"].setdefault(
                action_name,
                {
                    "block_kind": "function_router",
                    "start_line": line_number(text, match.start()),
                    "response_keys": sorted(set(k for payload in ok_payloads for k in extract_response_keys(payload))),
                    "ok_payload_examples": [payload.strip()[:220] for payload in ok_payloads[:3]],
                    "fail_examples": [payload.strip()[:220] for payload in fail_payloads[:3]],
                },
            )

        if file_key.endswith("conversions.php"):
            file_info["actions"].setdefault("__default__", file_info["actions"].get("list", {}))
        if file_key.endswith("init.php"):
            file_info["actions"].setdefault("__default__", {"block_kind": "file_scope", "start_line": 1, "response_keys": ["suppliers", "conversion", "config"], "ok_payload_examples": [], "fail_examples": []})
        if file_key.endswith("photos.php"):
            if re.search(r"Serve foto \(GET", text):
                file_info["actions"].setdefault("__default__", {"block_kind": "method", "start_line": 1, "response_keys": [], "ok_payload_examples": [], "fail_examples": []})
            if re.search(r"Upload foto \(POST", text):
                file_info["actions"].setdefault("__upload__", {"block_kind": "method", "start_line": 1, "response_keys": ["fileIds"], "ok_payload_examples": ["['fileIds' => $fileIds]"], "fail_examples": []})

        if file_key.endswith("settings.php"):
            for method in ("GET", "POST"):
                if method not in file_info["actions"]:
                    method_match = re.search(rf"if\s*\(\s*\$_SERVER\['REQUEST_METHOD'\]\s*===\s*'{method}'\s*\)", text)
                    if method_match:
                        file_info["actions"][method] = {"block_kind": "method", "start_line": line_number(text, method_match.start()), "response_keys": [], "ok_payload_examples": [], "fail_examples": []}
        backend[file_key] = file_info
    return {"backend": backend}


def enrich_gitnexus(_: AuditState) -> AuditState:
    symbols = [
        "loadAppData",
        "loadHistory",
        "loadAdminHistory",
        "loadAdminScorecard",
        "callApi",
        "actionSubmit",
        "actionValidate",
    ]
    return {"gitnexus_context": {symbol: run_gitnexus_context(symbol) for symbol in symbols}}


def summarize(state: AuditState) -> AuditState:
    consumers = state["consumers"]
    backend = state["backend"]
    by_endpoint: dict[str, list[dict[str, Any]]] = defaultdict(list)
    for item in consumers:
        endpoint_key = f"{item['endpoint_base']}::{item['endpoint_action']}::{item['method']}"
        by_endpoint[endpoint_key].append(item)

    endpoint_map = []
    findings = []
    for endpoint_key, uses in sorted(by_endpoint.items()):
        base_file, action, method = endpoint_key.split("::", 2)
        normalized_base = base_file.lstrip("./")
        if normalized_base.startswith("active/"):
            backend_file = normalized_base[len("active/"):]  # strip legacy prefix
        elif normalized_base.startswith("api/"):
            backend_file = normalized_base
        else:
            backend_file = f"api/{normalized_base}"
        backend_actions = backend.get(backend_file, {}).get("actions", {})
        backend_action = backend_actions.get(action)
        if backend_action is None and action == "__default__":
            backend_action = backend_actions.get(method)
        if backend_action is None and base_file == "photos.php" and method == "POST":
            backend_action = backend_actions.get("__upload__")

        response_keys = backend_action.get("response_keys", []) if backend_action else []
        silent_consumers = [u for u in uses if u["silent_fail_pattern"] or u["silent_catch_pattern"]]
        blank_consumers = [u for u in uses if u["renders_blank_state"]]
        has_dynamic_consumer = any(u["is_dynamic"] for u in uses)
        missing_backend = backend_action is None and not has_dynamic_consumer

        endpoint_map.append(
            {
                "endpoint_key": endpoint_key,
                "endpoint_base": base_file,
                "endpoint_action": action,
                "method": method,
                "consumer_count": len(uses),
                "consumer_functions": [f"{u['consumer_file']}::{u['consumer_function']}" for u in uses],
                "has_dynamic_consumer": has_dynamic_consumer,
                "backend_file": backend_file,
                "backend_found": not missing_backend,
                "backend_response_keys": response_keys,
                "silent_consumer_count": len(silent_consumers),
                "blank_state_consumer_count": len(blank_consumers),
            }
        )

        if missing_backend:
            findings.append(
                {
                    "severity": "high",
                    "endpoint_key": endpoint_key,
                    "title": "Frontend memanggil endpoint/action yang tidak terpetakan di backend",
                    "evidence": [f"{u['consumer_file']}::{u['consumer_function']}" for u in uses],
                }
            )
        elif has_dynamic_consumer:
            findings.append(
                {
                    "severity": "medium",
                    "endpoint_key": endpoint_key,
                    "title": "Endpoint dipanggil via template/dynamic expression dan perlu review manual shape response",
                    "evidence": [f"{u['consumer_file']}::{u['consumer_function']}" for u in uses if u["is_dynamic"]],
                }
            )

        for use in uses:
            if use["silent_fail_pattern"]:
                findings.append(
                    {
                        "severity": "medium",
                        "endpoint_key": endpoint_key,
                        "title": "Consumer berhenti diam-diam saat res.success false",
                        "evidence": [f"{use['consumer_file']}::{use['consumer_function']} line {use['start_line']}"],
                    }
                )
            if use["silent_catch_pattern"]:
                findings.append(
                    {
                        "severity": "medium",
                        "endpoint_key": endpoint_key,
                        "title": "Consumer memiliki catch silent yang bisa menyembunyikan failed data call",
                        "evidence": [f"{use['consumer_file']}::{use['consumer_function']} line {use['start_line']}"],
                    }
                )

            if use["renders_blank_state"] and not use["auth_recovery"]:
                findings.append(
                    {
                        "severity": "medium",
                        "endpoint_key": endpoint_key,
                        "title": "Consumer menampilkan blank/empty state tanpa recovery auth eksplisit",
                        "evidence": [f"{use['consumer_file']}::{use['consumer_function']} line {use['start_line']}"],
                    }
                )

    findings.sort(key=lambda item: {"high": 0, "medium": 1, "low": 2}.get(item["severity"], 9))
    summary = {
        "generated_at": datetime.now(timezone.utc).isoformat(),
        "frontend_call_count": len(consumers),
        "endpoint_count": len(endpoint_map),
        "silent_fail_consumer_count": len([u for u in consumers if u["silent_fail_pattern"]]),
        "silent_catch_consumer_count": len([u for u in consumers if u["silent_catch_pattern"]]),
        "blank_state_consumer_count": len([u for u in consumers if u["renders_blank_state"]]),
        "endpoint_map": endpoint_map,
        "findings": findings,
        "priority_endpoints": [
            item for item in endpoint_map
            if item["endpoint_base"] in {"history.php", "init.php", "admin.php", "config.php", "settings.php"}
        ],
    }
    return {"summary": summary}


def render_markdown(summary: dict[str, Any], gitnexus_context: dict[str, Any]) -> str:
    lines: list[str] = []
    lines.append("# Endpoint to UI Contract Map")
    lines.append("")
    lines.append(f"Generated at: `{summary['generated_at']}`")
    lines.append("")
    lines.append("## Coverage")
    lines.append("")
    lines.append(f"- Frontend API calls discovered: `{summary['frontend_call_count']}`")
    lines.append(f"- Unique endpoint contracts: `{summary['endpoint_count']}`")
    lines.append(f"- Consumers with silent fail pattern: `{summary['silent_fail_consumer_count']}`")
    lines.append(f"- Consumers with silent catch pattern: `{summary['silent_catch_consumer_count']}`")
    lines.append(f"- Consumers with blank-state rendering: `{summary['blank_state_consumer_count']}`")
    lines.append("")
    lines.append("## Priority Endpoints")
    lines.append("")
    for item in summary["priority_endpoints"]:
        lines.append(
            f"- `{item['endpoint_base']}::{item['endpoint_action']}::{item['method']}` -> "
            f"`{item['consumer_count']}` consumer(s), backend found `{item['backend_found']}`, "
            f"silent `{item['silent_consumer_count']}`, blank `{item['blank_state_consumer_count']}`"
        )
    lines.append("")
    lines.append("## Findings")
    lines.append("")
    if not summary["findings"]:
        lines.append("- None")
    else:
        for finding in summary["findings"][:25]:
            ev = "; ".join(finding["evidence"])
            lines.append(f"- `{finding['severity'].upper()}` {finding['title']} -> `{finding['endpoint_key']}` | {ev}")
    lines.append("")
    lines.append("## GitNexus Context Checks")
    lines.append("")
    for symbol, payload in gitnexus_context.items():
        ctx = payload.get("context", {})
        if ctx.get("status") == "found":
            meta = ctx.get("symbol", {})
            lines.append(f"- `{symbol}` found in `{meta.get('filePath', '?')}` lines `{meta.get('startLine', '?')}-{meta.get('endLine', '?')}`")
        else:
            lines.append(f"- `{symbol}` unresolved")
    lines.append("")
    return "\n".join(lines)


def write_outputs(state: AuditState) -> AuditState:
    GENERATED_DIR.mkdir(parents=True, exist_ok=True)
    json_path = GENERATED_DIR / "ENDPOINT_UI_MAP.json"
    md_path = GENERATED_DIR / "ENDPOINT_UI_MAP.md"
    payload = {
        "summary": state["summary"],
        "consumers": state["consumers"],
        "backend": state["backend"],
        "gitnexus_context": state["gitnexus_context"],
    }
    json_path.write_text(json.dumps(payload, indent=2, ensure_ascii=False), encoding="utf-8")
    md_path.write_text(render_markdown(state["summary"], state["gitnexus_context"]), encoding="utf-8")
    return {"outputs": {"json": str(json_path), "markdown": str(md_path)}}


def build_graph():
    graph = StateGraph(AuditState)
    graph.add_node("collect_consumers", collect_consumers)
    graph.add_node("collect_backend", collect_backend)
    graph.add_node("enrich_gitnexus", enrich_gitnexus)
    graph.add_node("summarize", summarize)
    graph.add_node("write_outputs", write_outputs)
    graph.add_edge(START, "collect_consumers")
    graph.add_edge("collect_consumers", "collect_backend")
    graph.add_edge("collect_backend", "enrich_gitnexus")
    graph.add_edge("enrich_gitnexus", "summarize")
    graph.add_edge("summarize", "write_outputs")
    graph.add_edge("write_outputs", END)
    return graph.compile()


def main() -> None:
    app = build_graph()
    result = app.invoke({"generated_at": datetime.now(timezone.utc).isoformat()})
    print("Endpoint to UI contract map generated:")
    print(f"- JSON: {result['outputs']['json']}")
    print(f"- Markdown: {result['outputs']['markdown']}")


if __name__ == "__main__":
    main()
