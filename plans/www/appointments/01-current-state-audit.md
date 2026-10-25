# 01 — Current State Audit

Findings are numbered `A#`.

## 1.1 What exists

| Layer | File | LOC |
|---|---|---|
| Migration | `2026_04_13_000001_create_laravel_appointments_table.php` — 3 tables, **with real foreign keys** | 118 |
| Models | `Appointment`, `AppointmentSlot`, `AppointmentBooking` | — |
| Controller | `Portal/Admin/AppointmentsController.php` — 8 methods | 214 |
| Routes | `routes/portal.php:406–413` | 8 |
| **Admin: shell** | `controllers/appointments/AppointmentsController.ts` | **294** |
| **Admin: configure** | `controllers/appointments/appointments.ts` | **583** |
| **Admin: preview** | `controllers/appointments/appointment-preview.ts` | **590** |
| **Widget generator** | `admin/utils/appointment-export.ts` | **1,038** |
| Client store | `admin/store/DataStore.ts` — appointments, slots, bookings | — |
| SDK | `resources/js/external/appointments/api.ts` + `README.md` | — |
| afs-connect | `app/AfsConnect/Providers/WwwAppointmentsProvider.php` | — |

```
laravel_appointments          reference_num (unique) · name · title
                              website_domain · website_reference_num

laravel_appointment_slots     reference_num · slot_id · name · description
                              duration · available_days (TEXT json) · start_time · end_time
                              max_bookings · buffer_time · active · color
                              unique(reference_num, slot_id) · FK → appointments, CASCADE

laravel_appointment_bookings  reference_num · booking_id · slot_id
                              user_name · user_email · user_phone
                              date (date) · time (string) · notes · status
                              unique(reference_num, booking_id) · FK → appointments, CASCADE
```

**The module works**, and there is roughly 2,500 lines of front end in front of it. That is what
makes §1.2 serious.

## 1.2 🔴 Unauthenticated, and destructive by design

Every route at `routes/portal.php:406–413` is **outside** `ApiAuthMiddleware` — verified by
walking brace depth through the file. The only wrapper is `portal.cors`, which is an explicit
no-op (`PortalCorsMiddleware::handle()` is `return $next($request)`).

| # | Finding | Consequence |
|---|---|---|
| **A1** | **`POST /appointments/{ref}/bookings` is a bulk replace.** It upserts the bookings in the payload, then runs `AppointmentBooking::where('reference_num', $ref)->whereNotIn('booking_id', $bookingIds)->delete()` (`:213`) | **An unauthenticated request containing one dummy booking deletes every other booking for that appointment.** One `curl` wipes a tenant's entire calendar |
| **A2** | `POST /appointments/{ref}/slots` does the same to slots (`:158`) | One request deletes every appointment type the tenant configured |
| **A3** | **`GET /appointments/{ref}/bookings` returns raw booking rows** — `user_name`, `user_email`, `user_phone`, `notes`. So does `GET /appointments/{ref}`, which eager-loads `bookings` and maps every field | **Unauthenticated PII disclosure** of every person who ever booked. Names, emails, phone numbers and free-text notes |
| **A4** | `DELETE /appointments/{ref}` — and the migration declares `cascadeOnDelete()` from both child tables | Unauthenticated deletion of an appointment **and every slot and every booking beneath it**. The foreign keys, which are otherwise good practice, make this worse |
| **A5** | `listAppointments()` returns every appointment on the deployment; no route checks ownership; `website_reference_num` exists on the table and is **never written and never filtered on** | Cross-tenant read, and after auth, cross-tenant write unless scoping lands with it |
| **A6** | The **externals SDK exposes `create()` and `delete()`** (`external/appointments/api.ts`) — admin operations on the public storefront SDK | The destructive surface is not merely reachable; it is published as client API |
| **A14** | `saveAppointment()` is `Appointment::updateOrCreate(['reference_num' => $input], …)` on a **client-supplied reference** (`:94`) | Any appointment's name and title is overwritable by naming its reference — the same defect as [ecommerce **E3**](../ecommerce/02-current-state-audit.md), unauthenticated |

**A1 and A3 are the ones to act on today.** A1 destroys data and A3 leaks personal data, both
without credentials, on every deployment running this module.

This is not a Site Users problem and does not wait for [02](02-site-users-guard.md).
Phase **P0** closes it and depends on nothing.

## 1.3 The admin UI performs the destructive operation itself

The audit's first pass did not read the front end. It changes the severity of **A1** and
**A2** from "an attacker could" to "the product does, routinely".

| # | Finding | Consequence |
|---|---|---|
| **A15** | Writes are **fire-and-forget**. `dataStore.addAppointment()` does `void this.syncAppointment(...)` (`DataStore.ts:405–410`), and `saveAppointmentSlots()` does `void this.syncAppointmentSlots(...)` (`:485–494`). Failures are `console.error` and nothing else | **A failed save looks identical to a successful one.** The UI updates, `localStorage` updates, the server never hears, and the operator finds out at the next reload |
| **A16** | **Every slot mutation is a full-array replace.** `handleSlotSubmit`, `toggleSlotActive` and `confirmDeleteSlot` each rebuild the whole array from the client cache and call `saveSlots()` → the bulk-replace endpoint (`appointments.ts:474`, `:491`, `:517`) | The client cache is the source of the payload. **If a fetch failed, or the cache is stale, or two admins have different caches, saving deletes every slot missing from that snapshot.** This is **A2** fired by the tenant's own UI, with no attacker and no warning |
| **A17** | `saveBooking()` in the preview does the same for bookings: read the cached array, push one, send **all of them** (`appointment-preview.ts:95–111`) | Guaranteed lost updates. Two bookings made from two stale caches: the second replaces the first, and the first customer's booking is deleted |
| **A18** | Reference numbers are generated **in the browser** — `'a'` plus 6 characters from `Math.random()`, 7 total (`AppointmentsController.ts:22–29`, duplicated at `appointments.ts:133–140`) | Non-cryptographic, collision-prone, and unchecked server-side. `updateOrCreate` (**A14**) means a collision silently overwrites someone else's appointment rather than failing |
| **A19** | Slot and booking ids are `'apt_'`/`'bk_'` + `Date.now()` + `Math.random().toString(36).substr(2,9)`, generated client-side in three places | Same class of problem for the identifiers that key every booking |
| **A20** | **Duplicating an appointment copies its bookings.** `duplicateAppointmentObject()` clones every booking — names, emails, phone numbers — into the new object under fresh ids (`AppointmentsController.ts:80–92`) | Duplicating a *template* silently clones real customers' personal data into a second record, doubling the tenant's PII footprint and creating bookings nobody made |
| **A21** | There is **no delete action** in the appointment list. The dropdown offers Configure, Preview, Duplicate, Response | `DELETE /appointments/{ref}` (**A4**) exists and is unreachable from the UI. A tenant cannot remove an appointment object |
| **A22** | The Response view's cancel handler navigates to `view: 'events'` (`AppointmentsController.ts:279`) | A copy-paste defect: leaving the appointments email builder drops the operator into the events module |
| **A23** | Two different `escapeHtml()` implementations — a five-`replace` chain (`AppointmentsController.ts:243–250`) and a `div.innerHTML` round-trip (`appointments.ts:579–583`, `appointment-preview.ts:586`) | Both are used; they behave differently on edge cases; neither is shared |
| **A24** | The UI is built on **inline `onclick` handlers bound to `window` globals** — `registerAppointmentHandlers()` assigns `openSlotModal`, `editSlot`, `deleteSlot`, `toggleSlotActive`, `exportWidget`, and the preview does the same for `selectSlot`, `selectDate`, `selectTime`, `prevMonth`, `nextMonth` | Global namespace collisions across lazily-loaded modules, and ids interpolated into JavaScript string literals inside HTML attributes |
| **A25** | `SLOTS_STORAGE_KEY` and `BOOKINGS_STORAGE_KEY` are declared at `appointments.ts:82–83` and **never used** — everything routes through `dataStore`, which has its own keys | Dead constants that name a second storage layer, next to code that reads a third (`appointment-export.ts`, **A26**) |

## 1.4 The public widget

`admin/utils/appointment-export.ts` generates a **1,038-line** standalone HTML booking widget —
the module's public surface, and the largest single file in it.

| # | Finding | Consequence |
|---|---|---|
| **A26** | `exportWidget()` builds the widget with a config of `{ title, subtitle, theme, primaryColor }` and **no `apiEndpoint`** (`appointments.ts:544–549`). Every data path then takes the fallback branch: slots from `localStorage.getItem('afs_appointment_slots')`, bookings from `'afs_appointment_bookings'`, and new bookings written back there (`appointment-export.ts:647–685`) | On a third-party site those keys are empty, so the widget renders *"No appointment types available"*. Where they are not empty, **the widget reads and writes the visitor's own browser storage** — and any booking a visitor makes is stored in, and visible only to, their own browser |
| **A27** | Even with `apiEndpoint` set, `saveBooking()` POSTs a **single booking object** (`:673–679`) to an endpoint that reads `$request->input('bookings', [])` | The payload parses as an empty array, the loop body never runs, `$bookingIds` stays empty so the delete is skipped, and the endpoint returns `{"success": true, "bookings": []}`. **The booking is silently discarded and the customer is shown a confirmation.** The one saving grace: because the array is empty, this particular call does *not* wipe the calendar |
| **A28** | The widget fetches `apiEndpoint + '/slots'` and `+ '/bookings'` and expects bare arrays; the real endpoints return `{ success, slots }` and `{ success, bookings }` | `data.filter(...)` on an object throws. Every documented integration path is broken in a different way from the last |
| **A29** | `fetchBookings()` pulls **every booking for the appointment** into the public widget in order to grey out taken times — and those rows carry `user_name`, `user_email`, `user_phone` and `notes` (**A3**) | The intended public integration ships every customer's personal data to every visitor of the tenant's site. **Availability must be a computed projection, never the booking list** ([05](05-availability-and-scheduling.md) §5.2) |

## 1.5 No notion of who booked

| # | Finding | Consequence |
|---|---|---|
| **A7** | A booking's only identity is `user_name` / `user_email` / `user_phone`, free text | Two bookings by the same person are unrelated rows. "My appointments" is impossible |
| **A8** | No cancel or reschedule path — `status` exists (`pending` default) and **nothing anywhere transitions it**. `handleBookingSubmit` hard-codes `'pending'` | Every booking is pending forever. A customer who needs to change one must contact the tenant |
| **A9** | No booking token. The only way back to a booking is knowing its `booking_id` — which is also an identifier appearing in admin lists and afs-connect payloads | The emailed link is the whole access model and its credential is not secret. Fixed by `access_token` from the first migration — **no backfill, because the application is unreleased and there are no bookings** ([02](02-site-users-guard.md) §2.5.1) |
| **A30** | The Response view configures mail handlers through `EmailConstructionBuilder` under `ViewsCategories.APPOINTMENTS` (`AppointmentsController.ts:252–292`). **No server-side code reads them**, and nothing sends on booking | The same shape as [forms **F57**](../forms/01-current-state-audit.md): a configuration screen wired to nothing. **No confirmation email is sent when a booking is made** |

## 1.6 Capacity and correctness

| # | Finding | Consequence |
|---|---|---|
| **A10** | **`max_bookings` is never enforced.** No count, no check, no constraint, server-side or client-side | A slot with `max_bookings = 1` accepts unlimited bookings. Double-booking is not a race — it is the default behaviour |
| **A11** | No unique constraint on `(reference_num, slot_id, date, time)` | Even with a check, concurrent requests would both pass it |
| **A31** | The booking UI treats a time as taken if **any** booking exists at it — `bookedTimes.includes(time)` (`appointment-preview.ts:290`) — ignoring `maxBookings` entirely, and **without excluding cancelled bookings** | A slot configured for 3 concurrent bookings blocks after 1; and once `status` transitions exist (**A8**), a cancelled booking will permanently block its time |
| **A12** | `date` is a `date` and `time` is a **string**; no timezone anywhere | "10:00" in whose timezone? A tenant and a customer in different zones disagree about when the appointment is |
| **A32** | Every date comparison uses local browser time: `new Date(booking.date) >= new Date()` (`appointments.ts:335`) parses `YYYY-MM-DD` as **UTC midnight** and compares it to local now | Off-by-one day in negative-offset zones, and today's already-past appointments still count as upcoming |
| **A13** | `buffer_time` is stored and **never applied server-side** | Back-to-back bookings ignore the gap the tenant configured. It *is* applied in the preview's `generateTimeSlots()` (`appointment-preview.ts:307–326`), so the client and server disagree about what is bookable |
| **A33** | An empty `available_days` array means **every day is available** — `availableDays.length === 0 \|\| availableDays.includes(dayOfWeek)` (`appointment-preview.ts:242`) | An operator who unticks all days opens the calendar completely, silently, which is the opposite of the intent |
| **A34** | `slot.active` is fetched and mapped but **never filtered** in `renderSlotSelector()` or the calendar (`appointment-preview.ts:149`, `:209`) | Deactivating a slot does nothing on the booking surface. The exported widget *does* filter on `active` (`appointment-export.ts:651`, `:656`), so the two booking surfaces disagree |
| **A35** | There is no concept of: a staff member, a room or resource, a date-specific exception, a holiday, a lead time, a booking horizon, a recurring booking, a waitlist, a deposit, a duration that varies by service, or a location | The module models *one* anonymous calendar with weekly opening hours. Everything a scheduling product does beyond that is absent — see [00](00-overview.md) §0.6 |

**A10** is worth stating plainly: the module's central promise — a bookable slot with a
capacity — is not implemented, on either side of the wire.

## 1.7 What is good

Genuinely, and the plan builds on all of it:

- **The three-table shape is right**: appointment → slots → bookings.
- **The migration is the best-documented in the codebase**, with real foreign keys, named
  indexes and explanatory comments. The cascade is dangerous only because **A4** is
  unauthenticated.
- `unique(reference_num, slot_id)` and `unique(reference_num, booking_id)` already exist —
  the module already knows how to use a unique index, which is exactly the tool **A11** needs.
- **`status` on bookings exists** and is ready for a real lifecycle (**A8**).
- **The preview is a complete, working booking flow** — slot selector, month calendar, time
  grid, booking form, confirmation. It is the right UX and it is the basis for the public
  surface ([11](11-public-surface-and-widget.md) §11.5) rather than something to discard.
- **`buffer_time` is correctly implemented in `generateTimeSlots()`** — the algorithm is right;
  it is only on the wrong side of the wire (**A13**).
- `escapeHtml` is defined and used on user-supplied strings in both admin files, which is more
  discipline than [forms](../forms/01-current-state-audit.md) showed (**F35**, **F36**).
- `website_domain` / `website_reference_num` anticipate multi-site scoping.
- The SDK has a README, which is more than most modules here.

## 1.8 Summary

| Group | Findings | Phase |
|---|---|---|
| Security | **A1**–**A6**, **A14** | **P0** — immediately, no dependencies |
| The UI's own destructive writes | **A15**–**A17** | **P0** — the same fix removes both |
| Client-side identifiers and state | **A18**–**A20**, **A25** | P1 |
| Capacity and time correctness | **A10**–**A13**, **A31**–**A34** | P1–P2 — before accounts make bookings more valuable |
| The public widget | **A26**–**A29** | P2 |
| Identity and lifecycle | **A7**–**A9**, **A30** | P3–P5 — [02](02-site-users-guard.md) |
| Absent scheduling capability | **A35** | P2–P8 |
| UI quality | **A21**–**A24** | P6 |

Two orderings are not negotiable. **Remove bulk replace before anything else** — it is
simultaneously the unauthenticated attack (**A1**) and the tenant's own data-loss path
(**A16**), and one change closes both. And **fix capacity before identity**: a double-booking
discovered by a signed-in customer with an account and a history is worse than one discovered
by the tenant.
