# 05 — Add and purchase flows: completion report

> **Moved 2026-10-23** from `domains_deploy/new_way/` to `domains_deploy/domain_name_management/` when the plan was split into domain names and website deployment. The text is unchanged; step, section and finding numbers are the same.

> **Status: the Brain side is built and tested (P0, and the P1 items listed in §1). The browser, the HTTP routes and the live registrar / processor are not.** Carried out
> 2026-10-08 against [05-add-and-purchase-flows.md](../05-add-and-purchase-flows.md), on [02](02-domain-identity-and-collisions.complete.md), [03](03-data-model.complete.md) and
> [04](04-dns-zone-engine.complete.md). Nothing is committed. The code is in `w/www` (the Brain); `members` is untouched by this step.
>
> This document says what was actually built, including where it differs from the plan. For questions about current behaviour it supersedes the plan file.

## 1. What the step delivers

**Buying a domain is a saga owned by the Brain**, not three browser calls (audit F34). Every purchase is a stored row; the browser, the scheduled reconciler or a restart can resume it.

```
 search ─▶ quote (held by the server) ─▶ intent (name lock + invoice for the SERVER price) ─▶ awaiting_payment
    paid? asked of billing, never of the browser ─▶ paid ─▶ registering ─▶ registered ─▶ [ns_retry ─▶] nameservers_set ─▶ active  (= zone + sites name published by step 04)
    └ unpaid in 30 min ─▶ abandoned          └ registrar refuses / name not ours ─▶ refunding ─▶ failed (money back)       (a name we own is never refunded)
```

| Plan section | Built as |
|---|---|
| §5.1 connect | the claim API already existed (02). New: `ClaimInstructions` — the response's `steps[]` (the TXT to publish, the optional nameserver move with its "records you served elsewhere are lost" warning, and for external DNS the exact records to create and the "update `@` if the site moves" warning); a contested claim is shown the same steps as a pending one |
| §5.2 steps 1–2 search, intent | `DomainPurchaseService::search()` / `intent()` — names a claim holds or someone is buying are *unavailable* whatever the registrar says; a re-quote at the registrar and a **`requote`** answer when the price moved; idempotent per `(member, key)`; the name lock (`purchasing` claim, 02 §2.11); the invoice is created **on the server** for the quote's price (F36) |
| §5.2 step 3 payment | **D10 answered: in-process lookup.** The Brain is the Direct Payments host, so the saga asks `PaymentGateway::snapshot()` (which confirms with the processor when the row lags). A browser that says "paid" changes nothing (tested). A payment of a different amount is refunded, never registered on |
| §5.2 steps 4–5 register, nameservers | registration looks the name up in **our** registrar account before and after any ambiguous answer, so a retry cannot buy twice; a failed nameserver call is retried with backoff and **never refunded**, and staff are told after `purchase_ns_attempts` |
| §5.2 steps 6–7 activate, finish | `DomainClaimService::completePurchase` (proof `registrar`, supersedes unproven claims, `site_ref`, the sites entry and zone publish through step 04); registrar, order, expiry, auto-renew stored on the claim; receipt notice; a notice to every displaced claim |
| §5.2 failure table + reconciler | all rows implemented and tested (§6); `php artisan domains:purchases` every five minutes |
| §5.3 registrar adapter | `RegistrarDriver` (`search quote register getDomain setNameservers setAutoRenew renew lock`), `NameComDriver`, `FakeDriver`; credentials read by the Brain only (`RegistrarCredentials`), environment from `domains.registrar_env` (F35) |
| §5.4 abuse limits | kill switch `domains.purchases_enabled`; purchases started per day (3 for an account with no completed purchase, 10 after); search rate limit; the 02 limits for claims remain |
| §5.5 releasing | `DomainReleaseService`: connected → at any time; purchased → **stops auto-renew and leaves at expiry** (`sweepExpired`), staff can remove at once with a reason |

## 2. Files

All paths under `C:\projects\vagrant\afs-eco\w\www`.

**Registrar** (`app/Services/Brain/Domains/Registrar/`) — `RegistrarDriver`, `RegistrarOffer`, `RegisterResult`, `RegisteredDomain`, `NameComDriver`, `FakeDriver`, `RegistrarCredentials`.
**Purchase** (`app/Services/Brain/Domains/Purchase/`) — `DomainPurchaseService` (the saga), `DomainReleaseService`, `PurchaseBilling` (interface) + `DirectPaymentsBilling`, `PurchaseNotifier` (interface) + `EventNotifier`.
**Other** — `app/Services/Brain/Domains/ClaimInstructions.php`; `DomainClaimService::present()` now returns `steps`; `app/Console/Commands/DomainsPurchases.php` (scheduled in `routes/console.php`);
`AppServiceProvider` binds the registrar by `domains.registrar_driver` (`namecom` | `fake`), billing and notifier; `config/domains.php` (the §5 keys).
**Database** — `database/migrations/2026_10_08_000005_add_purchase_tables_and_selection_columns.php` (`brain_domain_quotes`, `brain_domain_purchases`; also step 06's server columns).
**Tests** — `tests/Feature/Brain/Domains/Purchase/{RegistrarDriversTest, DomainPurchaseTest}.php`, `tests/Support/FakePurchaseBilling.php`.

## 3. Data model as built

- `brain_domain_quotes` — a price the member was shown, held by the server: `quote_id` (unique), member, name, price, currency, expiry (15 min).
- `brain_domain_purchases` — one row per saga: `idempotency_key` **unique** (`member|key`), the server price, `state`, `invoice_ref`, registrar order and price actually paid, `expires_at` of the registration,
  `attempts` / `next_attempt_at` / `last_error`, `refund_state` (`none | pending | refunded | failed`) and `refund_ref`, a timestamp per stage.
- States: `awaiting_payment · paid · registering · registered · ns_retry · nameservers_set · active` and `abandoned · refunding · failed`. `registering` is persisted while the registrar call is in flight, so a crash mid-call is found by the reconciler.

## 4. Where the build differs from the plan text

1. **No webhook for "paid" (D10).** The plan leaves it to the owner of billing; because the Brain hosts Direct Payments, option (b) costs nothing and was used. `plans/billing` needs no change.
2. **"Only the reconciler refunds" is "only the saga's refund step refunds".** The step runs from the reconciler *and* from the member's `confirm()` (so a refund is not delayed five minutes); there is exactly one function that refunds, and every refund is an event.
3. **A timed-out registration that is not in our account is refunded at once**, after one look-up — the plan says "the reconciler decides"; this is what it decides. An unreadable answer (`error`) is retried up to three times first. If the registrar cannot be asked at all, nothing is refunded and the saga waits.
4. **The fake registrar has three more behaviours than the plan lists** (`-taken`, `-slow`, `-fail` are as planned, and `-slow` means "the call timed out but the name was registered behind it"): `-ghost` (timed out, nothing registered → refund), `-nsfail` (the first nameserver call fails → retry, no refund), `premium…` (99.99). Without them two rows of the failure table cannot be exercised.
5. **Quotes are rows**, not signed tokens: simple to expire, tie to a member and a name, and audit.
6. **The daily cap** is the plan's "until the account has history": 3 starts a day until one purchase has completed, then 10 (both configurable). A per-IP limit is **not** built — the services have no request; it belongs to the routes (step 08).
7. **Receipts and notices are events, not mail.** `EventNotifier` writes `purchase_receipt`, `purchase_refund_notice`, `claim_superseded_notice`, `purchase_needs_attention` to the claim's audit trail and the log; the Brain mail surface replaces one binding when it is wired.
8. **Registrant contacts (D6) are not collected or sent.** The register request carries the domain, the server price and the years — the former code's body. Whether Name.com accepts that for every TLD is unverified (§6), and who is registrant of record is still the owner's call.
9. **Name.com's production base URL is configuration, not knowledge.** The reference page this was written against lists the paths and shows only the sandbox host; `domains.namecom_base_url_production` defaults to `https://api.name.com` and must be confirmed before the first real purchase (the plan says the same).
10. **Not in this step although §5.4/§5.7 mention them:** the website-count hook points (`canUse` / `consume` / `release`) and the server-side count (D11) belong to the deploy endpoint (step 07); the renewal job (who pays for a renewal is unsaid — D12 covers release and refunds only); record import, transfer-in and a second registrar are P2.

## 5. What is not built (and why)

- **No HTTP routes and no browser.** Search, intent, confirm, status, release and the connect screens are the signed surface and the members UI (**step 08**). Until then `members`' own `search()` / `purchase()` still call `api.dev.name.com` with credentials fetched from the Brain's `/config` (audit F33–F35), and the browser still charges before it buys (F34). Nothing in this step turns those off.
- **No live registrar run and no live processor.** `NameComDriver` is tested against faked HTTP only; `DirectPaymentsBilling` (invoice creation, `snapshot`, refund through `RefundService`) is **not exercised against a processor** — the lab has no processor account and `DOMAINS_PURCHASE_DIRECT_PAYMENT_REF` (the Direct Payments product the invoices belong to) is not set anywhere. The saga is tested through the `PurchaseBilling` seam.
- **Renewals and expiry sync** (§5.7 P1): the registration's expiry is stored and used for release-at-expiry; nothing renews it or refreshes it from the registrar.
- **Account closure** (`plans/account_close`) is expected to call `DomainReleaseService::request` per claim; it does not yet.

## 6. Verification

Run against the real lab MariaDB (`10.0.0.243`, the throwaway `testdb_phpunit_www`; no SQLite). Registrar, billing and DNS lookups are the fakes described above.

| Result | |
|---|---|
| `RegistrarDriversTest` | **9 tests, 66 assertions, all passing** — Name.com's paths and bodies, the host and credentials from configuration (not the request host), every answer class (unavailable, declined, server error, rate limit, unreadable, connection loss) mapped, `getDomain` = null only on 404 and an exception otherwise (so "cannot ask" is never read as "not registered"), the credentials reader on the Brain's document, the fake's documented rules |
| `DomainPurchaseTest` | **21 tests, 162 assertions, all passing** — see below |
| Whole Domains folder + guardrail, one run | **179 tests, 3,087 assertions** (step 02: 44, 03: 9, 04: 74, 05: 30, 06: 18, `MigrationOwnershipTest`: 4); one test is skipped by design (the opt-in lab DNS test). One run found a wrong expectation about the ORDER of an array in the concurrency test (a test defect, not a behaviour); it was fixed and that file re-run: 18 of 18 pass |

What `DomainPurchaseTest` proves, against the plan's failure table: search hides held names and issues quotes · the intent takes the name lock and bills the server price · the same key is the same intent (one invoice) and a second buyer is refused · a moved registrar price returns `requote` and creates nothing · quotes expire and cannot be used by another member or for another name · kill switch, daily cap, and an invoice failure that releases the lock ·
**happy path to an active claim** (proof `registrar`, `site_ref`, registrar fields, nameservers at the registrar, zone and sites jobs queued, the full event trail) · **a browser that claims "paid" moves nothing**, and another member's purchase is a 404 · supersession of an unproven claim with a notice · unpaid → abandoned and the name is free again ·
wrong amount → refund, nothing registered · registrar declines → automatic refund and a refund notice · timeout that registered behind our back → continues, **no second purchase, no refund** · timeout that registered nothing → refund · nameserver failure → `ns_retry` with backoff, then active, **never refunded** · refund failing three times → staff told, then refunded · browser closed after paying → the reconciler finishes it ·
release of a purchased domain stops auto-renew, leaves the claim active, and releases at expiry; no request → never released by the sweep; staff removal needs a reason; a connected domain goes at once · the `steps[]` for pending, contested, external-active.

**Defects the tests found and that were fixed:** the quote-expiry and abandonment checks read the real clock instead of the application's (`time()` instead of `now()`); the fake registrar was `final` so a test could not make it change its price; the test double for HTTP kept the first stub (Laravel keeps the first matching `Http::fake`).

**Not verified, honestly:** a real Name.com call (paths are from the reference, the production host and registrant-contact requirements are not); a real refund through a processor; `DirectPaymentsBilling` against a configured Direct Payments product (**corrected 2026-10-09, `complete/00_extras.md` E11:** the Brain no longer creates the invoice — the browser builds it with the SDK; `createInvoice` was removed).

## 7. Applied to the lab

Migration `…000005` was applied to the lab database (`testdb_appfactorystudio`) after a copy of `brain_http_servers` (`brain_http_servers_backup_20261008b`); it adds the two purchase tables and step 06's column (06 completion report §7). Nothing else on the lab changed for this step; the lab's `.env` already has `DOMAINS_REGISTRAR_DRIVER=fake` and **`DOMAINS_PURCHASE_DIRECT_PAYMENT_REF` is not set** (`DOMAINS_PURCHASES_ENABLED` defaults to on) (a purchase on the lab will be refused with `DOM_E_PURCHASE_NOT_CONFIGURED` until a Direct Payments product is chosen).

## 8. To do next, in order

1. **Choose the Direct Payments product the domain invoices belong to** (`DOMAINS_PURCHASE_DIRECT_PAYMENT_REF`) and, in the lab, a processor in test mode; then run one purchase end to end with the fake registrar, including a refund.
2. **Step 08:** the signed routes for search / intent / confirm / status / release and the connect screens; remove the browser-orchestrated purchase, the `/config` fetch of registrar keys, and the `api.dev.name.com` calls from `members`.
3. **Before the first real purchase:** confirm Name.com's production host and whether registrant contacts must be sent (**D6**), run `NameComDriver` against the sandbox with a sandbox account, and decide the refund / release policy (**D12**).
4. **Step 07:** call the website-count hooks and the server-side count (D11); wire `plans/account_close` to `DomainReleaseService::request`.
