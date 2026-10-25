# 00 — Overview

> **Folder split 2026-09-22.** This document still narrates both halves of the original
> problem, because the reasoning behind D1–D18 only makes sense together — Part 1 and Part 2
> were designed as one system sharing one crontab. But Part 1's *documents* (steps 02–07,
> 12–15) now live in [`../www/direct_payments/`](../www/direct_payments/README.md) as generic
> tenant-facing infrastructure; only Part 2 — everything that touches
> `brain_memberships.membership` — is still built out of this folder. See
> [`README.md`](README.md) for exactly which document moved where.

## 1. The problem

### 1.1 Part 1 — a subscription that nobody manages

`www/resources/js/external/direct_payments/api.ts` and
`www/resources/js/external/processors/` are a complete, working checkout SDK. A member
lands on a page, `initializeDirectPayment()` resolves the Direct Payment and its
processor, and `charge()` / `setupSinglePayPayment()` + `confirmPayment()` takes the money.
For a `SUBSCRIPTION` Direct Payment, `ProcessorSubscriptionController::createStripeSubscription()`
(and its Square and PayPal siblings) creates the subscription at the provider and writes
one row into `laravel_processor_subscriptions`.

Then it stops.

- Nothing tells the member "you will be charged $49 on 12 September".
- Nothing reacts when the renewal fails.
- `laravel_processor_subscriptions.current_period_end` is written **only** by
  `ProcessorWebhookController::logStripeSubscription()`. The creation path never sets it.
  If the webhook is not configured, or was configured after the subscription was made, the
  column is `null` forever.
- There is no way for an operator to say "email these people, from this address, three
  days before the charge" without writing code.

The whole processor/Direct Payments layer is client-side with server-side data parsing.
Subscription *management* is inherently not client-side — it has to happen when nobody's
browser is open. That is what part 1 builds.

### 1.2 Part 2 — billing that changes nothing

A member signs up at `www.appfactory.studio`, manages their subscription in
`members.appfactory.studio` → `BillingApp.ts`, and their tier is
`brain_memberships.membership` (0–4). Every entitlement in the ecosystem derives from that
integer:

- `alloc_v2.ts` takes `tier` as a caller-supplied index into
  `auth/public/checkpoint/checkpoint_registry_v3.json`'s positional `tiers[]` arrays.
- cloud-storage's `QuotaService::planFor()` reads `brain_memberships.membership` directly
  and clamps it to 0–4; `RetentionService::reconcile()` retires buckets above it.
- afs-connect's five entitlement layers resolve layer 2 ("does their plan include it?")
  from the registry at that same index — see
  [../afs-connect/11-billing-and-allocation.md](../afs-connect/11-billing-and-allocation.md) §11.3.

So the ecosystem is *already wired* to one integer. The defect is that **nothing in billing
ever writes it back down.** A member can cancel, or let their card fail every month for a
year, and keep an Enterprise tier's limits. `StorageRetentionSweep`'s own docblock says so
out loud:

> *"A billing hook on membership change would be the primary, immediate trigger (§6.5's
> table), but no such hook exists in this app to attach to (billing/membership changes
> happen entirely outside members)."*

Part 2 is that hook, implemented as a scheduled task on the platform (`members`, D18) rather than as an event
listener, for the same reason cloud-storage made its sweep authoritative: a missed event
must not mean a member silently keeps what they stopped paying for.

## 2. Goals

1. An operator can configure, per Direct Payment, **which events send a notification**,
   **which `laravel_mail_handlers` row** it is sent from, and — for the upcoming-charge
   event — **which days before** (1–7) and **how many sends** (up to 3).
2. A Laravel scheduled task runs **every 15 minutes** and takes **no action whatsoever**
   unless the database says to. Zero configuration means zero queries beyond one indexed
   existence check.
3. The crontab entry that makes `schedule:run` possible is installed **automatically**,
   **idempotently**, when a Direct Payments configuration is saved — via a new
   `--update-cron-task` block in `www/core/scripts/commands.sh`.
4. The subscription's next charge date, amount and status live **in the database**,
   normalised across Stripe, Square and PayPal, refreshed by the same task, so no consumer
   has to know which provider backs a given subscription.
5. A second, **Brain-only** scheduled task downgrades a member's tier for non-payment,
   after a grace period, with notifications, and restores it on payment.
6. That downgrade is visible to afs-connect, cloud-storage and alloc_v2 **without any
   change to those subsystems** — because it writes the column they already read.
7. `members`' billing dashboard shows the real next-charge date and the real dunning state.
8. A member can **cancel**, **change tier** and **change payment method** through the
   Direct Payments API — cards only — and each action can send an operator-configured
   email ([28](../www/direct_payments/28-subscription-actions.md)). Today cancel is a `localStorage` write that
   never reaches the provider (**B27**).
9. Every money movement — charges and refunds, across single payments, subscriptions and
   Connect splits — is visible in **Financials → Money** with its provider and customer,
   refundable from there, and projected into afs-connect as **period rollups only**
   ([29](../www/direct_payments/29-money-and-refunds.md)).

## 3. Non-goals

- **Not** replacing webhooks. Webhooks stay the fast path; the scheduler is the safety net
  and the notifier. Same push/pull split as afs-connect's ingest.
- **Not** a new payment flow. Charging, saved cards, Connect splits and invoices are done
  and are not touched beyond additive columns.
- **Not** proration, plan-change arithmetic, invoicing, tax or dunning *retries*. Retry
  policy stays with the provider — Stripe Smart Retries, PayPal's
  `payment_failure_threshold`, Square's fixed day-3/6/9 schedule. We observe the outcome; we
  do not schedule retries. [27](../www/direct_payments/27-provider-retry-policy.md) describes what each provider
  does and exposes the one knob PayPal allows; [08](08-brain-enforcement.md) §8.4 covers why
  the grace period has to be read against those windows.
- **Not** making `alloc_v2` unforgeable. Step 09 explains why that is a separate effort
  ([../alloc-v2-parse-direct/](../alloc-v2-parse-direct/)) and what this plan does provide
  in the meantime.
- **Not** a queue worker. Everything runs inside the scheduled command. If volume ever
  justifies queues, the send call is already behind one service seam.

## 4. Glossary

| Term | Meaning |
|---|---|
| **Brain** | `www.appfactory.studio` (prod) / `www15.appfactory.studio` (dev) — the `www` codebase acting as control plane. Identified by `Subdomain ∈ {www, www15}` **and** `Domain === 'appfactory.studio'` |
| **Tenant** | A member website: the same `www` codebase cloned onto its own server and database. Carries the same tables, empty `brain_*` |
| **DP** | Direct Payment — a `laravel_direct_payments` row, `pay_type ∈ {SINGLE_PAY, SUBSCRIPTION, CONNECT}` |
| **Processor** | A `laravel_processors` row: provider (`stripe`/`square`/`paypal`), mode, keys |
| **Mail handler** | A `laravel_mail_handlers` row; its `json` column (htmlentities-encoded) holds `{ handlers: { ref: {…} } }` and is sent via `FunctionsController::SendEmailBuildIntegration()` with a caller-resolved `$integration` — see [20](../www/direct_payments/20-current-state-audit.md) §2.3 |
| **Tier / membership** | `brain_memberships.membership`, integer 0–4. **Positional**, never resolved by name |
| **Notification** | An email, sent from a configured mail handler, for one configured event |
| **Send ledger** | `laravel_direct_payment_notification_sends` — the uniqueness constraint that makes "3 sends" mean 3 |
| **Dunning** | The past-due → grace → downgrade ladder in [08](08-brain-enforcement.md) |
| **Action event** | A notification triggered by something happening — a cancel, a plan change, a payment method change — rather than by a date approaching ([28](../www/direct_payments/28-subscription-actions.md) §14.7) |
| **`portal.d.ts`** | The externals API's ambient type declaration. **Copied** into each consuming app (`<app>/resources/js/checkpointHelper/static/`), never imported from source. Hand-maintained — an undeclared method is invisible to consumers ([25](../www/direct_payments/25-direct-payments-api.md) §7.5) |
| **Retry window** | How long the *provider* keeps re-attempting a failed charge before giving up — 2 weeks default on Stripe, threshold-driven on PayPal, a fixed 9 days on Square ([27](../www/direct_payments/27-provider-retry-policy.md) §13.2) |

## 5. The eighteen decisions

| # | Decision | Why | Detail |
|---|---|---|---|
| **D1** | **Two commands. `direct_payments:subscription-notify` in `www` (Brain and every tenant); `billing:enforce-membership` in `members` (platform only)** | Only the tenant-facing task must ship to tenant clones; the appfactory-specific task belongs where it runs once. The `laravel_*`/`brain_*` tables are in one shared database, so `members` can read them | [01](01-architecture.md) §1.2, [08](08-brain-enforcement.md) §8.0 |
| **D2** | **The scheduler is database-driven, not code-driven** | The task ships to every tenant clone. On a tenant with no notification config it must cost one indexed `EXISTS` query and exit | [24](../www/direct_payments/24-notification-scheduler.md) §6.2 |
| **D3** | **Crontab bootstrap is idempotent and grep-guarded** | `saveDirectPayment` fires on every save. Re-adding the line 50 times would run `schedule:run` 50× a minute | [21](../www/direct_payments/21-cron-bootstrap.md) §3.3 |
| **D4** | **The DP↔subscription link is a new `laravel_direct_payment_subscriptions` table, NOT a column on `laravel_processor_subscriptions`** | Direct Payments encapsulates the processor; the processor must know only about itself. A `direct_payment_reference_num` on a processor table would point *upward* and make every future consumer of the processor layer inherit a Direct Payments dependency | [22](../www/direct_payments/22-subscription-state.md) §4.2 |
| **D4b** | **`laravel_processor_subscriptions` gains only provider-derived fields — `next_charge_at` chief among them** | Without it every consumer re-implements three providers' date semantics. Nothing added there names a DP, a member, a tier or a website | [22](../www/direct_payments/22-subscription-state.md) §4.2.1 |
| **D5** | **One normalised `next_charge_at`, plus provider-native mirrors** | Stripe `current_period_end`, PayPal `billing_info.next_billing_time`, Square `charged_through_date` are three different things. Normalise once, at ingest | [22](../www/direct_payments/22-subscription-state.md) §4.3 |
| **D6** | **Notification config is a child table of the DP, mirroring `laravel_direct_payment_connect`** | The precedent exists and works: `pay_type`-specific config lives in its own table with a `direct_payment_reference_num` key, written by `saveDirectPayment` | [23](../www/direct_payments/23-notification-config.md) §5.2 |
| **D7** | **Every send is written to a ledger with a unique index on `(config_id, provider_subscription_id, period_end, days_before, recipient_email)`** | A 15-minute tick over a 24-hour day-bucket is 96 opportunities to send the same email. The database, not the code, is what makes it one | [24](../www/direct_payments/24-notification-scheduler.md) §6.5 |
| **D8** | **`days_before` is an array of at most 3 distinct integers in 1..7** | Exactly the user requirement: up to 7 days ahead, up to 3 sends. Stored as JSON, validated server-side, sorted descending | [23](../www/direct_payments/23-notification-config.md) §5.3 |
| **D9** | **"Is this the Brain?" is the `IS_BRAIN` env flag — plus, in production, a hard check that `APP_URL`'s host is `www.appfactory.studio` — read as `config('brain.is_brain')`; it fails closed** | A tenant clone has `Subdomain = 'www'` too, and environments differ in URL (`.test`, `appfactory.test1`, staging), so a domain test gives the wrong answer in a lab. Tenants run on separate servers with their own databases, so this is hygiene, not a security boundary | [www_brain](../www_brain/00-overview.md) B10 |
| **D10** | **Non-payment gets a 14-day grace ladder, not an instant downgrade** | Same argument as cloud-storage §6.1: an expired card must not instantly vaporise a member's capabilities. The grace also *serves* the requirement — the downgrade does happen | [08](08-brain-enforcement.md) §8.4 |
| **D11** | **The downgrade's only ecosystem write is `brain_memberships.membership`** | afs-connect, cloud-storage and alloc_v2 already read it. Writing anything else would create a second source of truth that can disagree | [09](09-entitlement-integration.md) §9.2 |
| **D12** | **Add a Brain entitlement endpoint + audit log, and say plainly what it does not fix** | `alloc_v2` takes the tier from the caller. Making the billing signal correct does not make it unforgeable. Name the gap, give apps a server-derived answer, point at the effort that closes it | [09](09-entitlement-integration.md) §9.4 |
| **D13** | **Per-DP advanced policy lives in a Direct Payments table; "Brain-only" is one middleware** | The grace period is a Direct Payments concern that the Brain happens to be the only current editor of. Putting a `brain_` prefix or a member column on it would make a temporary authorization rule into permanent schema coupling | [26](../www/direct_payments/26-advanced-policy.md) §12.2 |
| **D14** | **Each provider gets a config object shaped like its own API — including an empty one** | Stripe's retry policy is unreachable by API, PayPal's is per-plan and writable, Square's does not exist. Forcing one shape would mean either pretending we can write Stripe's or hiding that PayPal's is real. `StripeRetryConfig` is a declared mirror, `PayPalRetryConfig` writes through, `SquareRetryConfig` has no fields | [27](../www/direct_payments/27-provider-retry-policy.md) §13.4 |
| **D15** | **An action event fires from the first point the provider confirmed the outcome — and from exactly one such point** | A 200 means the request was accepted, not that the state changed as asked. Emailing "cancelled" off a 200 that later did not hold is the same class of lie as **B27**. So cancel and update fire from webhooks; payment-method change fires from its controller, because no reliable webhook exists on all three providers | [28](../www/direct_payments/28-subscription-actions.md) §14.7 |
| **D16** | **Cancel stops billing immediately at every provider; when *access* ends is a Direct Payments policy** | The provider controls billing, not features — this application controls those. Expressing "keep access to period end" as `cancel_at_period_end` asked three billing systems for an opinion on a question that is not theirs, and PayPal has no answer at all. Split them and the asymmetry disappears, along with any need for a scheduled provider call months later | [28](../www/direct_payments/28-subscription-actions.md) §14.3 |
| **D17** | **afs-connect receives money as period rollups, never transactions — and off by default** | The index is metadata only, and `www_direct_payments` already declares "no customer emails, charge IDs". Money is exactly those. One row per site per month per provider is a project-planning fact; 61 transactions is a PII export into a search index | [29](../www/direct_payments/29-money-and-refunds.md) §15.6 |
| **D18** | **Enforcement is scheduled in `members` and runs on `www`** | `www/routes/console.php` runs on every tenant, so a Brain-only task there pays a per-tenant no-op and needs a guard. `members` runs once and hosts platform sweeps, but is not the sole writer of `brain_*` and has no mail sender — so it schedules the command and triggers it on `www` over a signed call, and `www` runs the ladder, the tier write, the audit rows and the notices in one transaction per member. Porting the ladder into `members` would have duplicated about 1,200 lines for no behavioural gain | [08](08-brain-enforcement.md) §8.0–8.2, §8.13 |

## 6. What "no action unless configured" means concretely

On a freshly cloned tenant with no billing set up, one `direct_payments:subscription-notify` tick is:

```sql
SELECT EXISTS(
  SELECT 1 FROM laravel_direct_payment_notifications WHERE is_active = 1
);
-- false → return Command::SUCCESS
```

and `billing:enforce-membership` — which runs in `members`, never on a tenant — is a no-op unless `BILLING_ENFORCEMENT_ENABLED` and `brain_billing_settings.enabled` are both on ([08](08-brain-enforcement.md) §8.2).

Nothing else. That is the acceptance bar for D2 and D18.
