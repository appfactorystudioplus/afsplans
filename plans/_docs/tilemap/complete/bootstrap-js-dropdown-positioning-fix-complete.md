# Dropdown Off-Screen Fix: Bootstrap's JS Was Never Loaded — COMPLETE

**Reported symptom:** the top navbar's user-profile dropdown rendered partly off-screen.

## Root cause — much bigger than just that one dropdown

This app never actually imported Bootstrap's JS component library. Only `@tabler/core/dist/js/tabler.min.js` was loaded (Tabler-specific theme extras — sidebar behavior, chart theming, etc.), which assumes Bootstrap's own JS is loaded separately, per Tabler's standard architecture. It wasn't, even though both `bootstrap` and `@popperjs/core` were already installed as dependencies and simply never imported anywhere.

Confirmed by tracing the compiled CSS: `.dropdown-menu-end[data-bs-popper]{right:0;left:auto}` — Bootstrap 5.3's alignment classes only take effect once the `data-bs-popper` attribute is present, and that attribute is set programmatically (`Manipulator.setDataAttribute(this._menu, 'popper', ...)` inside Bootstrap's own `Dropdown` class) only when Bootstrap's real dropdown component initializes and calls `Popper.createPopper(...)` on it. Without that JS ever running, `dropdown-menu-end` silently did nothing everywhere in the app, and every dropdown fell back to plain left-aligned CSS positioning with no viewport-collision awareness — overflowing off-screen whenever its toggle button sat near an edge. The user-profile dropdown in the top navbar hit this hardest since its toggle sits at the far right, but this affected every dropdown in the app (View/Data in the sidebar, both graph-location variants tried earlier, etc.) — they "worked" in the sense of opening/closing, just without proper positioning.

## The fix

One line in [`main.tsx`](../../../apps/dream_graph/resources/js/main.tsx): `import 'bootstrap';` — Vite resolves this to `bootstrap.esm.js` via the package's `module` field, which pulls in `@popperjs/core` (already installed) for real collision-aware positioning. This is a single foundational fix, not per-dropdown patches — Popper's `flip`/`preventOverflow` modifiers (Bootstrap's dropdown defaults) automatically keep every dropdown in the app within the viewport, which is inherently viewport-size-independent — it fixes mobile the same way it fixes desktop, by computing against whatever viewport actually exists at open-time rather than needing separate handling.

## Verification
`npm run build` compiles cleanly; bundle grew from ~719KB to ~809KB (expected — real Bootstrap + Popper code, confirmed present via `class Dropdown` and `Popper.createPopper` references in the installed package, and a bundle-size delta consistent with that addition). Confirmed live via `curl`: `http://dream_graph.test/` returns `HTTP 200` and serves the newly-built bundle. Full vitest suite: 192/193 pass (1 pre-existing unrelated floating-point failure, unchanged) — no test imports `main.tsx` directly, so nothing needed updating there. Visual confirmation (checking the profile dropdown, and the View/Data sidebar dropdowns, on both desktop and a narrow mobile viewport) is on you — still blocked by the browser tool's per-action-approval gate on `dream_graph.test`.
