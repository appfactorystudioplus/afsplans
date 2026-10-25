# 13 — Provider Retry Policy

What each provider does when a subscription charge fails, where that behaviour is
configurable, and the per-provider configuration objects that expose it.

## 13.1 Why this belongs to the processor layer

Retry behaviour is a fact about **Stripe, PayPal or Square** — not about a Direct Payment,
a member or a tier. It therefore lives in the processor layer, and everything here obeys the
same rule [22](22-subscription-state.md) §4.2 sets: **the processor knows only about
itself.** Nothing in this document references a Direct Payment.

The dependency runs the allowed direction — *downward*. [26](26-advanced-policy.md)'s
`grace_days` needs to know the provider's retry window in order to warn an operator who sets
a grace shorter than it (§13.7); Direct Payments reads the processor layer, never the
reverse.

## 13.2 What each provider actually does

Verified against current provider documentation, August 2026. **Re-verify before
implementing** — these are the most change-prone facts in this plan, and two of the three
are documented only in prose rather than in an API contract.

### Stripe

| | |
|---|---|
| **Mechanism** | Smart Retries (AI-timed) or a custom schedule |
| **Smart Retries** | N attempts within a chosen window: 1 week, 2 weeks, 3 weeks, 1 month, or 2 months. Stripe's recommended default is **8 attempts over 2 weeks** |
| **Custom schedule** | **Up to 3 retries**, each a chosen number of days after the previous attempt |
| **After exhaustion** | Configurable: `cancel` the subscription, mark it `unpaid` (invoices keep generating as drafts), or leave it `past_due` (invoices keep generating and charging) |
| **Configurable via API?** | **No.** Dashboard only — Billing → Revenue recovery → Retries. There is no Subscription or Invoice field, and no endpoint, that sets the retry policy |
| **Observable** | `invoice.payment_failed` webhook; `invoice.attempt_count`; `invoice.next_payment_attempt` (the scheduled next attempt) |

Two behaviours worth knowing before designing around Stripe's retries:

- **Hard declines suspend execution, not scheduling.** For `incorrect_number`, `lost_card`,
  `pickup_card`, `stolen_card`, `revocation_of_authorization`,
  `revocation_of_all_authorizations`, `authentication_required`, `highest_risk_level` and
  `transaction_not_allowed`, retries stay scheduled and `attempt_count` keeps incrementing,
  but nothing is actually charged until a new payment method appears. So a rising
  `attempt_count` does **not** prove Stripe is still trying.
- **Stripe does not retry at all** when there is no payment method on file, the card is
  India-issued, or the Connect account is disconnected.

### PayPal

| | |
|---|---|
| **Mechanism** | PayPal retries every **5 days**, **up to twice per billing cycle**. After the second failure the amount rolls into the next cycle's balance |
| **Threshold** | `payment_preferences.payment_failure_threshold` on the **plan** — consecutive failures before the subscription is **`SUSPENDED`** (not cancelled) |
| **Outstanding balance** | `payment_preferences.auto_bill_outstanding` — carry the failed amount forward and attempt it on the next cycle |
| **Setup fee** | `payment_preferences.setup_fee_failure_action` — `CONTINUE` \| `CANCEL` |
| **Configurable via API?** | **Yes.** Set at `POST /v1/billing/plans`, and changed later with `PATCH /v1/billing/plans/{id}` — `op: replace`, path `/payment_preferences/payment_failure_threshold`. Returns `204 No Content` |
| **Observable** | `BILLING.SUBSCRIPTION.PAYMENT.FAILED`, `BILLING.SUBSCRIPTION.SUSPENDED` |

You control **how many** failures are tolerated; PayPal controls **when** it retries. No
minimum or maximum for `payment_failure_threshold` is documented — treat the bounds as
unverified and clamp to a sane range (§13.5).

### Square

| | |
|---|---|
| **Mechanism** | Fixed: retries on **day 3, 6 and 9** after the decline, then stops |
| **On decline** | Square emails the customer an invoice immediately, and emails the merchant |
| **Configurable?** | **No.** Not by API, not by dashboard. There is nothing to write |
| **Observable** | `invoice.payment_made`, subscription and invoice webhooks |

### The comparison that matters

| | Retry window | Attempts | Configurable | Terminal state |
|---|---|---|---|---|
| Stripe | 1 wk – 2 mo (default 2 wk) | up to 8 (Smart) / 3 (custom) | Dashboard only | `canceled` / `unpaid` / `past_due` |
| PayPal | ~5-day cadence, 2 per cycle | threshold-driven | **API, per plan** | `SUSPENDED` |
| Square | **9 days, fixed** | 3 | **No** | deactivated |

A single `grace_days = 14` is therefore three different things depending on the backing
processor — the argument developed in [08](../../billing/08-brain-enforcement.md) §8.4.

## 13.3 The capability descriptor — code, not configuration

`App\Externals\DirectPayments\Subscriptions\Providers\ProviderRetryProfile` — a **static** per-provider
descriptor. It is provider truth, not user data, so it does not go in a table where it could
be edited into a lie.

```php
/**
 * AFS_BILLING_MODIFIER_ADDITION — plans/www/direct_payments/27-provider-retry-policy.md §13.3.
 *
 * What one provider does about failed subscription charges, and how much of it
 * this application is allowed to change. Static: these are facts about Stripe,
 * PayPal and Square, and an operator must not be able to edit them into
 * something untrue.
 *
 * `retryWindowDays` is the value 12-direct-payments-advanced.md §12.5 compares
 * grace_days against. For Square it is exact (9). For PayPal it is derived from
 * the configured threshold. For Stripe it is UNKNOWABLE from the API and comes
 * from the operator's own declaration (§13.4) — `windowIsDeclared` says which,
 * so no caller mistakes a declaration for a fact.
 */
final class ProviderRetryProfile
{
    public string $provider;              // stripe | paypal | square
    public string $summary;               // the §13.2 description, shown in admin
    public bool   $configurableViaApi;    // paypal only
    public array  $configurableFields;    // [] for stripe and square
    public ?int   $retryWindowDays;
    public bool   $windowIsDeclared;      // true for stripe — operator-stated, not read
    public array  $terminalStates;
    public ?string $dashboardUrl;         // stripe: deep link to the setting we cannot write
}
```

`ProviderRetryProfile::for('stripe'|'paypal'|'square')` is the only way to obtain one.
An unknown provider throws rather than returning a permissive default — a new provider must
be described before it can be sold.

## 13.4 The three configuration objects

One per provider, as asked. They differ in kind, not just in fields, and that difference is
the honest reflection of what each API permits.

### `StripeRetryConfig` — declared, advisory, never written to Stripe

Stripe's retry policy is not reachable by API. So this object is a **mirror the operator
maintains by hand**, so the rest of the system can reason about the window:

| Field | Notes |
|---|---|
| `retry_window_days` | What the operator has set in the Stripe Dashboard: 7, 14, 21, 30 or 60 |
| `max_attempts` | Smart Retries attempt count, or the custom schedule's length |
| `uses_smart_retries` | bool — Smart Retries vs a custom schedule |
| `terminal_behavior` | `cancel` \| `unpaid` \| `past_due` — mirrors the Dashboard setting |

**Every field is `is_declared = true`.** The UI labels the whole panel *"Recorded from the
Stripe Dashboard — this application cannot read or change these settings"*, and links
straight to `dashboard.stripe.com/revenue_recovery/retries`. Presenting a declared value as
if it were read from Stripe would be worse than showing nothing: it would be trusted.

Writing this object **never** calls Stripe.

### `PayPalRetryConfig` — authoritative, written through to PayPal

The only genuinely configurable one:

| Field | Maps to | Write path |
|---|---|---|
| `payment_failure_threshold` | `payment_preferences.payment_failure_threshold` | `PATCH /v1/billing/plans/{id}` — `op: replace`, path `/payment_preferences/payment_failure_threshold` |
| `auto_bill_outstanding` | `payment_preferences.auto_bill_outstanding` | Same PATCH |
| `setup_fee_failure_action` | `payment_preferences.setup_fee_failure_action` | Same PATCH |

Scoped **per plan** (`laravel_processor_plans`), because that is PayPal's own scope. A save
does two things in order: PATCH PayPal, and only on `204` write the local row. Local-first
would let the database claim a threshold PayPal never accepted.

`retry_window_days` is **derived**, not stored: `threshold × 5` days, from PayPal's 5-day
cadence. Derived and marked as such, so §13.7's warning has a number to compare against.

### `SquareRetryConfig` — constants, no fields

Square exposes nothing to configure, so this object has **no writable fields at all**. It
exists so that callers can ask every provider the same question and get a real answer rather
than a null:

```php
SquareRetryConfig::retryDays()        // [3, 6, 9]
SquareRetryConfig::retryWindowDays()  // 9
SquareRetryConfig::isConfigurable()   // false
```

The admin panel renders the description and nothing else — no disabled inputs. A greyed-out
field implies a permission problem and invites a support ticket; "Square does not allow this
to be configured" is the truth and closes the question.

## 13.5 `laravel_processor_retry_settings`

One table, provider-discriminated, with per-provider validation. Typed columns rather than a
JSON blob, for the reasons in [26](26-advanced-policy.md) §12.3.

| Column | Type | Applies to |
|---|---|---|
| `id` | `id` | |
| `processor_reference_num` | `string(7)` index | all |
| `provider` | `string(20)` | all — the discriminator |
| `plan_id` | `ulid` null, index | **PayPal only** (its scope is the plan). Null = processor-wide |
| `retry_window_days` | `smallint` null | Stripe (declared) |
| `max_attempts` | `smallint` null | Stripe (declared) |
| `uses_smart_retries` | `boolean` null | Stripe |
| `terminal_behavior` | `string(20)` null | Stripe |
| `payment_failure_threshold` | `smallint` null | PayPal |
| `auto_bill_outstanding` | `boolean` null | PayPal |
| `setup_fee_failure_action` | `string(20)` null | PayPal |
| `is_declared` | `boolean` default `false` | **true for every Stripe row** — the values were typed in, not read |
| `synced_at` | `timestamp` null | PayPal — when the PATCH last succeeded |
| `sync_error` | `string(500)` null | PayPal |
| `timestamps` | | |

```php
$table->unique(['processor_reference_num', 'plan_id'], 'processor_retry_settings_uq');
```

Model `App\Models\Externals\Processor\ProcessorRetrySetting`; `$casts`: the three booleans to
`boolean`, `synced_at` to `datetime`. It belongs under `app/Models/Externals/Processor/` because it is
processor data (§13.1), and it gains **no** relation to `DirectPayment`.

Validation, per provider:

| Rule | Code |
|---|---|
| `provider` matches the processor's own `provider` | `PR_E_PROVIDER_MISMATCH` |
| Stripe: `retry_window_days` ∈ {7, 14, 21, 30, 60} | `PR_E_INVALID_RETRY_WINDOW` |
| Stripe: `terminal_behavior` ∈ {cancel, unpaid, past_due} | `PR_E_INVALID_TERMINAL_BEHAVIOR` |
| Stripe: `max_attempts` 1–8 | `PR_E_INVALID_MAX_ATTEMPTS` |
| PayPal: `payment_failure_threshold` 1–10, `plan_id` required | `PR_E_INVALID_FAILURE_THRESHOLD` |
| PayPal: `setup_fee_failure_action` ∈ {CONTINUE, CANCEL} | `PR_E_INVALID_SETUP_FEE_ACTION` |
| Square: **any writable field present** | `PR_E_NOT_CONFIGURABLE` |
| A field belonging to another provider is present | `PR_E_FIELD_NOT_FOR_PROVIDER` |

PayPal's 1–10 clamp is **ours, not PayPal's** — no bounds are documented, and an unbounded
threshold is a subscription that never suspends. The comment on the rule must say so, or a
future reader will "fix" it to match a spec that does not exist.

The last two rules matter more than they look: a union table invites a client to post a
Stripe field to a Square processor and have it silently stored. Reject, don't ignore.

## 13.6 Where it appears in the admin

`ProcessorDetailView.ts` and `processor-details.html`, in the **processor** admin section,
on a processor whose subscriptions are in use — that is,
one with at least one `laravel_processor_plans` row. A processor that only takes single
payments has no retry policy to show, and rendering an empty panel there is noise.

Each provider's panel leads with the §13.2 description in plain language, then:

- **Stripe** — the declared-mirror fields, the *"cannot read or change"* label, and the
  Dashboard deep link.
- **PayPal** — the real editable fields, per plan, with a save that reports the PATCH result.
  A failed PATCH shows the PayPal error and leaves the previous local values intact.
- **Square** — description only.

The description is not decoration. An operator setting a 7-day grace period on a
Square-backed Direct Payment needs to know Square stops retrying on day 9, and there is
nowhere else in the product they would learn it.

## 13.7 What [26](26-advanced-policy.md) does with this

`GET /portal/api/v1/processors/{ref}/retry-policy` returns the `ProviderRetryProfile` merged
with any stored settings. The advanced-config UI ([26](26-advanced-policy.md) §12.7)
calls it when the operator edits `grace_days`, and warns when
`grace_days < retryWindowDays`:

> ⚠ This Direct Payment uses a **Square** processor, which stops retrying **9 days** after a
> failed charge. A 5-day grace period downgrades members while Square is still trying.

For Stripe the warning adds *"based on the retry window recorded for this processor, which
this application cannot verify"* — because it cannot, and a warning that overstates its own
authority gets ignored the first time it is wrong.

**This is a warning, never a rejection.** The window is unverifiable for Stripe and derived
for PayPal ([26](26-advanced-policy.md) §12.5's reasoning for allowing
`grace_days = 0` applies unchanged).

## 13.8 Fixing the hardcoded PayPal values

`ProcessorPlanAdminController::createPayPalPlan()` currently hardcodes, at plan creation:

```php
'payment_preferences' => [
    'auto_bill_outstanding'     => true,
    'setup_fee_failure_action'  => 'CONTINUE',
    'payment_failure_threshold' => 3,
]
```

Every PayPal plan this application has ever created carries a threshold of 3 that no
operator chose and none can see (**B26**). This step replaces those literals with the
`PayPalRetryConfig` values, **keeping exactly these as the defaults** so existing behaviour
is unchanged for anyone who does not touch the new panel.

The backfill is read-only: for each existing PayPal plan, `GET /v1/billing/plans/{id}`, and
write what PayPal actually reports into `laravel_processor_retry_settings`. Do **not** assume
the hardcoded 3 — a plan may have been edited in PayPal's own dashboard since.

## 13.9 Migrations in this step

| File | Contents |
|---|---|
| `2026_08_25_000011_create_processor_retry_settings_table.php` | §13.5 |

## 13.10 Acceptance

- `ProviderRetryProfile::for()` returns a populated descriptor for all three providers, and
  **throws** for an unknown one.
- A Square processor's panel renders the description and offers **no** inputs; posting any
  writable field returns `PR_E_NOT_CONFIGURABLE`.
- A Stripe processor's panel is labelled as a declaration, links to the Dashboard, and
  saving it makes **no** outbound Stripe call — assert this with a mocked HTTP client.
- Saving a PayPal threshold issues the `PATCH`, and on a non-`204` leaves the local row
  unchanged with `sync_error` populated.
- Setting `grace_days = 5` on a Square-backed DP shows the 9-day warning and still saves.
- The same on a Stripe-backed DP shows the warning **with** the "cannot verify" caveat.
- `grep -rn "direct_payment\|DirectPayment\|Membership"` across the new provider classes,
  controller and migration returns nothing — §13.1, the same CI check as
  [22](22-subscription-state.md) §4.9.
- Creating a PayPal plan without touching the new panel produces
  `payment_failure_threshold: 3` exactly as before (§13.8).
