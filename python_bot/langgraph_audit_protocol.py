from __future__ import annotations

import argparse
import json
import subprocess
from dataclasses import asdict, dataclass, field
from datetime import datetime, timezone
from pathlib import Path
from typing import Any, TypedDict

from langgraph.graph import END, START, StateGraph


ROOT = Path(__file__).resolve().parents[1]
GENERATED_DIR = ROOT / "documentation" / "generated"
PHP_BIN = Path(r"C:\xampp\php\php.exe")
GIT_CMD_DIR = Path(r"C:\Program Files\Git\cmd")


class AuditState(TypedDict, total=False):
    repo_root: str
    generated_at: str
    reports_dir: str
    phases: list[dict[str, Any]]
    outputs: dict[str, str]


@dataclass
class CheckResult:
    name: str
    status: str
    details: str
    evidence: dict[str, Any] = field(default_factory=dict)


@dataclass
class PhaseResult:
    phase_id: str
    title: str
    status: str
    objective: str
    checks: list[CheckResult] = field(default_factory=list)
    next_step: str = ""


def run_cmd(command: str, cwd: Path, timeout: int = 60) -> tuple[int, str, str]:
    env = dict(**subprocess.os.environ)
    env["PATH"] = str(GIT_CMD_DIR) + ";" + env.get("PATH", "")
    proc = subprocess.run(
        command,
        cwd=str(cwd),
        shell=True,
        capture_output=True,
        text=True,
        timeout=timeout,
        env=env,
        encoding="utf-8",
        errors="replace",
    )
    return proc.returncode, proc.stdout.strip(), proc.stderr.strip()


def phase_status(checks: list[CheckResult]) -> str:
    statuses = {check.status for check in checks}
    if "FAIL" in statuses:
        return "FAIL"
    if statuses == {"PENDING"}:
        return "PENDING"
    if "PENDING" in statuses:
        return "PENDING"
    return "PASS"


def phase_preflight(state: AuditState) -> AuditState:
    repo = Path(state["repo_root"])
    checks: list[CheckResult] = []

    rc, out, err = run_cmd("gitnexus status", repo)
    status_ok = rc == 0 and "up-to-date" in out
    checks.append(
        CheckResult(
            name="gitnexus_status",
            status="PASS" if status_ok else "FAIL",
            details="GitNexus index must exist and be up-to-date.",
            evidence={"stdout": out, "stderr": err},
        )
    )

    rc, out, err = run_cmd("gitnexus doctor", repo)
    doctor_ok = rc == 0 and "Graph store:     available" in out and "Full-text search: available" in out
    checks.append(
        CheckResult(
            name="gitnexus_doctor",
            status="PASS" if doctor_ok else "FAIL",
            details="GitNexus doctor must confirm graph and full-text search availability.",
            evidence={"stdout": out, "stderr": err},
        )
    )

    meta_path = repo / ".gitnexus" / "meta.json"
    map_path = repo / "documentation" / "generated" / "CODEBASE_MAP.md"
    checks.append(
        CheckResult(
            name="audit_artifacts_present",
            status="PASS" if meta_path.exists() and map_path.exists() else "FAIL",
            details="Meta index and codebase map must exist before the audit advances.",
            evidence={"meta_json": str(meta_path), "codebase_map": str(map_path)},
        )
    )

    phase = PhaseResult(
        phase_id="phase_0",
        title="Preflight & Index Integrity",
        objective="Validate audit tooling, graph index, and baseline architecture artifacts.",
        checks=checks,
        status=phase_status(checks),
        next_step="Advance to architecture audit only if all preflight checks pass.",
    )
    return {"phases": [asdict(phase)]}


def phase_architecture(state: AuditState) -> AuditState:
    repo = Path(state["repo_root"])
    checks: list[CheckResult] = []
    codebase_map = repo / "documentation" / "generated" / "CODEBASE_MAP.json"

    if codebase_map.exists():
        data = json.loads(codebase_map.read_text(encoding="utf-8"))
        buckets = data.get("counts", {}).get("by_bucket", {})
        required = [
            "root_runtime",
            "backend_api",
            "frontend_app",
            "backend_config",
            "database_sql",
            "operations_tools",
            # legacy_gas_reference intentionally removed — ProdAdmin does not use Google Apps Script
        ]
        missing = [name for name in required if name not in buckets]
        checks.append(
            CheckResult(
                name="required_architecture_layers",
                status="PASS" if not missing else "FAIL",
                details="All architecture layers required by the runtime model must be discoverable.",
                evidence={"missing_layers": missing, "present_layers": sorted(buckets)},
            )
        )
    else:
        checks.append(
            CheckResult(
                name="required_architecture_layers",
                status="FAIL",
                details="Codebase map JSON is missing, so architecture layering could not be verified.",
            )
        )

    for symbol in ("Function:api/transactions.php:actionSubmit", "Function:assets/app/auth.js:doLogin"):
        rc, out, err = run_cmd(f'gitnexus context -r ProdAdmin "{symbol}"', repo, timeout=90)
        ok = rc == 0 and '"status": "found"' in out
        checks.append(
            CheckResult(
                name=f"context_{symbol.split(':')[-1]}",
                status="PASS" if ok else "FAIL",
                details=f"Critical workflow symbol `{symbol}` must be traceable in the graph.",
                evidence={"stdout": out, "stderr": err},
            )
        )

    phase = PhaseResult(
        phase_id="phase_1",
        title="Architecture Conformance",
        objective="Confirm that runtime boundaries, major layers, and critical symbols align with the documented architecture.",
        checks=checks,
        status=phase_status(checks),
        next_step="Advance to dependency audit only if runtime layers and critical symbols are visible.",
    )
    return {"phases": state.get("phases", []) + [asdict(phase)]}


def phase_dependencies(state: AuditState) -> AuditState:
    repo = Path(state["repo_root"])
    checks: list[CheckResult] = []

    for name, command in (
        ("python_version", "python --version"),
        ("node_version", "node --version"),
        ("npm_version", "npm --version"),
        ("gitnexus_version", "gitnexus --version"),
    ):
        rc, out, err = run_cmd(command, repo)
        checks.append(
            CheckResult(
                name=name,
                status="PASS" if rc == 0 else "FAIL",
                details=f"{name} must be available for the audit/runtime workflow.",
                evidence={"stdout": out, "stderr": err},
            )
        )

    rc, out, err = run_cmd(
        "python -c \"import importlib.util; import langgraph; assert importlib.util.find_spec('langgraph.checkpoint'); assert importlib.util.find_spec('langgraph.prebuilt'); print('LANGGRAPH_STACK_OK')\"",
        repo,
    )
    checks.append(
        CheckResult(
            name="langgraph_stack",
            status="PASS" if rc == 0 and "LANGGRAPH_STACK_OK" in out else "FAIL",
            details="LangGraph core and required checkpoint/prebuilt packages must import cleanly.",
            evidence={"stdout": out, "stderr": err},
        )
    )

    php_files = sorted(
        str(path.relative_to(repo))
        for path in repo.rglob("*.php")
        if ".gitnexus" not in path.parts and ".git" not in path.parts
    )
    if PHP_BIN.exists():
        lint_failures: list[dict[str, Any]] = []
        for rel in php_files:
            rc, out, err = run_cmd(f'"{PHP_BIN}" -l "{rel}"', repo, timeout=30)
            if rc != 0:
                lint_failures.append({"file": rel, "stdout": out, "stderr": err})
        checks.append(
            CheckResult(
                name="php_lint",
                status="PASS" if not lint_failures else "FAIL",
                details="All PHP files must pass syntax linting.",
                evidence={"files_checked": len(php_files), "failures": lint_failures[:20]},
            )
        )
    else:
        checks.append(
            CheckResult(
                name="php_lint",
                status="PENDING",
                details="XAMPP PHP binary was not found; PHP lint could not be executed.",
                evidence={"php_bin": str(PHP_BIN)},
            )
        )

    phase = PhaseResult(
        phase_id="phase_2",
        title="Dependency & Runtime Audit",
        objective="Validate interpreter/tool availability and syntax health for critical runtime files.",
        checks=checks,
        status=phase_status(checks),
        next_step="Advance to contract audit only if runtime dependencies are present and syntax checks are clean.",
    )
    return {"phases": state.get("phases", []) + [asdict(phase)]}


def phase_contracts(state: AuditState) -> AuditState:
    repo = Path(state["repo_root"])
    checks: list[CheckResult] = []

    contract_path = repo / "documentation" / "generated" / "ENDPOINT_UI_MAP.md"
    function_map_path = repo / "documentation" / "generated" / "FUNCTION_DB_MAP.md"
    checks.append(
        CheckResult(
            name="contract_docs_present",
            status="PASS" if contract_path.exists() and function_map_path.exists() else "FAIL",
            details="Function map and endpoint contract must exist before I/O audit.",
            evidence={"endpoint_contract": str(contract_path), "function_map": str(function_map_path)},
        )
    )

    codebase_map = repo / "documentation" / "generated" / "CODEBASE_MAP.json"
    expected_actions = {
        "api/auth.php": {"login", "logout", "validate", "changePassword", "checkTakeover", "takeoverDecision", "takeoverStatus", "forceLogin"},
        "api/transactions.php": {"submit", "revise", "finalize", "delete", "diff", "previousStock"},
        "api/migration_api.php": {"upload", "progress", "reset_progress", "run_import", "run_setup_photos", "photo_stats", "photo_retry", "photo_batch"},
    }
    if codebase_map.exists():
        data = json.loads(codebase_map.read_text(encoding="utf-8"))
        api_actions = data.get("api_actions", {})
        missing: dict[str, list[str]] = {}
        for path, expected in expected_actions.items():
            present = set(api_actions.get(path, []))
            absent = sorted(expected - present)
            if absent:
                missing[path] = absent
        checks.append(
            CheckResult(
                name="core_action_coverage",
                status="PASS" if not missing else "FAIL",
                details="Core auth and transaction actions must be discoverable from the current codebase map.",
                evidence={"missing_actions": missing, "detected_actions": {k: api_actions.get(k, []) for k in expected_actions}},
            )
        )
    else:
        checks.append(
            CheckResult(
                name="core_action_coverage",
                status="FAIL",
                details="Codebase map JSON missing; could not compare router actions.",
            )
        )

    for symbol in ("Function:api/transactions.php:actionSubmit", "Function:api/transactions.php:actionRevise", "Function:api/transactions.php:actionFinalize", "Function:assets/app/auth.js:doLogin"):
        rc, out, err = run_cmd(f'gitnexus context -r ProdAdmin "{symbol}"', repo, timeout=90)
        ok = rc == 0 and '"status": "found"' in out
        checks.append(
            CheckResult(
                name=f"function_trace_{symbol.split(':')[-1]}",
                status="PASS" if ok else "FAIL",
                details=f"Critical workflow symbol `{symbol}` must remain traceable for I/O and outcome audit.",
                evidence={"stdout": out, "stderr": err},
            )
        )

    phase = PhaseResult(
        phase_id="phase_3",
        title="Function Contract Audit",
        objective="Validate that core function entrypoints and documented API contracts remain visible and consistent.",
        checks=checks,
        status=phase_status(checks),
        next_step="Advance to dynamic workflow audit once core function contracts are intact.",
    )
    return {"phases": state.get("phases", []) + [asdict(phase)]}


def write_report(state: AuditState) -> AuditState:
    reports_dir = Path(state["reports_dir"])
    reports_dir.mkdir(parents=True, exist_ok=True)
    json_path = reports_dir / "AUDIT_RUN.json"
    md_path = reports_dir / "AUDIT_RUN.md"

    payload = {
        "repo_root": state["repo_root"],
        "generated_at": datetime.now(timezone.utc).isoformat(),
        "phases": state.get("phases", []),
    }
    json_path.write_text(json.dumps(payload, indent=2, ensure_ascii=False), encoding="utf-8")

    lines: list[str] = []
    lines.append("# Audit Run")
    lines.append("")
    lines.append(f"Generated at: `{payload['generated_at']}`")
    lines.append(f"Repo root: `{payload['repo_root']}`")
    lines.append("")
    for phase in payload["phases"]:
        lines.append(f"## {phase['phase_id']} - {phase['title']}")
        lines.append("")
        lines.append(f"- Status: `{phase['status']}`")
        lines.append(f"- Objective: {phase['objective']}")
        lines.append(f"- Next step: {phase['next_step']}")
        lines.append("")
        for check in phase["checks"]:
            lines.append(f"### {check['name']}")
            lines.append(f"- Status: `{check['status']}`")
            lines.append(f"- Details: {check['details']}")
            evidence = check.get("evidence") or {}
            if evidence:
                preview = json.dumps(evidence, ensure_ascii=False)[:500]
                lines.append(f"- Evidence: `{preview}`")
            lines.append("")

    md_path.write_text("\n".join(lines), encoding="utf-8")
    return {"outputs": {"json": str(json_path), "markdown": str(md_path)}, "phases": payload["phases"]}


def build_graph():
    graph = StateGraph(AuditState)
    graph.add_node("phase_preflight", phase_preflight)
    graph.add_node("phase_architecture", phase_architecture)
    graph.add_node("phase_dependencies", phase_dependencies)
    graph.add_node("phase_contracts", phase_contracts)
    graph.add_node("write_report", write_report)

    graph.add_edge(START, "phase_preflight")
    graph.add_edge("phase_preflight", "phase_architecture")
    graph.add_edge("phase_architecture", "phase_dependencies")
    graph.add_edge("phase_dependencies", "phase_contracts")
    graph.add_edge("phase_contracts", "write_report")
    graph.add_edge("write_report", END)
    return graph.compile()


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Run the structured static audit protocol.")
    parser.add_argument("--repo", default=str(ROOT), help="Repository root.")
    parser.add_argument("--reports-dir", default="documentation/generated", help="Directory to write audit reports.")
    return parser.parse_args()


def main() -> None:
    args = parse_args()
    repo_root = Path(args.repo).resolve()
    reports_dir = (repo_root / args.reports_dir).resolve()
    app = build_graph()
    result = app.invoke(
        {
            "repo_root": str(repo_root),
            "generated_at": datetime.now(timezone.utc).isoformat(),
            "reports_dir": str(reports_dir),
        }
    )
    print("Structured audit run generated:")
    print(f"- JSON: {result['outputs']['json']}")
    print(f"- Markdown: {result['outputs']['markdown']}")


if __name__ == "__main__":
    main()
