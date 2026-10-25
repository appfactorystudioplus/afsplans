# 05 — The Implementer Contract

What a selling module stores, what it calls, and what each of the four consumers changes.

## 5.1 The contract, in full

> **A module stores two Direct Payments values and no provider value:
> `direct_payment_reference_num` and `payer_reference_num`. Both are ours. Neither ever
> changes.**

Everything else it needs — the amount, the status, the provider customer, the card, the
refund — it asks for at the moment it needs it, and Direct Payments answers from whichever
account is correct for that object ([04](04-history-refunds-and-webhooks.md) §4.1).

This is the same shape the façade contract already has
([`../ecommerce/AUDIT`](../ecommerce/AUDIT-direct-payments-decoupling.md)): *store
references, resolve facts*. This plan just removes the one reference that was not ours.

## 5.2 What changes on a module's tables

| | Before | After |
|---|---|---|
| The Direct Payment | `direct_payment_reference_num` | **unchanged** |
| The processor | `processor_reference_num` (snapshot, on the order) | **unchanged** — still a snapshot, still correct, now also the thing refunds resolve through |
| The invoice | `direct_payment_invoice_ref` | **unchanged** |
| The customer | *(the raw `cus_…`, in the invoice and the Site Users link)* | **`payer_reference_num`** `char(32)`, nullable, indexed |

So the façade order still holds three references, and gains a fourth that identifies the
buyer. No column is removed from a module's tables by this plan — the provider id lived in
Direct Payments' invoice row and in Site Users' link row, not in the order.

### The cart's payer is provisional until checkout completes (D11)

A guest's cart gets a `payer_reference_num` the moment they add a first line — a fresh, empty
payer with no email, exactly as it does today for the cart cookie itself
([`../connections/12`](../connections/12-guest-continuity.md) §12.1). If that guest signs in
before completing checkout, the cart's payer has to change with it, or the order ends up owned
by the account while paid-as a payer nobody will ever see again.

```php
/**
 * AFS_BILLING_MODIFIER_ADDITION — plans/www/direct_payments/05-implementer-contract.md §5.2 (D11).
 *
 * Runs at the SAME moment `EcommerceCartService::resolveCartForShopper()`
 * re-attributes `site_user_reference` (connections/06 §6.7) — this is that
 * moment's payer half, not a separate hook. The cart's LINES already merge;
 * this is what keeps the person paying for them the same person who will see
 * the order afterward.
 *
 * The discarded guest payer is not deleted — it may have a provider customer
 * with a saved card nobody will use, which is harmless and cheaper to leave
 * than to hunt down.
 */
public function reassignCartPayer(EcommerceCart $cart, SiteUser $user): void
{
    $accountPayer = $this->payers->resolveForSiteUser($user);      // 05 §5.5's link, or create on first purchase
    if ($cart->payer_reference_num !== $accountPayer->reference_num) {
        $cart->payer_reference_num = $accountPayer->reference_num;
        $cart->save();
    }
}
```

Events and appointments have the same shape wherever a form can be started as a guest and
finished signed in — a hold, a draft registration — and call the equivalent reassignment at
the same point their own sign-in merge happens.

## 5.3 The SDK

The customer resolver the connections plan introduced
([`../connections/07`](../connections/07-direct-payments-connection.md) §7.3) changes what it
returns. Its shape, its failure behaviour and its reason for existing are unchanged.

```ts
/**
 * AFS_BILLING_MODIFIER_ADDITION — plans/www/direct_payments/05-implementer-contract.md §5.3.
 *
 * BEFORE: resolve() returned a provider customer id, so the host page had to
 * hold a value that dies when the tenant changes provider.
 *
 * AFTER: it returns a PAYER reference. The SDK hands that to Direct Payments,
 * which maps it to the right provider customer for whichever processor the
 * Direct Payment is bound to right now (02 §2.3). Neither the page nor the
 * module ever sees a `cus_…` again.
 */
export interface DirectPaymentsPayerResolver {
    /** The payer this checkout is for, or null to let DP create an anonymous one. */
    resolve(dpRef: string): Promise<{ payer_reference_num: string } | null>;
    /** Saved methods for this payer on the DP's ACTIVE binding. Never lists a retired binding's cards. */
    paymentMethods(dpRef: string): Promise<DirectPaymentsSavedMethod[]>;
}

/** Install (or clear) the resolver for this Direct Payment instance. */
useCustomerResolver(resolver: DirectPaymentsPayerResolver | null): void;
```

`adopt()` disappears from the interface. It existed so a host page could tell Direct Payments
which provider customer a charge actually used, after the provider had possibly recreated it
— and that is now Direct Payments' own bookkeeping, inside `customerFor()`
([02](02-payers-and-bindings.md) §2.3), where it belongs. One fewer round trip and one fewer
thing a page can get wrong.

**A guest checkout installs no resolver**, exactly as today. Direct Payments creates or
matches a payer from the email on the invoice ([02](02-payers-and-bindings.md) §2.2) and
returns its reference on the charge result, so the module can store it.

### Server side

```php
// The checkout path, unchanged in shape:
$payer    = $payers->resolveForCheckout($dpRef, $payerRef ?? null, $email, $name);
$customer = $payers->customerFor($payer->reference_num, $dpRef);   // creates at the provider on first use
// …charge $customer->provider_customer_id on the binding's processor…
```

`InvoiceSnapshot` gains `payerReference`, so a façade reading an invoice can store the payer
without a second call. `PaymentGateway`'s four method signatures are unchanged.

## 5.4 Ecommerce, events, appointments

Each is one column, one write, and one read. The registry-style summary
[`../connections/02`](../connections/02-architecture.md) §2.9 uses:

| Step | ecommerce | events | appointments |
|---|---|---|---|
| Add `payer_reference_num` | `laravel_ecommerce_orders`, `_carts` | `laravel_event_orders` | `laravel_appointment_bookings` |
| Write it at checkout | from the charge result, beside the three refs it already stores | same | same |
| Read it | "everything this person bought here" without joining a provider customer | same | same |
| Remove | nothing | nothing | nothing |

**None of them changes a line of provider-facing code**, because none of them had any. That is
the test of whether this plan worked.

## 5.5 Site Users

The biggest simplification, and the one that fixes a layering violation.

```
BEFORE  laravel_site_user_customer_links
          site_user_reference, processor_reference_num, provider, provider_customer_id
          → one row per user PER PROCESSOR, holding a provider-issued value

AFTER   laravel_site_user_payers
          site_users_id, site_user_reference, payer_reference_num, linked_by, linked_at
          → ONE row per user, holding a Direct Payments value
          unique(payer_reference_num)        one payer belongs to one site user
          unique(site_users_id, site_user_reference)
```

Consequences, each an improvement:

- **Site Users stops storing a provider identifier entirely**, which is what
  [`../connections/02`](../connections/02-architecture.md) §2.6's layering rule always wanted
  and never quite got.
- **A swap writes nothing to Site Users.** The link is to the payer; the payer's customers
  change underneath it.
- **`GET /me/customers` disappears** and is replaced by `GET /me/payer`, returning
  `{ payer_reference_num }`. The saved-card and purchase endpoints
  ([`../connections/07`](../connections/07-direct-payments-connection.md) §7.5, §7.6) resolve
  through the payer instead of iterating per-processor links, and get shorter.
- **The `email_match` reconciler** ([`../site_users/08`](../site_users/08-direct-payments-link.md)
  §8.5) matches a user to a **payer** rather than to a processor customer — one match instead
  of one per processor, and its three rules are unchanged.
- **An email change** ([`../site_users/13`](../site_users/13-identity-and-email-change.md)
  §13.4.3) is one call from Site Users into Direct Payments — `PayerService::updateEmail(payerRef,
  email, name)` — not a loop Site Users runs itself:

  ```php
  /**
   * AFS_BILLING_MODIFIER_ADDITION — plans/www/direct_payments/05-implementer-contract.md §5.5.
   *
   * Updates the payer's own email row, then every ACTIVE payer_customers row —
   * never a superseded one, which nobody charges anymore — through the
   * processor-layer service. Best-effort and logged per processor: the address
   * is already the user's login, and one provider timeout must not block the
   * others or leave the account half-changed.
   */
  public function updateEmail(string $payerRef, ?string $email, ?string $name): void
  {
      $payer = Payer::where('reference_num', $payerRef)->firstOrFail();
      $payer->update(['email' => $email, 'name' => $name]);

      foreach (PayerCustomer::where('payer_reference_num', $payerRef)->where('status', 'active')->get() as $pc) {
          try {
              $this->processorCustomers->updateEmail($pc->processor_reference_num, $pc->provider_customer_id, $email, $name ?? '');
          } catch (\Throwable $e) {
              Log::error('[direct_payments] provider customer email not updated', [
                  'payer' => $payerRef, 'processor' => $pc->processor_reference_num, 'error' => $e->getMessage(),
              ]);
          }
      }
  }
  ```

  Site Users' side of the call is [`../connections/07`](../connections/07-direct-payments-connection.md)
  §7.10.

The migration is a straight fold: for each existing link row, find-or-create the payer for
that site user, then create a `payer_customers` row from the link's
`(processor, provider, provider_customer_id)`. No provider is called; the data is already
there.

## 5.6 What an implementer must never do

The rules a reviewer can check without reading this document:

| Never | Because |
|---|---|
| Store, log or display a `cus_…` / Square customer id / PayPal payer id | It dies at the next swap, and a module holding one is a module that will break |
| Read `laravel_direct_payments.processor_reference_num` | It is a derived cache during the deprecation window and gone after ([02](02-payers-and-bindings.md) §2.4). `BindingRegistry` is the reader |
| Call a provider directly | Unchanged rule; the processor layer is the only caller ([`../ecommerce/03`](../ecommerce/03-architecture.md) §3.2) |
| Resolve a processor for an existing object from the Direct Payment | [04](04-history-refunds-and-webhooks.md) §4.1's invariant, and the grep gate that enforces it |
| Assume a payer has a saved card | After a swap, none do ([03](03-the-swap.md) §3.5) |

## 5.7 Acceptance

- `grep -rn "cus_\|provider_customer_id" app/Externals/Ecommerce app/Events app/Externals/Appointments` is empty.
- An order placed before a swap and one placed after both resolve to the **same**
  `payer_reference_num`.
- A module's tables are not written by a swap — asserted by checksumming them across one.
- `Portal.directPayments` exposes no method returning a provider customer id to a page.
- Site Users holds no provider identifier:
  `grep -rn "provider_customer_id" app/SiteUsers app/Models/Externals/SiteUsers` is empty after the fold.
- A guest checkout with an email produces a payer, returns its reference, and a second
  checkout with the same email on the same site reuses it.
- A signed-in checkout never adopts a guest payer, even when the emails match
- A cart begun as a guest and finished after signing in mid-checkout produces an order whose
  `site_user_reference` and `payer_reference_num` agree — never one naming the account and the
  other naming the discarded guest payer (D11)
  ([02](02-payers-and-bindings.md) §2.2).
