# Character Audit

Generated: 2026-05-24

## Conclusion

- Files are generally valid UTF-8, but many contain mojibake text that was already corrupted before this audit.
- This is mostly a source hygiene and maintainability issue, not a universal runtime crash issue.
- The real risk is patch/context mismatch, ugly UI strings, and future edits becoming less reliable.

## Runtime Files With Mojibake Risk

- None

## Highest Counts

- `assets/xlsx.full.min.js`: `mojibake=51698`, `runtime=false`
- `documentation/generated/FUNCTION_DEPENDENCY_MAP.json`: `mojibake=5`, `runtime=false`
- `python_bot/character_audit.py`: `mojibake=5`, `runtime=false`
- `docs/generated/CHARACTER_AUDIT.md`: `mojibake=2`, `runtime=false`

## Non-Runtime Files Also Affected

- `assets/xlsx.full.min.js`: `mojibake=51698`, `non_ascii=122918`
- `documentation/generated/FUNCTION_DEPENDENCY_MAP.json`: `mojibake=5`, `non_ascii=34`
- `python_bot/character_audit.py`: `mojibake=5`, `non_ascii=5`
- `docs/generated/CHARACTER_AUDIT.md`: `mojibake=2`, `non_ascii=2`

## Interpretation

- `decode_ok=true` means the file can be read as UTF-8.
- `mojibake_count>0` means the file already contains wrong visible characters like `â`, `Ã`, or replacement markers.
- Files with mojibake in comments may still execute fine.
- Files with mojibake inside runtime strings can leak broken text to UI and make patch matching brittle.
