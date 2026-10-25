# Step 12 (web) — Rollout and migration

**Desktop step:** [12-rollout-and-migration.md](../../../../afsdesktop5/plans/pdf_editor/12-rollout-and-migration.md). Status: planning only.

- **Shared-code extraction first:** the desktop refactor (P1) creates `src/shared/pdf/`; the web copies it with the manifest and parity test (D-W2). Web milestones W-M1…W-M6 follow the desktop ones.
- **Feature flag:** `engine = "legacy" | "parser"` per document and a member-level default (config, not an `.env` branch), legacy default until Step 11 thresholds pass twice.
- **Data migration:** existing documents become library documents (Step 1); dry-run command, then run; reversible because files are untouched.
- **Retire `editor.ts` eager path** after the parser engine is default for one release.
- **Builds:** `npm run build` run once at the end of each chunk; no dev server; no commits unless the owner asks; asset hashes/filenames per the repo's Vite setup.
- **Docs:** user guide page, developer README for the Worker/shared-code layout, and the support matrix (what is editable) shared with the desktop.
- **Translations:** all new strings in en/es/de/zh.
