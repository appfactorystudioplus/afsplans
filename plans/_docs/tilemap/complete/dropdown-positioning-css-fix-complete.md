# Dropdown Off-Screen Fix, Take 2: CSS Override Instead of a Second JS Library — COMPLETE

## The previous attempt was wrong and caused a regression

The prior fix (`import 'bootstrap'` in `main.tsx`) assumed Bootstrap's JS was never loaded at all. That was wrong — the user caught it immediately: `@tabler/core/dist/js/tabler.min.js` already bundles its own copy of Bootstrap's Dropdown + Popper internally (confirmed by grepping it: 18 `data-bs-toggle` references, 19 `Popper` references, already present in that file). Adding a second, separate Bootstrap JS instance created two competing dropdown implementations, which broke dropdowns entirely — clicking them stopped doing anything. Reverted that import; `main.tsx` is back to exactly what it was before (rebuilt bundle hash `main-D8jAdKyz.js` matches byte-for-byte with the pre-regression build).

## The actual fix: an unconditional CSS override, no JS changes

Bootstrap 5.3's alignment classes are gated behind a `[data-bs-popper]` attribute selector — `.dropdown-menu-end[data-bs-popper]{right:0;left:auto}` and `.dropend .dropdown-menu[data-bs-popper]{top:0;right:auto;left:100%;...}` — and that attribute is only added by Bootstrap's JS at the moment Popper actually initializes a given dropdown instance. Whatever the exact reason Tabler's bundled copy doesn't reliably do this for every dropdown here, the effect is the same: the alignment rule silently never applies, and menus fall back to plain left-aligned positioning, overflowing off-screen when the toggle sits near an edge.

Added to [`graph-editor.css`](../../../apps/dream_graph/resources/css/graph-editor.css) (alongside the project's existing Tabler theme overrides):
- `.dropdown-menu-end { right: 0; left: auto; }` — unconditional, no longer gated on the JS-set attribute. Fixes the top navbar's profile dropdown and any other `-end`-aligned menu.
- `.dropend .dropdown-menu { top: 0; right: auto; left: 100%; margin-top: 0; margin-left: var(--tblr-dropdown-spacer, 0.5rem); }` — the same gating bug affects `.dropend`, used by the sidebar's View/Data dropdowns when the sidebar is collapsed to its icon rail.
- `.dropdown-menu { max-width: calc(100vw - 1rem); }` — a belt-and-suspenders cap so no dropdown menu (even a plain, non-`-end` one) can exceed the viewport width on a narrow mobile screen, regardless of alignment.

This sidesteps the JS mystery entirely — it doesn't matter whether Tabler's bundled Popper does or doesn't tag the attribute in any given case, since the positioning is now guaranteed by CSS alone. It's also inherently viewport-size-independent (`right: 0`/`left: 100%` and the `calc(100vw - ...)` cap both work identically at any screen width), so this covers mobile the same way it covers desktop without special-casing.

## Verification
`npm run build` compiles cleanly; JS bundle hash is unchanged from before the regression (confirming a clean revert), only the CSS bundle changed. `php artisan view:clear` + `curl` confirm the live site still returns `HTTP 200`. Full vitest suite: 192/193 pass (1 pre-existing unrelated floating-point failure, unchanged). Visual confirmation — checking the profile dropdown and the sidebar's View/Data dropdowns (both expanded and collapsed-rail states) on desktop and a narrow mobile viewport — is on you, since the browser tool's per-action-approval gate on `dream_graph.test` is still blocking me from doing it myself.
