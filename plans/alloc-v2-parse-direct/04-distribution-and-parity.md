# 04 — Distribution & Parity Across the Nine Clients

> ### ⚠ Status — 2026-09-23 (resolving OQ-K, [membership_change/06](../membership_change/06-registry-distribution.md) §6.7)
>
> **The sync script was broken again, the same way, and had been for a while.**
> `sync-checkpoint-client.sh` computed `APPS_ROOT` three levels up from its own location —
> correct arithmetic for `w/packages/afsserver/bin`, but this checkout has `packages/afsserver`
> as a **sibling** of `w/` under `afs-eco/`, not nested inside it, so `APPS_ROOT` resolved to
> `afs-eco/` and `SRC` pointed at a `www` that doesn't exist there. `TARGETS` also still carried
> an `apps/` prefix on five entries (`apps/coordinator`, etc.) that has never matched this
> checkout's flat layout (`w/AGENTS.md` §2: all ten repos sit directly under `w/`). Both are
> fixed — `APPS_ROOT` now resolves to `w/`, `TARGETS` is unprefixed.
>
> **This is very likely why `06-registry-distribution.md`'s completion doc found what it
> found**: eight of the nine `checkpoint/resources.ts` copies had silently fallen five keys
> behind `www`'s, plus the newest registry addition (`communications`) missing from all nine —
> exactly the class of drift this script exists to catch, undetected because the script
> couldn't reach its own source directory. `--check` now runs, found that exact drift (as a
> comment-text difference once the key content was already hand-reconciled), and `--write`
> reconciled it properly through the canonical tool rather than leaving nine hand-edited
> comments. `--check` now exits 0 across all nine apps.
>
> **OQ-K's answer, confirmed rather than re-decided**: this document already chose Option A
> (a synced-copy script) over Option B (a published `@appfactory/checkpoint-client` package)
> for `alloc_v2.ts`, `resources.ts` and `app-names.ts` — "not required by this refactor" — and
> that choice still holds. A package remains **the eventual destination**, not the current
> answer; nothing found this pass changes that recommendation, but the enforcement mechanism
> for the current answer needed to actually work, which as of 2026-08-31 it evidently did not.
> **Not done**: wiring `--check` into CI. No `.github/workflows` (or any other CI config) exists
> in any of the nine app repos today — this is a bigger, separate decision (which CI platform,
> against which git host) than a script fix, and is left as the next real gap per §4.4.
>
> ### ⚠ Status — 2026-08-31
>
> **This document is still accurate and still the right procedure.** Two notes:
>
> - The count is **nine** client copies and **eight** factories (`www` keeps its factory at
>   `resources/js/registry.ts`; the other seven use
>   `resources/js/checkpointHelper/static/registry.ts`). Verified again after the
>   `MemberRegistry` removal touched every one of them.
> - Parity was re-verified on 2026-08-31: all nine `alloc_v2.ts` copies are byte-identical.
>   The factories are **not** byte-identical to each other and are not expected to be — they
>   differ by app, which is why §4.3 lists what each app edits outside the synced directory.
>
> The rollout order in §4.5 is superseded by [03](03-parse-cloud-functions.md) §3.8.

`resources/js/checkpoint/` is duplicated in **www, members, market, auth** and **apps/{coordinator, dream_graph, notes, pdf_editor, flow}** — nine copies, meant to be identical. This document covers getting the refactor into all of them and keeping them from drifting again.

> **Updated 2026-08-27.** The drift this document was written to reconcile is gone, and the sync script it proposed has been built. §4.1 and §4.2 are rewritten against what is on disk. What has **not** gone away is the drift *problem* — it moved to a different file and now points the wrong way (§4.1).

---

## 4.1 Current drift — measured, not assumed

**`alloc_v2.ts` is clean.** All nine copies are 1525 lines with one identical hash (modulo line endings). The 752-vs-754 `statusCode` delta described below has been reconciled and www is no longer the odd one out; it is kept for the record because it is what motivated §4.2.

**`app-names.ts` had five distinct versions.** Not two — the `MARKET` count alone understated it:

| Version | Copies | Carried |
|---|---|---|
| 83 lines | members | `MARKET`, `APPFACTORY_MOBILE`, **the security docblock** |
| 58 lines | coordinator, dream_graph, notes, pdf_editor, flow | `MARKET`, no `APPFACTORY_MOBILE`, no docblock |
| 56 lines | market | `MARKET` only, no trailing comma |
| 56 lines | auth | no `MARKET`; **plus `appfactory: APPFACTORY_STUDIO`** in the secondary-user UI map |
| 55 lines | **www — the sync source** | none of the above |

**✅ Fixed 2026-08-27:** www now carries the 83-line version. The drift direction is inverted back — www is the superset, so a write pass now *raises* every target instead of stripping seven of them.

The load-bearing part was not `MARKET`. It was the **docblock on `UI_APP_ID_TO_CHECKPOINT`** that www had no copy of: the one recording that `APPFACTORY_STUDIO`, `APPFACTORY_MOBILE` and `MEMBERS` are deliberately absent as a **security control** (checkpoint-server-auth plan 10 §1, with a vitest regression test in `members/resources/js/checkpoint/app-names.test.ts`). A sync from the old www would have deleted that comment in eight repos, leaving the omission looking like an oversight for the next person to "complete".

> ### ⚠ Two findings this surfaced, neither fixed here
>
> **1. `MARKET` is not registered in the identity provider.** `auth/app/Services/Checkpoint/CheckpointAppName.php` has no `MARKET` constant and `all()` does not list it, so `isValid('MARKET')` returns **false** — while market really does sign in with it (`checkpointHelper/app.ts`, `dashboard.ts`, `auth-session.ts`, `settings/config.ts`). Either add it to the PHP or remove it from the TS; it cannot stay in only one. This is the same open question as F3 in [../TODOS.md](../TODOS.md) — market is not on the shared validator package, so nothing enforces `app_name` today and the mismatch is silent.
>
> **2. auth's copy grants `appfactory: APPFACTORY_STUDIO`** in `UI_APP_ID_TO_CHECKPOINT` — the entry the docblock above says must stay absent — and exports it through `checkpoint/index.ts`. Layer 2 (`CheckpointAppName::PRIMARY_ONLY_APPS`) refuses the grant server-side so it cannot actually grant access, but it is exactly the "broken option in front of a user" the comment warns about. Syncing from www removes it.

This is the same class of failure the script exists to prevent, which is the point: a duplicated file set is only as good as the check that runs over it, and the check has to actually run (§4.4).

### 4.1.2 `--check` has false positives and is not CI-ready

Run today it reports drift in `alloc_v2.ts` for **coordinator** and in `resources.ts` for **all eight** targets. Neither is real:

- coordinator's `alloc_v2.ts` is **CRLF** where www's is LF. Content is byte-identical otherwise — all nine copies match under `tr -d '\r'`.
- `resources.ts` differs by **trailing whitespace on line 3** (`*  ` vs `* `).

The script's `diff` normalises neither. Wire it into CI as-is and it is red on day one, permanently — and a check that is always red is a check nobody reads, which is how its own breakage survived in the first place. **Normalise line endings and trailing whitespace in the comparison before making it a gate** (`diff -b` plus a `tr -d '\r'`, or a `.gitattributes` `text=auto` pass across the nine directories).

### 4.1.1 The original finding, for the record

| App | `alloc_v2.ts` | Other |
|---|---|---|
| members, coordinator, dream_graph, notes, pdf_editor, flow | 754 lines, one identical hash | complete file set |
| **www** | **752 lines, different hash** | **missing `app-names.ts`** |

The www delta:

```ts
// six apps — safe under TS strict / exactOptionalPropertyTypes
if (statusCode !== undefined) {
    this.statusCode = statusCode;
}

// www
this.statusCode = statusCode;
```

The six-app form is the correct one: assigning `undefined` to an optional property is an error under `exactOptionalPropertyTypes`, so www's copy is the version that would break first under stricter settings.

The rule it produced still holds and is now the script's rule: **www is the source of record, and a fix belongs in www.** Repairing a target and syncing from www undoes the repair.

## 4.2 One source, eight targets — the script exists

The nine directories are copies with no shared package, so "keep them in sync" was a manual promise. **It no longer is:** `packages/afsserver/bin/sync-checkpoint-client.sh` is built and working.

```
SRC     = www/resources/js/checkpoint          ← source of record
TARGETS = members market auth apps/{coordinator,dream_graph,notes,pdf_editor,flow}

  bash packages/afsserver/bin/sync-checkpoint-client.sh            # write
  bash packages/afsserver/bin/sync-checkpoint-client.sh --check    # verify, non-zero on drift (CI)
```

Three things about the real script differ from the sketch this section used to carry, and each matters:

1. **It copies a named `SYNC_FILES` list, not the whole directory.** Adding a file to the synced set means editing the script, not just dropping the file into www.
2. **It carries an inverted registry guard.** `checkpoint_registry_v*.json` used to be in the sync list, and the script parity-checked the eight bundled copies against auth's. That is over — every consumer resolves the registry at runtime now ([03](03-parse-cloud-functions.md) §3.8.1) — so the check was flipped: **finding a bundled registry anywhere is now the failure**, and the script says so in its own output.
3. **It was broken for a long time and silently did nothing.** `APPS_ROOT` resolved one directory short, so `SRC` pointed at a path that does not exist and it aborted before any real work; `TARGETS` was stale too, naming the five creative apps at top level after they had moved under `apps/`, and never listing `market`. Both are fixed. **This is very likely why anything drifted at all** — the guard against drift was itself inert, and nothing reported that either.

**A real package** (`@appfactory/checkpoint-client`, which `CHECKPOINT.md` already advertises as installable) remains the eventual destination, tracked as **OQ-K** in [../membership_change/06-registry-distribution.md](../membership_change/06-registry-distribution.md) §6.7. It needs a publish pipeline, version pinning, and nine `package.json` edits. Not required by this refactor.

<details>
<summary>The sketch this section originally proposed, superseded by the above</summary>

**Option A — a sync script (recommended).** One directory is the source (`apps/www/resources/js/checkpoint`, since it is the client's home app), plus a script that copies it into the other six and a CI check that fails when they differ.

```bash
#!/usr/bin/env bash
# scripts/sync-checkpoint-client.sh
#
# Copies the canonical checkpoint TypeScript client into every consuming app.
#
# The client is duplicated rather than packaged because these apps have no
# shared build. That is workable, but ONLY if the copies are mechanically
# synced: the drift this script exists to prevent is exactly what was found
# before the alloc_v2 refactor — www's copy had diverged by one statement and
# was missing app-names.ts, and nothing anywhere reported it.
#
# Usage:  ./scripts/sync-checkpoint-client.sh          # write
#         ./scripts/sync-checkpoint-client.sh --check  # verify only (CI)
set -euo pipefail

SRC="apps/www/resources/js/checkpoint"
TARGETS=(members coordinator dream_graph notes pdf_editor flow)

for app in "${TARGETS[@]}"; do
    dest="apps/${app}/resources/js/checkpoint"
    if [[ "${1:-}" == "--check" ]]; then
        diff -r "$SRC" "$dest" || {
            echo "checkpoint client drift in ${app} — run scripts/sync-checkpoint-client.sh"
            exit 1
        }
    else
        rsync -a --delete "$SRC/" "$dest/"
    fi
done
```

**Option B — a real package** (`@appfactory/checkpoint-client`, which `CHECKPOINT.md` already advertises as installable). Correct long-term, and a bigger change: a publish pipeline, version pinning per app, and seven `package.json` edits. Not required by this refactor; note it as the eventual destination.

</details>

**Add the `--check` invocation to CI before distributing the refactor**, so the nine copies are provably identical at the moment they change. **Nothing runs it in any pipeline today** — that is the gap that let both the `app-names.ts` drift and the script's own breakage go unreported.

## 4.3 What each app must edit outside the synced directory

The synced directory is copied verbatim. Three things sit outside it and are per-app:

**1. The construction site.** **All eight web apps now construct `CheckpointAllocV2`** — `www` at `resources/js/registry.ts`, the other seven at `resources/js/checkpointHelper/static/registry.ts`. The transport half of the change is already in every one of them: `applicationId`, `serverURL`, `registryUrl`, no `managerUrl`.

| Field | State |
|---|---|
| `managerUrl` | ✅ gone everywhere |
| `applicationId` / `serverURL` | ✅ present everywhere |
| `registryUrl` | ✅ present everywhere — the registry is fetched, not bundled |
| `tier` | **stays.** Still `Number(_app.window.member?.membership ?? 0)`, still caller-supplied ([02](02-client-refactor.md)'s status banner) |

> **`www`'s factory is at a different path from the other seven** (`resources/js/registry.ts`, not `checkpointHelper/static/registry.ts`), and it is outside the synced file set either way. Any estate-wide factory change finds seven in one place and misses the eighth — recorded as **G10** in [../allocation.md](../allocation.md) §12.3.

**2. The Vite env vars.** `VITE_CHECKPOINT_APP_ID`, `VITE_CHECKPOINT_SERVER_URL` and **`VITE_CHECKPOINT_REGISTRY_URL`** are read in each app's factory and `checkpointHelper/static/globalAuth.ts`. All three are set in all eight `.env` files today, every `VITE_CHECKPOINT_REGISTRY_URL` resolving `…/checkpoint/checkpoint_registry_v4.json`. Confirm they are in every `.env.example` too — a missing value used to degrade quietly and is now a hard failure at the first allocation call.

**3. `resources.ts`.** Present in all nine copies. It is inside the synced set, so it stays consistent for free — but it must be regenerated whenever `checkpoint_registry_v4.json` gains a resource, or client code references ids that resolve to nothing.

```ts
/**
 * Generated from checkpoint_registry_v4.json — do not hand-edit.
 *
 * Every constant here must exist as a key under `resources` in that file.
 * A drifted id is not a type error (both sides are strings); it surfaces at
 * runtime as an unknown resource, which is why the parity check in §4.4
 * compares this map against the registry directly.
 */
export const AllocV2Resources = { … } as const;
```

> Registry v4 carries resources v3 did not — notes, notebooks, DreamGraph graphs, PDF documents, design projects, secondary users, `afs_connect` ([../handoff.md](../handoff.md) §6). The both-directions parity check in §4.4 is what surfaces any that never got a constant.

## 4.4 Parity checks for CI

**None of these run in a pipeline today.** That is the gap, not the checks themselves.

```
1. Client sync          — packages/afsserver/bin/sync-checkpoint-client.sh --check
                          NOTE: passing again as of 2026-09-23, but was RED for an
                          unknown period before that for a reason unrelated to any
                          app's own code — the script's own path resolution broke
                          (see the 2026-09-23 status banner above), which is worse
                          than a red check: a check that can't run reports nothing,
                          not failure, so this sat silently uninformative rather
                          than visibly red. Confirm it still runs before trusting
                          this note.
2. Resource-id parity   — every value in AllocV2Resources exists in
                          checkpoint_registry_v4.json.resources, and every
                          registry resource has a constant. Both directions:
                          a missing constant is a feature nobody can meter.
3. No managerUrl        — grep the nine directories for `managerUrl`; it must
                          appear nowhere. Currently passing.
4. No bundled registry  — no checkpoint_registry_v*.json under any app's
                          resources/js/checkpoint/. The sync script already
                          enforces this (§4.2); keep it enforced.
5. Env presence         — VITE_CHECKPOINT_APP_ID, VITE_CHECKPOINT_SERVER_URL
                          and VITE_CHECKPOINT_REGISTRY_URL in every .env.example.
6. No direct counter    — after 03 §3.8 step 5, no `object.save()` against
   writes                 MemberRegistry anywhere in alloc_v2.ts, and no
                          /classes/MemberRegistry write in coordinator's
                          ParseAllocationGateway.php.
```

Checks 3, 4 and 6 are worth keeping permanently: each is a tripwire against reintroducing exactly the thing that was just removed — a per-app transport, a bundled registry, a client-authored counter.

## 4.5 Rollout order

> **Superseded.** The authoritative sequence is [03](03-parse-cloud-functions.md) §3.8, which is written for the state the estate is actually in. The order below assumed the Parse side would land before the client; the reverse happened, so the client-side steps here are done and the Parse-side ones are not.

1. ~~**Parse side deployed** — schema, CLPs, Cloud Functions.~~ **Not done.** `cloud/functions.ts` is still the stock scaffold.
2. **Verify CLPs are actually closed** from a browser console. parse-server *merges* schema changes, so a previously permissive class can survive a redeploy. If a client-side write still succeeds, stop — the rest depends on this. **Still the right check, still unperformed.**
3. **Drift reconciled** (§4.1) and the sync check green. **Outstanding — `app-names.ts`.**
4. ~~**Refactor in `apps/www`**~~ ✅ done for transport; the `consume`/`release` half is 03 §3.8 step 5.
5. ~~**Distribute**~~ ✅ done — all nine copies identical.
6. ~~**Factory updated**~~ ✅ done in all eight.
7. **Retire** the `AllocationV2Manager` references in both docblocks and the `/alloc/v2` proxy routes. `managerUrl` is already gone; the routes are live orphans still accepting a caller-supplied `tier` (03 §3.8 step 6).

## 4.6 What this unlocks

Beyond removing a broken transport: a desktop or mobile client can now use allocation with **nothing but an Application ID, a URL, and a Checkpoint token**. That is the precondition for metering anything outside the seven web apps — including the Joystick game engine, whose Checkpoint C++ client already carries an `Alloc` module (`JoystickEngine_v2/plan/steps/61_afs_connect_engine.md` §61.2.1) that has no usable server contract until this lands.
