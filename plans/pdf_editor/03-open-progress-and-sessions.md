# Step 3 (web) — Opening with progress, sessions, autosave

**Desktop step:** [03-open-progress-and-sessions.md](../../../../afsdesktop5/plans/pdf_editor/03-open-progress-and-sessions.md). Status: planning only.

Same stages and loading bar (fetch → parse → analyse → automatic OCR → ready), driven by **Worker progress messages** (D-W3) with cancel. Deltas:

- **Open** fetches `model/` if present (instant open) and otherwise runs the parse in a Worker, then saves the model so later opens are fast. The page shows pages as soon as they are parsed (lazy render, as the web editor already does).
- **Automatic OCR** (D-W5) runs after the editor is usable, page by page in a Worker pool sized to `navigator.hardwareConcurrency − 1` (max 4), pausing when the tab is hidden for long; opt-out in settings.
- **Autosave** keeps the existing server autosave but writes the **delta** (small JSON) rather than a whole PDF; conflicts use an `If-Match` revision so two tabs cannot silently overwrite each other (409 → "reload or keep mine").
- **Sessions:** one editing tab per document is enforced softly (a heartbeat lock with a takeover prompt), because the server holds the only copy.
- **Crash/reload recovery:** unsaved delta is mirrored to IndexedDB and offered on next open.
- **Close warnings:** `beforeunload` only when the delta is not yet accepted by the server.

Tests: progress ordering and cancel; reload during OCR resumes; two-tab conflict; offline autosave then reconnect; big (600-page) document memory ceiling.
