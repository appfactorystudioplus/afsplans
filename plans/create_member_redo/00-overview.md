# Create member — redo

Goal: when a payment succeeds, Direct Payments sends the mail handlers attached to that Direct Payment, with the payment
info in the email, for every provider (Stripe, Square, PayPal), for subscriptions and single charges alike. The work began as
a signup welcome email; Direct Payments has no such concept (no member, no tier), so the design is now the module's own
"mail handler on a successful purchase" ([07](07-direct-payment-email.md)).

Working rule for this plan: small steps, each one agreed before it is made. No new tables or columns, and no
new Direct Payments concepts, unless the owner says so.

The welcome-email test block restored in `MembershipController::store()` is left exactly as it is (owner). No plan here changes it.

## Plan files

All of these are PLAN ONLY unless a line says DONE. Read them in this order.

| # | File | What it settles | State |
|---|---|---|---|
| 01 | [01-website-reference-num.md](01-website-reference-num.md) | one source for `website_reference_num`; payer scope removed from Direct Payments | **DONE** 2026-10-15 (code, tests and plan status committed) |
| 02 | (removed) | the one-time seeder for the Brain's own `subdomain_properties`; replaced by the rows in `lab2/databases/production/testdb_appfactorystudio.data-only.sql`. That file now has the `website_reference_num` rows for `www` / `www15` and the Brain's own `brain_http_server_tenants` and `members_websites` rows (checked 2026-10-15); its `laravel_sponsorships` INSERTs lost the removed column | removed; nothing open |
| 03 | [03-welcome-email.md](03-welcome-email.md) | the first email design (member/tier based) | **superseded by 07**; do not follow |
| 04 | [04-charge-succeeded-hook.md](04-charge-succeeded-hook.md) | one `ChargeSucceeded` call from every provider's successful-charge path; the Stripe, Square and PayPal gaps | plan; decisions A (PayPal capture) and A (Stripe `charge.succeeded`) made |
| 05 | [05-webhook-registration.md](05-webhook-registration.md) | register the three providers' webhooks from the admin processor dashboard | plan; five open questions |
| 06 | [06-members-processor-removal.md](06-members-processor-removal.md) | remove the unused processor controllers and models from `members` | plan; models-too decided |
| 07 | [07-direct-payment-email.md](07-direct-payment-email.md) | what `ChargeSucceeded` does: handlers by `item_reference_num`, per-provider parsers, variable registry, delayed job | plan; `variables.json` group DONE |
| 08 | [08-queue-worker.md](08-queue-worker.md) | one scheduled `queue:work` line so the delayed job runs | plan; delay of 30 to 90 s decided |
| 09 | [09-brain-schedule-move.md](09-brain-schedule-move.md) | move the nine Brain-only scheduled commands from www to members | plan; `domains:deploys` detached decided |
| 10 | [10-run-as-apache.md](10-run-as-apache.md) | web, scheduler, queue and Brain commands all run as apache; `laravel-cron.sh` takes user and group | plan |
| 11 | [11-domain-and-website-on-dev.md](11-domain-and-website-on-dev.md) | add or buy a domain and deploy a website with the old flow, on dev; signed Brain routes instead of level-99 routes | plan |

How they depend on each other: 04 supplies the call, 07 says what it does, 08 and 10 make the delayed job run, 09 uses the
same scheduler and the same user, 05 and 06 are independent clean-ups of the processor code.

## Cleanup item — appfactory concepts inside Direct Payments code (not started)

Direct Payments is its own API: a **tier** there is a provider plan (Stripe/Square/PayPal `provider_plan_id` +
`original_data`) with an operator-typed `custom_title` and `level`
(`laravel_direct_payment_tiers`). It has nothing to do with `auth/public/checkpoint/checkpoint_registry_v4.json`
(confirmed: no registry reference anywhere in the Direct Payments code). It also has no concept of a member.

These places in Direct Payments code still reach into appfactory's member tables and should be moved out or
replaced by an event the appfactory side listens to (the `SubscriptionPlanChanged` / `ApplyTierOnPlanChange`
pattern):

| File (under `w/www/`) | Line(s) | What it does |
|---|---|---|
| `app/Http/Controllers/Externals/DirectPayments/DirectPaymentSubscriptionController.php` | 12, 202, 612, 690, 744 | imports `Membership`; reads `payer_reference_num` / `reference_num` from `brain_memberships` |
| `app/Http/Controllers/Externals/DirectPayments/DirectPaymentSubscriptionController.php` | 678-695 | reads `brain_membership_billing_state` / `BrainMembershipBillingState` |
| `app/Externals/DirectPayments/Dunning/AdvancedConfigGate.php` | 6, 71 | reads `Membership::...->value('level')` |
| `app/Externals/DirectPayments/Notifications/NotificationDispatcher.php` | 11, 250, 349 | looks up `Membership` by payer for the email / name variables |
| `app/Http/Controllers/Externals/DirectPayments/DirectPaymentsController.php` | 465 | comment names `MembershipTierResolver` |

## Rule: `payer_reference_num` belongs to Direct Payments

`payer_reference_num` is a Direct Payments identifier (`laravel_direct_payment_payers`). When a member is created,
Direct Payments creates/resolves the payer and **returns** that id, and the member row stores it. The member side
never invents it or looks it up from its own tables to give to Direct Payments.

Superseded: the "two payers" worry was caused by `website_reference_num` scoping payers, which Direct Payments does
not need. See [01-website-reference-num.md](01-website-reference-num.md) (payer scope removed; `saveInvoice()`
always creates the payer and returns it; `store()` takes that payer instead of creating its own).

## Rule: `VITE_SUBDOMAIN` is the single source of the site label

A site is `www` unless `.env` says otherwise. `VITE_SUBDOMAIN` (config `afs_connect.subdomain`, default `www`) is
the only thing that decides which `subdomain_properties` rows a site loads
(`FunctionsController::getSubdomain2()` already returns it). `public/type.txt` is not a source of truth.

Cleanup to do later, not started (all under `w/`):

| File | Line(s) | What |
|---|---|---|
| `www/app/Http/Controllers/Portal/Mail/MailMuaController.php` | 39 | DONE: now `FunctionsController::getSubdomain2()` (VITE_SUBDOMAIN) |
| `www/app/Http/Controllers/FunctionsController.php` | 890-904 | commented-out `type.txt` block in `getSubdomain2()`; delete |
| `members/core/scripts/remote/builder/app_install.sh` | 562, 605, 1042, 1059 | writes `public/_portal_/type.txt`; replace with a `VITE_SUBDOMAIN=` line in the tenant `.env` |
| `www/core/scripts/run_setup.sh` | 8 | writes `portal/type.txt` |
| several files in `www` and `members` | see [11](11-domain-and-website-on-dev.md) section 5.4 | `www15` → `dev/www15` / `dev/members15` folder literals; the real dev folders are `dev/www` and `dev/members` |
| `members/core/scripts/remote/builder/app_install_template.sh` | 33 | writes `portal/type.txt` |
| `www/app/Console/Commands/AllocReconcilePayments.php` | 36-38, 175 | comments that describe the `type.txt` fallback |

Also hard-coded to the label: `FunctionsController::RunCommandBG` and `SendEmailBuilder` branch on `Subdomain ==
"www15"` to pick the dev path. They should read the same `VITE_SUBDOMAIN` value (already what `Subdomain` is).
