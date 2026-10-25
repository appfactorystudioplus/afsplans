# 03 — API Contracts: folding `state()`/`preview()` into `CheckpointAllocV2` — Complete

## Status

Done. `CheckpointAllocV2.state()` and `.preview()` are real, working methods on the shared
client SDK now — not `window.Portal.membership` (§3.1's own 2026-09-23 correction), a plain
class method, matching every other method on this class. Both resources with a retention
engine (`storage_files_size_kb`, `domain_email_accounts`) are fully wired end to end,
client through server. Every other resourceId throws a named, deliberate error rather than
fabricating a shape — `items()`/`move()` remain unbuilt, as does the chooser UI they would
back.

## Summary

**A new backend read, symmetric across both resources.** `RetentionService::state()` and
`EmailRetentionService::state()` (members) compute the `AllocationState` contract shape
(`plan`, `allowance`, `bands[]`, `retention`) from data these two services already had —
`bandStatesFor()`, `RetentionEngine::effectiveState()`, `summaryFor()`, the tier registry
clients — all built in plan 10 for `previewChange()`. `state()` needed no new inventory
reads, just a new assembly of existing ones. Two new routes:
`GET /api/v1/storage/state` and `GET /api/v1/email/state?domain=X`, both member-facing
(`checkpoint.verify`), both opportunistically reconciling before reading, matching
`profile()`'s own precedent.

**`CheckpointAllocV2` gained `.state(resourceId, scope?)` and `.preview(resourceId, toPlan,
scope?)`** — the first two methods on this class that ever leave Parse. A small internal
`RETENTION_ROUTES` table (2 entries today) maps a resourceId to its `members` endpoints;
any other resourceId throws `ALLOC_V2_RETENTION_NOT_BUILT` immediately, by design — §04's
"wired forward-only is not done" rule applies to this facade too, and a plausible-looking
fabricated response would be a worse failure mode than a named error. Both methods
authenticate the call with the same Checkpoint bearer `getToken()` already provides,
against a new `config.membersBaseUrl`.

**`preview()`'s scoping had to diverge from `state()`'s, and from the contract's own naive
reading.** `.../email/preview` (built in plan 10, for `BillingApp.ts`'s confirm dialog)
aggregates every domain a member holds in one response — it was built for "warn about all
of them before this plan change," not "answer about the one domain I named." `state()`'s
backend, built fresh for this pass, takes `?domain=X` directly. `CheckpointAllocV2.preview()`
still requires `scope.domain` for a scoped resource (the facade's contract is per-instance,
matching `canUse`/`consume`/`release`) — it fetches the aggregate and picks that one domain
out client-side, rather than exposing the aggregate shape through the facade or building a
second backend endpoint that duplicates the first.

**CORS, enabled for the first time on `members`, scoped to exactly these four routes.**
`members` had no `config/cors.php` before this pass — Laravel's `HandleCors` middleware
(in the default global stack) resolved `config('cors.paths', [])` to an empty array and
matched nothing, so no CORS headers were ever added to any response. That was invisible
until now because every `members` consumer was either same-origin or a signed
server-to-server call, neither of which CORS gates. `alloc_v2.ts`, copied into all nine
app repos, breaks that — `state()`/`preview()` need to work from a browser on any of the
other eight origins. The new `config/cors.php` scopes `paths` to exactly
`api/v1/storage/state`, `api/v1/storage/preview`, `api/v1/email/state`,
`api/v1/email/preview` — not `api/*` — so this does not silently widen every other
`members` route (storage uploads, domain management, etc.) to cross-origin access they
were never designed for.

**Synced to all nine apps and wired into `www`'s own factory**, so the deliverable is not
"exists in one repo, called by nothing" — the exact anti-pattern `RetentionEngine::preview()`
itself was before plan 10 (§10.2), and the one `04-feature-template.md` names by name.
`packages/afsserver/bin/sync-checkpoint-client.sh` (fixed in `06-registry-distribution.md`'s
own 2026-09-23 addendum) propagated the edited `alloc_v2.ts` to `members`, `market`, `auth`,
`coordinator`, `dream_graph`, `notes`, `pdf_editor`, `flow` — verified byte-identical after.
`www`'s `registry.ts` factory now passes `membersBaseUrl: import.meta.env.VITE_MEMBERS_URL`,
a new frontend-exposed alias for the `MEMBERS_URL` value every `www` environment file
already had (added to `.env` and all four `_env/*/`.env` templates).

## Files changed

| File | Repo | Change |
|---|---|---|
| `app/Checkpoint/Storage/RetentionService.php` | members | New `state()` |
| `app/Checkpoint/Email/EmailRetentionService.php` | members | New `state()` |
| `app/Http/Controllers/Storage/StorageBucketController.php` | members | New `state()` action |
| `app/Http/Controllers/Email/EmailPreviewController.php` | members | New `state()` action |
| `routes/web.php` | members | Two new routes |
| `config/cors.php` | members | New file — 4 paths, GET only, token-authenticated so `allowed_origins: ['*']` is safe |
| `resources/js/checkpoint/alloc_v2.ts` | www (source), synced to all 9 | `membersBaseUrl` config field; `AllocV2AllocationState`/`AllocV2PreviewResult` types; `RETENTION_ROUTES` table; `.state()`, `.preview()`, and three private helpers |
| `resources/js/registry.ts` | www | `createAllocV2()` passes `membersBaseUrl` |
| `.env`, `_env/{local,dev,vagrant,prod}/.env` | www | New `VITE_MEMBERS_URL="${MEMBERS_URL}"` |
| `tests/Feature/Storage/StorageStateTest.php` | members | New. 3 tests |
| `tests/Feature/Email/EmailStateTest.php` | members | New. 3 tests |
| `03-api-contracts.md` | plans | §3.1 corrected (see the separate 2026-09-23 correction, prior to this implementation pass); OQ-J/OQ-K resolved |

No new database table beyond nothing — `state()` reads exactly the tables `previewChange()`
already reads.

## Design decisions not fully spelled out in the plan text

- **`preview()`'s client-side domain extraction, not a second backend endpoint.** Detailed
  in the summary above. The alternative — a new `GET /api/v1/email/preview-one?domain=X`
  matching `state()`'s shape — would have meant two server-side computations of the same
  thing for two different callers, when one endpoint plus a client-side pick is simpler and
  already correct.
- **CORS scoped to 4 paths, not `api/*`.** Explained above; the alternative (blanket
  `api/*`) would have been a larger, unreviewed change to `members`'s attack surface for
  routes nothing calls cross-origin.
- **`allowed_origins: ['*']` rather than an explicit allowlist.** These four routes
  authenticate via `Authorization: Bearer <token>`, never a cookie — `supports_credentials`
  stays `false` (Laravel's own default), so a wildcard origin cannot be used to forge or
  steal a session the way it could for cookie-based auth. Matches the framework's own
  unpublished default (`vendor/laravel/framework/config/cors.php`) rather than inventing a
  stricter policy for these specific reads.
- **`RETENTION_ROUTES` as a 2-entry hardcoded table, not a registry-driven lookup.** Mirrors
  `TierChangeController`'s own "just two lines that grow by one each time a resource gets
  its backward edge" reasoning (plan 09's OQ-M) — worth promoting to something
  registry-driven once a third resource has an engine, not before.
- **Only `www`'s `registry.ts` factory was wired with `membersBaseUrl`.** The other eight
  apps' own `checkpointHelper/static/registry.ts` factories (and their `.env` files) were
  left untouched — see "What's left" below. The synced `alloc_v2.ts` file works identically
  in all nine; only the one call site that actually constructs a client with
  `membersBaseUrl` set exists today.

## Verification performed

- `php -l` on every new/changed PHP file — clean.
- New test suites — `StorageStateTest` (3: plan/allowance/per-band state including a band
  the endpoint's own opportunistic reconcile retires live, a band already retired before
  the request, retention-block presence) and `EmailStateTest` (3: same shape, one domain) —
  all pass.
- `members`'s full test suite (`php artisan test`, no path filter) — 70 passed, 0 failures
  (up from 64 before this pass's first commit in this session — the 6 new tests, no
  regressions in the 64 that already existed).
- `www`'s full test suite — 573 passed, 11 failed. Same 11 pre-existing, unrelated
  `Appointments`/`Builder`/`Ecommerce`/`Events`/`Forms` environment-domain failures plans
  09/10 and the member-management pass all already found.
- `npx tsc --noEmit` against `www`'s own `tsconfig.json` — 0 errors, project-wide, both
  before and after this pass's edits.
- `npx tsc --noEmit` against a temporary copy of `members`'s `tsconfig.json` with its
  broken `ignoreDeprecations: "6.0"` value removed (same pre-existing issue plan 10's
  completion doc already found and worked around) — the synced `alloc_v2.ts` copy compiles
  cleanly under `members`'s **stricter** settings (`exactOptionalPropertyTypes`,
  `noUncheckedIndexedAccess`) too, not just `www`'s looser ones.
- `npm run build` (Vite, `www`) — succeeded, exit 0, twice (once before the `registry.ts`
  wiring, once after) — `public/build` regenerated both times.
- `packages/afsserver/bin/sync-checkpoint-client.sh --check` — exits 0 across all nine
  apps after `--write`; a byte-diff of `auth`'s synced copy against `www`'s source
  confirmed identical.
- **Not** exercised against the live vagrant-lab database or in a real browser — same
  limitation as every other completion doc this session produced against this
  environment. The CORS configuration specifically has never been proven against a real
  cross-origin browser request (only reasoned about from `HandleCors`'s source and
  Laravel's own default config) — worth a real cross-origin `fetch()` test from another
  app's origin once the lab is reachable, before relying on this in production.

## What's left, explicitly

- **The other eight apps' own factory functions** (`checkpointHelper/static/registry.ts`,
  per `www/resources/js/registry.ts`'s own comment naming that as the path the other six —
  now seven — use) do not pass `membersBaseUrl`, and none of their `.env` files have
  `VITE_MEMBERS_URL` defined. `state()`/`preview()` will throw
  `ALLOC_V2_MEMBERS_BASE_URL_MISSING` if called from any of them today. The SDK method
  exists and works everywhere; the per-app wiring to actually use it does not, beyond
  `www`.
- **`prod`'s `MEMBERS_URL` (`http://members.test`) looks like a leftover dev placeholder**,
  not a real production value — found while adding `VITE_MEMBERS_URL` alongside it, not
  something this pass caused or fixed. Flagged, not touched.
- **`items()`/`move()`** — the "choose what to keep" half of the facade — remain
  unspecified beyond `03-api-contracts.md`'s original stub. No chooser UI exists anywhere
  in the estate to call them yet.
- **A live cross-origin browser call** is the one piece of this pass not verified
  end-to-end — see "Verification performed" above.
