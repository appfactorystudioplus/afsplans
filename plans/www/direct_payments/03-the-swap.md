# 03 — The Swap

The operation itself: what a tenant is actually asking for, what it costs, and the two things
it cannot buy them.

## 3.1 Three swaps, three very different costs

"Change the provider" is three operations that a tenant will not distinguish and the system
must.

| # | Swap | Customers | Saved cards | Live subscriptions | History |
|---|---|---|---|---|---|
| **A** | **Same account, new API keys** (rotated secret, restored access) | unchanged | unchanged | unchanged | unchanged |
| **B** | **Same provider, different account** — Stripe A → Stripe B | re-created lazily | **lost**, unless Stripe migrates the vault (§3.5) | **cannot move** (§3.6) | unchanged |
| **C** | **Different provider** — Stripe → PayPal | re-created lazily | **lost**, permanently | **cannot move** (§3.6) | unchanged |

**A is not a swap at all** and must not be routed through this machinery. Rotating a key edits
the processor record; the binding, the payers and the customers are all still correct. The
admin distinguishes them by asking whether the processor *record* changes or its *credentials*
do — and the preview in §3.4 says "no changes" for A, which is how a tenant learns they picked
the wrong action.

**B and C differ only in whether the card vault can be moved by human arrangement.** Everything
this plan does is identical for both.

## 3.2 The Direct Payment keeps its reference (P1)

The alternative — create a new Direct Payment on the new processor and re-point the store at
it — is rejected, because the Direct Payment reference is the thing every implementer stores.
Changing it would mean:

- rewriting `stores.direct_payment`, every event's and appointment's
  `direct_payment_reference_num`, and every historical order's
  `direct_payment_reference_num`;
- or leaving history pointing at a Direct Payment the tenant considers dead.

Both are the disruption this plan exists to remove. **So the DP is re-pointed and its
reference never changes**, exactly as a payer's reference never changes. The pattern is the
same one level up.

## 3.3 `saveDirectPayment()` stops accepting a processor change (P8, D1)

```php
/**
 * AFS_BILLING_MODIFIER_ADDITION — plans/www/direct_payments/03-the-swap.md §3.3 (D1).
 *
 * A processor change stops being an ordinary field. `saveDirectPayment()` may
 * still SET one when creating a Direct Payment that has no binding yet; it may
 * never CHANGE one.
 *
 * The refusal names the operation that does it, because an operator whose save
 * silently did nothing is worse off than one who is told where to go.
 */
$binding = $this->bindings->active($ref);

if ($binding !== null && $procRef !== '' && $procRef !== $binding->processor_reference_num) {
    return response()->json([
        'error' => 'A live Direct Payment cannot change processor here. Use Change processor, '
                 . 'which shows what the change affects before it happens.',
        'code'  => 'DP_E_PROCESSOR_CHANGE_REQUIRES_SWAP',
        'current_processor' => $binding->processor_reference_num,
    ], 409);
}
```

The swap gets its own endpoints:

| Method | Path | Purpose |
|---|---|---|
| `POST` | `/portal/api/v1/directpayments/{dpRef}/swap/preview` | `{processor_reference_num}` → the impact report (§3.4). **Writes nothing** |
| `POST` | `/portal/api/v1/directpayments/{dpRef}/swap` | `{processor_reference_num, note, acknowledged}` → performs it |
| `GET` | `/portal/api/v1/directpayments/{dpRef}/bindings` | The binding history, with each one's outstanding obligations |
| `POST` | `/portal/api/v1/directpayments/{dpRef}/bindings/{id}/retire` | Close out a `retiring` binding (§3.7) |

`acknowledged` must echo the preview's `impact_hash`. A tenant cannot confirm a swap whose
consequences they were not shown, and a preview that is stale — a subscription started since —
fails with `DP_E_IMPACT_CHANGED` and re-previews.

## 3.4 The preview

Everything a tenant needs, from queries that already have indexes:

```json
{
  "kind": "different_provider",
  "from": { "processor_reference_num": "pr-1234", "provider": "stripe", "mode": "live", "name": "Main Stripe" },
  "to":   { "processor_reference_num": "pr-9xyz", "provider": "paypal", "mode": "live", "name": "PayPal Live" },
  "impact": {
    "payers": 1204,
    "payers_with_saved_cards": 38,
    "live_subscriptions": 12,
    "subscriptions_next_charge_within_7_days": 3,
    "open_invoices": 2,
    "charges_last_90_days": 486,
    "tiers_total": 3,
    "tiers_unpriced_on_target": 3,
    "history_affected": 0
  },
  "consequences": [
    "38 customers will need to enter payment details again. Saved cards cannot move to another account.",
    "12 live subscriptions keep billing on Main Stripe until each one is re-authorised or ends. They will not move.",
    "3 of 3 tiers have no price configured on PayPal Live yet. New subscriptions cannot be sold until you add one — see 07 §7.4.",
    "2 invoices are open and not yet paid. They will be charged on Main Stripe if completed, or can be voided.",
    "All past orders, receipts and refunds are unaffected."
  ],
  "blocking": [],
  "impact_hash": "9c1f…"
}
```

`blocking` is non-empty when the swap must not proceed at all:

| Blocker | Why |
|---|---|
| The target processor is inactive, or has no usable credentials | A swap into an account that cannot charge is an outage |
| The target is already the active binding | Nothing to do; likely they meant a key rotation (§3.1 A) |
| `pay_type` is `CONNECT` and the target has no connected-account configuration | A marketplace Direct Payment mid-swap with no payout destination |
| Currency mismatch between the two processors, where the tenant's DP items are priced in the old one | The same `EC_E_CURRENCY_MISMATCH` reasoning ecommerce already applies at store level |

**Unpriced tiers are a warning, not a blocker** — [07](07-tier-bindings.md) §7.4 explains why:
a `SINGLE_PAY` Direct Payment has no tiers to price, and a tenant migrating a `SUBSCRIPTION`
one may deliberately configure the new pricing after the swap rather than before it.

## 3.5 Saved cards do not move (P6)

**A card token belongs to the provider account that vaulted it.** `pm_…` on Stripe account A
is not addressable from Stripe account B, and has no meaning at all to PayPal. This is not a
limitation of the estate; it is how card vaulting and PCI scope work.

| Swap | What is possible |
|---|---|
| **A** — same account | Nothing to do. The cards are still there |
| **B** — Stripe → Stripe | Stripe will migrate vaulted card data between its own accounts. It is a **manual, compliance-reviewed request to Stripe**, arranged by the tenant, not something this application can trigger. Where a tenant has done it, the new customer ids differ and §3.8 covers importing the mapping |
| **C** — different provider | **Impossible.** Network tokens and provider vaults do not transfer |

So the plan's behaviour is:

- The preview says how many payers lose a card, in that language.
- After a swap, `GET /me/payment-methods` returns the cards on the **active** binding only,
  which for a fresh binding is none. Cards on a retiring binding are not listed as usable
  ([`../connections/07`](../connections/07-direct-payments-connection.md) §7.5).
- The next checkout for that payer collects a card normally. No error, no dead end — the
  saved-card row simply is not offered.
- **Subscriptions are the exception that makes this urgent**, not an inconvenience: a
  subscription on the retiring binding still needs its card, which is why that binding keeps
  working (§3.6).

## 3.6 Live subscriptions do not move — they dual-run (P6, P7)

A recurring mandate is an agreement between the customer and the provider. It cannot be
handed to a different account, let alone a different company. The options are to keep it where
it is, or to end it and ask the customer to authorise a new one — and killing revenue on swap
day is not an option.

**So a swap does not touch existing subscriptions.** The old binding enters `retiring`:

| | Retiring binding | Active binding |
|---|---|---|
| New charges | ❌ | ✅ |
| New subscriptions | ❌ | ✅ |
| Existing subscriptions keep billing | ✅ | ✅ |
| Cancel / update / change card on an existing subscription | ✅ | ✅ |
| Refunds on its charges | ✅ | ✅ |
| Webhooks accepted | ✅ (P7) | ✅ |

Because subscriptions now carry their own `processor_reference_num`
([02](02-payers-and-bindings.md) §2.5), every one of those operations resolves the right
account from the subscription itself, and the DP's current binding is never consulted for an
object that already exists ([04](04-history-refunds-and-webhooks.md) §4.1). **That column is
what makes dual-running work**, and its absence is why dual-running is impossible today.

### Moving a subscriber across, when the tenant wants to

Not automatic, and not silent:

1. `direct_payments:subscription-migrate {dpRef} --dry-run` lists subscriptions on retiring bindings
   with their next charge date and amount.
2. For each, the flow is **create the new subscription first, then cancel the old** — never
   the reverse, so a failure leaves the customer subscribed rather than not.
3. The customer must supply payment details at the new provider. There is no way around this;
   it is the mandate. The tenant's options are a "your payment details need updating" email
   with a link to a hosted setup, or waiting for the subscription to end naturally.
4. `access_ends_at` on the old row protects the member throughout
   ([`28`](28-subscription-actions.md) §14.3's separation of
   billing from access), so nobody loses entitlement mid-migration.

## 3.7 Retiring a binding

A binding leaves `retiring` only when it has nothing outstanding:

```php
/**
 * AFS_BILLING_MODIFIER_ADDITION — plans/www/direct_payments/03-the-swap.md §3.7.
 *
 * Refuses while anything is still live on this processor. Retiring early is how
 * a tenant stops receiving webhooks for their own outstanding money (01 D7).
 */
public function canRetire(Binding $binding): array   // list of blockers, empty when retirable
{
    return array_filter([
        $this->liveSubscriptions($binding)  ? 'subscriptions still billing' : null,
        $this->openInvoices($binding)       ? 'invoices not settled' : null,
        $this->refundableWindow($binding)   ? 'charges still inside the refund window' : null,
        $this->openDisputes($binding)       ? 'open disputes' : null,
    ]);
}
```

The refund window matters and is easy to forget: a card dispute can arrive months after a
charge. The default is **180 days from the last charge**, configurable per binding, and the
admin shows the date rather than a countdown so an operator can plan.

A `retired` binding keeps its row, keeps its `payer_customers`, and keeps answering history
queries forever. Retiring stops the tenant *expecting* webhooks from it; it does not delete
anything.

## 3.8 Importing a migrated vault (Stripe → Stripe)

Where a tenant has arranged a Stripe vault migration (§3.5 B), the new account has the same
cards under **new** customer ids. Direct Payments can adopt them rather than making everyone
re-enter a card:

```bash
php artisan direct_payments:import-customer-map {dpRef} {csv} [--dry-run]
```

The CSV is `old_provider_customer_id,new_provider_customer_id`, which is what Stripe's
migration produces. For each row: find the `payer_customers` row for the old id on the
retiring binding, and create an `active` row for the same payer on the new binding with the
new id. Unmatched rows are reported and skipped; a new id already claimed by another payer is
reported and skipped, never reassigned.

**Dry run first, always.** This writes the mapping that decides whose card charges whose
order.

## 3.9 Acceptance

- `saveDirectPayment()` with a different `processor_reference_num` on a Direct Payment that
  already has a binding → `409 DP_E_PROCESSOR_CHANGE_REQUIRES_SWAP`.
- The preview writes nothing, and its counts match a hand-run query on the same data.
- A swap confirmed with a stale `impact_hash` → `DP_E_IMPACT_CHANGED`.
- After a swap: the DP's `reference_num` is unchanged; the old binding is `retiring`; exactly
  one binding is `active`.
- A returning payer's next checkout creates one customer on the new processor, links it to
  their existing payer reference, and charges it.
- **A subscription taken on the old processor still cancels correctly after the swap** — the
  single test that would have failed catastrophically before D2 was fixed.
- A refund on a charge from before the swap succeeds, against the old account.
- Retiring a binding with a live subscription → refused, naming the blocker.
- `import-customer-map --dry-run` reports matched, unmatched and already-claimed, and writes
  nothing.
- A key rotation (§3.1 A) does not go through the swap path, and the preview tells a tenant
  who tries so.
