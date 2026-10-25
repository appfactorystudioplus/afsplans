# Flash-of-Unstyled-Content Fix — COMPLETE

**Reported symptom:** the page briefly showed raw text before the actual UI rendered.

## Root cause

[`home.blade.php`](../../../apps/dream_graph/resources/views/home.blade.php) had its single `@vite([...])` call — which emits both the CSS `<link>` tags and the JS bundle `<script>` — sitting at the very bottom of `<body>`, after the entire static Tabler dashboard shell (sidebar, header, placeholder "User Name"/"Admin" text, dropdown menus). The browser parses and paints that HTML top-to-bottom as it arrives; with the stylesheet links placed after all of it, the raw unstyled markup painted first, then the CSS arrived and restyled everything, then React finally mounted into `#graph-editor-root` — three visibly separate flashes. There's also a synchronous, non-deferred third-party `<script src="https://...sdk.js">` in `<head>` that blocks further parsing, extending the window before the CSS request even started.

## The fix

Moved the entire `@vite([...])` call into `<head>`, ahead of the third-party SDK script, so the browser starts fetching stylesheets immediately and applies them before painting any body content. The JS entry doesn't need special handling here — Vite emits it as `type="module"`, which per the HTML spec is deferred until after parsing regardless of where the `<script>` tag sits, so moving it earlier doesn't change execution timing or risk running before `#graph-editor-root` exists in the DOM.

Also ran `php artisan view:clear` to drop any stale compiled Blade cache, and confirmed via `curl` that the rendered HTML now has the `<link rel="stylesheet">` tags and `<script type="module">` inside `<head>` (previously they were the last thing in `<body>`).

## Verification
Blade-only change — no TS/JS logic touched. Full vitest suite unaffected: 192/193 pass (1 pre-existing unrelated failure). `npm run build` was already current from the prior fix in this session. Visual confirmation is on you — I still can't drive the browser myself (per-action-approval gate on `dream_graph.test` persists), so please confirm the flash is gone on your end.
