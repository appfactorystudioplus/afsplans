# 09 — The Billing Tier-Change Hook

> New 2026-09-22. Extends the architecture step sequence (see `README.md`) — this is
> shared infrastructure, not tied to one resource, the same reason
> [06-registry-distribution.md](06-registry-distribution.md) is its own document rather than
> a section inside [02-architecture.md](02-architecture.md).

> **Done, 2026-09-23 — banner below is stale, see
> [complete/09-billing-tier-change-hook.complete.md](complete/09-billing-tier-change-hook.complete.md).**
> Built as designed, with one deviation the completion doc explains: §9.4's pseudocode
> sketched one `saved()` handler on `wasChanged('membership')` — building it for real found
> that misses signup entirely (`Model::performInsert()` never calls `syncChanges()`), so the
> observer uses separate `created()`/`updated()` methods instead. OQ-K/L/M all resolved as
> built.
>
> **PLANNING ONLY. No code written for this feature yet.**

## 9.1 What this closes

[00-overview.md](00-overview.md) §5's **D8** names three detection mechanisms — scheduled
sweep, opportunistic-on-read, and a "tier-change hook from billing" as the fast path
([02-architecture.md](02-architecture.md) §2.9's own table). Two of the three are built:
`StorageRetentionSweep`/`EmailRetentionSweep` (both `members`, hourly) are the sweep; the
opportunistic path exists in the storage controllers and in `EmailAccountController::index()`.
**The hook is the one piece named in the design and never built.** This document is that
design.

> **Superseded finding, 2026-09-22 — read
> [../billing/17-self-service-tier-changes.md](../billing/17-self-service-tier-changes.md)
> first.** Tracing whether a self-service upgrade takes effect immediately (the question that
> prompted this document) found that it doesn't take effect **at all** — the self-service
> "change plan" endpoint never writes `brain_memberships.membership` in either direction, a
> gap one level upstream of anything this document designs. `17` is the fix; **this document's
> observer has nothing to react to until `17`'s §17.3 ships.** Build order: `billing/17` §17.3,
> then this document.

## 9.2 What "billing" actually means here — verified against the live code, not assumed

Researched directly (2026-09-22) rather than inferred from a comment, because getting this
wrong would mean designing a hook that watches the wrong thing.

**`brain_memberships.membership` (the tier column) is written from exactly three places, all
in `www`, all through `App\Models\Membership` (a plain Eloquent model — no raw `DB::table`
writes anywhere):**

| # | Site | Trigger |
|---|---|---|
| 1 | `app/Http/Controllers/Portal/Admin/MembershipController.php` `store()` (~189) | Signup — admin-created and public registration share this endpoint; `$membershipTier` is resolved from the paid Direct Payment tier, not taken raw from the request (04-subscription-state §4.2.5/B38) |
| 2 | `app/Http/Controllers/Portal/Admin/MembershipController.php` `update()` (~303) | `PUT /portal/api/v1/memberships/{ref}` — the operator console's manual edit ([08-member-management.md](08-member-management.md)'s MM1/D5 territory) |
| 3 | `app/Services/Billing/DunningService.php` `downgrade()`/`restore()`/the cancellation-access-ended path (~273, ~319, ~414) | The billing enforcement sweep's own write, inside a `DB::transaction()` |

**`billing:enforce-membership` already exists and is already a sweep** —
`app/Console/Commands/BillingEnforceMembership.php`, scheduled
`->everyFifteenMinutes()->withoutOverlapping(14)->onOneServer()->runInBackground()`
(`routes/console.php`), reading subscription state via `DunningService`/tier resolvers and
writing site #3 above when it decides a tier changed. **This is `www`'s own fast detection of
"did billing change,"** built under `plans/billing/08-brain-enforcement.md` — a different,
already-shipped concern. **This document is not about detecting a billing change** (that's
solved, and solved deliberately as a 15-minute poll, not an event — `plans/billing/00-overview.md`
§1.2/§3 explicitly rejected a push-based hook there: *"an event that must be delivered exactly
once is not [degrade-safe]"*, mirroring this plan's own D8 reasoning almost verbatim).

**The actual gap is one hop further downstream.** Once any of the three sites above writes a
new `membership` value, `members` — which owns every resource's actual `reconcile()` — has no
way to find out except its own next hourly sweep, or a member happening to load a page that
opportunistically reconciles. A member who upgrades at 9:00am and immediately tries to use
what they just paid for can be blocked by their own stale band assignment for up to an hour.
**The hook this document designs is `www` → `members`, "this member's tier just changed,
reconcile them now" — not a Stripe webhook, not a replacement for `billing:enforce-membership`,
and not an event bus.**

## 9.3 Why an Eloquent observer, not per-call-site instrumentation

Three write sites, in two different classes, one of them a service with three separate write
branches inside one transaction. Asking each call site to remember to also fire a
notification is the exact pattern this plan has already found broken twice this session —
`AfsConnectSettingsController`'s hand-kept source-key list (finding A,
[25-storage_files_size_kb.complete.md](features/complete/25-storage_files_size_kb.complete.md)
F11's neighbor) and the `AFS_ALLOC_EMAIL` marker convention that was specified but never
actually carried out until asked for by name. A fourth write site added later — another
admin action, another billing-service branch — silently wouldn't fire the hook, and nothing
would fail loudly enough to notice.

**An Eloquent model observer on `App\Models\Membership`, watching `wasChanged('membership')`
on `saved`, catches all three sites structurally** — it fires because `save()` happened, not
because a call site remembered to ask. This is the same reasoning `VerifyCheckpointToken`
existing as route-group middleware (not a per-controller call) already applies inside
`members`, just on the `www` side instead.

**Must dispatch after the enclosing transaction commits, not from inside `saved`'s own
synchronous firing.** `DunningService`'s three writes happen inside `DB::transaction(...)`;
Eloquent's `saved` event fires as part of `save()`, before that transaction commits. An
observer that queues the hook job synchronously would fire it even if the transaction later
rolled back — reconciling a tier change that never actually happened. Laravel's job dispatch
supports exactly this case (`->afterCommit()`, or the queue connection's own
`after_commit` config) and the two existing queued-job patterns already in this estate
(`SendAfsConnectIngestJob`, `DeliverMemberEvent` — both `ShouldQueue`, both wrapping a signed
HTTP call to one fixed downstream app, `tries=3`) are the template to copy, not invent.

## 9.4 The mechanism

```
www: Membership::saved() -> wasChanged('membership')?
       -> NotifyMembersOfTierChange::dispatch($ref, $before, $after)->afterCommit()
            (ShouldQueue, database queue — matching the estate's existing convention)
       -> job: signed POST to members' api/internal/www/v1/tier-changed
            (same www.internal / VerifyWwwInternalSignature route group and middleware
            already built for the email reconcile channel — see
            features/complete/2-domain_email_accounts.complete.md)

members: TierChangeController::handle()
       -> loops the registered reconcile callbacks for this member
            (today: RetentionService::reconcile() [storage], EmailRetentionService::reconcile()
            [email] — every future resource's backward edge registers here the same way)
       -> each reconcile() call already fails closed / logs and skips independently
            (F1, F8 — per-holder isolation already exists, reused as-is)
```

**Idempotency is free, not designed.** `reconcile()` for every resource built so far
recomputes state from the *current* `membership` value read fresh each call — it is not
applied as a delta against a "before" value. Firing the hook twice, out of order, or for a
tier change that's already stale by the time it arrives produces the same correct result as
firing it once, on time. This is the same property that already lets the hourly sweep and the
opportunistic-on-read path coexist without conflicting (§2.9's "two authoritative sweeps
reading the same column degrade safely").

**Failure is safe by design, not by exception-handling effort.** If `members` is unreachable,
the queued job's own retry/backoff exhausts and the member is reconciled at most an hour late
by the existing sweep — exactly D8's own stated tolerance ("a missed hook must not mean a
member keeps what they stopped paying for"). No dead-letter handling, no manual replay UI, no
new failure mode: the hook can only ever make reconciliation *faster*, never *the only* path,
matching every fire-and-forget cross-repo call already built this plan
(`MembersEmailClient`, `CheckpointAllocClient`).

## 9.5 What this does NOT do

- **Does not replace `billing:enforce-membership`.** That command remains `www`'s own
  mechanism for deciding a tier *should* change, at its existing 15-minute cadence. This hook
  only fires after that decision is already written to `membership`.
- **Does not touch `alloc_v2`'s own tier.** `alloc_v2`'s tier is client-supplied and advisory
  by design (D7 in `00-overview.md`) — a separate, already-settled forward-edge concern.
- **Does not become a general event bus.** One trigger (`membership` changed), one downstream
  (`members`), one payload shape. If a second app ever needs the same notification, it gets
  its own queued job and its own signed route, matching how `SendAfsConnectIngestJob` and
  `DeliverMemberEvent` are two separate single-purpose jobs rather than one generic publisher.
- **Does not replace the "force reconcile" operator action** already scoped in
  [08-member-management.md](08-member-management.md) (line ~170) — that's a manual,
  audited, on-demand trigger for a specific member; this hook is automatic and unconditional
  on every write.

## 9.6 Open questions

- **OQ-K — does `update()`'s manual admin edit deserve the same hook as a billing-driven
  change, or should `tier_source: manual` (already returned by
  `plans/billing/09-entitlement-integration.md` §9.3's entitlement endpoint) suppress it?**
  An operator granting a temporary tier bump presumably *wants* it applied immediately, same
  as a paid upgrade — leaning toward "no distinction," but not yet decided.
- **OQ-L — what does `TierChangeController` do with a `member_reference_num` that resolves to
  no known holdings in `members` at all** (a brand-new signup, tier written before any
  resource has ever been touched)? Likely a silent no-op — nothing to reconcile — but should
  be an explicit case, not an accidental fallthrough, given F1's whole lesson was that
  "nothing found" and "something's wrong" must never look the same.
- **The registered-reconcile-callbacks list (§9.4) doesn't exist as a concept yet** — today
  it would just be two hardcoded calls. Worth deciding whether it's a real registry (config
  array, service-tag discovery) or genuinely just two lines that grow by one each time a
  resource gets its backward edge, before a third resource makes the answer matter.

## 9.7 Files to create (when this moves to implementation)

| File | Repo | Purpose |
|---|---|---|
| `app/Observers/MembershipTierObserver.php` (or similar) | www | `saved()`, checks `wasChanged('membership')`, dispatches the job `afterCommit()` |
| `app/Jobs/NotifyMembersOfTierChange.php` | www | `ShouldQueue`, signed POST to `members`, matching `SendAfsConnectIngestJob`'s retry/backoff shape |
| `routes/www_internal.php` addition | members | New route in the *existing* `www.internal` group (no new middleware needed — `VerifyWwwInternalSignature` already exists) |
| `app/Http/Controllers/Www/TierChangeController.php` | members | Receives the notification, loops registered `reconcile()` callbacks |

No new database table, no new signing mechanism, no new middleware — every piece this needs
already exists for a different purpose and is being reused, not built from scratch.
