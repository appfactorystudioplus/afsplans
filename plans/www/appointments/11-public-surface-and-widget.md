# 11 — The Public Surface, SDK and Widget

Closes **A3**, **A6**, **A26**, **A27**, **A28**, **A29**.

## 11.1 What exists

Three public-facing things, all broken in different ways.

| | |
|---|---|
| **The SDK** (`external/appointments/api.ts`) | `list()`, `get()`, `create()`, `delete()` — **admin operations published as client API** (**A6**). `get()` hits an endpoint that eager-loads and returns **every booking's name, email, phone and notes** (**A3**) |
| **The preview** (`appointment-preview.ts`, 590 lines) | A complete, well-built booking flow — slot cards, month calendar, time grid, form, confirmation. It writes through the bulk-replace path (**A17**) and lives only in the admin |
| **The widget** (`utils/appointment-export.ts`, 1,038 lines) | The intended public surface. Cannot book (**A26**, **A27**), cannot parse the API's responses (**A28**), and downloads every customer's details to grey out taken times (**A29**) |

There is a lot of good work here pointing at an API that does not match it.

## 11.2 The four surfaces

Split from P0 ([02](02-site-users-guard.md) §2.2), because one prefix serving two audiences is
how this module ended up with the wrong middleware.

| Prefix | Contents | Auth |
|---|---|---|
| `/portal/api/v1/appointments/*` | **Admin** — configuration, services, staff, resources, schedules, exceptions, **list bookings**, reports | `ApiAuthMiddleware` + site scope |
| `/api/appointments/public/*` | **Public read** — appointment, active services, staff profiles, **availability** | Open; throttled |
| `/api/appointments/book/*` | **Booking** — hold, book, waitlist | Open; throttled; idempotent |
| `/api/appointments/manage/*` | **Customer** — view, reschedule, cancel, my appointments | `access_token` or Site Users session |

## 11.3 The public projection

Built by a separate controller with **no code path that can reach a booking row** — not a shared
handler with a flag ([forms/02](../forms/02-security-and-tenancy.md) §2.2).

| Included | Excluded |
|---|---|
| Appointment name, title, description, timezone | Autoincrement `id` |
| Active services: name, description, duration, price | `website_domain`, `website_reference_num` |
| Staff: name, title, photo, bio — when `show_staff_names` | Staff email, phone, `portal_user_id` |
| **Availability** as computed slots ([05](05-availability-and-scheduling.md) §5.2) | **Every booking row** |
| `remaining` — only when `show_remaining` | Raw counters |
| Location, directions, accessibility notes | Internal notes, `settings` |
| Cancellation policy text | `direct_payment_*` references, calendar tokens |

**No booking data, ever.** This is **A3** and **A29** — the endpoint that hands every customer's
name, email, phone and free-text notes to anyone who asks, and the widget that was built to
consume it.

The fix is structural rather than a field filter: **availability is a computed projection**
([05](05-availability-and-scheduling.md) §5.2), so the public surface has no reason to see a
booking. A widget greying out taken times receives *times*, not *bookings*.

Inactive services are **absent from the payload** (**A34**), not merely hidden in rendering.

## 11.4 The SDK

`external/appointments/api.ts`, rewritten. **`create()` and `delete()` are removed** (**A6**) —
the storefront SDK gets no admin write.

```ts
interface PortalAppointmentsAPI {
    // Discovery
    get(reference_num: string): Promise<PortalAppointment>;
    services(reference_num: string): Promise<PortalService[]>;
    staff(reference_num: string, service_ref?: string): Promise<PortalStaff[]>;

    // Availability
    availability(payload: PortalAvailabilityQuery): Promise<PortalAvailability>;
    nextAvailable(service_ref: string, staff_ref?: string, count?: number): Promise<PortalSlot[]>;

    // Booking
    hold(payload: PortalHoldPayload): Promise<PortalHold>;
    releaseHold(hold_ref: string): Promise<void>;
    book(payload: PortalBookPayload): Promise<PortalBooking>;
    joinWaitlist(payload: PortalWaitlistPayload): Promise<PortalWaitlistEntry>;

    // Manage
    booking(reference_num: string, token?: string): Promise<PortalBooking>;
    myBookings(): Promise<PortalBooking[]>;               // Site Users session
    reschedule(reference_num: string, starts_at: string, token?: string): Promise<PortalBooking>;
    cancel(reference_num: string, token?: string, reason?: string): Promise<void>;
    calendarUrl(reference_num: string, token?: string): string;

    // Rendering
    renderBooking(container: HTMLElement, reference_num: string, options?: PortalBookingOptions): PortalAppointmentsInstance;
    renderManage(container: HTMLElement, booking_ref: string, token: string): PortalAppointmentsInstance;
}
```

`nextAvailable()` earns its place: *"the next three available times"* is what turns a refusal
([06](06-booking-engine.md) §6.6) and an empty day
([05](05-availability-and-scheduling.md) §5.7) into a booking, and every caller needs it.

Four properties carried from what the sibling SDKs got right and wrong
([forms/12](../forms/12-externals-sdk.md), [events/11](../events/11-public-surface-and-sdk.md)):

- **Renderers return an instance with `destroy()`.** A mount without an unmount leaks in any
  single-page host.
- **DOM events** — `portal:appointment:booked`, `:hold-expiring`, `:cancelled`,
  `:rescheduled`, `:error` — because a tenant embedding a widget usually cannot edit the script
  that mounted it but can always add a listener.
- **No side effects in the browser.** No mail, no notifications, no completion analytics
  ([10](10-communications-and-calendar.md) §10.2).
- **Custom questions render through `Portal.forms`**
  ([forms/12](../forms/12-externals-sdk.md) §12.3) rather than a second question renderer
  ([08](08-booking-lifecycle.md) §8.3).

`portal.d.ts` is the contract, is hand-maintained, and consuming projects take a **copy** of it
rather than importing the externals source — so every change here lands there in the same pass
and the copies are refreshed as part of shipping
([forms/12](../forms/12-externals-sdk.md) §12.6).

## 11.5 The widget

`utils/appointment-export.ts` — 1,038 lines, and the module's largest file. It is **kept and
repaired**, not discarded: the UX is right, the state machine is right, and only the data layer
is wrong.

| Defect | Fix |
|---|---|
| **A26** — no `apiEndpoint`, so it reads and writes the visitor's `localStorage` | The generator **requires** an endpoint and the appointment reference. There is no `localStorage` fallback at all — a widget on a third-party site must never persist a tenant's calendar in a visitor's browser |
| **A27** — POSTs a single object to a bulk endpoint, which discards it and returns success | POSTs to `/api/appointments/book`, which creates one booking ([06](06-booking-engine.md) §6.2) and returns it. **A confirmation is shown only on a 201** |
| **A28** — expects bare arrays, receives `{ success, slots }` | One typed client shared with the SDK, so the shapes cannot drift again |
| **A29** — downloads every booking to grey out times | Calls `availability()`; receives times, never bookings (§11.3) |
| — | Holds a time while the form is completed, with a countdown ([06](06-booking-engine.md) §6.5) |
| — | Renders staff selection, service categories, timezone display, and the "next three dates" affordance |

**The preview and the widget become one component.** Today `appointment-preview.ts` (590 lines)
and the widget generator (1,038 lines) are two implementations of the same booking flow that will
diverge — and already have: the widget filters on `slot.active` and the preview does not
(**A34**). The admin preview renders the **real public component** against a preview endpoint,
which is the same rule [forms/05](../forms/05-form-builder.md) §5.2 arrives at for its canvas.

### Delivery

| Method | |
|---|---|
| **Hosted booking page** | `https://{site}/book/{slug}` — themed, mobile-first, no embedding required |
| **Inline embed** | Script + mount div |
| **Iframe embed** | Full style isolation for a hostile host cascade |
| **Popup / button** | A "Book now" button opening a modal |
| **Direct service link** | Straight to one service, skipping selection |
| **QR code** | To the booking page, for print and shop windows |

The share dialog generates all of them with the reference filled in
([12](12-admin-dashboard.md) §12.7), so a tenant copies rather than composes — which is what
turns "configured" into "live".

## 11.6 The builder

Blocks for the site builder, following
[site_users/12](../site_users/12-builder-integration.md) §12.1 — block → component type →
`script` — and reusing the shared `isEditorMode()` / `waitForPortal()` helpers:

| Block | Renders |
|---|---|
| **Booking widget** | The full flow (§11.5) |
| **Service list** | Services with durations and prices, each linking into booking |
| **Staff list** | Profiles, each linking to their availability |
| **Next available** | *"Next available: Thursday 14:00 — Book"*. The highest-converting block, and one query |
| **Book-now button** | Opens the modal |

Editor mode renders sample data — a block showing "no availability" in the builder reads as
broken, and fetching live availability on every keystroke is slow.

## 11.7 Hosted pages and SEO

- `https://{site}/book/{slug}` — the appointment; and `/book/{slug}/{service}` per service.
- **`schema.org`** structured data: `LocalBusiness` or `Service` with `areaServed`,
  `openingHoursSpecification` generated from the schedules, and `potentialAction` as a
  `ReserveAction` pointing at the booking page. This is what puts a "Book" affordance in search
  results, and the opening hours are data the module already holds.
- Open Graph and Twitter cards; canonical URLs; a sitemap entry per service.
- **Mobile-first**: the majority of bookings are made on a phone, and the time grid is where a
  desktop-first design fails.

## 11.8 Embedding constraints

A strict host CSP may block the bundle or inline styles; the iframe embed is the answer, and the
embed documentation says so up front rather than letting a tenant debug a blank div
([forms/12](../forms/12-externals-sdk.md) §12.7).

A widget for a **paid** service additionally renders the Direct Payments payment element inside
the host page, which inherits its CSP requirements — so the snippet for a paid service names
them.

## 11.9 Acceptance

- `GET /api/appointments/public/*` returns **no** booking row, name, email, phone or note —
  asserted field by field against the raw response (**A3**, **A29**).
- Availability responses contain times, not bookings.
- An inactive service is absent from the public payload (**A34**).
- The SDK exposes **no** admin write (**A6**).
- The generated widget **requires** an endpoint; there is no `localStorage` code path
  (**A26**).
- A booking made through the widget **exists in the database**, and the confirmation appears only
  on a 201 (**A27**).
- The widget parses the real API's response shapes (**A28**).
- The widget holds a time while the form is completed and shows a countdown.
- The admin preview and the public widget are **one component**; a change to either appears in
  both.
- `destroy()` unmounts cleanly and fires `portal:appointment:destroyed`.
- `nextAvailable()` returns three times and is used by the empty-day and refusal paths.
- Custom questions render through `Portal.forms`.
- Five builder blocks are registrable and render sample data in editor mode.
- A booking page validates as structured data with `openingHoursSpecification` derived from the
  schedules.
- The booking flow is usable one-handed on a 375 px screen.
- `portal.d.ts` declares the new surface, and consuming copies are refreshed in the same
  release.
