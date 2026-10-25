# 31 — Code layout consolidation

**A refactor plan, not a feature.** Direct Payments code in `www` lives in several directories with
no stated rule for what goes where. This document fixes the rule, maps every file to its home, and
sequences the move so behaviour never changes.

> **Status (2026-09-25): executed in `www`** (uncommitted). What was built, the decisions taken and
> what was and was not verified are in [`complete/31-code-layout-consolidation.complete.md`](complete/31-code-layout-consolidation.complete.md).
> The tables below describe the layout as it was planned and now is; "Today" columns show the
> locations before the move.

Facts below were read from the code on 2026-09-25.

## 31.1 What exists today

| Location | Files | What it really is |
|----------|-------|-------------------|
| `app/Externals/DirectPayments/` | 9 | **Domain layer, no HTTP** — `DirectPaymentGateway` + `Contracts/PaymentGateway` (the door ecommerce/events/appointments use), `PayerService`, `SwapService`, `BindingRegistry`, `TierBindingRegistry`, `InvoiceSnapshot`, `DirectPaymentException`, `Support/DirectPaymentReference` |
| `app/Http/Controllers/Externals/DirectPayments/` | 6 controllers, ~2,650 lines | **HTTP layer** — `DirectPaymentsController` (1,116), `…SubscriptionController` (804), `…Connect`, `…Advanced`, `…Notification`, `…Swap` |
| `app/Services/Billing/` | 11 + `Providers/` 17 + `Exceptions/` 3 (≈3,700 lines) | **Direct Payments domain code under a "Billing" name**: subscription sync, notification dispatch, refunds, dunning policy, provider readers/actions/retry configs — mixed with genuinely **appfactory-specific** classes |
| `app/Http/Controllers/Externals/Processor/` | 13 | The **processor (provider) layer** — deliberately separate ([00](00-overview.md) D4: the processor must not know Direct Payments) |
| `app/Models/DirectPayment*.php` | ~10 | Models |
| `app/Console/Commands/` | ~10 `DirectPayments*` / `Billing*` | Commands (see §31.6) |
| Façade-side services | `Ecommerce/`, `Events/`, `Appointments/` `*DirectPaymentService` | **Consumers** — correctly outside |

### The two real problems

1. **The domain layer is split.** `App\Externals\DirectPayments\*` and `App\Services\Billing\*` are the same
   layer with two names, built in different efforts (the files carry `AFS_ECOMMERCE_*` vs
   `AFS_BILLING_*` markers). Nobody can say which a new class belongs in.
2. **A dependency points the wrong way.** `DirectPaymentSubscriptionController` imports
   `MembershipTierResolver` — an **appfactory** class — so Direct Payments code depends on the
   appfactory studio implementation. The decoupling contract ([26 §12.2](26-advanced-policy.md))
   says the opposite: appfactory depends on Direct Payments, never the reverse. A move alone does
   not fix this; §31.4 does.

## 31.2 The rule

| Layer | Namespace / directory | May depend on |
|-------|----------------------|---------------|
| **HTTP** | `App\Http\Controllers\Externals\DirectPayments` | the domain layer, models |
| **Domain** | `App\Externals\DirectPayments\*` | models, `App\Processor`-side services, contracts |
| **Processor** | `App\Http\Controllers\Externals\Processor` (+ its services) | itself only — never Direct Payments |
| **appfactory-specific** | `App\Services\Billing` (kept, shrunk) | Direct Payments domain — **never the reverse** |
| **Consumers** | `Ecommerce/`, `Events/`, `Appointments/` | only `Contracts\PaymentGateway` |

Everything that is *about a Direct Payment or its subscription* goes under `App\Externals\DirectPayments`.
`App\Services\Billing` keeps only what exists because appfactory has members and tiers.

## 31.3 The file map

| Today (`App\Services\Billing\…`) | Target | Reason |
|-----|-----|--------|
| `SubscriptionSyncService` | `App\Externals\DirectPayments\Subscriptions\SubscriptionSyncService` | Provider sync for any Direct Payment |
| `Providers\{Stripe,PayPal,Square}SubscriptionReader`, `SubscriptionReader`, `Subscription{Gone,Unavailable}Exception` | `App\Externals\DirectPayments\Subscriptions\Providers\…` | Same |
| `Providers\{Stripe,PayPal,Square}SubscriptionActions`, `SubscriptionActionsProvider`, `SubscriptionActionFailedException`, `PlanChangeNotSupportedException`, `PaymentMethodChangeNotSupportedException` | `App\Externals\DirectPayments\Subscriptions\Providers\…` | Cancel/update/change-card ([28](28-subscription-actions.md)) |
| `Providers\{Stripe,PayPal,Square}RetryConfig`, `ProviderRetryProfile` | `App\Externals\DirectPayments\Subscriptions\Providers\…` | [27](27-provider-retry-policy.md) |
| `NotificationDispatcher`, `SendResult` | `App\Externals\DirectPayments\Notifications\…` | [23](23-notification-config.md)/[24](24-notification-scheduler.md) |
| `BillingMailer`, `Exceptions\MailHandlerMissingException`, `MailIntegrationMissingException` | `App\Externals\DirectPayments\Notifications\…` *(see Q1)* | The mail seam the notifier uses |
| `RefundService` | `App\Externals\DirectPayments\Money\RefundService` | [29](29-money-and-refunds.md) |
| `DunningPolicy`, `DunningPolicyResolver` | `App\Externals\DirectPayments\Dunning\…` | Per-DP policy ([26](26-advanced-policy.md)); reads `laravel_direct_payment_advanced`, no `brain_*` |
| `AdvancedConfigGate` | `App\Externals\DirectPayments\Dunning\AdvancedConfigGate` | Guards the Direct Payments Advanced card |
| **Stay in `App\Services\Billing`** | | |
| `DunningService`, `MembershipTierResolver` | *(unchanged)* | Write `brain_memberships`; appfactory-specific ([billing/08](../../billing/08-brain-enforcement.md)) |
| `Exceptions\DryRunRollback` | *(unchanged)* | Used by the enforcement command |
| `BrainGuard` | *(retired)* | Replaced by `config('brain.is_brain')` ([www_brain](../../www_brain/08-security-and-safety.md) §8.3) |

`app/Externals/DirectPayments/` keeps every file it has; the new sub-folders are added beside them, so the
directory stays one place to look.

**Not moving:** the six controllers stay in `Http/Controllers/DirectPayments` (the HTTP layer is
correctly separate); the processor controllers stay put (D4); models stay in `app/Models`.

## 31.4 Fixing the wrong-way dependency

`DirectPaymentSubscriptionController` writes `brain_memberships.membership` after a self-service plan
change by calling `MembershipTierResolver` ([billing/17](../../billing/17-self-service-tier-changes.md)).
That is appfactory behaviour inside a Direct Payments controller.

Do **not** just repoint the import. Replace the call with an **event or hook** the Direct Payments
layer raises after a successful plan change, which the appfactory layer listens to:

```
Direct Payments (generic)                   appfactory (App\Services\Billing)
──────────────────────────                  ────────────────────────────────
update() succeeds ─▶ raise                  listener resolves tier, writes
  SubscriptionPlanChanged(subscription,       brain_memberships.membership
  old_level, new_level)                       (same synchronous behaviour)
```

- **Synchronous**, in the same request, so the member sees the new tier immediately — the property
  billing/17 exists to guarantee. A Laravel event with a sync listener does this.
- On a **tenant** (no `brain_memberships`) no listener is registered, so nothing happens — which is
  the correct generic behaviour.
- The listener is registered only when `config('brain.is_brain')` is true.
- This is a behaviour-preserving change with its own test ([31.7](#317-tests)); it can ship before,
  after or independently of the file moves, and **should not be bundled into the same commit**.

## 31.5 The move, safely

PSR-4 maps `App\` to `app/`, so any directory works — no `composer.json` change, only
`composer dump-autoload`.

1. **Freeze.** Land or shelve pending `www` work; this touches ~31 files (11 + 17 + 3 classes, minus the ones that stay) and ~14 importers, and
   should be its own commit series ([AGENTS.md §1](../../../../../w/AGENTS.md): commits only when
   asked).
2. **Move with history.** `git mv` each file, then rewrite its `namespace` line.
3. **Rewrite references mechanically** (a script, reviewed, not hand edits):
   - `use App\Services\Billing\X;` lines,
   - inline fully-qualified names — **`ProcessorWebhookController` alone has 13** such
     `app(\App\Services\Billing\…::class)` calls,
   - docblocks and `@see` references,
   - string references (`'App\\Services\\Billing\\…'`) in config, routes, service providers or
     tests — grep for the backslash-escaped form too.
4. **No aliases.** This is an application, not a published package; leaving
   `class_alias` shims would keep both names alive forever.
5. **Verify** ([31.7](#317-tests)) before committing.
6. **Rewrite the plan documents in place** — [31.10](#3110-the-plan-documents-are-part-of-the-change). This is
   part of the change, not a follow-up; the change is not done until step 6 is.

### Known reference count (2026-09-25)

| Kind | Files |
|------|-------|
| Files importing the namespace in `app/` | 14: nine in `Http` (`DirectPaymentSubscriptionController`, `DirectPaymentAdvancedController`, `DirectPaymentsController` (inline), `ProcessorWebhookController`, `ProcessorRetryPolicyController`, `ProcessorPlanAdminController` (inline), `MoneyController`, `MembershipController`, `DirectPaymentAdvancedWriteMiddleware`) and five commands (`BillingSubscriptionNotify`, `BillingEnforceMembership`, `ProcessorBackfillSubscriptions`, `ProcessorBackfillRetrySettings`, `MoneyBackfillCharges`) |
| Tests | 1 found (`DirectPaymentSubscriptionUpdateMembershipTest`) — more may use the classes by another spelling |
| `routes/`, `config/`, `bootstrap/`, `database/` | none found for the namespace string |

These are counts from a bounded search, not a guarantee; step 3's grep is the authority.

## 31.6 Commands (optional, same effort)

Command prefixes are currently inconsistent, and one of them was changed in this planning pass:

| Signature today | Notes |
|-----------------|-------|
| `direct_payments:subscription-notify` | **Renamed in the plans** from `billing:subscription-notify`; the code still says `billing:` until built ([24](24-notification-scheduler.md)) |
| `directpayments:backfill-processor-bindings`, `directpayments:backfill-tier-bindings`, `directpayments:import-customer-map` | **Already exist, no underscore** — a *different* spelling from the renamed scheduler |
| `billing:backfill-dp-subscriptions`, `billing:subscription-migrate` | Direct Payments work under the `billing:` prefix |
| `processor:backfill-subscriptions`, `processor:backfill-retry-settings` | Processor layer — leave |
| `money:backfill-charges` | Money ledger ([29](29-money-and-refunds.md)) — leave |

Proposal: **one prefix, `direct_payments:`**, for everything that is about a Direct Payment:

| Today | Proposed |
|-------|----------|
| `directpayments:backfill-processor-bindings` | `direct_payments:backfill-processor-bindings` |
| `directpayments:backfill-tier-bindings` | `direct_payments:backfill-tier-bindings` |
| `directpayments:import-customer-map` | `direct_payments:import-customer-map` |
| `billing:backfill-dp-subscriptions` | `direct_payments:backfill-subscriptions` |
| `billing:subscription-migrate` | `direct_payments:subscription-migrate` |

Each rename must be checked against **cron entries, shell scripts, runbooks and plan docs** that call
it by name (`commands.sh`, crontabs, the completion docs) before it lands — a renamed command that a
script still calls by the old name fails silently. `billing:enforce-membership` is *not* renamed: it
stays appfactory-specific and is moving to `members` anyway.
## 31.7 Tests

| Check | How |
|-------|-----|
| **No behaviour change** | Run the existing `tests/Feature/DirectPayments` suite before and after; results identical. (Tests need `APP_URL=https://www.test` in the environment — the `.env` `APP_URL` otherwise leaks into PHPUnit) |
| Autoload | `composer dump-autoload`; `php artisan list` and `php artisan route:list` both succeed |
| No leftovers | `grep -rn "App.Services.Billing" app tests routes config` shows only the classes that intentionally stayed |
| Wrong-way dependency gone | A test/grep asserting nothing under `App\Externals\DirectPayments` or `Http\Controllers\DirectPayments` imports `App\Services\Billing` |
| `SubscriptionPlanChanged` event | Plan change writes the tier synchronously when a listener is registered; does nothing (no error) when none is |
| Cron/commands | `php artisan schedule:list` unchanged apart from any renamed command |
| **Docs match the code** | The greps in [31.10](#3110-the-plan-documents-are-part-of-the-change) step 4 return nothing outside the documented exceptions |

A cheap guardrail worth keeping after the move: an architecture test that fails if
`App\Externals\DirectPayments\*` imports `App\Services\Billing\*`, so the layering cannot silently regress.

## 31.8 Risks

| Risk | Mitigation |
|------|------------|
| A missed inline FQCN breaks a webhook at runtime, not at boot | The step-3 grep, plus the webhook controller tests; PHP does not fail on an undefined class until it is called |
| Queued jobs or cache/DB rows hold a serialized old class name | Check for serialized `App\Services\Billing` in `jobs`/`failed_jobs` before moving; drain the queue first |
| A rename breaks a cron line or script | §31.6 check; do renames in a separate step from the namespace move |
| Reviewers cannot see the real change in a 25-file diff | Move-only commit (renames + namespace + references) with **no logic edits**; the event change is a separate commit |
| Plans and docs drift from the code | Step 6, and the file map in `billing/01` §1.7 |

## 31.9 Decisions

The questions this plan raised, and what was decided:

| # | Question | Decision |
|---|----------|---------|
| **Q1** | `BillingMailer` is used by both the generic notifier and appfactory code (`MembershipController`). Generic `DirectPayments\Notifications`, or a neutral `App\Mail`? | `App\Mail`, beside `MailHandlerSender` — it is a mail seam, not a payments concept. `SendResult` and the two mail exceptions moved with it |
| **Q2** | Keep the class names or drop the `Billing` flavour too? | Names kept; only namespaces changed |
| **Q3** | Before or after the command rename and the enforcement move to `members`? | Before |
| **Q4** | `DunningPolicyResolver` is also copied into `members` by [billing/08](../../billing/08-brain-enforcement.md) §8.12. Which is authoritative? | The `www` one (`App\Externals\DirectPayments\Dunning`); `members` copies the read logic only |
| **Q5** | `direct_payments:` or `directpayments:`? | `direct_payments:`, matching the folder name and the scheduler |

## 31.10 The plan documents are part of the change

The plans are what the next person reads to learn how the system works. After this refactor a
reader must find the **current** paths, namespaces, class names and command names in the documents
themselves. A pointer to this document, a "renamed from …" aside or a "see the change note" line is
**not** an update — it makes every reader do the translation this document did.

### The rules

1. **Rewrite the sentence, not add a footnote.** Where a document says
   `www/app/Services/Billing/SubscriptionSyncService.php`, it says
   `www/app/Externals/DirectPayments/Subscriptions/SubscriptionSyncService.php`. Where it explains how
   something works, the explanation describes the new arrangement as simply the arrangement.
2. **No breadcrumbs.** Do not leave "(formerly …)", "*(renamed from …)*", "superseded — see …",
   "pre-relocation" or "history:" notes to make a change visible. The one place a change is
   recorded is that plan's dated status line or `complete/` account, which says *what was done and
   why*, not *what the old name was on every line*.
3. **Code samples and file maps are corrected too.** Namespaces in snippets, the path tables in
   [`billing/01`](../../billing/01-architecture.md) §1.7 and the "files touched" tables in the
   numbered plans are part of the specification and must compile in the reader's head.
4. **`complete/` documents are updated for code references as well.** [AGENTS.md](../../../../../w/AGENTS.md)
   §4 makes `complete/` the authority for "how does X work today", so a stale path there actively
   misleads. Update paths, namespaces, class names and command names; **leave the historical
   narrative alone** — what was decided, what diverged, what was found. That narrative is the record;
   only the pointers into the code are wrong.
5. **Cross-plan claims stay true.** A statement in one plan about another ("`billing/08` deletes
   `BrainGuard`") is re-read and corrected against the final state.

### Inventory (2026-09-25)

A bounded search of `packages/afsserver/plans` for the old locations and names
(`Services/Billing`, `billing:subscription-notify`, `billing:backfill-dp-subscriptions`,
`billing:subscription-migrate`, `directpayments:backfill|import`) found **113 occurrences in 32
files**. Counts are a floor — inline class names such as `BrainGuard`, `DunningService` or
`BillingMailer` without a path are not in that number and must be found by the greps below.

| Group | Files | Notes |
|-------|-------|-------|
| `www/direct_payments/*.md` (live) | `README`, `03`, `22`, `24`, `26`, `27`, `30`, plus this document | Rewrite paths and namespaces |
| `www/direct_payments/complete/*.md` | `02`, `03`, `06`, `07`, `21`, `22`, `23`, `24`, `26`, `27`, `28`, `29` (12 files, ~46 occurrences) | Rule 4 |
| `billing/*.md` (live) | `01` (8), `08`, `10`, `11`, `17` | `01` §1.7's file map is the biggest single edit |
| `billing/complete/*.md` | `08-brain-enforcement.complete` (7) | Rule 4 |
| `account_close/*.md` | `01`, `07` | Cross-plan pointers |
| `membership_change/*.md` | `07` (6), `09` | **Not** Direct-Payments plans but cite the paths — easy to miss |
| `brain_membership_identity/complete/` | `02` (3) | Same |
| `www_brain/*.md` | `08`, `09` | Reference `BrainGuard` and `NotificationDispatcher` |
| `TODOS.md` | the dunning entry | Names the old locations |
| `w/AGENTS.md` | none found for these names | Re-check its plan list and any path tables after the move |

### Documents that already carry breadcrumbs and must be rewritten

Earlier planning passes recorded moves as annotations instead of rewriting the text. These are the
first thing to fix, whether or not the code move has happened yet, because they are the
pattern rule 2 forbids:

| Document | Breadcrumb to replace with a plain statement of the current design |
|----------|--------------------------------------------------------------------|
| `billing/01-architecture.md` | "Relocation decision (supersedes §1.2 …)" banner, "§1.2 … *(superseded …)*", the "*(History: …)*" paragraph |
| `billing/08-brain-enforcement.md` | "Status: planned relocation" banner, "the pre-relocation locations" note, the `www` paths in the old §8.1–8.2 wording |
| `billing/00-overview.md` | D1 "*(amended by D18)*", D9 "*(retired by D18 …)*" — restate D1 as the decision, and drop D9 or move it to a "rejected" list |
| `www/direct_payments/24-…`, `21-…`, `26-…` | "*(renamed from `billing:subscription-notify` …)*", "Pre-relocation it was registered here with `BrainGuard`", "the old `BrainGuard` … retired" |
| `www/direct_payments/README.md` | The "Moved from `billing/`" table's **Was** column may stay (it records document moves), but code paths in it must be current |
| `account_close/01`, `07` | "*(Current state, before the billing relocation …)*", "had to open with `BrainGuard`…" |

For a **planned-not-built** change (the `members` enforcement move) the correct form is the
*target design written as the design*, with one dated status line at the top of the file
("Status: not built") — not the old design plus a correction beside it.

### Procedure

1. **Order.** Code move first, then documents, in the same change set, so the docs are never ahead
   of or behind the code by more than the review. (For the doc-only breadcrumb rewrites above the
   order does not matter.)
2. **Script the mechanical part.** A reviewed script rewrites the class/namespace/path/command
   names using the [31.3](#313-the-file-map) map and [31.6](#316-commands-optional-same-effort). It
   runs over `plans/` and prints every changed line for review.
3. **Hand-edit the explanatory part.** Anything that *reasons* about the old arrangement (why the
   two directories existed, why a class lived in `Billing`, the D-decisions that assumed the old
   split) is rewritten by a person, per rules 1–2. The script cannot judge that.
4. **Verify.** These must return nothing, except for the documented exceptions:

   ```
   grep -rnE "Services[\\/]+Billing" plans            # only DunningService, MembershipTierResolver, DryRunRollback
   grep -rn  "billing:subscription-notify" plans      # nothing
   grep -rnE "directpayments:(backfill|import)" plans # nothing
   grep -rnE "BrainGuard" plans                       # nothing (retired)
   grep -rniE "formerly|renamed from|pre-relocation|superseded|was moved" plans/www plans/billing plans/www_brain plans/account_close
   ```

   The last line is judged, not blindly zero: "superseded" is legitimate in a `complete/` doc's
   narrative about a decision, but not as a stand-in for updating a section.
5. **Link check.** Every relative link in the touched folders resolves, as in the earlier audits.
6. **Record it once.** Each affected plan gets its normal dated status line or `complete/` entry
   saying the layout changed and why — one sentence — with no per-line annotations.

### Acceptance for the documentation

- A reader who has never seen this document can follow any plan to the correct file, class and
  command with no translation.
- No live plan contains a breadcrumb (rule 2), and no `complete/` document points at a path that
  does not exist.
- The greps in step 4 pass and the link check is clean.