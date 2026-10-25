# 12 — Direct Payments Advanced

Per-Direct-Payment policy that the Brain can configure today and that **anything** can
configure tomorrow. The non-payment grace period is the first setting; the point of this
document is the shape that lets the second, third and tenth be added without a migration
of meaning.

## 12.1 The requirement, and the trap in it

> *"Create the necessary database tables to expand the Direct Payments API but do not tie it
> to the brain so that it cannot be decoupled from it later. It should just be a Direct
> Payments advanced feature only configurable by the brain for right now."*

Two statements that pull in opposite directions unless one distinction is held:

| | Where it lives | Changes when this graduates |
|---|---|---|
| **The data** | Direct Payments layer. No `brain_*` table, no member column, no FK to anything the Brain owns | **Nothing** |
| **Who may write it** | One route guard, keyed on operator `level == 99` | Deleted or widened |

"Only the Brain can configure it" is an **authorization fact**, not a schema fact. The
moment it becomes a schema fact — a `brain_` prefix, a `member_reference_num`, a column
holding a `brain_memberships.membership` value — the coupling is permanent and graduation
means a data migration.

So: the table is `laravel_direct_payment_advanced`, it sits beside
`laravel_direct_payment_connect`, the UI is a card in the **normal** Direct Payments form
(§12.7), and the restriction is one middleware alias checking one operator permission.

## 12.2 The decoupling contract

Six rules. A change that breaks any of them has re-coupled the feature and should be
rejected in review.

1. **No table in this feature carries a `brain_` prefix**, and none references a `brain_*`
   table by column or foreign key.
2. **No column holds a member reference.** Policy is per Direct Payment. Which members it
   affects is a consequence of which DP they bought, resolved through
   `laravel_direct_payment_subscriptions` ([22](22-subscription-state.md) §4.2.2).
3. **Tier values are DP tier `level`s**, from `laravel_direct_payment_tiers.level` — never
   `brain_memberships.membership`. The two happen to share the 0–4 range on the Brain
   ([08](../../billing/08-brain-enforcement.md) §8.3), and that coincidence must not become an assumption.
   The Brain's enforcement task performs the mapping; the table stores the DP's own units.
4. **The restriction is exactly one middleware**, registered in `bootstrap/app.php`'s alias
   map beside `portal.cors`, `portal.admin.auth` and `afs_connect.service`, and it checks a
   **permission** (`level == 99`) rather than a deployment property. Graduation = drop that
   alias from the route, plus the matching visibility check in the UI.
5. **The read path is never restricted.** Anything that resolves policy — the enforcement
   task, a tenant's own scheduler, the DP form itself — reads the row directly. Only
   *writes* are gated, and only for now.
6. **The UI lives in the normal Direct Payments form from day one** (§12.7). There is no
   Brain-only screen to port later.

### What graduation actually costs

When this moves into normal Direct Payments configuration:

- Remove `->middleware('directpayments.advanced')` from the route in `routes/portal.php`,
  and the `$request->has('advanced')` gate in `saveDirectPayment()` — **two lines**.
- Delete the `level == 99` visibility check in `directpayments.ts` (§12.7) so the card shows
  for every operator — **one block**.

That is all of it. No migration, no data movement, no new UI, no change to the enforcement
task, and every DP that already had a policy keeps it. If a future change makes graduation
cost more than deleting three things, the coupling has crept back in.

## 12.3 `laravel_direct_payment_advanced`

One row per Direct Payment, created lazily on first save. Modelled on
`laravel_direct_payment_connect` — the established precedent for DP child config
([20](20-current-state-audit.md) §2.2).

| Column | Type | Notes |
|---|---|---|
| `id` | `id` | |
| `reference_num` | `string(32)` unique | `Str::random(12)` |
| `direct_payment_reference_num` | `string(128)` **unique** | → `laravel_direct_payments.reference_num`. One row per DP |
| `dunning_enabled` | `boolean` default `false` | **Ships off.** No DP gets non-payment handling until an operator turns it on |
| `grace_days` | `smallint` default `14` | Days from first observed `past_due` to the action. `0` = act immediately — allowed, but see §12.5 |
| `reminder_days` | `json` null | Day offsets inside the grace window, e.g. `[3, 7, 12]`. Each must be `>= 1` and `< grace_days` |
| `nonpayment_action` | `string(20)` default `downgrade` | `downgrade` \| `none`. `none` = notify only, never change a tier. **No `suspend`** — see below |
| `downgrade_to_level` | `tinyint` null | A `laravel_direct_payment_tiers.level` — **DP units, rule 3**. Null = the lowest level configured on this DP |
| `restore_on_payment` | `boolean` default `true` | Return to the paid level when the subscription recovers |
| `cancellation_access_ends` | `string(20)` default `period_end` | `period_end` \| `immediately`. **When the member's benefits stop after they cancel.** Billing always stops immediately at the provider ([28](28-subscription-actions.md) §14.3) — this is the half the application controls |
| `dunning_mail_handler_reference_num` | `string(64)` null | → `laravel_mail_handlers.reference_num`, same dropdown as [23](23-notification-config.md) §5.5. Null = no dunning email |
| `timestamps` | | |

```php
$table->unique('direct_payment_reference_num', 'dp_advanced_dp_uq');
$table->index('dunning_enabled', 'dp_advanced_dunning_idx');
```

Model `App\Models\Externals\DirectPaymentAdvanced`, `$casts`: `reminder_days` → `array`,
`dunning_enabled`/`restore_on_payment` → `boolean`, `grace_days`/`downgrade_to_level` →
`integer`. Relation `directPayment()` →
`belongsTo(DirectPayment::class, 'direct_payment_reference_num', 'reference_num')`.

### There is no `suspend` action

An earlier draft of this table carried a third value, `suspend`, meaning "set
`brain_memberships.account_status = 2`". It is removed, because it contradicts a decision
already made in [09](../../billing/09-entitlement-integration.md) §9.2: **enforcement writes `membership`
and nothing else.**

`account_status` is an account-lifecycle field — abuse, fraud, a support action, a deletion
request. A failed card is none of those. Conflating them makes an expired card
indistinguishable from a ban in every log and every downstream check that reads
`account_status`, including afs-connect's.

An enum value with no implementation is worse than a missing feature: it saves cleanly,
passes validation, and then does nothing at 3am. If account suspension is ever wanted as a
billing outcome it needs its own decision, its own restore path and its own member-facing
copy — not a third string in this column.

### Why real columns and not a `settings` JSON blob

A JSON bucket would make "add the next advanced feature" a zero-migration change, which is
the obvious argument for it. It is the wrong trade here:

- Nothing can validate it. `grace_days: "fourteen"` and `grace_days: -3` both store fine
  and fail at 3am inside a cron.
- Nothing can index it. `dunning_enabled` is the flag that keeps the enforcement task cheap
  ([12.6](#126-how-policy-is-resolved)); buried in JSON it becomes a full scan.
- It becomes a dumping ground. Two years on, nobody knows which keys are live, and the
  answer requires reading every consumer.

A new advanced feature is a migration and a validated field. That is a small, honest cost
paid once per feature.

## 12.4 Validation

Server-side, rejecting with `422` and a `DP_E_*` code in the style
`saveDirectPayment` already uses:

| Rule | Code |
|---|---|
| `grace_days` integer, `0..365` | `DP_E_INVALID_GRACE_DAYS` |
| `reminder_days` array, ≤ 6 entries, each integer `>= 1` and `< grace_days`, no duplicates, stored sorted ascending | `DP_E_INVALID_REMINDER_DAYS` |
| `nonpayment_action` in the enum | `DP_E_INVALID_NONPAYMENT_ACTION` |
| `cancellation_access_ends` ∈ {period_end, immediately} | `DP_E_INVALID_CANCELLATION_POLICY` |
| `downgrade_to_level` null, or a `level` that exists on **this DP's** tiers | `DP_E_INVALID_DOWNGRADE_LEVEL` |
| `dunning_mail_handler_reference_num` null, or exists in `laravel_mail_handlers` | `DP_E_MAIL_HANDLER_NOT_FOUND` |
| `dunning_enabled = true` with `nonpayment_action = 'downgrade'` and no resolvable target level | `DP_E_NO_DOWNGRADE_TARGET` |

The `reminder_days < grace_days` rule matters: a reminder scheduled after the downgrade has
already happened is an email that contradicts the account state, and it is the exact
misconfiguration an operator makes when they shorten `grace_days` and forget the reminders.
Reject it at save time, where they can see it.

The last row is the same principle as [23](23-notification-config.md) §5.3's rejection of an
empty `days_before`: a configuration that cannot do what it claims is refused at save, not
discovered as silence.

## 12.5 `grace_days = 0`

Permitted, and deliberately not blocked — an operator running a prepaid-credits product may
genuinely want immediate action, and this table exists to serve Direct Payments generally,
not just membership billing.

The admin UI warns when `grace_days` falls below the backing processor's retry window,
resolved through [27](27-provider-retry-policy.md) §13.7 — downgrading a member while the
provider is still retrying produces a downgrade that reverses itself days later, with both
emails already sent.

But [08](../../billing/08-brain-enforcement.md) §8.4's argument still holds for anything resembling a
membership: payments fail for reasons unrelated to intent, and an instant downgrade on a
declined card is a support incident. The admin UI (§12.7) shows an inline warning when
`grace_days < 3`, naming what happens immediately. It warns; it does not refuse. Deciding
that for the operator would be this document exceeding its remit.

## 12.6 How policy is resolved

`DunningPolicyResolver` (`App\Externals\DirectPayments\Dunning`, used by the enforcement run on `www`, [billing/08](../../billing/08-brain-enforcement.md)) — the single place any consumer turns a
subscription into a policy:

```php
/**
 * AFS_BILLING_MODIFIER_ADDITION — plans/www/direct_payments/26-advanced-policy.md §12.6.
 *
 * Resolves the dunning policy governing one subscription.
 *
 * Deliberately knows nothing about the Brain. It takes a Direct Payment
 * reference and returns a DunningPolicy in DP units (tier LEVELs, not
 * brain_memberships.membership values — §12.2 rule 3). Mapping a level onto a
 * membership tier is the Brain enforcement task's job and happens after this
 * returns, so a tenant scheduler could consume this unchanged.
 *
 * Resolution order:
 *   1. laravel_direct_payment_advanced for this DP, when dunning_enabled
 *   2. the deployment default (brain_billing_settings on the Brain; the
 *      hardcoded defaults anywhere else)
 *   3. DunningPolicy::inert() — notify nothing, change nothing
 *
 * Step 3 is the important one. An unresolvable policy is INERT, never
 * "downgrade with sensible defaults". A missing config row must not be able to
 * cost a paying customer their tier.
 */
public function forDirectPayment(string $dpRef): DunningPolicy
```

`DunningPolicy` is a readonly value object: `enabled`, `graceDays`, `reminderDays`,
`action`, `downgradeToLevel`, `restoreOnPayment`, `mailHandlerRef`, plus `source`
(`dp_advanced` | `deployment_default` | `inert`) so `--dry-run` and the audit log can say
*which* configuration produced a transition.

Caching: resolved per DP per command run, not per member. A sweep over 500 members on 3 DPs
does 3 lookups.

### What changes in [08](../../billing/08-brain-enforcement.md)

`brain_billing_settings` (§8.6) loses the policy fields it was holding — `grace_days`,
`reminder_days`, `downgrade_to_tier` — because those are now per-DP. What remains there is
the deployment kill switch, the per-run cap and the `default_*` fallbacks; **that table is
defined in [08](../../billing/08-brain-enforcement.md) §8.6 and is not restated here**, so the two
documents cannot drift apart.

The enforcement ladder itself is unchanged — it just reads its durations from a
`DunningPolicy` instead of from constants, and records `policy_source` on each
`brain_membership_events` row.

## 12.7 The UI — inside the normal Direct Payments form

The advanced settings live in the **ordinary Direct Payments admin screen**, not in a
separate Brain section. A fifth conditionally-shown card in
`resources/js/portal/admin/html/views/directpayments-form.html`, beside the three that
already exist and the notifications card from [23](23-notification-config.md) §5.7:

```html
<!-- AFS_BILLING_MODIFIER_ADDITION - plans/www/direct_payments/26-advanced-policy.md 12.7 -->
<div class="card mb-3 d-none" id="dpAdvancedSection">
  <div class="card-header">
    <h3 class="card-title" data-i18n="directpayments-form.advanced">Advanced</h3>
    <span class="badge badge-outline text-orange ms-2"
          data-i18n="directpayments-form.advanced_restricted">Restricted</span>
  </div>
  <div class="card-body">
    <!-- dunning_enabled, grace_days, reminder_days, nonpayment_action,
         downgrade_to_level, restore_on_payment, dunning_mail_handler_reference_num,
         cancellation_access_ends -->
  </div>
</div>
```

Building it here rather than in a Brain-only screen is the point: **when the restriction is
lifted, the UI is already where it belongs.** Graduation stops being a port and becomes the
deletion of a visibility check.

### The visibility gate

`directpayments.ts`, following the precedent already in `main.ts:596` — which is how the
Brain sidebar item itself is shown:

```ts
/**
 * AFS_BILLING_MODIFIER_ADDITION — plans/www/direct_payments/26-advanced-policy.md §12.7.
 *
 * Advanced config is restricted to level-99 operators for now. Same check
 * main.ts:596 uses to reveal the Brain sidebar item, so there is one definition
 * of "operator" in this UI rather than two that can drift.
 *
 * Visibility only; AdvancedConfigGate (§12.8) enforces the same condition
 * server-side.
 *
 * When advanced config graduates to all operators, delete this block. The card,
 * its fields, the save payload and the endpoint all stay exactly as they are.
 */
const advancedSection = document.getElementById('dpAdvancedSection');
const canEditAdvanced = app.window.member?.level == 99;
if (advancedSection && canEditAdvanced && payTypeSelect?.value === 'SUBSCRIPTION') {
    advancedSection.classList.remove('d-none');
}
```

Shown for `SUBSCRIPTION` only, driven by the same `#dpPayType` change listener that already
toggles `#dpSinglePaySection`, `#dpSubscriptionSection` and `#dpConnectSection`.

Two rules for the non-99 operator:

- The card is **hidden, not disabled**. A greyed-out control invites a support ticket about
  a feature the operator cannot have yet.
- `dpObj.advanced` is **omitted entirely** from the save payload when the card is hidden —
  never sent as `null` or as defaults. An operator without access must not be able to
  silently blank another operator's policy by opening and saving the DP. The server treats a
  missing `advanced` key as "leave the existing row alone", exactly as
  [23](23-notification-config.md) §5.6 treats a missing `notifications` key.

The second rule is the one that actually bites in testing, and it is easy to get wrong by
building the payload unconditionally.

### Where the Brain admin section still fits

No new view is registered, and `brain_membership.ts` gains no editor. If it is later useful
for an operator looking at one member to see *which* policy governs them and where it came
from (`dp_advanced` vs `deployment_default`), that is a small read-only panel on
`initMembershipSubscriptionView()` — worth doing, not required by this step, and explicitly
not a second place to edit the values.

## 12.8 API and the server-side guard

DP-layer routes in `routes/portal.php`, inside the existing `/directpayments` group:

```php
// AFS_BILLING_MODIFIER_ADDITION — plans/www/direct_payments/26-advanced-policy.md §12.8.
// READ is open to any admin caller: the enforcement task, the DP form and a future
// tenant UI all need it.
Route::get('/{dpRef}/advanced', [DirectPaymentAdvancedController::class, 'show']);

// WRITE is level-99-only FOR NOW. This middleware is the ENTIRE restriction
// (§12.2 rule 4) — deleting this one line graduates the feature.
Route::post('/{dpRef}/advanced', [DirectPaymentAdvancedController::class, 'store'])
    ->middleware('directpayments.advanced');
```

Advanced config may also arrive as an `advanced` key on `saveDirectPayment`, since it is now
part of the same form. That path runs the **same** guard — a restriction enforced on one
route but not on the sibling that writes the same table is not a restriction:

```php
// In saveDirectPayment(), alongside syncConnectConfig() and syncNotificationConfigs():
if ($request->has('advanced')) {
    if (! app(AdvancedConfigGate::class)->allows($request)) {
        return response()->json([
            'error' => 'Advanced configuration is restricted.',
            'code'  => 'DP_E_ADVANCED_WRITE_FORBIDDEN',
        ], 403);
    }
    $this->syncAdvancedConfig($directPayment, $request->input('advanced'));
}
```

### The identity chain

`app.window.member.level` is a client-side value, so the server resolves the same fact
itself. What it must resolve is **not** "who is logged in" — it is **whose site this is**.

`main.ts:496` fetches the member like this:

```ts
const usernameProp = app.window.subdomain_properties.find(p => p.PropertyName === 'Username');
const brainClient = new portal.brain.Client({ subdomain: app.window.subdomain === 'www15' ? 'www15' : 'www' });
app.window.member = await brainClient.getMember(usernameProp.PropertyValue);
```

`BrainClient.getBaseUrl()` hardcodes `https://www.appfactory.studio` /
`https://www15.appfactory.studio`, and `getMember()` sends the value as `reference_num`. So
on **every** deployment — tenants included — `app.window.member` is the **site owner's**
membership record, fetched from the Brain over HTTP. A tenant's own empty `brain_*` tables
are not involved.

That is what makes `level == 99` work as the restriction: on the Brain the site owner is the
Appfactory account, whose `level` is 99. On a tenant the site owner is that member, whose
level is not. The check is a property of *whose deployment this is*, expressed as data rather
than as a hardcoded domain string.

The server mirrors exactly that resolution:

```php
/**
 * AFS_BILLING_MODIFIER_ADDITION — plans/www/direct_payments/26-advanced-policy.md §12.8.
 *
 * May this deployment write Direct Payments advanced configuration?
 *
 * Mirrors main.ts:496 server-side — the SITE OWNER's membership record, keyed
 * on the `Username` subdomain property, which is a member reference_num
 * (WwwAfsConnectTenantFromRequest.php:47 reads it the same way):
 *
 *     subdomain_properties.Username
 *       -> brain_memberships.reference_num   (locally on the Brain)
 *       -> Brain API /brain/member           (on a tenant, where brain_* is empty)
 *       -> level === 99
 *
 * NOT resolved from the portal token. `laravel_user_tokens.user_id` is a LOCAL
 * users-table id, while `brain_memberships.user_id` on the Brain refers to the
 * Brain's users table — different id spaces, and on a tenant the local lookup
 * is meaningless. The token answers "who is logged in"; this gate asks "whose
 * deployment is this", and they are different questions.
 *
 * FAILS CLOSED at every link: no Username property, no member, an unreachable
 * Brain, or a level that is not exactly 99, all deny. An unreachable Brain
 * denying is deliberate — advanced config is edited rarely, and a transient
 * outage that blocks an edit is cheaper than one that opens the gate.
 */
final class AdvancedConfigGate
{
    private const REQUIRED_LEVEL = 99;

    public function allows(): bool
    {
        $md = FunctionsController::GetManagedDomain();
        $username = trim((string) ($md['Username'] ?? ''));
        if ($username === '') return false;

        $level = $this->resolveOwnerLevel($username);

        return $level !== null && $level === self::REQUIRED_LEVEL;
    }

    /** Local read where brain_memberships is populated; Brain API otherwise. */
    private function resolveOwnerLevel(string $username): ?int
    {
        $local = Membership::where('reference_num', $username)->value('level');
        if ($local !== null) return (int) $local;

        $res = FunctionsController::brainCall('/member', ['reference_num' => $username]);
        $level = $res?->json('member.level');

        return $level === null ? null : (int) $level;
    }
}
```

Cache the resolved level for the request; a `saveDirectPayment` that also carries an
`advanced` key must not make two Brain round-trips.

**`level === 99` exactly, not `>= 99`.** `brain_memberships.level` defaults to 0 with no
documented ladder above it, so 99 is a sentinel, not a rank. A `>=` comparison would grant
access to any future value chosen for an unrelated reason.

### Why this still decouples

The gate reads a **member's level**, not a domain. Widening it later is a data change —
grant a partner's owner account level 99 — or a one-line change to `REQUIRED_LEVEL`. Neither
requires finding and removing a hardcoded `appfactory.studio` string.

The two guards answer different questions and must not be merged:

| | Question | Mechanism |
|---|---|---|
| `config('brain.is_brain')` ([www_brain B10](../../www_brain/00-overview.md)) | Is this deployment the Brain? | The `IS_BRAIN` env flag, plus a production URL check |
| `AdvancedConfigGate` (here) | May this **deployment edit advanced config**? | Site owner's `brain_memberships.level` |

The middleware is a two-line wrapper around it, registered in `bootstrap/app.php`'s existing
alias map beside `portal.cors`, `portal.admin.auth` and `afs_connect.service`:

```php
'directpayments.advanced' => \App\Http\Middleware\DirectPaymentAdvancedWriteMiddleware::class,
```

`show()` returns the stored row, or the **resolved defaults** with
`"source": "deployment_default"` when no row exists — so the form renders what *would* apply
rather than an empty card implying nothing is configured. It also returns
`"editable": true|false` from the same gate, so the UI's visibility decision and the
server's authorization decision come from one answer rather than two that can disagree.

## 12.9 Migrations in this step

| File | Contents |
|---|---|
| `2026_08_25_000005_create_direct_payment_advanced_table.php` | §12.3 |
| `2026_08_25_000010_adjust_brain_billing_settings_defaults.php` | §12.6 — renames the policy columns to `default_*`, drops `downgrade_to_tier` |

The second migration only runs where [08](../../billing/08-brain-enforcement.md) has already shipped; it
is guarded with `Schema::hasTable()`/`hasColumn()` like every other migration in this plan.

## 12.10 Acceptance

- `grep -rn "brain_\|Membership\|member_reference_num"` across `DirectPaymentAdvanced.php`,
  `DirectPaymentAdvancedController.php`, `DunningPolicyResolver.php` and the migration
  returns **nothing**. Rules 1–3, as a CI check. (`AdvancedConfigGate` is the one deliberate
  exception — it reads `brain_memberships.level` to answer *who is calling*, never *what the
  policy is*.)
- **Graduation drill:** deleting the two route/controller gates and the one UI block makes
  advanced config editable by any operator on any deployment, with no migration and no data
  movement. Do this once for real in a scratch branch — it is the only way to know rule 4
  still holds.
- A tenant, or any non-99 operator, can `GET /{dpRef}/advanced` and receives the resolved
  policy with `"editable": false`. Rule 5.
- A non-99 operator does not see the Advanced card, and **saving the DP does not clear an
  existing advanced row** — the payload omits the key entirely (§12.7).
- A forged `app.window.member.level = 99` in the browser console reveals the card and the
  save still returns `403 DP_E_ADVANCED_WRITE_FORBIDDEN`. The client check is visibility;
  the server check is authorization.
- `POST /{dpRef}/advanced` with a valid token whose member has `level = 0` → `403`.
- Same call with no `brain_memberships` row for the token's user (the tenant case) → `403`,
  by the lookup missing rather than by any domain check.
- A DP with no advanced row resolves to `deployment_default`; with neither, **inert**.
- `reminder_days: [3, 20]` with `grace_days: 14` rejects at save.
- `downgrade_to_level: 3` on a DP whose tiers are levels 0–2 rejects at save.
- `grace_days: 1` shows the warning and still saves.
- Two DPs with `grace_days` 7 and 30 produce different ladders in one enforcement run, and
  `brain_membership_events.policy_source` says which config drove each.
