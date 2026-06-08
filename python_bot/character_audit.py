from __future__ import annotations

import json
from dataclasses import asdict, dataclass
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
GENERATED = ROOT / "docs" / "generated"
EXTENSIONS = {".php", ".js", ".md", ".css", ".json", ".sql", ".py"}
MARKERS = ["â", "Ã", "�"]
RUNTIME_FOCUS = {
    "index.php",
    "api/auth.php",
    "api/admin.php",
    "api/config.php",
    "api/conversions.php",
    "api/history.php",
    "api/init.php",
    "api/materials.php",
    "api/photos.php",
    "api/settings.php",
    "api/transactions.php",
    "assets/app/api.js",
    "assets/app/auth.js",
    "assets/app/admin.js",
    "assets/app/form.js",
}


@dataclass
class FileFinding:
    path: str
    decode_ok: bool
    non_ascii_count: int
    mojibake_count: int
    runtime_file: bool


def scan_file(path: Path) -> FileFinding:
    rel = path.relative_to(ROOT).as_posix()
    try:
        text = path.read_text(encoding="utf-8")
        non_ascii = sum(1 for ch in text if ord(ch) > 127)
        mojibake = sum(text.count(marker) for marker in MARKERS)
        return FileFinding(
            path=rel,
            decode_ok=True,
            non_ascii_count=non_ascii,
            mojibake_count=mojibake,
            runtime_file=rel in RUNTIME_FOCUS,
        )
    except UnicodeDecodeError:
        return FileFinding(
            path=rel,
            decode_ok=False,
            non_ascii_count=0,
            mojibake_count=0,
            runtime_file=rel in RUNTIME_FOCUS,
        )


def render_md(findings: list[FileFinding]) -> str:
    runtime = [f for f in findings if f.runtime_file and (not f.decode_ok or f.mojibake_count)]
    docs = [f for f in findings if not f.runtime_file and (not f.decode_ok or f.mojibake_count)]
    worst = sorted(
        [f for f in findings if f.decode_ok and f.mojibake_count],
        key=lambda item: (item.runtime_file, item.mojibake_count),
        reverse=True,
    )[:15]

    lines = [
        "# Character Audit",
        "",
        "Generated: 2026-05-24",
        "",
        "## Conclusion",
        "",
        "- Files are generally valid UTF-8, but many contain mojibake text that was already corrupted before this audit.",
        "- This is mostly a source hygiene and maintainability issue, not a universal runtime crash issue.",
        "- The real risk is patch/context mismatch, ugly UI strings, and future edits becoming less reliable.",
        "",
        "## Runtime Files With Mojibake Risk",
        "",
    ]

    if runtime:
        for item in sorted(runtime, key=lambda x: x.mojibake_count, reverse=True):
            lines.append(
                f"- `{item.path}`: `mojibake={item.mojibake_count}`, `non_ascii={item.non_ascii_count}`"
            )
    else:
        lines.append("- None")

    lines += [
        "",
        "## Highest Counts",
        "",
    ]
    for item in worst:
        lines.append(
            f"- `{item.path}`: `mojibake={item.mojibake_count}`, `runtime={str(item.runtime_file).lower()}`"
        )

    lines += [
        "",
        "## Non-Runtime Files Also Affected",
        "",
    ]
    if docs:
        for item in sorted(docs, key=lambda x: x.mojibake_count, reverse=True)[:15]:
            lines.append(
                f"- `{item.path}`: `mojibake={item.mojibake_count}`, `non_ascii={item.non_ascii_count}`"
            )
    else:
        lines.append("- None")

    lines += [
        "",
        "## Interpretation",
        "",
        "- `decode_ok=true` means the file can be read as UTF-8.",
        "- `mojibake_count>0` means the file already contains wrong visible characters like `â`, `Ã`, or replacement markers.",
        "- Files with mojibake in comments may still execute fine.",
        "- Files with mojibake inside runtime strings can leak broken text to UI and make patch matching brittle.",
    ]
    return "\n".join(lines) + "\n"


def main() -> None:
    findings: list[FileFinding] = []
    for path in ROOT.rglob("*"):
        if path.is_file() and path.suffix.lower() in EXTENSIONS:
            findings.append(scan_file(path))

    findings.sort(key=lambda item: item.path)
    GENERATED.mkdir(parents=True, exist_ok=True)
    (GENERATED / "CHARACTER_AUDIT.json").write_text(
        json.dumps({"findings": [asdict(f) for f in findings]}, indent=2),
        encoding="utf-8",
    )
    (GENERATED / "CHARACTER_AUDIT.md").write_text(render_md(findings), encoding="utf-8")
    print("Character audit generated:")
    print(f"- JSON: {GENERATED / 'CHARACTER_AUDIT.json'}")
    print(f"- Markdown: {GENERATED / 'CHARACTER_AUDIT.md'}")


if __name__ == "__main__":
    main()
