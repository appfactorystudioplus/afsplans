# 11 — The Public Surface, SDK and Builder

Closes **V2**, **V5**, **V6**, **V7**, **V26**.

## 11.1 What exists

`resources/js/external/events/api.ts` — 78 lines, four methods, posting form-encoded bodies to
`/_portal_/admin/core/php/request.php`.

Three things are wrong with it, and one of them is not the module's fault:

| | |
|---|---|
| **V2** | The base URL is a **legacy pre-Laravel endpoint**. `public/_portal_` does not exist in this repository at all, and no handler for `admin_events_get_events` exists anywhere. The SDK has never worked against this codebase |
| **V6** | `createEvent` and `updateEvent` are **admin writes on the public storefront SDK** — the same shape as [appointments **A6**](../appointments/01-current-state-audit.md) |
| **V7** | The admin controller does `new ExternalEventsAPI()` at module scope, but the bundle exposes only `window.Portal` — the class is never a global, so the events module should fail to load entirely |
| **V26** | There are **three copies** of the SDK: the source, a checked-in build at `public/assets/api/afsapi.js`, and the remote `https://{afssdk}.appfactory.studio/sdk.js` the admin actually loads |

## 11.2 The three surfaces

Split from the first commit ([02](02-site-users-guard.md) §2.2), because that is the lesson every
sibling module paid for.

| Prefix | Contents | Auth |
|---|---|---|
| `/portal/api/v1/events/*` | **Admin** — CRUD events, occurrences, sessions, ticket types, promo codes, list registrations, check-in, export | `ApiAuthMiddleware` + site scope |
| `/api/events/public/*` | **Public read** — events, occurrences, ticket types, remaining capacity | Open; throttled |
| `/api/events/register/*` | **Registration** — hold, order, pay, waitlist | Open; throttled; idempotent |
| `/api/events/attendee/*` | **Attendee** — my ticket, my registrations, cancel, transfer | `access_token` or Site Users session |
| `/api/events/scan/*` | **Check-in** — manifest, sync | Scoped scanner token ([09](09-checkin-and-onsite.md) §9.5) |

Five surfaces rather than three, because the scanner and the attendee have genuinely different
credentials from both the admin and the public.

## 11.3 The public projection

What `/api/events/public/*` returns is a **projection**, built by a separate controller with no
code path that can reach an admin field — not a shared handler with a flag
([forms/02](../forms/02-security-and-tenancy.md) §2.2).

| Included | Excluded |
|---|---|
| Event name, description, images, category | Autoincrement `id` |
| Occurrence times, zone, status | `website_domain`, `website_reference_num` |
| Venue name, address, directions, accessibility | Internal notes |
| Ticket types: name, price, availability **state** | `registered_count`, `reserved_count` as raw numbers |
| Remaining count **only** when `display.show_remaining` and below the threshold | Revenue, order counts |
| Sessions, speakers (public fields) | **Any attendee name or email** |
| Whether a waitlist is available | Promo codes, access codes |
| Refund policy text | `direct_payment_*` references |
| Structured data (§11.7) | Hidden and code-required ticket types |

Two rules with teeth:

**No attendee data, ever.** This is [appointments **A3**](../appointments/01-current-state-audit.md)
— a public endpoint returning every customer's name, email and phone — and it is the single
leak this module exists to not repeat. Listing registrations is admin-only by construction:
there is no public route that returns them.

**Raw counters are not public.** `registered_count` tells a competitor how a tenant's event is
selling. The public payload carries `available` / `low` / `sold_out`, and a number only when the
tenant has chosen to show one ([04](04-database-schema.md) §4.12).

Hidden and code-required ticket types are **absent from the payload**, not merely hidden in the
rendering — verified against the raw API response
([06](06-ticketing-and-capacity.md) §6.11).

## 11.4 The SDK

`resources/js/external/events/api.ts`, rewritten. **`createEvent` and `updateEvent` are removed**
(**V6**) — the storefront SDK gets no admin write.

```ts
interface PortalEventsAPI {
    // Discovery
    list(filter?: PortalEventsFilter): Promise<PortalEventSummary[]>;
    get(reference_num: string): Promise<PortalEvent>;
    occurrences(event_ref: string, filter?: { from?: string; to?: string }): Promise<PortalOccurrence[]>;
    occurrence(occurrence_ref: string): Promise<PortalOccurrenceDetail>;
    ticketTypes(occurrence_ref: string, accessCode?: string): Promise<PortalTicketType[]>;
    sessions(occurrence_ref: string): Promise<PortalSession[]>;

    // Registration
    hold(payload: PortalEventHoldPayload): Promise<PortalEventHold>;
    releaseHold(hold_ref: string): Promise<void>;
    validatePromo(occurrence_ref: string, code: string): Promise<PortalPromoResult>;
    register(payload: PortalEventRegisterPayload): Promise<PortalEventOrder>;
    joinWaitlist(payload: PortalWaitlistPayload): Promise<PortalWaitlistEntry>;

    // Attendee
    registration(reference_num: string, token?: string): Promise<PortalRegistration>;
    myRegistrations(): Promise<PortalRegistration[]>;      // Site Users session
    cancelRegistration(reference_num: string, token?: string): Promise<void>;
    calendarUrl(reference_num: string, token?: string): string;

    // Rendering
    renderEventList(container: HTMLElement, options?: PortalEventListOptions): PortalEventsInstance;
    renderEvent(container: HTMLElement, ref: string, options?: PortalEventOptions): PortalEventsInstance;
    renderRegistration(container: HTMLElement, occurrence_ref: string, options?: PortalRegistrationOptions): PortalEventsInstance;
}
```

Four properties carried over from what the forms SDK got right and wrong
([forms/12](../forms/12-externals-sdk.md)):

- **Renderers return an instance with `destroy()`.** A mount without an unmount leaks in any
  single-page host.
- **DOM events** — `portal:event:registered`, `portal:event:hold-expiring`,
  `portal:event:waitlisted`, `portal:event:error` — because a tenant embedding a widget usually
  cannot edit the script that mounted it but can always add a listener.
- **No side effects in the browser.** The SDK does not send mail, create notifications or emit
  completion analytics; the server does ([10](10-communications.md) §10.2).
- **The registration widget renders the forms module's questions** through
  `Portal.forms.renderForm` ([forms/12](../forms/12-externals-sdk.md) §12.3) rather than
  reimplementing a question renderer ([07](07-registration-and-attendees.md) §7.4).

### The class-not-a-global bug (V7)

The admin controller's `new ExternalEventsAPI()` is fixed by **not doing that**: the admin uses
its own typed admin client (`controllers/events/api.ts`,
[12](12-admin-dashboard.md) §12.9) against `/portal/api/v1/events/*`, and the externals SDK is
consumed — where it is consumed at all — as `window.Portal.events`.

That also removes the oddity of an **admin** screen calling a **storefront** SDK, which is how
`createEvent` came to be on the public surface in the first place.

## 11.5 `portal.d.ts`

`portal.d.ts` is the contract, it is **hand-maintained**, and consuming projects take a **copy**
of it rather than importing the externals source
([forms/12](../forms/12-externals-sdk.md) §12.6).

So every change here lands in `portal.d.ts` in the same pass, and the copies in consuming
projects are refreshed as part of shipping. Specifically:

- `ExternalEventsAPI`'s ambient `declare class` (admin `portal.d.ts:747`) is **removed** — it is
  what made **V7** compile.
- `PortalEventsAPI` is replaced with §11.4's shape.
- `createEvent` / `updateEvent` are deleted from the declaration, not deprecated: they have never
  worked (**V2**), so there is no caller to keep working.

### The three builds (V26)

One source, one build, one location. `public/assets/api/afsapi.js` is either regenerated by the
build or deleted; the admin's remote `sdk.js` and the local build must not be two different
vintages. Until that is resolved, a fix to `external/events/api.ts` reaches an unknown subset of
consumers — which is a release-process defect, and it is called out in
[14](14-rollout-and-testing.md) §14.6.

## 11.6 The builder (V5)

`componentData.events` is fetched (`builder/main.ts:38`, `:72`) and no component is registered.

Five blocks, following the model in
[site_users/12](../site_users/12-builder-integration.md) §12.1 — block → component type →
`script` — and reusing the shared `isEditorMode()` / `waitForPortal()` helpers rather than adding
copies:

| Block | Renders |
|---|---|
| **Event list** | Upcoming occurrences, filterable by category, date range or tag; grid, list or calendar layout |
| **Event detail** | Description, schedule, venue, speakers, agenda, ticket types, remaining capacity |
| **Registration** | Ticket selection, attendee fields, custom questions, payment |
| **Calendar** | A month view of a tenant's occurrences |
| **Featured event** | A single event promotion, for a home page |

Three behaviours these blocks need and are easy to omit:

- **Editor mode renders sample data.** A block showing "no upcoming events" in the builder looks
  broken, and a builder that fetches live data on every keystroke is slow.
- **The registration block reflects state honestly** — sold out, waitlist, sales-not-open,
  code-required — rather than a button that fails on submit
  ([06](06-ticketing-and-capacity.md) §6.10).
- **Blocks inherit the site's styling** through the builder's design tokens, with the option to
  isolate in an iframe for a hostile cascade
  ([forms/07](../forms/07-theming-and-layout.md) §7.8).

## 11.7 Hosted pages and SEO

Publishing should produce something usable immediately.

| Surface | |
|---|---|
| **Event page** | `https://{site}/events/{slug}` — hosted, themed, listing occurrences |
| **Occurrence page** | `.../{slug}/{occurrence}` — the registration target |
| **Listing page** | All upcoming events, filterable |
| **Embed** | Script + mount div, or iframe |
| **QR code** | To the occurrence page, for print |
| **Share** | Prefilled links and social preview images |

### Structured data

Every occurrence page emits `schema.org/Event` JSON-LD — `name`, `startDate` **with offset**,
`endDate`, `eventStatus` (including `EventCancelled` and `EventPostponed`),
`eventAttendanceMode` (offline / online / mixed), `location` (`Place` or `VirtualLocation`),
`organizer`, `image`, `description`, and `offers` with price, currency, availability and
`validFrom`.

This is not decoration: it is what puts an event in Google's event results, and it is the
cheapest distribution an events product has. `eventStatus` matters most — a cancelled event that
still advertises itself as scheduled sends people to a closed door.

Plus: Open Graph and Twitter cards, canonical URLs, an events sitemap, and — for a series —
`eventSchedule` on the event page with individual occurrence pages beneath it.

## 11.8 Embedding and isolation

The same constraints as the forms embed
([forms/12](../forms/12-externals-sdk.md) §12.7): a strict host CSP may block the bundle or
inline styles, and the iframe embed is the answer. The embed documentation says so up front
rather than letting a tenant debug a blank div.

The registration widget additionally needs the **payment element** to render inside the host
page, which is a Direct Payments concern and inherits its CSP requirements — so the embed
snippet for a paid event names them.

## 11.9 Acceptance

- No SDK file references `/_portal_/admin/core/php/request.php` (**V2**).
- The SDK exposes **no** admin write (**V6**).
- The admin does not construct `ExternalEventsAPI`; the ambient `declare class` is gone
  (**V7**).
- `GET /api/events/public/*` returns **no** attendee name, email or phone — asserted field by
  field against the raw response.
- Raw `registered_count` never appears in a public payload.
- A code-required ticket type is absent from the public response until the code is supplied.
- An unpublished or private event 404s publicly and loads in the admin.
- `Portal.events.hold()` reserves inventory and the widget shows a countdown.
- The registration widget renders custom questions through `Portal.forms`.
- `destroy()` unmounts cleanly and fires `portal:event:destroyed`.
- Five builder blocks are registrable and render sample data in editor mode.
- The registration block shows sold-out and waitlist states without a failing button.
- An occurrence page validates as `schema.org/Event` in Google's Rich Results test, including
  `offers` and `eventAttendanceMode`.
- A cancelled occurrence emits `eventStatus: EventCancelled`.
- `portal.d.ts` declares the new surface, and the consuming copies are refreshed in the same
  release.
- Exactly one SDK build is authoritative, and the release notes say which (**V26**).
