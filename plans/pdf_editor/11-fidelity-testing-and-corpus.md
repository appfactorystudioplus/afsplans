# Step 11 (web) — Fidelity testing and corpus

**Desktop step:** [11-fidelity-testing-and-corpus.md](../../../../afsdesktop5/plans/pdf_editor/11-fidelity-testing-and-corpus.md). Status: planning only.

- **One corpus, one harness.** The shared modules are tested by the desktop Node harness; the web consumes the same fixtures by copy (hash-listed manifest, same rule as the shared code, D-W2). Fixtures are never fetched at run time.
- **Browser run:** a Playwright suite (Chromium + Firefox; WebKit optional) opens a fixed subset, runs parse → model → export in the real Workers, and compares to the Node results (element counts, text, page images within the Step 11 thresholds).
- **Benchmarks** for the vision twins (Step 8) and OCR (Step 7) are recorded with machine details and compared with the desktop native numbers.
- **PHP side:** PHPUnit against the real MySQL test DB for library, uploads, quota, shares; no SQLite rewrites.
- **Cleanup:** per `w/AGENTS.md` no Vite dev server is started for tests; builds are run once at the end of a chunk; temp output removed.
