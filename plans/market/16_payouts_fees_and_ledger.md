# Step 16 — Payouts, the 10% fee, and the seller ledger

**On completion:** write `complete/16_payouts_fees_and_ledger_complete.md`.
**Depends on:** [08](08_stripe_connect_brain_api.md), [13](13_market_cart_and_checkout.md), [15](15_freelance_order_lifecycle.md).
**Blocks:** 19.

---

## 16.1 Who owns which number

The one question that must not be ambiguous, because two systems computing the same fee is how a
ledger drifts.

| Number | Owner | Consumers |
|---|---|---|
| List price, tax rate | **market** (`assets` / `service_packages`) | members (display), brain (charge amount) |
| `platform_fee_percent` | **brain's processor record**; market snapshots it onto the listing at publish | brain (validates), market (computes from the snapshot) |
| Fee amount, seller net | **market**, computed at order creation | brain (applies as `application_fee_amount` / transfer amount) |
| Charge, transfer, payout state | **brain / Stripe** | market (mirrors into the ledger) |
| Seller balance | **market**, from the ledger | members (display) |

Market computes; brain applies; brain reports back; market reconciles. Brain **re-validates**
market's fee (step 08's invariant 3) rather than trusting it — the two must agree, and disagreement
is a loud error rather than a silent overwrite.

**The percentage has exactly one home:** the processor record in brain's database, configured in the
Processor dashboard (step 08 §08.3, step 02 §02.4.2). Market fetches it when a listing is published
and snapshots it onto the listing row, so a seller's agreed rate survives a later change.

No cross-project drift check is needed: with one home and a snapshot there is nothing to drift, and
a check that can never fire only teaches people to ignore checks.

---

## 16.2 The arithmetic

Step 08 §08.5 defines it once. Restated for the ledger's purposes:

```
line_net     = unit_price × quantity            (tax excluded)
platform_fee = floor(line_net × fee_pct / 100)  (minor units)
seller_net   = line_net − platform_fee
```

- Integer minor units. `bcmath` or integer cents. **Never floats.**
- `floor` on the fee ⇒ sub-cent remainders favour the seller, and `fee + net == line_net` exactly,
  always. An invariant test asserts this over every price from 1 to 100000 cents.
- Tax is never in the fee base.
- The rate comes from the **row**, not config, so a seller's agreed terms survive a config change.

---

## 16.3 The ledger

`seller_ledger_entries` is append-only. Corrections are new entries, never updates. An updatable
financial ledger cannot be audited.

| Type | Sign | When |
|---|---|---|
| `sale` | + | Order paid (assets) / milestone or order completed (services) |
| `platform_fee` | − | Alongside every `sale` |
| `refund` | − | Refund issued |
| `fee_refund` | + | Platform fee returned on a refund |
| `chargeback` | − | Dispute lost |
| `payout` | − | Funds sent to the seller's bank |
| `payout_reversal` | + | Payout failed |
| `adjustment` | ± | Manual, admin-only, mandatory memo |

Each carries `balance_after` (denormalized, verified by a periodic recompute), `available_at`, and
`external_reference` (the Stripe id).

**Availability.** Asset sales become available after a clearing period (default 7 days, configurable)
— the refund window. Service earnings become available on completion or milestone approval.
Balance is therefore two numbers, both shown: **pending** and **available**. Showing only one is
how a seller comes to believe they have money they cannot withdraw yet.

---

## 16.4 Payouts

Stripe Connect handles the mechanics. Market records intent and mirrors state.

- **Automatic** (default): Stripe's schedule on the connected account. Market records `payout.*`
  webhooks into `payouts` and the ledger.
- **Manual**: seller requests from members (step 10); market validates available balance, creates a
  `payouts` row, asks brain to trigger it, and ledgers on `payout.paid`.
- Minimum payout threshold, configurable, default $20 — below it the transfer fees dominate.
- Failure (`payout.failed`): `payout_reversal` entry, seller notified with Stripe's reason,
  payouts paused until resolved.

---

## 16.5 Refunds

> **Mechanics live in [step 08](08_stripe_connect_brain_api.md)** — `refund_application_fee` and
> `reverse_transfer` are both required on a destination charge, and omitting either loses money in a
> different direction. This section covers only the ledger consequences. `chargeback` below means a
> **card** chargeback; buyer-vs-seller disputes are step 15's `order_disputes`.

| Trigger | Effect |
|---|---|
| Buyer refund request (assets) | Admin review. If granted: refund charge, reverse transfer, refund application fee, `refund` + `fee_refund` entries, **entitlement revoked** |
| Service cancellation | Per §15.7 resolution: full, partial, or none |
| Chargeback | Platform absorbs, reverses transfer, `chargeback` entry (which can drive a balance negative — the ledger must allow it, and the UI must show it honestly) |
| Partial refund | Fee refunded proportionally, on the same `floor` rule |

Revoking an entitlement is a `revoked_at` write, not a delete. The buyer's portal shows the item as
refunded and the download returns `403`. The purchase history stays intact — deleting a refunded
purchase erases the record of a transaction that genuinely happened.

---

## 16.6 Reporting

**Seller** (members, via market): balance (pending + available), earnings by period, per-listing
performance, fee summary, payout history, CSV export, annual summary.

**Platform admin** (market): GMV, fee revenue, refund rate, payout volume, sellers with negative
balances, unreconciled transfers, disputes by outcome.

**Buyer** (portal): receipts and refunds only. **Never the fee split.** They paid list price; the
platform's cut is not disclosed to them.

---

## 16.7 Reconciliation

A daily command, and the reason it exists: webhooks get missed, transfers fail silently, and the
first symptom is usually a seller asking where their money is.

1. Every `paid` order has ledger entries. Missing ⇒ alert.
2. Every ledger `sale` has a matching Stripe transfer. Missing ⇒ alert.
3. `SUM(entries)` per seller equals the latest `balance_after`. Drift ⇒ alert with the entry range.
4. `ProcessorTransferIntent` rows `pending` > 24h ⇒ re-check against Stripe.
5. Market's `platform_fee_percent` equals brain's configured value ⇒ else alert.
6. Stripe's connected-account balance is within tolerance of market's computed available balance.

Output goes to an admin report and to logs. Never auto-corrects — an automated fix to a financial
discrepancy that nobody looked at is how a small bug becomes a large one.

---

## 16.8 The members callback

Step 04's `POST /events/sale` and friends. On each sale, market tells members: seller ref, item,
gross, fee, net, and the new balance. Members raises a notification and updates its displayed
figures.

**Members caches nothing.** The callback is a *signal to refresh*, not a data feed. Steps 01 (D1)
and 04 both depend on this staying true.

---

## 16.9 Files

> **Tagging (step 01 §01.4) — every file below.** Created files carry
> `MARKET_MODIFIER_ADDITION` in their top doc block; modified files carry it at each changed line or
> region. `grep -rn MARKET_MODIFIER_ADDITION` must return this list in full, or the change index is
> already wrong.


**Created (market):** `app/Services/Ledger/{LedgerService,BalanceCalculator,FeeCalculator}.php`,
`app/Services/Payouts/PayoutService.php`,
`app/Http/Controllers/Internal/{LedgerController,PayoutController}.php`,
`app/Console/Commands/{ReconcileLedger,ReleasePendingEarnings,ProcessScheduledPayouts}.php`,
`app/Models/{SellerLedgerEntry,Payout}.php` + migrations,
admin reporting views.

**Modified (market):** `OrderFulfilmentService` (ledger writes), `ServiceOrderStateMachine`
(completion and milestone releases), `config/market.php` (clearing period, minimum payout).

**Modified (brain):** `ProcessorWebhookController` (forwards payout/transfer events to market),
`TransferExecutor`.

**Modified (members):** `EarningsView.ts`, the payouts template.

---

## 16.10 Verification

- [ ] A $40 sale writes `sale +4000`, `platform_fee −400`; balance nets $36.00.
- [ ] Fee invariant test: for every price 1..100000 cents, `fee + net == line_net` and `fee == floor(net_price × pct / 100)`.
- [ ] Earnings show pending before the clearing period and available after.
- [ ] Full refund: charge refunded, transfer reversed, fee returned, entitlement revoked, download `403`, purchase still visible as refunded.
- [ ] Partial refund refunds the fee proportionally.
- [ ] A chargeback can drive a balance negative and the UI states it plainly.
- [ ] Manual payout below the minimum is refused with the threshold named.
- [ ] Reconciliation detects a deliberately deleted ledger entry and a deliberately mismatched fee percent.
- [ ] Buyer receipts contain no fee information anywhere in the response body.
- [ ] Milestone approval ledgers exactly that milestone's amount.
- [ ] Every file created or modified by this step carries `MARKET_MODIFIER_ADDITION` (§01.4). Verify by `grep -rn`, and reconcile the result against this step's Files section — a file in the list without a tag, or a tagged file not in the list, are both defects.
- [ ] `complete/16_payouts_fees_and_ledger_complete.md` written.
