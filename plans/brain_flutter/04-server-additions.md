# 04 — Server additions (`w/www`)

## Completion document

Built 2026-10-04 (BS11 optional, deliberately not built; tests not yet executed): [complete/04-server-additions.complete.md](complete/04-server-additions.complete.md).

[BF5](00-overview.md): `www` changes are **additive**. This file is the complete list. Where an existing route is
already right, the app calls it unchanged (§4.1); everything else is a numbered **BS#** below, each tagged
`AFS_BRAIN_FLUTTER_MODIFIER_ADDITION — plans/brain_flutter/04-server-additions.md §4.x` in code.

## 4.1 Existing routes the app calls unchanged

| Use | Route | Gate | Notes |
|-----|-------|------|-------|
| Token check | `GET /portal/api/auth/me` | `ApiAuth` | handshake step 1 ([02](02-authentication-device-token.md) §2.5) |
| Member list/search | `GET /portal/api/v1/memberships` | `ApiAuth` | extended by **BS3**, backward compatible |
| Member lookup | `GET /portal/api/v1/brain/member?reference_num=` | `portal.admin.auth` | superseded for the detail screen by **BS4**; still used by the handshake mismatch guard |
| Username / email free? | `GET /portal/api/v1/memberships/validate-username?username=` · `validate-email?email=` | public, throttled | live availability on the create form |
| Create | `POST /portal/api/v1/memberships` | `ApiAuth` | `adminStore`; see [06](06-members-feature.md) §6.4 for the payload |
| Edit names | `PUT /portal/api/v1/memberships/{ref}` | `ApiAuth` | extended by **BS5** |
| Status | `PUT /portal/api/v1/memberships/{ref}/status` | `ApiAuth` | `account_status`, `account_close_date`, `expected_updated_at` |
| Plan | `PUT /portal/api/v1/memberships/{ref}/tier` | `ApiAuth` | `membership`, `reason` (3–500), `expected_updated_at` |
| Allocation | `GET/POST /portal/api/v1/brain/member/{ref}/allocation` | admin + `level99` | |
| Overrides | `GET/POST/DELETE /portal/api/v1/brain/member/{ref}/overrides[/{id}]` | admin + `level99` | |
| Ledger | `GET /portal/api/v1/money/ledger` | `ApiAuth` | `provider`, `from`, `to`, `status`, `direct_payment` |
| Refund | `POST /portal/api/v1/money/charges/{chargeId}/refund` | `ApiAuth` | body `amount_cents?`, `reason?` |
| Checkpoint errors | `GET /memberships/checkpoint-errors` · `PATCH …/{id}/resolve` | `ApiAuth` | [09](09-other-brain-sections.md) |
| afs-connect | `GET/PUT /memberships/{ref}/afs-connect`, `…/afs-connect/global-flag` | `ApiAuth` | [09](09-other-brain-sections.md) |
| Registry | `GET {REGISTRY_URL}` | public | static JSON on `auth` |

**Not called, ever:** `processors/billing/*` ([BA10](01-current-state-audit.md)), the self-service
`directpayments/{dp}/subscription*` routes ([BA9](01-current-state-audit.md)), `/brain/entitlement/*` (HMAC), the
`login*` routes.

## 4.2 Conventions for every new route

- **One group**, `Route::prefix('api/v1/brain/staff')->middleware(['brain.only', 'portal.admin.auth', 'portal.level99'])`
  — the same shape as the Apps and Storage groups. `brain.only` makes them 404 on a tenant; `portal.level99` is the
  **server-side** authority (the database row, never the request); both are in force on every route below.
  `GET /memberships` extensions (**BS3**, **BS5**) stay in their existing `ApiAuth` group, because the browser SPA uses them.
- **Success** `{ "success": true, "data": … }`. **Failure** `{ "success": false, "code": "BS_E_…", "message": "…", "fields"?: { name: [msgs] } }`.
  Codes: `BS_E_NOT_FOUND` 404, `BS_E_VALIDATION` 422, `BS_E_STALE_RECORD` 409, `BS_E_SELF_ACTION` 422,
  `BS_E_NOT_ALLOWED` 422 (action not valid in this state), `BS_E_PROVIDER` 502, `BS_E_NO_SUBSCRIPTION` 404. (The
  *existing* routes use their own shapes; the app normalises all of them, [05](05-app-architecture.md) §5.5.)
- **Audit.** Every mutating staff route writes a `brain_membership_events` row in the same transaction as the change:
  `actor_user_id`/`actor_username` from `auth_user`, `reason`, `source = 'admin_mobile'` (12 chars, fits the
  `varchar(20)` column) so a phone action is distinguishable from a browser (`admin`) action, and `payload.device`
  = the `brain_device_tokens.label` of the calling token. New event constants on `BrainMembershipEvent`:
  `EVENT_PROFILE_UPDATED`, `EVENT_STAFF_SUBSCRIPTION_SYNCED`, `EVENT_STAFF_SUBSCRIPTION_CANCELED`,
  `EVENT_STAFF_SUBSCRIPTION_TIER_CHANGED`.
- **Self-protection.** Any route that changes a member refuses when `{ref}` is the acting member
  (`auth_member->reference_num`) with `BS_E_SELF_ACTION` (BF13).
- **Stale protection.** Routes that change a member row take `expected_updated_at` and return `BS_E_STALE_RECORD` 409
  on mismatch (`MembershipController::staleResponse` semantics).
- **Files.** Controllers in `app/Http/Controllers/Brain/Staff/`; services in `app/Services/Brain/Staff/`; the route group
  in `routes/portal.php` beside the Apps/Storage groups.
- **Code documentation.** PHPDoc on every class and method with the plan reference, per the billing plan's standard.

## 4.3 BS1 — `brain:device-token` and `brain_device_tokens`

Specified in [02](02-authentication-device-token.md) §2.4. Files: `app/Console/Commands/BrainDeviceToken.php`,
`app/Models/BrainDeviceToken.php`, one migration. Phase **P0**.

## 4.4 BS2 — `GET /api/v1/brain/staff/whoami`

The handshake and capability probe. No parameters.

```json
{
  "success": true,
  "data": {
    "user":   { "id": 1, "username": "superadmin" },
    "member": { "reference_num": "appfactorystudio", "username": "appfactorystudio", "level": 99, "membership": 4 },
    "token":  { "expires_at": null, "device": { "id": 1, "label": "pixel" } },
    "server": { "is_brain": true, "time": "2026-10-03T21:04:11Z", "app_version": "…" },
    "membership_direct_payment": "vfPSdk2xj",
    "capabilities": {
      "members": true,
      "member_create": true,
      "member_profile_edit": true,
      "subscription_staff": true,
      "billing_overview": true,
      "allocations": true,
      "overrides": true,
      "money_ledger": true,
      "afs_connect": true,
      "subscription_pricing": false,
      "account_close": false,
      "apps": true,
      "storage": true
    }
  }
}
```

- `token.device` is found by looking the request's bearer token up in `laravel_user_tokens` and joining
  `brain_device_tokens.user_token_id`; null for an ordinary browser token (so the browser could call it too).
- `membership_direct_payment` — the Direct Payment the Brain sells its own plans through. It is **not recorded
  server-side today** (only as `VITE_DIRECT_PAYMENT_ID`, a browser build variable, in every `www` `_env` mode). **BS12** adds
  `config('brain.membership_direct_payment')` reading `env('BRAIN_MEMBERSHIP_DIRECT_PAYMENT', env('VITE_DIRECT_PAYMENT_ID'))`
  in `config/brain.php`, so it works without editing every `www` `_env` mode file and can be set explicitly later. ([OQ8](#4121-open-questions).)
- `capabilities` are computed, never configured: `account_close` = the `MembershipClosureController` class exists
  (`false` until `account_close` ships); `apps`/`storage` = `config('brain.is_brain')`; the rest `true` once the route they
  name is registered. **An unknown or missing capability is `false` in the app (BF10).**

## 4.5 BS3 — `GET /api/v1/memberships` filters (additive)

Existing params unchanged (`page`, `per_page` 1–200, `search`). New, all optional:

| Param | Values | Effect |
|-------|--------|--------|
| `status` | `1`, `2`, `3` or comma list | `account_status IN (…)` |
| `tier` | `0`–`4` or comma list | `membership IN (…)` |
| `billing_state` | `current` \| `past_due` \| `downgraded` | `whereHas('billingState', …)` — the relation already exists on `Membership` |
| `sort` / `dir` | `created_at` (default) \| `username` \| `email` \| `membership` ; `asc` \| `desc` | |
| `summary` | `1` | Return only list columns, **omit `json_data`** and address, and add `billing_state` (string\|null) per row |

`summary=1` row: `id, reference_num, username, email, firstname, lastname, membership, tier_source, account_status,
account_close_date, has_payer (bool), billing_state, created_at, updated_at`. (`level` is deliberately absent, as in the
browser list — [membership_change/08](../membership_change/08-member-management.md) D2.) Without `summary` the response is
byte-for-byte what it is today, so the browser SPA is unaffected. Phase **P1**.

## 4.6 BS4 — `GET /api/v1/brain/staff/members/{ref}`

One call for the whole detail screen (the browser needs a list-snapshot plus four fetches; a phone should not).

```json
{ "success": true, "data": {
  "member":   { …all brain_memberships columns except json_data…, "updated_at": "…" },
  "json_data": { "discount_code": null },
  "is_self":  false,
  "billing_state": { "state": "current", "tier_paid": 1, "tier_effective": 1, "downgrade_after": null } ,
  "subscription_summary": { "status": "incomplete", "tier_name": "Pro", "next_charge_at": "…", "resolution": "payer_link" },
  "overrides": [ { "id": 3, "resource_id": "…", "scope_type": "member", "limit_value": 10, "expires_at": null } ],
  "recent_events": [ { "event": "admin_tier_change", "tier_before": 1, "tier_after": 2, "source": "admin_mobile",
                       "actor_username": "superadmin", "reason": "…", "created_at": "…" } ]
} }
```

`billing_state` and `subscription_summary` are `null` when there is none. `overrides` reuses
`BrainAllocOverride::activeForMember`. `recent_events` = the last 20 `brain_membership_events`. Phase **P1**.

## 4.7 BS5 — `PUT /api/v1/memberships/{ref}` accepts address (additive)

`update()` today fills `$request->only(['firstname','lastname'])`. BS5 widens the allow-list to
`firstname, lastname, street, city, state, zip_code, country_code, country_name` (all plain local columns; none is
part of the Checkpoint identity) and writes an `EVENT_PROFILE_UPDATED` event whose `payload` holds the changed
fields' before/after. `email`, `username`, `level`, `membership`, `account_status`, `payer_reference_num`,
`processor`, `direct_payment`, `reference_num`, `user_id` remain **not writable** here (BF8; [OQ2](00-overview.md)).
Existing `expected_updated_at` handling is kept. Phase **P2**.

## 4.8 BS6 — Staff subscription surface

The heart of the app ([07](07-subscriptions-direct-payments.md)). Controller `BrainStaffSubscriptionController`,
service `StaffSubscriptionResolver`. All under `api/v1/brain/staff/members/{ref}/subscription`.

### 4.8.1 The resolver (one code path)

`StaffSubscriptionResolver::resolve(Membership): ResolvedSubscription` tries, in order, and records which succeeded in
`resolution.path`:

| # | Path | How |
|---|------|-----|
| 1 | `governing` | `MembershipTierResolver::governingLink($member)` / `governingSubscription($member)` — **the exact function `billing:enforce-membership` uses**, so the app and the enforcement task can never disagree about which subscription governs |
| 2 | `payer_link` | latest `laravel_direct_payment_subscriptions` where `payer_reference_num = member.payer_reference_num` (covers a cancelled/ended one the governing rule excludes) |
| 3 | `payer_customer` | `laravel_direct_payment_payer_customers` for the member's payer → `provider_customer_id` → `laravel_processor_customers` → latest `laravel_processor_subscriptions` → the link row by `provider_subscription_id` (covers a link whose `payer_reference_num` is NULL — [BA12](01-current-state-audit.md)) |
| 4 | `none` | nothing found; `data.subscription = null` |

Each path adds a human-readable string to `resolution.warnings` when it had to fall back
(e.g. `"link row has no payer_reference_num"`). The resolver is **read-only and side-effect free**; actions call it first.

### 4.8.2 Shared provider actions (refactor, no behaviour change)

`DirectPaymentSubscriptionController::actionsFor()` is `private`. It is extracted unchanged into
`App\Externals\DirectPayments\Subscriptions\SubscriptionActionsFactory` (constructor-injected `Stripe/Square/PayPalSubscriptionActions`);
the self-service controller delegates to it, and the staff controller uses the same factory. This is the **only** edit to
the self-service controller, and it must keep its existing tests green ([12](12-rollout-and-testing.md) P5).

### 4.8.3 Routes

| Method & path | Body | Behaviour |
|---------------|------|-----------|
| `GET …/subscription` | — | Resolve and return the detail below. Never calls the provider |
| `POST …/subscription/sync` | — | `SubscriptionSyncService::syncOne($subscription)`; returns the refreshed detail. Writes `EVENT_STAFF_SUBSCRIPTION_SYNCED`. On a provider failure `502 BS_E_PROVIDER` and `last_sync_error` is already stored by the sync service |
| `POST …/subscription/cancel` | `{ reason (req 3–500), expected_link_updated_at? }` | Same sequence as the self-service `cancel()`: idempotent if `canceled_at` set; read `next_charge_at` first; `actionsFor($provider)->cancel()`; set `canceled_at` and `access_ends_at` per `DunningPolicyResolver::cancellationAccessEnds($dpRef)`; `syncOne`. Never dispatches `subscription_canceled` itself (the webhook does — `14-subscription-actions` §14.3). `EVENT_STAFF_SUBSCRIPTION_CANCELED` |
| `GET …/subscription/plans` | — | The plans this subscription **can** change to: every tier of the link's Direct Payment that has a price on the processor the subscription is billed through (`TierBindingRegistry::priceForExistingSubscription`), each `{ tier_reference_num, name, level, amount_cents, interval, current: bool }`. Tiers with no price on that processor are listed with `selectable:false, reason:"not priced on this processor"` |
| `POST …/subscription/change-tier` | `{ tier_ref (req), reason (req 3–500), proration? = 'create_prorations', expected_updated_at? }` | Same sequence as the self-service `update()`: tier must belong to the link's DP; `priceForExistingSubscription`; `actionsFor()->update()`; `link.update(direct_payment_tier_reference_num)`; fire `SubscriptionPlanChanged` (its Brain-side listener writes `brain_memberships.membership` — a listener failure arrives as `warning`, never an exception); `syncOne`. `PlanChangeNotSupportedException` → `422 BS_E_NOT_ALLOWED` (e.g. PayPal). `EVENT_STAFF_SUBSCRIPTION_TIER_CHANGED` with before/after |

`GET …/subscription` (and the detail returned by every action):

```json
{ "success": true, "data": {
  "member": { "reference_num": "m1xTHpRmdtH2Y", "membership": 1, "tier_source": null,
              "payer_reference_num": "pay_a1b2c3d4e5f6", "updated_at": "…" },
  "link": {
    "reference_num": "SBgEhRUBUe6R", "direct_payment_reference_num": "vfPSdk2xj",
    "direct_payment_tier_reference_num": "TMKJx6A8tZQ7", "tier_name": "Pro", "tier_level": 1,
    "processor_reference_num": "2uDJGCr", "processor_name": "MainStripe", "processor_mode": "sandbox", "provider": "stripe",
    "provider_subscription_id": "sub_1UGiUt…", "canceled_at": null, "access_ends_at": null, "updated_at": "…"
  },
  "subscription": {
    "status": "incomplete", "next_charge_at": "2026-10-17T16:49:19Z", "next_charge_amount_cents": 1500,
    "currency": "usd", "cancel_at_period_end": false, "trial_ends_at": null, "current_period_end": null,
    "plan_name": "Pro", "last_synced_at": "…", "last_sync_error": null
  },
  "billing_state": { "state": "current", "tier_paid": 1, "tier_effective": 1, "past_due_since": null,
                     "downgrade_after": null, "days_until_downgrade": null, "last_notified_step": null },
  "drift": { "membership": 1, "tier_paid": 1, "matches": true },
  "resolution": { "path": "payer_customer", "warnings": ["link row has no payer_reference_num"] },
  "access_ends_policy": "immediately",
  "allowed_actions": ["sync", "cancel", "change_tier"],
  "warning": null
} }
```

`access_ends_policy` is `DunningPolicyResolver::cancellationAccessEnds($dpRef)` (`immediately` | `period_end`) so the cancel sheet can state the outcome before the call. `processor_mode` is the processor's `mode` (`sandbox` | `live`).

`allowed_actions` is computed by the server so the app never guesses: `cancel` absent when `canceled_at` is set or
status is already `canceled`; `change_tier` absent when the provider cannot change plans or there is no selectable plan;
`sync` present whenever a subscription exists. `drift` flags a member whose `brain_memberships.membership` differs from
the tier their subscription pays for (`tier_source = 'manual'` explains an intentional difference).

Self-protection and stale rules per §4.2. Provider calls can take several seconds; the routes set no queue and return
the final state.

### 4.8.4 What this deliberately does not include

Creating a subscription, adding/removing a card, changing the default card — all need the member's own payment
authentication ([00](00-overview.md) §0.3). **Discount / billing-amount / billing-date** (the web's three never-working
forms) are **BS11**, optional and later.

## 4.9 BS7 — `GET /api/v1/brain/staff/create-options`

What the Add Member form needs that the phone must not hard-code (BF4):

```json
{ "success": true, "data": {
  "membership_direct_payment": "vfPSdk2xj",
  "processors": [ { "reference_num": "2uDJGCr", "provider": "stripe", "name": "MainStripe" } ],
  "plans": [ { "level": 0, "label": "Free" }, { "level": 1, "label": "Pro" }, { "level": 2, "label": "Business" },
             { "level": 3, "label": "Organization" }, { "level": 4, "label": "Enterprise" } ],
  "account_statuses": [ { "value": 1, "label": "Active" }, { "value": 2, "label": "Suspended" },
                        { "value": 3, "label": "Marked for deletion" } ]
} }
```

`processors` lists only `reference_num, provider, name` — never keys or config (the existing `GET /processors` returns
`toAdminArray()` and sits in an ungated group). The plan labels come from `MembershipController::TIER_LABELS` (a private constant today; BS7 exposes it through a public accessor); the app does not carry its own copy. Phase **P2**.

## 4.10 BS8 — `GET /api/v1/brain/staff/members/{ref}/payments`

`?limit=` (default 50, max 200). Charges for the member's payer, derived exactly as the self-service `history()` does
(payer → `payer_customers.provider_customer_id` → both storage shapes of `ProcessorCharge.customer_id`), but with the
fields staff need:

```json
{ "success": true, "data": [ {
  "id": 12, "charge_id": "ch_…", "provider": "stripe", "status": "succeeded",
  "gross_cents": 1500, "refunded_cents": 0, "net_cents": 1500, "currency": "usd",
  "charged_at": "…", "direct_payment_reference_num": "vfPSdk2xj", "refundable_cents": 1500
} ] }
```

`id` is the local `ProcessorCharge` id that `POST /money/charges/{id}/refund` takes. Empty `data: []` (not an error) when
the member has no payer or no charges. Phase **P4**.

## 4.11 BS9 — `GET /api/v1/brain/staff/billing/overview`

Read-only, for the Billing tab ([10](10-ui-and-navigation.md) §10.5):

```json
{ "success": true, "data": {
  "enforcement": { "enabled": false, "default_grace_days": 14, "max_transitions_per_run": 50 },
  "counts": { "current": 0, "past_due": 0, "downgraded": 0, "no_state": 3 },
  "attention": [ { "reference_num": "…", "username": "…", "state": "past_due", "membership": 2, "tier_paid": 2,
                   "past_due_since": "…", "downgrade_after": "…", "days_until_downgrade": 6 } ]
} }
```

`enforcement.enabled` is `brain_billing_settings.enabled` — **`0` in lab2** (verified), so no member moves along the
dunning ladder there; the app shows "Enforcement off" rather than an empty, misleading ladder. `attention` = members whose
state is not `current`, ordered by `downgrade_after` ascending, limit 50. Phase **P4**.

## 4.12 BS10–BS12 and the optional BS11

| # | Change | Phase |
|---|--------|-------|
| **BS10** | `BrainMembershipEvent` constants (§4.2) and `source = 'admin_mobile'` convention | P2 |
| **BS11** *(optional)* | `POST …/subscription/discount`, `…/billing-amount`, `…/billing-date` — staff wrappers that resolve processor + provider customer server-side from the member and then reuse the logic now private in `ProcessorBillingController`, extracted to a service. Changing a recurring amount is the riskiest action in the estate, so this is **P5b**, behind its own capability `subscription_pricing` | P5b |
| **BS12** | `config/brain.php` → `membership_direct_payment` (§4.4) | P0 |

### 4.12.0 BS13 to BS25

The infrastructure additions (the `staff/infra` group: masked config, versioned section patches with history and rollback, the fleet, tenants, DNS, the transfer and apply jobs) are defined in [13](13-brain-infrastructure.md) §13.5, not here.

### 4.12.1 Open questions

| # | Question | Recommendation |
|---|----------|----------------|
| **OQ8** | Name of the server-side Direct Payment setting | `BRAIN_MEMBERSHIP_DIRECT_PAYMENT`, falling back to `VITE_DIRECT_PAYMENT_ID`; add the explicit key to all `www` `_env` modes only when convenient (`check-env.py` already requires identical key sets, so adding it is all-modes-or-none) |
| **OQ9** | Should `GET /memberships/{ref}` (a plain detail) also exist for the browser? | Not needed; BS4 serves the app and the browser keeps its snapshot flow |

## 4.13 Tests (server)

PHPUnit against the real MySQL schema (never SQLite — `afs-eco` runs MySQL everywhere real):

- **BS1/BS2** — see [02](02-authentication-device-token.md) §2.8; `whoami` 404 for a non-99 token, 401 for none, never
  contains a token string.
- **BS3** — each filter, combined filters, `summary=1` shape, and that the **no-param response is unchanged** (golden).
- **BS4** — member with and without payer/subscription/overrides/events; `is_self`.
- **BS5** — address saved and evented; `email`/`level`/`membership` in the body are ignored; stale → 409.
- **BS6** — resolver for each of the four paths using seed shapes copied from lab2; `cancel` idempotent; `cancel` on a
  provider failure leaves `canceled_at` null; `change-tier` to a tier not priced on the processor → 422; self-action → 422;
  every mutating call writes exactly one event with `source = admin_mobile`; provider calls faked at the
  `SubscriptionActionsProvider` seam (no live Stripe).
- **BS7–BS9** — shapes; `payments` returns `[]` for a payer-less member; `overview` with enforcement off.
- **Regression** — the self-service `DirectPaymentSubscriptionController` tests pass unchanged after the factory extraction.
