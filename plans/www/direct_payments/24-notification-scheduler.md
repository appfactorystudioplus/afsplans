# 06 — The Notification Scheduler

`php artisan direct_payments:subscription-notify` — every 15 minutes, on the Brain and on every
tenant, doing nothing unless the database says otherwise.

## 6.1 The command

`www/app/Console/Commands/BillingSubscriptionNotify.php`

```php
protected $signature = 'direct_payments:subscription-notify
                        {--dry-run   : Evaluate and report, send nothing, write no ledger rows}
                        {--dp=       : Restrict to one Direct Payment reference_num}
                        {--force-sync : Ignore the freshness floor and re-read every candidate}';

protected $description = 'Refresh subscription state from the payment provider and send configured billing notifications (plans/www/direct_payments/24-notification-scheduler).';
```

`--dry-run` is not a nicety. This command sends email to paying customers on a 15-minute
loop; every change to it gets exercised against production data with `--dry-run` before it
is scheduled. Its output is the evaluation table from §6.4 with a `WOULD SEND` column.

Registered in `routes/console.php` at `everyFifteenMinutes()` — see
[21](21-cron-bootstrap.md) §3.4.

## 6.2 Phase 0 — the guard (decision **D2**)

```php
/**
 * AFS_BILLING_MODIFIER_ADDITION — plans/www/direct_payments/24-notification-scheduler.md §6.2.
 *
 * This command ships to every tenant clone of the Brain, most of which will
 * never configure billing. On those, a tick must cost ONE indexed existence
 * check and nothing else — no provider calls, no member scan, no log line.
 * That is the acceptance bar in plans/billing/00-overview.md §6, and every future addition
 * to this command must stay behind this guard.
 */
if (!DirectPaymentNotification::where('is_active', true)->exists()) {
    return self::SUCCESS;
}
```

Backed by the `dp_notifications_active_event_idx` index from
[23](23-notification-config.md) §5.2. No output on the silent path — a command that logs
"nothing to do" 96 times a day on 500 tenants is its own denial of service.

## 6.3 Phase 1 — sync

Delegates entirely to `SubscriptionSyncService` ([22](22-subscription-state.md) §4.4),
with `$horizon` computed from the active configs:

```php
$maxDaysBefore = DirectPaymentNotification::where('is_active', true)
    ->whereIn('event', ['upcoming_charge', 'trial_ending'])
    ->get()
    ->flatMap(fn ($c) => $c->days_before ?? [])
    ->max() ?? 0;
```

A deployment configured only for `payment_failed` has `$maxDaysBefore = 0`, so the horizon
collapses and only the 24-hour freshness floor selects candidates. Correct: nothing needs
a fresh `next_charge_at` if nothing reads it.

Failures here are per-subscription and swallowed into `last_sync_error` — phase 2 runs
regardless, on whatever data is already in the table. A provider outage must not silence a
notification that was already due on fresh data.

## 6.4 Phase 2 — evaluate

The query starts from the **Direct Payments** side and joins *down* into the processor
layer — never the reverse ([22](22-subscription-state.md) §4.2):

```php
DirectPaymentSubscription::query()
    ->where('laravel_direct_payment_subscriptions.direct_payment_reference_num',
            $config->direct_payment_reference_num)
    ->join('laravel_processor_subscriptions as ps',
           'ps.provider_subscription_id', '=',
           'laravel_direct_payment_subscriptions.provider_subscription_id')
    ->whereIn('ps.status', ['active', 'trialing', 'past_due'])
    ->whereNotNull('ps.next_charge_at')
    ->where('ps.cancel_at_period_end', false)
    ->whereBetween('ps.next_charge_at', [now(), now()->addDays($maxDayForThisConfig)->endOfDay()])
    ->select('laravel_direct_payment_subscriptions.*',
             'ps.next_charge_at', 'ps.next_charge_amount_cents', 'ps.currency', 'ps.status')
    ->cursor();
```

A DP link row whose processor row is missing (an orphan, or a subscription deleted out from
under us) drops out of the `join` and is simply not notified. That is the correct failure:
without a processor row there is no `next_charge_at`, so there is nothing truthful to say.
`direct_payments:backfill-subscriptions` reports those separately ([22](22-subscription-state.md) §4.7).

`cancel_at_period_end = true` is excluded deliberately: that period end is a termination,
not a charge, and telling someone who cancelled that they are about to be billed is the
single worst email this system could send.

It is **not** redundant against the `status` filter. A cancel made through this
application leaves the provider subscription `canceled`, which the status filter already
drops. This clause catches the other case: an operator who cancelled at period end
directly in the provider's dashboard, where the subscription is still `active`
([28](28-subscription-actions.md) §14.3).

Then the **day bucket**:

```php
$daysOut = (int) floor(now()->diffInDays($sub->next_charge_at, false));
if (!in_array($daysOut, $config->days_before, true)) continue;
```

`floor` of a signed day difference, evaluated in the deployment's configured timezone.
Worked example, `days_before = [7,3,1]`, charge at 2026-09-12 14:00:

| Now | `$daysOut` | Match? |
|---|---|---|
| 09-05 09:00 | 7 | ✅ send the 7-day notice |
| 09-05 15:00 | 6 | ✖ |
| 09-09 09:00 | 3 | ✅ |
| 09-11 09:00 | 1 | ✅ |
| 09-12 09:00 | 0 | ✖ — 0 is not configurable (§5.3) |

Note that `$daysOut == 7` is true for a ~24-hour span, which is 96 ticks. Everything that
stops those 96 ticks from becoming 96 emails is §6.5, and nothing else. If
`send_window_hour` is set, ticks outside that hour are skipped — a preference, never the
correctness mechanism.

## 6.5 Phase 2b — the send ledger (decision **D7**)

`laravel_direct_payment_notification_sends`:

> **Superseded 2026-09-23** — `member_reference_num` below was dropped and replaced by a
> generic `payer_reference_num`, pointing at `laravel_direct_payment_payers.reference_num`
> instead of `brain_memberships.reference_num`, for the same reason given in
> [22](22-subscription-state.md)'s superseded note: this table is DP-owned and
> tenant-generic, and naming a Brain-only concept in it was the layering violation. See
> `plans/brain_membership_identity/02-database-and-server.md` §2.1 (BM1).

| Column | Type | Notes |
|---|---|---|
| `id` | `id` | |
| `notification_reference_num` | `string(32)` index | → the config |
| `direct_payment_reference_num` | `string(128)` index | Denormalised for reporting |
| `provider_subscription_id` | `string(255)` index | The provider's id, not our ulid — survives a row rebuild |
| `member_reference_num` | `string(64)` null index | Null on tenants |
| `event` | `string(64)` | |
| `days_before` | `tinyint` | **`0` for action events, never null** — see the MySQL note below |
| `period_end` | `timestamp` null | **The `next_charge_at` this send was about** |
| `recipient_email` | `string(255)` | Part of the unique key — one row per recipient (§6.6.3) |
| `status` | `string(20)` | `pending` \| `sent` \| `failed` \| `skipped` |
| `error` | `string(500)` null | |
| `sent_at` | `timestamp` null | |
| `timestamps` | | |

```php
$table->unique(
    ['notification_reference_num', 'provider_subscription_id', 'period_end',
     'days_before', 'recipient_email'],
    'dp_notification_sends_uq'
);
```

`recipient_email` is in the key because `BillingMailer` sends **one recipient per call**
(§6.6.3), so a `custom`-mode config with three addresses legitimately produces three rows
for the same (config, subscription, period, offset). Without it, the second and third
recipients would collide with the first and be silently treated as already-sent.

**That index is the whole mechanism.** The flow is:

1. `INSERT` the ledger row with `status = 'pending'`.
2. Catch `QueryException` on duplicate key → **already handled; continue silently.** This
   is the expected path 95 times out of 96 and must not log at warning level.
3. Send.
4. `UPDATE` the row to `sent` (+ `sent_at`) or `failed` (+ `error`).

`pending` exists so an in-flight row is distinguishable from a genuinely skipped one.
Overloading `skipped` for both would make a row abandoned by a crashed tick
indistinguishable, in the admin panel and in every query, from a legitimate
`no_recipient` skip. A `pending` row older than one tick is a crash to investigate.

Insert-then-send, not send-then-insert. A crash between the two loses **one** email; the
reverse loses the dedupe and sends until the process is killed. For a billing notice, a
missed send is recoverable by the member (the charge notice is a courtesy; the charge
still happens and the receipt still sends) and a send storm is not.

`period_end` is what makes the next billing cycle a *new* opportunity: when `next_charge_at`
rolls from 2026-09-12 to 2026-10-12, the key changes and all three notices fire again. It
is also why `next_charge_at` must be *stable within a period* — a provider that jitters it
by seconds would break dedupe, so **`period_end` is stored truncated to the minute.**

Action events (`payment_failed` etc.) insert with `days_before = 0` and `period_end` = the
event's own occurrence timestamp truncated to the minute, which deduplicates a webhook
delivered twice.

> **MySQL treats `NULL`s in a unique index as distinct**, so two rows that differ only by a
> `NULL` column both insert. Every column in `dp_notification_sends_uq` must therefore be
> NOT NULL for the rows it governs: `days_before` is `0` for action events rather than
> `null`, and `recipient_email` is never null on a row that reached the send path. This is
> a real footgun — the obvious implementation (`nullable()` everywhere) silently disables
> the dedupe that the whole design rests on.

## 6.6 Phase 3 — the send

```php
BillingMailer::sendOne(
    string $mailHandlerRef,   // laravel_mail_handlers.reference_num
    string $recipient,
    array  $vars,             // the flat [{name, value}, …] list — §6.6.4
    bool   $overrideEmails = true,   // false = recipient_mode 'handler'
): SendResult
```

It takes a **mail-handler reference, not a config object**, so the dunning ladder can call
it with `DunningPolicy::mailHandlerRef` ([08](../../billing/08-brain-enforcement.md) §8.8) without
inventing a `DirectPaymentNotification` it does not have. One mailer, two callers, one
send path.

**One recipient per call.** That is not a style choice — see **B25** and §6.6.2.

The sequence is the one in [20](20-current-state-audit.md) §2.3: the caller resolves
`$integration`, `$myconfig` and `$managed_domain` — `SendEmailBuildIntegration()` takes all
three as parameters — and hands them over with a callback. `SendEmailHandler()` is **not**
used; it re-resolves `$integration` internally and skips the token injection (**B9**).

### 6.6.1 Resolve the integration once per run, not per send

```php
/**
 * AFS_BILLING_MODIFIER_ADDITION — plans/www/direct_payments/24-notification-scheduler.md §6.6.1.
 *
 * The five-step preamble every correct send in this codebase performs —
 * see Portal/Admin/MembershipController.php:127 for the production original.
 *
 * Hoisted to once per command run: __get_main_configurations() reads the whole
 * `configurations` table and getBrainConfig() is an HTTP call to the Brain. Doing
 * both per recipient would put an outbound request in front of every email.
 */
$configDomain   = FunctionsController::__get_main_configurations();
$managed_domain = $configDomain['managed_domain'];
$myconfig       = $configDomain['configurations'];

$integration = MailProviderModel::where('status', 1)->first();
if (!$integration) { throw new MailIntegrationMissingException('no active mail provider'); }

// B9 — the step SendEmailHandler() omits and every working caller performs.
// B24 — getBrainConfig() returns NULL on a non-ok response, and every reference
// implementation dereferences it unguarded. On the web that is one failed request;
// in a cron it is a fatal TypeError that kills the tick and repeats next tick.
$brainConfig = FunctionsController::getBrainConfig();
$token = $brainConfig['controller']['email_providers'][0]['token'] ?? null;
if ($token !== null && $token !== '') {
    $integration['key'] = $token;
} elseif (empty($integration['key'])) {
    // No token from either source — fail LOUDLY here rather than letting
    // SendEmailBuilder post to SendGrid with an empty bearer and report success.
    throw new MailIntegrationMissingException('no provider token from brain config or local row');
}
```

That exception is caught by the caller and becomes a `failed` ledger row per pending send,
with `error = 'no_provider_token'` — so a misconfigured deployment shows up in the admin
panel (§6.8) instead of silently sending nothing.

### 6.6.2 The per-recipient send

```php
$json     = FunctionsController::Utils_decodeStringToJson($mailHandler->json); // htmlentities-encoded
$handlers = $json['handlers'] ?? [];
if (!$handlers) { throw new MailHandlerMissingException(...); }

// §5.4 recipient_mode. 'handler' leaves the handler's own emails untouched.
foreach ($handlers as $k => $v) {
    if ($config->recipient_mode !== 'handler') { $v['emails'] = [$recipient]; }
    $handlers[$k] = $v;
}
$json['handlers'] = $handlers;

// B25 — variables.client is read at [$i] where $i is SendEmailBuilder's RECIPIENT
// index. One recipient ⇒ exactly one outer entry. This is why the array is doubly
// nested, and why this method takes a single recipient.
$clientVariables = [ $vars ];   // [[ ['name'=>…, 'value'=>…], … ]]

$result = null;
// B10 — SendEmailBuilder invokes $cb unconditionally on its `active=false` and
// "No receipents" early returns. A null callback is a fatal Error in a cron run.
FunctionsController::SendEmailBuildIntegration(
    $json, $clientVariables, $integration, $myconfig, $managed_domain,
    function ($resp) use (&$result) { $result = $resp; }
);
```

`$result['error']` (set by `SendEmailBuilder` on `active=false` and "No receipents") is
inspected and turned into a `failed`/`skipped` ledger row rather than a silent success.

### 6.6.3 Why one recipient per call, spelled out

`SendEmailBuilder` treats the three variable buckets **differently**, and only one of them
is per-recipient:

| Bucket | Shape expected | How it is read |
|---|---|---|
| `schema` | flat list | Force-wrapped to `[$schema]`, always read at `[0]` |
| `system` | — | **Overwritten wholesale** from `LaravelSetting` (line 1699). Anything the caller supplies is discarded |
| `client` | **list per recipient** | `$form_data["variables"]["client"][$i][$n]`, `$i` = recipient index |

So a two-recipient send with one client-variable set reaches
`count($form_data["variables"]["client"][1])`, which is `count(null)` — a PHP 8
**`TypeError`**. The `catch (\Exception $e)` wrapped around that loop catches `Exception`,
and `TypeError` is an `Error`. It is not caught. The cron tick dies.

Sending one recipient per call makes the invariant structural instead of remembered.
`NotificationDispatcher` loops recipients and calls `sendOne()` for each; each gets its own
ledger row, so one bad address fails alone.

**Do not "optimise" this back into a multi-recipient call** without first fixing
`SendEmailBuilder`'s bucket handling — and that is a shared function with other callers, so
it is not this plan's to change.

### 6.6.4 Template variables

`$vars` is the flat inner list — `[ ['name'=>…, 'value'=>…], … ]` — that §6.6.2 wraps once.
Available in the handler body, subject and title as `${name}`:

| Variable | Source |
|---|---|
| `firstname`, `lastname`, `email` | `brain_memberships`, else `laravel_processor_customers` |
| `amount` | `next_charge_amount_cents` formatted with `currency` |
| `currency` | |
| `next_charge_date` | `next_charge_at`, formatted long |
| `days_until_charge` | The bucket that matched |
| `plan_name` | `laravel_processor_plans.name`, else the DP name |
| `direct_payment_name` | `laravel_direct_payments.name` |
| `card_brand`, `card_last4` | The default payment method, when resolvable |
| `manage_billing_url` | `https://members.appfactory.studio/billing` on the Brain; the tenant's own `Address` elsewhere |
| `subscription_status` | Normalised `status` |
| `site_name`, `site_url` | `$managed_domain['Domain']` / `['Address']` |

Every one is a string, and every one is emitted even when empty — a missing key leaves a
literal `${amount}` in the sent email, which is worse than a blank.

### 6.6.5 Throughput cap (**B12**)

`SendEmailBuilder` shells out to `commands.sh --transform-email-mjml` and then
`sleep(1)` **per recipient**. 200 sends is 200+ seconds, which overruns the 15-minute
`withoutOverlapping(14)` lease at ~800.

```php
config('billing.sends_per_run', 100)
```

Sends beyond the cap are simply not attempted this tick — their ledger rows are not
inserted, so the next tick picks them up. With 96 ticks a day that is 9 600 emails/day of
headroom at the default. A deployment that needs more should move `BillingMailer` behind a
queue; the seam is already one class.

## 6.7 The action-event path

`ProcessorWebhookController`'s subscription/invoice handlers gain one call each:

```php
/**
 * AFS_BILLING_MODIFIER_ADDITION — plans/www/direct_payments/24-notification-scheduler.md §6.7.
 *
 * The push half of the notification system. Same dispatcher, same ledger and
 * same recipient rules as the scheduled path — a webhook must never become a
 * second, subtly different way to send the same email.
 *
 * Dispatch is best-effort and fully swallowed: a notification failure must
 * never make this handler return non-2xx, because the provider would then
 * retry the webhook and the payment record would be reprocessed.
 */
app(NotificationDispatcher::class)->dispatchEvent('payment_failed', $subscription, $payload);
```

The webhook-sourced events are `payment_succeeded`, `payment_failed`,
`subscription_canceled`, `subscription_cancel_scheduled` and `subscription_updated` — the
last three added by [28](28-subscription-actions.md) §14.7, which also explains why
`payment_method_changed` dispatches from its controller instead.

`NotificationDispatcher` looks up active configs for
`(direct_payment_reference_num, event)`, resolves recipients, writes the ledger, calls
`BillingMailer`. Identical code path to phase 3; only the trigger differs.

## 6.8 Observability

- `storage/logs/laravel.log` at **info** for each send, **warning** for each `failed`,
  **nothing** for a dedupe hit or the phase-0 exit.
- The ledger *is* the report. `GET /portal/api/v1/directpayments/{ref}/notifications/sends`
  (admin, paginated) backs a "Recent notifications" panel showing the last 50 with status
  and error — an operator asking "did the reminder go out?" should not need database
  access.
- `--dry-run` prints the phase-2 evaluation table.

## 6.9 Acceptance

- On a tenant with no configs: one query per tick, no log output, no provider calls.
- A subscription 7 days out with `days_before = [7,3,1]` receives **exactly one** email
  across the 96 ticks of that day, and exactly three across the period.
- Deleting the ledger row and re-running sends again (proving the ledger, not luck, is the
  mechanism).
- Rolling `next_charge_at` forward one month re-arms all three.
- A subscription with `cancel_at_period_end = true` receives nothing.
- Killing the mail provider mid-run leaves `failed` ledger rows with the error text, and
  the next tick does **not** retry them (a `failed` row still occupies the unique key —
  retry policy is deliberately manual; see §6.10).
- **A config in `custom` mode with three `recipient_emails` produces three ledger rows and
  three `sendOne()` calls** — never one call with three addresses (**B25**, §6.6.3).
- Pointing `BRAIN_API_URL` at a dead host makes `getBrainConfig()` return null; the tick
  must record `no_provider_token` ledger rows and **exit 0**, not fatal (**B24**).
- A deployment whose active `laravel_mail_providers` row already carries a key still sends
  when the Brain is unreachable.

## 6.10 Deliberately not built

- **Automatic retry of a failed send.** A `failed` row holds the unique key, so the send
  is not retried. Retrying an email whose failure was "invalid recipient" 96 times a day
  is worse than not sending it. An operator re-sends by deleting the ledger row, and the
  admin panel in §6.8 is where that button would go if it is ever wanted.
- **Per-member notification opt-out.** Belongs in a member preferences surface that does
  not exist yet. When it does, it is a check in `NotificationDispatcher::resolveRecipients()`
  and nowhere else.
- **Queues.** See §6.6.
