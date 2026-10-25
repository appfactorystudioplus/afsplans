# 11 — Rollout and Testing

## 11.1 Phases

Thirteen phases, **K0–K12**. Each is shippable on its own and leaves the deployment working.

| Phase | Contents | Depends on | Ships behind |
|---|---|---|---|
| **K0 — the prerequisite** | **X1**: `resolveProcessorAndCustomer()` stops accepting a local integer id from unauthenticated callers. **X2**: `ApiAuthMiddleware` on `GET /api/v1/processors/customers`. **X3**: delete `ProcessorsAPI.getPaymentMethods()` — its route does not exist, so it throws on every call | **nothing** | **Nothing. Not optional, not schedulable.** A live unauthenticated card-detach |
| **K1 — schema** | `laravel_site_user_connections` + model, **`laravel_site_user_mode_snapshots`** (X29 — captured before anything writes to the legacy columns), the backfill, `siteusers:connections-audit`, the test-schema helper, the four **container bindings** (X31 — [04](04-connection-registry-and-enforcement.md) §4.2.1), **`SiteUsersUserDeletion::detachAndDelete()`** (X20), and steps 1–2 of the **identifier migration** — `site_user_reference` added and backfilled on twelve tables, dual-write on ([`../site_users/13`](../site_users/13-identity-and-email-change.md) §13.3) | K0 (ordering only) | No reader yet — pure addition; the deletion change is a correctness fix on an existing admin action |
| **K2 — the registry** | `SiteUsersConnectionRegistry`, `SiteUsersConnectionService`, `SiteUsersDerivedColumns`, `currentUserFor()`, `modeFor()`/`assertAuthenticated()`/`attributableUserReference()` re-pointed, `publicBlock()`, `paymentsLinked()` | K1 | **The behaviour switch.** Backfilled rows make it a no-op for every tenant whose audit was clean |
| **K3 — enforcement** | Identifier migration step 3 (**read the reference**, keep writing both; `siteusers:reference-drift` must report zero for a release), the three modules' ungated writes (`checkout/complete`, holds, waitlists), `401` standardisation, the `module` hint, `mine` endpoints returning `401` + connection filter, `currentUserId()` callers migrated | K2 | Only bites in `required` mode, which no tenant has unless they set it |
| **K4 — admin** | The Connections tab, the seven admin endpoints, the module-form shortcuts, the Modules index line, removal of the store's mode select and the Settings checkbox | K2 | The first phase a tenant can see |
| **K5 — the public block + the gate** | `site_users` on the three public configs, `shared/authGate.ts`, `buildGateMarkup()`, `wireLoginForm()` extraction, the three storefront wirings, `PortalApiError`, the gate's optional `su` parameter and **`su_session` `secure: true` on HTTPS** (X19 — [06](06-checkout-guard.md) §6.8) | K2, K3 | `required` becomes usable, not just enforceable |
| **K6 — payments + guard isolation** | **`SiteUsersApiGuard` and `BuilderPublishedController` enforce their own `site_users_id`** (X27 — [04](04-connection-registry-and-enforcement.md) §4.10), `ProcessorCustomerService`, `ProcessorPaymentMethodService`, `SiteUsersPaymentsController`, `DirectPaymentsCustomerResolver` in the DP SDK, `paymentsResolver()`, checkout wiring, `link_payment_customers` derived, **the `/me/*` throttle buckets and the 60-second provider cache** (X18 — [07](07-direct-payments-connection.md) §7.9) | K0, K2 | Saved cards follow the account. **The throttle ships with the endpoints, not after.** The guard change is the one edit in this plan that can refuse a request which succeeds today — the audit reports the affected rows first |
| **K7 — dashboard shell** | `sections.ts`, `buildDashboardGroups()`, `buildDashboardMarkup(user, groups)`, `/me/connections` **with module-wide expansion** (X22 — [04](04-connection-registry-and-enforcement.md) §4.9), `SiteUsersDashboardService` + the four contributions, chrome selectors, canvas preview samples | K2 | Empty for an unconnected module — identical to today |
| **K8 — the four providers** | ecommerce (orders/returns/favourites), events (upcoming/past/ticket), appointments (upcoming/past/reschedule), payments (cards/purchases) in a **new `external/ui/direct_payments/` that `main.ts` must import** ([08](08-dashboard-sections.md) §8.2), plus `/me/purchases` joined on the link table only (X23) | K6, K7, K9 for the module endpoints each needs | One provider at a time; a missing provider is a missing group |
| **K9 — self-service endpoints** | The appointments waitlist attributes (X28), shopper cancel, the returns table + endpoints + admin queue, events cancel policy + waitlist promotion, appointments `customerPermissions()` enforcement, the richer `mine` serializers, **`SiteUsersGuestClaimService`** (X24 — [09](09-module-self-service.md) §9.7) | K3 | Each action is refused until its connection setting enables it; claiming fires only at email verification |
| **K10 — guest continuity** | The cart token becomes a browser identity (`active_cart_token`, X25), `ModulePagePaths`, three receipt mailables and their Blade views, `EcommerceReceiptService`, the `?t=` branch on `my-registrations` / `my-bookings` ([12](12-guest-continuity.md)) | **nothing** — both halves are pre-existing defects | The cart half changes no behaviour a tenant configured; the receipt half is the first mail these modules have ever sent |
| **K11 — identity and email change** | Identifier migration steps 4–5 (stop writing the bigint, drop `site_user_id`), `SiteUserReference::generate()`, the email-change flow and its confirm endpoint, the provider-customer follow-up, the Profile pane field, `siteusers:reformat-references` ([`../site_users/13`](../site_users/13-identity-and-email-change.md)) | K1's steps 1–2, K3's step 3, K6 for the provider update | Steps 4–5 only after the drift report is clean for a release. The email change is additive — an account that never uses it is unaffected |
| **K12 — disconnection** | `status` replaces `is_active`, `resolveAny()` / `forDashboard()`, the read-only capability subsets, the `SU_E_CONNECTION_DISCONNECTED` guard on every self-service write, the dashboard's read-only rendering and notice, the payments carve-out, the rewritten admin confirmations ([13](13-disconnection.md)) | K4 for the admin surface, K7/K8 for the dashboard, K9 for the writes it guards | Nothing changes until a tenant disconnects something. **Ships before the first tenant is told they can disconnect** — the alternative is a tenant discovering that disconnecting deleted their customers' history |

K8 and K9 interleave per module: ecommerce's provider needs ecommerce's endpoints. The
sensible order inside them is **ecommerce → events → appointments → payments**, because
ecommerce has the most surface and shakes out the shell.

### Deployment ordering that matters

- **K2 before K3.** Enforcement reading a registry that does not exist yet would make every
  instance `off`.
- **The audit before K2, on production data.** `siteusers:connections-audit` must report zero
  unassignable instances, or the operator must connect them in K4 before K2's behaviour
  switch reaches them. If K2 must ship first, the audit's list is the manual to-do.
- **K5 after K3, never before.** A gate that says "sign in" while the server still accepts
  anonymous checkout teaches visitors the wrong thing.
- **K0 can ship today**, ahead of all of it.
- **K10 depends on nothing either.** Its cart half is a two-line change plus a migration and
  should ship with K0; its receipt half is independent of every connection concept and only
  becomes *urgent* at K9, when the plan starts offering guests actions they cannot reach.

## 11.2 The audit command

```bash
php artisan siteusers:connections-audit [--fix-single-module]
```

Reports, from live data:

- instances with a non-`off` legacy mode and no connection row, per module;
- instances whose `site_users_id` column names a module that does not exist;
- connections whose instance no longer exists (orphans);
- modules with `link_payment_customers = true` and no `direct_payments` connection;
- **guard and route rows carrying a `site_users_id`**, and how many active sessions belong to
  a different module — the blast radius of X27's fix, reported before K6 ships;
- instances whose derived column disagrees with their resolved connection (X30's stale-cache
  window);
- a count of orders / registrations / bookings carrying an attribution for instances that
  would become unconnected — the "history that will disappear from dashboards" number.

`--fix-single-module` performs the backfill's assignment when exactly one active Site Users
module exists, and refuses otherwise. Exit `0` when nothing needs attention, `1` otherwise —
so it can gate a deploy.

## 11.3 Feature-flag posture

There is no flag. The connection row **is** the flag: nothing changes for an instance without
one, and every phase above is inert until a tenant connects something. Adding a global
kill-switch would create a second source of truth for exactly the question K1 exists to
answer once.

The one exception is K3's standardisation of `422` → `401` for events and appointments, which
changes a status code for an existing `required`-mode caller. No tenant has `required` set
today (verified by the audit before deploy); if one does, K4 ships first so they can see it.

## 11.4 Test matrix

New files, by phase. Existing suites (`--filter="SiteUsers|Ecommerce|Events|Appointments"`)
must stay green throughout; the ones that set `site_users_mode` on an instance are rewritten
onto `connect()` in K2 ([03](03-database-schema.md) §3.8).

| Phase | File | Asserts |
|---|---|---|
| K0 | `tests/Feature/Processor/ProcessorPaymentMethodSecurityTest.php` | `DELETE /billing/payment-methods` with `customer_id=1`, no admin session → `404`; with the provider id → works; `GET /processors/customers` unauthenticated → `401` |
| K1 | `tests/Unit/SiteUsers/SiteUserConnectionModelTest.php` | `mode()`, `isModuleWide()`, `setting()` dot paths, casts |
| K1 | `tests/Feature/SiteUsers/ConnectionsBackfillTest.php` | `required`/`guest` columns become rows with the right `guest_checkout`; `off` produces none; two modules with no store column → skipped and logged; re-running writes nothing new |
| K2 | `tests/Unit/SiteUsers/SiteUsersConnectionRegistryTest.php` | precedence (instance > wildcard > null); inactive row invisible; inactive Site Users module invisible; request cache hit count; **fail-open** on a thrown query |
| K2 | `tests/Unit/SiteUsers/SiteUsersConsumerRegistryTest.php` *(extended)* | `modeFor()` ignores the legacy column; six registered modules; `connectable` set is exactly four; `attributableUserReference()` returns null in `off`; **module isolation** — a module-B session on a module-A store |
| K2 | `tests/Feature/SiteUsers/ConnectionDerivedColumnsTest.php` | connecting rewrites `stores.site_users_mode`; a wildcard change rewrites every instance; disconnect → `off`; `direct_payments` connect → `link_payment_customers = true` |
| K3 | `tests/Feature/Connections/CheckoutEnforcementTest.php` | the whole §2.4 truth table for ecommerce, from HTTP: `off`/`guest`/`required` × guest/own-module/foreign-module, on `begin` **and** `complete`; `EC_E_CART_OWNER_MISMATCH`; the `module` hint present |
| K3 | `tests/Feature/Connections/EventsEnforcementTest.php` | same table for `register`, **plus `hold` and `waitlist`**; `401` not `422` |
| K3 | `tests/Feature/Connections/AppointmentsEnforcementTest.php` | same for `book`, `hold`, `waitlist` |
| K3 | `tests/Feature/Connections/MineEndpointsTest.php` | `401` for guests on all three; disconnected instances filtered out; another user's rows never returned |
| K4 | `tests/Feature/SiteUsers/SiteUsersConnectionsAdminTest.php` | [05](05-admin-connections.md) §5.9's seven cases |
| K5 | `tests/Feature/Connections/PublicBlockTest.php` | unconnected → no `module`, no paths; connected → module + paths from the builder route row; `settings.login_path` overrides |
| K6 | `tests/Feature/SiteUsers/SiteUsersPaymentsTest.php` | `GET /me/customers?create=1` creates one customer with the user's email and one link; second call creates none; **shared-email collision** → a *different* customer; `DELETE` of a foreign method → `404`; `connected:false` when Direct Payments is not connected; every endpoint `401` without a session |
| K6 | `tests/Unit/Ecommerce/…` *(existing, extended)* | a signed-in checkout passes the resolver; a throwing resolver charges anonymously and writes **no** link |
| K7 | `resources/js/portal/builder/tests/site_users.test.ts` *(extended)* | `buildDashboardGroups([])` → one group; two ecommerce connections → two groups with distinct nav keys; a connection with no provider → skipped; nav key format |
| K8 | `resources/js/external/ui/tests/dashboard-sections.test.ts` *(new)* | each provider's `views()` filters on `capabilities` and `settings`; markup builders emit the hooks the chrome styles |
| K9 | `tests/Feature/Ecommerce/EcommerceShopperCancelTest.php` | window, fulfilment, ownership, actor `customer`, no refund call |
| K9 | `tests/Feature/Ecommerce/EcommerceReturnsTest.php` | [09](09-module-self-service.md) §9.7's return cases |
| K9 | `tests/Feature/Events/EventCancelPolicyTest.php` | cutoff refusal; waitlist promoted **in the same transaction** (assert with a failure injected after promotion) |
| K9 | `tests/Feature/Appointments/BookingSelfServiceTest.php` | `can_cancel` from `policy_snapshot`; changing the calendar's settings after booking does not change it; endpoint refuses what the flag denies |

Added by the plan review pass (X18–X24):

| Phase | File | Asserts |
|---|---|---|
| K1 | `tests/Feature/SiteUsers/SiteUsersUserDeletionTest.php` | deleting a user **nulls** `site_user_reference` on every table in `SiteUsersUserDeletion::TABLES` and deletes their customer links and return requests; the orders themselves survive; the constant matches every `site_user_reference` column in the schema (X20) |
| K6 | `tests/Feature/SiteUsers/SiteUsersPaymentsThrottleTest.php` | the 31st `/me/payment-methods` in the window → `429` with `Retry-After`; `/me/connections` is not throttled at the same rate; two list calls inside 60s issue one provider call; a `store()` busts the cache (X18) |
| K6 | `tests/Feature/SiteUsers/SiteUsersPurchasesTest.php` | an invoice stamped `user_id = 'su:<other user>'` does **not** appear in that user's purchases; an invoice on a linked customer does; a disconnected instance contributes nothing (X23) |
| K7 | `tests/Feature/SiteUsers/DashboardConnectionsTest.php` | `ecommerce@*` with three stores → three entries; `events@*` → one; an instance row plus a wildcard row → one entry, not two; expansion caps at `MAX_EXPANDED` (X22) |
| K9 | `tests/Feature/SiteUsers/GuestClaimTest.php` | verifying an email claims matching guest orders/registrations/bookings on **connected** instances only; an unverified user claims nothing; an already-attributed row is never re-assigned; running twice claims the same count once (X24) |
| K5 | `resources/js/external/ui/tests/auth-gate.test.ts` | `requireSignIn()` uses the `su` instance it is handed rather than constructing one (X19) |
| K10 | `tests/Feature/Ecommerce/EcommerceGuestCartScopeTest.php` | a guest with carts at two stores keeps both, and the cookie value never changes; checkout nulls `active_cart_token` and the next visit reuses the same cookie; a second active cart under one token in one store is refused by the index; the migration backfills existing active carts (X25) |
| K1 | `tests/Feature/SiteUsers/ContainerBindingTest.php` | the four services resolve to one instance per request; two `resolve()` calls through different services issue **one** query; a second request gets a fresh cache (X31) |
| K4 | `tests/Feature/SiteUsers/AttentionSnapshotTest.php` | a `required` instance with no row appears in `attention` **after** a derived sync has written `off` over its column; connecting clears it; dismissing clears it; nothing else does (X29) |
| K6 | `tests/Feature/SiteUsers/GuardModuleIsolationTest.php` | a guard row naming module A refuses a module-B session with `401`; a row with a null `site_users_id` still accepts any session; a protected page redirects a module-B visitor to **module A's** login; `require_verified_email` is evaluated after the module test (X27) |
| K9 | `tests/Feature/Appointments/WaitlistAttributionTest.php` | a signed-in join writes `site_user_reference`; a guest join writes null; the entry appears in that user's dashboard badge (X28) |
| K11 | `tests/Feature/SiteUsers/SiteUserReferenceTest.php` | a new reference matches `^u[a-z0-9]{11}$`; an existing `usr-` reference still resolves; the drift report is zero after backfill; sessions/identities/verifications still join on the bigint (site_users/13 §13.7) |
| K12 | `tests/Unit/SiteUsers/ConnectionReadSetsTest.php` | `paymentsLinked()` false and `paymentsVisible()` true for a disconnected payments connection; `activeFor()` excludes disconnected while `forDashboard()` includes it; `resolveAny()` prefers an active instance row over a disconnected wildcard and vice versa (X35, X36) |
| K12 | `tests/Feature/SiteUsers/EmailChangeClaimTest.php` | confirming an email change re-runs the guest claim against the **new** address, records which address triggered it, and claims nothing on a disconnected connection (X36, X37) |
| K11 | `tests/Feature/SiteUsers/SiteUsersPaymentsThrottleTest.php` *(extended)* | the fourth `PATCH /me/email` in an hour → `429`, i.e. the narrow bucket is reached before the generic `/me` branch (X38) |
| K12 | `tests/Feature/Connections/DisconnectionTest.php` | the refusal message equals the pane notice, both from `readOnlyNotice()` (X40); a guest's `?t=` link on a disconnected instance renders the notice and no controls (X41); a disconnected store: storefront takes guest checkout and attributes nobody; `/me/connections` still returns the group with `read_only: true`, empty badges and the reduced capability set; `registrations/mine` and `bookings/mine` **include** its rows; `/me/purchases` still lists its purchases; every self-service write → `409 SU_E_CONNECTION_DISCONNECTED`; **removing a saved card still succeeds** while add and set-default `409`; **Remove** hides the group and leaves the orders in the admin; the instance cannot be connected elsewhere while disconnected and can after removal; reconnecting restores interaction with no data change (13 §13.10) |
| K11 | `tests/Feature/SiteUsers/EmailChangeTest.php` | an in-use address in the same module → `422`; the same address in another module → allowed; the email does not change until confirm; a race between request and confirm → `409` not an integrity error; the old address is notified; other sessions revoked; an SSO-only account needs no password; linked provider customers updated; order/registration/booking email snapshots untouched; the fourth change in an hour → `429` |
| K10 | `tests/Feature/Connections/GuestReceiptTest.php` | a guest registration, booking and order each send one mail; the body carries a working `?t=` link; a **signed-in** purchaser's body carries a dashboard link and no token; the full URL is absent from every log line; a throwing mailer leaves the row committed; an instance with no published page sends no link rather than a broken one (X26) |

### The concurrency case worth a real test

`required` mode plus two tabs: begin checkout signed in, sign out in tab B, complete in tab A.
Expected: `401`, cart untouched, no order. Written as a feature test by forging the second
request without the cookie — cheap, and it is the exact shape of the bug K3 exists to prevent.

## 11.5 Grep gates

Run in CI as a single script, `scripts/connections-gates.sh`, failing on any non-empty result:

```bash
# 1. No consumer imports a Site Users model.
grep -rln "Models\\\\SiteUsers" app/Externals/Ecommerce app/Events app/Externals/Appointments app/Externals/DirectPayments \
  app/Http/Controllers/Portal/{Ecommerce,Events,Appointments} app/Http/Controllers/Externals/Processor \
  app/Http/Controllers/Externals/DirectPayments app/Externals/Processor

# 2. Only Site Users reads the connections table.
grep -rn "site_user_connections" app/ --include=*.php \
  | grep -v "app/SiteUsers\|app/Models/Externals/SiteUsers\|app/Http/Controllers/Externals/SiteUsers"

# 3. The processor layer learns nothing about users.
grep -rn "SiteUser\|site_user" app/Http/Controllers/Externals/Processor app/Models/Externals/Processor app/Externals/Processor

# 4. No client-supplied owner on any path this plan added.
grep -rn "input('site_user\|input('user_id'\|query('user_id'" app/Http/Controllers/Externals/SiteUsers \
  app/Http/Controllers/Portal/{Ecommerce,Events,Appointments}

# 5. The deprecated any-module resolver has no callers left (after K3).
grep -rn "currentUserId(" app/Externals/Ecommerce app/Events app/Externals/Appointments app/Http/Controllers/Portal

# 6. One login form (after K5).
test "$(grep -rn 'data-su-login' resources/js/external/ui --include=*.ts | grep -c 'form data-su-login')" = "1"

# 7. The legacy mode column is read in exactly two places (after K2):
#    the derived-column writer and the admin's "needs attention" banner.
grep -rn "site_users_mode" app/ resources/js/portal --include=*.php --include=*.ts
```

Gate 7 is a **count check with a documented allowlist**, not an emptiness check, until
[03](03-database-schema.md) §3.6 drops the columns — at which point it becomes emptiness.

## 11.6 Rollback

| Phase | Rollback | Cost |
|---|---|---|
| K0 | Revert the two commits | Re-opens the exposure. **Do not.** Fix forward |
| K1 | Drop the table | Backfilled rows lost; the legacy columns still hold the same information, so nothing behavioural is lost |
| **K2** | Revert `modeFor()` to `mode_source` (kept for exactly this — [03](03-database-schema.md) §3.6) | The columns are current because K2 wrote them through. **This is the reason the write-through exists** |
| K3 | Revert the module edits | Re-opens the ungated holds/waitlist/complete. Acceptable briefly; they are gaps that predate this plan |
| K4 | Revert the SPA | The tab disappears; rows stay; the API still works via `curl` |
| K5 | Revert the UI bundle | `required` becomes enforceable-but-unusable again — so **only roll K5 back together with K3** |
| K6 | Revert the resolver install (one line in each storefront) | Checkouts return to anonymous customers. Links already written stay valid |
| K7/K8 | Revert the UI bundle | Dashboard returns to Account-only. No data change |
| K9 | Revert the endpoints | Dashboard actions 404; the panes show the error state they already have |
| K10 | Revert each half separately | The cart migration is reversible (restore the global unique after nulling duplicates — there will be none unless a shopper used two stores, which is the bug). Reverting the receipts returns guests to having no route back, so treat it as a regression, not a rollback |
| K12 | Revert the guard and the dashboard flag; leave the `status` column | The column is a superset of the boolean it replaced, so data survives a revert. **Reverting after a tenant has disconnected something re-opens writes on a module they turned off** — treat as an incident action, not a rollback |

Two are not independently rollbackable and must be treated as one unit each: **K3+K5**, and
**K7+K8** (the shell's second parameter and the providers).

## 11.7 Follow-ups this plan deliberately leaves open

| # | Item | Owner |
|---|---|---|
| 1 | **Subscriptions for site users** — `dp.subscription.*` resolves identity through `CheckpointIdentityResolver` (a member concept). A second resolver accepting a Site Users session would let the dashboard cancel and update a tenant-site subscription ([07](07-direct-payments-connection.md) §7.7) | billing + site_users |
| 2 | **X4** — `GET /directpayments/invoices?user_id=` is enumerable | billing |
| 3 | **Drop the legacy columns** ([03](03-database-schema.md) §3.6) once gate 7 is clean for a release | this plan, later |
| 4 | **Ticket transfer** and **season-pass purchase** as dashboard views | events |
| 5 | **Package/credit balance** in the appointments group ([appointments/09](../appointments/09-payments-and-policies.md) §9.6) | appointments |
| 6 | **Notification emails** for return-request state changes — the mail handler pattern exists; no templates in this plan | ecommerce |
| 7 | **Customers admin screen** (ecommerce TODO 13). [05](05-admin-connections.md) §5.8's activity card is the interim | ecommerce |
| 8 | **Origin checks on `/me/*`** — `SiteUsersOriginMiddleware` passes through when no module is in the request, which is every `/me/*` call ([01](01-current-state-audit.md) §1.1). The session is the credential, so this is defence in depth, not a hole | site_users |
| 9 | **Forms attribution in the dashboard** — forms is guest-only and not connectable; a read-only "your submissions" group is possible later | forms |
| 10 | ~~Email change for a site user (X21)~~ — **now planned**, in [`../site_users/13`](../site_users/13-identity-and-email-change.md) §13.4, with the provider-customer follow-up in [07](07-direct-payments-connection.md) §7.10. Ships as **K11** | site_users + this plan |
| 11 | **`SameSite=None` for genuinely cross-origin session cookies** (X19) — blocked on there being *any* CSRF verification on `routes/portal.php`, which today has none ([06](06-checkout-guard.md) §6.8). Until then an external site uses `localStorage` token storage | site_users |
| 12 | **Self-service account deletion / data export** ([03](03-database-schema.md) §3.7) — needs a tenant retention decision, an export and a confirmation path | site_users |
| 14 | **Receipt resend for a guest.** Deliberately absent: an endpoint keyed on an email address is an enumeration oracle for "did this person buy" ([12](12-guest-continuity.md) §12.7). The admin can resend from the record; a self-service version needs a different shape | this plan, later |
| 13 | **Claiming guest *payment* identities** — [09](09-module-self-service.md) §9.7 claims façade records at verification but deliberately leaves processor customers to the admin-run, unscheduled reconciler ([site_users/08](../site_users/08-direct-payments-link.md) §8.5) | site_users |

## 11.8 Definition of done

The connection is done when every line of [00](00-overview.md) §6 is true on a real
deployment, and:

- `php artisan test --filter="SiteUsers|Connections|Ecommerce|Events|Appointments|Processor"` is green.
- `scripts/connections-gates.sh` exits 0.
- `npx tsc --noEmit`, `npm run check:modules`, `npm run test:builder`, `npm run build`,
  `npm run build-external-webpack`, `npm run build-external-ui-webpack` all pass.
- `php artisan siteusers:connections-audit` exits 0.
- `portal.d.ts` declares every method in [10](10-api-contracts.md) §10.12, and the consumer
  copies are refreshed for any app that consumes commerce or site users.
- A manual pass of the **disconnect** journey: connect a store, place an order as a signed-in
  shopper, disconnect, then confirm the order is still listed in the account, the Cancel
  control is gone, `curl` to the cancel endpoint returns `409`, and reconnecting brings the
  control back.
- A manual pass **as a guest**, before any of the above: add to cart at two stores, confirm
  both survive, check out at one, and follow the emailed link from `storage/logs/laravel.log`
  to the record it names.
- A manual pass on `www.test`: connect a store with guest checkout off → checkout gate →
  register through the gate → land back on checkout → pay with a new card → see the order
  and the card in the dashboard → cancel the order within the window → see it cancelled with
  the refund-pending copy → disconnect the store → the group is gone and guest checkout works.

That last line is the acceptance test that actually matters, and it is the one no unit test
replaces.
