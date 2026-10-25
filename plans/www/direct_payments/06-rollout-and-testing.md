# 06 — Rollout and Testing

## 6.1 Phases

Seven phases, **DP0–DP6**. The ordering constraint that matters is stated first because
getting it wrong is unrecoverable.

> ### The hard gate
>
> **DP1 must ship before DP5, and that now covers two backfills, not one.**
> `laravel_direct_payment_subscriptions` gains its `processor_reference_num` in DP1,
> backfilled by inferring each subscription's processor from its Direct Payment.
> `laravel_direct_payment_tier_bindings` ([07](07-tier-bindings.md) §7.5) gets the same
> treatment for the same reason, in the same phase: each tier's price is backfilled as an
> `active` binding to whichever processor it currently has.
>
> Both inferences are correct **only while no Direct Payment has ever been re-pointed**, which
> is true today and false the moment the swap feature exists.
>
> Ship the swap first and both backfills are a guess. There is no later repair: nothing else
> records which account a subscription was taken on, or which account a tier's price belongs
> to.

| Phase | Contents | Depends on | Ships behind |
|---|---|---|---|
| **DP0 — stop the silent swap** | `saveDirectPayment()` refuses a processor change on a Direct Payment that already has one, with `DP_E_PROCESSOR_CHANGE_REQUIRES_SWAP` ([03](03-the-swap.md) §3.3). No schema | **nothing** | Removes a footgun no tenant should have had. A tenant who genuinely needs a swap has no way to do one yet — which is better than the current way |
| **DP1 — snapshot the processor** | `subscriptions.processor_reference_num` + backfill; `invoices.payer_reference_num`; `subscriptions.payer_reference_num`; the tier-identity upsert fix (**D9**) and `laravel_direct_payment_tier_bindings` + backfill (**D10** — [07](07-tier-bindings.md)). Read paths still resolve as they do today | DP0 (ordering) | Pure addition. **The gate above** |
| **DP2 — payers** | `laravel_direct_payment_payers`, `laravel_direct_payment_payer_customers`, `PayerService`, `customerFor()` ([02](02-payers-and-bindings.md)). Checkout resolves a payer and records it. The provider customer is still created exactly as today | DP1 | Payers are written and not yet read by anything that decides behaviour |
| **DP3 — bindings** | `laravel_direct_payment_processor_bindings` + backfill from the existing column; `BindingRegistry`; every forward resolution re-pointed at it; the column becomes a derived write-through cache | DP2 | One active binding per DP, identical to the column it replaces |
| **DP4 — the invariant** | Refunds resolve from the charge (**D5**); subscription actions resolve their **account** from the subscription and their **price** from the tier binding (**D2**, **D10** — [07](07-tier-bindings.md) §7.3); webhooks accept retiring bindings and record orphans (**D7**) ([04](04-history-refunds-and-webhooks.md)) | DP1, DP3 | **Fixes four live bugs on its own**, before any swap exists. D5 and D9 are both reachable today with no swap involved |
| **DP5 — the swap** | Preview (now including the unpriced-tier warning, [07](07-tier-bindings.md) §7.4), `impact_hash`, the swap endpoint, binding retirement with blockers, `import-customer-map`, the admin surface ([03](03-the-swap.md)) | DP1 **(the gate)**, DP3, DP4 | The first phase a tenant can use. Nothing changes until they do |
| **DP6 — fold in the consumers** | `payer_reference_num` on ecommerce / events / appointments tables; the SDK's payer resolver; the Site Users link fold ([05](05-implementer-contract.md)) | DP2 | Additive per module; each can land on its own schedule |

**DP0 and DP4 are worth shipping regardless of whether a swap is ever built.** DP0 closes a
one-field footgun; DP4 fixes four defects that exist today — three of them (**D2**, **D5**,
**D7**) only visible once a swap has happened, and one (**D9**) visible on the very next
ordinary tier edit.

## 6.2 Deprecating `laravel_direct_payments.processor_reference_num`

Add → migrate → deprecate → remove, the shape used for the connections plan's mode columns
([`../connections/03`](../connections/03-database-schema.md) §3.5).

| Step | Phase | State |
|---|---|---|
| Bindings created and backfilled | DP3 | Both exist; the column is authoritative |
| `BindingRegistry` becomes the only reader; the column is written through on every binding change | DP3 | Both agree; a stale reader still sees the truth |
| `directpayments:binding-drift` reports any DP where the two disagree; must be zero for a release | DP5 | Gate |
| Column dropped | after DP6 | `grep -rn "processor_reference_num" app/` shows only snapshots and the processor table itself |

The write-through is what makes DP3 rollback-safe: revert the code and the column is still
current.

`laravel_direct_payment_tiers.processor_tier_id` follows the identical shape, one phase
earlier — created and backfilled in DP1, write-through from DP1 onward, gated by
`directpayments:tier-binding-drift` at DP5, dropped after DP6
([07](07-tier-bindings.md) §7.5).

## 6.3 What the plan deliberately does not migrate

- **Existing provider customers are not bulk-imported into `payer_customers`** at DP2 for
  guest checkouts. Only the Site Users link rows are folded ([05](05-implementer-contract.md)
  §5.5), because those have an identity attached. A guest's historical provider customer stays
  reachable through the invoice's `customer_id` snapshot, which is what history needs, and the
  payer is created the next time they buy.
- **No provider is called during any migration.** Every backfill reads rows this deployment
  already has. A migration that calls Stripe is a migration that fails halfway on a timeout.

## 6.4 Test matrix

| Phase | File | Asserts |
|---|---|---|
| DP0 | `tests/Feature/DirectPayments/ProcessorChangeGuardTest.php` | `saveDirectPayment()` with a different processor on a bound DP → `409`; on an unbound DP → sets it; same processor → no-op |
| DP1 | `tests/Feature/DirectPayments/SubscriptionProcessorBackfillTest.php` | every subscription gets its DP's processor; a subscription whose DP is missing is reported, not silently null |
| **DP1** | `tests/Feature/DirectPayments/TierIdentityUpsertTest.php` | **the test that would have caught D9** — saving a Direct Payment's tier list twice with no change leaves every `reference_num` identical; a save with an unknown reference in the payload → `422`, nothing written; a tier dropped from the payload is deleted and its orphaned subscriptions still resolve entitlement through `MembershipTierResolver`'s fallback |
| DP1 | `tests/Feature/DirectPayments/TierBindingBackfillTest.php` | every existing tier gets one `active` binding to its current processor, carrying its current `processor_tier_id` |
| DP2 | `tests/Unit/DirectPayments/PayerResolutionTest.php` | a guest email reuses its payer within a site and not across sites; a signed-in user **never** adopts a guest payer on an email match; a payer with no email is always fresh; `customerFor()` creates once and reuses |
| DP2 | `tests/Unit/DirectPayments/PayerCustomerUniquenessTest.php` | one active customer per (payer, processor, mode); superseding frees the slot; one provider customer maps to at most one payer |
| DP3 | `tests/Feature/DirectPayments/BindingRegistryTest.php` | exactly one active binding per DP, enforced by the index; the derived column matches; drift reports zero |
| **DP4** | `tests/Feature/DirectPayments/RefundAfterSwapTest.php` | **the test that would have caught D5** — a refund on a pre-swap charge succeeds against the old account, whether the request names the new processor, the old one, or neither; a deleted origin processor → `DP_E_ORIGIN_PROCESSOR_MISSING` |
| **DP4** | `tests/Feature/DirectPayments/SubscriptionAfterSwapTest.php` | **the test that would have caught D2** — cancelling a pre-swap subscription calls the **old** provider; the fake provider asserts which account it was called as |
| **DP4** | `tests/Feature/DirectPayments/SubscriptionUpgradeAfterSwapTest.php` | **the test that would have caught D10** — upgrading a pre-swap subscription sends the price id bound to the subscription's **own** processor, even after the tenant has repriced every tier for the new one; the fake provider asserts both which account it was called as and which price id it received |
| DP4 | `tests/Feature/Processor/WebhookRetiringBindingTest.php` | a webhook from a retiring binding's processor is attributed; one matching nothing creates an orphan row |
| DP5 | `tests/Feature/DirectPayments/SwapTest.php` | preview writes nothing and counts correctly, including the unpriced-tier count; a stale `impact_hash` → `DP_E_IMPACT_CHANGED`; after a swap the DP reference is unchanged and exactly one binding is active; a returning payer's next checkout creates one customer on the new processor; retiring with a live subscription is refused; a new subscription on an unpriced tier → `DP_E_TIER_NOT_PRICED_ON_PROCESSOR` |
| DP5 | `tests/Feature/DirectPayments/ImportCustomerMapTest.php` | `--dry-run` writes nothing; unmatched and already-claimed rows are reported and skipped |
| DP6 | `tests/Feature/SiteUsers/PayerLinkFoldTest.php` | every existing customer link becomes one payer plus one `payer_customers` row; no provider call; `grep` for `provider_customer_id` in Site Users is empty |

### The three tests that justify the plan

`RefundAfterSwapTest`, `SubscriptionAfterSwapTest` and `SubscriptionUpgradeAfterSwapTest` all
fail today, before any swap feature exists, if you simulate a re-pointed Direct Payment —
which `saveDirectPayment()` lets you do in one call. They are the plan's argument in
executable form, and they should be written first.

The provider is faked at the HTTP boundary and **asserts which account, and for the third
test which price, it was called with**. A fake that only checks the call happened would pass
while the money went to the wrong place or the wrong plan, which is the exact failure D2 and
D10 describe.

`TierIdentityUpsertTest` justifies DP1 on its own, with no swap in it anywhere: it fails
today, against the code as it exists right now, by saving an unrelated field on a Direct
Payment and observing every tier's reference change underneath it.

## 6.5 Grep gates

```bash
# 1. No implementer holds a provider-issued customer id.
grep -rn "cus_\|provider_customer_id" app/Externals/Ecommerce app/Events app/Externals/Appointments

# 2. Site Users holds no provider identifier (after DP6).
grep -rn "provider_customer_id" app/SiteUsers app/Models/Externals/SiteUsers

# 3. §4.1's invariant: nothing resolves a BACKWARD question forward. Any hit is
#    reviewed by hand — a method that takes an invoice, charge or subscription
#    reference must not consult the DP's active binding.
grep -rn "BindingRegistry::active\|bindings->active" app/ \
  | grep -iE "refund|cancel|subscription|invoice|charge"

# 4. The derived column has no readers left (before it is dropped).
grep -rn "direct_payments.*processor_reference_num\|->processor_reference_num" app/ \
  | grep -v "Snapshot\|charges\|invoices\|subscriptions\|bindings\|Processor::"

# 5. D10's twin of gate 3: nothing resolves an EXISTING subscription's price
#    from the tier's own current column instead of from its own tier binding.
grep -rn "\->processor_tier_id" app/ \
  | grep -v "TierBinding\|tier_bindings"

# 6. saveDirectPayment() is an upsert, not a destroy-and-recreate (D9).
#    Presence of BOTH a delete and a fresh reference_num generator on the same
#    tier write path is the regression signature — reviewed by hand.
grep -n "DirectPaymentTier::where.*delete()" app/Http/Controllers/Externals/DirectPayments/DirectPaymentsController.php
```

Gates 3 and 5 are review prompts, not emptiness checks: some hits are legitimate (a *new*
subscription, or a *new* price choice on an existing DP, is a forward question). They exist so
the decision is made consciously.

## 6.6 Rollback

| Phase | Rollback | Cost |
|---|---|---|
| DP0 | Revert | Re-opens the silent swap. Do not |
| DP1 | Drop the columns and the tier-binding table; revert the tier upsert to the old delete-and-recreate | The backfills' information is lost, and re-deriving either is only correct while no swap has happened. **Effectively one-way once DP5 ships.** Reverting the tier upsert alone (D9, independent of the rest of DP1) is always safe — it only removes a fix |
| DP2 | Stop writing payers; drop the tables | Payers written so far are lost; provider customers are untouched, so checkout is unaffected |
| DP3 | Revert to the column | Safe — the write-through kept it current (§6.2) |
| DP4 | Revert | Restores four known bugs, one of them (D9) with no swap required to trigger it. Treat as an incident action |
| DP5 | Revert the endpoints; leave the bindings | A tenant mid-swap keeps their retiring binding and their dual-running subscriptions, which is the correct state to be stuck in |
| DP6 | Revert per module | Each module's `payer_reference_num` stops being written; existing values stay valid |

## 6.7 Definition of done

- A Direct Payment can be swapped from Stripe to PayPal, and:
  - the ecommerce store's code and database are byte-identical afterwards except for new rows;
  - a returning shopper's full order history is intact and continuous;
  - their next checkout works, with a new card;
  - a refund on a two-year-old order goes to the Stripe account;
  - a subscription taken last year still cancels at Stripe, and stops billing;
  - upgrading that same old subscription still prices it against Stripe, even after every
    tier has been repriced for PayPal;
  - the tenant was told all of this before they confirmed.
- A Direct Payment's tiers can be re-saved with an unrelated edit, any number of times, and
  no subscription's `direct_payment_tier_reference_num` ever goes stale as a result.
- `php artisan test --filter="DirectPayments|Processor|SiteUsers"` green.
- All six grep gates reviewed, 1, 2, 4 and 5 empty; gate 6 finds nothing.
- `directpayments:binding-drift` and `directpayments:tier-binding-drift` both exit 0.
- The `laravel_processor_webhook_orphans` count is zero on a deployment with no retired
  bindings.

## 6.8 Follow-ups this plan leaves open

| # | Item |
|---|---|
| 1 | ~~Move the remaining Direct Payments documents out of `../../billing/` into this folder.~~ **Done 2026-09-22** — see [README](README.md)'s steps 20–29 |
| 2 | **Per-payer provider preference** — a tenant running two processors at once (a card processor and PayPal side by side) rather than swapping between them. The binding model allows more than one non-retired binding; nothing in this plan routes a charge by preference |
| 3 | **Automatic subscription migration with hosted re-authorisation** ([03](03-the-swap.md) §3.6 step 3). Needs a provider-hosted setup flow per provider |
| 4 | **Payer merge** — two payers that turn out to be one person, most likely a guest payer and an account payer. Deliberately absent: merging is where a saved card moves between people, and it needs its own design |
