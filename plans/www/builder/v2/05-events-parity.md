# 05 — Events parity

[03](03-the-three-modules.md) §3.2 shipped events as a page-level module: bind
an Event, get three empty pages, and a ~50-line stub runtime that dumped
`<article>` rows with inline styles. It never got what ecommerce got in P3 and
the [`00_extras.md`](complete/00_extras.md) E1–E28 saga — designed chrome, a
hydrating runtime, a working checkout, honest sold-out state. This step closes
that gap and adds the fourth page the SDK already implied.

It has a server half. Events still stored money on `laravel_event_orders` /
`laravel_event_registrations` and trusted the invoice row status alone — the
webhook-lag hole the ecommerce decoupling (`0aa2b4dfd`) closed with
`App\Externals\DirectPayments\Contracts\PaymentGateway`. A builder Register page that
charges is only trustworthy on the far side of that gate, so the decoupling is
in scope, not deferred.

## 5.1 Server — event orders become façades

Mirror `0aa2b4dfd`. Read `EcommerceOrderService`, `EcommerceCheckoutService`,
`EcommerceReconcileOrders` and copy the shape.

| Change | Detail |
|---|---|
| Migration | ADD `event_orders.direct_payment_reference_num` + `processor_reference_num` (backfill from the invoice). DROP `event_orders` money columns (`subtotal_cents` … `refunded_cents`) + `direct_payment_charge_ref`. DROP `event_registrations.amount_cents` + `refunded_cents`. `hasColumn`-guarded; lossy `down()`. |
| `EventOrderService` (new) | `finalizeFromSnapshot(order, InvoiceSnapshot)` — the three refs + `status:'paid'` + registrations → `confirmed`, idempotent. `paymentSnapshot(order)` resolves every figure from `PaymentGateway::snapshot()`. |
| `EventPaymentService` | `createInvoiceForOrder()` stays server-side (inside `register()`'s capacity transaction — splitting it would fracture the claim). Stamps `metadata.facade = 'events'` + `registration_reference_num`. `confirmPaid()` **removed**. |
| `EventStorefrontController::confirmPayment()` | Gate on `PaymentGateway::isPaid()`, then `finalizeFromSnapshot()`. Recover a thin order by `direct_payment_invoice_ref` then `$snapshot->facadeRef`. |
| `EventsReconcilePayments` | Rewritten like `EcommerceReconcileOrders`: `{--minutes=15} {--dry-run}`, scan `pending` orders with an invoice → `isPaid()` → `finalizeFromSnapshot()`. |
| `EventReportingService` | `revenue_cents` from `gateway->snapshots()` → `netCents()`. |
| `DirectPaymentGateway::facadeRef()` | Add `event_order_reference_num` to the metadata key list. |
| Free tickets | A `free`-kind ticket registers without a Direct Payment item even on a paid event (was `EV_E_ITEM_UNMAPPED`). |

## 5.2 The Page panel

Unchanged from [03](03-the-three-modules.md): the events select is already
`eventsInstances`-backed and shows on Page Type = Events. Two additions:

- `eventsInstances` surfaces `directPaymentRef` from the event's
  `direct_payment_reference_num` (via `Event::adminPayload()`).
- `pageTypes.ts` `syncEventDirectPayment()` mirrors `syncStoreDirectPayment()`:
  picking an event writes its DP onto the page + parent + children as
  `pageEventDpRef`. Blank for a free event. `publish.ts` passes `pageEventDpRef`
  into the compile binding.

No template pickers. `eventsChrome.ts` exists for a future
`eventsTemplates.ts`; until then the runtime's `injectChrome()` covers it.

## 5.3 The page list

```
['list', 'detail', 'register', 'my-registrations']
```

`my-registrations` is the events analogue of ecommerce `orders` — auth-gated
(`authDefaultForSubpage` → `'page'`), renders `myRegistrations()` with cancel +
calendar links. `SUBPAGE_LABELS`, `EVENTS_KIND` and `authDefaultForSubpage`
grow the one entry. `ensureBoundSubpages` already creates whatever the module
declares — binding an event now makes four children.

No `retired.ts` row: there was never a `module-events-mine` block, and check 15
only validates the rows that exist (ecommerce `favorites` has none either).

## 5.4 The canvas

`ensureBoundSubpages` stamps `defaultViewHtml` — `stampPageModuleHtml` around a
dashed placeholder. `pageModuleAttributes` stamps `data-events-kind` from the
subpage and, for events, `data-dp-ref` from `pageEventDpRef`. Live registration
never runs in the canvas (v1 D5).

## 5.5 The published page

Runtime is `eventsComponentScript`, rewritten — typed, no optional chaining, a
typed Direct Payments client (memory `ts-no-optional-chaining-no-any`).

| Kind | Renders |
|---|---|
| `list` | Occurrence cards, links to Detail with `?event=<ref>` |
| `detail` | Event resolved from `?event=` (like Product from a slug); schedule, description, ticket types with honest **sold-out / waitlist / sales-not-open / code-required** state ([events/06](../../events/06-ticketing-and-capacity.md) §6.10) |
| `register` | Ticket + qty + attendee form + promo → `register()` → when `requires_payment`, DP single-pay via the SDK → `confirmPayment()` → receipt |
| `my-registrations` | Auth-gated `myRegistrations()` list + cancel + calendar |

Shared: `waitForPortal()`, `isEditorMode()` bail, `clearCanvas()` before paint,
`data-events-ready` gate, `injectChrome()` fallback CSS.

`compileRuntime` gets an `if (mod.id === 'events')` branch indexing
`pageEventDpRef` as a `direct_payments` ref (parity with the ecommerce branch).
Missing DP is a runtime concern, not a publish refuse.

### Retirement (events)

Unchanged from [03](03-the-three-modules.md):

| Retired type | Becomes |
|---|---|
| `module-events-list` | List |
| `module-events-detail` | Detail |
| `module-events-register` | Register |

## 5.6 SDK

`resources/js/external/api/events/api.ts` — `beginPayment()` rebuilt around a
typed `EventsDpClient` (`resolveDirectPayments()` asserts + throws, never a
silent no-op); `myRegistrations()` returns `PortalEventRegistrationRecord[]`.
`portal.d.ts` events surface expanded from three methods to the full SDK
(`hold`, `validatePromo`, `myRegistrations`, `cancelRegistration`,
`calendarUrl`, `joinWaitlist`, `passes`, …).

## 5.7 Acceptance

| # | Check | Expect |
|---|---|---|
| E1 | Bind an event | Page list is List / Detail / Register / My Registrations — four pages, `my-registrations` defaults to page auth |
| E2 | Publish, paid event | `js` non-empty; `refs` carry `events/<event>` **and** `direct_payments/<dp>` |
| E3 | Publish, free event | Same, no `direct_payments` ref |
| E4 | Served detail page | Real event data, ticket types, honest availability; no design-time placeholder |
| E5 | Served register page | Form → `register()` → DP card mount when paid → receipt |
| E6 | `event_orders` row after payment | Three refs, `status='paid'`, **no money columns** |
| E7 | `events:reconcile-payments --dry-run` | Nothing stuck |
| E8 | `check-modules.mjs` | Passes with events at four subpages |

E4/E5 need a bound page on a `local` tenant. Stub tests cover E1–E3, E8.

## 5.8 Residue

| Item | Where |
|---|---|
| Designed events templates + Page-panel template pickers (`eventsTemplates.ts`) | a later addendum; `injectChrome()` is the functional floor |
| Appointments has the identical DP coupling (`AppointmentsReconcilePayments`) | its own step |
| Season-pass view sells via DP but has no builder page | events/12 |
| Occurrence `starts_at` timezone rendering | events serialisation, pre-existing |

## 5.9 Repo

**www** for code, **packages/afsserver** for this plan. Server + builder land
in one www commit on `main`. The v1 07 + v2 P0–P6 tree is the backend this
compiles through — it is not reverted.
