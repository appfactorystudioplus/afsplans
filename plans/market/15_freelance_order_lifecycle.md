# Step 15 — Market: the freelance order lifecycle

**On completion:** write `complete/15_freelance_order_lifecycle_complete.md`.
**Depends on:** [03](03_market_database_schema.md), [07](07_members_freelance_publishing.md), [12](12_market_portal_dashboard.md), [13](13_market_cart_and_checkout.md).
**Blocks:** 16, 19.

> *"Model the freelance system like how Fiverr and Upwork works. And create a complex freelancing
> system."*

Steps 07 and 14 built the seller's authoring surface and the buyer's browsing surface. This step is
what happens after money changes hands — which is where a freelance marketplace either works or
collapses into support tickets.

---

## 15.1 The state machine

```
                    ┌──────────────────────┐
   order paid ─────▶│ awaiting_requirements│  clock NOT running
                    └──────────┬───────────┘
                buyer submits  │           ┌───────────── seller requests, buyer accepts
                               ▼           ▼
                    ┌──────────────────────┐  due_at set from package delivery_days
              ┌────▶│     in_progress      │◀──────────────┐
              │     └──────────┬───────────┘               │
              │  seller        │ delivers                  │ buyer requests revision
              │  redelivers    ▼                           │ (revisions_used < allowed)
              │     ┌──────────────────────┐               │
              │     │      delivered       │───────────────┘
              │     └──────────┬───────────┘
              │                │ buyer accepts, or auto_accept_at passes
              │                ▼
              │     ┌──────────────────────┐
              │     │      completed       │  funds released to seller ledger
              │     └──────────────────────┘
              │
              └──── cancelled ◀── mutual agreement / admin
                    disputed  ◀── either party, from any active state
```

Every transition is written through one class, `ServiceOrderStateMachine`, which owns the legality
table, the side effects, and the audit row. Not a controller. Not an Eloquent observer.

*Why so strict.* Every transition has money attached and at least two notification targets. Once
transition logic is spread across six controllers, "delivered" means six slightly different things
and the ledger stops reconciling.

Illegal transitions raise, are logged, and return `409`.

---

## 15.2 The rules that make it work

### The clock does not start until requirements are answered

An order sits in `awaiting_requirements` with **no** `due_at`. Only when the buyer submits does
`due_at = now + package.delivery_days` get set.

This is the single most important rule in the step. Without it, sellers are late for reasons
entirely outside their control, on-time metrics become meaningless, and the metrics were the
mechanism keeping quality up.

A buyer who never answers gets reminders at 24h, 72h and 7 days; at 14 days the order is
auto-cancelled and refunded. Silence must resolve to *something*.

### Revisions are counted, not negotiated

`revisions_allowed` from the package; `revisions_used` on the order. At the limit the button is
gone, replaced by an offer to purchase an extra revision. `-1` means unlimited.

Turns the most common freelance argument into arithmetic both sides agreed to before the order
existed.

### Auto-accept

On delivery, `auto_accept_at = now + 3 days` (configurable). The buyer is told, on the page and in
the notification. On expiry the order completes and funds release.

Otherwise an inattentive buyer holds a seller's money indefinitely, and that is what makes sellers
leave.

### Deadline extensions are mutual

A seller can request; only a buyer can grant. Requesting does not stop the clock — a one-sided
extension is not an extension.

### Cancellation is mutual or arbitrated

Either party requests; the other accepts, or it becomes a dispute. Neither can unilaterally cancel a
delivered order.

---

## 15.3 Requirements

`service_requirements` (the questionnaire, step 07) → `service_order_requirements` (the answers).

Buyer-facing form at `/portal/services/{ref}/requirements`: text, choice, multi, and file — files
through the step-05 pipeline with the same filename analysis. Required items block submission.
Answers are visible to the seller in members and immutable after submission (changing scope after
work starts is a revision request, not an edit).

---

## 15.4 Deliverables

Seller uploads files plus a note; optionally marks a delivery final.
Stored at `{seller}/services/{svcRef}/deliverables/{soRef}/`.

The buyer can download deliverables **while the order is `delivered` or `completed`**. Not before.
Not after a cancellation-with-refund — the state machine revokes access, because "download the work,
then dispute for a refund" is otherwise a free workflow.

Deliverables from completed orders remain downloadable indefinitely from `/portal`, same principle
as asset entitlements: what was paid for stays reachable.

---

## 15.5 Milestones

For larger fixed-price work and for accepted Upwork-style proposals.

`service_order_milestones`: title, amount, due date, sequence, status
(`pending` → `funded` → `in_progress` → `submitted` → `approved` | `disputed`).

Each milestone is funded and released independently; the sum must equal the order total. Milestone
approval releases that milestone's funds to the ledger — which means the ledger entry is
milestone-scoped, and step 16 must model it that way rather than as one entry per order.

---

## 15.6 Messaging

Threaded per order, both parties plus admin on a dispute.

- Attachments via step 05's pipeline.
- `read_at` for read receipts and unread badges.
- Rendered with escaping and a strict allowlist — a marketplace message box is an untrusted-input
  surface and the seller reading it is the target.
- Notification on new message: in-app both sides, plus the step-04 callback to members so a seller
  is reachable without keeping the portal open.
- Immutable: edits and deletes are not offered. The thread is evidence in a dispute, and evidence
  that can be edited is not evidence.

---

## 15.7 Disputes (marketplace — **not** card chargebacks)

> **Naming.** This section covers **buyer-vs-seller** disputes over a deliverable. Stripe
> `charge.dispute.*` events are **card chargebacks** — a different mechanism, different parties,
> different money movement, handled in [step 08](08_stripe_connect_brain_api.md)'s refunds-and-disputes
> section. Per that section, this table and service are named **`order_disputes`** /
> `OrderDisputeService`; the payments-side one is `chargebacks` / `ChargebackService`. Do not
> collapse them. A lost chargeback on an order with an open marketplace dispute should close both,
> and that rule is only expressible when they have different names.

Either party, from any active state. Freezes the order, holds the funds, notifies both sides and an
admin.

`order_disputes`: reason category (not delivered / not as described / quality / scope / communication /
other), narrative, evidence attachments, status (`open` → `under_review` → `resolved` |
`escalated`), resolution (`favour_buyer` / `favour_seller` / `split` / `mutual`), resolution amount.

Resolution drives money: full refund, partial refund, or release. Whichever it is, step 16's ledger
gets the reversal entries and step 08's Connect gets the transfer reversal.

An admin surface is needed — even a minimal one. A dispute system with no way to resolve a dispute
is worse than none, because it silently freezes funds.

---

## 15.8 Reviews

On completion (or auto-accept), both parties are prompted. 14-day window.

- **Double-blind**: neither review is published until both are submitted or the window closes.
  Without this, the first reviewer is exposed to retaliation and everyone rates 5 stars.
- One review per side per order, enforced by the step-03 unique constraint.
- Sellers may reply once, publicly.
- Ratings recompute `seller_profiles.rating_avg` / `rating_count` and feed seller levels.

---

## 15.9 The Upwork half

**Job posts** — `/portal/jobs/new`, buyer-authored: title, description, category, budget
(fixed or hourly range), skills, experience level, visibility, closing date.

**Proposals** — sellers apply from members (step 07). Buyers review at
`/portal/jobs/{ref}/proposals`: shortlist, message, decline, or accept.

**Contracts** — an accepted proposal becomes a contract. Fixed-price contracts run on milestones
(§15.5). Hourly contracts run on weekly timesheets: the seller submits, the buyer has 5 days to
review, then it auto-invoices through the same checkout path as any other purchase.

Hourly is genuinely more work than fixed-price (weekly billing cycles, dispute windows per week,
a weekly hour cap). **If scope pressure appears, ship fixed-price contracts first and defer hourly.**
Record that as a deferral naming the missing piece, not as a completed step.

---

## 15.10 Notifications

Every transition notifies. Matrix — buyer / seller / both / admin: order placed, requirements
submitted, work started, delivered, revision requested, redelivered, accepted, auto-accepted,
extension requested/granted, cancellation requested/agreed, dispute opened/resolved, message
received, review received, milestone funded/submitted/approved, timesheet submitted/approved.

In-app in market's portal, plus the step-04 callback into members for sellers, plus email for the
high-consequence ones (delivered, dispute, auto-accept imminent). All of it queued — a slow SMTP
server must not slow a state transition.

---

## 15.11 Files

> **Tagging (step 01 §01.4) — every file below.** Created files carry
> `MARKET_MODIFIER_ADDITION` in their top doc block; modified files carry it at each changed line or
> region. `grep -rn MARKET_MODIFIER_ADDITION` must return this list in full, or the change index is
> already wrong.


**Created:** `app/Services/ServiceOrders/ServiceOrderStateMachine.php`,
`.../{RequirementsService,DeliveryService,RevisionService,MilestoneService,DisputeService,ReviewService}.php`,
`app/Http/Controllers/Portal/{ServiceOrder,Requirements,Delivery,Dispute,Review,Jobs,Proposals,Contracts}Controller.php`,
`app/Models/{ServiceOrder,ServiceOrderRequirement,ServiceOrderMilestone,ServiceOrderDeliverable,ServiceOrderRevision,ServiceOrderMessage,Dispute,Review,JobPost,JobProposal,Contract,ContractTimesheet}.php`,
`app/Console/Commands/{AutoAcceptDeliveries,ExpireUnansweredRequirements,PublishPendingReviews,CloseExpiredJobPosts}.php`,
`resources/views/portal/services/*`, `resources/views/portal/jobs/*`,
`resources/js/portal/{serviceOrder,messaging,milestones}.ts`,
plus the internal endpoints for the members-side seller queue (step 07).

**Modified:** `routes/portal.php`, `app/Services/Checkout/OrderFulfilmentService.php` (creates
`service_orders` on purchase), `resources/js/portal.ts`.

Scheduled: auto-accept sweep hourly; requirements reminders daily; review publication daily;
job-post closure daily.

---

## 15.12 Verification

- [ ] A purchased gig creates a `service_orders` row in `awaiting_requirements` with `due_at` **null**.
- [ ] Submitting requirements sets `due_at` correctly from the package's delivery days.
- [ ] Every illegal transition (deliver before start, accept before delivery, revise past the limit) returns `409` and is logged.
- [ ] Revision counter decrements; at the limit the button is replaced by the paid-revision offer.
- [ ] Auto-accept fires on schedule and completes the order.
- [ ] Deliverables are not downloadable in `in_progress`, are in `delivered`, and are not after a refunded cancellation.
- [ ] A dispute freezes the order and blocks completion.
- [ ] Reviews stay hidden until both are in or the window closes.
- [ ] Unanswered requirements auto-cancel and refund at 14 days.
- [ ] Milestone approval releases exactly that milestone's amount.
- [ ] A hostile message body renders escaped.
- [ ] Every file created or modified by this step carries `MARKET_MODIFIER_ADDITION` (§01.4). Verify by `grep -rn`, and reconcile the result against this step's Files section — a file in the list without a tag, or a tagged file not in the list, are both defects.
- [ ] `complete/15_freelance_order_lifecycle_complete.md` written, stating whether hourly contracts shipped.
