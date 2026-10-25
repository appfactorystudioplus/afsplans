# Dropdown Off-Screen: the Actual Bug — COMPLETE

Three attempts at this (a JS import that caused a regression, then two CSS/SCSS approaches chasing a Popper-transform theory) were all solving the wrong problem. The user pointed at the real one: the dropdown toggle sits on the **left** side of the screen, not the right, and the menu was styled `dropdown-menu-end` (right-aligned to the toggle) — extending further left past the toggle and off the edge of the viewport.

## Why the toggle is on the left at all

[`home.blade.php`](../../../apps/dream_graph/resources/views/home.blade.php)'s header has exactly one flex child inside `.container-fluid`: the `<div class="navbar-nav flex-row order-md-last">` wrapping the profile dropdown. `order-md-last` sets the CSS `order` property — which only has any visual effect when there's more than one flex item to reorder *relative to*. With only one item in the container (true ever since the dashboard sidebar and its other nav items were removed earlier in this session), `order` is a no-op, and the item just sits at the flex container's default start (left) edge — at every screen size, not only mobile. `dropdown-menu-end` then aligns the menu's *right* edge to that left-sitting toggle, pushing the menu further left, off-screen.

## The fix

Changed the class to `dropdown-menu-start` in `home.blade.php`. Checked first that Tabler's `dropdown-menu-arrow` decoration (the little triangle pointer) supports both alignments natively — it defaults to a left-pointing arrow (`left: .75rem`) and only flips to the right via a dedicated `.dropdown-menu-arrow.dropdown-menu-end` override — so swapping the alignment class alone repositions the arrow correctly too, no extra CSS needed.

## Cleanup: removed the wrong-theory CSS

The previous SCSS mixin (`static-dropdown-position`, forcing `transform: none !important` to cancel Popper's inline positioning) was addressing a real, legitimate CSS mechanism — Popper *does* position via inline transform when active — but it wasn't actually the cause of this bug, and no other dropdown in the app has a demonstrated version of that problem. Removed the mixin and its two `@include` call sites from `graph-editor.scss`, keeping only the simple `.dropdown-menu { max-width: calc(100vw - 1rem); }` safety net, which is still reasonable general hardening against any future long-content overflow.

## Verification
`npm run build` compiles cleanly. `php artisan view:clear` + `curl` confirm the live page now serves `dropdown-menu-start` (not `-end`). Full vitest suite: 192/193 pass (1 pre-existing unrelated floating-point failure, unchanged). Visual confirmation is on you — the browser tool's per-action-approval gate on `dream_graph.test` is still blocking me from checking it myself — but this fix targets the actual, correctly-diagnosed cause rather than a plausible-sounding theory, so it should hold.
