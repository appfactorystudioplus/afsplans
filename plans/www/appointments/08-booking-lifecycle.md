# 08 — The Booking Lifecycle

Closes **A8**, **A30**, and the lifecycle half of **A35**.

## 8.1 What exists

`status` exists on the bookings table, defaults to `pending`, and **nothing anywhere transitions
it** (**A8**). `handleBookingSubmit` hard-codes `'pending'`; there is no confirm, no cancel, no
reschedule, no completion, no no-show.

So every booking a tenant has ever taken is pending, forever, and a customer who needs to change
one must email somebody.

## 8.2 The states

```
                   ┌──────────► rejected
   pending ────────┤
   (approval)      └──────────► approved ──┐
                                            │
   pending ─────────────────────────────────┼──► confirmed ──┬──► completed
   (payment)                                                 ├──► no_show
                                                             ├──► cancelled
                                                             └──► rescheduled ──► (new booking)
```

| State | Meaning | Holds capacity? |
|---|---|---|
| `pending` | Awaiting approval or payment | **Yes** — §8.4 |
| `confirmed` | Going ahead | Yes |
| `cancelled` | Called off; the time is released | No |
| `completed` | It happened | Yes (historically) |
| `no_show` | The customer did not attend | Yes (historically) |
| `rescheduled` | Superseded by a new booking | No |

`cancelled`, `rescheduled` and `no_show` set the generated `active_slot_key` to `NULL`
([04](04-database-schema.md) §4.9.2) — which is what frees the time, and what stops a cancelled
booking blocking its slot forever (**A31**).

**`completed` and `no_show` are set by a job after `ends_at`**, defaulting to `completed` unless
`checked_in_at` is used by the tenant, and correctable by staff. Leaving every past booking
`confirmed` makes no-show reporting impossible
([13](13-reporting-and-analytics.md) §13.3).

## 8.3 Custom questions

The forms reuse ([03](03-architecture.md) §3.5). A service may name a **published form version**;
its answers are stored as a forms submission and mirrored to `custom_fields` for day sheets and
exports.

- `user_name`, `user_email`, `user_phone` are native columns, never form answers.
- The pinned version means editing the form later never changes what a past booking answered
  ([forms/09](../forms/09-publishing-and-versioning.md) §9.3).
- A service with no custom questions never touches the forms module.
- Intake questionnaires, consent, "what is this about", medical history — all of this is a form,
  with conditional logic and validation the forms module already provides
  ([forms/06](../forms/06-logic-and-validation.md)).

**Answers marked special-category** — health, accessibility — are flagged in the attachment so
exports exclude them by default ([13](13-reporting-and-analytics.md) §13.7). An appointments
module in a clinical setting will collect them.

## 8.4 Approval

`services.requires_approval`. For consultations that must be vetted, services with prerequisites,
or a tenant who simply wants to see requests first.

| Step | |
|---|---|
| Request | Booking created `pending` / `approval_status = pending` |
| **Capacity** | **Held.** The time is claimed, so approving cannot fail because someone else took it |
| **Payment** | **Not taken.** Charging before approval means refunding every rejection |
| Approve | Status → `confirmed`; payment requested if required; confirmation sent |
| Reject | Time released, customer notified with an optional reason |
| Expiry | Unapproved requests expire per setting and release the time |

Holding capacity without charging is the whole design, and it is the same rule
[events/07](../events/07-registration-and-attendees.md) §7.6 arrives at. Charging then refunding
costs the tenant fees on every rejection and reads, to the customer, as being charged for being
turned down.

## 8.5 Reschedule

The action customers want most and the one that does not exist.

**Claim-then-release, never release-then-claim** ([06](06-booking-engine.md) §6.8): the new time
is claimed first, so a failure leaves the original booking intact.

| Rule | |
|---|---|
| Who | The customer (within policy), staff, or an admin |
| Window | `reschedule_up_to_hours` before the start |
| Limit | Optional cap on reschedules per booking — otherwise a diary can be churned indefinitely |
| Same service | By default. Changing service is a cancel plus a new booking, and price differences make it so |
| Staff | May change; the customer is told if it does |
| Record | Old booking → `rescheduled`, new booking → `rescheduled_from_ref`. **Both rows survive** |
| Notify | Both parties, with an **updated ICS** carrying a bumped `SEQUENCE` ([10](10-communications-and-calendar.md) §10.4) |
| Money | Unchanged if the price is the same; a difference follows [09](09-payments-and-policies.md) §9.5 |

Keeping both rows is what makes "this customer has rescheduled four times" visible, which is a
real operational signal.

## 8.6 Cancellation

| Actor | Rules |
|---|---|
| **Customer** | `allow_customer_cancel` and `cancel_up_to_hours`; reachable by `access_token` or as the signed-in owner ([02](02-site-users-guard.md) §2.5) |
| **Staff** | Any time, with a reason; the customer is notified |
| **System** | Payment expiry, approval expiry, an appointment being archived |

Every cancellation, in one transaction: release the time, promote the waitlist (§8.8), write the
activity entry, queue the notification, and **push the cancellation to the synced calendar**
([10](10-communications-and-calendar.md) §10.6).

**A cancelled booking is never deleted.** The row keeps `cancelled_at`, `cancel_reason` and
`cancelled_by` — a tenant needs to distinguish a cancellation from a no-show, and both from a
booking that never existed ([02](02-site-users-guard.md) §2.7).

### Late cancellation and no-show fees

`settings.policy` ([04](04-database-schema.md) §4.10) — `late_cancel_fee_cents`,
`no_show_fee_cents`, charged through Direct Payments where a card is on file
([09](09-payments-and-policies.md) §9.5).

The policy is **shown before booking** and snapshotted onto the booking. Changing it must not
retroactively alter what an existing customer agreed to.

**Charging a fee is never automatic.** It is offered to the operator as an action with the policy
quoted, because the judgement — was this a genuine emergency — is theirs and getting it wrong
loses the customer permanently.

## 8.7 Recurring bookings

"Every Tuesday at 10:00 for ten weeks." A standard requirement for therapy, tutoring, training
and maintenance, and entirely absent.

`laravel_appointment_series` ([04](04-database-schema.md) §4.11) holds the rule; **each
occurrence is a real booking row**, the same decision
[events/05](../events/05-event-model-and-scheduling.md) §5.3 makes for occurrences and for the
same reason: a series where one week moves has nowhere to record that unless the weeks are rows.

| Concern | Handling |
|---|---|
| Creation | Availability is checked for **every** occurrence before any is created |
| Partial availability | The customer is shown which dates are unavailable and chooses: skip them, take the nearest alternative, or abandon |
| Atomicity | All occurrences claimed in one transaction — a series that half-books is worse than one that fails |
| Editing | *This one* · *this and following* · *all* |
| Cancelling one | Frees that time only |
| Cancelling the series | Cancels future occurrences; past ones stand |
| Payment | Per occurrence, or as a package ([09](09-payments-and-policies.md) §9.6) |
| Extension | A series near its end prompts the tenant to extend rather than silently stopping |

**Checking every occurrence before creating any** is the part naive implementations skip, and it
produces a customer who believes they have ten appointments and has six.

## 8.8 Waitlist

`laravel_appointment_waitlist` ([04](04-database-schema.md) §4.11). For a fully booked day, or a
customer who wants a specific window.

| Property | |
|---|---|
| Entry | Preferred date range, preferred days, optionally a specific staff member |
| Ordering | `position`, first-come; reordering is possible and **audited** |
| Trigger | Any cancellation, reschedule away, or capacity increase that matches an entry's preferences |
| **Offer, not a booking** | A time-limited claim link, `offer_ttl_hours` (default 4) |
| Held | The freed time is held for the offer's duration ([06](06-booking-engine.md) §6.5) |
| Expiry | The offer lapses, the next matching entry is offered; the time is never left unclaimed |
| Matching | Only entries whose preferences contain the freed time |

**Promotion happens in the same transaction as the cancellation that freed the time**
([06](06-booking-engine.md) §6.8). Otherwise two cancellations offer the same slot twice, or a
passer-by refreshing the booking page takes it while the waitlist waits.

The offer window is short — hours, not days — because the value of a waitlist is filling a gap
that is about to arrive.

## 8.9 What the customer can do

The booking page, reachable by `access_token` or as the signed-in owner
([02](02-site-users-guard.md) §2.5) — **404 for anyone else, never 403**:

- See the appointment: what, when (both zones), who, where.
- Add to calendar ([10](10-communications-and-calendar.md) §10.4).
- Directions, or the join link once released
  ([07](07-services-staff-and-resources.md) §7.7).
- **Reschedule** within policy, choosing from live availability.
- **Cancel** within policy, with the policy stated.
- Review and amend their answers before a cutoff.
- See what they paid and what a late cancellation would cost.

With Site Users on, the same content appears under "my appointments" without a token
([02](02-site-users-guard.md) §2.3), survives a lost email, and shows history — which is the
clearest benefit an account offers and the honest way to sell it.

**Self-service cancellation is the feature that pays for the module.** A customer who cannot
cancel emails the tenant, who edits the calendar by hand — the work does not disappear, it moves
to a person. And the freed time returns to availability immediately, which is worth more to the
tenant than to the customer.

## 8.10 Acceptance

- Every state transition in §8.2 is reachable and recorded in the activity log.
- A past booking becomes `completed` or `no_show`, never staying `confirmed` (**A8**).
- A `pending` approval **holds** its time and is **not charged**.
- Rejecting releases the time and notifies the customer.
- Rescheduling claims the new time before releasing the old; a failure leaves the original
  intact.
- Both the old and new booking rows survive a reschedule, linked.
- Rescheduling outside the window is refused with the policy stated.
- A cancelled booking releases its time immediately and promotes the waitlist in the same
  transaction.
- A cancelled booking is never deleted and records who cancelled it and why.
- A no-show fee is never charged automatically.
- The cancellation policy shown before booking is the policy applied afterwards.
- A ten-week series checks all ten before creating any, and reports unavailable dates.
- Cancelling one occurrence of a series frees only that time.
- A waitlist offer holds the time for its TTL, then passes to the next matching entry.
- A waitlist entry is only offered times matching its stated preferences.
- The customer booking page is reachable by token and by owner, and **404s** otherwise.
- A service with a booking form pins its version; editing the form does not change past answers.
