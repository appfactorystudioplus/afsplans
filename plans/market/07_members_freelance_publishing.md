# Step 07 — Members: freelance service authoring (the Fiverr side of the seller experience)

**On completion:** write `complete/07_members_freelance_publishing_complete.md`.
**Depends on:** [04](04_internal_cross_api.md).
**Blocks:** 15, 17, 19.

---

## 07.1 What is being replaced

`members/resources/js/controllers/FreelanceController.ts` — 439 lines, `localStorage`
(`freelance_services`, `freelance_payments`, `freelance_account`), plus a session-backed
`FreelanceController.php` and eleven routes at `members/routes/web.php:75–85`.

The current model is "a service is a title, a price, and a delivery time." Fiverr's and Upwork's are
not, and the gap is the whole step: a marketplace where every gig is one price and one date cannot
express upsells, cannot express scope, and gives the buyer nothing to compare.

---

## 07.2 The model, and why it is shaped this way

A **gig** (`services` row) is the marketing surface. What is actually bought is a **package**
(`service_packages`), optionally plus **extras** (`service_extras`).

| Fiverr concept | Table | Why it must exist |
|---|---|---|
| Three-tier pricing | `service_packages` | The comparison table is the conversion mechanism. One price gives the buyer nothing to anchor against, and gives the seller no way to upsell without a conversation. |
| Extras | `service_extras` | Scope creep priced in advance, instead of negotiated per order. |
| Gallery | `service_gallery` | Nobody buys creative work from text. |
| FAQ | `service_faqs` | The same five pre-sale questions, answered once. |
| Requirements | `service_requirements` | The single largest cause of late delivery is work starting before the seller has what they need. The order does not enter `in_progress` until these are answered (step 15). |
| Revisions | on the package | Turns "are we done?" from an argument into a counter. |

The Upwork half — job posts, proposals, contracts — is buyer-initiated and lives on the market side
(step 15). Members only surfaces the seller's inbox for it (§07.6).

---

## 07.3 The gig editor

`members/resources/js/market/GigEditor.ts`, five tabs. Tabs, not a wizard: a gig is edited far more
often than it is created, and forcing an eight-stage wizard to fix a typo in the FAQ is hostile.
(The asset flow is a wizard because an asset is created once and versioned thereafter — the two
differ deliberately.)

### Tab 1 — Overview
Title (`"I will …"` placeholder, the genre convention), category + subcategory, search tags,
`gig_type` (`fixed` | `hourly`).

### Tab 2 — Pricing
A three-column editor mirroring the buyer's comparison table. Per tier: name, description, price,
delivery days, revisions (`-1` = unlimited), and a checklist of features.

Rules, enforced client- and server-side:
- Basic is mandatory. Standard and Premium are optional but, when present, must be
  monotonically priced and no faster to deliver than the tier below.
- The feature checklist is shared across tiers so the comparison table has aligned rows. A feature
  present in Basic but absent from Premium is flagged — usually a mistake, never blocked.
- Extras below: title, price, extra delivery days, max quantity.

Live earnings preview per tier, identical in structure to step 06's:

```
  Premium package                   $250.00
  Platform fee (10%)                $ 25.00
  ─────────────────────────────────────────
  You receive                       $225.00
```

with the same explicit disclosure sentence. A seller must not encounter the 10% for the first time
on a payout statement.

### Tab 3 — Description & FAQ
Markdown description with live preview; an ordered, drag-sortable FAQ list.

### Tab 4 — Requirements
The questionnaire the buyer completes after ordering. Per item: prompt, type
(`text` | `choice` | `multi` | `file`), options, required flag, order.

Copy on the tab: *"The order clock does not start until the buyer answers these."* That is the
actual behaviour (step 15) and it is the reason to fill this tab in properly.

### Tab 5 — Gallery
Up to 3 images, 1 video, 2 documents — Fiverr's shape, and a sane cap. Same filename analysis and
server-side re-encode as step 05. Drag to reorder; the first image is the card thumbnail, stated on
screen.

**Publish** hits `POST /services/{ref}/publish` and renders the full gate-failure list (§04.4).

---

## 07.4 Seller profile

Currently nowhere. New tab in the members freelance area, backed by `seller_profiles`:
display name, headline, avatar, bio, country, languages with proficiency, skills, education and
certifications (free-text), hourly rate for `hourly` gigs.

Read-only stats rendered beside it, sourced from market: level, completed orders, rating and count,
average response time, on-time delivery rate. Read-only because they are computed from orders — a
seller-editable "98% on-time" is worth nothing to a buyer.

---

## 07.5 Order management (the seller's queue)

The seller's working view of `service_orders` (step 15 owns the lifecycle; this is the members-side
surface):

- Queue grouped by state: Awaiting requirements · In progress · Delivered · Revision requested · Completed · Cancelled · Disputed.
- Per order: buyer, package, extras, price, due date with a countdown that turns red inside 24h,
  revisions used / allowed.
- Actions: view requirements, message the buyer, deliver (upload files + note), request a deadline
  extension, request cancellation, escalate to a dispute.
- A delivery uploads through the step-05 pipeline into
  `{member}/services/{svcRef}/deliverables/{soRef}/` and calls
  `POST /sellers/{ref}/service-orders/{soRef}/deliver`.

### Messaging
Threaded per order. Members polls (or subscribes to) `GET /service-orders/{soRef}/messages`; market
is the store. Attachments go through the same upload path with the same analysis.

Unread counts arrive by the step-04 `POST /events/message` callback so the badge does not require
the seller to have the page open.

---

## 07.6 Proposals inbox (Upwork side)

Read-mostly in members: browse open `job_posts` matched to the seller's skills, submit a proposal
(cover letter, bid, type, estimated days, optional milestone breakdown), and track proposal status.
Accepted proposals become contracts and appear in the same queue as §07.5. Hourly contracts add a
weekly timesheet submission form.

---

## 07.7 Removals

Delete outright, do not deprecate:

- All `localStorage` in `FreelanceController.ts`.
- All `session("freelance_*")` in `FreelanceController.php`.
- The **payment methods** UI and its three routes. Bank account and routing numbers currently go
  into a PHP session and render as `****1234`. Payouts are Stripe Connect's job (step 10); a
  marketplace holding raw bank details in session storage is a liability with no compensating
  benefit. The tab is replaced by a link into Connect onboarding.
- `handleWithdraw()`'s `prompt()`-based flow, replaced by step 16's payout request.

---

## 07.8 Files

> **Tagging (step 01 §01.4) — every file below.** Created files carry
> `MARKET_MODIFIER_ADDITION` in their top doc block; modified files carry it at each changed line or
> region. `grep -rn MARKET_MODIFIER_ADDITION` must return this list in full, or the change index is
> already wrong.


**Created:** `members/resources/js/market/GigEditor.ts`,
`GigPricingTable.ts`, `GigRequirementsEditor.ts`, `GigGalleryEditor.ts`,
`SellerProfileEditor.ts`, `ServiceOrderQueue.ts`, `OrderMessaging.ts`, `ProposalsInbox.ts`,
`members/resources/js/templates/views/gig_editor.html`,
`.../service_orders.html`, `.../seller_profile.html`, `.../proposals.html`.

**Modified:** `FreelanceController.ts`, `templates/views/freelance.html`, `views.ts`,
`i18n/en.json` (+`es.json`), `app/Http/Controllers/FreelanceController.php`,
`routes/web.php:75–85`.

---

## 07.9 Verification

- [ ] `grep -n "localStorage" members/resources/js/controllers/FreelanceController.ts` → zero.
- [ ] `grep -rn "session(\"freelance" members/app` → zero.
- [ ] Publish a three-tier gig as `m1xTHpRmdtH2Y`; market holds one `services` row and three `service_packages` with correct tiers, prices, delivery days and revision counts.
- [ ] Standard priced below Basic is rejected client-side **and** server-side.
- [ ] Publishing with no gallery item and no active package returns both errors at once.
- [ ] The 10% disclosure appears on every tier in the pricing tab.
- [ ] Payment-methods UI and its routes are gone; the tab links to Connect onboarding.
- [ ] A message sent from the market portal raises the members unread badge without a page reload.
- [ ] Every file created or modified by this step carries `MARKET_MODIFIER_ADDITION` (§01.4). Verify by `grep -rn`, and reconcile the result against this step's Files section — a file in the list without a tag, or a tagged file not in the list, are both defects.
- [ ] `complete/07_members_freelance_publishing_complete.md` written.
