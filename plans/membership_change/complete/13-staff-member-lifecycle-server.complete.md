# 13 — The server: create a member with its billing through Direct Payments (complete — built and tested; nothing seen against real Stripe or in a browser)

## Status

**Complete 2026-10-20.** Nothing is committed. The Brain can now create a member and arrange how they pay **through Direct Payments' normal flow**: nothing to pay (free plan or complimentary), a card page,
a free period with a card, or a free period with no card (the subscription **pauses** when the free period ends, then a 7-day grace, then the billing ladder's own downgrade). Stripe `paused` is understood,
tax is a processor setting, and **no member can ever be level 99 except `appfactorystudio`**. Brain only (`brain.only` routes, Brain-only module).

Work is in `w/www` only. The phone and the dashboard screens that call this are [14](../14-phone-and-dashboard-member-lifecycle.md).

## Phases

| Phase | Piece | State | Where |
|---|---|---|---|
| P0 | Start options: an administrator-set, one-use, 24-hour "start this payer's subscription later / pause at the end" held by Direct Payments | **built** | `StartOptions`, `DirectPaymentStartOption`, migration `2026_10_20_000001` |
| P1 | Stripe creator: `end_behavior` (pause) and `automatic_tax`; customer address; the subscription start takes the option | **built** | `ProviderSubscriptionCreator`, `ProcessorSubscriptionController`, `ServerCustomerResolver::payerForRequest` |
| P2 | `paused` end to end: reader, resume on a new card, governing status, 7-day grace in the ladder, `subscription_paused` notice (on by default) and webhooks | **built** | `StripeSubscriptionReader`, `StripeSubscriptionActions`, `MembershipTierResolver`, `DunningService::advancePaused`, `DirectPaymentsActivityModule`, webhook routes |
| P3 | Tax as a processor setting | **built** | `processors:set-automatic-tax {reference} {on\|off}` |
| P4 | Billing service: modes, one-time card sessions, payer token with a one-hour life, "saved" is asked not believed | **built** | `Services/Brain/Memberships/Billing/{MemberBilling,FreePeriod,BillingException}`, `BrainBillingSession`, migration `2026_10_20_000002` |
| P5 | Staff routes, the card page and its two endpoints | **built** | `Brain/Staff/StaffMemberCreateController`, `StaffMemberBillingController`, `Brain/BillingSessionController`, `resources/js/afs/card.ts`, `afs/card*.blade.php`, routes, `RoutePolicy`, `StaffCapabilities`, `StaffCreateOptionsController` |
| P6 | Level 99 rule | **built** | `Membership` saving guard, `memberships:check-levels` |
| P7 | This record, README, memory | **done** | — |

## What was built

**Billing goes through Direct Payments.** The operator never calls Stripe. `MemberBilling::begin` either opens a card session (the card page runs the Direct Payments SDK as the member's payer, with a
payer token that lives one hour, not 180 days) or, for a free period with no card, calls `DirectPaymentCheckoutController::startSubscription` with that payer token — the same start every customer uses.
The free period reaches Direct Payments as a **start option** written by staff-authenticated code; no customer-facing route can write one, and a request cannot supply its own free period.

**Modes:** `none` (free plan), `complimentary` (paid plan, no billing, `tier_source = admin`), `card_now`, `free_period_card`, `free_period`. Free periods: 7d, 14d, 1m, 3m, 6m, 12m.

**"Saved" is never believed.** The card page tells the Brain it finished; the Brain re-reads the subscription through `SubscriptionSyncService::syncOne`, requires it to belong to **this member's payer** and be
active or trialing, and only then joins the member to it. A failed or expired session leaves `billing_setup = pending`, so "Finish billing" is offered.

**Routes.** `POST api/v1/brain/staff/members` (create; the billing choice is checked **before** anything is created; a billing failure keeps the member and returns `billing_error`);
`/members/{ref}/billing-session` (open), `/billing-session/{id}` (poll), `/subscription/start`; public `GET /brain/card/{session}` and `POST api/v1/brain/billing-sessions/{id}/complete` (throttled).
Cancel accepts `access = immediately | period_end`. Create-options returns `free_periods`, `billing_modes`, `password_modes`.

**Paused.** A free period that ends with no card pauses (`trial_settings.end_behavior.missing_payment_method = pause`, sent only for a deferred start with no card on file). The ladder gives a paused
member `memberships.paused_grace_days` (7), sends none of the failed-payment mails, then downgrades; a card resumes the subscription and the existing restore path puts the plan back.

**Level 99.** A model guard throws when anyone but `appfactorystudio` is set to 99, when it loses 99, or when a second 99 would exist; `memberships:check-levels` verifies the data. `phpunit.xml` pins the
guard off (`MEMBERSHIPS_SINGLE_LEVEL_99=false`) because older tests build several level-99 staff; every new test turns it on.

**Config / env.** `MEMBERSHIPS_CARD_PAGE_URL`, `MEMBERSHIPS_PAUSED_GRACE_DAYS`, `MEMBERSHIPS_SINGLE_LEVEL_99` (default on).

## Tests

| File | Tests | Proves |
|---|---|---|
| `Unit/Memberships/FreePeriodTest` | — | the menu and the dates |
| `Feature/DirectPayments/StartOptionsTest` | 7 | bound to payer and tier, used once, expires, replace, release, no customer-facing writer |
| `Feature/DirectPayments/ProviderSubscriptionCreatorTest` | +4 | pause only when asked, tax only when on, address fields |
| `Feature/Membership/PausedSubscriptionGraceTest` | 7 | grace, downgrade after it, restore, config length, dry run, Stripe `paused` |
| `Feature/Membership/SingleLevel99Test` | 10 | the 99 rule, the check command, no route reads `level` |
| `Feature/Membership/MemberBillingTest` | 17 | choices, sessions, start option, one-hour token, "saved" asked, other member's subscription never taken, no-card start as the payer, refused start |
| `Feature/Membership/StaffMemberCreateBillingTest` | 12 | order of checks, nothing created on refusal, billing failure keeps the member, card page and `complete` |
| `Feature/DirectPayments/DirectPaymentsNotificationModuleTest` | edited | 14 events; `subscription_paused` on by default |

## Final run

Below-normal priority, one process at a time, MariaDB 11.4.5 on port 3307, shut down afterwards.

| Pass | Suites | Result |
|---|---|---|
| PHP, new files | `MemberBillingTest`, `StaffMemberCreateBillingTest`, `SingleLevel99Test` | **OK: 39 tests, 113 assertions** |
| PHP, targeted | `Unit/Memberships`, `Feature/Membership`, `StartOptionsTest`, `ProviderSubscriptionCreatorTest`, `DirectPaymentsNotificationModuleTest` | 134 tests; the 6 errors/failures were all in the three files above and are fixed (see below) |
| PHP, older files re-run one at a time | `BrainStaffSubscriptionTest` (34), `BrainStaffWhoamiTest` (7), `BrainStaffWriteTest` (14), `DirectPaymentSubscriptionUpdateMembershipTest` (4), `TierResolverFollowsSubscriptionTest` (7), `RoutePolicyTest` (3), `Unit/DirectPayments/LayeringTest` (2), `ActivityCatalogTest` (8), `WebhookCandidateTest` (3), `ProviderEndedSubscriptionTest` (6), `DirectPaymentCheckoutPriceTest` (6), `NotificationRulesApiTest` (24), `ActivityPublisherTest` (17), `DefaultEmailsTest` (18, 283 assertions) | **all OK** |
| PHP, whole directories | Direct Payments, Processor, Brain, Notifications, Externals | **not completed**: two attempts hit a 600 s limit and ran for hours (each test rebuilds the schema), so they were replaced by the per-file runs above. The whole suite is left for the end of the folder. |
| TypeScript | `tsc --noEmit` (includes the new `resources/js/afs/card.ts`) and `npm run test:admin` | **clean; 81/81 tests pass** |

Two older tests needed changes because of this step: `RoutePolicy` (the Brain card `complete` route was wrongly in `PUBLIC_WRITES`, which only covers externals routes; the route is `brain.only` and throttled) and `DefaultEmailsTest` (15 default e-mails now, because `subscription_paused` added one; the module's own count is 14 events + `payment_receipt`).

Problems found by the run and fixed (all in tests): helper names that clash with `TestCase` (`post`, `options`); the audit mock's return type; the processor row the Direct Payments foreign key needs;
the "on by default" test read a value phpunit pins off, so it now reads the config source.

## Deviations from the plan

1. **No Direct Payments invoice is saved for the no-card free-period path.** The first charge's invoice is recorded by Direct Payments' normal webhook/linker when it happens.
2. **Card brand and last four are not shown** to the operator.
3. **`processors` stays in create-options** — the phone still uses it until step 14.
4. **`StaffSubscriptionStarter`'s plan name is replaced by `MemberBilling`**; the mode names are `none / complimentary / card_now / free_period_card / free_period`.
5. **No `PayerService::updateAddress`**; the tax address is set through `ProviderSubscriptionCreator::stripeCustomerAddress`.
6. **`access` override on cancel** (immediately / period end) was added.
7. **`subscription_paused` notice** added to Direct Payments, on by default (bell + mail).
8. **Stripe `resume` uses raw curl**, so it is not covered by `Http::fake`.
9. **The staff HTTP tests call the controllers directly** with the member-creating and billing collaborators mocked, rather than going through the staff login middleware; the middleware and route registration are covered by `php artisan route:list` and the existing staff-auth tests.

## Not verified

- **Nothing ran against real Stripe**: pause at the end of a free period, resume on a new card, `automatic_tax`, and the webhooks `customer.subscription.paused|resumed`.
- **Stripe Tax:** run `processors:set-automatic-tax <ref> on` only after Stripe Tax is confirmed on the development Stripe account. Once on, it also applies to the public signup.
- **The card page was not opened in a browser** (`card.ts` compiles; its flow is untested end to end).
- **`.env` to set:** `IS_BRAIN=true`, `MEMBERSHIPS_LOGIN_URL`, `MEMBERSHIPS_CARD_PAGE_URL`, optional `MEMBERSHIPS_PAUSED_GRACE_DAYS`. None are in `_env/*/.env` yet.
- **Deploy order:** migrations first (`2026_10_20_000001`, `2026_10_20_000002`), then `www`; build the `afs` bundle for `card.ts`.
- `LaravelCronScriptTest` has 18 known unrelated errors.

## Next

[14](../14-phone-and-dashboard-member-lifecycle.md) — the phone and dashboard screens, and the phone's alerts ([12](../12-phone-add-member-and-alerts.md)).
