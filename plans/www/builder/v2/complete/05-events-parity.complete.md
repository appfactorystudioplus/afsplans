# 05 — Events parity (complete)

## Status

**Done.** Events reaches the ecommerce page-level surface: a decoupled server,
a real four-view runtime, chrome, DP-ref indexing, and a fourth bound page. A
demo event is bound and serving on `local`.

- **www** commit `c97feaafa` (`events: decouple from Direct Payments + full
  builder integration`) — 27 files. On `main`.
- **packages/afsserver** commit — this file + [05 plan](../05-events-parity.md)
  + the `events/complete` cross-references.
- **462 builder tests** (was 451; +11 in `events.test.ts`). `tsc --noEmit`
  clean. `check-modules.mjs`: 10 modules, events at four subpages.
- `php artisan test --filter=Event` — 11 pass, incl. `EventOrderServiceTest`.
- `npm run build` green.

| Phase | Status |
|---|---|
| 5.1 Server decoupling | Done — migration `2026_09_09_000000` ran; `EventOrderService` new; `EventPaymentService` / `EventStorefrontController` / `EventsReconcilePayments` / `EventReportingService` reworked |
| 5.2 Page panel | Done — `syncEventDirectPayment()`; `eventsInstances.directPaymentRef`; `publish.ts` passes `pageEventDpRef` |
| 5.3 Page list | Done — `['list','detail','register','my-registrations']`; `my-registrations` page-auth |
| 5.4 Canvas | Done — `data-events-kind` + `data-dp-ref` stamped via `pageModuleAttributes` |
| 5.5 Published runtime | Done — `eventsComponentScript` rewritten (4 views); `eventsChrome.ts`; `compileRuntime` events branch |
| 5.6 SDK | Done — `beginPayment()` typed; `portal.d.ts` events surface expanded |
| 5.7 Acceptance | E1–E3, E8 stub-tested. E4–E6 verified live (below). E7 clean. |
| 5.8 Residue | `eventsTemplates.ts` (designed templates + panel pickers) not built — `injectChrome()` is the floor |

## What shipped

### Server (§5.1)

`laravel_event_orders` lost `subtotal_cents`, `discount_cents`, `fee_cents`,
`tax_cents`, `total_cents`, `refunded_cents`, `direct_payment_charge_ref`;
`laravel_event_registrations` lost `amount_cents`, `refunded_cents`. The order
keeps `direct_payment_reference_num`, `processor_reference_num`,
`direct_payment_invoice_ref` and resolves the rest from
`PaymentGateway::snapshot()`.

`EventStorefrontController::confirmPayment()` now refuses unless
`PaymentGateway::isPaid($invoiceRef)` — the same gate the ecommerce checkout
write-path uses. `confirmPaid()` (row-status only) is gone.

Fixed in passing: a `free`-kind ticket on a paid event was rejected
`EV_E_ITEM_UNMAPPED`; it now registers with no charge. This unblocked the
seeded "Community Pass" / "Free RSVP" tickets.

Detail in [`events/complete/00_extras.md`](../../../events/complete/00_extras.md)
E2 and [`events/complete/08`](../../../events/complete/08-payments-and-refunds.complete.md).

### Builder (§5.3–5.6)

`events.module.ts` — `subpages` → four; `foreignRefTraits: { 'data-dp-ref':
'direct_payments' }`; `data-site-ref` trait dropped (the published site *is* the
website). `pageLevel.ts` — `my-registrations` in `SUBPAGE_LABELS`,
`EVENTS_KIND`, `authDefaultForSubpage`; events branch in `pageModuleAttributes`
stamps `data-dp-ref` from `pageEventDpRef`.

`events_component_script.ts` — full rewrite, ~470 lines, typed, a local
`EventsApi` / `EventsDpClient` interface, `getPortal()` / `getEventsApi()` that
assert and throw. Four kinds dispatched off `data-events-kind`. `list` links to
`detail` with `?event=`; `detail` resolves the event from `?event=` the way
Product resolves a slug; `register` runs `register()` → DP single-pay →
`confirmPayment()` → receipt with a full-screen "processing" state; `register`
and `detail` reflect sold-out / waitlist / sales-not-open / code-required
honestly. `clearCanvas()` drops the design-time placeholder before painting;
`injectChrome()` writes fallback CSS so a page published without a template
still gets the loader + layout.

`eventsChrome.ts` (new) — `[data-events-kind]` stylesheet +
`withEventsChrome()` / `cssHasEventsChrome()`, mirroring `ecommerceChrome.ts`.

`compileRuntime.ts` + `publish.ts` — `pageEventDpRef` reaches the compile and is
filed under `direct_payments`, so deleting a bound event's Direct Payment is
refused while the page is published (05 §5.5's reverse index).

`pageTypes.ts` — `syncEventDirectPayment()` writes the picked event's
`direct_payment_reference_num` onto the page tree as `pageEventDpRef`;
`Event::adminPayload()` surfaces that ref; `eventsInstances` maps it to
`ModuleInstance.directPaymentRef`.

SDK — `events/api.ts` `beginPayment()` rebuilt around a typed `EventsDpClient`
(`resolveDirectPayments()` asserts + throws — never a silent no-op);
`myRegistrations()` returns `PortalEventRegistrationRecord[]`. `portal.d.ts`
events surface went from three methods to the whole SDK.

## Verified live (E4–E6)

Bound **Demo Tech Conference 2026** (`demo-tech-conf`, DP `demo-events-dp`) to a
new `new-page-events` page in project `projeKEnNosrOA3M` and published its four
views. Because the builder UI cannot be driven headlessly, the bind + publish
went through a one-off script that used the real `core/publish` `compileRuntime`
(via the test loader) for the `js`, `eventsChrome.ts` for the `css`, and
inserted the `laravel_builder_published` rows + `laravel_builder_module_refs`
(events + `demo-events-dp`) in a transaction — the same artifacts
`publishRelease` writes. The project draft got the five pages appended; the ten
original pages round-tripped byte-identical (backup kept).

| URL | Result |
|---|---|
| `/new-page-events-list` | Both seeded events, cards, View → detail |
| `/new-page-events-detail?event=demo-tech-conf` | Title, schedule, GA $150 / VIP $350 / Community Pass Free; `data-events-ready="1"` |
| `/new-page-events-register?event=demo-tech-conf` | Ticket picker + attendee form |
| `/new-page-events-my-registrations` | Auth-gated empty state |

Server-side (tinker): paid GA `register()` → invoice with
`metadata.facade=events` + `registration_reference_num`; `finalizeFromSnapshot()`
→ order `status=paid`, three refs, **no money columns**, registrations
`confirmed`. Free Community Pass registers with no invoice.

The global cart FAB (`#afs-ui-cart-fab`) that shows on the events pages is the
ecommerce UI SDK's site-wide badge — not events chrome, not in scope here.

## Residue

| # | Item | Where it belongs |
|---|---|---|
| 1 | ~~`eventsTemplates.ts` — designed templates + `renderEventsTemplate` editor preview~~ | **closed by [06](06-design-themes.complete.md)** — events now has a live canvas preview and a 4-option Design picker |
| 2 | Appointments' identical DP coupling (`AppointmentsReconcilePayments`) | its own decoupling step |
| 3 | Season-pass sale surface | events/12; needs a `retired.ts` row the day it ships |
| 4 | Occurrence `starts_at` renders in browser TZ off the seeded value | events serialisation |
| 5 | Live `.test` builder not rebuilt for the editor-side bind flow | [02](02-page-level-contract.complete.md) residue #7 — `npm run build` done, a running editor still needs its own reload |

## Verification

```
cd w/www
npx tsc --noEmit
npm run test:builder
php artisan test --filter=Event
php artisan migrate            # 2026_09_09_000000_events_facade_payment_refs
php artisan db:seed --class=EventsDemoSeeder
php artisan events:reconcile-payments --dry-run
```

Then open `/new-page-events-detail?event=demo-tech-conf` on the `local` site.
