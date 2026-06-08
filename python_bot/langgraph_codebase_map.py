from __future__ import annotations

import argparse
import json
import re
from collections import Counter, defaultdict
from datetime import datetime, timezone
from pathlib import Path
from typing import Any, TypedDict

from langgraph.graph import END, START, StateGraph


TEXT_SUFFIXES = {
    ".php",
    ".js",
    ".py",
    ".sql",
    ".md",
    ".gs",
    ".html",
    ".css",
    ".ps1",
    ".json",
    ".toml",
    ".sh",
}

SKIP_DIRS = {
    ".git",
    ".gitnexus",
    ".claude",
    "uploads",
    "node_modules",
    "__pycache__",
    ".venv",
    "venv",
}

KEY_FILES = [
    "README.md",
    "CHANGELOG.md",
    "SETUP_PROTOCOL.md",
    "MIGRATION_REBUILD.md",
    "index.php",
    "documentation/GAS_VS_PHP_MAPPING.md",
    "documentation/ENDPOINT_CONTRACT.md",
]


class MapState(TypedDict, total=False):
    repo_root: str
    out_dir: str
    generated_at: str
    gitnexus: dict[str, Any]
    file_inventory: list[dict[str, Any]]
    buckets: dict[str, list[dict[str, Any]]]
    summary: dict[str, Any]
    outputs: dict[str, str]


def count_lines(path: Path) -> int:
    try:
        return sum(1 for _ in path.open("r", encoding="utf-8", errors="ignore"))
    except OSError:
        return 0


def read_text(path: Path, max_chars: int = 250000) -> str:
    try:
        return path.read_text(encoding="utf-8", errors="ignore")[:max_chars]
    except OSError:
        return ""


def detect_bucket(rel_path: str) -> str:
    norm = rel_path.replace("\\", "/")
    if norm.startswith("active/"):
        norm = norm[7:]
    if norm.startswith("api/"):
        return "backend_api"
    if norm.startswith("config/"):
        return "backend_config"
    if norm.startswith("assets/app/"):
        return "frontend_app"
    if norm.startswith("assets/"):
        return "frontend_assets"
    if norm.startswith("sql/"):
        return "database_sql"
    if norm.startswith("tools/"):
        return "operations_tools"
    if norm.startswith("seeds/"):
        return "migration_seed_tests"
    if norm.startswith("documentation/"):
        return "project_docs"
    if norm.startswith("docs/") or norm.startswith("documentation/"):
        return "documentation"
    if norm.endswith(".gs") or norm in {
        "AppWrapper.html",
        "Index.html",
        "Js.html",
        "Css.html",
        "Login.html",
        "Footer.html",
    }:
        return "legacy_gas_reference"
    if norm in {
        "README.md",
        "CHANGELOG.md",
        "SETUP_PROTOCOL.md",
        "MIGRATION_REBUILD.md",
        "CLAUDE.md",
    }:
        return "project_docs"
    if norm in {"index.php", ".htaccess", "deploy_to_xampp.ps1", "appscript.json"}:
        return "root_runtime"
    return "other"


def extract_signals(path: Path, text: str) -> dict[str, Any]:
    suffix = path.suffix.lower()
    signals: dict[str, Any] = {}
    if suffix == ".php":
        signals["functions"] = len(re.findall(r"\bfunction\s+[A-Za-z_][A-Za-z0-9_]*\s*\(", text))
        signals["classes"] = len(re.findall(r"\bclass\s+[A-Za-z_][A-Za-z0-9_]*\b", text))
        endpoints = set(re.findall(r"action=([A-Za-z0-9_]+)", text))
        endpoints.update(re.findall(r"\$action\s*===\s*'([A-Za-z0-9_]+)'", text))
        for block in re.findall(r"match\(\$action\)\s*\{(.*?)\n\};", text, re.S):
            endpoints.update(re.findall(r"^\s*'([A-Za-z0-9_]+)'\s*=>", block, re.M))
        signals["endpoints"] = sorted(endpoints)
    elif suffix == ".js":
        signals["functions"] = len(
            re.findall(r"\bfunction\s+[A-Za-z_][A-Za-z0-9_]*\s*\(", text)
        ) + len(re.findall(r"\bconst\s+[A-Za-z_][A-Za-z0-9_]*\s*=\s*(?:async\s*)?\(", text))
        signals["listeners"] = len(re.findall(r"addEventListener\s*\(", text))
    elif suffix == ".py":
        signals["functions"] = len(re.findall(r"^\s*def\s+[A-Za-z_][A-Za-z0-9_]*\s*\(", text, re.M))
        signals["classes"] = len(re.findall(r"^\s*class\s+[A-Za-z_][A-Za-z0-9_]*\b", text, re.M))
        signals["imports"] = len(re.findall(r"^\s*(?:from|import)\s+", text, re.M))
    elif suffix == ".sql":
        signals["create_table"] = len(re.findall(r"\bCREATE\s+TABLE\b", text, re.I))
        signals["insert_into"] = len(re.findall(r"\bINSERT\s+INTO\b", text, re.I))
    elif suffix == ".md":
        signals["headers"] = len(re.findall(r"^\s*#+\s+", text, re.M))
    return signals


def load_gitnexus(state: MapState) -> MapState:
    repo_root = Path(state["repo_root"])
    meta_path = repo_root / ".gitnexus" / "meta.json"
    data: dict[str, Any] = {}
    if meta_path.exists():
        data = json.loads(meta_path.read_text(encoding="utf-8"))
    return {"gitnexus": data}


def discover_files(state: MapState) -> MapState:
    repo_root = Path(state["repo_root"])
    inventory: list[dict[str, Any]] = []
    buckets: dict[str, list[dict[str, Any]]] = defaultdict(list)
    for path in repo_root.rglob("*"):
        if not path.is_file():
            continue
        if any(part in SKIP_DIRS for part in path.parts):
            continue
        if path.relative_to(repo_root).as_posix().startswith("documentation/generated/"):
            continue
        if path.suffix.lower() not in TEXT_SUFFIXES and path.name not in {".htaccess"}:
            continue
        rel = path.relative_to(repo_root).as_posix()
        text = read_text(path)
        item = {
            "path": rel,
            "bucket": detect_bucket(rel),
            "suffix": path.suffix.lower() or path.name,
            "lines": count_lines(path),
            "bytes": path.stat().st_size,
            "signals": extract_signals(path, text),
        }
        inventory.append(item)
        buckets[item["bucket"]].append(item)
    inventory.sort(key=lambda item: item["path"])
    ordered_buckets = {key: sorted(value, key=lambda item: item["path"]) for key, value in buckets.items()}
    return {"file_inventory": inventory, "buckets": ordered_buckets}


def summarize(state: MapState) -> MapState:
    repo_root = Path(state["repo_root"])
    inventory = state["file_inventory"]
    buckets = state["buckets"]
    gitnexus = state.get("gitnexus", {})

    by_suffix = Counter(item["suffix"] for item in inventory)
    by_bucket = {
        name: {
            "file_count": len(items),
            "line_count": sum(item["lines"] for item in items),
            "largest_files": [
                {"path": item["path"], "lines": item["lines"]}
                for item in sorted(items, key=lambda item: item["lines"], reverse=True)[:5]
            ],
        }
        for name, items in buckets.items()
    }

    key_file_snippets = {}
    for rel in KEY_FILES:
        path = repo_root / rel
        if path.exists():
            key_file_snippets[rel] = read_text(path, max_chars=1200)

    api_actions = {}
    for item in buckets.get("backend_api", []):
        api_actions[item["path"]] = sorted(set(item["signals"].get("endpoints", [])))

    summary = {
        "repo": {
            "root": state["repo_root"],
            "generated_at": datetime.now(timezone.utc).isoformat(),
        },
        "gitnexus": gitnexus,
        "counts": {
            "files": len(inventory),
            "lines": sum(item["lines"] for item in inventory),
            "by_suffix": dict(by_suffix),
            "by_bucket": by_bucket,
        },
        "runtime_entrypoints": [
            "index.php",
            "api/*.php",
            "assets/app/*.js",
            "config/database.php",
            "tools/setup.php",
        ],
        "api_actions": api_actions,
        "key_file_snippets": key_file_snippets,
        "top_files": [
            {"path": item["path"], "lines": item["lines"], "bucket": item["bucket"]}
            for item in sorted(inventory, key=lambda item: item["lines"], reverse=True)[:15]
        ],
        "observations": [
            "Repo menggabungkan aplikasi PHP runtime, dokumen migrasi, aset legacy GAS, SQL schema/migration, dan seed/testing scripts.",
            "Lapisan runtime utama berada di index.php, api/, config/, dan assets/app/.",
            "Lapisan referensi migrasi masih penting karena Kode.gs dan file HTML GAS dipakai sebagai sumber kebenaran historis.",
            "GitNexus sudah menyediakan graph + embeddings lokal; LangGraph di sini dipakai sebagai orchestrator mapping agar output bisa dijalankan ulang.",
        ],
    }
    return {"summary": summary}


def render_markdown(summary: dict[str, Any]) -> str:
    counts = summary["counts"]
    gitnexus = summary.get("gitnexus", {})
    stats = gitnexus.get("stats", {})
    capabilities = gitnexus.get("capabilities", {})

    lines: list[str] = []
    lines.append("# Codebase Map")
    lines.append("")
    lines.append(f"Generated at: `{summary['repo']['generated_at']}`")
    lines.append(f"Repo root: `{summary['repo']['root']}`")
    lines.append("")
    if stats:
        lines.append("## GitNexus Snapshot")
        lines.append("")
        lines.append(f"- Files: `{stats.get('files', 0)}`")
        lines.append(f"- Nodes: `{stats.get('nodes', 0)}`")
        lines.append(f"- Edges: `{stats.get('edges', 0)}`")
        lines.append(f"- Communities: `{stats.get('communities', 0)}`")
        lines.append(f"- Processes: `{stats.get('processes', 0)}`")
        lines.append(f"- Embeddings: `{stats.get('embeddings', 0)}`")
        vector = capabilities.get("vectorSearch", {})
        if vector:
            lines.append(f"- Vector mode: `{vector.get('status', 'unknown')}` via `{vector.get('provider', 'unknown')}`")
        lines.append("")

    lines.append("## Layer Summary")
    lines.append("")
    for bucket, meta in counts["by_bucket"].items():
        lines.append(f"### {bucket}")
        lines.append(f"- Files: `{meta['file_count']}`")
        lines.append(f"- Total lines: `{meta['line_count']}`")
        if meta["largest_files"]:
            largest = ", ".join(f"`{item['path']}` ({item['lines']} lines)" for item in meta["largest_files"][:3])
            lines.append(f"- Largest files: {largest}")
        lines.append("")

    lines.append("## API Surface")
    lines.append("")
    if summary["api_actions"]:
        for path, actions in summary["api_actions"].items():
            rendered = ", ".join(f"`{action}`" for action in actions) if actions else "`(route parsing not detected)`"
            lines.append(f"- `{path}`: {rendered}")
    else:
        lines.append("- No API actions detected.")
    lines.append("")

    lines.append("## Runtime Entrypoints")
    lines.append("")
    for item in summary["runtime_entrypoints"]:
        lines.append(f"- `{item}`")
    lines.append("")

    lines.append("## Top Files")
    lines.append("")
    for item in summary["top_files"]:
        lines.append(f"- `{item['path']}` - {item['lines']} lines ({item['bucket']})")
    lines.append("")

    lines.append("## Observations")
    lines.append("")
    for item in summary["observations"]:
        lines.append(f"- {item}")
    lines.append("")

    return "\n".join(lines)


def write_outputs(state: MapState) -> MapState:
    out_dir = Path(state["out_dir"])
    out_dir.mkdir(parents=True, exist_ok=True)

    json_path = out_dir / "CODEBASE_MAP.json"
    md_path = out_dir / "CODEBASE_MAP.md"

    json_path.write_text(json.dumps(state["summary"], indent=2, ensure_ascii=False), encoding="utf-8")
    md_path.write_text(render_markdown(state["summary"]), encoding="utf-8")

    return {
        "outputs": {
            "json": str(json_path),
            "markdown": str(md_path),
        }
    }


def build_graph():
    graph = StateGraph(MapState)
    graph.add_node("load_gitnexus", load_gitnexus)
    graph.add_node("discover_files", discover_files)
    graph.add_node("summarize", summarize)
    graph.add_node("write_outputs", write_outputs)

    graph.add_edge(START, "load_gitnexus")
    graph.add_edge("load_gitnexus", "discover_files")
    graph.add_edge("discover_files", "summarize")
    graph.add_edge("summarize", "write_outputs")
    graph.add_edge("write_outputs", END)
    return graph.compile()


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Generate a LangGraph-driven codebase map.")
    parser.add_argument("--repo", default=".", help="Repository root to map.")
    parser.add_argument("--out-dir", default="documentation/generated", help="Output directory for map artifacts.")
    return parser.parse_args()


def main() -> None:
    args = parse_args()
    repo_root = Path(args.repo).resolve()
    out_dir = (repo_root / args.out_dir).resolve()
    app = build_graph()
    result = app.invoke(
        {
            "repo_root": str(repo_root),
            "out_dir": str(out_dir),
            "generated_at": datetime.now(timezone.utc).isoformat(),
        }
    )
    print("LangGraph codebase map generated:")
    print(f"- JSON: {result['outputs']['json']}")
    print(f"- Markdown: {result['outputs']['markdown']}")


if __name__ == "__main__":
    main()
