# 14 — Rollout and Testing

## 14.1 Phases

Unlike its siblings there is **no P0** — nothing is exposed, because nothing is served
([01](01-current-state-audit.md) §1.5).

| Phase | Contents | Docs | Depends on |
|---|---|---|---|
| **P1 — the API** | Controller, split surfaces, schema through registrations, server-generated references, the SDK off the legacy URL, the admin's own client (**V1**, **V2**, **V6**–**V12**) | [02](02-site-users-guard.md) §2.2, [04](04-database-schema.md), [11](11-public-surface-and-sdk.md) §11.2–§11.5 | nothing |
| **P2 — the model** | Occurrences, recurrence, sessions, venues, time zones, validation (**V13**, **V17**, **V18**) | [05](05-event-model-and-scheduling.md) | P1 |
| **P3 — inventory** | Ticket types with prices, tiers, atomic capacity, holds, allocations, promo codes (**V14**–**V16**, **V21**) | [06](06-ticketing-and-capacity.md) | P2 |
| **P4 — registration** | Orders, group registration, forms attachment, approval, waitlists, transfers | [07](07-registration-and-attendees.md) | P3 |
| **P5 — money** | Direct Payments, fees, tax, refunds, cancellation policy, reconciler (**V4**) | [08](08-payments-and-refunds.md) | P4, the [billing plan](../../billing/README.md) |
| **P6 — the admin** | The `events.ts` rewrite, all views, roles (**V20**, **V23**, **V24**) | [12](12-admin-dashboard.md) | P1–P5 incrementally |
| **P7 — communications** | Confirmations, ICS, reminders, change notices, webhooks (**V22**) | [10](10-communications.md) | P4 |
| **P8 — public** | Hosted pages, builder blocks, embeds, structured data (**V5**) | [11](11-public-surface-and-sdk.md) §11.6–§11.8 | P4 |
| **P9 — on-site** | QR tickets, scanner PWA, offline manifest, badges, walk-ins | [09](09-checkin-and-onsite.md) | P4 |
| **P10 — reporting** | Dashboards, exports, afs-connect, retention (**V25**) | [13](13-reporting-and-analytics.md) | P5, P9 |
| **P11 — accounts** | Site Users attribution, my-events, self-service | [02](02-site-users-guard.md) §2.3–§2.8 | [site_users](../site_users/README.md) P2 and P8d |
| **P12 — passes** | Season passes and memberships | [08](08-payments-and-refunds.md) §8.10 | P5, billing subscriptions |

### Order that is not negotiable

1. **Occurrences before registrations.** Registering against an event and retrofitting
   occurrences means rewriting every registration ([03](03-architecture.md) §3.2).
2. **Atomic capacity before any public registration path.** The first public event is when the
   race becomes real, and it is not a thing to add afterwards
   ([06](06-ticketing-and-capacity.md) §6.5).
3. **Holds before payment.** Without them, a registrant pays for a place that may be gone
   ([06](06-ticketing-and-capacity.md) §6.6).
4. **Time zones before reminders and ICS.** Neither can be built on four loose strings
   (**V17**).
5. **The reconciler ships with payment**, not after ([08](08-payments-and-refunds.md) §8.7).
6. **Ticket codes before the scanner** ([09](09-checkin-and-onsite.md) §9.3).

P6 tracks P1–P5 rather than following them: each admin screen ships with the API it drives, so
there is never a large, unexercised back end.

## 14.2 Before P1 starts — two hours of verification

Both cheap, and both change what P1 is.

### Does the events module load at all? (V7)

`events.ts:13` runs `new ExternalEventsAPI()` at module scope. The externals bundle assigns only
`window.Portal` (`external/main.ts:113–116`), and `webpack.config.cjs` sets no `library` name.

Open the events view in a browser and read the console.

| Result | Meaning |
|---|---|
| `ReferenceError: ExternalEventsAPI is not defined` | The existing UI has **never run** in this deployment. P1 is a rebuild, and none of §14.4's import work is needed |
| The view renders | Something exposes the class — find what, because it is not in this repository, and it constrains the rewrite |

### Is anything in `localStorage`? (V8)

In a tenant admin session: `localStorage` keys used by `DataStore`. If tenants have created
events that exist only in their browsers, §14.4's import is worth building. If not, delete it
from the plan.

**Do not build the importer before answering this.** It is the only migration this module has,
and it may have nothing to migrate.

## 14.3 The concurrency suite

The module's correctness is a concurrency property, so its test suite is a load test, not a unit
test. This runs in CI, on every change to the claiming code.

| # | Scenario | Assertion |
|---|---|---|
| 1 | 200 concurrent registrations, capacity 50 | Exactly 50 confirmed, 150 refused or waitlisted |
| 2 | Occurrence 50, dedicated type 20, mixed traffic | Type sells exactly 20; occurrence never exceeds 50 |
| 3 | Mixed type/tier claims from many workers | **No deadlocks** — consistent lock ordering ([06](06-ticketing-and-capacity.md) §6.5) |
| 4 | 100 holds, half abandoned | Abandoned inventory returns after TTL; none leaks |
| 5 | Hold expires mid-checkout | Order fails **before** any charge |
| 6 | Tier of 100 under load | Exactly 100 at the tier price |
| 7 | Promo code `max_uses = 100` under load | Exactly 100 redemptions |
| 8 | 50 concurrent cancellations, 50 waitlist entries | Exactly 50 promotions, no double-promotion, no place unclaimed |
| 9 | Transfer between two full occurrences | Fails safely; source place never released |
| 10 | Payment confirmation retried 5× | One order, one set of registrations, one email |
| 11 | Process killed between charge and write | Reconciler completes it; no duplicates |
| 12 | 500 check-ins, 6 devices, half offline | 500 distinct attendances; conflicts recorded |
| 13 | Session capacity 30, 100 concurrent selections | Exactly 30 |
| 14 | Walk-in during a sold-out scan session | Refused, capacity intact |

**After every scenario: `events:reconcile-counters` reports zero disagreements.** That is the
single assertion that catches a claiming bug the scenario itself missed.

## 14.4 The `localStorage` import

Only if §14.2 finds data.

A one-time, explicit, previewed offer in the admin ([04](04-database-schema.md) §4.17):

| Step | |
|---|---|
| 1 | Detect events in `localStorage` on first load of the new module |
| 2 | Show them: name, dates, ticket levels — and what each will become |
| 3 | **Ask for the time zone** — the stored strings carry none (**V17**) |
| 4 | Import: each event → an event + one occurrence; each ticket level → a ticket type, priced from its mapped Direct Payment item (the price the current UI discards, **V15**) |
| 5 | Server generates new references (**V9**) |
| 6 | Report what was imported and what could not be |
| 7 | Offer to clear the local copy — **after** the import is confirmed, never before |

Skippable and repeatable. Silently importing a browser cache into a live event catalogue is
worse than losing it.

Discount codes (**V21**) import the same way, into `laravel_event_promo_codes`.

## 14.5 The test matrix

### Backend

| Area | Cases |
|---|---|
| Auth | Every route, authenticated and not, own tenant and other |
| Scoping | Two tenants, two sites, list/read/write/delete each way |
| Public projection | Field-by-field assertion of what is absent ([11](11-public-surface-and-sdk.md) §11.3) |
| Recurrence | Every frequency; exceptions; horizon extension; edit this / following / all; divergence protection |
| Time | The seven DST and zone cases in [05](05-event-model-and-scheduling.md) §5.5 |
| Capacity | §14.3, entirely |
| Registration | Group modes, unassigned claims, per-attendee forms, approval, transfer, cancel |
| Money | Discount before tax, fee modes, tax failure, refunds, partial refunds, transfer price differences |
| Check-in | Valid, cancelled, transferred, wrong occurrence, wrong session, outside window, invalid signature |
| Offline | Manifest signing, expiry, delta refresh, conflict recording |
| Communications | Idempotency, retries, ICS correctness, reminder rescheduling |

### Front-end

| Area | Cases |
|---|---|
| Persistence | Nothing is a source of truth in `localStorage`; two browsers agree |
| Recurrence builder | Plain-language preview matches the rule; next-ten-dates are correct across DST |
| Ticket grid | Inline edit, tiers, mismatch warning, refusal of half-filled types |
| Registration widget | Sold out, waitlist, code-required, sales-not-open, hold countdown |
| Scanner | Camera, manual search, undo, every refusal reason, offline mode |
| Escaping | Markup in an event name, a venue, an attendee name and an answer renders as text |
| States | Loading, empty, error and permission states on every view |
| A11y | Keyboard operation of the admin; the public registration flow with a screen reader |

### End-to-end

Seven journeys, each a whole vertical:

1. **Create → publish → embed → register → pay → confirm → ICS → remind → check in → report.**
2. **Recurring class** — 52 occurrences, cancel one, edit one, edit the series, verify the
   exception survived.
3. **Conference** — multi-day, tracks, session registration, badges, per-session attendance.
4. **Sell-out** — capacity reached, waitlist, cancellation, promotion, offer expiry, next offer.
5. **Free event at scale** — 1,000 registrations, no payment, no-show reporting.
6. **Hybrid** — in-person and online types, timed link release, mixed check-in.
7. **Abuse** — `curl` against every public endpoint: oversell, promo reuse, hidden ticket
   discovery, attendee enumeration, another attendee's registration.

## 14.6 Standing checks

Enforced in CI:

| Check | Guards |
|---|---|
| No `SELECT count(*)` … `INSERT` pattern in a claiming path | [06](06-ticketing-and-capacity.md) §6.5 |
| Lock ordering is occurrence → type → tier, everywhere | Deadlocks |
| No admin query on `laravel_events` without a `website_reference_num` predicate | Tenancy |
| No `decimal` money column in an events migration | [08](08-payments-and-refunds.md) §8.2 |
| No public route returns `attendee_email` or `attendee_name` | [11](11-public-surface-and-sdk.md) §11.3 |
| No reference to `/_portal_/admin/core/php/request.php` | **V2** |
| No `new ExternalEventsAPI()` outside the externals bundle | **V7** |
| No date produced by string concatenation | **V17** |
| No `innerHTML` assignment containing a `${` template literal in the events controllers | Escaping |
| `portal.d.ts` matches the SDK's exported surface | [11](11-public-surface-and-sdk.md) §11.5 |
| Exactly one authoritative SDK build | **V26** |
| Every changed file carries `AFS_EVENTS_MODIFIER_ADDITION` | [README](README.md) |

## 14.7 Performance targets

| Operation | Target |
|---|---|
| Public occurrence page (cached) | < 150 ms |
| `hold()` | < 200 ms, including the atomic claim |
| Registration list, 50k rows, filtered | < 500 ms |
| Attendee export, 10k rows | queued, < 60 s |
| Manifest download, 5k attendees | < 5 s |
| Offline scan decision | < 100 ms, **no network** |
| Recurrence materialisation, 52 occurrences | < 2 s |
| Admin overview | < 1 s |

The published occurrence projection is cacheable until the occurrence or its ticket types change
— with the **availability state** deliberately outside the cache, because a page that caches
"available" for five minutes sells tickets that are gone.

## 14.8 Rollback

| Phase | If it goes wrong |
|---|---|
| P1–P3 | Migrations are additive and reversible; the module is new, so there is nothing live to break |
| P4–P5 | Feature-flag registration per event. An event with registration disabled is a listing |
| P6 | The old `events.ts` stays importable for one release, behind a flag |
| P7–P10 | Additive; disable the flag |

The one irreversible action is **purge** ([13](13-reporting-and-analytics.md) §13.8), which is
why it is separate from delete and separately confirmed.

## 14.9 Definition of done

- Every finding **V1**–**V26** is closed, or explicitly deferred with a reason recorded in the
  phase's completion document.
- §14.3's concurrency suite passes, and `events:reconcile-counters` reports zero disagreements
  after all fourteen scenarios.
- No public endpoint returns attendee personal data — asserted field by field.
- The seven end-to-end journeys pass.
- The `localStorage` question (§14.2) is answered, and the importer is built or deleted
  accordingly.
- `portal.d.ts` is current and the consuming copies are refreshed
  ([11](11-public-surface-and-sdk.md) §11.5).
- `WwwEventsProvider::isStub()` returns `false`.
- Each numbered document has a completion document in [`complete/`](complete/) — status,
  summary, files changed, verification steps, follow-ups.
