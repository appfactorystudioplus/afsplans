# 07 — The Direct Payments Connection

Saved payment methods that follow the account, a provider customer reused across devices, and
one place to see every purchase. This is the read side [site_users/08](../site_users/08-direct-payments-link.md)
deferred, plus the endpoints that make it safe.

> **Rewritten 2026-09-12 against [`../direct_payments/`](../direct_payments/README.md).** The
> connection this document sets up is unchanged — a Direct Payments module linked to a Site
> Users module. What that connection *carries* changed underneath it: Site Users now hands
> the checkout a **payer reference**, minted and owned entirely by Direct Payments, instead of
> a raw provider customer id it once helped manage. Every code sample below reflects that; the
> concepts and acceptance criteria are the same ones this document has always argued for.

## 7.1 What "connected" means for Direct Payments

A `direct_payments` connection row (module-wide `*`, or per Direct Payment) means three
things, all gated on the row and none on a checkbox (K10).

> **All three providers mint a customer.** Stripe, Square and PayPal each return a
> `provider_customer_id`, and `laravel_processor_customers` already stores one per
> `(processor, provider, mode)`. That is what makes a Direct Payments **payer** a universal
> join — one payer, many provider customers underneath it, one per processor it has ever paid
> on ([`../direct_payments/02`](../direct_payments/02-payers-and-bindings.md) §2.3) — and, for
> a purchase Direct Payments charged with no façade record behind it (a donation, a DP block,
> a subscription), **the only** join back to a site user
> ([`../site_users/13`](../site_users/13-identity-and-email-change.md) §13.5). The payer keys
> on the provider customer, never on an email address, which is why it survives both a
> provider swap and the email change §7.10 makes possible.

1. **A signed-in checkout charges a provider customer that belongs to the account** — the
   payer's existing customer on that processor, or a new one Direct Payments creates *with the
   user's email* before the charge (K11).
2. **Saved payment methods are readable and manageable from the Dashboard**, through
   ownership-checked endpoints (K12).
3. **The Dashboard gains a Payments group**: *Cards* and *Purchases*.

Guest checkout does not apply to this module — a Direct Payment has no checkout of its own;
it is charged *through* a store, an event or a calendar whose own connection decides the
guest question.

## 7.2 Why the prerequisite (X1) ships alone, first

`ProcessorBillingController::resolveProcessorAndCustomer()` accepts a local integer
`customer_id` on unauthenticated routes. Whatever this plan builds on top, that door has to
close, and it closes independently:

```php
/**
 * AFS_BILLING_MODIFIER_ADDITION — plans/www/connections/07-direct-payments-connection.md §7.2 (X1).
 *
 * The public billing routes are keyed by PROVIDER customer id only — a
 * high-entropy string the caller can hold only if the provider handed it to
 * them. The local integer id is accepted from admin-authenticated callers only.
 */
private function resolveProcessorAndCustomer(Request $request): array
{
    $processorId = $request->input('processor_reference_num') ?? $request->input('processor_id');
    $customerId  = (string) $request->input('customer_id', '');
    // …
    $customer = null;
    if ($this->isAdminAuthenticated($request) && ctype_digit($customerId)) {
        $customer = ProcessorCustomer::find((int) $customerId);
    }
    $customer ??= ProcessorCustomer::query()
        ->where('provider_customer_id', $customerId)
        ->where('provider', $processor->provider)
        ->where('processor_reference_num', $processor->reference_num)
        ->first();
    // …
}
```

Plus `ApiAuthMiddleware` on `GET /api/v1/processors/customers` (**X2**).

**X3** needs no fix, only a deletion: `GET /billing/payments` does not exist and never did,
so `ProcessorsAPI.getPaymentMethods()` throws on every call today
([01](01-current-state-audit.md) §1.2). It is removed from the SDK in the same pass; §7.5's
`/me/payment-methods` is the first working saved-card list this estate has had, which is why
nothing depends on the old shape.

This is **K0** in [11](11-rollout-and-testing.md): no Site Users dependency, no schema, one
test each.

## 7.3 The payer resolver — the third door (K11)

> **This section is now owned by [`../direct_payments/05`](../direct_payments/05-implementer-contract.md)
> §5.3 (2026-09-12).** An earlier draft of this plan had the interface return a raw provider
> customer id, with a client-called `adopt()` reconciling whatever the provider actually used.
> Both are gone. Direct Payments now issues the identity itself — a **payer reference** — and
> resolves which provider customer that payer maps to entirely on its own side
> ([`../direct_payments/02`](../direct_payments/02-payers-and-bindings.md) §2.3). There is
> nothing left for a client to reconcile, because the client is never handed a provider id to
> reconcile in the first place.
>
> What stays true here, and is why this section still exists rather than being deleted
> outright: the Direct Payments SDK remains a standalone product that does not import Site
> Users. It accepts a small interface — `DirectPaymentsPayerResolver`
> ([`../direct_payments/05`](../direct_payments/05-implementer-contract.md) §5.3) — and Site
> Users is simply the one implementation of it that ships in this estate. A developer wiring
> their own account system implements the same two methods and gets the same behaviour. Read
> the linked section for the interface itself; §7.4 below is Site Users' implementation of it.

## 7.4 Site Users implements the resolver

```ts
// external/api/site_users/SiteUsersClient.ts

/**
 * AFS_SITE_USERS_MODIFIER_ADDITION — plans/www/connections/07-direct-payments-connection.md §7.4.
 *
 * The signed-in user's payments identity, for handing to
 * `Portal.directPayments.create(ref).useCustomerResolver(...)`
 * (direct_payments/05 §5.3's DirectPaymentsPayerResolver). One call, no
 * processor argument on resolve() — a payer is not processor-scoped, that
 * mapping is Direct Payments' own problem (direct_payments/02 §2.3).
 */
paymentsResolver(): DirectPaymentsPayerResolver {
    return {
        resolve: async (_dpRef) => {
            const json = await this.authedJson(`${this.baseUrl}/me/payer`, { method: 'GET' });
            return json.connected ? { payer_reference_num: json.payer_reference_num as string } : null;
        },
        paymentMethods: async (processorRef) => {
            const json = await this.authedJson(`${this.baseUrl}/me/payment-methods?processor=${encodeURIComponent(processorRef)}`, { method: 'GET' });
            return (json.payment_methods ?? []) as DirectPaymentsSavedMethod[];
        },
    };
}
```

The storefront wires it in one line, only when signed in and only when the instance's block
says the account exists ([06](06-checkout-guard.md) §6.6):

```ts
const dp = window.Portal.directPayments.create(dpRef);
if (session) dp.useCustomerResolver(session.su.paymentsResolver());
```

If the Direct Payments connection does **not** exist for the user's module, `GET /me/payer`
returns `{ connected: false }` and the SDK proceeds exactly as a guest checkout does — no
payer, a fresh one created on the invoice's email if any is known
([`../direct_payments/02`](../direct_payments/02-payers-and-bindings.md) §2.2). The storefront
does not need to know which case it is; the server is the switch.

## 7.5 The server — `SiteUsersPaymentsController`

> **Site Users holds one link, not one per processor**
> ([`../direct_payments/05`](../direct_payments/05-implementer-contract.md) §5.5):
> `laravel_site_user_payers` is `(site_users_id, site_user_reference, payer_reference_num)`,
> one row per user. Everything below the payer — which processor customer it maps to on
> which processor, the email-collision rule, the create-on-first-charge lazily — is **Direct
> Payments' bookkeeping**, not Site Users'
> ([`../direct_payments/02`](../direct_payments/02-payers-and-bindings.md) §2.2, §2.3). The
> old `customerFor()` below, keyed per processor with its own email-collision branch, is
> **deleted outright**, not simplified — there is nothing left in Site Users for it to do.

All under the existing `Route::middleware('site_users.session')` group at
`/portal/api/siteusers/public/me/*`. Every handler starts with the session user; the payer
lookup and every processor call go through Direct Payments' own services, which know nothing
about Site Users beyond the `(site_users_id, site_user_reference)` pair they are handed
(rule three of the README).

### `GET /me/payer`

```php
/**
 * AFS_SITE_USERS_MODIFIER_ADDITION — plans/www/connections/07-direct-payments-connection.md §7.5 (K11).
 *
 * The ENTIRE payments identity for this session, for any processor, any Direct
 * Payment. Not processor-scoped, because a payer isn't (direct_payments/02
 * §2.2's P9 — one payer per user per WEBSITE). Reads the link; creates one on
 * first call for a connected module, scoped to the caller's site — Direct
 * Payments never sees a request without a website to scope it to.
 */
public function payer(Request $request): JsonResponse
{
    $user = $request->attributes->get('site_user');
    if (! $this->connections->paymentsLinked($user->site_users_id)) {
        return response()->json(['success' => true, 'connected' => false]);
    }

    $link = SiteUserPayer::query()
        ->where('site_users_id', $user->site_users_id)
        ->where('site_user_reference', $user->reference_num)
        ->first();

    if ($link === null) {
        // resolveForSiteUser() NEVER adopts an existing guest payer on an email
        // match (direct_payments/02 §2.2) — it always mints a fresh payer for
        // an account's first payment.
        $payer = $this->payers->resolveForSiteUser($request->websiteReferenceNum(), $user->email, $user->display_name);
        $link = SiteUserPayer::create([
            'site_users_id' => $user->site_users_id,
            'site_user_reference' => $user->reference_num,
            'payer_reference_num' => $payer->reference_num,
            'linked_by' => 'checkout',
            'linked_at' => now(),
        ]);
    }

    return response()->json(['success' => true, 'connected' => true, 'payer_reference_num' => $link->payer_reference_num]);
}
```

There is no `POST` for this endpoint. A payer reference is never client-supplied — it is read
or it is minted, and both happen on the same `GET`.

### `GET /me/payment-methods?processor=`

```php
public function paymentMethods(Request $request): JsonResponse
{
    $user = $request->attributes->get('site_user');
    // READ side — paymentsVisible(), so a disconnected connection still lists the
    // cards a user needs to be able to remove (13 §13.6, X35).
    if (! $this->connections->paymentsVisible($user->site_users_id)) {
        return response()->json(['success' => true, 'connected' => false, 'payment_methods' => []]);
    }

    $link = SiteUserPayer::query()->where('site_users_id', $user->site_users_id)
        ->where('site_user_reference', $user->reference_num)->first();
    if ($link === null) {
        return response()->json(['success' => true, 'connected' => true, 'payment_methods' => []]);
    }

    // ONE call into Direct Payments for every processor this payer has ever
    // paid on — including SUPERSEDED payer_customers rows, filtered internally,
    // never a raw table this controller iterates itself.
    $methods = $this->payerCustomers->paymentMethodsFor($link->payer_reference_num, $request->query('processor'));

    return response()->json(['success' => true, 'connected' => true, 'payment_methods' => $methods]);
}
```

`App\Externals\Processor\ProcessorPaymentMethodService` — `list()`, `store()`, `delete()`,
`setDefault()` — is the extraction of `ProcessorBillingController`'s private
`fetchPaymentMethods`, `storeStripePaymentMethod` / `storeSquarePaymentMethod`, the delete
branches and `updatePaymentMethod`'s provider calls. It is called by Direct Payments'
`payerCustomers` service, never by this controller directly — Site Users hands over a payer
reference and gets back money-shaped facts, the same boundary
[`../direct_payments/02`](../direct_payments/02-payers-and-bindings.md) §2.6 draws for every
other consumer.

| Method | Path | Body | Ownership check |
|---|---|---|---|
| `POST` | `/me/payment-methods` | `{processor_reference_num, payment_method_id \| source_id}` | `paymentsLinked()` (active) **and** an active `payer_customers` row for `(payer, processor)`; the method is attached to **that** customer, never to a client-named one |
| `DELETE` | `/me/payment-methods/{methodId}?processor=` | — | the method must be in `list()` for the payer's customer on that processor — read-then-delete, so a foreign method id is `404` |
| `POST` | `/me/payment-methods/{methodId}/default?processor=` | — | same read-then-act |
| `POST` | `/me/payment-methods/setup` | `{processor_reference_num}` | returns what the provider needs to mount an add-card element (Stripe: a SetupIntent `client_secret` created **for the payer's customer** via the existing intent controller's logic, extracted; Square/PayPal: their equivalents per `ProcessorClient.setupPaymentMethod()`'s contract) |

The read-then-act rule is what makes `DELETE` safe without trusting any id from the client:
the server lists the payer's own methods and acts only on one of them.

## 7.6 Purchases — `GET /me/purchases`

One list of everything the account has paid for, across modules, with money resolved live
through `PaymentGateway::snapshots()` and **nothing** stored (K15's neighbour: the façade
rule).

```php
/**
 * AFS_SITE_USERS_MODIFIER_ADDITION — plans/www/connections/07-direct-payments-connection.md §7.6.
 *
 * Union of:
 *   1. façade records owned by the user — ecommerce orders, event orders,
 *      appointment bookings with an invoice ref — for instances whose connection
 *      is ACTIVE **or DISCONNECTED** (13 §13.4: disconnection is read-only, and a
 *      purchase a user made does not stop being theirs). Excluded only when the
 *      connection row was removed;
 *   2. DP invoices whose `customer_id` names a provider customer belonging to
 *      one of this PAYER's `payer_customers` rows (direct_payments/02 §2.3) and
 *      that no façade record above already names — a donation, a DP block, a
 *      subscription.
 * Money for all of them comes from PaymentGateway::snapshots() in ONE call.
 *
 * X23 — `laravel_direct_payment_invoices.user_id` is validated `nullable|string`
 * and set by the CLIENT (`saveInvoice()`). An earlier draft of this method also
 * matched `user_id = 'su:<reference_num>'`, which would let anyone stamp a
 * victim's reference on their own invoice and have it appear in that victim's
 * purchases. It discloses nothing of the victim's, but it is someone else
 * writing rows into a user's account view, so it is not a join this endpoint
 * makes. THE PAYER IS THE ONLY TRUSTWORTHY JOIN; `user_id` is display and
 * support metadata only.
 */
public function purchases(Request $request, SiteUsersPurchasesService $purchases): JsonResponse
{
    $user = $request->attributes->get('site_user');
    return response()->json(['success' => true] + $purchases->forUser($user, [
        'scope' => $request->query('scope', 'all'),      // all | orders | events | appointments | other
        'limit' => min(100, (int) $request->query('limit', 50)),
        'cursor' => $request->query('cursor'),
    ]));
}
```

Response row shape (the client's "purchase" is deliberately module-agnostic):

```json
{ "kind": "order", "reference_num": "ord-…", "label": "Order #1042 · Main Store",
  "module_key": "ecommerce", "instance_reference_num": "store-9f3k",
  "invoice_ref": "inv-…", "processor_reference_num": "pr-1234",
  "amount_cents": 8450, "currency": "usd", "status": "paid", "refunded_cents": 0,
  "paid_at": "2026-09-01T14:02:11Z", "placed_at": "2026-09-01T14:02:05Z",
  "manage": { "section": "ecommerce:store-9f3k", "view": "order", "ref": "ord-…" } }
```

`manage` tells the dashboard which section owns the actions (cancel, return, reschedule) —
the Payments section itself is read-only (K15). Invoices with no façade record get
`"kind": "invoice"`, a label from the invoice's `items_data` names, and `manage: null`.

### Stamping `user_id` on the invoice

The DP SDK's `saveInvoice()` already forwards `user_id`. When a resolver is installed, the
SDK sets `user_id = 'su:' + <payer reference>` — the `resolve()` call in §7.4 already returns
the payer reference directly, so no separate lookup is needed to stamp it.

**This is a support and reporting aid, not an authorisation fact.** It makes an operator
looking at an invoice able to see which payer made it, and it gives the Money ledger's
Customer column ([`../direct_payments/04`](../direct_payments/04-history-refunds-and-webhooks.md)
§4.6) something to resolve when the automatic join has not run yet. `/me/purchases` does
**not** join on it (X23 above), so a forged value can only mislabel the forger's own invoice
in an admin view — visible, bounded, and worth the operator value.

`getInvoices?user_id=` (**X4**) is **not** used by any surface here — the purchases view is
session-scoped — and stays flagged for billing's owner.

The cost of not joining on it: a purchase made **before** the user's payer had a customer on
that processor does not appear. In practice a payer's first payment on any processor creates
the mapping immediately ([`../direct_payments/02`](../direct_payments/02-payers-and-bindings.md)
§2.3), so the only purchases this misses are ones made **before the payer existed at all** —
guest purchases from before the shopper had an account — which is
[09](09-module-self-service.md) §9.7's problem, solved there for façade records with the same
three rules the §8.5 reconciler uses.

## 7.7 Subscriptions

`dp.subscription.*` resolves identity server-side today through
`CheckpointIdentityResolver` — a **member** concept ([direct_payments/28](../direct_payments/28-subscription-actions.md)).
A site user is not a member and never receives a Checkpoint token
([site_users/03](../site_users/03-architecture.md) §3.2). So tenant-site subscriptions
appear in *Purchases* as `kind: "subscription"` rows (read through the invoice's
`laravel_direct_payment_subscriptions` link when present) with **no actions** in this plan.
Cancel/update for a site user's subscription is a follow-up that needs the DP subscription
controller to accept a Site Users session as a second identity resolver — recorded in
[11](11-rollout-and-testing.md) §11.7, not built here.

## 7.8 `link_payment_customers` becomes derived (K10)

> **`linkCustomer()` and `siteusers:link-customers` no longer exist.** Both belonged to the
> per-processor `SiteUserCustomerLink` model, which
> [`../direct_payments/05`](../direct_payments/05-implementer-contract.md) §5.5 folds away
> entirely. There is nothing left to explicitly *link* — `GET /me/payer` (§7.5) creates the
> one row Site Users keeps, and every per-processor mapping underneath it is Direct Payments'
> own bookkeeping, created lazily and never through an action a client calls.

What survives from this section's original point — the checkbox, and what it gates:

- The Settings tab's checkbox is **removed**; its help text moves to the Direct Payments card
  in the Connections tab.
- `GET /me/payer` and `GET /me/payment-methods` both read `paymentsLinked()` /
  `paymentsVisible()` ([04](04-connection-registry-and-enforcement.md) §4.8,
  [13](13-disconnection.md) §13.6) — unchanged by this document's rewrite.
- `laravel_site_user_modules.link_payment_customers` (the legacy flag) is written through by
  the connection service for one release ([03](03-database-schema.md) §3.5), read by nothing
  new.
- The reconciler this section used to point at — `siteusers:link-customers` — **survives,
  re-targeted.** It matched an existing processor customer to a user by email; it now matches
  a **payer** to a user by email, one match instead of one per processor
  ([`../direct_payments/05`](../direct_payments/05-implementer-contract.md) §5.5), and its
  three rules — verified email only, unique on both sides, `linked_by = 'email_match'` forever
  distinguishable — are unchanged. What it does **not** do is run automatically: a guest who
  buys, then later creates an account with the same email, gets a fresh payer at their first
  signed-in checkout ([`../direct_payments/02`](../direct_payments/02-payers-and-bindings.md)
  §2.2's rule that a signed-in user never adopts a guest payer on a match), and reaching their
  guest purchase from the new account needs either an operator running the reconciler or the
  **façade** guest-claim service picking up the underlying order
  ([09](09-module-self-service.md) §9.7) — the same two routes that exist today, unchanged in
  number.

## 7.9 Rate limiting and caching the provider fan-out (X18)

`GET /me/payment-methods` issues **one provider HTTP call per linked customer** — Stripe's
`/v1/payment_methods`, Square's `/v2/cards`, PayPal's vault. `SiteUsersPublicThrottle`'s
`if/elseif` chain matches none of the `/me/*` paths, so today every one of them falls through
with an empty bucket list and is **unlimited**. An authenticated user could hold a dashboard
tab on refresh and turn one session into sustained load against the tenant's provider
account, which is rate-limited per *tenant*, not per visitor — so the failure lands on other
shoppers' checkouts.

Two changes, both required, neither optional:

```php
/**
 * AFS_SITE_USERS_MODIFIER_ADDITION — plans/www/connections/07-direct-payments-connection.md §7.9 (X18).
 *
 * The `/me/*` family gets buckets keyed on the SESSION, not the IP: these are
 * authenticated endpoints, several users legitimately share an IP (an office, a
 * mobile carrier), and the thing being protected is a per-tenant provider quota.
 * The IP bucket stays as a second, looser ceiling.
 */
// X38 — ORDER MATTERS. This is an if/elseif chain, so the FIRST match wins.
// The narrow `/me/email` bucket (site_users/13 §13.4.2) has to be tested before
// the generic `/me` one below, or it never fires and an email-change request is
// limited to 240 per five minutes instead of three per hour — which is a token
// mailed to an address of the attacker's choosing, 240 times.
} elseif (str_contains($path, '/me/email')) {
    $sessionKey = substr(hash('sha256', (string) $this->tokenFor($request)), 0, 32);
    $buckets[] = ["su-emailchange-user:{$sessionKey}", 3, 3600];
    $buckets[] = ["su-emailchange-ip:{$ip}", 10, 3600];

} elseif (str_starts_with($path, 'portal/api/siteusers/public/me')) {
    $sessionKey = substr(hash('sha256', (string) $this->tokenFor($request)), 0, 32);

    if (str_contains($path, '/payment-methods')) {
        // Provider fan-out. Deliberately tight.
        $buckets[] = ["su-me-pm:{$sessionKey}", 30, 300];
        $buckets[] = ["su-me-pm-ip:{$ip}", 120, 300];
    } else {
        // /me, /me/connections, /me/purchases, /me/sessions — local reads.
        $buckets[] = ["su-me:{$sessionKey}", 240, 300];
        $buckets[] = ["su-me-ip:{$ip}", 600, 300];
    }
}
```

Keying on the session token's hash rather than the user id keeps the middleware from
resolving a session (a database read) before deciding to reject — the token is already in the
request. `tokenFor()` is `SiteUsersSessionService::extractToken()`, already public.

And a short server-side cache on the read path, because the dashboard fetches cards on every
activation of that pane:

```php
// ProcessorPaymentMethodService::list()
return Cache::remember(
    "pm:{$processor->reference_num}:{$customer->provider_customer_id}",
    now()->addSeconds(60),
    fn () => $this->fetchFromProvider($processor, $customer),
);
```

**Sixty seconds, and every write busts it** (`store()`, `delete()`, `setDefault()` forget the
key). A card added in the dashboard must appear immediately; a card that was removed at the
provider's own dashboard may lag a minute, which is the right trade.

## 7.10 Email change, and the customer that follows it (X21 — now closed)

A site user **can** change their email:
[`../site_users/13`](../site_users/13-identity-and-email-change.md) §13.4 adds a
verify-before-switch flow, and the address must not already be in use inside that module.

The consequence for this document is one call, not a loop Site Users runs itself. **The payer
belongs to Direct Payments** ([`../direct_payments/02`](../direct_payments/02-payers-and-bindings.md)
§2.2), so propagating an email change to the provider customers it maps to is Direct Payments'
job — Site Users' confirm handler makes one call and moves on:

```php
/**
 * AFS_SITE_USERS_MODIFIER_ADDITION — plans/www/connections/07-direct-payments-connection.md §7.10.
 *
 * Site Users' side of the confirm (site_users/13 §13.4.3): resolve the payer
 * link, if any, and hand Direct Payments the new address. Everything past this
 * line — updating the payer's own email row, then every provider customer on
 * its ACTIVE payer_customers rows (never a superseded one, which nobody
 * charges anymore) — is Direct Payments' own concern, specified in full at
 * direct_payments/05 §5.5, not duplicated here.
 */
$link = SiteUserPayer::query()
    ->where('site_users_id', $user->site_users_id)
    ->where('site_user_reference', $user->reference_num)
    ->first();

if ($link !== null) {
    $this->payers->updateEmail($link->payer_reference_num, $user->email, $user->display_name ?? '');
}
```

`updateEmail()` is best-effort and logged on the Direct Payments side, for the same reason the
old per-link loop was: the email is already the user's login, and a provider timeout must
never leave the account half-changed.

### What is deliberately *not* rewritten

Order, registration and booking **email snapshots**. `customer_email`, `attendee_email` and
`user_email` hold the address in force when the purchase happened, and a reprinted receipt has
to match the receipt that was sent. Snapshots are the estate's rule everywhere
([ecommerce/08](../ecommerce/08-orders-and-fulfilment.md) §8.3), and this is no exception.

The **payer itself** is likewise unaffected in a way worth naming: it keys on
`payer_reference_num`, never on email, so a later change does not re-open any question about
which purchases belong to it. `payer_customers` keys on `provider_customer_id` for the same
reason ([`../direct_payments/02`](../direct_payments/02-payers-and-bindings.md) §2.3) —
neither is a value email matching ever touches again once the payer exists.

What this plan *does* do: `GET /me/payer` refuses to create a payer for a user with **no**
email (an SSO account that returned none) any further than the payer row itself — no provider
customer is minted until that payer actually pays, at which point Direct Payments creates one
with whatever email exists then, or none, per its own rule
([`../direct_payments/02`](../direct_payments/02-payers-and-bindings.md) §2.2).

## 7.11 Acceptance

- A signed-in shopper's first checkout on a processor creates **one** provider customer,
  linked to their payer with their email; the second checkout on any device — same processor
  or a different one — creates no new customer where one already exists, and the saved card
  from the first appears in the card element's saved-method list.
- Two accounts sharing an email address never share a payer, and never share a provider
  customer — the second gets its own fresh payer and its own fresh customer;
  `laravel_direct_payment_payer_customers` has two rows naming two different payers.
- A resolver that throws: the charge succeeds anonymously and **no** `payer_customers` row is
  written.
- `GET /me/payment-methods` without a session → `401`; with a session in a module whose
  Direct Payments is not connected → `connected: false, []`.
- `DELETE /me/payment-methods/pm_foreign` for a method that belongs to another customer →
  `404`, and the method is still attached at the provider.
- `GET /me/purchases` lists an ecommerce order, an event order and a booking with amounts
  equal to their invoices' `amount_cents`; a store since **disconnected still contributes its
  rows** (13 §13.4), and one whose connection was **removed** contributes none.
- The X1 fix: `DELETE /portal/api/v1/processors/billing/payment-methods` with
  `customer_id=1` and no admin session → `404` "Customer not found"; with the provider id →
  works as before.
- `grep -rn "SiteUser\|site_user" app/Http/Controllers/Externals/Processor app/Models/Externals/Processor app/Externals/Processor` is empty.
- An invoice carrying `user_id = 'su:<someone else>'` does **not** appear in that someone
  else's `/me/purchases` (X23).
- The fourth `PATCH /me/email` in an hour → `429`, i.e. the narrow bucket is reached before
  the generic `/me` one (X38).
- The 31st `GET /me/payment-methods` in five minutes on one session → `429` with
  `Retry-After`; a second card list within 60 seconds issues **no** provider call; adding a
  card makes it visible immediately (X18).
- A user with no email address still gets a payer from `GET /me/payer` — an identity, not a
  payment method — and no provider customer is created for them until they actually pay, at
  which point Direct Payments decides how to handle the missing address
  ([`../direct_payments/02`](../direct_payments/02-payers-and-bindings.md) §2.2).
- A cart begun as a guest and finished after signing in mid-checkout produces an order whose
  `payer_reference_num` names the **account's** payer, never the discarded guest one (D11 —
  [`../direct_payments/05`](../direct_payments/05-implementer-contract.md) §5.2).
