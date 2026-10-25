# Create-Project Response-Shape Bug — COMPLETE

**Found and fixed during live Herd (`http://dream_graph.test`) verification.** Creating a project and immediately clicking "Open" on it threw `No query results for model [App\Models\GraphProject] undefined` — a `GET /api/projects/undefined` request, confirmed by matching the exact Laravel `ModelNotFoundException` message text the user reported.

## Root cause

`ProjectController::store()` (and `show()`) return a wrapped envelope: `{project, graphs, rootGraph}`. But [`client.ts`](../../../apps/dream_graph/resources/js/graph/api/client.ts)'s `createProject()` was typed as returning a flat `Project` and handed the raw response straight through. [`projectStore.ts`](../../../apps/dream_graph/resources/js/graph/store/projectStore.ts)'s `createProject` action then pushed that whole envelope into the `projects` array as if it were a `Project` — so the freshly-created card's `uuid` was `undefined`. Clicking "Open" called `handleOpen(project.uuid)` with that `undefined`, producing the exact route-model-binding failure reported.

This only affected the just-created card in the same session; a page reload masked it, since `loadProjects()` re-fetches from `GET /projects` (`index()`), which returns a correctly-shaped flat list.

## The fix

- `client.ts`: `createProject()` now returns `Promise<{ project: Project; graphs: Graph[]; rootGraph: Graph }>`, matching `getProject()`'s already-correct shape.
- `projectStore.ts`: `createProject` destructures `{ project: newProject }` from the response before pushing it into `projects`.

## Verification

New regression test [`createProject.test.ts`](../../../apps/dream_graph/resources/js/graph/store/__tests__/createProject.test.ts) mocks `apiClient.createProject` to return the real wrapped shape and asserts the stored project has a real `uuid`. Verified via `git stash` on the two fixed files: **red** (`expected undefined to be 'proj-uuid-1'`) without the fix, **green** with it restored.

Full suite: 189/190 vitest tests pass (1 pre-existing unrelated floating-point failure in `scripting.test.ts`, not caused by this change). `npm run build` recompiled cleanly (306 modules) — the Herd site was serving a static built bundle with no Vite dev server running, so a rebuild was required for the fix to actually reach the browser.

Also confirmed separately, unrelated to this bug: `tests/Feature/ApiTest.php` and `tests/Feature/NestedGraphsTest.php` fail with `Call to undefined method App\Models\GraphProject::factory()` — `GraphProject` doesn't `use HasFactory` and no factory class exists for it. This predates the entire tilemap effort (last touched in the initial two commits) and was flagged to the user rather than fixed, since it's out of scope for this bug.

## Root database issue (also encountered, not a code bug)

Before finding the above, the user also saw `Unexpected token '<'... is not valid JSON` on first load. Traced via `storage/logs/laravel.log` to a transient `SQLSTATE[HY000] [1049] Unknown database 'demo_apps'` — the DBngin-managed MySQL instance wasn't reachable/warmed yet when Herd's PHP-FPM process handled the very first request. Confirmed resolved: `demo_apps` exists (`SHOW DATABASES`), and both `GET /` and `GET /api/projects` now return `HTTP 200` reliably. No code change was needed for this part.
