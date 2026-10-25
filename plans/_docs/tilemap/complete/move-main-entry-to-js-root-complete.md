# Moved main.tsx to resources/js/ Root — COMPLETE

The app's entry point lived at `resources/js/graph/main.tsx`, nested inside the `graph` feature folder despite being the whole app's bootstrap file, not something specific to the graph editor. Moved it to `resources/js/main.tsx`.

## What changed

- **New file:** [`resources/js/main.tsx`](../../../apps/dream_graph/resources/js/main.tsx) — same content as the old one, with every relative import path adjusted for the new location one level up:
  - `./canvas/theme.css` → `./graph/canvas/theme.css`
  - `./shell/EditorShell` → `./graph/shell/EditorShell`
  - `./shell/themeToggle` → `./graph/shell/themeToggle`
  - `../checkpoint*` → `./checkpoint*` (one less `../`, since `checkpoint/`/`checkpointHelper/` are siblings of `js/`, not of `js/graph/`)
- **Deleted:** the old `resources/js/graph/main.tsx`.
- **Updated references** (found via a project-wide grep for `graph/main`, confirmed to be the only two real source hits):
  - [`vite.config.js`](../../../apps/dream_graph/vite.config.js) — build entry input path.
  - [`home.blade.php`](../../../apps/dream_graph/resources/views/home.blade.php) — the `@vite([...])` call.
- Ran `php artisan view:clear` to drop the stale compiled Blade view cache that still had the old path baked in.

## Verification
`npm run build` compiles cleanly — same 306 modules as before the move, confirming nothing failed to resolve. `manifest.json` now keys off `resources/js/main.tsx` as the entry. Confirmed live via `curl`: `http://dream_graph.test/` returns `HTTP 200` and the rendered `<script>` tag points at the newly-built, correctly-hashed bundle. Full vitest suite: 192/193 pass (1 pre-existing unrelated floating-point failure, unchanged) — no test imported the old path directly.
