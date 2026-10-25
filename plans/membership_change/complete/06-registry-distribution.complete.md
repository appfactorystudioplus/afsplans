# 06 — Registry Distribution and Resolution — Complete

## Status

Found, on starting this document, that **almost the entire migration sequence (§6.5) was
already built** — not by this plan's own tracking (no prior completion doc existed, and the
document itself still carried its original "STUB, FOR AUDIT... nothing below is built"
banner), but as an evidently-necessary side effect of other work across the estate (the
coordinator/auth-connect allocation rewrite, the storage-retention engine, and
`plans/alloc-v2-parse-direct/`, all of which cite this document by its old name,
`08-registry-distribution.md`, as the source of the "one file" decision). This pass audited
that claim against the actual code rather than trusting either the doc's banner or the
comments citing it, found one genuine remaining gap plus a **second, worse instance of the
same drift problem** this document exists to prevent, and fixed both.

## What was already done (found, not built, by this pass)

Verified directly against the code in every repo named in §6.2, not assumed from the plan text:

| Step | §6.5 requirement | Evidence |
|---|---|---|
| 1 | v4 published at the canonical public path | `w/auth/public/checkpoint/checkpoint_registry_v4.json` exists, 35 resources, `"version": 4` (int — **OQ-N resolved**, was `"3.0.0"` string) |
| 2 | `RegistryClient.php` → v4 | `w/members/app/Services/Storage/RegistryClient.php:70` fetches `checkpoint_registry_v4.json` over HTTP, cached 300s, **throws** (never returns a default) when unreachable with no cached copy — the exact §6.6 "unknown, never zero" requirement, already correctly implemented for the one retention engine that exists (storage) |
| 3 | `ParseAllocationGateway.php` config key → v4 | `w/coordinator/app/Services/AfsConnect/Allocation/ParseAllocationGateway.php:343` reads `config('afs_connect.checkpoint_registry_url')`, no more `resource_path()` disk read; cached 300s with an 86400s last-known-good fallback (**OQ-L**, in practice: a 5-minute refresh with a 24-hour degraded-fallback window, not a literal "1-hour kill switch" concern the OQ raised) |
| 4 | Dead v3 fallbacks removed | `w/auth/app/Services/Checkpoint/AllocationGateway.php:249` and `w/auth/app/Http/Controllers/Checkpoint/AllocationV2Controller.php:463` both default to `checkpoint_registry_v4.json`, not v3 |
| 5 | 8 bundled imports → `registryUrl` | All 9 (`www` + `auth`, `coordinator`, `dream_graph`, `flow`, `notes`, `pdf_editor`, `market`, `members`) `checkpointHelper/static/registry.ts` / `www/resources/js/registry.ts` factories pass `registryUrl: import.meta.env.VITE_CHECKPOINT_REGISTRY_URL` to `CheckpointAllocV2`. No bundled JSON import remains anywhere |
| 7 | 10 copies deleted | Confirmed zero `checkpoint_registry*.json` files outside `auth/public/checkpoint/` in any of the 9 app repos |

**§6.6's requirement** ("registry unreachable → deny new allocation, retire nothing, never
read as zero") is satisfied for the one retention engine actually built
(`RegistryClient::tiers()` throws; see table above). `ParseAllocationGateway` is an
*allocation* gateway, not a retention sweep, and its documented fail-closed posture (empty
map → `enabled: false` → deny) is the **correct, different** rule for allocation — denying a
quota grant on an outage is recoverable, which is exactly why §6.6 only requires the stronger
"unknown" distinction for something that *deletes data* on a zero reading.

**Not found and not built** — `checkpoint.allocation.registry_path`'s cross-origin
reachability (**OQ-J**) could not be verified from this session: this environment's Bash and
built-in browser cannot reach `*.appfactory.test1` (same limitation noted in every other
completion doc this session produced against the vagrant lab — connection refused / navigation
denied). Confirm CORS headers on the auth host's public `/checkpoint/` path permit a
cross-origin `GET` from every app origin before relying on this in a browser context that
hasn't already proven it works (`ParseAllocationGateway`/`RegistryClient` are both server-side
PHP `Http::get()`, which CORS does not gate at all — only a JS `fetch()` from `alloc_v2.ts` in
a browser would hit this, and no test in this session exercised that path live).

## What this pass built — step 6, and a drift it wasn't looking for

### The stated gap: `RegistryResourceIds` (`www/resources/js/registry.ts`)

Confirmed the audit's finding still held, and had gotten worse: registry v4 now has 35
resources (was 34 at audit time — `communications`, new, added by
`video_communications/05-feature-tiers-and-entitlements.md` §2-3). `RegistryResourceIds` had
28 entries: the phantom `domain_ecommerce` (still there, still not a real registry key) plus
27 real ones, missing 8: `afs_connect`, `communications`, `domain_chat`, `domain_discussions`,
`domain_feedback`, `domain_listings`, `domain_sponsorships`, `secondary_users`.

Its only two real consumers — `www/resources/js/portal/admin/main.ts` and
`.../views/email.ts` — used exactly two keys (`DOMAIN_EMAIL_ACCOUNTS`,
`DOMAIN_EMAIL_SENDS_MONTHLY`), both of which already exist, correctly, on a **second**
hand-maintained map in the same repo: `AllocV2Resources`
(`www/resources/js/checkpoint/resources.ts`), built later by
`plans/www/builder/07-module-handoffs.md` §7.9 P4 specifically to fix this exact class of
drift, and the map `tests/check-modules.mjs`'s build gate already checks module registrations
against. Rather than patch `RegistryResourceIds`'s drift in place (fixing a stale duplicate of
a fix that already exists), both call sites were repointed at `AllocV2Resources` and
`RegistryResourceIds` was deleted outright — one hand-maintained resource map in `www`, not
two.

### The gap nothing had flagged: `checkpoint/resources.ts` is copy-per-app too, and had already drifted

`AllocV2Resources` is not `www`-only. Like `alloc_v2.ts` (**OQ-K**'s subject), it is a
9-copy file: `checkpointHelper`-style consumers in `auth`, `coordinator`, `dream_graph`,
`flow`, `notes`, `pdf_editor`, `market`, `members` each hold their own
`resources/js/checkpoint/resources.ts`. Comparing all nine: `www`'s copy was ahead —
it alone had the five access-flag resources (`domain_listings`, `domain_feedback`,
`domain_discussions`, `domain_chat`, `domain_sponsorships`) that 07-module-handoffs.md §7.9 P4
added. The other eight were byte-identical **to each other** but stale relative to `www`,
missing all five. All nine were missing `communications`.

This is precisely the failure mode §6.1–§6.3 describe for the JSON registry itself, playing
out one level up in the hand-maintained TypeScript mirror of it: nine copies that agreed with
each other yesterday, drifted the moment one of them was updated and the other eight weren't,
and nothing detected it because `tests/check-modules.mjs` only runs in `www`'s own build and
only checks `www`'s own copy against `www`'s own module registrations — it has no way to see
the other eight exist.

**Fixed**: synced all eight non-`www` copies to match `www`'s five-key access-flag block, then
added `communications` to all nine copies (`www` included). All nine are now byte-identical
except for a short comment (`www`'s note is written as "this is where it was added"; the
other eight's as "this copy had drifted, synced from www"). Verified with a
key-list diff across all nine after the edit — identical.

**Not fixed, and explicitly out of scope per §6.7's own framing**: the underlying
copy-per-app shape of `resources.ts` (and `alloc_v2.ts`) itself. §6.7's OQ-K already asks
"does the 9-copy `alloc_v2.ts` SDK collapse to one published package, or stay copy-per-app?"
and marks it out of scope for this document. `resources.ts` is the identical class of
problem, one file smaller, discovered by this pass rather than the original audit — worth
folding into whatever resolves OQ-K, not solving separately here. Until then, the same
drift this pass just fixed can recur the next time either file changes in only one of the
nine checkouts.

## Files changed

| File | Repo | Change |
|---|---|---|
| `resources/js/checkpoint/resources.ts` | www | Added `COMMUNICATIONS: "communications"` |
| `resources/js/checkpoint/resources.ts` | auth, coordinator, dream_graph, flow, notes, pdf_editor, market, members | Added the 5-key access-flag block (`DOMAIN_LISTINGS`/`FEEDBACK`/`DISCUSSIONS`/`CHAT`/`SPONSORSHIPS`) to match `www`, plus `COMMUNICATIONS` |
| `resources/js/registry.ts` | www | Deleted `RegistryResourceIds` (dead after the two call-site changes below); left a marker comment explaining why |
| `resources/js/portal/admin/main.ts` | www | Import + 3 call sites: `RegistryResourceIds` → `AllocV2Resources` (from `@/checkpoint/resources`) |
| `resources/js/portal/admin/views/email.ts` | www | Import + 5 call sites: same substitution |

No PHP, no migrations, no config in this pass — every backend piece of §6.5 was already done.

## Verification performed

- `npm run check:modules` (`www`) — module registry build gate, which reads `AllocV2Resources`
  directly — passes: `10 module(s) declared`.
- `npx tsc --noEmit` (`www`, full project) — exit 0, no errors. Confirms both call-site
  rewrites compile and nothing else referenced the deleted `RegistryResourceIds`.
- `node --check` on all 9 `resources.ts` copies — all syntactically valid.
- Key-list diff (registry-value strings only, comments excluded) across all 9 `resources.ts`
  copies — identical, 35/35 matching `checkpoint_registry_v4.json`'s current resource set.
- Grep for `RegistryResourceIds` across `www` — zero remaining references outside the
  explanatory comment left in its place.
- **Not run**: any build of the 8 non-`www` apps (`npm run build` in each) — these are
  independent git repos this session did not otherwise touch, `node --check` and the
  key-list diff are static-correctness substitutes for a full per-app build, which was not
  performed. No dev server was started for any app, per standing instruction.
- **Not verified**: OQ-J (CORS) — see "Not found and not built" above; this environment
  cannot reach the vagrant lab's `*.appfactory.test1` hosts (Bash: connection refused;
  built-in browser: navigation denied), the same gap noted in every other completion doc
  from this session that needed a live service.

## Open questions from §6.7 — status

| # | Question | Status |
|---|---|---|
| OQ-J | CORS on the auth host's public checkpoint path | **Unresolved** — could not be checked from this environment (see above). The only consumer this actually gates is a browser `fetch()` from `alloc_v2.ts`; the PHP consumers (`ParseAllocationGateway`, `RegistryClient`) are server-to-server and unaffected by it |
| OQ-K | Does the 9-copy `alloc_v2.ts` SDK collapse to one package? | **Still open, and now demonstrably more urgent** — `resources.ts`'s independent drift (found by this pass) is a second, live instance of exactly the risk an unresolved OQ-K leaves standing |
| OQ-L | Cache TTL for the fetched registry | **De facto resolved, not decided** — `ParseAllocationGateway`: 300s refresh / 86400s stale-fallback; `RegistryClient`: 300s / throws (no fallback). Nobody chose these as an answer to OQ-L; they are what got built. Worth an explicit decision if a genuine "flip a flag and see it everywhere in under N minutes" requirement ever gets stated |
| OQ-M | Is a bundled fallback copy kept? | **Resolved: no.** Zero copies exist anywhere outside `auth/public/checkpoint/`. `ParseAllocationGateway`'s 24-hour last-known-good cache entry is the closest thing to a "fallback," and it is a cache of the fetched file, not a shipped one |
| OQ-N | `version` field type | **Resolved.** `checkpoint_registry_v4.json`'s `version` is `4` (int) |

## Addendum 2026-09-23 — OQ-K resolved

Investigated as its own follow-up. Short answer: **stays copy-per-app**, and the decision was
already made and already had a working implementation — `packages/afsserver/bin/sync-checkpoint-client.sh`,
built per `plans/alloc-v2-parse-direct/04-distribution-and-parity.md` §4.2, which explicitly chose
"Option A" (a sync script) over "Option B" (a published `@appfactory/checkpoint-client` package)
for exactly this: `alloc_v2.ts`, `resources.ts` and `app-names.ts`, with a real package left as
"the eventual destination, not required by this refactor." This pass did not re-litigate that
call — it confirmed the reasoning still holds (nine independent git repos, no shared build) and
checked whether the mechanism *implementing* it actually works.

**It didn't.** `sync-checkpoint-client.sh`'s `APPS_ROOT` computation (`three levels up from
packages/afsserver/bin`) assumed that path lives inside `w/`, matching a comment in the script
itself ("this script lives at w/packages/afsserver/bin"). In this checkout `packages/afsserver`
is a **sibling** of `w/` under `afs-eco/`, not nested inside it, so `APPS_ROOT` resolved to
`afs-eco/` and the script aborted immediately (`source not found: afs-eco/www/resources/js/checkpoint`).
`TARGETS` separately still carried a stale `apps/` prefix on five entries that has never matched
this checkout's flat `w/<repo>` layout (`w/AGENTS.md` §2).

This is almost certainly the direct cause of this same document's earlier finding: eight of
nine `checkpoint/resources.ts` copies had silently fallen behind `www`'s by five keys, plus a
ninth (`communications`) missing everywhere — the exact class of drift this script exists to
catch, undetected because the script could not reach its own source directory to check anything
at all. A broken check that can't run is worse than a red one: red is visible, and this produced
no signal either way.

**Fixed**: `APPS_ROOT` now resolves to `w/`; `TARGETS` lost its `apps/` prefix. Ran
`--check` (found only the expected comment-text difference on `resources.ts`, from this
session's earlier hand-edit — content already matched), then `--write` to reconcile
through the canonical tool rather than leave nine hand-written comments. `--check` now exits
0 across all nine apps, `alloc_v2.ts`, `resources.ts` and `app-names.ts` alike, plus its
"no bundled registry" and "no `managerUrl`" tripwires.

**Not done**: wiring `--check` into CI (`plans/alloc-v2-parse-direct/04` §4.4 already names this
as the real gap, separate from the checks themselves) — no CI configuration of any kind exists
in any of the nine app repos today, and choosing a platform is its own decision, not a
continuation of this one. Also not done: auditing `.env`/`.env.example` presence of the three
`VITE_CHECKPOINT_*` vars estate-wide (§4.3 point 2) beyond a spot check — `auth` alone is
missing `VITE_CHECKPOINT_REGISTRY_URL`, correctly, since it never actually constructs
`CheckpointAllocV2` for its own use (its `alloc_v2.ts` copy is the synced SDK file, not a
consumer); the other eight all carry all three.

Files changed: `packages/afsserver/bin/sync-checkpoint-client.sh` (path fix, comment update),
`plans/alloc-v2-parse-direct/04-distribution-and-parity.md` (status banner + §4.4 checklist
item 1), this document's own OQ-K row. Plus the mechanical effect of running `--write`:
all eight non-`www` `resources.ts` copies now carry `www`'s own comment text verbatim instead
of this session's earlier per-file "synced from www" note — no content change, since the key
set already matched.

## Follow-ups

- ~~Fold `resources.ts`'s copy-per-app drift into OQ-K's resolution.~~ **Done** — see the
  2026-09-23 addendum below. `resources.ts` was already inside the sync script's `SYNC_FILES`
  list; the script just couldn't run.
- **A parity check that compares `resources.ts` to the live registry directly** — the gap
  `tests/check-modules.mjs`'s own comment already names ("what it still cannot see: a drift
  between `resources.ts` and the registry JSON itself") and which is exactly how this pass's
  `communications`-across-nine-copies gap went undetected. Needs either a fixture copy of the
  registry checked into `www`'s test tree (reintroducing one, deliberately-stale copy for
  test purposes only) or a live fetch in CI — a design question, not a one-line fix, and
  worth its own decision rather than folding into this note.
- **OQ-J** — confirm in the vagrant lab or a real deployment, not from this environment.
  Until confirmed, treat a browser-side `registryUrl` fetch as unverified in a cross-origin
  context even though every server-side consumer is already proven to work.
- This document's own banner (**"STUB, FOR AUDIT... nothing below is built"**) is now
  actively misleading and should be updated to point here rather than corrected in place
  line-by-line, matching how [03-api-contracts.md](../03-api-contracts.md) handles a
  same-document correction.
