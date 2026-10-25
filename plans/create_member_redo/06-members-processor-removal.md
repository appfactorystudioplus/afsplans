# 06 — Remove the unused processor server code from the members app

Status: DONE 2026-10-15 for steps 1 to 3 and 5 (see "Done 2026-10-15" at the end); step 4 (dashboard pages against dev) is not done. Paths are under `/Users/jamesmitchell/Desktop/projects/vagrant/afs-eco/w/members/`
unless stated.

## 1. Goal

The members app has its own copy of the payment-processor controllers and models that is never reached. Remove it so
there is one processor implementation (`w/www`) and no old fail-open webhook receiver sitting in a second repo.

## 2. Evidence (read from the code, nothing run against members)

| Fact | Where |
|---|---|
| The members app has exactly one `/portal` route: a catch-all that forwards `/portal/api/{path}` to the www origin (`BRAIN_API_URL`) at `/portal/api/{path}` | `routes/portal.php`, loaded under the `portal` prefix by `bootstrap/app.php:14` |
| No route file imports a `Processor` controller (`web.php`, `portal.php`, `www_internal.php`, `market_events.php`) | `routes/*.php` |
| The members dashboard pays through the front-end SDK, which calls `/portal/api/v1/processors/...` and the Direct Payments endpoints, so www handles them | `resources/js/controllers/BillingApp.ts:792`, `resources/js/controllers/domains/purchase.ts:351` |
| Members and www share one database and all members tables are migrated from www: both `.env` files use `DB_DATABASE=testdb_appfactorystudio`, and `members/database/migrations` is empty. The members models point at the same real tables as www's (`laravel_processors`, `laravel_processor_customers`, `laravel_processor_subscriptions`, `laravel_processor_webhooks`, `laravel_direct_payment_invoices`, ...) | `members/.env`, `www/.env`, `app/Models/Processor/*` `$table` |
| The members models are older than the shared schema: `ProcessorSubscription` lacks 8 fillable columns www has (`next_charge_at`, `next_charge_amount_cents`, `currency`, `current_period_start`, `cancel_at_period_end`, `provider_status`, `last_synced_at`, `last_sync_error`) and `ProcessorCharge` lacks 4 (`connected_account_id`, `application_fee_cents`, `transfer_id`, `is_split`) | model `$fillable` arrays, members vs www |
| The deployed dev members app registers 183 routes and none of them belongs to a Processor controller or has `processors` or `webhook` in its URI | read-only `artisan route:list` on the dev server, project `members` |
| Nothing outside the Processor folders refers to the controllers or models (searched `app`, `routes`, `bootstrap`, `config`, `tests`, `database`) | grep for `Controllers\Processor`, `Models\Processor` |
| The copy is an early snapshot of www's code: the webhook receiver picks the first active Stripe processor and only checks the signature when a secret is set, so with no secret it accepts any request (www's version fails closed) | `app/Http/Controllers/Processor/ProcessorWebhookController.php:118-131` |
| The folder arrived in the single initial commit (`ecdcd7c0`) | `git log` |

### How a members payment actually flows (domain purchase, dev)

1. `purchase.ts` `buildInvoice()` initialises the Direct Payment from `VITE_DIRECT_PAYMENT_ID` (dev: `eq4W10iF8`) and
   builds an invoice for the item `VITE_DIRECT_PAYMENT_PURCHASE_ITEM_ID`.
2. `chargeDirectPayment()` charges through `Portal.directPayments` with `user_id` = the member reference. These calls hit
   the members proxy and are forwarded to www.
3. www saves the invoice (`DirectPaymentsController::saveInvoice`), creates the Stripe intent
   (`ProcessorIntentController`), and the Stripe webhook records the charge.
4. Dev binding: Direct Payment `eq4W10iF8` is bound (active) to Stripe sandbox processor `2uDJGCr`.

## 3. What gets removed

| Path | Lines | Note |
|---|---|---|
| `app/Http/Controllers/Processor/` (13 controllers: Admin, Billing, Charges, Client, Connect, Customer, DiscountAdmin, Intent, PlanAdmin, Subscription, Sync, TaxDiscount, Webhook) | 4,722 | whole folder |
| `app/Models/Processor/` (Processor, ProcessorCharge, ProcessorConnectedAccount, ProcessorCustomer, ProcessorDiscount, ProcessorPlan, ProcessorSubscription, ProcessorWebhook) | ~700 | they point at the real shared tables but nothing in members uses them, and two are stale against the schema (see section 2); www owns these tables |

## 4. What stays (and why)

| Path | Why |
|---|---|
| `routes/portal.php` proxy | it is how the dashboard reaches www |
| the front-end SDK that holds `Portal.processors` and `Portal.directPayments` (`resources/js/afslibs/afs/afssdk.ts` and the `public/playground/...` copies) | `BillingApp.ts` and `purchase.ts` call it |
| `app/Http/Controllers/Market/ConnectController.php`, `app/Services/Market/BrainConnectClient.php`, `config/market.php` | Market Connect through the Brain; they only mention Direct Payments in a comment |
| `core/scripts/includes/...` (`StripeModule.php`, `stripe_payments.php`, `functions.php`) | legacy `core` folder, out of scope as before |

## 5. Check before deleting

1. **The deployed members copy.** DONE on dev: `artisan route:list` shows 183 routes and no Processor controller. Repeat
   on any other environment the members app is deployed to (read-only, `remote-run.sh -p members`).
2. **The CSRF exception** `'portal/api/v1/processors/*'` in `bootstrap/app.php:33`. The `portal.php` routes are loaded in
   the `then:` closure with only the `portal` prefix (no `web` group), so CSRF probably never applies to them and the
   line may do nothing. Do not remove it with this change. Test a proxied POST (for example the processor
   `create-intent` call) with it removed, then decide separately.
3. **Search once more** for `Controllers\Processor` and `Models\Processor` across the repo, including `tests/`.

## 5a. Why this is worth removing even though the tables are shared

Because both apps use one database, the unrouted members copy is not harmless dead code: if a route were ever added to
it (or the proxy bypassed), it would read and write the live processor, customer, subscription, charge and webhook
rows with an older column set (the stale models above) and an older, fail-open webhook receiver. www owns those tables
and their migrations; members should only reach them through www.

## 6. Steps (after approval)

1. Run the checks in section 5.
2. `git rm -r app/Http/Controllers/Processor app/Models/Processor` in the members repo.
3. Run the members test suite and `php artisan route:list` (route list unchanged).
4. Load the dashboard billing page and a domain-purchase screen against dev (they go through the proxy, so they should
   not change).
5. One commit: "Remove the unused processor controllers and models".

## 7. Open

* DECIDED: remove the models too (`app/Models/Processor/`), together with the controllers.
* The CSRF line: decide after the proxied-POST test.
* `public/playground` is the owner's scratch area and is out of scope. Leave it alone.

## Done 2026-10-15

Committed in `w/members`, nothing pushed.

* **Search (check 3):** no reference to `Controllers\Processor` or `Models\Processor` in `app`, `routes`, `bootstrap`, `config`, `tests` or `database`; the only `Processor` left is Monolog's in `config/logging.php`.
* **Removed:** `app/Http/Controllers/Processor/` (13 controllers) and `app/Models/Processor/` (8 models) with `git rm -r`, 21 files.
* **Routes:** `php artisan route:list --json` gives 183 routes before and 183 after, identical; the app boots (`artisan about`).
* **Tests:** `members` has no `vendor/bin/phpunit` locally, so its test suite was not run; no test refers to the removed code.
* **Left alone (as decided):** the CSRF line `'portal/api/v1/processors/*'` in `bootstrap/app.php`, `routes/portal.php`, the front-end SDK, `Market` Connect and `public/playground`.
* **Not done:** step 4, loading the dashboard billing page and a domain-purchase screen against dev; check 1 on environments other than dev.
