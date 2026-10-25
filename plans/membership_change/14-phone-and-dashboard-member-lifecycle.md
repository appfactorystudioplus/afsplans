# 14 — The phone and the Brain dashboard: add, pay, cancel, change (one behaviour, two screens)

> **STATUS: PLAN ONLY (2026-10-20, revised three times the same day after the owner's answers; the work runs in the `development` environment, not the Vagrant lab).**
> Nothing here is built, and nothing was run. The server behind every screen is [13](13-staff-member-lifecycle-server.md)
> (billing through Direct Payments, `paused`, tax, the level guard) and [11](11-new-member-welcome-and-alerts.md) (create, welcome, password). The phone is `C:\projects\vagrant\afs-eco\afsbrain_flutter` (its plan:
> [`brain_flutter`](../brain_flutter/README.md)). The Brain dashboard is `w/www/resources/js/portal/admin/controllers/brain/`. Findings continue **NA#**; app changes **NP#**; dashboard changes **ND#**.

## 0. The rule that keeps the two the same

**Both clients call only `api/v1/brain/staff/*` and show the words the server sends** (plan names, free-period labels, statuses, notices). Neither holds a copy of a plan list, a price rule or a status wording.
A feature is done when it works on both, and each feature has one scenario test that both clients' models pass against the same recorded server reply.

**Vocabulary:** the clients say **plan** (the tier) and never "level". There is no level control anywhere ([13](13-staff-member-lifecycle-server.md) NM15: no member can be level 99, and the only level-99 member is
`appfactorystudio`). The phone's existing `PlanOption.level` field name is a plan index; it is renamed `PlanOption.tier` in P1 so the word cannot be confused with the account marker.

## 1. What exists today

| # | Finding | Where |
|---|---|---|
| **NA30** | **Phone:** one long Add Member form (account, address, plan, status, discount, a processor picker, generated or typed password shown once), live username/e-mail checks, a "was it created?" lookup after a lost reply. No welcome switch, no password-link option, no billing step. | `afsbrain_flutter/lib/features/members/ui/add_member_page.dart`, `cubit/member_form_cubit.dart` |
| **NA31** | **Phone:** member detail already has subscription read, sync, **cancel**, **change plan**, refund, billing ledger, plan and status edits, allocations. No start-subscription, no card. | `features/subscription/`, `features/members/`, `features/billing/` |
| **NA32** | **Dashboard:** `brain_membership.ts` (4,357 lines) has the create form (`setupMembershipForm`, `submitMembershipForm`, posting through `createMembershipAsAdmin`), the list and the tabs. Its **Subscription** and **Payments** tabs call routes that do not exist and send a dropped `customer_id` (audit BA8). | `brain_membership.ts` ~2502, ~3058, ~3224 |
| **NA33** | **The phone has no web view and no network-security config.** Its private lab CA is added to **Dart's** HTTP client only (`core/lab_ca.dart`); an Android web view does not use it. This matters only for the Vagrant/lab hosts (private certificate). The owner works in the **`development`** environment (`_env/development/.env` already points at `https://www15.appfactory.studio/portal/api`, a public certificate), so **no lab CA work is planned**; it is noted so nobody is surprised if a lab build is ever wanted. | `core/lab_ca.dart`; `android/app/src/main/res` has no `xml/network_security_config.xml` |
| **NA34** | The standard card entry is Direct Payments' own SDK card form; the card page ([13](13-staff-member-lifecycle-server.md) §3) reuses it, so there is **no separate Stripe page to build in either client**: both only open the page the server names. | [13](13-staff-member-lifecycle-server.md) NS7 |

## 2. The create flow (both clients)

A short wizard: one screen per step on the phone, one panel per step in the dashboard's modal.

| Step | Fields | Notes |
|---|---|---|
| **1. Account** | username, e-mail (live availability), **password**: *Set now* (typed or generated, shown once) / *Send a set-password link* / *Both*; **Send welcome e-mail** switch (default on, labelled with the address) | the password is never e-mailed; the link is a separate e-mail ([11](11-new-member-welcome-and-alerts.md) §3.4) |
| **2. Profile** | first and last name, street, city, state, postal code, country (code and name), discount code (optional) | the address is also what **tax** is computed on ([13](13-staff-member-lifecycle-server.md) §6); server errors shown on the field |
| **3. Plan** | **plan** (server list, paid/free marked), account status | no level field |
| **4. Payment** | only for a **paid** plan (a free plan skips it: "everything just goes through"): **Card now** · **Free period** (7 days / 14 days / 1 month / 3 months / 6 months / 12 months) **with a card now** · **Free period, no card yet** · **Complimentary (nothing billed)** | shows the date the first charge happens, computed by the server; "no card yet" says: "if no card is added by <date> the subscription pauses; the member keeps the plan for 7 more days, then drops to the free plan" |
| **5. Review** | everything in words, including "free until 20 Nov 2026, then charged $X + tax" | the Create button |

**After Create** the screen reports each notice from the server's reply: member created; storage started; welcome e-mail queued to `a@b` / off / no address (and "mentions the free period"); password link sent / not
sent; billing: not needed / card needed / started (no card).

**If a card is needed:** the client asks for a billing session ([13](13-staff-member-lifecycle-server.md) NS6), opens the returned page address (a web view on the phone, a framed modal on the dashboard), polls the
session until `saved` or `failed`, and shows brand and last four to confirm. The page itself runs Direct Payments' normal card form. If anything stops here the member exists with `billing_setup: pending` and the member
screen shows **Finish billing**, which resumes at this step.

## 3. The member screen (both clients)

| Action | Notes |
|---|---|
| **Start subscription** (member with none) | the Payment step above |
| **Add or replace card** | the same page; also what ends a pause (the server resumes the subscription when the card is set) |
| **Cancel subscription** | **end now** or **at the end of the period**; allowed during a free period and when paused; reason; the result states exactly when access ends |
| **Change plan** | the processor change for a member who has a subscription; the audited database plan for one without. The button says which is happening |
| **Account status** | active / suspended / marked for deletion (the existing interim "remove"); real deletion waits for `account_close` |
| **Status chips** | Free period until <date> · Paused, plan ends <date> (7-day grace) · Past due · Complimentary |

Every write sends `expected_updated_at` and shows the server's stale-record message; every write is audited by the server.

## 4. Phone changes (NP3…)

- **NP3** `features/members/ui/add_member_page.dart` becomes `add_member_wizard.dart` (five steps). `NewMember` gains `passwordMode`, `sendWelcome`, `billing`; the processor field and picker are removed (Direct Payments routes
  the processor). `createMember()` returns a `CreateResult` (reference, notices, `billingSetup`, `next`). The "was it created?" recovery is kept. `PlanOption.level` → `tier` (P1).
- **NP4** `features/billing_setup/` (new): `data/billing_setup_repository.dart` (billing session, poll, complete, subscription start), `cubit/card_flow_cubit.dart`, `ui/card_page.dart` (the web view, opened on the address the
  server returned; the app never builds a card address), `ui/finish_billing_sheet.dart`.
- **NP5** Member detail: Start subscription, Add/replace card, Finish billing, the new status chips; Cancel gains the end-now / period-end choice. `core/api/api_paths.dart` gains the routes of 13 §4.
- **NP6** Dependency `webview_flutter` (the owner runs `flutter pub get`; I install nothing). No network-security config is added: the `development` and `production` hosts use public certificates. (Only if a Vagrant/lab build is ever wanted would a debug-only config trusting the lab CA be needed, NA33.)
- **NP7** `whoami` capabilities (`member_create`, `member_billing`) hide what the server cannot do.
- Style: Dart edited by hand in the existing long-line style; no `dart format`.

## 5. Brain dashboard changes (ND1…)

| # | Change | Where |
|---|---|---|
| **ND1** | The create form becomes the same five steps with the same field names as the phone. A new file `brain_member_create.ts` carries it so `brain_membership.ts` does not grow; `submitMembershipForm` is replaced. | `controllers/brain/` |
| **ND2** | **The dead Subscription tab is replaced, not patched.** Rebuilt on `api/v1/brain/staff/members/{ref}/subscription` (show, sync, cancel with the end-now / period-end choice, plans, change-tier, start, status chips). The calls to `/processors/subscriptions/discount` and the `customer_id` field are deleted. **Acceptance:** nothing in `initMembershipSubscriptionView` calls a route that does not exist (a grep gate in the test). | `brain_membership.ts` `initMembershipSubscriptionView` and its HTML view |
| **ND3** | **The dead Payments tab is replaced** the same way, on `…/staff/members/{ref}/payments` and the money ledger the phone reads (refunds go through the existing authenticated money route). | `initMembershipPaymentsView` |
| **ND4** | Card entry: the billing-session page opens in a modal frame; the tab polls the session exactly as the phone does. | new `brain_card_modal.ts` |
| **ND5** | Status control and chips on the member detail, same wording as the phone. | `initMembershipDetailView` |
| **ND6** | **Alerts** are the dashboard's existing bell; the only build is the `memberships` settings card ([11](11-new-member-welcome-and-alerts.md) P4). | `appendNotificationsCard` |
| **ND7** | Types in `types/index.ts` (`AdminMembershipCreationPayload` becomes the staff create payload; no `level`); strings in `i18n/{en,de,fr,es,zh}.json` (edited with anchored scripts and diffed against a baseline); unit tests for the model code with `npm run test:admin`; `tsc` clean. No optional chaining, no `any` in new code. | `types/`, `i18n/`, `tests/` |

## 6. Phases

| Phase | Work | Proof |
|---|---|---|
| **P0** | Agree the exact create and billing-session payloads with 13 NS5/NS6; list every field the dashboard's current create form sends and where each goes. | written record |
| **P1** | Phone: rename `PlanOption.level` → `tier`; wizard steps 1–3 and 5 against a fake repository (free and complimentary end to end). | widget and cubit tests |
| **P2** | Phone: payment step, billing session, card flow, finish billing, start subscription (no card), status chips. | cubit tests with a fake gateway; **card entry itself can only be proved on a device with a Stripe test card** |
| **P3** | Phone: member-detail actions (cancel choice, status, change plan). | tests |
| **P4** | Dashboard ND1–ND5, ND7. | `tsc`, `npm run test:admin`, the ND2/ND3 grep gate, then opened in a browser by the owner |
| **P5** | Parity: one list of scenarios (each mode, each free period, cancel now / period end, paused, resume after an abandoned card, tier change) run on both clients' models against the same recorded replies. | the scenario list is the acceptance |
| **P6** | Docs: `complete/14-phone-and-dashboard-member-lifecycle.complete.md`; a pointer from brain_flutter's README. | — |

## 7. What cannot be proved without you

A real card on Stripe (test mode first), the card page in the phone's web view and the dashboard modal, a free period actually ending and a subscription actually pausing, the real welcome and set-password e-mails
arriving, tax appearing on a real invoice, and anything on a device. Each is listed as unverified in the completion records, as the other brain_flutter records do.

## 8. Questions

| # | Question | Answer / recommendation |
|---|---|---|
| **OQ-N18** | Wizard or one scrolling form on the phone | wizard (the payment step depends on the plan) |
| **OQ-N19** | Same steps on the dashboard | same steps, one modal, so the scenarios are identical |
| **OQ-N20** | Where a member with a no-card free period adds a card | **answered:** the members app's `BillingApp` (it already calls `set-payment-method`), which now also resumes a paused subscription ([13](13-staff-member-lifecycle-server.md) §5) |
