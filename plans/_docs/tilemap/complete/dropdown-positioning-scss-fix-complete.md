# Dropdown Off-Screen Fix, Take 3: SCSS, and Actually Canceling Popper's Transform — COMPLETE

The previous plain-CSS attempt (`right: 0; left: auto;` on `.dropdown-menu-end`) still didn't work, and the user asked for the fix to be done in SCSS.

## Why the plain-CSS version was inert

Bootstrap's `Dropdown` component positions the menu via **Popper.js**, which sets an *inline* `transform: translate3d(x, y, 0)` on the menu element at open time. A stylesheet rule setting `right: 0; left: auto;` has no effect on that — Popper never touches `right` at all, it moves the element purely via `transform`, so the element ends up wherever Popper's `transform` places it regardless of what `right`/`left` say. That's why the earlier fix visibly changed nothing.

## The real fix

Converted [`graph-editor.css`](../../../apps/dream_graph/resources/css/graph-editor.scss) to `graph-editor.scss` (installed `sass` as a dev dependency; Vite picks up `.scss` automatically, no plugin config needed) and wrote a mixin that **cancels Popper's inline positioning outright** instead of trying to coexist with it:

```scss
@mixin static-dropdown-position($top, $right, $bottom, $left) {
    position: absolute !important;
    inset: $top $right $bottom $left !important;
    transform: none !important;
    margin: 0.125rem 0 0 !important;
}

.dropdown-menu-end {
    @include static-dropdown-position(100%, 0, auto, auto);
}

.dropend .dropdown-menu {
    @include static-dropdown-position(0, auto, auto, 100%);
    margin-left: var(--tblr-dropdown-spacer, 0.5rem) !important;
}
```

`transform: none !important` is the piece that actually matters — a stylesheet `!important` rule *does* win over an inline style for that property (unlike `right`/`left`, which Popper never sets, so there was nothing to win against before). With the transform neutralized, `inset: ... !important` fully determines the menu's position, guaranteed, regardless of whatever Popper computed or whether it ran at all. The viewport-width cap (`.dropdown-menu { max-width: calc(100vw - 1rem); }`) is unchanged from the previous attempt and still serves as a backstop for narrow mobile viewports.

## Verification
Confirmed the compiled CSS matches the mixin's expansion exactly (`grep`'d the built `graph-editor-*.css`). `npm run build` compiles cleanly (306 modules, same as before — SCSS didn't change module count). `php artisan view:clear` + `curl` confirm the live site returns `HTTP 200`. Full vitest suite: 192/193 pass (1 pre-existing unrelated floating-point failure, unchanged). Visual confirmation — the profile dropdown and the sidebar's View/Data dropdowns, expanded and collapsed-rail, desktop and narrow mobile — is on you; the browser tool's per-action-approval gate on `dream_graph.test` is still blocking me from checking it myself, and this specific bug (Popper's inline transform, not just alignment classes) is exactly the kind of thing that's easy to get subtly wrong twice without seeing it render, so please verify carefully this time.
