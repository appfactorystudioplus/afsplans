# 07 — Tier Bindings

Subscription pricing has the identical shape of problem as customers ([02](02-payers-and-bindings.md))
and one problem underneath that: a `laravel_direct_payment_tiers` row is not a stable thing
to begin with. Both are fixed here, in that order — the second cannot be fixed without the
first.

## 7.1 The two defects

**D9 — a tier's identity does not survive a save.** `saveDirectPayment()` deletes every tier
row belonging to a Direct Payment and recreates all of them with a fresh
`Str::random(12)` reference, on every save — not an upsert, and not keyed on anything a
caller supplied. A subscription's `direct_payment_tier_reference_num` is pinned to a row that
no longer exists the next time an operator so much as re-saves the tier list to fix a typo in
a description. This happens with **no swap involved at all.**

**D10 — a tier's price is one column, with no processor of its own.** `processor_tier_id`
holds a raw Stripe price id, PayPal plan id, or Square catalog object id — scoped to whichever
processor happened to be active when someone typed it in, not to the Direct Payment's binding
at the time. This is [02](02-payers-and-bindings.md) §2.4's problem — one processor column
that should be a history — applied to pricing instead of to the Direct Payment itself.

Fixing D10 without fixing D9 first would mean building a stable per-processor mapping on top
of an identity that regenerates itself out from under it. So D9 is fixed structurally, and D10
follows the same pattern [02](02-payers-and-bindings.md) already established twice — a stable
concept, with a table of per-processor variants underneath it.

## 7.2 D9 — `saveDirectPayment()` stops destroying tier identity

```php
/**
 * AFS_BILLING_MODIFIER_ADDITION — plans/www/direct_payments/07-tier-bindings.md §7.2 (D9).
 *
 * Was: delete every tier, recreate all of them with a fresh reference_num.
 * Now: an upsert keyed on the reference_num the CLIENT already holds for an
 * existing tier — the admin UI already round-trips the tier objects it loaded,
 * so this is not a new value it has to start tracking, only one it has to stop
 * discarding.
 *
 * A tier with no reference_num in the payload is new. A reference_num in the
 * payload that does not match an existing row is refused rather than silently
 * creating a duplicate under a client-chosen id — the reference format is ours
 * to issue (the same rule site_users/13 §13.2 states for SiteUserReference),
 * never the client's.
 */
$incomingRefs = collect($tiers)->pluck('reference_num')->filter()->values();

$existing = DirectPaymentTier::where('direct_payment_reference_num', $ref)
    ->pluck('id', 'reference_num');

foreach ($incomingRefs as $tierRef) {
    if (! $existing->has($tierRef)) {
        return response()->json([
            'error' => "Tier {$tierRef} does not belong to this Direct Payment.",
            'code' => 'DP_E_UNKNOWN_TIER_REFERENCE',
        ], 422);
    }
}

// Tiers removed from the payload are genuinely deleted — an operator who drops
// a tier from the list means to retire it. What changes is that a tier KEPT in
// the list keeps its identity instead of losing it.
DirectPaymentTier::where('direct_payment_reference_num', $ref)
    ->whereNotIn('reference_num', $incomingRefs)
    ->delete();

foreach ($tiers as $tier) {
    DirectPaymentTier::updateOrCreate(
        ['reference_num' => $tier['reference_num'] ?? DirectPaymentTierReference::generate()],
        [
            'direct_payment_reference_num' => $ref,
            'custom_title' => $tier['custom_title'] ?? null,
            'custom_description' => $tier['custom_description'] ?? null,
            'original_data' => is_array($tier['original_data'] ?? null) ? json_encode($tier['original_data']) : null,
            'level' => $tier['level'] ?? 0,
            // processor_tier_id is NOT written here after §7.3 — it moves to
            // tier_bindings. Kept as a derived column through the deprecation
            // window (§7.5), same shape as every other column this plan retires.
        ]
    );
}
```

`DirectPaymentTierReference::generate()` is `tier_` + the same 12-char lowered alphanumeric
generator every other reference in this estate uses.

**A genuinely deleted tier orphans its historical subscriptions' reference gracefully.**
`direct_payment_tier_reference_num` on an old subscription then points at nothing, exactly as
it does today when a tenant removes a plan — `MembershipTierResolver`'s fallback (deriving the
level from the processor's own plan id) already covers this case and is unchanged.

## 7.3 D10 — `laravel_direct_payment_tier_bindings`

```php
/**
 * AFS_BILLING_MODIFIER_ADDITION — plans/www/direct_payments/07-tier-bindings.md §7.3 (D10).
 *
 * A tier ("Basic", level 1) is stable across a swap. Its PRICE is not — it is a
 * different object on every processor. This table is the per-processor variant,
 * the same shape as payer_customers (02 §2.3) and processor_bindings (02 §2.4):
 * one concept, many bindings, exactly one active per processor at a time.
 */
Schema::create('laravel_direct_payment_tier_bindings', function (Blueprint $table) {
    $table->id();

    $table->string('tier_reference_num', 32)->index('dp_tier_bind_tier_idx');
    $table->string('processor_reference_num', 7)->index('dp_tier_bind_proc_idx');
    $table->string('processor_tier_id', 255);        // the provider's price/plan id on THIS processor

    $table->string('status', 20)->default('active');  // active | retired
    $table->timestamps();

    // One active price per (tier, processor) — the nullable-unique pattern
    // §2.3 and §2.4 both already use.
    $table->string('active_key', 96)->nullable();      // "<tier_ref>:<processor_ref>" while active, NULL once retired
    $table->unique('active_key', 'dp_tier_bind_active_uq');
});
```

### The two resolutions, matching [04](04-history-refunds-and-webhooks.md) §4.1 exactly

```php
/**
 * FORWARD — "sell this tier" (a new subscription, or an upgrade target chosen
 * from the current pricing page). Resolves through the DP's ACTIVE processor
 * binding, because that is where the new charge is going.
 */
public function priceFor(string $tierRef, string $dpRef): TierBinding
{
    $binding = $this->processorBindings->active($dpRef)
        ?? throw new DirectPaymentException('DP_E_NO_ACTIVE_BINDING');

    return TierBinding::where('tier_reference_num', $tierRef)
        ->where('processor_reference_num', $binding->processor_reference_num)
        ->where('status', 'active')
        ->firstOrFail();      // DP_E_TIER_NOT_PRICED_ON_PROCESSOR if missing — §7.4
}

/**
 * BACKWARD — "change or read THIS existing subscription's plan". Resolves
 * through the SUBSCRIPTION's own bound processor (02 §2.5's new column), not
 * the DP's current one. This is the exact fix DirectPaymentSubscriptionController
 * ::update() needs: today it reads $tier->processor_tier_id straight off the
 * tier row, which is correct only until someone reprices for a new processor.
 */
public function priceForExistingSubscription(DirectPaymentSubscription $link): TierBinding
{
    return TierBinding::where('tier_reference_num', $link->direct_payment_tier_reference_num)
        ->where('processor_reference_num', $link->processor_reference_num)   // the subscription's OWN processor
        ->firstOrFail();      // DP_E_TIER_BINDING_MISSING — see §7.5's backfill guarantee
}
```

`update()` changes one line:

```php
// BEFORE
if (!$tier || !$tier->processor_tier_id) { … 404 … }
… $this->actionsFor($processor->provider)->update($processor, $subscription, $tier->processor_tier_id, $proration);

// AFTER
$binding = $this->tierBindings->priceForExistingSubscription($subscription);
… $this->actionsFor($processor->provider)->update($processor, $subscription, $binding->processor_tier_id, $proration);
```

The `$processor` resolution above it — from `$subscription->processor_reference_num` — is
already correct once [02](02-payers-and-bindings.md) §2.5's column lands. This is its
missing twin: the account and the price must be resolved from the **same** object, or the
right account gets sent the wrong provider's price id, which is worse than sending it to the
wrong account outright — it can silently match a coincidentally similar id.

## 7.4 What the swap preview adds

A `SUBSCRIPTION`-type Direct Payment's swap preview
([03](03-the-swap.md) §3.4) gains a row, because new sales stop working the moment the swap
completes unless pricing exists on the new processor:

```json
"consequences": [
  "3 of 3 tiers have no price configured on PayPal Live yet. New subscriptions cannot be sold until you add one.",
  "12 live subscriptions keep billing — and can still be upgraded or downgraded — on Main Stripe."
]
```

**Not a blocker.** A tenant may deliberately swap and configure pricing afterward, or may be
migrating a `SINGLE_PAY` Direct Payment that has no tiers at all. It is a loud warning in the
same list as the saved-card warning, because both describe the same shape of gap: something
that worked yesterday needs one more step before it works again tomorrow.

The admin's tier editor, once a swap has happened, shows each tier's binding status per
processor — *Priced on Main Stripe · Not priced on PayPal Live* — with an inline field to add
the new processor's price id, which is exactly the workflow a tenant already uses to set a
price the first time.

## 7.5 Migration and backfill

Ordered inside [06](06-rollout-and-testing.md)'s DP1, alongside D2's subscription backfill,
for the identical reason: **the inference below is correct only while no Direct Payment has
ever been re-pointed.**

1. Fix D9 first (§7.2) — the upsert ships before anything reads tier identity as stable.
2. Create `tier_bindings`.
3. For every existing tier, create one `active` binding: `(tier.reference_num,
   tier.direct_payment's current processor, tier.processor_tier_id)`. This is safe today for
   the same reason D2's subscription backfill is safe today — every tier's current processor
   *is* the only processor it has ever had.
4. `processor_tier_id` on `laravel_direct_payment_tiers` becomes a derived, write-through
   column for one release ([02](02-payers-and-bindings.md) §2.4's shape), then drops
   ([06](06-rollout-and-testing.md) §6.2).

`directpayments:tier-binding-drift` reports any tier whose column disagrees with its active
binding, on the same schedule as `directpayments:binding-drift`.

## 7.6 Acceptance

- Saving a Direct Payment's tier list twice in a row, with no other change, leaves every
  tier's `reference_num` unchanged.
- A tier reference in the save payload that does not belong to the Direct Payment →
  `422 DP_E_UNKNOWN_TIER_REFERENCE`; nothing is written.
- Removing a tier from the payload deletes it; a subscription pinned to it keeps resolving its
  entitlement level through `MembershipTierResolver`'s existing fallback.
- After a swap, `update()` on a **pre-swap** subscription sends the **old** processor's price
  id to the **old** processor — the test that would have caught D10, mirroring
  [06](06-rollout-and-testing.md) §6.4's `SubscriptionAfterSwapTest` exactly, for pricing
  instead of routing.
- A **new** subscription created after a swap uses the **new** processor's price id, resolved
  through `priceFor()`, never through a tier's raw column.
- A tier with no binding on the active processor refuses a new subscription with
  `DP_E_TIER_NOT_PRICED_ON_PROCESSOR`, naming the tier.
- The swap preview for a `SUBSCRIPTION` Direct Payment reports how many tiers are unpriced on
  the target, and does not block on it.
- `directpayments:tier-binding-drift` reports zero after the backfill.
