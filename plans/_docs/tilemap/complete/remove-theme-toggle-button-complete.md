# Removed the Theme Toggle Button — COMPLETE

Removed the light/dark theme toggle (`#themeToggle` / `#themeIcon`) from the header in [`home.blade.php`](../../../apps/dream_graph/resources/views/home.blade.php). Its mobile counterpart (`#themeToggleMobile`) was already gone as part of the earlier dashboard-sidebar removal.

## Left untouched, deliberately

[`themeToggle.ts`](../../../apps/dream_graph/resources/js/graph/shell/themeToggle.ts)'s `initThemeToggle()` is still called from `main.tsx` and still does something useful with no button present: it reads the last-stored theme from `localStorage` (defaulting to `dark`) and applies it to `document.body` on load. Its click-wiring (`document.getElementById('themeToggle')?.addEventListener(...)`) and icon-updating (`applyIcons`, targeting `#themeIcon`/`#themeIconMobile`) are null-safe and simply no-op now that neither element exists — no errors, just vestigial code paths that never run. Left in place since the scope here was removing the button, not the theme system; flagging in case a toggle affordance is wanted back somewhere else later. Net effect: the page still respects whatever theme was last set (or dark by default), there's just no way to change it from the UI anymore.

## Verification
Blade-only change, no JS/TS touched. Ran `php artisan view:clear` and confirmed via `curl` that the rendered HTML no longer contains `themeToggle` or `themeIcon`, and the page still returns `HTTP 200`.
