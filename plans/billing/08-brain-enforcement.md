# 08 — Brain Enforcement (Part 2) — scheduled in `members`, run on the Brain

`php artisan billing:enforce-membership` — the task that makes non-payment mean something.

> **Status (2026-09-25): built, verified in the lab, off by default.** `members` schedules the command
> every fifteen minutes and triggers the enforcement on `www` over a signed server-to-server call; `www`
> runs it. Nothing downgrades anyone until three switches are on (§8.2). Details of what was built and
> checked: [`complete/08-enforcement-in-members.complete.md`](complete/08-enforcement-in-members.complete.md).
> The original build of the ladder itself: [`complete/08-brain-enforcement.complete.md`](complete/08-brain-enforcement.complete.md).

## 8.0 Where each half runs, and why

Enforcement is appfactory-studio's own task — it has no reason to exist on a tenant — so it is
**scheduled in `members`**, which runs once. The **logic runs on the Brain (`www`)**, unchanged.

| | Where | Why |
|---|---|---|
| **Schedule** (every 15 min) | `members/routes/console.php` | `www/routes/console.php` is evaluated on every tenant clone, so an entry there ran 96×/day on every tenant to do nothing. `members` is never a tenant. It already hosts the platform sweeps (`storage:*`, `email:retention-sweep`) |
| **Trigger** | `members` command `billing:enforce-membership` → `POST /api/internal/brain/billing/enforce` on `www` | One signed call per tick |
| **Ladder, tier write, audit rows, notices** | `www` — `DunningService`, `MembershipTierResolver`, `BillingMailer`, the three `brain_*` tables | Only the Brain writes `brain_*` (`w/AGENTS.md` §5), `members` has no mail sender, and the tier write, state row and event row must commit together, once per member. Keeping the logic where it is means there is one implementation to test and no second copy of about 1,200 lines of dunning logic to keep in step |
| **"Is this the Brain?"** | `config('brain.is_brain')` — the `IS_BRAIN` env flag, plus a production URL check ([`www_brain`](../www_brain/08-security-and-safety.md) §8.3) | Replaces the domain-based `BrainGuard`, which gave the wrong answer in every environment whose URL differs |

## 8.1 The two commands

**`members`** — `members/app/Console/Commands/BillingEnforceMembership.php`

```php
protected $signature = 'billing:enforce-membership
                        {--dry-run : Ask www to evaluate and report, changing nothing}
                        {--member= : Restrict to one member reference_num}
                        {--force   : Run even when BILLING_ENFORCEMENT_ENABLED is false (manual runs)}';
```

```php
Schedule::command('billing:enforce-membership')
    ->everyFifteenMinutes()
    ->withoutOverlapping(14)
    ->onOneServer()
    ->runInBackground();
```

It sends one signed request, prints the Brain's output, and exits `0` on success or when disabled,
`1` when `www` could not be reached, refused the call or reported a failure (the next tick retries).
A `409` — a previous run has not finished — is a skip, not a failure.

**`www`** — `www/app/Console/Commands/BillingEnforceMembership.php` keeps its signature
(`--dry-run`, `--member=`, `--explain`) and is **not scheduled**; it runs when the surface route below
invokes it, and can still be run by hand on the Brain. Its first statements: `config('brain.is_brain')`
must be true, then `config('billing.enforcement_enabled')` (the env kill switch), then
`brain_billing_settings.enabled`. Each returns `SUCCESS` when off — declining to run is correct, not
an error.

Fifteen minutes is far more often than a day-granularity ladder needs. It is deliberate: the
**restore** direction must be fast. A member who fixes their card should not wait until tomorrow to
get their tier back, and running the same evaluation in both directions on the same cadence means
restore has no separate code path that can rot.

**Precondition.** The schedule only fires if `members` has a working `schedule:run` cron — install it
with [`www/core/scripts/laravel-cron.sh`](../../../../w/www/core/scripts/laravel-cron.sh)
(`--install --user <user> --path <members dir>`). Without it nothing runs, which is *safe* (nobody is
downgraded) but also means **restore stops working**.

## 8.2 Kill switches and the signed surface

Three switches, all of which must be on before any member's tier can change:

| # | Switch | Where | Ships |
|---|---|---|---|
| 1 | `BILLING_ENFORCEMENT_ENABLED` | `members/.env` (read through `config/brain_surface.php`); `--force` bypasses it for a manual run | `false` in every env set |
| 2 | `IS_BRAIN` + `BILLING_ENFORCEMENT_ENABLED` | `www/.env` (`config/brain.php`, `config/billing.php`) | `IS_BRAIN=true` in the Brain's env sets; the enforcement flag defaults on |
| 3 | `brain_billing_settings.enabled` | Brain database, one row | `0` |

**The surface.** `members` → `www`, `POST /api/internal/brain/billing/enforce`, body `{dry_run?, member?}`.

- **Signature:** `HMAC-SHA256(key, "{timestamp}.{nonce}.{METHOD}.{path}.{sha256(body)}")`, headers
  `X-Brain-App` (`MEMBERS`), `X-Brain-Timestamp`, `X-Brain-Nonce`, `X-Brain-Signature`. Skew ±300 s.
  Both repositories test the same fixed vector so the signing copy in `members`
  (`App\Services\Www\BrainSurfaceSignature`) and the verifying copy in `www`
  (`App\Services\Brain\BrainSurfaceSignature`) cannot drift.
- **Keys:** `BRAIN_SURFACE_KEY_MEMBERS` (+ `_NEXT` for rotation) in `www`, `WWW_BRAIN_SURFACE_KEY` in
  `members`. Not `WWW_INTERNAL_API_KEY` / `MEMBERS_INTERNAL_API_KEY` (the opposite direction) and not
  any afs-connect key. One key per calling app, so a leaked `MEMBERS` key can only act as `MEMBERS`.
- **Middleware, in order:** `brain.only` (`404` unless `config('brain.is_brain')`, so a tenant clone
  never serves it), then `brain.surface` (known app, key configured, signature and skew, nonce not
  seen before). Any failure is a `401` that says only "invalid".
- **Overlap:** enforcement is not re-entrant. A run holds a cache lock for up to 14 minutes; a call
  that finds it held gets `409 ENFORCEMENT_ALREADY_RUNNING` and does nothing.
- **Response:** `{success, exit_code, dry_run, duration_ms, output}`; `500` with `success:false` if the
  command failed.
- **Not built into this route:** any decision. It runs the command and reports what the command
  printed. The route is the seam [account_close](../account_close/00-overview.md) also needs (a
  different route on the same group and keys).

**Failure is per run, never per member.** If `www` is unreachable the run is skipped and the next tick
tries again; nothing in `members` holds state, so nothing can drift. A run that fails part-way leaves
each member either fully transitioned or unchanged (the transaction is per member).
## 8.3 What "the tier they paid for" means

Two integers per member:

- **`tier_paid`** — what their live subscription entitles them to.
- **`tier_effective`** — what `brain_memberships.membership` currently says.

`tier_paid` is resolved in the order below. Resolution starts from the **Direct Payments**
side, because that is the layer that knows
about members and tiers. The processor layer is read through the join and is never asked a
question about a member ([22](../www/direct_payments/22-subscription-state.md) §4.2):

1. The member's `laravel_direct_payment_subscriptions` row →
   `direct_payment_tier_reference_num` → `laravel_direct_payment_tiers.level`.
   `level` **is** the 0–4 tier index. This is the direct answer, recorded at purchase.
2. If that column is null (an older row, or one adopted by the backfill), join *down* to
   `laravel_processor_subscriptions` on `provider_subscription_id`, take its `plan_id` →
   `laravel_processor_plans.provider_plan_id`, and match that against the DP's own
   `laravel_direct_payment_tiers.processor_tier_id`. Same answer, derived the long way.
3. Only rows whose joined processor status is `active` or `trialing` count (most recent
   `current_period_start` wins if there are several).
4. If nothing resolves → **`tier_paid` is unknown, and the member is skipped entirely.**

Step 1 exists precisely so the common case never needs step 2: recording the tier at
purchase time makes enforcement independent of whether a plan mapping still resolves months
later.

Point 4 is the most important line in this document. *Unknown is not zero.* A member whose
plan mapping is broken must not be downgraded because of a join failure. The skip is
counted and reported (§8.8) so the mapping gets fixed, rather than silently costing a
customer their tier.

`tier_paid` is clamped to 0–4, and it is a **positional index** — never resolved by name.
`brain_memberships` labels them `Free/Pro/Business/Organization/Enterprise` and
`checkpoint_registry_v3.json` labels them `free/starter/professional/business/enterprise`;
the labels differ and carry no contract. See
[../afs-connect/11-billing-and-allocation.md](../afs-connect/11-billing-and-allocation.md) §11.3.

## 8.4 The dunning ladder (decision **D10**)

```
  current ──payment fails──▶ past_due ──grace elapses──▶ downgraded
     ▲                          │                            │
     └──────payment succeeds────┴────────────────────────────┘
                          (restore, immediate)
```

| T | State | Action |
|---|---|---|
| **T+0** | `past_due` | Detected from `status = 'past_due'` (sync or webhook). `past_due_since = now()`. `downgrade_after = now() + 14 days`. **No tier change.** `dunning_started` notification |
| **T+3d** | `past_due` | `dunning_reminder_1` |
| **T+7d** | `past_due` | `dunning_reminder_2` |
| **T+12d** | `past_due` | `dunning_final` — states the downgrade date explicitly |
| **T+14d** | `downgraded` | `tier_before_downgrade = tier_effective` saved **first**; then `brain_memberships.membership` written to the policy target (§8.4.1); `downgraded_at = now()`; `membership_downgraded` notification |
| any time | `current` | Subscription returns to `active`/`trialing` → restore `membership = tier_paid`, clear the ladder, `membership_restored` notification |

That ladder is for **non-payment only**. A member who deliberately cancels takes a
different path — §8.4.2.

**Why 14 days and not 0.** The same argument cloud-storage makes for its 30-day storage
grace ([../cloud-storage/08-storage-retention.md](../cloud-storage/08-storage-retention.md) §6.1),
and it applies with more force here because the blast radius is wider: a tier drop
simultaneously retires storage buckets, revokes afs-connect sources, and cuts every
allocation limit. Payments fail for reasons that have nothing to do with intent — an
expired card, a bank's fraud hold, a currency issue, a billing address change. Doing all of
that within seconds of a declined renewal is a support incident caused by a payment glitch.

### The grace period must be read against the provider's own retry window

All three providers retry a failed subscription charge on their own, and the ladder above is
deliberately layered on top of that rather than competing with it. But the windows differ
enough that one global `grace_days` means three different things — the full per-provider
behaviour, and the configuration surface for it, is [27](../www/direct_payments/27-provider-retry-policy.md):

| Provider | Retry window | Configurable |
|---|---|---|
| **Stripe** | 1 week – 2 months (recommended default **2 weeks**) | Dashboard only, not by API |
| **PayPal** | ~5-day cadence, threshold-driven | **Yes**, per plan, by API |
| **Square** | **9 days**, fixed | No |

The full behaviour of each — attempt counts, terminal states, hard-decline handling and the
configuration surface — is [27](../www/direct_payments/27-provider-retry-policy.md) §13.2, and is **not restated
here**: those are the most change-prone facts in this plan and must have exactly one home.

Three consequences for this plan:

1. **14 days is not uniformly "inside" the retry window.** It sits at the edge of Stripe's
   recommended 2-week default and well past Square's 9 days. A Square member spends days
   9–14 of the grace with the provider having already given up — still recoverable by the
   member updating their card, but no longer by an automatic retry.
2. **This is an argument for the per-DP `grace_days`** in [26](../www/direct_payments/26-advanced-policy.md)
   §12.3, not against it. A Direct Payment backed by a Square processor wants a different
   default from one backed by Stripe configured for a 2-month recovery window. The value
   should be set with the backing provider's window in mind, and §12.7's UI is where an
   operator can see which processor a DP uses while choosing it.
3. **Never set `grace_days` shorter than the provider's retry window.** Downgrading a member
   while the provider is still going to retry — and may well succeed — produces a downgrade
   that reverses itself days later, with both emails already sent. Validation cannot enforce
   this — the Stripe window is not readable from the API — so it surfaces as a warning
   rather than a rejection: [27](../www/direct_payments/27-provider-retry-policy.md) §13.7 supplies the number and
   the copy, and [26](../www/direct_payments/26-advanced-policy.md) §12.5 explains why it warns instead of
   refusing.

**What this plan deliberately does not do is retry the charge itself.** All three providers
are already retrying on a schedule the operator chose; a parallel attempt from our scheduler
would risk double charges, duplicate `invoice.payment_failed` webhooks and a dunning ladder
racing the provider's. We observe the outcome — that is the [00](00-overview.md) §3
non-goal, and the provider comparison above is why.

Exposing a **manual** "retry now" to an operator is a different matter and is genuinely
useful (Stripe `POST /v1/invoices/{id}/pay`, PayPal's outstanding-balance capture); it is
not built here, and it is a per-provider surface rather than something the normalised layer
in [22](../www/direct_payments/22-subscription-state.md) §4.3 can hide.

### 8.4.1 What exactly gets written

*Executed on the Brain, in one transaction per member, when `members` triggers a run (§8.1).*

The downgrade is **one UPDATE to one column**: `brain_memberships.membership`. Nothing else
about the member changes — not `account_status`, not `level`, not their subscription, not
their stored payment methods, not their afs-connect settings
([09](09-entitlement-integration.md) §9.2).

The value written is the resolved `DunningPolicy::downgradeToLevel`, **not a hardcoded
zero**:

| Policy | Value written |
|---|---|
| `downgrade_to_level` set on the DP | That level, clamped 0–4 |
| `downgrade_to_level` null | The **lowest `level` configured on that DP's tiers** — normally 0, but a DP whose cheapest tier is level 1 downgrades to 1, not to a tier it never sold |
| `nonpayment_action = 'none'` | **Nothing.** Notifications still go out; the tier is untouched |
| Target equals the member's current tier | **Nothing**, and no event row — the task stays idempotent (§8.11) |

In the ordinary Appfactory membership case the resolved target is 0, so the member lands on
Free. That is the outcome, not the rule — the rule is "the DP's own floor", which is what
lets a Direct Payment with no free tier stop at its cheapest paid one instead of dropping a
customer onto a tier that Direct Payment never sold.

`tier_before_downgrade` is written in the same transaction and **before** the tier changes,
and it is what restore reads. Restoring from `tier_paid` alone would fail for a member whose
subscription is gone by the time they come back; `tier_before_downgrade` is the fallback
that makes restore work in both directions.

### 8.4.2 Cancellation is a different path from dunning

Non-payment and cancellation both end with a tier write, and they are **not** the same
mechanism. Conflating them would put a member who deliberately cancelled through a dunning
ladder that emails them three times about a payment problem they do not have.

| | Trigger | Grace | Ladder |
|---|---|---|---|
| **Dunning** | Observed `past_due` | `grace_days`, configurable | Reminders at each `reminder_days` offset (§8.4) |
| **Cancellation** | `laravel_direct_payment_subscriptions.access_ends_at` has passed | The paid-through period itself | **None** — one transition, one email |

The cancellation path is deliberately the simpler of the two:

```php
DirectPaymentSubscription::query()
    ->whereNotNull('access_ends_at')
    ->where('access_ends_at', '<=', now())
    ->whereNotNull('member_reference_num')
    ->cursor();          // → drop to the policy target, fire subscription_access_ended
```

No reminders, because the member already knows — they cancelled, and the
`subscription_canceled` email told them the date ([28](../www/direct_payments/28-subscription-actions.md) §14.3).
A second campaign warning them about a consequence they chose is the kind of email that
teaches people to filter your domain.

Three rules this path shares with the dunning one:

- **Idempotent.** A member already at or below the target is a no-op with no event row, so
  the row can sit past its `access_ends_at` forever without re-firing.
- **Resubscribing wins.** If the member has an `active` subscription by the time the sweep
  reaches them, `tier_paid` resolves from that (§8.3) and the stale `access_ends_at` is
  ignored. Cancel-then-immediately-resubscribe must not cost them a tier.
- **`tier_before_downgrade` is still written**, so an accidental cancellation can be
  reversed by the same restore path (§8.4.1).

**Why the downgrade is not itself the deletion.** `membership = 0` retires storage buckets;
it does not delete files. cloud-storage's own 30-day grace then starts *from there*, so a
member has 14 + 30 days between a failed card and any data loss, with notifications at
every step. That composition is deliberate and must not be short-circuited by "helpfully"
purging on downgrade.

Every duration above is a **default**, not a constant: the real values are resolved per
Direct Payment by `DunningPolicyResolver` (§8.6, [26](../www/direct_payments/26-advanced-policy.md)). Two
DPs with different grace periods produce different ladders in the same run, and each
`brain_membership_events` row records which config drove it.

## 8.5 `brain_membership_billing_state`

One row per member, created lazily on first evaluation. **Written only by `www`** — `members` never reads or writes it, and the tables keep their `brain_*` names because the Brain is their sole writer.

| Column | Type | Notes |
|---|---|---|
| `id` | `id` | |
| `member_reference_num` | `string(64)` **unique** | |
| `provider_subscription_id` | `string(255)` null index | |
| `processor_reference_num` | `string(7)` null | |
| `direct_payment_reference_num` | `string(128)` null | |
| `tier_paid` | `tinyint` null | Null = unresolved → member skipped (§8.3) |
| `tier_effective` | `tinyint` null | Mirror of `brain_memberships.membership` at last evaluation |
| `state` | `string(20)` | `current` \| `past_due` \| `downgraded` |
| `past_due_since` | `timestamp` null | |
| `downgrade_after` | `timestamp` null indexed | The ladder's due date |
| `downgraded_at` | `timestamp` null | |
| `tier_before_downgrade` | `tinyint` null | What restore returns them to if `tier_paid` is unresolvable at restore time |
| `last_notified_step` | `string(40)` null | `dunning_reminder_1` etc. — the ladder's own dedupe |
| `last_evaluated_at` | `timestamp` null | |
| `notes` | `json` null | Skip reasons, unresolved joins |
| `timestamps` | | |

And an append-only audit log, `brain_membership_events`:

| Column | Notes |
|---|---|
| `member_reference_num` index | |
| `event` | `past_due_detected`, `dunning_reminder_1`, `membership_downgraded`, `membership_restored`, `evaluation_skipped` |
| `tier_before`, `tier_after` | |
| `source` | `scheduler` \| `webhook` \| `admin` — what triggered the evaluation |
| `policy_source` | `dp_advanced` \| `deployment_default` \| `inert` — which configuration supplied the durations ([26](../www/direct_payments/26-advanced-policy.md) §12.6). Distinct from `source`: one is *what ran*, the other is *what it obeyed* |
| `direct_payment_reference_num` | Which DP's policy governed this transition |
| `provider_subscription_id` | |
| `payload` json | |
| `created_at` | |

**This log is not optional.** It is the only record of *why* a member's tier changed, and
the first question after any tier dispute is "what happened and when". It is also what
[09](09-entitlement-integration.md) §9.3 exposes to other apps.

## 8.6 Where the ladder's numbers come from

**The durations in §8.4 are per Direct Payment, not global.** They live in
`laravel_direct_payment_advanced` — a Direct Payments table with no knowledge of the Brain
— and are resolved by `DunningPolicyResolver`. See
[26](../www/direct_payments/26-advanced-policy.md), which is the authority on the policy schema; this
section covers only what is enforcement-side.

```
DunningPolicyResolver::forDirectPayment($dpRef)
  1. laravel_direct_payment_advanced  (dunning_enabled)   → source: dp_advanced
  2. brain_billing_settings defaults                      → source: deployment_default
  3. DunningPolicy::inert()                               → source: inert
```

Step 3 matters as much as any rule in this document: **an unresolvable policy is inert**,
never "downgrade with sensible defaults". A missing config row must not be able to cost a
paying customer their tier — the same *unknown is not zero* principle as §8.3.

### `brain_billing_settings` — what remains

A single-row table holding only what is genuinely this deployment's operational concern:

| Column | Default | Purpose |
|---|---|---|
| `enabled` | `false` — **ships off** ([11](11-rollout-and-testing.md) §11.4) | The Brain-wide kill switch |
| `max_transitions_per_run` | `50` | Rate limiting for this scheduler |
| `default_grace_days` | `14` | Step-2 fallback for a DP with no advanced row |
| `default_reminder_days` json | `[3, 7, 12]` | ” |
| `default_mail_handler_reference_num` | null | ” |

`enabled = false` by default is a second, database-level safety on top of the `.env` kill
switch. Enforcement that writes membership tiers should require two deliberate acts to turn
on.

### The one mapping the Brain performs

`DunningPolicy::downgradeToLevel` is a **Direct Payment tier level**
(`laravel_direct_payment_tiers.level`), because [26](../www/direct_payments/26-advanced-policy.md) §12.2
rule 3 forbids the DP layer from storing a `brain_memberships.membership` value.

Translating that level into a membership tier is this task's job and happens in the
`DunningService`, after the policy is resolved (with the 0–4 clamp). On the Brain the two ladders are the same
0–4 index (§8.3), so today the mapping is the identity function plus a clamp — but it is
written as an explicit, named step rather than an implicit equality, because the coincidence
is not a contract.

## 8.7 The evaluation loop

```php
foreach ($this->candidates() as $member) {
    $tierPaid = $this->resolver->tierPaid($member);
    if ($tierPaid === null) {
        $this->recordSkip($member, 'tier_paid_unresolved');   // §8.3 point 3
        continue;
    }

    $sub = $this->resolver->activeSubscription($member);
    $this->dunning->advance($member, $sub, $tierPaid);        // the ladder, §8.4
}
```

`candidates()` is bounded — it does **not** scan every member every 15 minutes:

```php
Membership::query()
    ->whereIn('reference_num', function ($q) { /* members with any processor subscription */ })
    ->where(function ($q) {
        $q->whereHas('billingState', fn ($s) => $s->where('state', '!=', 'current'))   // in the ladder
          ->orWhereHas('billingState', fn ($s) => $s->where('last_evaluated_at', '<=', now()->subHours(6)))
          ->orWhereDoesntHave('billingState');                                          // never evaluated
    })
    ->limit($settings->max_transitions_per_run)
```

A member sitting at `current` with a healthy subscription is re-checked every 6 hours, not
every 15 minutes. Members already on the ladder are checked every tick — that is where the
speed matters.

**Members whose `account_status` is 4 (Closed) or 5 (Purging) are not candidates** — enforcement must not send dunning mail to a closed member or recreate `brain_membership_billing_state` for one ([account_close 05 §5.7](../account_close/05-purge-engine.md)).

**Members with no subscription at all are not candidates.** A free-tier member has nothing
to enforce, and a member whose tier was set manually by an admin must not be dragged down by
a task that cannot see why they have it. If manual grants ever need enforcing, that is an
explicit `json_data` flag, not an inference.

## 8.8 Dunning notifications

*Sent by `www`* (`members` has no mail sender): `DunningService` builds the variables and `BillingMailer` renders and sends. The send is recorded in the same transaction as the transition, so a notice and its state row cannot disagree.

Reuses [24](../www/direct_payments/24-notification-scheduler.md)'s `BillingMailer` and the mail-handler
mechanism — the resolved `DunningPolicy::mailHandlerRef` selects the handler (the DP's own
`dunning_mail_handler_reference_num`, else `brain_billing_settings.default_mail_handler_reference_num`), from
the same `laravel_mail_handlers` dropdown ([23](../www/direct_payments/23-notification-config.md) §5.5).

Dedupe is `last_notified_step` on the state row plus a
`brain_membership_events` row per send, rather than part 1's ledger: the ladder's steps are
a linear sequence per member, not a matrix of (config × subscription × period × offset).

Variables: everything from §6.6 plus `days_until_downgrade`, `downgrade_date`,
`current_tier_label`, `target_tier_label`, `update_payment_url`.

The T+12 `dunning_final` email must state the exact date and what specifically will happen
— *"your plan drops to Free on 8 September; storage buckets 1–3 become read-only, and afs-
connect sources stop syncing"*. A warning that does not say what is lost is not a warning.

## 8.9 Migrations in this step

Three tables, none of which existed before. All guarded with `Schema::hasTable()` in the
style of `2026_05_21_000001_create_processor_tables.php`.

| File | Contents |
|---|---|
| `2026_08_25_000007_create_brain_membership_billing_state_table.php` | §8.5 |
| `2026_08_25_000008_create_brain_membership_events_table.php` | §8.5's audit log |
| `2026_08_25_000009_create_brain_billing_settings_table.php` | §8.6, seeded with one row: `enabled = false` |

These are the plan's only `brain_*` tables, and deliberately so — they hold Brain-side
enforcement *state*, not Direct Payments *policy* ([26](../www/direct_payments/26-advanced-policy.md)
§12.2). A tenant carries the schema and leaves it empty, exactly as it does for every other
`brain_*` table.

`..._000009_create_brain_billing_settings_table` creates the table that
[26](../www/direct_payments/26-advanced-policy.md) §12.9's `..._000010_adjust_brain_billing_settings_defaults`
then renames columns on. The numbering puts create before adjust deliberately — P6 and P6b
ship independently ([11](11-rollout-and-testing.md) §11.1), and an adjust that can run
before its own create is the kind of ordering bug that only shows up on a fresh
deployment.

## 8.10 Reporting

`--dry-run` prints, and every run logs a summary line (both sides log one: `members` logs that it
triggered and how long `www` took; `www` logs the counts):

```
Brain: PASS (https://members.appfactory.studio)
Candidates: 128   Skipped (unresolved tier): 3   Advanced: 11   Downgraded: 2   Restored: 1
Cancellations processed: 4   Transitioned: 1
```

The **skipped** count is the number to watch. A non-zero, non-decreasing skip count means the
plan→tier mapping is broken for real customers, and it is the one failure mode of this task that is
silent by design (§8.3). The second number to watch is `members`' exit code: a persistently failing
trigger means the members→www call is broken and members are neither being downgraded nor restored.

## 8.11 Acceptance

Verified in the lab and by tests ([`complete/08-enforcement-in-members.complete.md`](complete/08-enforcement-in-members.complete.md)):

- `billing:enforce-membership` is scheduled in `members/routes/console.php` **only**; `www/routes/console.php` has no entry, so a tenant clone schedules nothing.
- With `members`' `BILLING_ENFORCEMENT_ENABLED=false` the command makes no call to `www` and exits 0; `--force` overrides it for a manual run.
- The surface refuses an unsigned call, a wrong key, a stale timestamp, a replayed nonce, an unknown calling app, a missing header, an app with no key, and the opposite-direction key — each with `401` — and answers `404` on any deployment where `config('brain.is_brain')` is false, even for a valid signature.
- A second call while a run is in progress gets `409` and does nothing; the lock is released after a run.
- With `brain_billing_settings.enabled = 0` (or the `www` env kill switch off) a triggered run changes nothing.
- A member whose subscription goes `past_due` gets a state row at T+0 and **no tier change**; advancing 14 days downgrades exactly once; paying at T+13 restores within one tick and logs `membership_restored`; a member whose `plan_id` does not map to a DP tier is **skipped**, counted, and keeps their tier; 96 runs against a stable population write zero `brain_membership_events` rows. *(The ladder itself: [`complete/08-brain-enforcement.complete.md`](complete/08-brain-enforcement.complete.md).)*

## 8.12 Switching it on, in order

1. `members` has a working `schedule:run` cron (`laravel-cron.sh --check`).
2. `www/.env` has `IS_BRAIN=true` and `BRAIN_SURFACE_KEY_MEMBERS`; `members/.env` has `WWW_BRAIN_SURFACE_URL` and the same value as `WWW_BRAIN_SURFACE_KEY`. Provision a fresh random value for production (64 hex characters); never reuse another integration's key.
3. Run `php artisan billing:enforce-membership --force --dry-run` from `members` and read the report against the members you expect to be in the ladder. With `brain_billing_settings.enabled` still `0` the Brain reports that enforcement is off.
4. Set `brain_billing_settings.enabled = 1` and repeat with `--dry-run`, which changes nothing, for a full billing cycle before the first real run ([11](11-rollout-and-testing.md) §11.4).
5. Set `BILLING_ENFORCEMENT_ENABLED=true` in `members/.env`. The first live tick is the first real run; watch its log line.

Rollback is any one switch: `members`' flag (stops the calls), `www`'s flag or `IS_BRAIN` (the surface
stops answering), or `brain_billing_settings.enabled = 0` (the command does nothing). Already-downgraded
members are restored with `billing:enforce-membership --member=REF` once enforcement is on (their
subscription is healthy, so the next run restores them).

## 8.13 Decisions taken while building

| Question | Decision |
|---|---|
| Port the ladder into `members` (an evaluator) with a `www` applier, or trigger the `www` logic from `members`? | **Trigger.** Porting meant duplicating about 1,200 lines and their read models and proving parity over a billing cycle, for no behavioural gain; the tier write, audit rows and notices need `www` anyway. The ladder can still move later behind the same trigger |
| May `members` write `brain_membership_billing_state` itself? | No — the Brain is its sole writer, and the state row, tier write and event must commit together |
| Which switch does `members` own? | Only whether to call. Every decision that can change a member is behind `www`'s own switches |
| `BrainGuard` | Removed. `config('brain.is_brain')` replaces it in the command and in the manage-billing URL |
