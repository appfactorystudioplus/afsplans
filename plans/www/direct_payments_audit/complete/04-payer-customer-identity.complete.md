# 04 — Payer and Customer Identity (complete — server side; the SDK half belongs to step 08)

## Status

**Complete 2026-10-17 for everything this step puts on the server.** One acceptance line is deliberately not met here and is listed first
so it is not missed: **the SDK still stores and sends a provider customer id** (`afs_customer_id`, grep gate G1 finds 12 lines in
`resources/js/external`). Removing that is the SDK rewrite of [08](../08-sdk-and-implementer-contract.md) §8.2, which this step's server half
makes possible: the server can now resolve a customer from a payer token or a signed-in identity, so the SDK has nothing left to send.
Old SDK builds keep working through the customer endpoints, which stay for one release as the plan says. Nothing is committed.

Verification: on MariaDB 11.4.5 (`C:\Users\mrmit\mariadb`), `tests/Feature/{DirectPayments,Processor,SiteUsers,Connections,Ecommerce,Events,
Membership}` — **605 tests, 2403 assertions, all green** (one run covers steps 04 and 05).
`tsc --noEmit` clean for both `w/www` and the external SDK project.

## Phases

| Phase | What shipped | Findings |
|---|---|---|
| P0 — schema | `laravel_direct_payment_payer_customers` + `source` (`created` / `recorded` / `imported` / `recreated` / `migrated`) and `superseded_reason` (`processor_swapped` / `provider_missing` / `vault_imported` / `payer_merged` / `operator`), straight into the create migration | §4.2 |
| P1 — one writer | `PayerService` is the only writer of the payer → customer map **and** of the processor-layer cache (`laravel_processor_customers`): `recordProcessorCustomer`, `processorCustomerForEmail`, `fillProcessorCustomerEmail`. The customer controller and the subscription controller write through it. Grep gate G6 is clean | A27 |
| P2 — browser can no longer rewrite a mapping | `saveInvoice` no longer records the browser's `customer_id`; it may only be **adopted** (`adoptCustomerIfAbsent`): the cache must know the id, the payer must have none on that processor, nobody else may hold it. `POST /customers/update-email` only fills an anonymous customer the cache already holds (404 for an unknown id, 409 if an e-mail is already set). `create-subscription` refuses a customer id the cache never saw (`CUST_E_UNKNOWN`) | A24, A23 (server side) |
| P3 — recovery | Stripe's "customer missing" recreation calls `PayerService::replaceProviderCustomer`: the old row is superseded with `provider_missing` and **kept**, the new row is active (`source = recreated`), the cache follows — one transaction. It used to overwrite the id in place | A25 |
| P4 — who is paying | `PayerToken` (HMAC over payer + issue time, 180-day expiry, revoked by rotating the application key). `resolveForCheckout` now takes a `payerToken`, matches an e-mail **only against guest payers** (`created_by` not `site_user` / `afs_signup`), and gives a guest who types an identity's e-mail a payer of their own. `saveInvoice` resolves the payer from the invoice's existing payer or the token — never from a `payer_reference_num` in the body — and returns a fresh `payer_token`. A guest payer whose e-mail was empty gets it at checkout, at every active provider customer (wires `updateEmail`; Site Users and the signup step already called it) | A28, A29, §4.5 |
| P5 — identity chain | `PayerIdentity` contract, `PayerIdentityChain`, `TokenPayerIdentity`, and `SiteUsersPayerIdentity` (on the Site Users side, so Direct Payments reads no Site Users table). `ServerCustomerResolver`: a charge / session / setup-intent / subscription request that names no customer gets the caller's payer customer on that processor, created at the provider on first use | A23 (server side) |
| P6 — PayPal | `ProcessorCustomerGateway` no longer returns `"paypal:{email}"`. A PayPal payer has no customer until a vaulting call returns one; the vault-setup call is made without a customer, PayPal's `customer.id` is returned and recorded (`PayerService::recordVaultCustomer`, `source = created`), later calls pass it back. Site Users' vault endpoints and the new payer endpoints both do this. One PayPal client id: `publishable_key` (three readers of `extra_config.paypal_client_id` moved) | A26, A44, A45 |
| P7 — saved cards | New payer-scoped `GET/POST …/directpayments/{dp}/me/payment-methods`, `…/setup`, `…/paypal/confirm`, `POST …/{method}/default`, `DELETE …/{method}`: the payer comes from the identity chain, the processor is the Direct Payment's **active** binding, and a method acts only when it is in the payer's own list. The old processor-level delete / set-default routes gained the same ownership check. Site Users' card list shows active customers on the processors the site charges through now; cards on an account it no longer uses come back separately (`other_accounts_methods`, `delete_only`) | A39, A59 |
| P8 — swap | `direct_payments:import-customer-map` carries each payer's **default card** across when the CSV has the two optional method columns (`old_pm,new_pm`); customers are written `source = imported`, `superseded_reason = vault_imported` | §4.7 |
| P9 — tests | 5 new classes, 32 tests (below) | — |

## Files

**New:** `app/Externals/DirectPayments/{PayerToken}.php`, `Contracts/PayerIdentity.php`, `Identity/{PayerIdentityChain,TokenPayerIdentity,ServerCustomerResolver}.php`,
`app/SiteUsers/SiteUsersPayerIdentity.php`, `app/Http/Controllers/Externals/DirectPayments/DirectPaymentPayerMethodsController.php`.

**Changed:** `PayerService`, `DirectPaymentPayerCustomer`, `DirectPaymentsController` (`saveInvoice`), `ProcessorCustomerController`, `ProcessorSubscriptionController`,
`ProcessorIntentController`, `ProcessorCustomerGateway`, `ProcessorPaymentMethodService`, `ProcessorBillingController`, `SiteUsersPaymentsController`,
`DirectPaymentsImportCustomerMap`, `AppServiceProvider` (binds the identity chain), `routes/portal.php`, the Direct Payments create migration.

## Tests (`tests/Feature/DirectPayments/`)

`PayerIdentityTest` (10), `PayerCustomerSingleWriterTest` (9), `PayerPaymentMethodsTest` (6), `PayPalVaultCustomerTest` (5), `ImportCustomerMapDefaultsTest` (2), plus
changes to `SaveInvoiceRecordsPayerTest` (a browser customer id is no longer recorded) and `ProcessorPaymentMethodSecurityTest` (ownership; a new test for a method that is
not the customer's own).

## Acceptance (§4.8)

| Criterion | Result | Evidence |
|---|---|---|
| No SDK code path stores or sends a provider customer id (G1) | ❌ — step 08 | 12 lines remain in the SDK; the server no longer needs them |
| A guest typing a Site User's e-mail gets a guest payer; the user's customer is untouched | ✅ | `test_a_guest_who_types_a_site_users_email_gets_a_guest_payer_not_the_users` |
| A forged `customer_id` on any public endpoint changes no `payer_customers` row | ✅ | `test_a_forged_customer_id_on_the_invoice_endpoint_changes_no_mapping`, `…_an_unknown_customer_id_cannot_start_a_subscription`, `…_update_email_endpoint_only_fills_an_anonymous_customer` |
| Stripe's "customer missing" recovery supersedes the old id (`provider_missing`) and activates the new one | ✅ at the service; the controller's live Stripe call is not exercised | `test_stripes_customer_missing_recovery_supersedes_the_old_id_and_keeps_it` |
| After Stripe → PayPal, a returning payer's first vault creates exactly one PayPal row | ✅ | `test_the_first_vault_records_paypals_customer_once_and_later_calls_reuse_it` |
| `GET …/me/payment-methods` after a swap lists nothing from the old processor | ✅ | `test_a_payer_sees_only_the_cards_on_the_active_processor_after_a_swap` |

## Residue and notes

- **PayPal Vault v3 is unverified against PayPal.** That a setup token created without a `customer` returns `customer.id` follows PayPal's documentation (the plan itself says not to rely on a merchant-chosen id); the tests fake PayPal. Confirm in a sandbox before taking real PayPal vault payments.
- **The legacy processor-level payment-method routes still exist** (ownership-checked now, but still unauthenticated and still taking a customer id). The plan removes them after one release; that removal waits for the SDK change.
- **The customer endpoints (`create-anonymous`, `find-or-create`, `update-email`) stay one release** for older SDK builds.
- **`MembershipController` (appfactory)** still calls `recordExistingCustomer` with a customer id from its own request; that is appfactory's code and belongs to [14](../14-appfactory-studio-modifications.md).
- Guest payers with no e-mail are not merged into one another; a returning guest on another device is a new payer (by design — OQ8).
