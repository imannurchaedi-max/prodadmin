from __future__ import annotations

import json
import urllib.error
import urllib.parse
import urllib.request
from dataclasses import asdict, dataclass, field
from datetime import date, datetime, timezone
from pathlib import Path
from typing import Any, TypedDict

import psycopg2
from langgraph.graph import END, START, StateGraph
from db_config import load_db_config


PROJECT_ROOT = Path(__file__).resolve().parents[1]
BASE = "http://localhost/ProdAdmin/api"
SETUP_URL = "http://localhost/ProdAdmin/tools/setup.php"

DB = load_db_config()


class DynamicState(TypedDict, total=False):
    generated_at: str
    reports_dir: str
    context: dict[str, Any]
    phases: list[dict[str, Any]]
    outputs: dict[str, str]


@dataclass
class StepResult:
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
    steps: list[StepResult] = field(default_factory=list)
    next_step: str = ""


def api(path: str, data: dict[str, Any] | None = None, method: str = "POST", token: str = "") -> dict[str, Any]:
    url = f"{BASE}/{path}"
    payload = data or {}
    body = None
    if method.upper() == "GET" and payload:
        url += ("&" if "?" in url else "?") + urllib.parse.urlencode(payload)
    elif method.upper() != "GET":
        body = json.dumps(payload).encode("utf-8")
    req = urllib.request.Request(url, data=body, method=method.upper())
    req.add_header("Content-Type", "application/json")
    if token:
        req.add_header("Authorization", f"Bearer {token}")
    try:
        with urllib.request.urlopen(req, timeout=15) as response:
            raw = response.read().decode("utf-8", errors="ignore")
            return json.loads(raw or "{}")
    except urllib.error.HTTPError as exc:
        raw = exc.read().decode("utf-8", errors="ignore")
        try:
            return json.loads(raw or "{}")
        except json.JSONDecodeError:
            return {"success": False, "message": f"HTTP {exc.code}", "raw": raw}
    except Exception as exc:  # noqa: BLE001
        return {"success": False, "message": str(exc)}


def fetch_text(url: str, method: str = "GET", data: dict[str, str] | None = None) -> tuple[int, str]:
    encoded = None
    if data is not None:
        encoded = urllib.parse.urlencode(data).encode("utf-8")
    req = urllib.request.Request(url, data=encoded, method=method.upper())
    try:
        with urllib.request.urlopen(req, timeout=15) as response:
            return response.getcode(), response.read().decode("utf-8", errors="ignore")
    except urllib.error.HTTPError as exc:
        return exc.code, exc.read().decode("utf-8", errors="ignore")


def db_conn():
    return psycopg2.connect(**DB)


def step_status(steps: list[StepResult]) -> str:
    statuses = {step.status for step in steps}
    if "FAIL" in statuses:
        return "FAIL"
    if "PENDING" in statuses:
        return "PENDING"
    return "PASS"


def phase_auth(state: DynamicState) -> DynamicState:
    steps: list[StepResult] = []
    username = "Group C"
    machine = "Mesin BHP 3"

    api("auth.php?action=logout", {"username": username, "machine": machine})
    login = api("auth.php?action=login", {"username": username, "password": "12345", "machine": machine})
    token = login.get("data", {}).get("token", "")
    steps.append(
        StepResult(
            name="login_user",
            status="PASS" if login.get("success") and token else "FAIL",
            details="User login must return a valid bearer token.",
            evidence=login,
        )
    )

    validate = api("auth.php?action=validate", method="GET", token=token)
    steps.append(
        StepResult(
            name="validate_token",
            status="PASS" if validate.get("success") and validate.get("data", {}).get("username") == username else "FAIL",
            details="Validate endpoint must recognize the active session.",
            evidence=validate,
        )
    )

    duplicate = api("auth.php?action=login", {"username": username, "password": "12345", "machine": machine})
    waiting = duplicate.get("status") == "WAITING_APPROVAL" and bool(duplicate.get("tempToken"))
    steps.append(
        StepResult(
            name="duplicate_login_waiting",
            status="PASS" if waiting else "FAIL",
            details="Duplicate machine login must trigger takeover approval flow.",
            evidence=duplicate,
        )
    )

    temp_token = duplicate.get("tempToken", "")
    pending = api("auth.php?action=checkTakeover", {"username": username, "machine": machine, "token": token}, method="GET")
    steps.append(
        StepResult(
            name="takeover_request_visible",
            status="PASS" if pending.get("success") and pending.get("data", {}).get("hasRequest") else "FAIL",
            details="Active session must see pending takeover request.",
            evidence=pending,
        )
    )

    approve = api(
        "auth.php?action=takeoverDecision",
        {"username": username, "machine": machine, "approved": True, "token": token},
    )
    steps.append(
        StepResult(
            name="takeover_approve",
            status="PASS" if approve.get("success") else "FAIL",
            details="Takeover approval must complete without JSON or state errors.",
            evidence=approve,
        )
    )

    status = api(
        "auth.php?action=takeoverStatus",
        {"username": username, "machine": machine, "tempToken": temp_token},
        method="GET",
    )
    new_token = status.get("data", {}).get("token", "")
    steps.append(
        StepResult(
            name="takeover_status_success",
            status="PASS" if status.get("success") and status.get("data", {}).get("status") == "SUCCESS" and new_token else "FAIL",
            details="Requester must receive SUCCESS status and a usable session token.",
            evidence=status,
        )
    )

    old_invalid = api("auth.php?action=validate", method="GET", token=token)
    new_valid = api("auth.php?action=validate", method="GET", token=new_token)
    steps.append(
        StepResult(
            name="session_rotation",
            status="PASS" if (not old_invalid.get("success")) and new_valid.get("success") else "FAIL",
            details="Old token must be invalidated and new token must remain valid after takeover.",
            evidence={"old": old_invalid, "new": new_valid},
        )
    )

    logout = api("auth.php?action=logout", {"username": username, "machine": machine}, token=new_token)
    after_logout = api("auth.php?action=validate", method="GET", token=new_token)
    steps.append(
        StepResult(
            name="logout_revokes_token",
            status="PASS" if logout.get("success") and not after_logout.get("success") else "FAIL",
            details="Logout must revoke the token cleanly.",
            evidence={"logout": logout, "after_logout": after_logout},
        )
    )

    phase = PhaseResult(
        phase_id="phase_4a",
        title="Dynamic Auth & Takeover Audit",
        status=step_status(steps),
        objective="Verify login, validate, takeover, token rotation, and logout behavior through live HTTP requests.",
        steps=steps,
        next_step="Advance to transaction workflow only if auth/takeover flow is clean.",
    )
    context = state.get("context", {})
    context["auth"] = {"username": username, "machine": machine}
    return {"phases": state.get("phases", []) + [asdict(phase)], "context": context}


def phase_transactions(state: DynamicState) -> DynamicState:
    steps: list[StepResult] = []
    username = "Group D"
    machine = "Mesin BHP 4"

    api("auth.php?action=logout", {"username": username, "machine": machine})
    login = api("auth.php?action=login", {"username": username, "password": "12345", "machine": machine})
    token = login.get("data", {}).get("token", "")
    steps.append(
        StepResult(
            name="transaction_login",
            status="PASS" if login.get("success") and token else "FAIL",
            details="Transaction workflow requires a valid user token.",
            evidence=login,
        )
    )

    init = api("init.php", method="GET", token=token)
    steps.append(
        StepResult(
            name="init_data",
            status="PASS" if init.get("success") and isinstance(init.get("data", {}).get("config", {}).get("mesin"), list) else "FAIL",
            details="Init endpoint must return config/supplier/conversion payloads.",
            evidence={"keys": sorted(init.get("data", {}).keys()), "success": init.get("success")},
        )
    )

    today = date.today().isoformat()
    materials = [
        {
            "name": "POLIPROPILENA",
            "supplier": "PT Suplier A",
            "stockAwal": 500,
            "masuk": 200,
            "retur": 10,
            "reject": 5,
            "hours": [20, 25, 22, 18, 24, 23, 21, 19],
            "photos": [],
        },
        {
            "name": "POLYETHYLENE",
            "supplier": "",
            "stockAwal": 300,
            "masuk": 0,
            "retur": 0,
            "reject": 2,
            "hours": [15, 15, 15, 15, 15, 15, 15, 15],
            "photos": [],
        },
    ]
    outputs = [
        {
            "mid": "E2E01",
            "name": "PRODUK E2E",
            "catBag": "POLIPROPILENA",
            "catBox": "POLYETHYLENE",
            "qtyBox": 50,
            "counterPcs": 5000,
            "totalKg": 125.0,
            "lossKg": 3.5,
        }
    ]
    report = {
        "counterKg": "125.00",
        "lossKg": "3.50",
        "lossPct": "2.80",
        "rejectPrintingKg": 1,
        "speed": 480,
        "downtimeMin": 15,
        "downtimePct": "3.13%",
        "trouble": "Tidak ada",
        "nearMiss": "",
        "notes": "Dynamic audit transaction flow",
        "itemsDetail": [{"mid": "E2E01", "counterPcs": 5000, "totalKg": 125.0, "lossKg": 3.5}],
    }

    submit = api(
        "transactions.php?action=submit",
        {
            "tanggal": today,
            "shift": "2",
            "mesin": machine,
            "size": "Size L",
            "materialsJson": json.dumps(materials),
            "outputsJson": json.dumps(outputs),
            "reportJson": json.dumps(report),
        },
        token=token,
    )
    uuid = submit.get("data", {}).get("uuid", "")
    steps.append(
        StepResult(
            name="submit_transaction",
            status="PASS" if submit.get("success") and uuid and submit.get("data", {}).get("rev") == 0 else "FAIL",
            details="Submit must create a DRAFT transaction and return uuid/rev.",
            evidence=submit,
        )
    )

    db_evidence: dict[str, Any] = {}
    if uuid:
        with db_conn() as conn, conn.cursor() as cur:
            cur.execute("SELECT COUNT(*) FROM transactions WHERE uuid = %s", (uuid,))
            db_evidence["transactions"] = cur.fetchone()[0]
            cur.execute("SELECT COUNT(*) FROM transaction_materials WHERE transaction_uuid = %s", (uuid,))
            db_evidence["transaction_materials"] = cur.fetchone()[0]
            cur.execute(
                """
                SELECT COUNT(*)
                FROM material_hourly_usage mhu
                JOIN transaction_materials tm ON tm.id = mhu.transaction_material_id
                WHERE tm.transaction_uuid = %s
                """,
                (uuid,),
            )
            db_evidence["hourly_usage"] = cur.fetchone()[0]
            cur.execute("SELECT COUNT(*) FROM production_outputs WHERE transaction_uuid = %s", (uuid,))
            db_evidence["outputs"] = cur.fetchone()[0]
            cur.execute("SELECT COUNT(*) FROM production_reports WHERE transaction_uuid = %s", (uuid,))
            db_evidence["reports"] = cur.fetchone()[0]
    steps.append(
        StepResult(
            name="submit_db_side_effects",
            status="PASS" if db_evidence.get("transactions") == 1 and db_evidence.get("transaction_materials") == 2 and db_evidence.get("hourly_usage", 0) > 0 and db_evidence.get("outputs") == 1 and db_evidence.get("reports") == 1 else "FAIL",
            details="Submit must persist header, materials, hourly usage, outputs, and reports.",
            evidence=db_evidence,
        )
    )

    previous = api(
        "transactions.php?action=previousStock",
        {"mesin": machine, "shift": "3", "date": today},
        method="GET",
        token=token,
    )
    expected_stock = 500 + 200 - 10 - (sum(materials[0]["hours"]) + 5)
    actual_stock = float(previous.get("data", {}).get("POLIPROPILENA", 0) or 0)
    steps.append(
        StepResult(
            name="previous_stock",
            status="PASS" if previous.get("success") and abs(actual_stock - expected_stock) < 0.01 else "FAIL",
            details="Server stock calculation must match expected final stock from submitted shift.",
            evidence={"response": previous, "expected_stock": expected_stock, "actual_stock": actual_stock},
        )
    )

    history = api(
        "history.php?action=paged",
        {"page": 1, "limit": 20, "startDate": today, "endDate": today},
        method="GET",
        token=token,
    )
    history_items = history.get("data", {}).get("data", [])
    active = next((item for item in history_items if item.get("id") == uuid), None)
    steps.append(
        StepResult(
            name="history_after_submit",
            status="PASS" if history.get("success") and active and active.get("status") == "DRAFT" else "FAIL",
            details="Submitted transaction must appear in history as active DRAFT.",
            evidence={"history_total": history.get("data", {}).get("total"), "active_item": active},
        )
    )

    materials[0]["stockAwal"] = 520
    revise = api(
        "transactions.php?action=revise",
        {
            "uuid": uuid,
            "tanggal": today,
            "shift": "2",
            "mesin": machine,
            "size": "Size L",
            "materialsJson": json.dumps(materials),
            "outputsJson": json.dumps(outputs),
            "reportJson": json.dumps(report),
        },
        token=token,
    )
    steps.append(
        StepResult(
            name="revise_transaction",
            status="PASS" if revise.get("success") and revise.get("data", {}).get("rev") == 1 else "FAIL",
            details="Revise must create revision 1 and preserve shared uuid.",
            evidence=revise,
        )
    )

    diff = api("transactions.php?action=diff", {"uuid": uuid}, method="GET", token=token)
    versions = diff.get("data", {})
    steps.append(
        StepResult(
            name="diff_versions",
            status="PASS" if diff.get("success") and len(versions) == 2 else "FAIL",
            details="Diff must expose both rev0 and rev1 for the same transaction UUID.",
            evidence={"version_count": len(versions), "response": diff},
        )
    )

    finalize = api("transactions.php?action=finalize", {"uuid": uuid}, token=token)
    final_history = api(
        "history.php?action=paged",
        {"page": 1, "limit": 20, "startDate": today, "endDate": today},
        method="GET",
        token=token,
    )
    final_item = next((item for item in final_history.get("data", {}).get("data", []) if item.get("id") == uuid), None)
    steps.append(
        StepResult(
            name="finalize_transaction",
            status="PASS" if finalize.get("success") and final_item and final_item.get("status") == "FINAL" else "FAIL",
            details="Finalize must switch the active revision to FINAL and keep it visible in history.",
            evidence={"finalize": finalize, "history_item": final_item},
        )
    )

    admin_login = api("auth.php?action=login", {"username": "Admin", "password": "DAM!@#123", "machine": "Mesin BHP 5"})
    admin_token = admin_login.get("data", {}).get("token", "")
    stats = api("admin.php?action=stats", method="GET", token=admin_token)
    steps.append(
        StepResult(
            name="admin_stats",
            status="PASS" if admin_login.get("success") and stats.get("success") else "FAIL",
            details="Admin login and scorecard stats endpoint must respond cleanly.",
            evidence={"admin_login": admin_login, "stats_keys": sorted(stats.get("data", {}).keys()) if isinstance(stats.get("data"), dict) else stats},
        )
    )

    setup_code, setup_html = fetch_text(SETUP_URL, method="POST", data={"setup_pass": "setup2024"})
    steps.append(
        StepResult(
            name="setup_portal_auth",
            status="PASS" if setup_code == 200 and "Setup ProdAdmin" in setup_html else "FAIL",
            details="Setup portal must still be reachable and authenticate with the configured password.",
            evidence={"status_code": setup_code, "html_excerpt": setup_html[:200]},
        )
    )

    cleanup = {"user_logout": api("auth.php?action=logout", {"username": username, "machine": machine}, token=token)}
    if admin_token:
        cleanup["admin_logout"] = api("auth.php?action=logout", {"username": "Admin", "machine": "Mesin BHP 5"}, token=admin_token)
    steps.append(
        StepResult(
            name="cleanup_logout",
            status="PASS" if cleanup["user_logout"].get("success") else "FAIL",
            details="Cleanup logout should leave no dangling session for the test user.",
            evidence=cleanup,
        )
    )

    phase = PhaseResult(
        phase_id="phase_4b",
        title="Dynamic Transaction, History, Admin, and Setup Audit",
        status=step_status(steps),
        objective="Exercise submit/revise/finalize/history/admin/setup flows against the live localhost deployment and DB.",
        steps=steps,
        next_step="Proceed to failure-path and regression stress audit if all live workflow checks pass.",
    )
    return {"phases": state.get("phases", []) + [asdict(phase)], "context": state.get("context", {})}


def write_report(state: DynamicState) -> DynamicState:
    reports_dir = Path(state["reports_dir"])
    reports_dir.mkdir(parents=True, exist_ok=True)
    json_path = reports_dir / "DYNAMIC_AUDIT_RUN.json"
    md_path = reports_dir / "DYNAMIC_AUDIT_RUN.md"

    payload = {
        "generated_at": datetime.now(timezone.utc).isoformat(),
        "phases": state.get("phases", []),
    }
    json_path.write_text(json.dumps(payload, indent=2, ensure_ascii=False), encoding="utf-8")

    lines: list[str] = []
    lines.append("# Dynamic Audit Run")
    lines.append("")
    lines.append(f"Generated at: `{payload['generated_at']}`")
    lines.append("")
    for phase in payload["phases"]:
        lines.append(f"## {phase['phase_id']} - {phase['title']}")
        lines.append("")
        lines.append(f"- Status: `{phase['status']}`")
        lines.append(f"- Objective: {phase['objective']}")
        lines.append(f"- Next step: {phase['next_step']}")
        lines.append("")
        for step in phase["steps"]:
            lines.append(f"### {step['name']}")
            lines.append(f"- Status: `{step['status']}`")
            lines.append(f"- Details: {step['details']}")
            if step.get("evidence"):
                preview = json.dumps(step["evidence"], ensure_ascii=False)[:700]
                lines.append(f"- Evidence: `{preview}`")
            lines.append("")
    md_path.write_text("\n".join(lines), encoding="utf-8")
    return {"outputs": {"json": str(json_path), "markdown": str(md_path)}, "phases": payload["phases"]}


def build_graph():
    graph = StateGraph(DynamicState)
    graph.add_node("phase_auth", phase_auth)
    graph.add_node("phase_transactions", phase_transactions)
    graph.add_node("write_report", write_report)
    graph.add_edge(START, "phase_auth")
    graph.add_edge("phase_auth", "phase_transactions")
    graph.add_edge("phase_transactions", "write_report")
    graph.add_edge("write_report", END)
    return graph.compile()


def main() -> None:
    app = build_graph()
    result = app.invoke(
        {
            "generated_at": datetime.now(timezone.utc).isoformat(),
            "reports_dir": str((PROJECT_ROOT / "documentation" / "generated").resolve()),
        }
    )
    print("Dynamic audit run generated:")
    print(f"- JSON: {result['outputs']['json']}")
    print(f"- Markdown: {result['outputs']['markdown']}")


if __name__ == "__main__":
    main()
