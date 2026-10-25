# 02 — Current State Audit

> **Moved from `billing/02-current-state-audit.md` 2026-09-22** as step 20 of
> [`direct_payments`](README.md) — content and internal `§2.x` numbering unchanged; only the
> filename and folder moved, because this audit covers generic Direct Payments/processor
> infrastructure every tenant shares, not appfactory studio's own billing.

Everything below was read from the repository, not assumed. Each finding is numbered so
later documents can cite it.

## 2.1 Subscription persistence — the blocking defect

**`laravel_processor_subscriptions`** (`www/database/migrations/2026_05_21_000001_create_processor_tables.php:110`)

```php
$table->ulid('id')->primary();
$table->string('processor_reference_num', 7)->index();
$table->ulid('customer_id')->nullable()->index();
$table->ulid('plan_id')->nullable()->index();
$table->string('provider', 50);
$table->string('provider_subscription_id', 255)->nullable()->index();
$table->string('status', 50)->default('incomplete');
$table->integer('trial_days')->nullable();
$table->string('discount_code', 100)->nullable();
$table->timestamp('trial_ends_at')->nullable();
$table->timestamp('current_period_end')->nullable();
```

| # | Finding | Consequence |
|---|---|---|
| **B1** | **No link of any kind between a Direct Payment and a subscription** — not a column, not a table. `ProcessorSubscriptionController` is DP-agnostic by design (the SDK's `createSubscription()` POST carries no DP reference), and nothing else records the association | Given a notification config attached to a DP, **there is no query that reaches its subscriptions.** Part 1 is impossible without this. The fix is a DP-owned link table, **not** a column on the processor row — see [22](22-subscription-state.md) §4.2 |
| **B2** | No link between a subscription and a member | Given a subscription, there is no query that reaches the member. Part 2 is impossible without this. Same fix, same table |
| **B3** | `current_period_end` is set **only** by `ProcessorWebhookController::logStripeSubscription()` (line 634) and `logSquareSubscription()` (925). `ProcessorSubscriptionController::createStripeSubscription()` (line 280) writes `status`, `trial_days`, `discount_code` — **not** `current_period_end` | Every subscription created before webhooks were configured has `next charge = null`, permanently |
| **B4** | PayPal's handler (`logPayPalSubscription`, line 1440) does a bare `->update([...])` on a `where(provider_subscription_id)` — it does not `updateOrCreate`, so a PayPal subscription whose row was never created is silently dropped | PayPal subscriptions can be invisible to the database entirely |
| **B5** | No amount/currency on the row | An "upcoming charge" email cannot state the amount without a second join to `laravel_processor_plans`, which is itself nullable (`plan_id` is nullable and is resolved by a `provider_plan_id` lookup that can miss) |
| **B6** | Nothing records *when* the row was last confirmed against the provider | No way to bound a sync sweep to stale rows |

`current_period_end` is also semantically wrong as a "next charge" for a subscription with
`cancel_at_period_end = true` — that is a *termination* date, not a charge date, and no
column records the flag.

## 2.2 The Direct Payments configuration

`www/app/Http/Controllers/Externals/DirectPayments/DirectPaymentsController::saveDirectPayment()`
(line 160) is the single admin write path. It:

1. validates `pay_type ∈ {SINGLE_PAY, SUBSCRIPTION, CONNECT}`,
2. `firstOrNew` on `reference_num`, saves name/processor/pay_type,
3. calls `syncConnectConfig()` — **the precedent for a child config table**
   (`laravel_direct_payment_connect`, one row per DP, written from a `connect` key on the
   request),
4. deletes-and-rebuilds `laravel_direct_payment_items` and `laravel_direct_payment_tiers`,
5. returns the reloaded DP with `items`, `tiers` and `connect` attached.

| # | Finding | Consequence |
|---|---|---|
| **B7** | No side effect of any kind runs on save — no command, no cron, no cache bust | The `--update-cron-task` hook has a clean insertion point (after step 3) and no existing behaviour to preserve |
| **B8** | Items and tiers are delete-and-rebuild; `laravel_direct_payment_tiers.reference_num` is regenerated with `Str::random(12)` on every save | Notification config must **not** follow that pattern — a delete-and-rebuild would orphan the send ledger every time an operator renames the DP. Use `updateOrCreate` keyed on `(dp_ref, event)` |

The admin UI is `www/resources/js/portal/admin/controllers/direct_payments/directpayments.ts`
(786 lines) with markup in `.../html/views/directpayments-form.html` (229 lines). The form
already has three conditionally-shown `<div class="card ... d-none" id="dp{SinglePay,Subscription,Connect}Section">`
blocks toggled by a `change` listener on `#dpPayType` — the notification card slots in as a
fourth, shown for `SUBSCRIPTION` (and, for action events, `SINGLE_PAY`).

## 2.3 The mail-handler send path

`laravel_mail_handlers` (`App\Models\MailHandlerModel`): `name`, `category`,
`reference_num`, `item_reference_num`, `json`.

The `json` column is **htmlentities-encoded** (`MailHandlerController::store()`:
`htmlentities(json_encode($handler), ENT_QUOTES, 'UTF-8')`) and must be read back with
`FunctionsController::Utils_decodeStringToJson()` (`json_decode(html_entity_decode($str), true)`).

### The correct send sequence

`FunctionsController::SendEmailBuildIntegration()` is the entry point, and **its own
signature is the lesson**:

```php
SendEmailBuildIntegration($handler, $integration, $myconfig, $managed_domain, $cb)
```

`$integration`, `$myconfig` and `$managed_domain` are **parameters, not lookups** —
resolving them is the caller's job, every time. `Portal/Admin/MembershipController.php:127`
is the production caller that does it correctly:

```php
$config_domain  = FunctionsController::__get_main_configurations();
$managed_domain = $config_domain["managed_domain"];
$myconfig       = $config_domain["configurations"];

$integration    = \App\Models\MailProviderModel::where('status', 1)->first();
$brainConfig    = FunctionsController::getBrainConfig();
$sendgridToken  = $brainConfig['controller']['email_providers'][0]['token'];
$integration["key"] = $sendgridToken;          // ← the step SendEmailHandler() skips
```

then the send itself:

```php
$mailHandler     = MailHandlerModel::where('reference_num', $ref)->first();
$mailHandlerJson = FunctionsController::Utils_decodeStringToJson($mailHandler['json']);

$handlers = $mailHandlerJson['handlers'] ?? [];
foreach ($handlers as $k => $v) { $v['emails'] = [$recipient]; $handlers[$k] = $v; }
$mailHandlerJson['handlers'] = $handlers;

FunctionsController::SendEmailBuildIntegration(
    $mailHandlerJson, $client_variables, $integration, $myconfig, $managed_domain,
    function ($resp) { /* … */ }
);
```

**Use `SendEmailBuildIntegration()`, not `SendEmailHandler()`.** `SendEmailHandler()`
(line 1982) looks like the convenient wrapper — it resolves `__get_main_configurations()`
and the active `MailProviderModel` itself — but that internal resolution is exactly the one
that omits the token injection above. See **B9**.

Two other places in the repo contain the same method body, useful only as further worked
examples of the shape:

- `TestFunctions::SendEmail()` — an inline sketch of the sequence above. The **class** is
  explicitly not for use (`"DO NOT USE THIS CLASS, IT IS FOR TESTING PURPOSES ONLY"`, and
  it is at global scope with no `namespace`, calling `FunctionsController::` unqualified).
  Read the method body; do not call it, extend it, or model a class on it.
- `EmailAccountController::sendInviteEmail()` (line 384) — a complete worked example of the
  handler-array shape, built from scratch rather than loaded from `laravel_mail_handlers`.

| # | Finding | Consequence for this plan |
|---|---|---|
| **B9** | `SendEmailHandler()` resolves `$integration = MailProviderModel::where('status',1)->first()` but — unlike `GetActiveEmailIntegration2()` (line 2001) and `MembershipController` (line 133) — **never sets `$integration["key"]` from `getBrainConfig()['controller']['email_providers'][0]['token']`** | On a deployment whose `laravel_mail_providers` row has no key of its own, `SendEmailHandler()` sends with an empty SendGrid token and fails silently. **`BillingMailer` calls `SendEmailBuildIntegration()` with a caller-resolved `$integration`** — it does not use `SendEmailHandler()` |
| **B24** | `getBrainConfig()` returns `null` when the Brain API response is not `ok()` (line 86). Every reference implementation then does `$brainConfig['controller']['email_providers'][0]['token']` **unguarded** | On the web that is one failed request. In a cron it is a fatal `TypeError` that kills the tick, and the next tick repeats it. `BillingMailer` must null-check the chain and record a `failed` ledger row instead |
| **B25** | **`variables.client` is indexed PER RECIPIENT.** `SendEmailBuilder` reads `$form_data["variables"]["client"][$i][$n]` where `$i` is the recipient loop index (line ~1790) — whereas `schema` is force-wrapped to `[$schema]` and always read at `[0]`, and `system` is overwritten wholesale from `LaravelSetting` (line 1699) | This is why `$client_variables` is doubly nested (`[[ {name,value}, … ]]`). **Two recipients with one variable set means `["client"][1]` is undefined → `count(null)` → `TypeError`.** The surrounding `catch (\Exception)` does **not** catch a PHP 8 `Error`, so it is fatal. See [24](24-notification-scheduler.md) §6.6 |
| **B10** | `SendEmailBuilder()` calls `$cb($message_response, $domain)` on its `active=false` and `count(emails)==0` early-return paths **without a null check** (lines 1692, 1716) | A null `$cb` is a fatal `Error` inside a cron run. Always pass a closure |
| **B11** | `SendEmailBuilder()` and `SendEmailHandler()` write debug JSON to hardcoded `/home/appfactorystudio/websites/dev/www15/storage/store/hello*.json` | On any tenant, and on prod `www`, these are unwritable paths. Harmless (`Utils_WriteFileJson` on a bad path) but noisy, and they should not be in a path a cron runs 96×/day. Flag for cleanup; do not block on it |
| **B12** | MJML transform writes to `/home/{SystemUser}/websites/{subpath}/public/_portal_/admin/core/services/email/templates/output/forms` and shells out to `commands.sh --transform-email-mjml`, then `sleep(1)` | A notification run that sends 200 emails costs 200 seconds of `sleep` alone. `BillingMailer` must cap sends per tick ([24](24-notification-scheduler.md) §6.6) |
| **B13** | `MailHandlerController::index()` requires an `item_reference_num` query param and returns `{}` without one | There is **no** endpoint that lists all mail handlers. The dropdown needs one ([23](23-notification-config.md) §5.5) |
| **B14** | There is no shared helper for the §2.3 sequence — it is copy-pasted into `MembershipController`, `EmailAccountController` and the `TestFunctions` scratch class, and the one wrapper that exists (`SendEmailHandler()`) is the broken one (**B9**) | Each copy can drift, and two of the three already have. `BillingMailer` is a **namespaced service** encapsulating the sequence once; billing calls that and nothing else. Whether the rest of the codebase adopts it is out of scope here |

## 2.4 The shell and cron surface

`www/core/scripts/commands.sh` — 1257 lines, `#!/bin/sh`, positional args
`action=$1 … param8=$9`, and ~30 `if [ "$action" = "--…" ]; then … fi` blocks. Helpers:
`create_log_directory $systemuser $logfile`, `update_system_owner`. `STORAGE_LOCATION="node1"`
is hardcoded; logs go to `/mnt/node1/{systemuser}/logs/`.

`FunctionsController::RunCommandBG($shParams, $managed_domain, $cb, $isOutput)` (line 594):

```php
$subpath = $managed_domain["Subdomain"];
if ($subpath == "www15") { $subpath = "dev/$subpath"; }
$basefolder = "/home/{SystemUser}/websites/$subpath/core/scripts";
$cmd = "$basefolder/bin/afs-website \"$basefolder/commands.sh $shParams\"";
popen("nohup $cmd 2>&1 > /dev/null & echo $!", 'r');
```

| # | Finding | Consequence |
|---|---|---|
| **B15** | `core/scripts/bin/afs-website` is a **setuid ELF binary** that calls `setuid()` then `system()` | `commands.sh` runs as root. `crontab -u`, `/var/spool/cron` and `systemctl restart crond` are all available |
| **B16** | `RunCommandBG` maps `www15 → dev/www15`, but the requested cron line hardcodes `websites/www/` | On the dev Brain the correct path is `/home/appfactorystudio/websites/dev/www15/`. See [21](21-cron-bootstrap.md) §3.2 — the block takes the subpath as a second parameter with `www` as default, so the literal one-argument call in the request still works |
| **B17** | `commands.sh` blocks are inconsistent about `exit 0` — some fall through | The new block must `exit 0`, or a later block could match on a stale `$action` |

## 2.5 The members dashboard

`members/resources/js/controllers/BillingApp.ts`, 1342 lines.

| # | Finding | Consequence |
|---|---|---|
| **B18** | Lines 278 and 343: `nextChargeDate: member.next_charge_date \|\| member.current_period_end \|\| null`. **Neither column exists on `brain_memberships`** (see the migration at `2026_05_15_000001_create_brain_tables.php:225`) | The next-charge line has never rendered. Step [10](../../billing/10-members-dashboard.md) points it at a real endpoint |
| **B19** | `initializeServices()` (line 140) hardcodes four demo services ("Premium Support", $49.99) and renders them beside real billing data | Not cosmetic once the rest of this plan lands: a screen whose whole premise is that a member can trust what it says cannot also show fake products. **Removed** in [10](../../billing/10-members-dashboard.md) §10.3 |
| **B20** | The subscription is matched by `Portal.directPayments.findActiveSubscriptionMatch(invoices, plans)` — an *invoice*-based heuristic, with a fallback that matches `member.membership_id` against plan ids | Once the DP link table exists (B1/B2), the dashboard can read the subscription directly instead of inferring it |
| **B37** | **`BillingApp.selectPlan()` makes no network call.** It sets `this.currentSubscription = plan`, writes `localStorage` and toasts *"Successfully switched to the {plan} plan"*. Its own comment says so: *"In a real app we'd charge for pro-rated amount or next cycle — Here we just note it"* | A member can "upgrade", see it confirmed, be charged nothing and receive nothing. Same root cause as **B27**: `localStorage` treated as billing state. See [10](../../billing/10-members-dashboard.md) §10.1 |

## 2.5b Subscription lifecycle actions — B27–B31

Cancel, plan change and payment-method change are analysed in full in
[28](28-subscription-actions.md) §14.1. The headline, because it does not belong buried in a
later document:

| # | Finding | Consequence |
|---|---|---|
| **B27** | **`BillingApp.cancelSubscription()` makes no network call.** It sets `this.currentSubscription = freePlan`, writes it to **`localStorage`**, re-renders and toasts *"Subscription cancelled."* `loadCurrentSubscription()` reads it back on next load | **The provider is never told; the member keeps being charged while their dashboard says they cancelled.** Survives reloads on that browser, evaporates on any other device. The most damaging defect in the billing area, and a live one |
| **B28** | No cancel endpoint exists. `ProcessorSubscriptionController` has one public method, `store()`; no route cancels anything on any provider | Even a correct client has nothing to call |
| **B29** | No plan/tier change. `updateBillingAmount()` changes the amount charged, not the `laravel_direct_payment_tiers` a member is on | No upgrade or downgrade path |
| **B30** | `updatePaymentMethod()` (line 585) handles Stripe only; its own comment says *"Square and PayPal … we just acknowledge for now"*, and both fall through to `success: true` | The UI reports success and nothing changed |
| **B31** | For Stripe it sets `customer.invoice_settings.default_payment_method`, **not** `subscription.default_payment_method` | Stripe's retry priority reads the subscription's field first — its docs state that updating only the customer's leaves retries on the old method. A member fixing their card mid-dunning keeps failing |

Full treatment, and the fix, in [28](28-subscription-actions.md).

## 2.5c Money and refunds — B32–B36

The **Financials → Money** section is already built (`MoneyView.ts`, 202 lines, nav item
`data-nav="money"`, registered in `utils.ts:456`) and has never worked. Full treatment in
[29](29-money-and-refunds.md).

| # | Finding | Consequence |
|---|---|---|
| **B32** | `MoneyView.loadCharges()` fetches `GET /portal/api/charges` — **the route does not exist** in `routes/portal.php` | The fetch fails, `setEmpty(true)` runs, and the section has always rendered its empty state. The UI is finished; nothing is behind it |
| **B33** | `ProcessorBillingController::refund()` 404s when `$charge->customer_id !== $customer->id` | Cannot refund a charge with no local customer — hence the parallel `refundByPaymentIntent()` written for market. Two refund paths, different auth, neither reachable from the admin |
| **B34** | **Refunds are never persisted.** `refundStripe()` returns the provider's refund id and writes nothing. No refund model, no refund table, no refund column on `laravel_processor_charges` | A refunded charge shows its full original amount, `succeeded`, forever. Every revenue total is wrong the moment anyone refunds |
| **B35** | Charges are recorded only by webhook (`recordStripeCharge()` and siblings) | A charge whose webhook never arrived is invisible to Money — **B3**'s defect class, with money instead of dates |
| **B36** | `laravel_processor_charges` has no Connect columns — no connected account, application fee or transfer id (at best inside `metadata`) | A split charge is indistinguishable from a platform charge, so Money cannot show who received the money — the first question anyone asks before refunding one |

## 2.5d Signup — B38–B41

`www/resources/js/afs/` is the public signup at `www.appfactory.studio`. `create.ts` drives
the flow; `memberships/index.ts` completes it. The paid path is:

```
getSelectedTier()  →  DP SDK creates the subscription  →  confirmPayment()
                   →  createParseAllocation()
                   →  registerMembership(customerId, allocId, subscriptionId, memberRef)
                   →  POST /portal/api/v1/memberships/register
```

| # | Finding | Consequence |
|---|---|---|
| **B38** | **The tier is client-supplied and never validated.** `registerMembership()` reads `sessionStorage.getItem('afs_selected_membership')` and posts it as `membership`; `MembershipController::store()` writes `'membership' => (int) ($data['membership'] ?? 0)` **verbatim**, with no check against the subscription that was actually paid for. The free path (`if (!isFree)`) skips `confirmPayment()` entirely | **A signup can claim any tier without paying.** Worse than the forgeable *read* in [09](../../billing/09-entitlement-integration.md) §9.4: this writes the forged tier into `brain_memberships`, from which cloud-storage, afs-connect and alloc_v2 all derive. And [08](../../billing/08-brain-enforcement.md) §8.3 point 4 would **not** correct it — with no resolvable `tier_paid` the member is *skipped* by design, so the fail-safe protects the forgery |
| **B39** | Signup never writes the DP↔subscription link row. It holds every field the row needs — `subscriptionId`, `memberRef`, `dpRef`, the selected tier — and scatters them into `brain_memberships.membership_id` / `.customer_id` / `.processor` / `.direct_payment` instead | Subscriptions created by the main signup would be invisible to [22](22-subscription-state.md) §4.2.2's link table, and so to notifications and enforcement |
| **B40** | **Ordering:** the subscription is created *before* the member exists — `registerMembership()` runs after `confirmPayment()`. So [22](22-subscription-state.md) §4.2.3's server-side `member_reference_num` resolution (Checkpoint bearer, else `brain_memberships.customer_id`) can resolve **nothing** at signup | The link row is born with a null member. Nothing in the plan adopted it afterwards until §4.2.4 |
| **B41** | `getOrGenerateMemberReferenceNum()` mints the member reference **client-side** (`Math.random()`, 10 chars, `sessionStorage`), and the server accepts it: `'reference_num' => $data['reference_num'] ?? Str::random(12)` | A caller chooses their own primary identifier. The unique index prevents takeover of an existing reference, so this is not an account-takeover path — but it is client-controlled identity, and the server should mint or at minimum validate it |

**B38 is the one to fix first in this group**, and it is close to **B27** in severity: B27
tells a member a falsehood, B38 lets a member tell *the platform* one.

## 2.6 Entitlement consumers — what already reads the tier

| Consumer | Reads | File |
|---|---|---|
| cloud-storage quota | `brain_memberships.membership`, clamped 0–4 | `members/app/Services/Storage/QuotaService.php:82` |
| cloud-storage retirement | `planFor()` vs `BucketUsage.bucket` | `members/app/Services/Storage/RetentionService.php:52` |
| cloud-storage sweep | hourly `storage:retention-sweep` | `members/routes/console.php`, `app/Console/Commands/StorageRetentionSweep.php` |
| afs-connect settings | `(int) $member->membership` | `members/app/Http/Controllers/AfsConnectSettingsController.php:98` |
| alloc_v2 client | caller-supplied `tier` index into `checkpoint_registry_v3.json` `tiers[]` | `www/resources/js/checkpoint/alloc_v2.ts` |

`checkpoint_registry_v3.json` (in `auth/public/checkpoint/`) has 29 resources and 5
positional tiers (`free, starter, professional, business, enterprise`).
`brain_memberships.membership`'s own comment labels them `0=Free, 1=Pro, 2=Business,
3=Organization, 4=Enterprise`. **The labels differ; the index is the contract** — already
established in [../../afs-connect/11-billing-and-allocation.md](../../afs-connect/11-billing-and-allocation.md) §11.3.

| # | Finding | Consequence |
|---|---|---|
| **B21** | `alloc_v2.ts`'s own header states the tier is caller-supplied and limits are **advisory**: *"a determined client can claim any tier … Treat allocation as a quota-management and UX mechanism, not a security boundary"* | Part 2 makes the billing signal correct. It does not make it unforgeable. [09](../../billing/09-entitlement-integration.md) §9.4 says so explicitly and gives the mitigation |
| **B22** | `StorageRetentionSweep`'s docblock explicitly waits for a billing hook that does not exist | Part 2 **is** that hook. No change to cloud-storage is required — its own hourly sweep picks up the new `membership` value |

## 2.7 The scheduler surface

`www` has **no** schedule registered: `bootstrap/app.php` wires
`commands: routes/console.php`, and `routes/console.php` contains only the stock `inspire`
command. `app/Console/Commands/` holds two unrelated webhook commands.

`members` is the precedent to copy — `members/routes/console.php` registers three
`Schedule::command(...)` calls with `AFS_MODIFIER_ADDITION — plans/…` header comments, and
notes: *"the server crontab must still run `php artisan schedule:run` every minute (a
deployment prerequisite, not application code — **not yet confirmed configured**)"*.

| # | Finding | Consequence |
|---|---|---|
| **B26** | `ProcessorPlanAdminController::createPayPalPlan()` (line ~283) **hardcodes** `payment_preferences`: `auto_bill_outstanding => true`, `setup_fee_failure_action => 'CONTINUE'`, `payment_failure_threshold => 3` | Every PayPal plan this application has created suspends after 3 consecutive failures — a dunning policy no operator chose and none can see. Stripe's and Square's equivalents are not surfaced anywhere either. [27](27-provider-retry-policy.md) makes the PayPal values configurable and describes all three |
| **B23** | No crontab entry is known to exist on any deployment | [21](21-cron-bootstrap.md) is the fix, and it retroactively enables `members`' three sweeps too — if and only if `members` gets its own crontab line. That is called out as a follow-up in [11](../../billing/11-rollout-and-testing.md) §11.5 |

## 2.9 Allocation — B42–B43

Both payment resources are already in `checkpoint_registry_v3.json` and in
`checkpoint/resources.ts`. What is missing is the wiring — see
[16](../../billing/16-allocation.md) and [../../allocation.md](../../allocation.md) for the pattern.

| # | Finding | Consequence |
|---|---|---|
| **B42** | **Processors have no allocation call sites at all.** `Processors/ProcessorsAdminView.ts:333` calls `saveProcessor(payload)` with no `canUse` preflight and no `consume` | `domain_processors_count` is a limit that has never applied. A **tier 0** member — for whom the resource is `enabled: false` — can create unlimited processors |
| **B43** | **Direct Payments never `release()`.** `canUse` (`directpayments.ts:145`) and `consume` (`:646`) are both wired correctly; there is no delete path calling `release` | Quota is a one-way ratchet. A member at 50 who deletes 10 still cannot create one, and their only recovery is a support ticket |

## 2.8 Summary of what must change before part 1 can work

1. A DP-owned `laravel_direct_payment_subscriptions` link table exists, carrying the
   Direct Payment, the member and the tier against a `provider_subscription_id` —
   **B1, B2**.
2. `laravel_processor_subscriptions` gains only provider-derived fields: `next_charge_at`,
   `next_charge_amount_cents`, `currency`, `cancel_at_period_end`,
   `current_period_start`, `last_synced_at`, `last_sync_error`, `provider_status` —
   **B3–B6**. Nothing about a DP or a member goes on this table.
3. The creation paths in `ProcessorSubscriptionController` populate those provider fields
   — **B3, B4** — and gain no knowledge of Direct Payments.
4. A mail-handler list endpoint exists — **B13**.
5. `BillingMailer` resolves the provider token and always passes a callback — **B9, B10**.
6. A crontab entry exists — **B23**.
