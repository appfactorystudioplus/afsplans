# 00 — Extras (off-plan adjustments)

## What this file is

The numbered `complete/` files ([03](03-api-contracts.complete.md),
[03 fold](03-api-contracts-alloc_v2-fold.complete.md),
[06](06-registry-distribution.complete.md), [07](07-signup-flow.complete.md),
[08](08-member-management.complete.md),
[08 allocations](08-member-management-allocations.complete.md),
[09](09-billing-tier-change-hook.complete.md),
[10](10-preview-endpoint.complete.md)) are what the membership_change plan
**became**. This file is everything added or fixed that **was not a plan
step** — things that would otherwise live only in `../../../handoff.md` and
then get re-discovered once that file has moved on.

It does **not** supersede the numbered completes for "how membership_change
works today." This file is the authority for the work that sat **beside**
those steps.

**How to add to it.** Append a new `E*` section (next number, dated). Keep
the same shape: symptom/context, cause, what changed, the files, and the
operational consequence. Do not fold an extra into a numbered complete
unless the plan itself changed.

---

## Index

| # | Date | What | Why it is not in the plan |
|---|---|---|---|
| [E1](#e1--resolvelimit-absent-vs-zero-b8) | 2026-09-23 | `resolveLimit()` throws on an absent registry entry instead of resolving `{limit:0, enabled:false}` | `05-rollout-and-testing.md` §5.2 lists B8 as a standalone Phase-1 bug fix, not a numbered plan step with its own file |
| [E2](#e2--checkpoint-becomes-a-real-published-package) | 2026-09-23 | `resources/js/checkpoint/` collapses from 9 hand-synced copies to one published package, `@appfactory/checkpoint-client` | Not a resource, not a bug fix against this plan's own findings — infrastructure the owner asked for directly, reversing `06-registry-distribution.md`'s same-day OQ-K resolution |

---

## E1 — `resolveLimit()` absent-vs-zero (B8)

**Date.** 2026-09-23. **Repo:** `www` (source of record), synced to `members`,
`market`, `auth`, `coordinator`, `dream_graph`, `notes`, `pdf_editor`, `flow`
via `packages/afsserver/bin/sync-checkpoint-client.sh`.

**Context.** `05-rollout-and-testing.md` §5.2 B8 / `02-architecture.md` §2.9:
`CheckpointAllocV2`'s private `resolveLimit()` (`resources/js/checkpoint/alloc_v2.ts`)
folded two different facts into the identical shape. A `resourceId` entirely
absent from the loaded registry, and a resource genuinely entitled to a
zero-value tier, both resolved to `{ limit: 0, enabled: false, period:
'lifetime', overage: 'block', warnAtPct: 0 }`. A caller — and specifically
the retention engine `02-architecture.md` §2.9 requires, which cannot be
implemented until this distinction exists — could not tell "the registry
doesn't know about this resource/tier" from "this member is on a plan that
gets zero of it." The method's own docblock defended this as intentional
("matching the PHP controller, where a missing registry row yields `0.0`"),
which was itself part of the bug: the fail-closed rule in §2.9 requires
absent to deny, not to act as a legitimate zero. `06-registry-distribution.md`
§6.6 additionally called out the concrete symptom: because a missing entry
produced `enabled: false`, a server misconfiguration reached the member as
an upsell ("Upgrade to add team members") rather than as an error.

Whole-registry-unreachable was already handled correctly one layer up —
`loadRegistry()` throws `ALLOC_V2_REGISTRY_NOT_FOUND` on a failed fetch or a
JSON body with no `resources` map, before `resolveLimit()` is ever reached.
The bug was specifically the per-`resourceId` / per-tier-index case inside an
otherwise successfully loaded registry.

**What changed.** `resolveLimit()` (`alloc_v2.ts`, private method) now throws
`AllocV2Error` with a new code, `ALLOC_V2_RESOURCE_UNKNOWN` (404), in the two
cases that used to fall through to a fabricated zero:

- `registry.resources[resourceId]` is `undefined` (resource not in the
  registry at all).
- `definition.tiers[tier]` is `undefined` (resource is registered, but has no
  entry at this specific tier index).

The `config.bypass` short-circuit is unaffected and still checked first — an
explicit escape hatch that lifts every ceiling, including for a resource the
registry doesn't know about, so it deliberately never throws. Once bypass is
ruled out, a resolved entry's `value`/`enabled` are read directly (no longer
defensively defaulted with `?? 0` / `?? false`), since the two cases that
required the defensive default now throw before reaching that code. Two
`@throws` docblocks (`canUse()`, `consume()`) were updated to name the new
code; `get()`/`getAll()` already documented a generic `@throws AllocV2Error`
and were left as-is.

`getAll()`'s internal loop (`metricsFor()`) iterates
`Object.entries(registry.resources)`, so `resourceId` there is always a key
that came from the registry itself — the "resource entirely absent" branch
cannot trigger from that call site, only the "no entry at this tier index"
branch could (a malformed/short `tiers` array on an otherwise-known
resource), which is the correct behaviour to fail loud on rather than show a
silently-wrong dashboard number.

The free-standing exported `resolveLimit()` function (line ~2151,
`members_brain`'s override resolution — a different signature, takes an
already-resolved `AllocV2RegistryResource` directly rather than looking one
up by id) was **not** touched; B8 as named in `05-rollout-and-testing.md`
refers specifically to the private class method, and the exported function's
caller has already resolved the resource object by the time it's called, so
the "resource entirely absent from the registry" case doesn't apply to it.

**Test.** New `resources/js/checkpoint/tests/alloc_v2.test.ts` (`www`), run
via new `npm run test:checkpoint` script (added to `package.json`, matching
the existing `test:builder` / `test:external-ui` / `test:external-api`
pattern — Node's built-in test runner with the shared
`portal/builder/tests/register.mjs` extensionless-import hook). Calls the
private `resolveLimit()` directly (a pure lookup against the supplied
registry object, no Parse access of its own) rather than through
`get()`/`canUse()`, since reaching it through a public method that resolves
*successfully* would need a live Parse server for the usage read that
follows — not what this bug is about. Four cases: a resource genuinely
entitled to zero still resolves normally; an unknown `resourceId` throws
`ALLOC_V2_RESOURCE_UNKNOWN`; a known resource with no entry at the given tier
throws the same way; bypass still lifts the ceiling for an unknown resource.
All four pass. `npx tsc --noEmit` clean on `www`.

**Files.** `www/resources/js/checkpoint/alloc_v2.ts`,
`www/resources/js/checkpoint/tests/alloc_v2.test.ts` (new),
`www/package.json` (`test:checkpoint` script) — then synced byte-identical
(verified via `sync-checkpoint-client.sh --check`) into
`{members,market,auth,coordinator,dream_graph,notes,pdf_editor,flow}/resources/js/checkpoint/alloc_v2.ts`.

**Ops.** No migration, no config. Nothing currently calls `canUse` / `get` /
`getAll` with a `resourceId` that's genuinely absent from
`checkpoint_registry_v4.json` under normal operation, so this should be a
no-op in practice — it only changes behaviour when the registry is missing
an entry it should have, which is exactly the misconfiguration this was
supposed to surface instead of hide. Any app that calls `CheckpointAllocV2`
methods should have a check for `ALLOC_V2_RESOURCE_UNKNOWN` alongside its
existing `ALLOC_V2_TIER_DISABLED` handling if it wants to show a distinct
message for "not entitled" vs. "this shouldn't happen" — none currently do,
since the code did not exist before this change.

---

## E2 — Checkpoint becomes a real published package

**Date.** 2026-09-23. **Repos:** all ten (`www`, `members`, `market`, `auth`,
`coordinator`, `dream_graph`, `flow`, `notes`, `pdf_editor`, plus a new
eleventh repo, `checkpoint-client`), and `packages/afsserver` for the
registry infrastructure and this record.

**Context.** Asked directly by the owner to turn `resources/js/checkpoint/`
into a private local npm package, importable by every app and kept properly
synced — reversing, the same day, `06-registry-distribution.md`'s own OQ-K
resolution ("stays copy-per-app, via `sync-checkpoint-client.sh`"). That
script only ever kept 3 files identical across apps (`alloc_v2.ts`,
`resources.ts`, `app-names.ts`); everything else in the directory had been
independently forked per app for a while, undetected because nothing
compared the other files.

**Registry: `packages.npm.appfactory.studio`, self-hosted Verdaccio.**
Chosen over a git-dependency or `file:` link after establishing the actual
deploy model: the vagrant lab never runs its own `npm install` (
`provision/deploy-app.sh` symlinks the already-built `w/<app>` tree from the
host), but production deploys are manual — upload files, run `npm install`
by hand — which makes a directory-layout-dependent `file:` link fragile and
makes a registry actually reachable at install time the right shape,
without a directory-layout assumption a git dependency would also have
avoided but a self-hosted registry that the owner already controls end to
end makes moot. Config, `docker-compose.yaml` (bind-mounted storage at
`/mnt/node2/appfactorystudio/verdaccio`, matching this estate's own
`/mnt/*`-not-a-named-volume convention), and an nginx vhost live in
`packages/afsserver/docker/verdaccio/` — see that directory's own README
for how it's actually run. `@appfactory/*` is scoped to authenticated
publish only (`max_users: -1` disables open self-registration); everything
else proxies through to the real npm registry.

**The package: `@appfactory/checkpoint-client`, a new repo
(`github.com/appfactorystudioplus/checkpoint-client`).** Ships raw
TypeScript source (no compile step for the bulk of it) — every consumer is
already a Vite app compiling this exact code today, so shipping `.ts`
directly reproduces current behaviour exactly rather than introducing a
second build pipeline. One exception: `resources.json`, generated from
`resources.ts` by `scripts/generate-resources-json.mjs` via
`prepublishOnly` — found necessary because Node's native
`--experimental-strip-types` refuses to run on anything inside
`node_modules` (`ERR_UNSUPPORTED_NODE_MODULES_TYPE_STRIPPING`), which broke
`www`'s `check-modules.mjs` build gate (a plain Node script, not
Vite-bundled) the moment `resources.ts` moved out of local source. Rather
than hand-duplicate the resource-id map as a second, driftable copy — the
exact class of bug this whole package exists to stop — the JSON is derived
from the real source at publish time and gitignored.

**`members` turned out to be the correct source, not `www`, for most of the
directory.** The first publish (`1.0.0`) used `www`'s copy of every file,
on the reasoning that `www` is `sync-checkpoint-client.sh`'s `SRC`. A
size/diff comparison across all 9 apps' `checkpoint/` directories (prompted
by the user's own instinct that `members` was likely more current) found
`www` was actually *behind* on `client-handler.ts` (missing a cross-app
SSO logout-watch feature and member-record hydration entirely) and
`index.ts` (missing `app-names.ts` integration), and that `types.ts` /
`secondary-users.ts` carried a real documented bug (`TwoFactorService`
response-shape mismatch, `qr_code_uri` vs. the real `qr_code_url`) and a
missing forced-2FA-enrolment feature that 6 of 9 apps still had. `members`
matched a 6–8 app consensus on every file and was ahead, not just common.
Republished as `1.0.1` from `members`, with `www`'s own two genuine
non-conflicting additions (`auth.ts`'s `applyExternalSession()`, today's
signup-flow work; `alloc.ts`'s `Parse.initialize()` call) merged in rather
than discarded.

**Two more files surfaced only by directory-listing, not diffing.** `1.0.2`
found `www`'s `checkpoint/index.ts` was still wired as a dead Vite build
entry point (nothing loaded it via `@vite()`) — removed. `1.0.3` found
`members` and `auth` each had two files — `app-urls.ts`
(`checkpoint-server-auth` plan 11's secondary-user launcher URLs) and
`app-names.test.ts` (plan 10's security regression test) — that existed in
neither `www` nor the other 7 apps at all, so no earlier size/diff pass
against `www` could have found them. `app-names.test.ts` used `vitest`;
ported to `node:test`/`node:assert` to match this package's existing
harness rather than add a second test framework as a dependency. `1.0.4`
widened the `accesscontrol` peer range to `^2.2.1 || ^3.0.0` after
`pdf_editor` (the one app on v3) hit an `ERESOLVE` — `authz.ts` is
byte-identical across every app and untouched by this migration, so
`pdf_editor` was already running it against v3 successfully before the
package existed; the peer range was simply narrower than reality.

**Wiring all 9 apps.** Each got a `.npmrc`
(`@appfactory:registry=https://packages.npm.appfactory.studio/` — 3 apps,
`flow`/`notes`/`pdf_editor`, already had one for unrelated settings and
got the line appended, not overwritten), the dependency added to
`package.json`, every `checkpoint/*` import specifier (`@/checkpoint/...`,
relative paths at every depth, `.ts`/`.tsx`) rewritten to
`@appfactory/checkpoint-client[/subpath]`, the local `resources/js/checkpoint/`
directory deleted, and — per app — a full `npm run build` plus whatever
test suite exists (`vitest`, `node --test`, or a `tsc`-gated build) run to
verify. `checkpointHelper/`-style directories (a separate, genuinely
per-app layer that *consumes* the checkpoint client rather than being it)
were left in place everywhere, but needed their own import fixes too — an
early pass on `members` excluded them by mistake, which broke its
`checkpointHelper/*` files' module resolution until caught by a full `tsc`
run. `auth`'s `_obsolete_checkpoint/` (a full archived copy, already
disused, nothing importing it) was left untouched. All 9 apps' full
verification passed; the handful of `tsc` errors found in `members` and
elsewhere were confirmed pre-existing and unrelated (unchanged call sites,
unchanged file content, previously invisible only because affected files
either had unrelated broken imports masking them as `any`, or because
`members`' own `tsconfig.json` has a separate, already-documented
`ignoreDeprecations` bug that blocks a clean `tsc` run entirely without a
workaround).

**`sync-checkpoint-client.sh` deleted.** Nothing left for it to sync — every
app now installs the package instead of carrying a local copy.
`plans/SYNC-CHECKPOINT-SCRIPTS.md` (the script's own explainer) and
`06-registry-distribution.md`'s OQ-K row were updated in place to record
the retirement and point here, rather than left describing a script that
no longer exists.

**Files.** New repo `checkpoint-client` (`package.json`, `tsconfig.json`,
14 `.ts` source files, `app-urls.ts`, `resources.json` generation script,
`tests/`). `packages/afsserver/docker/verdaccio/` (new: `config.yaml`,
`docker-compose.yaml`, nginx vhost, `README.md`). Every app repo:
`.npmrc`, `package.json`, every file that imported from `checkpoint/`
(dozens across the estate), `resources/js/checkpoint/` deleted.
`www/vite.config.js` (dead entry point removed),
`www/resources/js/portal/builder/tests/check-modules.mjs` (import
+ explanatory comment). `packages/afsserver/bin/sync-checkpoint-client.sh`
deleted. `packages/afsserver/plans/SYNC-CHECKPOINT-SCRIPTS.md`,
`membership_change/06-registry-distribution.md` (OQ-K + banner) updated.

**Ops.** Publisher account creation, DNS, and TLS for
`packages.npm.appfactory.studio` are the owner's own actions (no access
from this environment) — see `packages/afsserver/docker/verdaccio/README.md`
for the exact steps; all were completed live during this session, including
working around a real Verdaccio storage-permission gap (bind-mounted
directory owned by `root`, container runs as non-root — fixed with a
`chmod -R 777` on the mount, pragmatic over precise given the directory is
Docker/Verdaccio-only on a non-public box) and a missing `htpasswd` binary
in the official Verdaccio image (installed `httpd-tools` on the host and
wrote the file directly against the bind mount instead of `docker exec`).
Any future app added to this estate needs the same `.npmrc` line and
dependency — there is no longer a script to remind anyone of that, so it's
worth checking this record rather than assuming a new app inherits it
automatically.

## E3 — `laravel_queue_jobs` had no migration (2026-09-24)

**Found by** the first real lab signup (`james3`): `POST /portal/api/v1/memberships/register`
returned 500 — `Table 'testdb_appfactorystudio.laravel_queue_jobs' doesn't exist` — **after** the
`brain_memberships` row had already committed. `MembershipTierObserver::notify()` dispatches
`NotifyMembersOfTierChange` with `->afterCommit()`; the database queue driver had no table to write to.

**Cause (pre-existing, not from any plan step):** `.env` sets `DB_QUEUE_TABLE=laravel_queue_jobs`, but
the only migration creating a queue table, `0001_01_01_000002_create_jobs_table`, hard-codes `jobs`.
`laravel_queue_jobs` exists only in the old full dump `databases/full/testdb_appfactorystudio.sql`, so
any database built from migrations never had it.

**Fix:** `w/www/database/migrations/2026_09_24_000001_create_configured_queue_jobs_table.php` — creates
whatever `queue.connections.database.table` names, only if missing; `down()` deliberately a no-op.
Applied to the lab with `php artisan migrate --force`; the table now exists.

**Not fixed, worth a decision (belongs in `09-billing-tier-change-hook`'s territory):** a failed
post-commit dispatch turns an already-successful signup into a 500 for the user. Whether `store()`
should catch and log that failure is unresolved. Also, no queue worker runs in the lab, so
`NotifyMembersOfTierChange` jobs will accumulate unprocessed.

**Not run:** any test for this migration.
