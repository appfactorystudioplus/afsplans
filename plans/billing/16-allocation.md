# 16 — Allocation for Processors and Direct Payments

Limiting how many processors and Direct Payments a member may create, per their tier, through
`alloc_v2`.

Read [../allocation.md](../allocation.md) first — it explains the system; this document
applies it to two resources.

## 16.1 The limits already exist

Both resources are **already in** `auth/public/checkpoint/checkpoint_registry_v3.json` and
both are already declared in `checkpoint/resources.ts`. Nothing needs adding to the registry.

| Resource | Scope | Period | Overage | T0 | T1 | T2 | T3 | T4 |
|---|---|---|---|---|---|---|---|---|
| `domain_processors_count` | `per_domain` | lifetime | **block** | **0, disabled** | 1 | **3** | 10 | −1 |
| `domain_direct_payments_count` | `per_domain` | lifetime | **block** | **0, disabled** | 5 | **50** | 500 | −1 |

So the user's example — **a member on membership 2** — gets **3 processors and 50 Direct
Payments** per domain. Tier 0 has `enabled: false` on both: payments are not a free-plan
feature at all, which is a different message from a limit of zero
([../allocation.md](../allocation.md) §10).

**`per_domain`, not member.** A member with three websites gets 3 processors *each*. Every
call must pass the scope, or it reads a bucket nothing writes to.

## 16.2 What is wired today

| | `canUse` | `consume` | `release` |
|---|---|---|---|
| **Direct Payments** | ✅ `directpayments.ts:145` | ✅ `:646` | ❌ |
| **Processors** | ❌ | ❌ | ❌ |

Two findings, defined with the rest in [20](../www/direct_payments/20-current-state-audit.md) §2.9:
**processors have no allocation call sites at all** (**B42**), and **Direct Payments never
`release()`** (**B43**).

## 16.3 Processors — the three call sites

### Preflight, before the create modal opens

`Processors/ProcessorsAdminView.ts`, on the "Add Processor" handler — mirroring what Direct
Payments already does at `directpayments.ts:141`:

```ts
/**
 * AFS_ALLOC_V2_TAG
 * AFS_BILLING_MODIFIER_ADDITION — plans/billing/16-allocation.md §16.3
 *
 * Preflight against domain_processors_count before opening the create modal.
 *
 * per_domain scope, so the current subdomain's Domain property is passed —
 * a member with three websites has a separate processor allowance on each,
 * and omitting the scope reads a bucket nothing writes to.
 *
 * Gates the UI only. `consume` records the fact, after the server confirms.
 */
const domain: string = app.window.subdomain_properties
    .find((p: any) => p.PropertyName === 'Domain').PropertyValue;

allocV2.canUse(AllocV2Resources.DOMAIN_PROCESSORS_COUNT, 1, { domain })
    .then((checkResult: any) => {
        const check = checkResult.data !== undefined ? checkResult.data : checkResult;
        if (check.available) {
            showModal('processorModal');
        } else {
            showAllocationLimit(check);      // §16.5
        }
    })
    .catch((error: unknown) => {
        console.error('[AllocV2] processors canUse failed:', error);
        showAllocationLimit(null);           // fail CLOSED — §16.7
    });
```

### Consume, after the create succeeds

In `handleSave()`, after `saveProcessor()` resolves:

```ts
/**
 * AFS_ALLOC_V2_TAG
 * AFS_BILLING_MODIFIER_ADDITION — plans/billing/16-allocation.md §16.3
 *
 * Consume one processor allowance. AFTER the server confirmed the create, so
 * an abandoned or failed form never burns quota.
 *
 * Non-fatal by design: the processor exists. Failing the user's save because a
 * counter did not increment would lose real work over bookkeeping.
 *
 * Guarded on !isEditing — saving an existing processor is not a new one. The
 * legacy RegistryUpdate was unconditional and over-counted every edit.
 */
const created = await saveProcessor(payload);
processors.push(created);

if (!isEditing) {
    allocV2.consume(AllocV2Resources.DOMAIN_PROCESSORS_COUNT, 1, { domain })
        .catch((err: unknown) => {
            const code = err instanceof AllocV2Error ? err.code : 'UNKNOWN';
            console.error(`AllocV2 consume error [${code}]:`, err);
        });
}
```

`handleSave()` currently has no `isEditing` notion — creation and update share the path. It
gains one, derived from whether `payload` carries an existing id. **That flag is the fix for
the over-count bug before it exists here.**

### Release, on delete

`ProcessorDetailView.ts:402` already calls `deleteProcessor(currentProcessor.id)`:

```ts
/**
 * AFS_ALLOC_V2_TAG
 * AFS_BILLING_MODIFIER_ADDITION — plans/billing/16-allocation.md §16.3
 *
 * Return the allowance. Without this, quota is a one-way ratchet: a member at
 * their limit who deletes a processor still cannot create one (B43's shape).
 *
 * Only after the server confirms the delete, and non-fatal for the same reason
 * as consume.
 */
await deleteProcessor(currentProcessor.id);

allocV2.release(AllocV2Resources.DOMAIN_PROCESSORS_COUNT, 1, { domain })
    .catch((err: unknown) => console.error('[AllocV2] processors release failed:', err));
```

## 16.4 Direct Payments — closing the gap

`canUse` and `consume` are already correct, including the `!isEditing` guard. **Only
`release` is missing** (**B43**).

`saveDirectPayment` has no delete counterpart in the admin controller today, so the release
lands wherever the delete does — and if there is no delete UI, **the release is added in the
same pass as one**. Shipping a delete without its release recreates the ratchet.

```ts
/**
 * AFS_ALLOC_V2_TAG
 * AFS_BILLING_MODIFIER_ADDITION — plans/billing/16-allocation.md §16.4
 *
 * Closes B43. Release only what was consumed: a Direct Payment created before
 * allocation was wired, or one whose consume failed, was never counted — and
 * releasing it drives the count negative.
 */
allocV2.release(AllocV2Resources.DOMAIN_DIRECT_PAYMENTS_COUNT, 1, { domain })
```

The comment names the real trap. There is no per-row record of whether a consume succeeded, so
"release only what was consumed" is enforced by the reconciler (§16.8) rather than at the call
site — which is the honest position, and the reason the reconciler is not optional.

## 16.5 What the member sees

Two different refusals, two different messages ([../allocation.md](../allocation.md) §10):

| Case | Message |
|---|---|
| `enabled: false` at this tier (T0) | *"Payment processors aren't included on the Free plan."* + **Upgrade** |
| Limit reached (`used >= value`) | *"You're using all 3 processors included in your plan."* + **Upgrade** |
| Check failed | *"We couldn't verify your plan limits. Please try again."* — no upgrade prompt |

Direct Payments currently shows `"Not Authorized"` / `"Not authorized to create Direct
Payment"` for every case. That is accurate and useless: it tells a member they are forbidden
without saying why or what to do, and the same string appears whether they are on the wrong
plan, at their ceiling, or hitting a transient error.

`showAllocationLimit(check)` replaces it for both resources, reading `enabled`, `limit` and
`used` off the check result. **The upgrade prompt is deliberately absent from the error case**
— selling to someone whose request failed for an unrelated reason is worse than saying nothing.

## 16.6 Level 99 bypasses both limits

A member with `app.window.member.level === 99` is never blocked and never counted
([../allocation.md](../allocation.md) §6). Nothing in §16.3 or §16.4 changes: the bypass is
configured once in `createAllocV2()` (`www/resources/js/registry.ts`) and every call site
inherits it.

Two consequences worth stating where an implementer will hit them:

- **`showAllocationLimit()` never fires for staff.** `canUse` resolves `available: true`, so
  the T0 and limit-reached messages in §16.5 cannot be reproduced from a level-99 account.
  Testing them needs a real member account at the relevant tier — or a temporary local
  override of `level`. Expect this to be discovered the confusing way at least once.
- **The reconciler (§16.8) must ignore level-99 activity.** `consume` is a no-op under bypass,
  so a staff account that creates five processors on a member's domain leaves five rows and no
  counter movement. That is deliberate — it is what "not counted" means — but a reconciler
  comparing `count(*)` against Parse reads it as drift and would "correct" the member's usage
  upward for work the member never did.

The second point is the trap. **`alloc:reconcile-payments` must scope its row count to rows
the member owns**, which it does anyway by joining on the member, so the exposure is limited to
staff-created rows attributed to that member — the same rows a support action would create.
Where a support flow creates records on a member's behalf, the count is correct and the
counter is not; that is a reconciler `--fix` doing exactly its job, and the reason `--fix` is
opt-in.

The bypass is **not** an authorisation mechanism (§16.9). It grants nothing the API would not
already accept from anyone.

## 16.7 Fail closed

Both resources are `overage: block`, so a failed check **refuses**:

```ts
.catch(() => showAllocationLimit(null));   // not: proceed anyway
```

A processor is a set of live payment credentials and a Direct Payment charges real money.
"Parse was briefly unreachable" must not become free quota — and a member blocked for thirty
seconds retries, whereas quota granted in error is only discovered at reconciliation.

That is the opposite of the `consume` posture (§16.3, non-fatal), and the asymmetry is the
point: **check failures deny, record failures log.**

## 16.8 Drift, and the reconciler

Counts drift. A `consume` fails and the row exists; a `release` fires for something never
counted; a row is deleted straight from the database.

```
php artisan alloc:reconcile-payments [--dry-run] [--member=]
```

For each member, for each domain: count the real rows and compare with Parse.

| Column | Source of truth |
|---|---|
| `domain_processors_count` | `SELECT count(*) FROM laravel_processors WHERE …` |
| `domain_direct_payments_count` | `SELECT count(*) FROM laravel_direct_payments WHERE …` |

**Reports by default; corrects only with `--fix`.** A job that silently rewrites quota is a
job nobody notices is wrong, and the count it is repairing is the thing that decides whether a
member can work.

Not scheduled in this pass. A persistent non-zero drift means a call site is missing, and the
fix is the call site, not a nightly correction.

## 16.9 The server does not enforce this

Worth stating where an implementer will read it: **`canUse` runs in the browser and the tier
comes from the client.** `ProcessorAdminController::store()` and
`DirectPaymentsController::saveDirectPayment()` accept a create regardless.

That is the documented posture ([../allocation.md](../allocation.md) §7), not an oversight —
but it means allocation here is a **plan-management feature**, not a limit. A member who wants
a fourth processor on tier 2 can have one by calling the API directly.

If these limits ever need to be real, the enforcement point is the server controller, reading
a server-derived tier from the Brain entitlement endpoint
([09](09-entitlement-integration.md) §9.3) — a small addition once that endpoint exists, and
out of scope here.

The Parse side is worse than "not enforced": `MemberRegistry` has **public class-level
permissions**, so its counters are writable by anyone holding the application id, and the
client's atomic `ac_*` counter is probably falling back to a non-atomic read-modify-write
against the current `lockSchemas: true` config. Both are inventoried in
[../allocation.md](../allocation.md) §11 and designed out in
[../alloc-v2-parse-direct/](../alloc-v2-parse-direct/). **Neither blocks PA1–PA4** — wiring
call sites is the same work either way — but nothing here should be described to anyone as a
limit until that section's **G2** is closed.

## 16.10 Rollout

| Phase | Contents |
|---|---|
| **PA1 — processors** | §16.3: `canUse`, `consume` with `isEditing`, `release` (**B42**) |
| **PA2 — Direct Payments** | §16.4: `release` (**B43**) |
| **PA3 — messaging** | §16.5: `showAllocationLimit()` for both |
| **PA3b — bypass** | §16.6: `bypass` in `createAllocV2()`. One line in `registry.ts`; do it with PA1 |
| **PA4 — reconciler** | §16.8: `alloc:reconcile-payments --dry-run` |

Independent of every other phase in this plan. PA1 and PA2 are each a few call sites.

## 16.11 Acceptance

- A **tier 2** member can create **3 processors**; the 4th shows the limit message
  (§16.1).
- A **tier 2** member can create **50 Direct Payments**; the 51st is refused.
- A **tier 0** member sees *"not included on the Free plan"* — not a limit message
  (`enabled: false`).
- A **tier 4** member is unlimited (`value: -1`), and the check never blocks.
- A member with **two domains** has a separate allowance on each — the `per_domain` scope is
  actually passed (§16.1).
- **Editing** a processor or Direct Payment does **not** increment the count (§16.3).
- An abandoned create form does **not** burn quota.
- **Deleting** a processor frees the allowance immediately — verified by creating at the
  limit, deleting, creating again (**B42**, **B43**).
- A **level-99** account creates processors and Direct Payments **past every tier limit**
  without a dialog, and the member's Parse counters **do not move** (§16.6).
- A level-99 account's `get()` still reports the member's **real usage** — only the ceiling is
  lifted.
- `level` of `98` or `100` is **not** a bypass — the check is `=== 99` (§16.6).
- A failed `canUse` **blocks** and shows the error message with **no upgrade prompt** (§16.7).
- A failed `consume` **still saves** the record and logs (§16.3).
- `grep -rn "AFS_ALLOC_V2_TAG"` returns **six** call sites for these two resources — three
  each (§16.2's table, fully ticked).
- `alloc:reconcile-payments --dry-run` reports drift and writes nothing.
