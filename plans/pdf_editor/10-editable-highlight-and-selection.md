# Step 10 (web) — Highlight editable items and selection

**Desktop step:** [10-editable-highlight-and-selection.md](../../../../afsdesktop5/plans/pdf_editor/10-editable-highlight-and-selection.md). Status: planning only.

Fully reusable: the "highlight all editable items" toggle (border-only outlines, colour by kind, legend), selection rules and keyboard handling are driven by the shared page view-model plus CSS. Web notes: the toggle persists in `localStorage` (try/catch, per-viewer convenience) and the outline layer is a single absolutely-positioned overlay per page so it does not slow large documents; items outside the visible pages are not materialised.

Tests: toggle on/off visual snapshot; counts equal the model's element counts; no layout shift; works at 25 %–400 % zoom.
