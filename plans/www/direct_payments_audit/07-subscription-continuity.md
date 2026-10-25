# 07 — Subscription Continuity

> Owner requirement: *"the developer or website builder should be able to switch out the provider and maintain the same
> payment items, and payments continue to work between single payments and subscription payments … work out a way to
> also enable switching between two different payment processors."*

Single payments survive a swap once [06](06-swap-engine-v2.md) lands: the next checkout simply goes to the new processor.
Subscriptions are the hard part, because a provider subscription is a **recurring mandate held by the provider**: a Stripe
`sub_…` cannot be handed to PayPal, and a card token cannot leave the account that vaulted it. This document moves the
**subscription as a business relationship** anyway, by giving it a stable identity of its own and replacing the provider
subscription underneath it — a new one that starts exactly when the old one's paid period ends. Fixes **A30–A36**.

## 7.1 What moves, what is re-created, what the customer does

| | Stripe → Stripe, vault migrated by Stripe | Stripe → Stripe without vault migration | Cross provider |
|---|---|---|---|
| Subscription reference, tier, price, discount, access dates | kept | kept | kept |
| Provider customer | imported map ([04](04-payer-customer-identity.md) §4.7) | created on the target | created on the target |
| Payment method | imported with the vault | **customer enters it once** | **customer enters it once** (or approves PayPal once) |
| Provider subscription | created on the target, starting at the old paid-through moment | same, after the customer acts | same, after the customer acts |
| Customer action required | **none** | one link, one form | one link, one form |
| If the customer never acts | n/a | old subscription keeps renewing on the old account (no revenue lost) until the tenant sets a deadline | same |

## 7.2 The principle: a contract with legs

```
 contract  sub_9f2kq7x1m4pa   tier: Pro   price: 19.00 USD / month   payer: pay_x7k2…   access: active
   │
   ├─ leg 1  Stripe A  sub_1Nq…   active  ─────────────────────── paid through 2026-11-05 14:00 ─┐  then: ending → ended
   │                                                                                             │
   └─ leg 2  PayPal B  I-8R2…     scheduled (approved, first charge 2026-11-05 14:00) ───────────┴─▶ active
```

- The **contract** (`laravel_direct_payment_subscriptions`) is what modules, Site Users and host applications hold.
  Its `reference_num` never changes (today it is regenerated on every write — **A32**).
- A **leg** is one provider subscription. A contract has one leg at checkout and gains one per migration.
- **Create, verify, then end.** The new leg is created with a deferred start equal to the old leg's paid-through moment;
  only after the provider confirms that start date is the old leg set to end at period end. A failure at any step leaves
  the customer subscribed on the old leg — never on neither, never charged twice for one period.

## 7.3 Schema

### The contract — `laravel_direct_payment_subscriptions` (existing, extended)

| Column | Change |
|---|---|
| `reference_num` | **Stops regenerating** (fix in `store()`; see §7.9). New contracts use `sub_` + 12 lowercase alphanumeric; existing 12-character references are kept as they are |
| `status` (new) | `incomplete` · `active` · `past_due` · `migrating` · `ending` · `ended` — ours, derived from the legs (§7.8), not the provider's word |
| `current_leg_id` (new) | the leg currently responsible for renewals |
| `price_amount_cents`, `price_currency`, `price_interval`, `price_interval_count` (new) | the price **this** subscriber pays (grandfathering — [05](05-provider-parity-and-portability.md) §5.3). Updated by a plan change, never by a tier re-price |
| `discount_reference_num`, `discount_cycles_remaining` (new) | what a migration must carry over |
| `started_at`, `ended_at` (new) | contract lifetime |
| `provider_subscription_id`, `processor_reference_num` | kept as a **cache of the current leg** until every reader has moved to legs ([11](11-rollout-and-testing.md) gate G7), then dropped from the create migration |
| `direct_payment_tier_reference_num` | now validated against the leg's actual price (fixes **A33**, §7.9) |

### The legs — **new** `laravel_direct_payment_subscription_legs`

```php
/**
 * AFS_DIRECT_PAYMENTS_MODIFIER_ADDITION — plans/www/direct_payments_audit/07-subscription-continuity.md §7.3.
 *
 * One provider subscription that charges for a Direct Payments subscription contract during one period of its life.
 * A contract gains a leg at checkout and one more per migration. Nothing is ever deleted: an ended leg is how a
 * renewal charged two providers ago still resolves to its contract, its payer and its tier.
 */
Schema::create('laravel_direct_payment_subscription_legs', function (Blueprint $table) {
    $table->id();
    $table->string('subscription_reference_num', 32)->index('dp_sub_legs_sub_idx');
    $table->unsignedSmallInteger('sequence');                       // 1, 2, 3 … per contract
    $table->string('processor_reference_num', 7)->index('dp_sub_legs_proc_idx');   // FK restrict → laravel_processors
    $table->string('provider', 20);
    $table->string('provider_subscription_id', 255)->nullable();    // null until the provider object exists
    $table->unsignedBigInteger('payer_customer_id')->nullable();    // → laravel_direct_payment_payer_customers.id
    $table->unsignedBigInteger('processor_subscription_id')->nullable(); // → laravel_processor_subscriptions.id
    $table->unsignedBigInteger('tier_binding_id')->nullable();      // the provider price this leg was created with
    // pending_authorisation | scheduled | active | past_due | ending | ended | failed | abandoned
    $table->string('status', 24);
    $table->timestamp('starts_at')->nullable();                     // first charge (deferred start for a migration leg)
    $table->timestamp('paid_through_at')->nullable();               // end of the period already paid on this leg
    $table->timestamp('ended_at')->nullable();
    $table->string('created_by', 20);                               // checkout | migration | import
    $table->string('migration_reference_num', 32)->nullable();
    // At most ONE renewing leg per contract — the nullable-unique pattern used across this module.
    $table->string('renewing_key', 32)->nullable();                 // contract ref while status ∈ {active, past_due}, else NULL
    $table->timestamps();

    $table->unique(['processor_reference_num', 'provider_subscription_id'], 'dp_sub_legs_provider_uq');
    $table->unique('renewing_key', 'dp_sub_legs_renewing_uq');
});
```

No back-fill (development, no data): every contract gets its first leg and its price snapshot when it is created
through the server-side subscription start ([06](06-swap-engine-v2.md) §6.3).

## 7.4 Strategies — decided per subscriber

The swap wizard's Review step ([06](06-swap-engine-v2.md) §6.10) shows how many subscribers fall into each strategy
and asks only whether to set a closing date (S3); per-subscription overrides are in the migration dashboard.

| Strategy | When it is offered | What happens | Customer action |
|---|---|---|---|
| **S0 Stay** | always | Legs stay on the retiring binding until each ends. The first plan's behaviour | none |
| **S1 Vault copy** | Stripe → Stripe **and** `import-customer-map` has run for this DP | Fully automatic migration of every subscriber whose card was copied (§7.5) | none (a heads-up e-mail) |
| **S2 Re-authorise** | always — automatic for every subscriber whose card was not copied | Each subscriber is asked once to set up payment on the new provider; on success, automatic hand-over (§7.6) | one form |
| **S3 Re-authorise with deadline** | always | S2 plus a date after which un-migrated contracts are set to end at their period end (§7.6 "Deadline") | one form, by a date |

**Owner decision (OQ3, 2026-10-16):** copy the cards whenever that is possible, and for everyone else make re-entering
payment details as easy as possible for the purchaser. So the default is **not chosen per DP — it is decided per
subscriber**, automatically:

| Subscriber's situation | What happens | What the subscriber receives |
|---|---|---|
| Their card was copied (Stripe → Stripe, vault copy imported, their customer and default card found in the map) | **S1** — migrated with no action | A short **heads-up** e-mail (`subscription_provider_changed`): "Nothing for you to do. From {date} your {plan} renewal will appear as {statement name}." Sent because the charge may look different on their statement, which otherwise causes disputes |
| Their card could **not** be copied (cross-provider swap; no vault copy; or they are missing from the imported map; or the copied card has expired) | **S2** — asked once, through the easiest path below | `payment_details_required`, with reminders |
| The tenant set a closing date for the old account | **S3** for the S2 group | The same, plus the date |

S0 (stay) remains available as a per-subscription override in the dashboard, and is what an S2 subscriber is on until
they act — their old subscription keeps renewing, so nothing is lost while they get round to it.

### Making re-entry as easy as possible

Everything about the S2 path is designed to be one tap where the provider allows it:

| Friction removed | How |
|---|---|
| No sign-in | The e-mail link carries a signed, single-use token that identifies the subscription and payer; nothing to log in to |
| Nothing to type that we already know | Name, e-mail and (where the provider accepts it) billing postal code pre-filled from the payer |
| Fastest payment method first | The renewal page offers the target provider's express options before the card form: Apple Pay / Google Pay (Stripe and Square), PayPal / Venmo (PayPal). A wallet approval is one tap |
| No surprise charge | The page states the date and amount of the next charge and that nothing is charged today |
| Also on the website itself | When the subscriber next visits the website signed in, `dp.subscription.show()` returns `action_required: 'payment_details'` and the SDK can show a one-line banner with the same form inline ([08](08-sdk-and-implementer-contract.md) §8.4). Developers opt in; the e-mail works regardless |
| Reminders that stop at once | Reminders at 14, 7, 3 and 1 days before the renewal; none after the subscriber completes the form |
| Nothing lost if they ignore it | The old subscription keeps renewing (no deadline) — re-entry can happen at any later renewal |

## 7.5 S1 — automatic migration after a Stripe vault copy

Per live leg on the retiring binding, the `SubscriptionMigrator` runs these steps; each step's result is stored on the
migration row so a crash resumes instead of repeating:

1. **Preconditions.** If any precondition fails for a subscriber, that subscriber moves to **S2** automatically
   (their card could not be used) — they are never left without a path. Old leg `active` (not `past_due` — those wait for the DP's grace and retry period to resolve; not
   `cancel_at_period_end` — those end naturally). The payer has an `imported` customer on the target and a default
   payment method from the imported map. Tier price provisioned on the target at the **contract's** price.
2. **Create the new leg** on the target: `customer` = imported customer, `default_payment_method` = imported method,
   `items[0][price]` = provisioned price, **`trial_end` = old leg's `current_period_end`**, `proration_behavior = none`,
   remaining discount re-applied, no internal ids in metadata (TODOS rule 4), `Idempotency-Key: {migration_ref}:create`.
   Leg status `scheduled`.
3. **Verify** by reading the new subscription back: `trial_end` (its first charge) equals the old leg's period end
   within 60 seconds. Otherwise cancel the new leg, mark the migration `failed`, keep the old leg, alert.
4. **End the old leg** at its period end: `cancel_at_period_end = true`, idempotent. Verify by reading it back. Leg
   status `ending`.
5. **Hand-over** happens on its own at the boundary: the old leg's `customer.subscription.deleted` ends it; the new leg's
   first `invoice.payment_succeeded` makes it `active` and the contract's `current_leg_id` moves.

Why `trial_end`: it is Stripe's documented way to start a subscription later without charging, and a `trialing` status is
a status some consumers read directly from provider rows. That is why the contract's own `status` stays `active`
throughout and consumers are told to read the contract, not the provider row — nothing downstream should see a "trial".

## 7.6 S2 — re-authorisation, then automatic hand-over

### Planning

`DirectPaymentProcessorSwapped` creates a **migration run** (`laravel_direct_payment_migration_runs`: DP, from, to,
strategy, deadline, counts) and one **migration** per contract with a live leg on the retiring binding
(`laravel_direct_payment_subscription_migrations`: `mig_` + 12, contract, from leg, to processor, state, step results,
token hash, reminders sent, error).

### Asking the customer

- Four Direct Payments emails cover a processor change — **`payment_details_required`** (first notice and reminders at
  14, 7, 3 and 1 days before the old leg's paid-through date), **`payment_details_updated`**,
  **`subscription_provider_changed`** (S1 heads-up) and **`subscription_ending_payment_details_required`** (S3). They are
  always sent, with built-in default templates; a tenant can customise them but not switch them off during a change
  (owner decision **OQ14**; templates and mechanism in [15](15-default-emails.md)). Recipient is the payer's e-mail
  ([09](09-extension-points.md) §9.3).
- The e-mail carries a link to a **hosted renewal page** with a signed, single-use, expiring token
  (`HMAC(migration_ref, payer_ref, expires_at)`; expires at the deadline or 60 days):
  `https://{website domain}/portal/dp/renew/{token}` — served by the Brain/tenant app, so it works for every website with
  no developer code. Developers who prefer it on their own page call `dp.subscription.reauthorise(token, mountTarget)`
  ([08](08-sdk-and-implementer-contract.md) §8.4). Both exist — owner decision **OQ10**.
- The page shows the plan, the price, and one sentence: *"From {date}, your {plan} subscription will be charged by
  {provider}. Nothing is charged today."* It then mounts the **target** provider's own form.

### Creating the new leg, per target provider

| Target | Customer completes | Server then creates | Deferred start |
|---|---|---|---|
| **Stripe** | SetupIntent for `customerFor(payer, target)` | Subscription with `default_payment_method` = the new method | `trial_end` = old paid-through |
| **Square** | Web Payments SDK card → Cards API card on file for the payer's Square customer | Subscription with `card_id`, plan variation provisioned at the contract price, `location_id` | `start_date` = the calendar date of the old paid-through moment in the location's time zone † |
| **PayPal** | Approves a PayPal subscription created by the server (`plan_id` provisioned at the contract price, `subscriber.email_address`, `return_url` = the renewal page) | — (approval completes it) | `start_time` = old paid-through † |

Then the same **verify → end old leg → hand-over** steps as §7.5 steps 3–5, with per-provider "end at period end":
Stripe `cancel_at_period_end`; Square cancel (effective at period end); PayPal cancel (stops future charges; the paid
period is unaffected and, by construction, no PayPal charge was due before the new leg starts).

### If the customer does nothing

- **No deadline (S2):** at the paid-through moment the old leg simply renews on the old account (its binding is
  retiring, still fully functional). The migration's target date moves to the next period end and reminders continue.
  No revenue is lost; the old account must stay open.
- **Deadline (S3):** at the deadline, every un-migrated contract's old leg is set to end at its period end; the contract
  goes `ending`; `access_ends_at` follows the DP's cancellation-access policy (`../direct_payments/26`); a final
  `subscription_ending_payment_details_required` notification is sent. If the customer completes the link after the
  deadline but within a grace window (default 30 days after access ends), the **same contract** is reactivated with a new
  leg starting immediately — the module, Site Users and host applications never see a new subscription.

## 7.7 The two safety guarantees

**No gap.** The new leg's first charge is scheduled at the old leg's paid-through moment. Square schedules by date, so
the boundary can move by less than one day; the plan accepts that and states it in the tenant preview. No other source
of gap exists, because the old leg is never ended before the new leg is verified.

**No double charge.** Three checks:

1. The new leg is verified, by reading it back from the provider, to charge no earlier than the old paid-through moment
   (Square: no earlier than that date) before anything else happens.
2. `renewing_key` makes it impossible to record two renewing legs for one contract; the old leg leaves the renewing state
   (`ending`) in the same transaction in which the new leg is confirmed scheduled.
3. Any later step failure resolves toward the old leg: if ending the old leg cannot be confirmed by one day before the
   boundary, the new leg is cancelled and the migration retried next period.

Every provider call uses a deterministic idempotency key (`{migration_ref}:{step}`) so a retry returns the first result
instead of creating a second subscription (**A48**).

## 7.8 States

| Migration state | Leg states | Contract `status` | Next |
|---|---|---|---|
| `planned` | old `active` | `active` | notify (S2/S3) or create (S1) |
| `awaiting_customer` | old `active` | `active` | customer completes → `authorised`; deadline → `ending_unmigrated` |
| `authorised` | old `active`, new `pending_authorisation` | `migrating` | create/verify |
| `scheduled` | old `ending`, new `scheduled` | `migrating` | boundary |
| `completed` | old `ended`, new `active` | `active` | — |
| `failed` | old `active`, new `failed`/`abandoned` | `active` | retry next period or operator |
| `ending_unmigrated` | old `ending` | `ending` → `ended` | reactivation within grace |
| `cancelled` | — | as the customer chose | the customer cancelled during migration: **both** legs are ended |

Customer actions during a migration: **cancel** ends both legs; **plan change** is refused with
`DP_E_MIGRATION_PENDING` until the migration completes (one moving part at a time); **card change** applies to the old
leg until hand-over.

## 7.9 Fixing the link endpoint (A31–A33)

- Subscription start becomes server-side ([06](06-swap-engine-v2.md) §6.3): the contract and its first leg are written in
  the same request that creates the provider subscription. The browser-written `POST /{dpRef}/subscriptions` stays one
  release for old SDK builds, and from now on:
  - never changes `reference_num` on an existing contract (**A32**);
  - never changes `direct_payment_tier_reference_num` on an existing contract (**A33**);
  - on a new link, records a tier only if the provider subscription's plan (from the subscription row the server wrote
    when creating it, never from the request) is that tier's price on that processor; otherwise the link is written
    without a tier and the response carries `warning: tier_not_recorded`, `code: DP_E_TIER_PRICE_MISMATCH` — the call never
    fails once money has moved ([16](16-safety-rails-implementation.md) §5.1, PA0).
- `direct_payments:backfill-subscriptions` (the orphan reconciler) writes contracts and legs the same way.

## 7.10 What consumers see

| Consumer | Holds | During and after a migration |
|---|---|---|
| A module (ecommerce, events, appointments) | contract `reference_num` (when it sells subscriptions) | unchanged |
| `dp.subscription` (SDK) | contract | status `active`/`migrating`; next charge date and provider shown from the current/next leg |
| Notifications | contract | `payment_succeeded` etc. fire per leg as today; the four processor-change emails of [15](15-default-emails.md) §15.3 |
| Money ledger | charges | each charge shows the processor that took it (`../direct_payments/04` §4.6) |
| A host application | contract reference, via the public API and events | unchanged, **provided it reads the contract** and not provider ids from Direct Payments tables. (appfactory's own case: [14](14-appfactory-studio-modifications.md) §14.3, set aside) |

Events for consumers: `SubscriptionLegScheduled`, `SubscriptionMigrated`, `SubscriptionMigrationFailed`,
`SubscriptionEndedUnmigrated`. Host applications listen; Direct Payments never calls them ([09](09-extension-points.md) §9.4).

## 7.11 Operator tools

- `php artisan direct_payments:subscription-migrate {dpRef} --plan|--status|--run|--retry={mig}|--cancel={mig}`
  replaces today's list-only command (class renamed `DirectPaymentsSubscriptionMigrate`, [13](13-naming-and-separation.md)). `--plan` is a dry run.
- Due steps run from the existing 15-minute Direct Payments scheduler (`../direct_payments/21`, `24`).
- The migration dashboard in the DP admin: counts per state, next boundaries, failures with the provider's message, the
  resend-link and cancel actions.

## 7.12 Acceptance

Each with a fake provider that records which account was called, with what start date and idempotency key — the evidence
bar `../direct_payments/complete/README.md` set for D2.

- S1: every live leg on the old account gets a target leg whose first charge equals the old period end; the old leg is
  set to end at period end only after that is verified; the contract reference, tier and price are unchanged.
- S2 Stripe/Square/PayPal: completing the hosted page creates exactly one target leg with the deferred start; a
  double-submitted page creates one (idempotency).
- A target leg whose verified start is earlier than the old paid-through moment is cancelled and the old leg untouched.
- With no customer action, the old leg renews and the migration rolls to the next period; with a deadline, the contract
  ends at its period end and is reactivatable within the grace window under the same reference.
- A customer cancelling mid-migration ends both legs; a plan change mid-migration is refused.
- The unauthenticated link endpoint can no longer change an existing contract's tier or reference.
- `dp.subscription.show()` returns the same contract reference, tier and `active` status before, during and after a
  migration.
