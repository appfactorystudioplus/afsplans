# 00 — Extras (off-plan adjustments)

## What this file is

The numbered `complete/` files ([01](01-current-state-audit.complete.md) …
[14](14-rollout-and-testing.complete.md), plus
[p12](p12-season-passes.complete.md)) are what the events plan **became**.
This file is everything we had to add or fix that **was not a plan step** —
things that would otherwise live only in chat and then get re-discovered.

It does **not** supersede the numbered completes for "how events works
today." Those remain the authority for the API, model, ticketing,
registration, money, and public surface. This file is the authority for
the work that sat **beside** those steps.

**Repo:** `www`. Application code is uncommitted unless a later commit
says otherwise. Paths below are from `w/www`.

**How to add to it.** Append a new `E*` section (next number, dated).
Keep the same shape: symptom / context, cause, what we changed, the
files, and the operational consequence (run the seeder, re-run
migrations, republish, …). Do not fold an extra into a numbered
complete unless the plan itself changed.

---

## Index

| # | Date | What | Why it is not in the plan |
|---|---|---|---|
| [E1](#e1--events-demo-data-seeder) | 2026-09-08 | `EventsDemoSeeder` + `DemoStoreSeeder` regression fix | Plan `14` lists test commands but no demo-data fixture; the ecommerce decoupling broke the sibling seeder |
| [E2](#e2--events--direct-payments-decoupling-facade-orders) | 2026-09-08 | Event orders become façades on `PaymentGateway` | Plans `04`/`08` shipped money columns on `event_orders`/`event_registrations`; the ecommerce decoupling (`0aa2b4dfd`) set the pattern and the events module never followed |
| [E3](#e3--events-builder-integration-four-views) | 2026-09-08 | Full events builder runtime + chrome + 4th page | Builder-v2 `03` shipped events as three empty bound pages with a stub script; parity with the ecommerce builder surface was never done |

---

## E2 — Events ↔ Direct Payments decoupling (façade orders)

**Date.** 2026-09-08. **Repo:** `www`.

**Context.** `events/04` §4.10–4.11 and `events/08` gave `laravel_event_orders`
a full set of money columns (`subtotal_cents`, `discount_cents`, `fee_cents`,
`tax_cents`, `total_cents`, `refunded_cents`) and `laravel_event_registrations`
an `amount_cents` / `refunded_cents` pair. `EventPaymentService::confirmPaid()`
trusted the invoice row status alone — the webhook-lag hole the ecommerce
decoupling (`w/www` `0aa2b4dfd`) had already closed for carts with
`App\Externals\DirectPayments\Contracts\PaymentGateway`. Events never followed.

**What changed.** Events now sits on the same façade model as ecommerce.

- **Migration** `2026_09_09_000000_events_facade_payment_refs.php`: ADD
  `event_orders.direct_payment_reference_num` + `event_orders.processor_reference_num`
  (backfilled from the invoice); DROP the six order money columns +
  `direct_payment_charge_ref`; DROP `event_registrations.amount_cents` +
  `refunded_cents`. Guarded with `hasColumn`; `down()` re-adds nullable.
- **`EventOrderService`** (new) — `finalizeFromSnapshot(order, InvoiceSnapshot)`
  writes the three refs + `status:'paid'` + flips registrations to `confirmed`,
  idempotent; `paymentSnapshot(order)` resolves every figure from
  `PaymentGateway::snapshot()`.
- **`EventPaymentService`** — `createInvoiceForOrder()` still builds the invoice
  server-side inside `register()`'s capacity transaction (unlike ecommerce, which
  moved invoice-building to the client — the events flow is one atomic call and
  splitting it would fracture the capacity claim). It now stamps
  `metadata.facade = 'events'` + `metadata.registration_reference_num`.
  `confirmPaid()` is **removed**.
- **`EventStorefrontController::confirmPayment()`** — gates on
  `PaymentGateway::isPaid($invoiceRef)`, then `finalizeFromSnapshot()`. Recovers a
  thin order by `direct_payment_invoice_ref` then `$snapshot->facadeRef`.
- **`EventsReconcilePayments`** — rewritten like `EcommerceReconcileOrders`:
  `{--minutes=15} {--dry-run}`, scans `pending` orders with an invoice, `isPaid()`
  → `finalizeFromSnapshot()`.
- **`EventReportingService`** — `revenue_cents` from `gateway->snapshots()` →
  `netCents()`; registration exports read the face price from `ticket_snapshot`.
- **`DirectPaymentGateway::facadeRef()`** — added `event_order_reference_num` to
  the metadata key list.
- Serialisers (`EventStorefrontController`, admin `EventController`) read
  `amount_cents` from the immutable `ticket_snapshot.unit_price_cents`.
- **Also fixed in passing** (`EventRegistrationService`): a `free`-kind ticket
  now registers without a Direct Payment item even on a paid event — it was
  rejected `EV_E_ITEM_UNMAPPED`, which made the seeded "Community Pass" / "Free
  RSVP" tickets unregisterable.

**Files.** `database/migrations/2026_09_09_000000_events_facade_payment_refs.php`,
`app/Externals/Events/Services/EventOrderService.php` (new),
`app/Externals/Events/Services/{EventPaymentService,EventRegistrationService,EventReportingService}.php`,
`app/Console/Commands/EventsReconcilePayments.php`,
`app/Http/Controllers/Portal/{Events/EventStorefrontController,Admin/EventController}.php`,
`app/Models/{EventOrder,EventRegistration,Event}.php`,
`app/Externals/DirectPayments/DirectPaymentGateway.php`,
`tests/Unit/Events/EventOrderServiceTest.php` (new).

**Ops.** `php artisan migrate`. No config. The reconciler is
`events:reconcile-payments` (renamed args) — update any scheduler entry that
passed the old `{--dry-run}`-only signature. Appointments has the identical
coupling (`AppointmentsReconcilePayments`) and is **not** done here.

---

## E3 — Events builder integration (four views)

**Date.** 2026-09-08. **Repo:** `www`.

Events reached the ecommerce page-level surface: a rewritten four-view runtime
(`list` / `detail` / `register` / `my-registrations`), `eventsChrome.ts`,
`pageEventDpRef` synced + indexed under `direct_payments`, and the SDK's
`beginPayment()` rebuilt around a typed Direct Payments client.

This grew a plan and an approval, so it is **not an extra** — it is
[`builder/v2/05-events-parity.md`](../../builder/v2/05-events-parity.md) and its
[completion doc](../../builder/v2/complete/05-events-parity.complete.md), which
own the builder side. E2 above owns the server (façade orders) side.

**Ops.** `npm run build` in `www` (**never** `npm run dev` / vite).
**Republish** the builder project so events pages pick up the new page `js`, and
redeploy the sandbox `sdk.js` mirror. Re-bind the Event on any existing events
page so the 4th child page and `pageEventDpRef` are created. A demo event is
already bound + serving on `local` — see the completion doc.

---

## E1 — Events demo-data seeder

**Date.** 2026-09-08.

**Context.** Plan `14-rollout-and-testing` gives verification steps and
`php artisan test` commands but never defines a demo-data fixture for the
events module the way `DemoStoreSeeder` does for ecommerce. There was no
way to bring up a populated events storefront locally without hand-writing
rows. Asked for: an idempotent seeder that only inserts what is not
already there.

**What we added.** `database/seeders/EventsDemoSeeder.php`, modelled on
`DemoStoreSeeder`:

- `env('APP_ENV') === 'production'` guard.
- `resolveWebsiteStamp()` helper copied from `DemoStoreSeeder`
  (`BRAIN_SUBDOMAIN` env → `subdomain_properties` → `brain_memberships`)
  so the demo events carry `website_reference_num` / `website_domain` and
  are visible in the portal, not just in the DB.
- Every row keyed by a fixed `reference_num` via `firstOrCreate` /
  `updateOrCreate`, so re-running is a no-op and a partial run self-heals.

Seeded set:

| Data | Rows |
|---|---|
| Direct Payment | `demo-events-dp` (own DP, reuses the active Stripe processor / `demoprc`) + 2 items — `demo-evt-techconf-ga` $150, `demo-evt-techconf-vip` $350 |
| Events (`status = published`, `published_at` set) | `demo-tech-conf` — *Demo Tech Conference 2026* (paid, `direct_payment_reference_num` set); `demo-meetup` — *Demo Community Meetup* (free, no DP) |
| Occurrences | `occ-demo-techconf` (45 days out, 2 days); `occ-demo-meetup` (14 days out, evening) |
| Ticket types | `tkt-demo-techconf-ga` paid $150 → DP item; `tkt-demo-techconf-vip` paid $350 → DP item; `tkt-demo-techconf-free` free Community Pass; `tkt-demo-meetup-rsvp` free RSVP |
| Tier | `tier-demo-techconf-ga-early` — Early Bird $100 on GA |
| Sessions | `ses-demo-techconf-keynote`, `ses-demo-techconf-workshop` |
| Promo code | `prm-demo-techconf-10` — `DEMO10`, 10% off order |
| Pass | `demo-events-annual-pass` — $99, `included_event_refs` = both events |

Not seeded: venues (the `laravel_event_venues` table is **not migrated** —
`Event.default_venue_id` and `EventSession.venue_id` are left null), and no
registrations / orders / check-ins (kept to catalogue + config only, same
as `DemoStoreSeeder`).

**Gotcha — `laravel_events.reference_num` is `varchar(20)`.** Every other
`laravel_event_*` table is `varchar(32)`. `demo-tech-conference` (20) fit
but `demo-community-meetup` (21) truncated; the event refs were shortened
to `demo-tech-conf` / `demo-meetup`.

**Registration.** `database/seeders/DatabaseSeeder.php` calls it in the
dev-only block, guarded so it is skipped once demo events exist:

```php
if (DB::table('laravel_events')->where('reference_num', 'like', 'demo-%')->count() === 0) {
    $this->call(EventsDemoSeeder::class);
}
```

**Sibling fix — `DemoStoreSeeder` regression.** The ecommerce ↔ Direct
Payments decoupling (`w/www` commit `0aa2b4dfd`) removed
`EcommerceDirectPaymentService::publishPrices()` and dropped the store
columns `dp_item_mode` and `processor_tax_enabled`. `DemoStoreSeeder` still
called / wrote all three and is invoked unconditionally by `DatabaseSeeder`,
so `php artisan db:seed` fatalled. Removed the `publishPrices($store)` call
and those two `ensureStore()` fields; added `shipping_item_ref => null`.

**Files.** `database/seeders/EventsDemoSeeder.php` (new),
`database/seeders/DatabaseSeeder.php`, `database/seeders/DemoStoreSeeder.php`.
`w/www` commit `3128a82ed`.

**Ops.** `php artisan db:seed --class=EventsDemoSeeder` (or a full
`php artisan db:seed`). Idempotent — safe to re-run; counts stay 2 events /
2 occurrences / 4 ticket types / 1 tier / 2 sessions / 1 promo / 1 pass /
2 DP items. If venues get migrated later, extend the seeder with an
`EventVenue` and back-fill `default_venue_id` / `venue_id`.
