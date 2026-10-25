# 08 — Saved-Card Defaults

The Site Users Dashboard's saved-cards view ([connections/08](../connections/08-dashboard-sections.md)
§8.5) already shows a "Make default" button on every card, for every provider. Nothing behind
it works the way that implies. Found auditing [connections/10](../connections/10-api-contracts.md).

## 8.1 What's actually there today

`ProcessorPaymentMethodService::list()` never computes or returns `is_default` at all — no card
ever visually shows as *the* default, for any provider, regardless of what a shopper has
clicked. Worse, for two of the three providers the button is a real no-op:
`ProcessorPaymentMethodService::setDefault()` only ever acts for Stripe; its own comment says so
("Square and PayPal have no default payment method concept on the customer... acknowledged
everything else"). A shopper on Square or PayPal can click "Make default" and be told it
succeeded, having changed nothing.

**The deciding fact, found tracing where the Stripe field is actually consumed:** even for
Stripe, the field that button writes — `customer.invoice_settings.default_payment_method` — is
explicitly **not** what this codebase's subscription billing reads. `StripeSubscriptionActions::
setPaymentMethod()` carries its own docblock saying so directly:

> `subscription.default_payment_method` — the field retries actually use (§14.5, B31). **NOT**
> `customer.invoice_settings.default_payment_method`, which is what the pre-existing code
> updates and is a different field entirely — this method exists specifically because that one
> is wrong for a subscription's own retries.

Subscriptions already have their own, fully separate, already-built mechanism for "which card
pays this subscription" — nothing to do with the Dashboard's card-management view at all:

| Provider | Mechanism | Where |
|---|---|---|
| Stripe | `subscription.default_payment_method` — its own field, its own API call | `StripeSubscriptionActions::setPaymentMethod()` |
| Square | `subscription.card_id` — same idea, Square's own endpoint | `SquareSubscriptionActions::setPaymentMethod()` |
| PayPal | Not changeable via the API at all | `PayPalSubscriptionActions::setPaymentMethod()` throws `PaymentMethodChangeNotSupportedException('PayPal payment methods can only be changed by the payer, at PayPal.')` |

So "default card" in the Dashboard has never driven billing for anything, for any provider — it
is purely informational, even for Stripe. That means there is no asymmetry to preserve.
Building one internal mechanism that treats all three providers identically is *more* correct
than special-casing Stripe as "the real one" and faking the other two.

## 8.2 The design — an internal pointer, not a provider field

Track the default entirely inside this app: a local table pointing at a provider's own method
id, never card data — the same posture [02](02-payers-and-bindings.md) already takes for payers
(`DirectPaymentPayerCustomer` stores `provider_customer_id`, never card or account details). One
row means "this is the starred saved method for this customer on this processor":

```php
/**
 * AFS_BILLING_MODIFIER_ADDITION — plans/www/direct_payments/08-saved-card-defaults.md §8.2.
 *
 * Neither Stripe's `customer.invoice_settings.default_payment_method`, Square, nor PayPal
 * has a default this codebase's own subscription-retry logic actually reads (§8.1). So
 * "default" here is an internal pointer this app owns for all three providers uniformly —
 * never card data, only the provider's own id for a method already vaulted there.
 */
Schema::create('laravel_direct_payment_default_methods', function (Blueprint $table) {
    $table->id();
    $table->string('processor_reference_num', 7)->index();

    // The provider's own customer id — the same key list()/store()/delete()/setDefault()
    // already take. One default per (processor, customer): the unique index below IS the
    // "exactly one row" rule, not a service-layer check.
    $table->string('provider_customer_id', 255);

    // The provider's own payment-method/card/vault-token id — never card data.
    $table->string('provider_method_id', 255);

    $table->timestamps();

    $table->unique(['processor_reference_num', 'provider_customer_id'], 'dp_default_method_uq');
});
```

Two behaviours around it are admin-configurable, per processor, in the existing Processor
settings screen (`extra_config`, the same JSON blob `location_id`/`webhook_id` already live in):

- **`saved_card_default_on_add`: `'auto' | 'manual'`, default `'manual'`.** Does a newly-saved
  card become the default automatically? `'auto'` only ever fires when there is currently no
  default — adding a second/backup card must never silently steal default status from an
  existing choice.
- **`saved_card_default_on_delete`: `'auto_promote' | 'none'`, default `'auto_promote'`.** When
  the payer deletes their current default, does another remaining card get promoted, or does the
  payer have no default until they pick one again?

```php
// app/Models/Externals/Processor/Processor.php
public function defaultMethodOnAdd(): string
{
    $value = ($this->extra_config ?? [])['saved_card_default_on_add'] ?? null;
    return in_array($value, ['auto', 'manual'], true) ? $value : 'manual';
}

public function defaultMethodOnDelete(): string
{
    $value = ($this->extra_config ?? [])['saved_card_default_on_delete'] ?? null;
    return in_array($value, ['auto_promote', 'none'], true) ? $value : 'auto_promote';
}
```

## 8.3 `ProcessorPaymentMethodService` — three new methods, deliberately curl-free

This file has no test of its own today — everything about it is exercised indirectly via
`SiteUsersPaymentsControllerTest.php`, which avoids the network entirely by using `Processor`
rows with no secret key so `getSecretKey()` returns null and every provider branch
short-circuits before curl fires. The new default-tracking logic must be testable the same
way — given already-fetched data, never making its own curl call:

- **`attachDefaultFlag(array $methods, Processor $processor, string $providerCustomerId): array`**
  — looks up the stored row, marks the matching entry `'is_default' => true`. If the stored
  `provider_method_id` isn't found among `$methods` (stale — the card was removed directly at
  the provider), deletes the stale row and treats it as "no default." If there's no row at all
  but exactly one method in the list, marks that one default — trivially correct, no row
  needed. Every other entry gets `'is_default' => false`.
- **`maybeSetDefaultOnAdd(Processor $processor, string $providerCustomerId, string $methodId): void`**
  — called after a successful `store()`. No-ops unless `defaultMethodOnAdd() === 'auto'` AND no
  row currently exists for this (processor, customer); then creates one pointing at the new
  method.
- **`handleDefaultOnDelete(Processor $processor, string $providerCustomerId, string $deletedMethodId): void`**
  — called after a successful `delete()`. No-ops unless the deleted method WAS the stored
  default. On `'none'`, deletes the row. On `'auto_promote'` (default), deletes the row, then
  calls `list()` again — the cache was already busted by `delete()`'s own `bustCache()` call, so
  this re-fetches live — and if any methods remain, sets the first one as the new default.

Wiring:

- **`list()`** — pipe the result through `attachDefaultFlag()` **after** `Cache::remember(...)`
  returns, hit or miss, never inside the cached closure — the default must always reflect
  current state even while the provider-list cache is still warm.
- **`store()`** — on success, after the existing `bustCache()`, call `maybeSetDefaultOnAdd()`.
- **`delete()`** — on success, after the existing `bustCache()`, call `handleDefaultOnDelete()`.
- **`setDefault()`** (the explicit "Make default" button) — keep the existing Stripe-only API
  call as-is (harmless, keeps Stripe's own dashboard cosmetically in sync) but **always** upsert
  the local row too, for all three providers. This becomes the actual source of truth `list()`
  reads — not the Stripe-only branch.

## 8.4 Admin UI

Two new fields in the existing per-processor settings screen
(`resources/js/portal/admin/html/views/processor-details.html` +
`.../controllers/processors/ProcessorDetailView.ts`), provider-agnostic — shown regardless of
which provider is selected, inserted at the `<hr class="my-3">` immediately before the existing
"Is Active?" row:

- **On add** — "Payer sets it explicitly" (`manual`, default) / "First saved card becomes
  default automatically" (`auto`).
- **On delete** — "Auto-promote another saved card" (`auto_promote`, default) / "Leave no
  default until the payer picks one" (`none`).

**A real bug fix this depends on, found while tracing the save path:** `buildSettingsPayload()`
currently builds `extra_config` as `{}` from scratch on every save, populated only by the active
provider's own `switch` branch. Any other key already stored there — `extra_config.connect` for
marketplace, read by `directpayments.ts` — is silently wiped by *any* processor settings save
today, regardless of provider, whether or not this plan ships. Fixing it (start from
`{ ...(currentProcessor?.extra_config ?? {}) }` instead of `{}`) is required for these two new
fields to be safe to add — saving them must not blow away someone's Connect config on the same
processor.

## 8.5 SDK

`resources/js/external/api/portal.d.ts` — `PortalDirectPaymentsSavedMethod` gains
`is_default?: boolean`, matching `list()`'s new response field.

## 8.6 Acceptance

- A payer's saved cards for any of the three providers show exactly one `is_default: true` once
  they've explicitly set one, or automatically once a processor's `saved_card_default_on_add`
  is `'auto'` and they save their first card.
- Deleting the current default: another remaining card is promoted when
  `saved_card_default_on_delete` is `'auto_promote'` (the default); no card is marked default
  afterward when it is `'none'`.
- A card removed directly at the provider (never through this app) is not left as a dangling
  "default" the next time `list()` runs.
- Adding a second card when one is already the default never changes which one is default,
  regardless of the on-add setting.
- Saving unrelated processor settings (e.g. rotating a Stripe secret key) never removes
  `extra_config.connect` or any other previously-stored key.

## 8.7 Tests

`tests/Unit/Processor/ProcessorPaymentMethodServiceDefaultTest.php` — mirrors
`EcommerceOrderPayerTest`'s own pattern of testing a private method directly via
`ReflectionMethod` rather than routing through the network-dependent public methods. Needs
`laravel_direct_payment_default_methods` in a sqlite `Schema::create` (the convention every test
file in this suite uses — see `tests/Support/CreatesSiteUsersSchema.php`) plus a bare
`Processor` model instance for the two `defaultMethodOn*()` accessors (`extra_config` set
directly in memory, no DB row needed to exercise those).

Cases: `attachDefaultFlag()` marks the stored default among several methods; clears and falls
through to "no default" on a stale stored id; marks the lone method default when nothing is
stored and exactly one method exists. `maybeSetDefaultOnAdd()` sets on `'auto'` with nothing
existing; no-ops on `'auto'` when a default already exists; no-ops on `'manual'`.
`handleDefaultOnDelete()` promotes the first remaining method on `'auto_promote'`; clears with
no promotion on `'none'`; no-ops when the deleted method wasn't the default.
