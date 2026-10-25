# 06-name-validation — Completion

**Status:** Core rule set implemented in both PHP and TypeScript. The
cross-language fixture-corpus parity test (§7) is not built.

## Summary

- **§3.1 (hard rules):** All ten implemented — `NAME_EMPTY`, `NAME_NUL`,
  `NAME_SLASH`, `NAME_DOT`, `PATH_ABSOLUTE`, `PATH_UNC`, `NAME_TOO_LONG`,
  `PATH_TOO_LONG`, `PATH_TOO_DEEP`, `NAME_INVALID_UTF8` — in
  `App\Services\Storage\NameValidator` (server, authoritative) and
  `resources/js/storage/NameValidator.ts` (client, advisory-only per §5).
- **§3.2 (portable rules):** All six implemented —
  `NAME_CONTROL_CHAR`, `NAME_WIN_RESERVED_CHAR`, `NAME_WIN_RESERVED_BASE`,
  `NAME_TRAILING_DOT_SPACE`, `NAME_LEADING_SPACE`, `NAME_BIDI_OVERRIDE`.
  Only portable mode exists; **posix mode (§2) is not built** — there is no
  account-level setting and no `collision_mode` toggle in the UI (the
  column exists in the schema per `04`, always `'portable'`).
- **§3.3 (warnings):** `NAME_LEADING_DOT` and `NAME_VERY_LONG` implemented
  server-side and surfaced as non-blocking warnings.
  **Not implemented:** `CASE_COLLISION` and `UNICODE_NORMALISATION` as
  distinct warnings — case folding and NFC normalisation are applied
  silently (as the collision-hash basis, §4), but the scan response never
  reports "this differs only by case/normalisation from an existing file"
  as a separate warning the member sees before uploading; it will simply
  surface as a `CONFLICTS_UNRESOLVED` 409 if the hash collides, which is
  correct-but-less-informative than the doc's UX (§6 example).
- **§4 (Unicode normalisation):** Implemented — every relPath is
  NFC-normalised server-side before validation/storage
  (`\Normalizer::normalize`, requires `ext-intl` — flagged below), and the
  collision hash is computed over the case-folded NFC form in portable mode.
  **Requires `ext-intl` on the server** — if missing, `NameValidator` fails
  closed (rejects every path with `NAME_INVALID_UTF8`) rather than silently
  skipping normalisation. This is a deployment prerequisite, not yet
  confirmed present on the target host.
- **§5 (where the scan runs):** All four stages implemented — client
  preflight (non-authoritative), `POST /scan`, `POST /sessions` (re-
  validates), `POST .../commit` (re-validates + re-asserts containment
  immediately before `rename(2)`).
- **§6 (member-facing report):** The rejected-file list with rule id and
  message is returned by the API; the polished UI (inline "Rename to..."/
  "Skip this file" actions, "Fix all automatically") is **not built** — the
  web client currently just alerts a list of rejected filenames and reasons
  for local (client-side) rejections, and shows conflicts in a simple modal
  with skip/replace/cancel — no per-file "Fix all automatically" (§6.1)
  substitution UI.
- **§7 (one rule set, three implementations, one corpus):** **Not built.**
  `name-fixtures.json` does not exist; there is no PHP/TypeScript parity
  test. Dart (mobile) has no validator at all — mobile client work isn't
  started (see `README.md`'s scope, doc 11). Acceptance A3 is not met.

## Files changed

- `app/Services/Storage/NameValidator.php` (new, server, authoritative)
- `resources/js/storage/NameValidator.ts` (new, client, advisory)

## Verification steps

- `php -l` on the PHP validator.
- `tsc --noEmit` on the TypeScript validator — passes, introduces no new
  type errors (there are pre-existing unrelated type errors elsewhere in
  the members frontend, none in files this change touches).
- Manually traced a subset of doc 06 §7.1's example corpus (`../escape.txt`,
  `CON`, `con.txt`, `trailing `, `q1:2026.pdf`, `café.txt` /
  `café.txt` normalising to the same form, a 256-byte segment) against
  both implementations by inspection — not an automated test.

## Follow-up work

- Build `name-fixtures.json` and the three-language parity test (§7) — this
  is acceptance criterion A3 and is the single most valuable follow-up,
  since it's what prevents the PHP/TS validators (and, eventually, Dart)
  from silently drifting.
- `CASE_COLLISION` / `UNICODE_NORMALISATION` as explicit pre-upload
  warnings, not just an eventual conflict.
- The "Fix all automatically" UX (§6.1).
- Posix mode (§2) — account setting, opt-in, hard-rules-only.
- Confirm `ext-intl` is installed on the deployment target.
