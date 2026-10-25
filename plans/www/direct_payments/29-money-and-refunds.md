# 15 — Money and Refunds

The Financials → **Money** section: every money movement in one ledger, refunds for all three
charge shapes, and an aggregate-only projection into afs-connect.

## 15.1 What exists today

The Money section is **already built and has never worked**.

`resources/js/portal/admin/Processors/MoneyView.ts` (202 lines) is a complete transaction
table — provider badges, stat cards, currency formatting — reached from the existing
`Financials → Money` nav item (`main.html`, `data-nav="money"`, registered in
`utils.ts:456` as *"Financials / Transaction History"*).

| # | Finding | Consequence |
|---|---|---|
| **B32** | `MoneyView.loadCharges()` fetches **`GET /portal/api/charges`**. That route **does not exist** in `routes/portal.php` | The fetch fails, the catch calls `setEmpty(true)`, and the section has always rendered its empty state. The UI is finished; nothing is behind it |
| **B33** | `ProcessorBillingController::refund()` resolves a `ProcessorCustomer` and 404s when `$charge->customer_id !== $customer->id` | It cannot refund a charge with no local customer — which is precisely why `refundByPaymentIntent()` had to be written separately for market. **Two refund paths, different auth, and neither is reachable from the admin UI** |
| **B34** | **Refunds are never persisted.** `refundStripe()` calls Stripe and returns `['refund_id' => …, 'status' => …]`. It writes nothing back. There is no refund model, no refund table, and no refund column on `laravel_processor_charges` | A refunded charge shows its **full original amount**, status `succeeded`, forever. Every revenue total in Money would be wrong the moment anyone issues a refund |
| **B35** | Charges are recorded **only** by webhook (`recordStripeCharge()` and siblings) | A charge whose webhook never arrived is invisible to Money — the same class of defect as **B3**, with money instead of dates |
| **B36** | `laravel_processor_charges` has no Connect fields — no connected account, no application fee, no transfer id (at best buried in `metadata` json) | A marketplace split charge is indistinguishable from a platform charge, so Money cannot show **who actually received the money** — which is the first question anyone asks before refunding one |

**B34 is the one that makes this step urgent.** A Money section that silently overstates
revenue is worse than no Money section, because people make decisions from it.

## 15.2 Refunds are records, not mutations

`laravel_processor_refunds` — one row per refund, full or partial. Providers model refunds
this way and so should we: a charge can be refunded several times, and collapsing that into a
single `refunded_amount_cents` column on the charge loses when, by whom and why.

| Column | Type | Notes |
|---|---|---|
| `id` | `ulid` | |
| `processor_reference_num` | `string(7)` index | |
| `charge_id` | `ulid` index | → `laravel_processor_charges.id` |
| `provider` | `string(20)` | |
| `provider_refund_id` | `string(255)` index | The provider's own id |
| `amount_cents` | `unsignedInteger` | The refunded amount, always positive |
| `currency` | `string(10)` | |
| `status` | `string(50)` | `pending` \| `succeeded` \| `failed` \| `canceled` |
| `reason` | `string(255)` null | Operator-entered; free text, shown in the ledger |
| `reversed_transfer` | `boolean` default `false` | Connect — was the seller's transfer clawed back (§15.4) |
| `refunded_application_fee` | `boolean` default `false` | Connect — did the platform give back its fee |
| `initiated_by` | `string(64)` null | The operator who issued it |
| `failure_reason` | `string(500)` null | |
| `refunded_at` | `timestamp` null | |
| `timestamps` | | |

**No cached `refunded_amount_cents` on the charge.** Net revenue is
`SUM(charges.amount_cents) − SUM(refunds.amount_cents WHERE status = 'succeeded')`, computed
from the rows. A cached column is a second source of truth that goes stale exactly when a
refund partially fails — the case it exists to summarise. Index
`(charge_id, status)` so the join is cheap; revisit only if a real dataset says otherwise.

`laravel_processor_charges` also gains the Connect columns it is missing (**B36**):
`connected_account_id`, `application_fee_cents`, `transfer_id`, `is_split`.

Refunds are written from **two** places, and both must write:

1. The admin action (§15.4) — after the provider confirms.
2. `ProcessorWebhookController`'s existing refund handlers — `logStripeRefund()`,
   `logStripeRefundLifecycle()`, `logStripeRefundFailed()`, `logSquareRefund()`,
   `logPayPalRefund()`. These already receive the events and currently only **log** them.
   A refund issued in the provider's own dashboard must appear in Money too, and the webhook
   is the only way it can.

`updateOrCreate` keyed on `provider_refund_id`, so the admin path and the webhook converge on
one row rather than racing to make two.

## 15.3 Backfilling what webhooks missed

**B35** means the charge ledger is only as complete as webhook delivery has been.
`php artisan money:backfill-charges --since=` reads each active processor's charge list from
the provider and `updateOrCreate`s by `charge_id`, then does the same for refunds.

This is a one-shot with a `--dry-run`, not a scheduled job. Continuous reconciliation of
money is a bigger commitment than this plan should make quietly — it needs a decision about
what to do when local and provider disagree, and that decision belongs to whoever owns
finance, not to a scheduler. Report the discrepancy count; do not auto-resolve it.

## 15.4 Refunding the three shapes

One endpoint, three shapes. The **charge** is what gets refunded in every case — a
subscription does not have its own refund concept, its invoice's charge does.

```
POST /portal/api/v1/money/charges/{chargeId}/refund
     { amount_cents?, reason?, reverse_transfer?, refund_application_fee? }
```

Omitting `amount_cents` refunds in full.

| Shape | What differs |
|---|---|
| **Single payment** | Nothing special. Refund the charge |
| **Subscription** | Refund the invoice's charge. **This does not cancel the subscription** — the two are separate operator actions ([28](28-subscription-actions.md) §14.3), and a refund that silently cancelled would be a surprise in the expensive direction |
| **Connected account** | The charge was split. Refunding it raises the question the other two do not: **who bears it?** |

### The Connect question the UI must ask

`refundStripe()` already gets this right, and its own `MARKET_MODIFIER_ADDITION` comment
explains why: on a destination charge, a plain refund does **not** claw back the transfer.
Omit `reverse_transfer` and the seller keeps money the platform just returned to the buyer;
omit `refund_application_fee` and the platform keeps a fee on revenue that no longer exists.
The existing code fetches the charge first and only sends those flags when
`$stripeCharge->transfer` is actually present.

What is missing is that **the operator is never asked.** The refund dialog for a split charge
must state, in money rather than in flag names:

```
Refunding $80.00 to the customer.

  ☑ Reclaim $72.00 from the seller (Acme Studio)
  ☑ Return the $8.00 platform fee

  Unchecked, the platform absorbs $80.00 of this refund.
```

Both default **checked**, because absorbing a refund is the surprising outcome and should be
a deliberate act. The unchecked total is shown live, because "reverse_transfer: false" means
nothing to the person clicking and "the platform absorbs $80.00" means everything.

Square and PayPal have no equivalent split mechanism in this codebase; the dialog shows the
plain form and the flags are inert.

### Refunds are not permission-gated

Any operator who can reach the admin dashboard can issue a refund. The endpoint sits behind
`portal.admin.auth` like the rest of the Financials section and carries **no**
`AdvancedConfigGate` / level-99 restriction.

That is a deliberate decision, not an oversight: refunding is routine customer-service work,
and putting it behind the same gate as changing a deployment's dunning policy would either
block the people who actually handle refunds or push everyone to level 99 — which would
weaken the gate where it does matter ([26](26-advanced-policy.md) §12.8).

**A refund still cannot be undone**, so two things carry the weight the gate is not:

- **The confirm step.** The dialog states the amount, the customer, and — for a split charge
  — who bears it (§15.4). It is a confirmation, not a permission check.
- **`initiated_by` on every refund row** (§15.2). With no gate, the audit trail is the only
  record of who issued what, which makes it *more* important here, not less. It is populated
  from the resolved operator server-side, never from the request body.

## 15.5 The Money section

`MoneyView.ts` keeps its shape and gains the endpoint it has always called (**B32**), now at
`GET /portal/api/v1/money/ledger` with the legacy `/portal/api/charges` path registered as an
alias so the existing fetch keeps working.

The ledger row gains what the user asked for and what refunding needs:

| Column | Source |
|---|---|
| Date | `charged_at` |
| **Provider** | `provider` — already rendered as a badge |
| **Customer** | `customer_email`, linked to the member on the Brain when `laravel_processor_customers` resolves to one. **On a tenant** ([`04`](04-history-refunds-and-webhooks.md) §4.6), the same cell resolves through a Direct Payments **payer** rather than the per-processor customer, so a person's rows stay one continuous name across a provider swap instead of splitting the moment they do |
| Direct Payment | via `laravel_direct_payment_subscriptions` / invoices, when the charge came from one |
| Type | `single` \| `subscription` \| `split` |
| Gross / Refunded / **Net** | charge amount, summed refunds, the difference |
| Status | `succeeded` \| `partially_refunded` \| `refunded` \| `failed` |
| Seller | `connected_account_id`, for split charges (**B36**) |
| Actions | Refund (§15.4), view provider record |

Stat cards become **gross, refunded, net, count** — `updateStats()` currently sums charges
alone, which after §15.2 would overstate revenue by exactly the refunded amount.

This is the **operator** ledger. The member-scoped read of the same tables is
`dp.subscription.history()` ([25](25-direct-payments-api.md) §7.3.2) — a much smaller
projection, scoped server-side to the caller and carrying no charge ids, refund reasons
or `initiated_by`. One data source, two audiences, two projections.

Filters: provider, date range, type, status, Direct Payment, and a `customer_email` search.
These are what turn a transaction list into "financial management"; without them the section
is a log.

## 15.6 afs-connect — aggregates only, and why

The request is explicitly for **analysis in coordinator, not management** — no refunding, no
mutation. That boundary is not just scope discipline; it is what makes the integration
possible at all.

**The index cannot carry this data.** The ecosystem architecture is unambiguous — the
afs-connect index is *"metadata only — titles, counts, timestamps, deep links. Not form
submission bodies, not subscriber emails, not booking details."* And the existing
`www_direct_payments` module already states its own PII boundary: *"No customer emails,
charge IDs, or `items_data` in index."*

The Money section is customer emails, charge ids and amounts. Projecting it row-by-row would
put every transaction the platform has ever processed into a project-management search index.
So the source indexes **period rollups**, never transactions:

| Field | Example |
|---|---|
| `source_app` | `www_money` |
| `source_type` | `www_money_period` |
| `source_id` | `{website_ref}:{YYYY-MM}:{provider}` |
| Metadata | `gross_cents`, `refunded_cents`, `net_cents`, `charge_count`, `refund_count`, `currency` |
| Deep link | The Money section, filtered to that month and provider |

One row per website per month per provider. A member with three sites and two providers
generates six rows a month — trivially indexable, and genuinely useful in coordinator: *"this
site took $4,200 in August across 61 transactions, $180 refunded."* That is a project-planning
fact. The 61 individual transactions are not.

**No customer email, no charge id, no refund reason, no seller account, ever.** A rollup with
a `charge_count` of 1 is a single transaction's amount by inference — an acceptable and
unavoidable edge, and the reason the deep link exists is so anyone who needs the detail
follows it back to Money rather than expecting coordinator to hold it.

### Registering the source

[../../afs-connect/12-source-registry.md](../../afs-connect/12-source-registry.md)'s registry is
**already implemented** — `apps/coordinator/config/afs_connect_sources.php` exists, with
`SourceRegistry.php` reading it and `SourceRegistryConsistencyTest.php` guarding it. So
registration is genuinely one entry, alongside the eight existing `www_*` modules:

```php
'www_money' => [
    'key' => 'www_money', 'label' => 'Money', 'kind' => 'meta',
    'base_url_env' => 'AFS_CONNECT_WWW_BASE_URL', 'group' => 'www',
    'default_on' => false, 'min_tier_index' => 1, 'meter' => 'afs_connect_index_rows',
],
```

The `meter` value matches what the eight existing `www_*` entries already use. Note that
`afs_connect_index_rows` is **not** currently a resource in `checkpoint_registry_v3.json`
([09](../../billing/09-entitlement-integration.md) §9.5 — one of four proposed capacity meters that do not
exist yet), so the field is inert today for every source including this one. Copying the
existing value is still right: when the resource is added, `www_money` starts metering with
its siblings rather than being the one entry nobody remembered to update.

`kind: 'meta'` and the shared `AFS_CONNECT_WWW_BASE_URL` are correct here for the reason the
registry's own comment gives: the eight `www_*` entries front one Brain client, not eight
services. This is a ninth module behind the same client.

**`default_on => false`, unlike every other `www_*` entry.** The others default on because
form submissions and appointments are unsurprising things to see in a project tool. Revenue
is not: a member who enables afs-connect should not discover their financial totals in
coordinator without having chosen that. It is one toggle away, in the surface
[../../afs-connect/](../../afs-connect/) already built for exactly this
(layer 5 — *"what they choose to share"*, as distinct from layer 4's *"what they bought"*).

Ingest follows the established pattern: push on rollup change, pull on the scheduled
reconcile. A rollup changes at most once per charge, so the push volume is bounded by
transaction volume and the reconcile is cheap.

## 15.7 Migrations in this step

| File | Contents |
|---|---|
| `2026_08_25_000012_create_processor_refunds_table.php` | §15.2 |
| `2026_08_25_000013_add_connect_fields_to_processor_charges_table.php` | §15.2, **B36** |

## 15.8 Acceptance

- The Money section **loads** (**B32**). The route exists, and the legacy
  `/portal/api/charges` path still resolves.
- Issuing a refund writes a `laravel_processor_refunds` row, and Money immediately shows
  gross, refunded and **net** — with net, not gross, in the revenue stat card (**B34**).
- A refund issued in the **provider's own dashboard** appears in Money via the existing
  webhook handlers, on the same row rather than a duplicate.
- Refunding a charge with **no local customer** succeeds (**B33**) — the endpoint is
  charge-scoped, not customer-scoped.
- Refunding a **split** charge defaults both Connect flags checked, states the amounts in
  money, and records `reversed_transfer` / `refunded_application_fee` on the refund row.
- Unchecking both and confirming leaves the seller paid and the platform out of pocket —
  and the ledger row says so.
- A refund on a subscription charge **does not** cancel the subscription.
- **Any authenticated admin operator can refund** — no level-99 requirement — and every
  refund row records `initiated_by`, resolved server-side.
- Partial refunds totalling the full amount move the charge to `refunded`, not
  `partially_refunded`.
- `money:backfill-charges --dry-run` reports webhook-missed charges without writing.
- **The afs-connect index contains no `customer_email`, no `charge_id` and no per-transaction
  amount** — assert this against a seeded ledger, because it is the kind of field that gets
  added by a well-meaning `->toArray()`.
- Adding `www_money` touched **one** registry entry plus the provider module, and
  `SourceRegistryConsistencyTest` passes.
- A member with afs-connect enabled sees **no** money rows until they opt the source in
  (`default_on => false`).
