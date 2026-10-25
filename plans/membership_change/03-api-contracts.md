# 03 — API Contracts

> **STUB, FOR AUDIT — partially built as of 2026-09-23.** Shapes are proposals; `{base}`
> stands in until OQ-A settles whether the forward edge moves too. **§3.1's client-module
> location corrected 2026-09-21, then its *name* corrected again 2026-09-23** — see the
> notes below. **`state()`/`preview()` are real now** — folded into `CheckpointAllocV2`
> per §3.1's correction, for the two resources with a retention engine
> (`storage_files_size_kb`, `domain_email_accounts`) — see
> [complete/03-api-contracts-alloc_v2-fold.complete.md](complete/03-api-contracts-alloc_v2-fold.complete.md).
> `canUse`/`consume`/`release`/`items`/`move` and every other resourceId remain exactly as
> specified below: a contract, not yet an implementation.

## 3.1 One API, two edges — **location corrected 2026-09-21, name corrected 2026-09-23**

> **Original text (kept for the record — was wrong):** "The client surface is
> `window.Portal.membership`, at `www/resources/js/external/membership/`, registered in
> `external/main.ts` like every other externals module."
>
> That location is `www`-only. It was never a reasoned decision — nothing in this document,
> or in 00/02 that it builds on, considers or rejects any alternative — and it contradicts
> [06-registry-distribution.md](06-registry-distribution.md), a **prerequisite** for this
> plan, which documents that `resources/js/checkpoint/` is the established distribution point
> for exactly this class of registry-driven client code, copied into **nine** apps: `auth`,
> `coordinator`, `dream_graph`, `flow`, `notes`, `pdf_editor`, `market`, `members`, `www`. The
> forward edge (`alloc_v2.ts`) already lives there and is already available to all nine.
> `secondary-users.ts` — a per-resource-family module for resource #28, the same shape of
> thing `Portal.membership` is — is direct, existing precedent for building this kind of
> feature *inside* `checkpoint/`, not in one app's `external/` folder.
>
> Built as originally written, `pdf_editor`, `dream_graph`, `notes`, `flow`, `market`,
> `coordinator`, and `members` — every app that already carries `checkpoint/` for its own
> resources' `canUse`/`consume`/`release` — would have had **no access** to `state()`/
> `preview()`/`items()`/`move()` for those same resources. `pdf_editor_documents_count` and
> `pdf_editor_document_pages` belong in `pdf_editor`'s own UI, not proxied through `www`.

**Corrected 2026-09-21 (location):** the client surface lives in `checkpoint/membership.ts`,
exported from each app's `checkpoint/index.ts`, and distributed the same way `alloc_v2.ts`
already is today.

One caveat this correction does **not** paper over: per [06](06-registry-distribution.md), the
registry *JSON* is now resolved at runtime (no longer bundled per app), but the `checkpoint/*.ts`
**source files themselves are still separate, manually-kept-in-sync copies per app** — not a
shared package. `membership.ts` inherits that existing, already-accepted distribution cost; it
does not create a new one, but it also does not solve the old one. `06`'s own OQ-K has since
settled that this stays copy-per-app, synced by
`packages/afsserver/bin/sync-checkpoint-client.sh` — see
[06-registry-distribution.md](06-registry-distribution.md)'s 2026-09-23 addendum. No separate
decision is needed here any more; the file (if built as its own file at all — see the next
correction) joins that script's existing sync list.

> **Corrected again 2026-09-23 (name): `window.Portal.membership` was never the right name,
> and the "corrected" text above still had it wrong** — checked against the actual code, not
> assumed:
>
> 1. **`window.Portal` already exists, is already shipped, and means something else
>    entirely.** `www/resources/js/external/api/main.ts` builds it today —
>    `notifications`, `feedback`, `subscribers`, `appointments`, `forms`,
>    `directPayments`, `commerce`, `events`, `processors`, `listings`, `mailHandler`,
>    `siteUsers`, `brain`, `documents` — the SDK a **member's own tenant website** embeds to
>    call these APIs from third-party/embedded contexts. It is bundled and served
>    *independently* (`webpack.config.cjs` in `resources/js/external/`) for exactly that
>    reason — `AGENTS.md` §4 warns explicitly against coupling any other consuming file to
>    that source tree, because it pulls a second copy into a different build graph. Attaching
>    `.membership` to `window.Portal` from a *second*, separately-bundled source
>    (`checkpoint/membership.ts`) is not a hypothetical collision — `window.Portal = Object.
>    assign(Portal, window.Portal || {})` in `main.ts` means whichever bundle runs second on
>    a page loading both wins, silently, depending on script order.
> 2. **The precedent this section cited for the name was itself wrong.** `secondary-users.ts`
>    was named as "direct, existing precedent" for building `Portal.membership` — but
>    `secondary-users.ts` isn't on `window.Portal` either. Checked directly: every real
>    consumer of `checkpoint/alloc_v2.ts` (`brain_membership.ts`, `directpayments.ts`,
>    `email.ts`, `registry.ts`, `FormsViewsController.ts`, and others) reaches it by a plain
>    ES module import — `import { CheckpointAllocV2 } from '@/checkpoint/alloc_v2'` — never
>    through any `window.*` global. `registry.ts` instantiates `CheckpointAllocV2` and stores
>    the instance on the app's own context object (`app.window.registryManager`), not on the
>    browser's global `window`. There is no `window.Portal.alloc_v2` anywhere in this estate,
>    and never has been. The actual, verified convention for `checkpoint/*.ts` is: **a plain
>    exported class or set of functions, consumed by direct import** — no global namespace
>    at all, `Portal` or otherwise.
>
> **Corrected shape:** drop `window.Portal.membership` entirely. The interface below is a
> plain exported class (`MembershipClient`, matching `CheckpointAllocV2`'s own naming style),
> imported directly by whatever page needs it — `import { MembershipClient } from
> '@/checkpoint/membership'`.
>
> **Leaning toward folding this into `CheckpointAllocV2` itself, rather than a sibling
> file, though not yet decided.** `alloc_v2.ts` already owns the forward edge
> (`canUse`/`consume`/`release`) for every resource this facade would cover, and the
> retention engine's own `previewChange()` (built in [10-preview-endpoint.md](10-preview-endpoint.md))
> already exists server-side for `preview()` to call. A `.state()`/`.preview()`/`.items()`/
> `.move()` added as methods on `CheckpointAllocV2` gives one class, one import, one file
> already on the sync script's list — instead of a tenth file for nine repos to keep in step,
> for a facade whose entire reason for existing (§3.1's own framing) is that it shouldn't
> matter to the caller which edge they're calling. Splitting the two edges into two files
> reintroduces exactly the seam the facade exists to hide. If a separate file is preferred
> for size or ownership reasons instead, it should still be a plain export following
> `secondary-users.ts`'s *actual* shape (a class, imported directly), not the `window.Portal`
> shape this section specified until today.

It is a **facade over both edges**, so a consumer learns one vocabulary:

```
MembershipClient  // or: new methods on CheckpointAllocV2 — see correction above
  .plan(scope?)                    → { plan, plan_label, account_status }
  .resources()                     → registry keys + their retention policy

  .resource(key, scope)            → ResourceHandle

ResourceHandle
  ── forward edge ────────────────────────────────────────────────
  .canUse(amount?)                 → { allowed, band, reason }
  .consume(amount?)                → verdict
  .release(amount?)                → verdict
  ── backward edge ───────────────────────────────────────────────
  .state()                         → bands, allowance, retention block
  .preview({ toPlan })             → what a change WOULD do, incl. cascade
  .items({ band? })              → the "choose what to keep" list
  .move({ ids, toBand })         → per-item result
  .reconcile()                     → force the check now
```

`canUse` gains something it cannot express today: **which band** the item would land in.
That is the cascade routing from `storage`, generalised — and it is why merging the edges
is worth doing rather than bolting retirement alongside.

`scope` is `{ type, key }` — `{ type: 'per_domain', key: 'acme.com' }`. The seven scope
types come from `conventions.scope_graph`; the client does not hardcode them.

**Identity is never client-supplied.** The caller is resolved from the Checkpoint bearer;
a `member_ref` in the request is a hint checked against it, `403` on mismatch. Retirement
additionally reads the **tier** server-side (D7) — the advisory tier `alloc_v2` accepts from
callers must never reach the backward edge.

## 3.2 Server endpoints

| Method | Path | Returns |
|---|---|---|
| `GET` | `{base}/plan` | plan, label, account status |
| `GET` | `{base}/resources` | registry keys + retention policy |
| `GET` | `{base}/{resource}/state` | bands, allowance, retention block; reconciles opportunistically |
| `GET` | `{base}/{resource}/preview?to_plan=N` | consequence incl. **cascade subtree** |
| `GET` | `{base}/{resource}/items?band=N` | items for the chooser |
| `POST` | `{base}/{resource}/canUse` | `{ allowed, band, reason }` |
| `POST` | `{base}/{resource}/consume` | verdict |
| `POST` | `{base}/{resource}/release` | verdict |
| `POST` | `{base}/{resource}/move` | `{ moved, rejected[] }` |
| `POST` | `{base}/{resource}/reconcile` | fresh state |

An unregistered `{resource}` is **404**, never an empty state — a typo must not read as
"you have nothing".

### Error codes

| Code | HTTP | Meaning |
|---|---|---|
| `BAND_NOT_OWNED` | 403 | Target band above the holder's plan |
| `BAND_RETIRED` | 423 | Target retired/purging — cannot receive |
| `BAND_FULL` | 413 | No room; carries `upgrade_unlocks` |
| `ITEM_TOO_LARGE` | 413 | `unit_kind: continuous` only — fits no single band |
| `SUSPENDED` | 423 | `account_status = 2`; writes and moves frozen |
| `REGISTRY_UNAVAILABLE` | 503 | Fail closed |
| `RESOURCE_UNKNOWN` | 404 | Not in the registry |

## 3.3 The shapes that matter

**State** — plan, allowance (computed via `tier_mode`), `bands[]`, and a `retention` block
that is `null` when everything is active.

**Retention block** — `affected_bands[]`, `purge_after`, `items_at_risk`,
`amount_at_risk`, and critically:

- **`can_evacuate`** — whether the holder can actually rescue anything
- **`evacuation_headroom`** — how much room exists in kept bands

> `can_evacuate: false` is what stops a UI offering advice the member cannot follow.
> cloud-storage §6.6 learned this: "move your data" while the remaining band is full is
> worse than saying nothing.

**Preview** — `from_plan`, `to_plan`, `direction`, `bands_retired[]`,
`bands_restored[]`, `items_affected`, `amount_affected`, `purge_after`, `can_evacuate`,
`messages[]`, and — new in this rewrite — **`cascade[]`**: the full subtree of child
resources and instances that would retire with it.

**MoveResult** — `moved[]` and `rejected[]`, each rejection carrying a code. Partial success
is normal and never reported as plain success.

## 3.4 `preview()` has a second consumer

[../billing/08-brain-enforcement.md](../billing/08-brain-enforcement.md) §8.8's
`dunning_final` email carries a hand-maintained "what you lose" list, which drifts the
moment a resource is added.

**That list should be generated from an aggregated `preview(toPlan)` across all resources.**
One computation, two consumers — the dashboard and the email. It is also what a cancellation
screen should call.

## 3.5 Open questions

- **OQ-A** — does the forward edge actually move here, or does `canUse` keep going to Parse
  directly? Moving it makes both edges consistent and kills the drift; it also puts a local
  query where there is currently one Parse call.
- **OQ-G** — `{base}`: one **server** prefix proxied per resource, or one per owning app?
  (Narrowed 2026-09-21 to make clear this is about the HTTP base path only — it is a separate
  question from *which repo's client SDK* implements the facade, which §3.1 now settles:
  `checkpoint/`, distributed to all nine apps, regardless of how `{base}` resolves.)
- **OQ-H** — `state()` reconciling as a side effect of a `GET`. Storage already does this and
  it is load-bearing, but a `GET` with a write deserves a deliberate decision.
- **OQ-I** — does the API ever *apply* a plan change, or only observe one? This stub assumes
  **observe only**: billing changes the tier, this reacts.
- **OQ-J** — added 2026-09-21, from the §3.1 correction. **Resolved 2026-09-23, not by this
  document.** [06-registry-distribution.md](06-registry-distribution.md)'s own OQ-K settled
  it estate-wide: `checkpoint/*.ts` stays copy-per-app, synced by
  `packages/afsserver/bin/sync-checkpoint-client.sh` (fixed and verified working that same
  day). `membership.ts`, if built as its own file, is one more entry on that script's
  existing `SYNC_FILES` list — no separate decision needed here.
- **OQ-K** — added 2026-09-23, from §3.1's second correction. **Resolved 2026-09-23,
  same day, by implementation.** `state()`/`preview()` were added directly to
  `CheckpointAllocV2` (`checkpoint/alloc_v2.ts`), not a separate `membership.ts` — see
  [complete/03-api-contracts-alloc_v2-fold.complete.md](complete/03-api-contracts-alloc_v2-fold.complete.md).
  `items()`/`move()` remain unbuilt; when they are, the same class is where they belong,
  for the same reason.
