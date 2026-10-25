# 02 — Target Model

Names are proposals; each is an owner decision in [10](10-open-questions.md) (OQ1 for the class name, OQ3 for the tables). Everything lives in `w/www`.

## 2.1 The pieces

| Piece | Kind | Namespace (proposed) | One-line job |
|---|---|---|---|
| `Activity` | value object | `App\Services\Activity` | Something happened: module, event, scope, subject, value, data, dedupe key |
| `ActivityPublisher` | service | `App\Services\Activity` | The one call a module makes; hands the `Activity` to both systems, each in its own try/catch |
| `ActivityCatalog` | registry | `App\Services\Activity` | What events each module has (label, switches' defaults, variables, whether scheduled, default template) |
| `ActivityModule` | interface | `App\Services\Activity\Contracts` | A module's registration: its events, its recipient resolver, its variable builder, its optional scheduled source |
| `NotificationDispatcher` | service | `App\Services\Notifications` | **The general class.** Finds the rules for an `Activity`, and for each switched-on channel resolves recipients, writes the ledger, delivers |
| `InboxChannel` | service | `App\Services\Notifications\Channels` | Writes a row the admin bell shows |
| `MailChannel` | service | `App\Services\Notifications\Channels` | Sends one e-mail per recipient through `MailHandlerSender` (the only way mail leaves) |
| `NotificationRule` | model | `App\Models\Notifications` | A per-(module, scope, event) setting with its own inbox and e-mail switches — generalises `DirectPaymentNotification` |
| `NotificationSend` | model | `App\Models\Notifications` | The unique send ledger — generalises `DirectPaymentNotificationSend` |
| `Notification` | model | `App\Models` (unchanged) | The inbox row (`laravel_notifications`), extended ([03](03-admin-inbox.md)) |
| `AnalyticsRecorder` | service | `App\Services\Analytics` | Records an `Activity` as a server-side analytics event and counts it in the rollup |
| `AnalyticsRollup` | command | `App\Console\Commands` | Folds yesterday's events into `laravel_analytics_daily_rollups` ([05](05-analytics.md)) |

`NotificationDispatcher` keeps its name (the owner's request is to refactor *it*): with two channels it now does what the name says — it dispatches
notifications to the dashboard **and** to e-mail. The Direct Payments class of the same name is removed once its callers move ([04](04-dispatcher-extraction.md)).

## 2.2 `Activity` — the single input

```php
final class Activity
{
    public function __construct(
        public readonly string $module,            // 'direct_payments' — a key the module registered
        public readonly string $event,             // 'payment_succeeded' — a key from that module's catalogue
        public readonly ?string $scopeRef = null,  // the thing a rule hangs off: the Direct Payment, the form, the event; null = module-wide
        public readonly ?string $subjectRef = null,// the thing it happened to: the invoice, the submission, the registration
        public readonly ?int $valueCents = null,   // money, when there is some; never a float
        public readonly ?string $currency = null,  // ISO 4217, lower case, as the invoices store it
        public readonly array $data = [],          // what a template variable or an inbox message needs; references, not personal data where avoidable
        public readonly ?string $dedupeKey = null, // 'direct_payments.payment_succeeded.inv_ab12cd34ef'
        public readonly ?\DateTimeImmutable $occurredAt = null, // defaults to now
        public readonly ?string $actorRef = null,  // who did it, when a person did
    ) {}
}
```

Rules: immutable; no model instances inside (so it is safe to queue and to log); `data` is the module's own — the dispatcher passes it to the module's
`VariableBuilder` and the analytics recorder copies only the keys the event's catalogue entry lists as analytics-safe ([05](05-analytics.md) §5.4).

## 2.3 The event catalogue — modules own their vocabulary

Today the vocabulary is copied into four places ([01](01-audit-findings.md) M7). It becomes one registration per module:

```php
interface ActivityModule
{
    public function key(): string;                               // 'direct_payments'
    public function label(): string;                             // 'Direct Payments' — for the editor
    /** @return list<EventDefinition> */
    public function events(): array;
    public function recipients(): RecipientResolver;             // who an e-mail goes to when the rule says "customer"
    public function variables(): VariableBuilder;                // Activity -> the [{name, value}] list a mail handler template reads
    public function scheduled(): ?ScheduledSource;               // modules with "N days before" events return their due Activities
}

final class EventDefinition
{
    public function __construct(
        public readonly string $key,                 // 'payment_succeeded'
        public readonly string $label,               // 'Payment succeeded'
        public readonly bool $inboxDefault,          // is the inbox switch on when no rule exists
        public readonly bool $mailDefault,           // is the e-mail switch on when no rule exists  (answers OQ7)
        public readonly bool $alwaysOn = false,      // cannot be switched off (the four processor-change e-mails today)
        public readonly bool $scheduled = false,     // has days_before (upcoming_charge, trial_ending)
        public readonly ?string $defaultTemplate = null, // folder key of the built-in e-mail, or null
        public readonly array $variables = [],       // names the template may use
        public readonly array $analyticsKeys = [],   // keys of Activity::$data safe to store in analytics
        public readonly bool $recordAnalytics = true,    // false = the analytics recorder skips this event (06 §6.2)
        public readonly int $mailDelaySeconds = 0,       // the mail job is queued with this delay (payment_succeeded: 30)
        public readonly bool $attachedHandlers = false,  // compatibility: with no rule, send the item's attached mail handlers as the module does today
        public readonly array $appliesTo = [],           // module-defined tokens: which variants of the item the event applies to; empty = all (replaces SINGLE_PAY_NOTIFICATION_EVENTS)
        public readonly string $recipientMode = 'customer', // who an e-mail goes to by default: 'customer' or 'handler' (owner-facing events)
    ) {}
}
```

All modules are registered in **one** service provider, by name, in one visible list — no discovery, no reflection:

```php
// App\Providers\ActivityServiceProvider::register()
$this->app->singleton(ActivityCatalog::class, fn ($app) => new ActivityCatalog([
    $app->make(DirectPaymentsActivityModule::class),
    // later, one line per module: FormsActivityModule, EventsActivityModule, ...
]));
```

The dashboard editor reads the catalogue through one endpoint ([07](07-admin-ui.md) §7.2), so adding an event to a module is **one edit** in that module's
`events()`.

## 2.4 Rules — the per-event switches

`laravel_notification_rules` generalises `laravel_direct_payment_notifications`. One row is one event on one scope, with **one switch per channel**:

| Column | Type | Note |
|---|---|---|
| `id` | bigint | |
| `reference_num` | string(32), unique | as today |
| `module` | string(64) | `direct_payments` |
| `scope_ref` | string(128), nullable | the Direct Payment reference; `NULL` = module-wide |
| `event` | string(64) | a key from the module's catalogue |
| `inbox_active` | boolean, default false | **new** — the dashboard switch |
| `mail_active` | boolean, default true | today's `is_active` |
| `mail_handler_reference_num` | string(64), nullable | empty = the built-in default for the event |
| `recipient_mode` | string(32), default `customer` | `customer` \| `handler` \| `custom` (as today) |
| `recipient_emails` | json, nullable | when `custom` |
| `days_before` | json, nullable | scheduled events only; ≤3 days in 1..7, stored descending (D8) |
| `send_window_hour` | tinyint, nullable | as today |
| `inbox_title`, `inbox_body` | string / text, nullable | **new, optional** — overrides the module's default inbox wording |
| timestamps | | |

Keys: `unique (module, scope_ref, event)` — with `scope_ref` stored as `''` rather than `NULL` for module-wide rules, because a MySQL unique index treats
each `NULL` as distinct. Index `(mail_active, inbox_active, event)` for the idle guard ([04](04-dispatcher-extraction.md) §4.6).

**Resolution order for an `Activity`:** the rule for (module, scope, event); else the module-wide rule for (module, '', event); else the event's
catalogue defaults (`inboxDefault`, `mailDefault`). A rule can only switch things off or choose wording and handler; an `alwaysOn` event ignores `mail_active = false`.

## 2.5 The ledger

`laravel_notification_sends` generalises `laravel_direct_payment_notification_sends`:

| Column | Was | Note |
|---|---|---|
| `rule_reference_num` | `notification_reference_num` | |
| `module`, `scope_ref` | `direct_payment_reference_num` | |
| `subject_ref` | `provider_subscription_id` | the thing it happened to |
| `recipient_ref` | `payer_reference_num` | opaque to the dispatcher |
| `event`, `days_before` | same | |
| `bucket` | `period_end` | the period the send belongs to; `now()` truncated to the minute for one-off events |
| `channel` | — | `mail` \| `inbox` |
| `recipient` | `recipient_email` | an address, or `admin` for the inbox |
| `status`, `error`, `sent_at` | same | `pending` → `sent` \| `failed` \| `skipped` |

`rule_reference_num` is the empty string `''` (not `NULL`) when a send came from an event's defaults and no stored rule, so the unique key still deduplicates it.

Unique key (named, ≤64 characters): `notification_sends_uq` over `(rule_reference_num, subject_ref, bucket, days_before, channel, recipient)`. **The inbox
channel writes a ledger row too**, so a retried webhook cannot create two bell notifications — which fixes [01](01-audit-findings.md) N7 with the mechanism
that already works.

## 2.6 What "nothing configured" means

Each `EventDefinition` states its own defaults, so a new site works before anyone opens the editor:

| Module event (examples) | `inboxDefault` | `mailDefault` | Why |
|---|---|---|---|
| `direct_payments.payment_receipt` | off | **on** | A payer expects a receipt |
| `direct_payments.payment_failed` | **on** | **on** | Both the payer and the owner need to know |
| `direct_payments.subscription_canceled` | **on** | **on** | |
| `direct_payments.upcoming_charge` | off | off | Scheduled; needs `days_before`, so it stays opt-in |
| `forms.submission_received` (later) | **on** | off | Exactly what the form job does today |

This replaces "no row and no attached handler → silence" ([01](01-audit-findings.md) M3) with a stated default per event. Whether to adopt defaults at all
is [10](10-open-questions.md) OQ7; the schema works either way (set every default to off to keep today's behaviour).

## 2.7 The analytics record

`AnalyticsRecorder` writes the existing table, extended ([05](05-analytics.md) §5.3): `source` (`browser` \| `server`), `occurred_at`, `value_cents`,
`currency`, `subject_ref`, `dedupe_key` (unique). A server-recorded payment is `module = direct_payments`, `event = payment_succeeded`,
`resource_reference` = the Direct Payment, `subject_ref` = the invoice, `value_cents` = the amount.

## 2.8 Class diagram

```
   ActivityModule (interface) ◀──implements── DirectPaymentsActivityModule  (App\Externals\DirectPayments\Notifications — Direct Payments code)
        │ events() · recipients() · variables() · scheduled()
        ▼
   ActivityCatalog ──────────────┐
                                 ▼
   module ──publish(Activity)──▶ ActivityPublisher ──┬─▶ NotificationDispatcher ─┬─▶ InboxChannel ─▶ Notification (laravel_notifications)
                                                     │        │ rules + ledger   └─▶ MailChannel  ─▶ MailHandlerSender
                                                     │        ▼
                                                     │   NotificationRule · NotificationSend
                                                     └─▶ AnalyticsRecorder ─▶ AnalyticsEvent  ──(AnalyticsRollup, scheduled)──▶ AnalyticsDailyRollup
```

Nothing under `App\Services\Activity`, `App\Services\Notifications` or `App\Services\Analytics` names Direct Payments, a payer, a processor or a
subscription; that is a grep gate ([09](09-rollout-and-testing.md) §9.4).
