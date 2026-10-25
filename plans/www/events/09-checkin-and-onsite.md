# 09 — Check-in and On-site

The part of an events product that is used for four hours a year, under pressure, by volunteers,
in a room with no signal — and that determines what everyone remembers about the software.

## 9.1 What exists

Nothing. There is no attendee record to check in ([01](01-current-state-audit.md) **V3**).

## 9.2 The shape

```
  before        manifest downloaded to each device        §9.5
                badges pre-printed or queued              §9.8
     │
  at the door   scan QR ──► validate locally ──► admit    §9.4
                          └► queue check-in
                manual search by name/email/reference     §9.4
                walk-in registration                      §9.9
     │
  inside        session scanning, in and out              §9.7
     │
  after         sync · reconcile · attendance report      §9.6, 13 §13.4
```

## 9.3 The ticket and its code

Every registration carries `ticket_code` — **separate from `reference_num` and from
`access_token`** ([03](03-architecture.md) §3.5).

| Value | Purpose | Property |
|---|---|---|
| `reference_num` | The record's identifier | Used in the admin, appears in URLs |
| `access_token` | The emailed attendee page's credential | High-entropy, long-lived |
| `ticket_code` | **The QR payload** | High-entropy, **signed**, revocable |

Three values because they have three lifetimes. Transferring a ticket invalidates the
`ticket_code` and issues a new one, while the registration keeps its reference
([07](07-registration-and-attendees.md) §7.8). Using one value for all three means a transferred
ticket still scans, and a QR photographed from someone's phone grants access to their attendee
page.

### The payload

The QR carries a compact signed token — occurrence reference, registration reference, ticket
code, an issue timestamp, and an HMAC over them with a **per-occurrence** key.

Signing is what makes **offline validation** possible (§9.5): a scanner with no network verifies
the signature and knows the ticket is genuine without asking the server. It cannot know whether
the ticket was cancelled since — which is what the manifest is for.

The QR is delivered in the confirmation email, on the attendee page, and in a wallet-pass file
where the tenant enables it.

## 9.4 Scanning

A **PWA**, not a native app ([00](00-overview.md) §0.3) — installable, camera-capable, and
distributable by a link the day before, which is what a volunteer team actually needs.

| Capability | |
|---|---|
| Camera scan | Continuous, with an audible and haptic result |
| Result | **Large, unambiguous**: green admit with the name, red refuse with the reason |
| Manual search | By name, email, reference or the last four of the code — for a dead phone battery |
| Ticket detail | Ticket type, sessions, dietary/accessibility flags, notes, amount outstanding |
| Undo | Reverse the last check-in for at least 60 seconds — mis-scans happen constantly |
| Multi-device | Any number of scanners on one occurrence |
| Roles | A scanner operator is not an admin ([12](12-admin-dashboard.md) §12.8) |

### Refusal reasons, stated plainly

| Reason | Display |
|---|---|
| Already checked in | *"Already admitted at 09:14"* — with who scanned it |
| Cancelled | *"Cancelled 12 Nov"* |
| Transferred | *"Transferred — the new ticket is valid"* |
| Wrong occurrence | *"This ticket is for 14 March"* |
| Wrong session | *"Not registered for this workshop"* |
| Payment outstanding | *"£40 due"* — admit-anyway is an operator decision, and it is logged |
| Invalid signature | *"Not a valid ticket"* |
| Outside the scan window | *"Doors open at 08:30"* |

A scanner that says "invalid" for all eight is a scanner whose operator waves people through.

## 9.5 Offline

The design constraint ([00](00-overview.md) §0.6 D5). Events happen in basements, marquees and
village halls.

### The manifest

Before the doors open, each device downloads a **signed manifest** for the occurrence:

```
occurrence · starts_at · timezone · signing key (scoped, expiring)
registrations: [ { ticket_code_hash, name, ticket_type, status, sessions[], flags[] } ]
```

- Downloaded over authenticated HTTPS, stored in IndexedDB, encrypted at rest where available.
- **Names and emails are minimised** — the manifest carries what the door needs: a name, a ticket
  type, flags. Not phone numbers, not answers, not payment details. A lost phone is then a lost
  attendee list, not a lost data set.
- The signing key is **per-occurrence and expires** shortly after the event, so a stale device is
  not a permanent credential.
- Refreshed on demand and automatically whenever the device has connectivity.

### The loop

```
scan → verify signature locally → look up in manifest → decide → record locally → queue
                                                                         │
                                              when online ──────────────►│ sync
```

The device never blocks on the network. A check-in recorded offline is real; sync is how the
server learns about it, not how it becomes valid.

### What offline cannot know

A ticket cancelled after the manifest was downloaded. Two mitigations, both partial and both
honest:

- **Delta refresh** whenever connectivity appears, so the window is usually minutes.
- **Cancelled tickets stay in the manifest**, marked, so a cancellation before download is
  caught even offline.

The residual case — cancelled *after* download, on a device that never regains signal — admits
someone who should not have been. That is the correct trade (§9.6).

## 9.6 The duplicate-scan race

Two devices offline, same ticket, both admit. The unavoidable consequence of offline operation,
and it needs a stated answer rather than a discovered one.

> **Both are admitted. The conflict is recorded and surfaced at sync.**

`laravel_event_checkins.conflict` ([04](04-database-schema.md) §4.13) marks the later record;
the admin shows conflicts on the occurrence's check-in screen with times and devices.

The alternative — refusing when uncertain — turns a data problem into a queue of real attendees
being turned away at a door by software that cannot see the server. **Admitting a real attendee
beats blocking one** ([03](03-architecture.md) §3.10).

`settings.checkin.allow_duplicate_scan` lets a tenant with a stricter posture refuse instead, and
the setting explains what it costs.

Attendance reporting counts **distinct registrations**, so a conflict inflates neither the
attendance figure nor the utilisation percentage
([13](13-reporting-and-analytics.md) §13.4).

## 9.7 Sessions

Per-session attendance ([05](05-event-model-and-scheduling.md) §5.4) — for capacity control, for
CPD/CE credit, and because "which talks were full" is the question a programme committee asks.

- The scanner selects a session; scans then record against it.
- `direction` (`in` / `out`) supports occupancy counting for a room with a legal limit.
- A ticket not registered for a registrable session is refused with that reason (§9.4), and an
  operator may override — logged.
- Sessions with `requires_registration = false` accept any valid occurrence ticket.

## 9.8 Badges

- Templates with the attendee name, company, ticket type, sessions, a QR and the event branding.
- **Pre-printed** in a batch export (PDF, sheet or roll), or **printed on arrival**.
- Colour or icon per ticket type — how a room tells a speaker from a delegate at a glance.
- Reprint from the scanner for a lost badge, logged.
- Badge data comes from `ticket_snapshot` and `custom_fields`
  ([04](04-database-schema.md) §4.11.1), so a badge shows what was registered, not what the
  record was later edited to.

## 9.9 Walk-ins

`settings.checkin.allow_walkins`. On-the-day registration from the scanner:

- Claims capacity **atomically**, like any other registration
  ([06](06-ticketing-and-capacity.md) §6.5) — a walk-in is not an exemption from the room's size.
- Takes payment where required, or records an amount due for the organiser to collect.
- **Requires connectivity**, and says so when offline. A walk-in cannot claim capacity offline
  without risking an oversell, and this is the one place where refusing is right — the person is
  standing there and can be asked to wait a moment.
- Creates a normal registration, so it appears in every report and receives every email.

## 9.10 The organiser's live view

While the event runs — a screen an organiser leaves open on a laptop:

- Checked in / registered, live, with a percentage.
- Arrival rate over time — which tells a door team whether to open a second lane.
- Per-ticket-type and per-session breakdowns.
- Not-yet-arrived, searchable.
- Conflicts and overrides (§9.6).
- Devices connected and their last sync.

## 9.11 Acceptance

- A valid ticket scans and admits in under a second, with no network.
- A cancelled ticket present in the manifest is refused offline.
- A ticket cancelled after manifest download and admitted offline appears as a conflict at sync.
- The same ticket scanned on two offline devices admits both and records exactly one conflict.
- Attendance counts distinct registrations; a conflict does not inflate it.
- A transferred ticket's original QR is refused; the new one is admitted.
- Manual search finds an attendee by name, email or reference.
- Undo reverses a check-in within the window and is logged.
- Every refusal states its reason from §9.4's list.
- The manifest contains no phone numbers, no form answers and no payment data.
- The signing key expires after the event; a stale device cannot validate the next one.
- Session scanning records attendance per session, and refuses an unregistered attendee with
  that reason.
- A walk-in claims capacity atomically and is refused with an explanation when offline.
- 500 check-ins across 6 devices, half of them offline, reconcile to exactly 500 distinct
  attendances.
- A scanner operator cannot reach the admin dashboard.
