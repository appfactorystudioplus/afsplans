# Removed the Static Dashboard Sidebar — COMPLETE

[`home.blade.php`](../../../apps/dream_graph/resources/views/home.blade.php) had a second, entirely separate sidebar from the one just reworked in the React app: a static Tabler `<aside id="sideNavbar">` left over from the dashboard starter template. It held the "Dream Graph" brand link, a mobile theme toggle, dummy Profile/Settings/Logout links, and an empty `#graph-palette-root` div that nothing in the app ever mounted into (confirmed via grep before removing — the real node palette lives inside the React-rendered sidebar in `EditorShell.tsx`, unrelated to this element).

## What changed
Deleted the whole `<aside>` block. Checked `themeToggle.ts` first — its references to `#themeToggleMobile`/`#themeIconMobile` (both only inside the removed aside) are null-safe (`?.addEventListener`, `querySelectorAll`), so removing them doesn't throw; the header's own `#themeToggle`/`#themeIcon` are untouched and still work. The `<header>` navbar (theme toggle + user dropdown) was left in place — only the vertical dashboard sidebar was in scope for this change.

Note: this removed the only place the "Dream Graph" name was visibly shown on the page (the browser tab title from `<title>` still shows it) — flagging this since it wasn't explicitly discussed, in case a header brand mark is wanted back later.

## Verification
Blade-only change, no JS/TS touched. Ran `php artisan view:clear` and confirmed via `curl` that the rendered HTML no longer contains `sideNavbar` or `graph-palette-root`, and the page still returns `HTTP 200`. Visual confirmation is on you — still blocked by the browser tool's per-action-approval gate on `dream_graph.test`.
