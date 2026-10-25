# 14 — The phone and the Brain dashboard: add, pay, cancel, change (complete — built and tested with fakes; nothing seen on a device, in a browser, or against a real server or Stripe)

## Status

**Complete 2026-10-20.** Nothing is committed (`w/www` and `afsbrain_flutter` are separate repositories). The phone app and the Brain dashboard now do the same things through the same server routes ([13](../13-staff-member-lifecycle-server.md)),
with the same words: **add a member in five steps** (Account, Profile, Plan, Payment for a paid plan, Review) with the password choice, the welcome switch and how the member pays; **start or finish a member's subscription** with a card
page the server names; **cancel** now or at the end of the period; **change plan**; and **status chips** (free period, paused, past due, complimentary). Both call only `api/v1/brain/staff/*` and show what the server sends.

## Phases

| Phase | Piece | State | Where |
|---|---|---|---|
| P0 | The exact payloads agreed with 13 | **done** — see "The payloads" | — |
| P1 | Phone: wizard steps 1–3 and 5; `PlanOption.level` → `tier` | **built** | `features/members/ui/add_member_wizard.dart`, `data/models/{create_options,new_member,create_result}.dart` |
| P2 | Phone: payment step, card flow, finish billing, start subscription, status chips | **built** | `features/billing_setup/`, `features/subscription/logic/status_chips.dart` |
| P3 | Phone: cancel choice, plan change | **built** | `features/subscription/ui/cancel_subscription_sheet.dart` |
| P4 | Dashboard ND1–ND5, ND7 | **built** | see "Dashboard" |
| P5 | Parity: the same scenarios on both clients' models | **done by hand-kept twin tests** — see "Parity" | — |
| P6 | This record, README, handoff, memory | **done** | — |

## The payloads

- **Create:** `POST api/v1/brain/staff/members` with `username, email, password?` (only for *set now* / *both*), `password_mode`, `send_welcome`, the profile, `membership`, `account_status`, `discount_code`, and `billing {mode, free_period?}`. No level, no reference, no direct payment, no processor. Reply: the member, `notices` (`payer_email`, `storage`, `welcome`, `password_link`), `billing {billing_setup, next, session, subscription}` and `billing_error`.
- **Billing:** `POST …/members/{ref}/subscription/start` (and `billing-session`) with `{mode, free_period?}`; `GET …/billing-session/{id}` is polled until `saved`, `failed` or `expired`. Modes: `none`, `complimentary`, `card_now`, `free_period_card`, `free_period`.
- **Server additions made for the clients (13 NS4):** `create-options` now also returns `plans[].paid`, `free_periods[].ends_on` (the date each period ends if chosen today) and `paused_grace_days`, so neither client holds a price rule, a date calculation or the grace number. `whoami` reports `member_create_billing`, `member_billing` (13) and `member_alerts` (12).

## What was built

**Phone (`afsbrain_flutter/lib`).**
- `features/members/ui/add_member_wizard.dart` replaces `add_member_page.dart`: steps, per-step checks, live username/e-mail availability, *Set now / Send link / Both*, a welcome switch naming the address, a generated password shown **once** with a warning, a review in words, and a result screen listing what happened (member created, storage, welcome e-mail, set-password link, billing). The "was it created?" lookup after a lost reply is kept. The Add button is offered only when the server reports both `member_create` and `member_create_billing`.
- `features/billing_setup/` — models (`BillingMode`, `BillingChoice`, `BillingSession`, `BillingStart`), the repository and the card-page opener, `CardFlowCubit` (opens the address the server named, polls the session every 3 s, stops on a final status), `BillingStartCubit` (one request per tap, never re-sent; a lost reply is "not known"), the shared payment choice (`BillingChoiceForm`), the card screen and the *Start subscription / Finish billing* sheet.
- Member screen (Subscription tab): status chips, *Start subscription* (or *Finish billing* when `billing_setup` is `pending`), cancel with **End access now / At the end of the period** (default: the Direct Payment's own policy; during a free period it says "free period"; a paused subscription says there is nothing to wait for), and a *Paused* status pill. "Level" is gone from the plan and change-plan text.
- `core/api/api_paths.dart` routes, `session/capabilities.dart`, `home_shell.dart` wiring.

**Dashboard (`w/www/resources/js/portal/admin`).**
- `html/…/brain_memberships_create_content.html` + `controllers/brain/brain_member_create.ts` — the same five steps and fields; no processor, no tax estimate, no level; the country list kept.
- `controllers/brain/brain_card_modal.ts` — the card page in a frame with an "Open in a new tab" fallback; asks the server where the session is (never believes the page).
- `controllers/brain/brain_member_subscription.ts` + the rebuilt Subscription tab (sync, start/finish billing, change plan, cancel with the choice). **The old controls (discount code, billing amount, billing date) are removed**: they called routes that do not exist with a `customer_id` the member no longer has.
- `controllers/brain/brain_member_payments.ts` + the rebuilt Payment History tab on `…/payments` and the authenticated money refund. **The form that took a card number, expiry and CVV typed into the dashboard is removed.**
- Status chips on the Member Info tab. `brain_membership.ts` lost the old create/subscription/payments code (about 1,000 lines); `createMembershipAsAdmin` and its payload type are removed from `afs/memberships` (nothing else called them).
- `services/memberStaff.ts` (the rules, sentences, chips, markup — pure) and `services/memberStaffApi.ts` (the calls, `fetch` injected).

## Tests

| File | Tests | Proves |
|---|---|---|
| Dart `billing_setup/billing_setup_test.dart` | 26 | models, routes, card flow (opens the server's address, polls to *saved*, failed, phone cannot open), start cubit (one send, lost reply), the words, the card screen, the sheet |
| Dart `billing_setup/billing_shell_test.dart` | 6 | Start subscription end to end in the shell, Finish billing, no button without the capability, the wizard handing a needed card to the card screen, no Add button without the staff create route |
| Dart `members/ui/add_member_wizard_test.dart` | 22 | the steps, the payment step only for a paid plan, send-link needs no password, taken username/e-mail go back to step 1, Checkpoint failure, lost reply, notices, card handover |
| Dart `subscription/billing_actions_test.dart` | 16 | chips, Start/Finish billing, cancel choice |
| Dart (edited) `member_forms`, `member_writes_http`, `member_form_cubit`, `home_shell`, `subscription_ui` tests | — | no processor, the new create payload and reply, create-options fields, the capability gate |
| TypeScript `memberStaff.test.ts`, `memberStaffApi.test.ts`, `memberStaffGates.test.ts` | 54 | the rules and words (same replies as the phone), paths and bodies, refusals, markup escaping; **gates**: no old processors route, no `customer_id`, no card field, every route called is registered in `routes/portal.php`, no optional chaining or `any` in the new files |
| PHP `BrainStaffWriteTest`, `BrainStaffWhoamiTest`, `FreePeriodTest` | 26 | `paid`, `ends_on`, `paused_grace_days`, `member_alerts` and the billing capabilities |

## Final run (run once, at the end of 12 + 14)

| Pass | Result |
|---|---|
| Flutter, **whole suite** (`flutter test`) | 1,862 tests; **1 failure** (the old Add-button test, which now needs the second capability) — **fixed, that file re-run: 4/4 pass** |
| TypeScript `npm run test:admin` | **135 / 135 pass** |
| TypeScript `tsc --noEmit` | **clean** |
| Dart `dart analyze lib test` | **no issues** |
| PHP, the three files changed this turn | **OK — 26 tests, 180 assertions** |
| PHP, **whole suite** | **not completed.** Two attempts: the first died at 732 / 2,702 on "Maximum execution time of 600 seconds exceeded"; the second was stopped by the owner at 427 / 2,702. Cause of the 600 s: two controllers call `set_time_limit(600)` and the limit then covers the rest of the CLI process; **fixed in `tests/TestCase.php`** (`set_time_limit(0)` before and after each test), not yet re-run over the whole suite. Each test drops and rebuilds all tables, so the whole suite takes more than an hour. |
| PHP, the other touched-area files (Membership billing, create, paused, level 99 …) | **not re-run this turn** (their code was not changed this turn; they passed when step 13 closed) |

## Parity

The phone and the dashboard cannot read one fixture (two repositories), so the same hand-written server replies and the same expected sentences are kept in both: the card-needed create reply, the billing-failure reply, the free-period explanations (dates and grace days), the chips (free period, paused with the downgrade date, past due, complimentary), the cancel words for paid / free period / paused, and the session messages. If one client's wording changes, the other's test must change with it.

## Deviations from the plan

1. **No `webview_flutter`.** The card page opens in the phone's **in-app browser** through `url_launcher` (already a dependency; Custom Tabs on Android). Nothing is installed to test this plan, and the system browser trusts the same certificates the phone does, so no network-security config is needed either. The opener is one small class; swapping in a web view later changes only it.
2. **No "Add or replace card" for a member who already has a live subscription.** The server has no route for it (`MemberBilling::begin` refuses `BS_E_ALREADY_SUBSCRIBED`); the plan's own answer (OQ-N20) is that the member adds a card in the members app, which also resumes a paused subscription. Cards are entered only for *Start subscription* / *Finish billing*.
3. **The brand and last four digits of the card are not shown** (the server does not send them).
4. **Dashboard strings are English only.** The Brain's Memberships screens are not localized (none of them carries `data-i18n`), so no entries were added to `i18n/{en,de,fr,es,zh}.json`.
5. **The read-only staff "level" chip on the member list/detail (phone) is unchanged**; it is a diagnostic display, not a control. No level can be set anywhere.
6. **Status chips** on the phone are on the Subscription tab; on the dashboard they are on both the Member Info and Subscription tabs.
7. **`create-options` is a saved-copy route on the phone**, so offline the free-period end dates can be a day old.

## Not verified

- **Nothing was run on a device, in a browser, or against a real server.** The card page opening in the phone's in-app browser; the frame on the dashboard (if the host sends frame-blocking headers the frame stays blank and "Open in a new tab" is the way out — the headers were not checked); a real card on Stripe test mode; a free period actually ending and a subscription actually pausing; the welcome and set-password e-mails arriving; tax on a real invoice.
- **The webpack/vite build of the admin bundle was not run** (only `tsc` and the Node tests).
- **The whole PHP suite** (see above).
- Packages were not installed; the owner runs `flutter pub get` and the device build.

## Next

M8 retirement of the notifications plan is separate. Nothing else is planned in this folder.
