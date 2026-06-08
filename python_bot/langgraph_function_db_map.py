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
ACTIVE_ROOT = PROJECT_ROOT / "active"
PYTHON_BOT_DIR = PROJECT_ROOT / "python_bot"
SQL_SCHEMA = ACTIVE_ROOT / "sql" / "setup_fresh.sql"
API_DIR = ACTIVE_ROOT / "api"
CONFIG_DIR = ACTIVE_ROOT / "config"
GENERATED_DIR = PROJECT_ROOT / "documentation" / "generated"
GIT_CMD_DIR = Path(r"C:\Program Files\Git\cmd")


class MapState(TypedDict, total=False):
    repo_root: str
    generated_at: str
    tables: list[str]
    functions: list[dict[str, Any]]
    links: list[dict[str, Any]]
    direct_links: list[dict[str, Any]]
    calls: list[dict[str, Any]]
    gitnexus_context: dict[str, Any]
    summary: dict[str, Any]
    outputs: dict[str, str]


@dataclass
class CodeBlock:
    file_path: str
    block_name: str
    block_kind: str
    start_idx: int
    end_idx: int
    start_line: int
    end_line: int
    body: str


TABLE_REGEX = re.compile(
    r"CREATE\s+TABLE(?:\s+IF\s+NOT\s+EXISTS)?\s+(?:public\.)?([a-zA-Z_][a-zA-Z0-9_]*)\s*\(",
    re.I,
)
FUNCTION_REGEX = re.compile(r"function\s+([A-Za-z_][A-Za-z0-9_]*)\s*\(", re.M)
ACTION_BLOCK_REGEX = re.compile(
    r"""if\s*\(\s*\$action\s*===\s*['"]([^'"]+)['"](?P<tail>.*?)\)\s*\{""",
    re.S,
)
METHOD_BLOCK_REGEX = re.compile(
    r"""if\s*\(\s*\$_SERVER\[['"]REQUEST_METHOD['"]\]\s*===\s*['"]([^'"]+)['"](?P<tail>.*?)\)\s*\{""",
    re.S,
)
SQL_PATTERNS = {
    "SELECT": re.compile(r"\bSELECT\b.*?\bFROM\s+([a-zA-Z_][a-zA-Z0-9_]*)", re.I | re.S),
    "INSERT": re.compile(r"\bINSERT\s+INTO\s+([a-zA-Z_][a-zA-Z0-9_]*)", re.I),
    "UPDATE": re.compile(r"\bUPDATE\s+([a-zA-Z_][a-zA-Z0-9_]*)", re.I),
    "DELETE": re.compile(r"\bDELETE\s+FROM\s+([a-zA-Z_][a-zA-Z0-9_]*)", re.I),
    "JOIN": re.compile(r"\bJOIN\s+([a-zA-Z_][a-zA-Z0-9_]*)", re.I),
}
PHP_CALL_REGEX = re.compile(r"\b([A-Za-z_][A-Za-z0-9_]*)\s*\(")
CALL_EXCLUDE = {
    "if", "elseif", "for", "foreach", "while", "switch", "echo", "exit", "isset", "empty",
    "array_map", "array_filter", "array_values", "array_change_key_case", "count", "trim",
    "json_encode", "json_decode", "preg_match", "sprintf", "mt_rand", "in_array", "max",
    "min", "implode", "explode", "strtoupper", "strtolower", "str_replace", "file_get_contents",
    "function_exists", "getallheaders", "password_verify", "password_hash", "filter_var",
    "rtrim", "intval", "floatval", "is_array", "is_numeric", "date", "time", "strtotime",
}


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


def run_gitnexus_context(symbol: str) -> dict[str, Any]:
    env = dict(**subprocess.os.environ)
    env["PATH"] = str(GIT_CMD_DIR) + ";" + env.get("PATH", "")
    proc = subprocess.run(
        f'gitnexus context {symbol}',
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


def extract_tables(_: MapState) -> MapState:
    text = SQL_SCHEMA.read_text(encoding="utf-8", errors="ignore")
    tables = sorted(set(TABLE_REGEX.findall(text)))
    return {"tables": tables}


def make_block(path: Path, text: str, name: str, kind: str, start_idx: int, end_idx: int) -> CodeBlock:
    start_line = text[:start_idx].count("\n") + 1
    end_line = text[:end_idx].count("\n") + 1
    body = text[start_idx:end_idx + 1]
    return CodeBlock(
        file_path=str(path.relative_to(PROJECT_ROOT)).replace("\\", "/"),
        block_name=name,
        block_kind=kind,
        start_idx=start_idx,
        end_idx=end_idx,
        start_line=start_line,
        end_line=end_line,
        body=body,
    )


def find_named_function_blocks(path: Path, text: str) -> tuple[list[CodeBlock], list[tuple[int, int]]]:
    blocks: list[CodeBlock] = []
    spans: list[tuple[int, int]] = []
    for match in FUNCTION_REGEX.finditer(text):
        name = match.group(1)
        start_idx = match.start()
        brace_start = text.find("{", match.end())
        if brace_start == -1:
            continue
        end_idx = find_matching_brace(text, brace_start)
        blocks.append(make_block(path, text, name, "function", start_idx, end_idx))
        spans.append((start_idx, end_idx))
    return blocks, spans


def find_route_blocks(path: Path, text: str, ignore_spans: list[tuple[int, int]]) -> list[CodeBlock]:
    blocks: list[CodeBlock] = []
    seen_ranges: set[tuple[int, int]] = set()
    patterns = (
        ("action", ACTION_BLOCK_REGEX, "action"),
        ("method", METHOD_BLOCK_REGEX, "method"),
    )
    for _, pattern, kind in patterns:
        for match in pattern.finditer(text):
            start_idx = match.start()
            if any(span_start <= start_idx <= span_end for span_start, span_end in ignore_spans):
                continue
            brace_start = text.find("{", match.end() - 1)
            if brace_start == -1:
                continue
            end_idx = find_matching_brace(text, brace_start)
            pair = (start_idx, end_idx)
            if pair in seen_ranges:
                continue
            seen_ranges.add(pair)
            label = match.group(1).strip()
            block_name = f"{kind}:{label}"
            blocks.append(make_block(path, text, block_name, kind, start_idx, end_idx))
    return blocks


def mask_spans(text: str, spans: list[tuple[int, int]]) -> str:
    chars = list(text)
    for start_idx, end_idx in spans:
        for idx in range(start_idx, end_idx + 1):
            if chars[idx] != "\n":
                chars[idx] = " "
    return "".join(chars)


def has_sql_reference(body: str, tables: set[str]) -> bool:
    for table in tables:
        if table in body:
            return True
    return any(pattern.search(body) for pattern in SQL_PATTERNS.values())


def find_file_scope_block(path: Path, text: str, ignore_spans: list[tuple[int, int]], tables: set[str]) -> list[CodeBlock]:
    masked = mask_spans(text, ignore_spans)
    if not has_sql_reference(masked, tables):
        return []
    return [
        CodeBlock(
            file_path=str(path.relative_to(PROJECT_ROOT)).replace("\\", "/"),
            block_name="__file_scope__",
            block_kind="file_scope",
            start_idx=0,
            end_idx=len(text) - 1,
            start_line=1,
            end_line=text.count("\n") + 1,
            body=masked,
        )
    ]


def collect_functions(state: MapState) -> MapState:
    targets = list(API_DIR.glob("*.php")) + list(CONFIG_DIR.glob("*.php")) + [
        PYTHON_BOT_DIR / "setup.php",
        PYTHON_BOT_DIR / "migrate_photos.php",
    ]
    tables = set(state["tables"])
    functions: list[dict[str, Any]] = []
    for path in targets:
        if not path.exists():
            continue
        text = path.read_text(encoding="utf-8", errors="ignore")
        named_blocks, named_spans = find_named_function_blocks(path, text)
        route_blocks = find_route_blocks(path, text, named_spans)
        route_spans = [(block.start_idx, block.end_idx) for block in route_blocks]
        file_scope_blocks = find_file_scope_block(path, text, named_spans + route_spans, tables)
        for block in [*named_blocks, *route_blocks, *file_scope_blocks]:
            functions.append(
                {
                    "file_path": block.file_path,
                    "function_name": block.block_name,
                    "block_kind": block.block_kind,
                    "start_line": block.start_line,
                    "end_line": block.end_line,
                    "body": block.body,
                }
            )
    functions.sort(key=lambda item: (item["file_path"], item["start_line"]))
    return {"functions": functions}


def map_links(state: MapState) -> MapState:
    tables = set(state["tables"])
    links: list[dict[str, Any]] = []
    for fn in state["functions"]:
        body = fn["body"]
        table_hits: dict[str, dict[str, Any]] = {}
        for op, pattern in SQL_PATTERNS.items():
            for table in pattern.findall(body):
                if table not in tables:
                    continue
                hit = table_hits.setdefault(table, {"operations": set(), "snippets": []})
                hit["operations"].add(op)
        for table in tables:
            if table in body:
                hit = table_hits.setdefault(table, {"operations": set(), "snippets": []})
                if not hit["operations"]:
                    hit["operations"].add("REFERENCE")
        for table, meta in sorted(table_hits.items()):
            links.append(
                {
                    "file_path": fn["file_path"],
                    "function_name": fn["function_name"],
                    "block_kind": fn.get("block_kind", "function"),
                    "start_line": fn["start_line"],
                    "end_line": fn["end_line"],
                    "table": table,
                    "operations": sorted(meta["operations"]),
                    "confidence": "high" if any(op in meta["operations"] for op in ("SELECT", "INSERT", "UPDATE", "DELETE", "JOIN")) else "medium",
                    "link_type": "direct",
                }
            )
    return {"links": links, "direct_links": list(links)}


def map_calls(state: MapState) -> MapState:
    functions = state["functions"]
    local_function_map: dict[str, dict[str, dict[str, Any]]] = defaultdict(dict)
    shared_function_map: dict[str, dict[str, Any]] = {}
    for fn in functions:
        if fn.get("block_kind") != "function":
            continue
        local_function_map[fn["file_path"]][fn["function_name"]] = fn
        if fn["file_path"].startswith("config/"):
            shared_function_map[fn["function_name"]] = fn

    calls: list[dict[str, Any]] = []
    seen: set[tuple[str, str, str, str]] = set()
    for fn in functions:
        body = fn["body"]
        source_key = f"{fn['file_path']}::{fn['function_name']}"
        local_targets = local_function_map.get(fn["file_path"], {})
        for match in PHP_CALL_REGEX.finditer(body):
            callee_name = match.group(1)
            if callee_name in CALL_EXCLUDE:
                continue
            callee = local_targets.get(callee_name) or shared_function_map.get(callee_name)
            if not callee:
                continue
            target_key = f"{callee['file_path']}::{callee['function_name']}"
            edge = (source_key, target_key, fn["file_path"], callee_name)
            if edge in seen or source_key == target_key:
                continue
            seen.add(edge)
            calls.append(
                {
                    "source_file_path": fn["file_path"],
                    "source_function_name": fn["function_name"],
                    "source_block_kind": fn.get("block_kind", "function"),
                    "target_file_path": callee["file_path"],
                    "target_function_name": callee["function_name"],
                    "target_block_kind": callee.get("block_kind", "function"),
                    "call_name": callee_name,
                }
            )
    return {"calls": calls}


def propagate_links(state: MapState) -> MapState:
    direct_links = state["direct_links"]
    calls = state["calls"]

    direct_by_block: dict[str, dict[str, dict[str, Any]]] = defaultdict(dict)
    for link in direct_links:
        block_key = f"{link['file_path']}::{link['function_name']}"
        direct_by_block[block_key][link["table"]] = link

    call_targets: dict[str, set[str]] = defaultdict(set)
    for call in calls:
        source_key = f"{call['source_file_path']}::{call['source_function_name']}"
        target_key = f"{call['target_file_path']}::{call['target_function_name']}"
        call_targets[source_key].add(target_key)

    effective_tables: dict[str, set[str]] = {
        block_key: set(table_map.keys()) for block_key, table_map in direct_by_block.items()
    }
    for call in calls:
        source_key = f"{call['source_file_path']}::{call['source_function_name']}"
        effective_tables.setdefault(source_key, set())
        target_key = f"{call['target_file_path']}::{call['target_function_name']}"
        effective_tables.setdefault(target_key, set())

    changed = True
    while changed:
        changed = False
        for source_key, targets in call_targets.items():
            current = effective_tables.setdefault(source_key, set())
            inherited = set()
            for target_key in targets:
                inherited.update(effective_tables.get(target_key, set()))
            new_tables = inherited - current
            if new_tables:
                current.update(new_tables)
                changed = True

    links: list[dict[str, Any]] = list(direct_links)
    direct_index = {(link["file_path"], link["function_name"], link["table"]) for link in direct_links}
    function_index = {
        f"{fn['file_path']}::{fn['function_name']}": fn
        for fn in state["functions"]
    }
    for block_key, tables in effective_tables.items():
        file_path, function_name = block_key.split("::", 1)
        fn = function_index[block_key]
        inherited_tables = tables - set(direct_by_block.get(block_key, {}).keys())
        for table in sorted(inherited_tables):
            idx = (file_path, function_name, table)
            if idx in direct_index:
                continue
            via = sorted(
                {
                    f"{call['target_file_path']}::{call['target_function_name']}"
                    for call in calls
                    if call["source_file_path"] == file_path
                    and call["source_function_name"] == function_name
                    and table in effective_tables.get(f"{call['target_file_path']}::{call['target_function_name']}", set())
                }
            )
            links.append(
                {
                    "file_path": file_path,
                    "function_name": function_name,
                    "block_kind": fn.get("block_kind", "function"),
                    "start_line": fn["start_line"],
                    "end_line": fn["end_line"],
                    "table": table,
                    "operations": ["CALL"],
                    "confidence": "inherited",
                    "link_type": "inherited",
                    "via": via,
                }
            )
    return {"links": links}


def enrich_gitnexus(_: MapState) -> MapState:
    symbols = ["actionSubmit", "actionRevise", "actionFinalize", "actionLogin", "loadAdminScorecard", "renderAdminCharts"]
    enriched = {symbol: run_gitnexus_context(symbol) for symbol in symbols}
    return {"gitnexus_context": enriched}


def summarize(state: MapState) -> MapState:
    links = state["links"]
    direct_links = state["direct_links"]
    functions = state["functions"]
    tables = state["tables"]
    calls = state["calls"]

    by_function: dict[str, list[dict[str, Any]]] = defaultdict(list)
    by_table: dict[str, list[dict[str, Any]]] = defaultdict(list)
    for link in links:
        key = f"{link['file_path']}::{link['function_name']}"
        by_function[key].append(link)
        by_table[link["table"]].append(link)

    function_summary = []
    for fn in functions:
        key = f"{fn['file_path']}::{fn['function_name']}"
        refs = by_function.get(key, [])
        function_summary.append(
            {
                "file_path": fn["file_path"],
                "function_name": fn["function_name"],
                "block_kind": fn.get("block_kind", "function"),
                "start_line": fn["start_line"],
                "db_table_count": len(refs),
                "direct_db_table_count": len({ref["table"] for ref in refs if ref.get("link_type") == "direct"}),
                "inherited_db_table_count": len({ref["table"] for ref in refs if ref.get("link_type") == "inherited"}),
                "tables": sorted({ref["table"] for ref in refs}),
            }
        )

    orphan_tables = [table for table in tables if table not in by_table]
    db_heavy_functions = sorted(function_summary, key=lambda item: item["db_table_count"], reverse=True)[:20]
    critical_files = [
        "active/api/auth.php",
        "active/api/transactions.php",
        "active/api/history.php",
        "active/api/admin.php",
        "active/api/materials.php",
        "active/api/settings.php",
        "active/api/conversions.php",
        "active/api/init.php",
        "active/api/photos.php",
        "active/config/auth_helper.php",
        "python_bot/setup.php",
        "python_bot/migrate_photos.php",
    ]
    critical_view = {
        file_path: [
            {
                "function_name": item["function_name"],
                "block_kind": item["block_kind"],
                "tables": item["tables"],
                "db_table_count": item["db_table_count"],
                "direct_db_table_count": item["direct_db_table_count"],
                "inherited_db_table_count": item["inherited_db_table_count"],
            }
            for item in function_summary
            if item["file_path"] == file_path and item["db_table_count"] > 0
        ]
        for file_path in critical_files
    }

    summary = {
        "repo_root": str(PROJECT_ROOT),
        "generated_at": datetime.now(timezone.utc).isoformat(),
        "table_count": len(tables),
        "function_count": len(functions),
        "link_count": len(links),
        "direct_link_count": len(direct_links),
        "inherited_link_count": len([link for link in links if link.get("link_type") == "inherited"]),
        "db_block_count": len([item for item in function_summary if item["db_table_count"] > 0]),
        "zero_link_block_count": len([item for item in function_summary if item["db_table_count"] == 0]),
        "call_edge_count": len(calls),
        "orphan_tables": orphan_tables,
        "db_heavy_functions": db_heavy_functions,
        "critical_file_view": critical_view,
        "table_coverage": {
            table: sorted({f"{item['file_path']}::{item['function_name']}" for item in refs})
            for table, refs in sorted(by_table.items())
        },
        "confidence_notes": [
            "High confidence berarti ada pola SQL eksplisit seperti SELECT/INSERT/UPDATE/DELETE/JOIN yang mengarah ke tabel schema final.",
            "Medium confidence berarti fungsi mereferensikan nama tabel tanpa operasi SQL eksplisit, biasanya pada helper, flow migrasi, atau query yang dibangun dinamis.",
            "Inherited berarti blok tidak men-query tabel secara langsung, tetapi memanggil function lain yang memiliki link DB.",
            "Mapper ini fokus pada file PHP runtime dan tools setup/migration. Frontend JS tidak dihitung sebagai pemilik akses DB langsung.",
        ],
    }
    return {"summary": summary}


def render_markdown(summary: dict[str, Any], gitnexus_context: dict[str, Any]) -> str:
    lines: list[str] = []
    lines.append("# Function vs DB Map")
    lines.append("")
    lines.append(f"Generated at: `{summary['generated_at']}`")
    lines.append(f"Repo root: `{summary['repo_root']}`")
    lines.append("")
    lines.append("## Coverage")
    lines.append("")
    lines.append(f"- Tables in schema: `{summary['table_count']}`")
    lines.append(f"- PHP/tool blocks scanned: `{summary['function_count']}`")
    lines.append(f"- Blocks with DB links: `{summary['db_block_count']}`")
    lines.append(f"- Blocks without DB links: `{summary['zero_link_block_count']}`")
    lines.append(f"- Direct function-to-table links: `{summary['direct_link_count']}`")
    lines.append(f"- Inherited function-to-table links: `{summary['inherited_link_count']}`")
    lines.append(f"- Effective function-to-table links: `{summary['link_count']}`")
    lines.append(f"- Call edges analyzed: `{summary['call_edge_count']}`")
    lines.append("")
    lines.append("## Critical Backend Files")
    lines.append("")
    for file_path, items in summary["critical_file_view"].items():
        lines.append(f"### {file_path}")
        if not items:
            lines.append("- No DB-linked function detected.")
        else:
            for item in items:
                tables = ", ".join(f"`{table}`" for table in item["tables"])
                suffix = []
                if item.get("direct_db_table_count"):
                    suffix.append(f"direct `{item['direct_db_table_count']}`")
                if item.get("inherited_db_table_count"):
                    suffix.append(f"inherited `{item['inherited_db_table_count']}`")
                suffix_text = ", ".join(suffix) if suffix else "direct `0`"
                lines.append(f"- `{item['function_name']}` -> {tables} (`{item['db_table_count']}` tables; {suffix_text})")
        lines.append("")
    lines.append("## Orphan Tables")
    lines.append("")
    if summary["orphan_tables"]:
        for table in summary["orphan_tables"]:
            lines.append(f"- `{table}`")
    else:
        lines.append("- None")
    lines.append("")
    lines.append("## Heaviest Function/Table Links")
    lines.append("")
    for item in summary["db_heavy_functions"][:15]:
        tables = ", ".join(f"`{table}`" for table in item["tables"])
        lines.append(f"- `{item['file_path']}::{item['function_name']}` -> {tables}")
    lines.append("")
    lines.append("## GitNexus Context Checks")
    lines.append("")
    for symbol, payload in gitnexus_context.items():
        ctx = payload.get("context", {})
        if ctx.get("status") == "found":
            symbol_meta = ctx.get("symbol", {})
            lines.append(f"- `{symbol}` found in `{symbol_meta.get('filePath', '?')}` lines `{symbol_meta.get('startLine', '?')}-{symbol_meta.get('endLine', '?')}`")
        else:
            lines.append(f"- `{symbol}` unresolved or not found")
    lines.append("")
    lines.append("## Notes")
    lines.append("")
    for note in summary["confidence_notes"]:
        lines.append(f"- {note}")
    lines.append("")
    return "\n".join(lines)


def write_outputs(state: MapState) -> MapState:
    GENERATED_DIR.mkdir(parents=True, exist_ok=True)
    json_path = GENERATED_DIR / "FUNCTION_DB_MAP.json"
    md_path = GENERATED_DIR / "FUNCTION_DB_MAP.md"

    payload = {
        "summary": state["summary"],
        "links": state["links"],
        "gitnexus_context": state["gitnexus_context"],
    }
    json_path.write_text(json.dumps(payload, indent=2, ensure_ascii=False), encoding="utf-8")
    md_path.write_text(render_markdown(state["summary"], state["gitnexus_context"]), encoding="utf-8")
    return {"outputs": {"json": str(json_path), "markdown": str(md_path)}}


def build_graph():
    graph = StateGraph(MapState)
    graph.add_node("extract_tables", extract_tables)
    graph.add_node("collect_functions", collect_functions)
    graph.add_node("map_links", map_links)
    graph.add_node("map_calls", map_calls)
    graph.add_node("propagate_links", propagate_links)
    graph.add_node("enrich_gitnexus", enrich_gitnexus)
    graph.add_node("summarize", summarize)
    graph.add_node("write_outputs", write_outputs)
    graph.add_edge(START, "extract_tables")
    graph.add_edge("extract_tables", "collect_functions")
    graph.add_edge("collect_functions", "map_links")
    graph.add_edge("map_links", "map_calls")
    graph.add_edge("map_calls", "propagate_links")
    graph.add_edge("propagate_links", "enrich_gitnexus")
    graph.add_edge("enrich_gitnexus", "summarize")
    graph.add_edge("summarize", "write_outputs")
    graph.add_edge("write_outputs", END)
    return graph.compile()


def main() -> None:
    app = build_graph()
    result = app.invoke(
        {
            "repo_root": str(PROJECT_ROOT),
            "generated_at": datetime.now(timezone.utc).isoformat(),
        }
    )
    print("Function vs DB map generated:")
    print(f"- JSON: {result['outputs']['json']}")
    print(f"- Markdown: {result['outputs']['markdown']}")


if __name__ == "__main__":
    main()
