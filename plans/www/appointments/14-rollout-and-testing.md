# 14 — Rollout and Testing

## 14.1 Phases

| Phase | Contents | Docs | Depends on |
|---|---|---|---|
| **P0** | **Security gate** — split the routes, `ApiAuthMiddleware` on admin, **delete both bulk-replace endpoints**, single-booking create, admin-only booking list, site scoping, server-side references (**A1**–**A6**, **A14**–**A19**) | [02](02-site-users-guard.md) §2.2, [06](06-booking-engine.md) §6.2 | nothing |
| **P1** | **Correctness** — the schema, UTC instants, the unique index, capacity enforcement, split buffers, active filtering (**A10**–**A13**, **A31**–**A34**) | [04](04-database-schema.md), [06](06-booking-engine.md) §6.3 | P0 |
| **P2** | **Availability** — services/schedules split, exceptions, lead time, horizon, the computed projection | [05](05-availability-and-scheduling.md) | P1 |
| **P3** | **Staff and resources** — assignment strategies, per-staff schedules, resources, group services | [07](07-services-staff-and-resources.md) | P2 |
| **P4** | **Lifecycle** — confirm, reschedule, cancel, no-show, approval, holds, custom questions (**A8**) | [06](06-booking-engine.md) §6.5, [08](08-booking-lifecycle.md) | P2 |
| **P5** | **Communications** — confirmations, ICS, reminders, staff notices (**A30**) | [10](10-communications-and-calendar.md) §10.1–§10.5 | P4 |
| **P6** | **Public surface** — the repaired widget, hosted booking page, SDK, builder blocks (**A26**–**A29**) | [11](11-public-surface-and-widget.md) | P2, P4 |
| **P7** | **Admin** — calendar, day sheet, bookings, configuration screens (**A20**–**A25**) | [12](12-admin-dashboard.md) | tracks P1–P6 |
| **P8** | **Money** — deposits, prepayment, fees, refunds, packages | [09](09-payments-and-policies.md) | P4, the [billing plan](../../billing/README.md) |
| **P9** | **Calendar sync** — two-way, busy blocks, conservative mode | [10](10-communications-and-calendar.md) §10.6 | P5 |
| **P10** | **Waitlist and recurring bookings** | [08](08-booking-lifecycle.md) §8.7–§8.8 | P4 |
| **P11** | **Reporting** — utilisation, no-shows, exports, retention | [13](13-reporting-and-analytics.md) | P4, P8 |
| **P12** | **Accounts** — Site Users attribution, my-appointments, self-service | [02](02-site-users-guard.md) §2.3–§2.6 | [site_users](../site_users/README.md) P2, P8d |

P0 ships alone, on its own branch, with no feature work attached.

### Order that is not negotiable

1. **P0 before everything.** Two endpoints delete by omission, unauthenticated, and the admin UI
   fires one of them on every edit.
2. **Delete bulk replace before authenticating it.** Authenticating leaves **A16** and **A17**
   fully armed against the tenant themselves ([00](00-overview.md) §0.5 D1).
3. **UTC instants and the unique index before availability.** Availability computed over a `date`
   and a `varchar` cannot be correct, and a computed slot with no constraint behind it is a
   double-booking with extra steps.
4. **De-duplicate before adding the unique index** ([04](04-database-schema.md) §4.12 step 10).
   Duplicates exist, because nothing ever prevented them.
5. **Capacity before accounts.** A double-booking discovered by a signed-in customer with a
   history is worse than one discovered by the tenant.
6. **Reminders (P5) before or with the public widget (P6).** Opening a booking channel that sends
   no confirmation is worse than not opening it (**A30**).

P7 tracks P1–P6 rather than following: each admin screen ships with the API it drives.

## 14.2 The security gate

P0 does not ship until every one of these is demonstrated by test:

| # | Gate |
|---|---|
| 1 | **No endpoint anywhere deletes a row it did not name.** `grep` finds no `whereNotIn(...)->delete()` in the module |
| 2 | Unauthenticated `POST /portal/api/v1/appointments/{ref}/bookings` → **404**; the route no longer exists (**A1**) |
| 3 | Unauthenticated slot save → **404**; the route no longer exists (**A2**) |
| 4 | Unauthenticated `GET .../bookings` → **401** (**A3**) |
| 5 | **No public response contains a name, email, phone or note** — asserted field by field (**A3**, **A29**) |
| 6 | Unauthenticated `DELETE /appointments/{ref}` → **401**, and the authenticated version soft-deletes, destroying no bookings (**A4**) |
| 7 | An authenticated caller cannot read or write another tenant's appointments (**A5**) |
| 8 | The SDK exposes no `create()` or `delete()` (**A6**) |
| 9 | `saveAppointment` cannot overwrite an existing appointment by naming its reference (**A14**) |
| 10 | Booking twice through the public endpoint leaves **both** bookings (the direct test for **A1**) |
| 11 | Every reference is server-generated (**A18**, **A19**) |
| 12 | A failed save surfaces an error in the admin (**A15**) |

Gate 10 is the one to run first and by hand: it is a two-line reproduction of the finding, and it
is the fastest way to confirm the fix.

## 14.3 The concurrency suite

Correctness here is a concurrency property. This runs in CI on every change to the booking or
availability code.

| # | Scenario | Assertion |
|---|---|---|
| 1 | 50 concurrent bookings for one capacity-1 time | **Exactly one** succeeds; 49 get a clean 409 |
| 2 | The same, **with the application-level check removed** | Still exactly one — proves the database is the guarantee ([06](06-booking-engine.md) §6.3) |
| 3 | 50 concurrent bookings for a capacity-6 group service | Exactly 6 |
| 4 | Mixed service/staff/resource claims from many workers | **No deadlocks** — consistent lock ordering |
| 5 | 100 holds, half abandoned | Abandoned time returns after TTL; none leaks |
| 6 | Hold expires mid-checkout | Refused **before** any charge |
| 7 | Cancel and rebook the same time concurrently | Exactly one booking survives; the time is never double-held |
| 8 | 50 cancellations with 50 waitlist entries | Exactly 50 offers, no double-offer, no time left unclaimed |
| 9 | Reschedule into a time being booked concurrently | One wins; the loser keeps its **original** booking |
| 10 | Two admins editing services concurrently | Neither loses the other's service (the **A16** regression test) |
| 11 | Payment confirmation retried 5× | One booking, one confirmation |
| 12 | Process killed between charge and write | Reconciler completes it; no duplicate |
| 13 | Ten-week recurring series with week 6 unavailable | **Nothing** is created; the conflict is reported |
| 14 | Availability queried while bookings are being made | Never returns a time that is already full |

**After every scenario: `appointments:reconcile-counters` reports zero disagreements.**

Scenario 2 is the important one. If removing the application check changes the result, the
guarantee is in the wrong place.

## 14.4 Migration

[04](04-database-schema.md) §4.12 has the steps. The operational notes:

| Step | Care |
|---|---|
| Time zone (§4.12 step 5) | Existing rows carry none (**A12**). Default to the site zone, **show the tenant what will be assumed**, and let them correct before converting |
| Instants (step 6) | `date` + `time` + zone → UTC. Run as a dry-run first and spot-check both sides of a DST boundary |
| De-duplication (step 10) | **Produces a report; the tenant chooses which booking survives.** Never automatic — one of two duplicates is a real customer |
| Site backfill (step 9) | Unattributable rows are listed, never guessed. Until it completes, scoped queries return nothing — run it in the migration window and compare counts |
| `booking_id` (step 8) | Kept, so old emailed links resolve during the deprecation window ([02](02-site-users-guard.md) §2.5) |

### Verification

| Check | Expectation |
|---|---|
| Booking count before vs after | Identical |
| Every booking has `starts_at`, `access_token`, `reference_num` | Yes |
| Sample of 20 bookings, displayed time before vs after | Identical |
| Bookings either side of a DST boundary | Correct local time |
| Slots → services + schedules | One service and *n* schedules per row |
| Rows with `website_reference_num IS NULL` | Reported and reviewed by a human |
| Duplicate active bookings | Zero, after the reported resolution |

## 14.5 The test matrix

### Backend

| Area | Cases |
|---|---|
| Auth | Every route, authenticated and not, own tenant and other |
| Public projection | Field-by-field assertion of what is absent |
| Availability | All 13 conditions in [05](05-availability-and-scheduling.md) §5.1, independently and combined |
| **Cache equivalence** | Availability with and without the cache is identical ([05](05-availability-and-scheduling.md) §5.2) |
| Time | The seven DST and zone cases in [05](05-availability-and-scheduling.md) §5.6 |
| Capacity | §14.3, entirely |
| Lifecycle | Every transition in [08](08-booking-lifecycle.md) §8.2, and every refusal in [06](06-booking-engine.md) §6.6 |
| Staff and resources | Assignment strategies, room conflicts, per-staff overrides |
| Money | Deposits, prepayment, fees, refunds, credits, reschedule differences |
| Communications | Idempotency, retries, ICS correctness, reminder rescheduling |
| Sync | Busy import, loop prevention, staleness, conservative mode |

### Front-end

| Area | Cases |
|---|---|
| Mutations | One service per request; a failed save shows an error |
| Persistence | Nothing is a source of truth in `localStorage`; two browsers agree |
| Calendar | Day/week/month/staff views; drag-reschedule confirms and uses the transaction |
| Widget | Holds, countdown, refusal messages, next-three-dates, mobile at 375 px |
| Dates | No `new Date(bareDateString)`, no string concatenation |
| Escaping | Markup in a service name, a customer name and a note renders as text |
| States | Loading, empty, error, permission on every view |
| A11y | Keyboard operation of the admin; the booking flow with a screen reader |

### End-to-end

Seven journeys:

1. **Configure → publish → embed → book → confirm → ICS → remind → complete.**
2. **Two practitioners, one room** — resource conflict never offers both at once.
3. **Reschedule and cancel**, by customer and by staff, with the waitlist promoting.
4. **Recurring series** of ten, one week unavailable, resolved and booked.
5. **Deposit flow** — pending → paid → confirmed → late cancellation → fee decision.
6. **Calendar sync** — block time externally, confirm it disappears from availability, book,
   confirm it appears externally.
7. **Abuse** — `curl` against every public endpoint: double-book, enumerate bookings, wipe the
   calendar, hold a whole day, read another customer's booking.

Journey 7 case 3 is the regression test for the finding that opens this plan.

## 14.6 Standing checks

Enforced in CI:

| Check | Guards |
|---|---|
| No `whereNotIn(...)->delete()` in the module | **A1**, **A2** |
| No `count()`-then-`INSERT` in a booking path | [06](06-booking-engine.md) §6.3 |
| Lock ordering is service → staff → resource | Deadlocks |
| No admin query on `laravel_appointments` without a `website_reference_num` predicate | **A5** |
| No public route returns `user_email`, `user_name`, `user_phone` or `notes` | **A3**, **A29** |
| No `decimal` money column in an appointments migration | [09](09-payments-and-policies.md) §9.1 |
| No `new Date(` on a bare date string; no date built by concatenation | **A12**, **A32** |
| No inline `onclick` and no `window.<fn> =` in the appointments controllers | **A24** |
| No `innerHTML` assignment containing a `${` template literal | **A23** |
| One `escapeHtml` implementation | **A23** |
| No `localStorage` in the generated widget | **A26** |
| `portal.d.ts` matches the SDK's exported surface | [11](11-public-surface-and-widget.md) §11.4 |
| Every changed file carries `AFS_APPOINTMENTS_MODIFIER_ADDITION` | [README](README.md) |

## 14.7 Performance targets

| Operation | Target |
|---|---|
| Availability, one service, one week, one staff | < 200 ms |
| Availability, one service, one month, ten staff | < 800 ms |
| `hold()` | < 150 ms |
| `book()` including the atomic claim | < 250 ms |
| Bookings list, 100k rows, filtered | < 500 ms |
| Calendar week view, 20 staff | < 500 ms |
| Day sheet | < 200 ms |
| Export, 10k bookings | queued, < 60 s |

Availability is the hot path and the one that grows with staff count. It is cached per
(service, staff, date) with the equivalence property in §14.5, and the month-across-ten-staff
figure is the one to watch — it is what a busy clinic's booking page requests on load.

## 14.8 Rollback

| Phase | If it goes wrong |
|---|---|
| P0 | **Do not roll back the endpoint deletions.** If the split routes misbehave, fix forward — restoring bulk replace restores an unauthenticated calendar wipe |
| P1 | Migrations are additive; the unique index can be dropped without data loss |
| P2–P4 | Feature-flag the availability engine per appointment; the legacy path stays for one release |
| P5–P11 | Additive; disable the flag |

The one irreversible action is **purge** ([13](13-reporting-and-analytics.md) §13.7), which is
why it is separate from delete and separately confirmed.

## 14.9 Definition of done

- Every finding **A1**–**A35** is closed, or explicitly deferred with a reason recorded in the
  phase's completion document.
- The twelve security gates pass.
- §14.3's concurrency suite passes, including scenario 2 with the application check removed.
- No public endpoint returns customer personal data — asserted field by field.
- The migration has run against a copy of production data with §14.4's checks passing, and the
  duplicate report has been resolved by a human.
- A booking made through the public widget exists in the database and sends a confirmation.
- The seven end-to-end journeys pass.
- `portal.d.ts` is current and consuming copies refreshed.
- `WwwAppointmentsProvider` reports more than a row count.
- Each numbered document has a completion document in [`complete/`](complete/) — status,
  summary, files changed, verification steps, follow-ups.
