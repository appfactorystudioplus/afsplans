# 01 — `website_reference_num`: one source, stamped automatically

Status: all sections of this file are written and committed (nothing pushed); two decisions and one extra finding are open, listed under "Still open" at the end. Brain registry tables keep the column (see 5.3). Code blocks of steps not marked done are examples.

All paths are under `/Users/jamesmitchell/Desktop/projects/vagrant/afs-eco/w/` unless they start with `packages/`.

## 1. The problem

`website_reference_num` is the id of one deployed website (`members_websites.reference_num`;
`packages/afsserver/plans/afs-connect-www/00-overview.md` line 66). It exists for **afs-connect**: the coordinator
keeps one index for a member who owns several sites, so every indexed item has to say which site it came from, or
items from different sites collide (`afs-connect-www/02-architecture.md` line 202).

A website is self-contained: its own tenant database. Inside it the value is always the same one. It was copied
into feature tables (forms, events, ecommerce, appointments, direct payments, ...) and then into Direct Payments
payer matching, where it does no work and breaks the membership payer (see section 4).

## 1a. How a site gets its properties (the full route)

1. A site works out which environment it is in by a single label, `www` (production) or `www15` (development).
   The active code takes it from `config('afs_connect.subdomain')` = `VITE_SUBDOMAIN`
   (`www/config/afs_connect.php:59`, `FunctionsController::getSubdomain2()`). The older `type.txt` read inside
   `getSubdomain2()` is commented out; `public/type.txt` currently holds `www15`, and `MailMuaController.php:39` still
   reads it.
2. `get_subdomain_managed_domain_new_way($subdomain)` (`FunctionsController.php:909`) loads **every**
   `subdomain_properties` row with `SubDomain = <that label>` and turns the rows into the "managed domain" array.
   `CreatedBy` is no longer important.
3. `subdomain_properties` is a table every site has. On a tenant it is filled once, at install, from the
   `brain_http_server_tenants.json` the Brain recorded for it. The Brain's own copy of the table holds the rows for
   `www` and `www15`.
4. When a new tenant is created, the members app asks the Brain for its own `www`/`www15` rows as a template
   (`GET /portal/api/v1/brain/subdomain_properties`, `BrainController::getSubdomainProperties`), builds the tenant's
   identity JSON, posts it to `/http/tenants` (the `brain_http_server_tenants` row), and `app_install.sh` writes the
   same fields into the new tenant's `subdomain_properties` (`app_install.sh:595-619`).

## 1b. Where it breaks

Three different values stand in for "the website":

| Where | Value | Source |
|---|---|---|
| `members_websites.reference_num` and the `brain_http_server_tenants.reference_num` **column** | `$websiteRef` | `members/app/Http/Controllers/Brain/DomainController.php:438`, used at `:516` and `:545` |
| `transfer_data['website']` — what the install script reads as `.website.reference_num` | a **second**, different random `$websiteRef` | `DomainController.php:1058` inside `getTenantData()` |
| the tenant's `subdomain_properties.reference_num`, and `json.reference_num` inside the tenant row | a third, a fourth: `$(openssl rand -hex 12)` in the script (`app_install.sh:417`), and `$tenantRef` (21 characters) in the JSON | `app_install.sh:417`, `DomainController.php:1078` |

`run_create_repo` reads `website_ref` (`app_install.sh:406`) but never inserts it into `subdomain_properties`. The
tenant therefore reports an id that the Brain registry does not know it by, and the Brain's own site has no id or
tenant row at all.

Two things about the install that are not part of this plan but showed up: `app_install.sh` writes `type.txt` to
`public/_portal_/type.txt` (lines 562, 605, 1042, 1059), while `MailMuaController` reads `public_path('type.txt')`;
and the current `getSubdomain2()` no longer reads the file at all.

## 2. The design

1. The id is created **once**, in `DomainController::createWebsite()` (`$websiteRef`), the same value already used
   for `members_websites` and the Brain tenant row.
2. It travels to the install script and is written to the tenant's `subdomain_properties` as
   `website_reference_num`.
3. The tenant reads it from there (`TenantContext::websiteReferenceNum`).
4. afs-connect output gets it from the `TenantContext`, **not** from feature tables. This already works that way:
   `www/app/AfsConnect/Providers/WwwDirectPaymentsProvider.php:78` and `WwwAfsConnectMetaProvider.php:76` set
   `website_reference_num` from `$tenant->websiteReferenceNum`. No feature has to carry the column for
   afs-connect.

There are no existing tenants to migrate: the application is in development and all data is test data.

## 3. Changes

### 3.1 `members/app/Http/Controllers/Brain/DomainController.php` — `createWebsite()`

Send the real id to the script, instead of the second random one from `getTenantData()`:

```php
$transfer_data = [
    'root_directory'  => "/home/appfactorystudio/websites/$filepath",
    'managed_domain'  => $data['subdomain_properties'],
    // one website id, the same one stored on members_websites and the Brain tenant row
    'website'         => array_merge($data['website'], ['reference_num' => $websiteRef]),
    // ...rest unchanged
];
```

### 3.2 `members/core/scripts/remote/builder/app_install.sh` — `run_create_repo`

Delete the random value at line 417 and stop seeding it (so nothing can read a random id by mistake):

```bash
# REMOVE:
local tenant_reference_num=$(openssl rand -hex 12)
```

Seed the real id. Replace the `reference_num` row (line 598) with:

```bash
mysql -p"${mysql_password}" -u root -e "INSERT INTO ${new_db_name}.subdomain_properties (SubDomain, PropertyName, PropertyValue) VALUES
    ('${tenant_subdomain}', 'server', '${tenant_server}'),
    ('${tenant_subdomain}', 'website_reference_num', '${website_ref}'),
    ('${tenant_subdomain}', 'owner', 'www'),
    ...
```

`website_ref` is already defined in that function (line 406) from `.website.reference_num`, so after 3.1 it is
`$websiteRef`.

### 3.3 `www/app/AfsConnect/Support/WwwAfsConnectTenantFromRequest.php` — `tryFromManagedDomain()` (DONE 2026-10-14)

```php
// was: $domain['reference_num']
$websiteRef = trim((string) ($domain['website_reference_num'] ?? ''));
```

### 3.4 `www/app/Externals/Forms/Support/FormSiteResolver.php` — line 174 (DONE 2026-10-14)

The second reader of the old key:

```php
// was: $domain['reference_num']
$referenceNum = trim((string) ($domain['website_reference_num'] ?? ''));
```

### 3.5 Direct Payments stops using `website_reference_num`

The payer concept in Direct Payments does not need it (section 4).

`www/app/Externals/DirectPayments/PayerService.php`:

```php
public function resolveForCheckout(
    ?string $payerReferenceNum,
    ?string $email,
    ?string $name,
    string $createdBy,
): DirectPaymentPayer {            // never null: every invoice gets a payer
    if ($payerReferenceNum !== null && $payerReferenceNum !== '') {
        $existing = DirectPaymentPayer::where('reference_num', $payerReferenceNum)->first();
        if ($existing !== null) {
            return $existing;
        }
    }

    $email = $email !== null ? trim($email) : null;

    if ($email !== null && $email !== '') {
        return DirectPaymentPayer::firstOrCreate(
            ['email' => $email],
            ['reference_num' => DirectPaymentReference::payer(), 'name' => $name, 'created_by' => $createdBy]
        );
    }

    return DirectPaymentPayer::create([
        'reference_num' => DirectPaymentReference::payer(),
        'email' => null,
        'name' => $name,
        'created_by' => $createdBy,
    ]);
}
```

`resolveForSiteUser()` loses its first parameter the same way.

`www/database/migrations/2026_04_03_160000_create_laravel_direct_payment_tables.php`, the `laravel_direct_payment_payers` create
block (lines 129-159). **Owner decision: edit the create statement in place; no alter migration.** Nothing is deployed that
must be preserved, because every database is rebuilt with `migrate:fresh`. The `Schema::hasTable` guard stays (owner), so the
file remains safe to run over a database that already has the table. Two edits in that block:

```php
// remove (lines 135-140): the website scope column and its index
$table->string('website_reference_num', 64)->index('dp_payers_site_idx');

// replace (line 157): the per-site unique becomes a unique on the email alone
$table->unique(['website_reference_num', 'email'], 'dp_payers_site_email_uq');   // before
$table->unique('email', 'dp_payers_email_uq');                                    // after (NULL emails stay distinct in MySQL)
```

The comment above the removed column (P9, "a payer belongs to a WEBSITE") is deleted with it.

`www/app/Http/Controllers/Externals/DirectPayments/DirectPaymentsController.php` — `saveInvoice()` (line 66):
always resolve a payer, and return it:

```php
$payer = $this->payers->resolveForCheckout(
    payerReferenceNum: $request->input('payer_reference_num'),
    email: $validated['customer_email'],
    name: $request->input('customer_name'),
    createdBy: 'checkout',
);
$payerReferenceNum = $payer->reference_num;

if (!empty($validated['customer_id']) && !empty($validated['processor_reference_num'])) {
    $this->payers->recordExistingCustomer($payerReferenceNum, $validated['processor_reference_num'], $validated['customer_id']);
}
// ...invoiceValues always gets 'payer_reference_num' => $payerReferenceNum
return response()->json(['success' => true, 'invoice' => $invoice, 'payer_reference_num' => $payerReferenceNum]);
```

`www/app/Externals/DirectPayments/SwapService.php:131` — the swap impact count loses its website filter (it counts
every payer in the tenant, which is the same set):

```php
$payersCount = DirectPaymentPayer::query()
    ->whereHas('customers', fn ($q) => $q->where('processor_reference_num', $currentProcessorRef)->where('status', 'active'))
    ->count();
```

`www/app/Models/Membership.php:22` — `AFS_MEMBERSHIP_PAYER_SCOPE` is deleted. `MembershipController::store()` stops
calling `PayerService` itself; it takes the payer Direct Payments assigned to this signup's invoice:

```php
$payerReferenceNum = DirectPaymentInvoice::where('user_id', $referenceNum)
    ->whereNotNull('payer_reference_num')
    ->latest()
    ->value('payer_reference_num');
```

### 3.6 Remaining `website_reference_num` columns (decided now, see section 5)

## 4. Why the Direct Payments scope is dropped

Direct Payments plan decision **P9** (`packages/afsserver/plans/www/direct_payments/00-overview.md` line 109) scopes a
payer to a website so that one person buying from the store and booking an appointment on the same site is **one
payer, not one per Direct Payment**. That goal is right. The website key is not needed for it: one tenant database
holds one website, so `email` alone already gives "one payer per person across Direct Payments". P9 should be
reworded to "a payer belongs to the tenant, not to a Direct Payment".

The key is also what made the membership payer break: the membership Direct Payment has no
`website_reference_num`, `resolveForCheckout()` returned null, `saveInvoice()` skipped payer creation, and the
invoice had no `payer_reference_num`. An invoice must always have a payer.

## 5. The other features (decide per table)

`grep` of `database/migrations` found `website_reference_num` on these tables. Two kinds of use exist:

* **A — only there so afs-connect can attribute rows to a site.** Not needed any more: afs-connect stamps it from
  `TenantContext`. Example of the pattern, already in use (`WwwDirectPaymentsProvider.php:78`):

  ```php
  return [
      'source_id' => $dp->reference_num,
      'website_domain' => $tenant->websiteDomain,
      'website_reference_num' => $tenant->websiteReferenceNum,   // from the tenant context, not from a column
  ];
  ```

* **B — used by local logic.** Examples found:

  | Where | Local use |
  |---|---|
  | `app/Externals/Forms/Services/FormAllocationService.php:19-35` | counts forms per site for the allocation limit |
  | `app/Externals/Forms/Services/FormSubmissionQuotaService.php:21-36` | per-site submission quota row `(website_reference_num, period)` |
  | `app/Http/Controllers/Portal/Admin/AdminDashboardController.php:38-156` | `whereIn('website_reference_num', $siteRefs)` over events, stores, forms |
  | `app/Externals/Ecommerce/Services/EcommerceDirectPaymentService.php:59-66` | store and Direct Payment must be the same site |
  | forms `unique(website_reference_num, slug)`, events promo `unique(website_reference_num, code)`, analytics rollup key | uniqueness scoped per site |

Tables (from the migrations): ecommerce (2), `laravel_direct_payments`, appointments, analytics rollup, forms (2),
`members_website` tables, events (4), sponsorships, `laravel_site_user_modules`, and the Brain tables.

**Correction (earlier draft of this file was wrong):** an earlier version claimed the Brain-hosted portal stores
more than one website's data in one database. That was a misreading. The Brain portal serves only its own website.
What `FormSiteResolver` actually does (`www/app/Externals/Forms/Support/FormSiteResolver.php`):

* `siteRefsForMember()` (line ~35) asks the Brain's tenant registry (`BrainHttpServerTenant`) for every website the
  member owns, then the dashboard and form queries filter local tables with `whereIn('website_reference_num', ...)`.
  A tenant database only holds its own site's rows, so that filter always matches the one site: it is a no-op.
* `brainPortalSiteStamp()` / `memberOwnsBrainPortal()` (lines ~127-200) is a **workaround**: the Brain's own site is
  often missing from `brain_http_server_tenants` and its `subdomain_properties` often has no `reference_num`, so the
  code invents the member's `reference_num` as the "website ref". It exists only because of the missing id this
  plan fixes. Once every site, including the Brain's own, has `website_reference_num` in `subdomain_properties`,
  this workaround can be deleted.

Proposed handling, per table, nothing started:

1. `laravel_direct_payments.website_reference_num` (A): drop column and `DirectPayment::$fillable` entry.
2. Ecommerce store/DP check (`EcommerceDirectPaymentService.php:59-66`): remove, since both rows live in one
   tenant:

   ```php
   $dp = DirectPayment::query()->where('reference_num', $dpRef)->first();
   if ($dp === null) {
       return ['error' => ['message' => 'Direct Payment not found.', 'code' => 'EC_E_DP_NOT_FOUND']];
   }
   // site-ownership check removed: store and Direct Payment are always in the same tenant database
   ```

3. Forms, events, ecommerce stores, appointments, analytics, sponsorships, site user modules (B): the column and the
   per-site filters are redundant inside one website's database. Each needs its own step (edit that table's create
   statement to drop the column and the `(website_reference_num, ...)` uniques, as above, and simplify the queries above). Not
   done in this file; to be listed as separate steps once the owner approves the direction.
4. Delete `brainPortalSiteStamp()`, `memberOwnsBrainPortal()` and `managedDomainContext()` from `FormSiteResolver`,
   after seeding `website_reference_num` into the Brain's own `subdomain_properties` (it is not created by
   `app_install.sh`, so this is a manual seed for that one site).

## 6. Questions

1. How is the Brain's own site's `subdomain_properties` created? `app_install.sh` only seeds tenant sites, so the
   Brain's own site needs `website_reference_num` added another way. (Not searched.)
2. After 3.2 the tenant has `website_reference_num` and no `reference_num` property. Is anything else reading
   `subdomain_properties.reference_num` that this plan has not found? Searched `www/app` only; `members`,
   `auth`, `coordinator` and the shell scripts were not searched.

## Status 2026-10-14

* 3.3 and 3.4 are written (both read `website_reference_num` from the managed domain). Tested on the dev server against a
  database built from the 26 migrations plus `testdb_appfactorystudio.data-only.sql`: with `VITE_SUBDOMAIN=www` the
  tenant context is `W8FdmgH1KSLj @ appfactory.studio`, with `www15` it is `uv6Op6gaAGdg @ appfactory.studio`.
  The test loaded the changed file ahead of the server's copy; the two files are not uploaded to the dev server yet.
* The Brain's own site now has `website_reference_num` rows, a `brain_http_server_tenants` row and a
  `members_websites` row for `www` and `www15` in the SQL data file.
* 3.1, 3.2, 3.5 (the Direct Payments payer scope) and the `FormSiteResolver::brainPortalSiteStamp()` workaround
  removal are not done.

## Status 2026-10-15

* **3.1 DONE**: `members/app/Http/Controllers/Brain/DomainController.php`, the job JSON's `website` now carries `reference_num = $websiteRef`
  (`array_merge($data['website'], ['reference_num' => $websiteRef])`), the same id as `members_websites` and the Brain tenant row.
* **3.2 DONE**: `members/core/scripts/remote/builder/app_install.sh`, in `run_create_repo`: the random `tenant_reference_num` (old line 417)
  is deleted and the `subdomain_properties` seed inserts `website_reference_num = ${website_ref}` instead of `reference_num`. The same unused
  `local tenant_reference_num=$(openssl rand -hex 12)` also exists at line 284 inside `write_dovecot_files`; nothing reads it and it was left alone.
* **Question 2 answered:** a search of `www/app`, `www/core`, `www/database`, `members/app`, `members/core` and `auth/app` found nothing that reads
  `subdomain_properties.reference_num` (no `PropertyName`/`reference_num` pair, no read of `$domain['reference_num']`).
* **Not covered by this plan, found while checking:** `core/scripts/remote/builder/transfer_tenants.sh` (tenant move, lines ~364-372, in `members` and `www`) deletes and
  re-inserts a tenant's `subdomain_properties` rows (`SystemUser`, `Username`, `CreatedBy`, `Subdomain`, `Domain`, ...) and writes **no** `website_reference_num`, so a moved
  tenant would lose it. Decide whether the move script should carry it.
* **Tested later:** the `www` side was run afterwards (see the next section); `app_install.sh` and `DomainController` still have no tests (`members` has none), and `bash -n` cannot run on the Mac's bash 3.2.
  bash is 3.2), so the edits were reviewed as diffs only.

## Status 2026-10-15, the rest of the file

All written and committed. **Checked 2026-10-15** with a prebuilt PHP 8.4.8 command-line binary (static-php-cli, kept in the session scratch folder, not installed system-wide):

* `php -l` on all 38 changed PHP files (37 in `www`, 1 in `members`): no syntax errors.
* The `www` tests for Direct Payments, Ecommerce, Events, Membership, SiteUsers, Connections and Processor, run on an in-memory SQLite database (`DB_CONNECTION=sqlite DB_DATABASE=:memory:`; `phpunit.xml` points at the lab MySQL, which is unreachable from the Mac) with `EXTERNALS_CORS_MODE=off`, once on a clean copy of `HEAD` and once on the changed tree: **383 tests, 5 failing before; 380 tests, 5 failing after, the same five**: `MembershipSignupServerSideTest::test_a_failed_checkpoint_registration_creates_no_membership_row` (its mock lacks `initMemberAllocation()`, which `store()` calls first), `MembershipTierAndStatusTest` (3 tests) and `MembershipUpdateIgnoresIdentityFieldsTest::test_payer_reference_num_is_silently_ignored_by_admin_update` (both a 500 on SQLite). None involves this change.
* Without `EXTERNALS_CORS_MODE=off` 87 of those tests fail identically before and after with `SDK_E_ORIGIN_DENIED` ("An Origin or Referer header is required"), because the default mode is `enforce`; that is why the HTTP tests need the setting.
* Not exercised by any test: the new `store()` lookup of the payer on the signup's invoice (the only signup test stops earlier), and `app_install.sh` / `DomainController` (`members` has no tests for them).

* **3.5 DONE** (Direct Payments payer scope):
  * `PayerService::resolveForCheckout()` and `resolveForSiteUser()` lost their `websiteReferenceNum` parameter and never return null.
  * The `laravel_direct_payment_payers` create block lost `website_reference_num`, its index and the per-site unique; it now has `unique('email', 'dp_payers_email_uq')` (create file edited in place, `Schema::hasTable` guard kept).
  * `DirectPaymentsController::saveInvoice()` always resolves a payer and returns `payer_reference_num` in its response.
  * `SwapService::impact()` counts every payer with an active customer on the processor.
  * `Membership::AFS_MEMBERSHIP_PAYER_SCOPE` is deleted. `MembershipController::store()` takes the payer from the signup's invoice (`user_id` = the reserved member reference **and** `customer_email` = the signup email, so a posted reference cannot adopt another checkout's payer).
  * **Deviation from the plan text:** when the signup has no invoice (a free signup), `store()` still resolves a payer by email through `PayerService`. Without it a free member has no payer, `ApplyTierOnPlanChange` could not find the member from a later subscription's payer, and an upgrade would never apply its tier. Remove that fallback if you disagree.
  * `ecommerce`, `appointments` and `events` order services, `SiteUsersPublicController::myPayer()` and `SiteUsersPaymentsController::payerLinkOrCreate()` call the new signatures.
  * **Found, not in this file's list:** `siteusers:link-customers` (`SiteUsersLinkCustomers`) filtered payers by the module's `website_reference_num` and refused a module without one; both are gone.
* **5.1 DONE:** `laravel_direct_payments.website_reference_num` is removed from the create block and from `DirectPayment::$fillable`; the two Direct Payment `INSERT`s in `lab2/databases/production/testdb_appfactorystudio.data-only.sql` lost the column; `DemoStoreSeeder` and `EventsDemoSeeder` no longer write it.
* **5.2 DONE:** the site-ownership check in `EcommerceDirectPaymentService::validateDirectPayment()` is removed.
* **5.4 DONE:** `FormSiteResolver` lost `brainPortalSiteStamp()`, `memberOwnsBrainPortal()` and `managedDomainContext()`. It relies on the Brain's own site being a `brain_http_server_tenants` row owned by the owner's member reference; the data file has that (member `appfactorystudio`, rows for `www` and `www15`, with `website_reference_num` in `subdomain_properties`). On a database without those rows the owner no longer gets the Brain site in forms, events and the dashboard.
* **Tests updated** (payer and Direct Payment schemas, call signatures, and the tests whose behaviour the design changed): `PayerServiceTest`, `PayerServiceUpdateEmailTest`, `SaveInvoiceRecordsPayerTest`, `SwapServiceTest`, `DirectPaymentSwapControllerTest`, four more Direct Payments tests that only built the old column, `EcommerceOrderPayerTest`, `EventOrderPayerTest`, `BrainMembershipPayerResolutionTest`, `MembershipSignupServerSideTest`, `SiteUsersMyPayerTest`, `SiteUsersLinkPayersReconcilerTest`, `SiteUsersEmailChangeTest`, `CreatesSiteUsersSchema`. All of them pass in the final full run (1582 tests, 0 failing, on a throwaway local MySQL 8.0.36).
* **Latent problem kept as it was:** `resolveForSiteUser()` always creates a fresh payer ("a signed-in user never silently adopts a stranger's payer"). With the email unique, that fails when a payer with the same email already exists. It already failed the same way on the old `(website_reference_num, email)` unique; the test schema has no unique, which hid it. Decide: adopt the existing payer, or create the new payer with no email.
* **5.3 DONE**: the column, its indexes and uniques, the model `$fillable` entries, the write/filter code, the seeders and scripts, and the tests are gone from:
  * forms (`laravel_forms`, `laravel_form_submission_quota`; quota unique is now `period`), `FormAllocationService::assertCanCreate()` and `FormSubmissionQuotaService` lost the site parameter, `FormsBackfillSites` command deleted;
  * events (`laravel_events`, `laravel_event_promo_codes` with `unique('code','ev_promo_code_uq')`, `laravel_venues`, `laravel_event_passes`); `EventPromoService::validate()` lost `$websiteRef`, `EventPassService::listForSite()` became `listActive()`, the storefront no longer takes a `site` query parameter;
  * ecommerce stores and orders, appointments, `laravel_analytics_daily_rollups` (unique is now `rollup_date, module, event`), sponsorships, `laravel_site_user_modules`;
  * the `site_column` entries of `config/site_users_consumers.php` (and the two readers in `SiteUsersConnectionsController`);
  * `AdminDashboardController`, the demo seeders and `database/scripts`.
  * **Access gate kept:** `forSites()`, `*AuthorizesSites` and the dashboard still return nothing when the caller owns no site; they just no longer filter rows by the column.
  * **Deviation from the plan text (kept on purpose):** `brain_dns_zones.website_reference_num` and `members_website_deployment_progress.website_reference_num` stay, because those Brain registry tables hold rows for many websites, so the column is not redundant there. The AfsConnect providers keep sending `website_reference_num`, now from `TenantContext`.
  * Tests whose point was cross-site isolation (`SiteUsersConnectionsAdminTest` candidates / PUT) now test the empty-site-scope gate instead.

## Decisions made (2026-10-15)

* **Decision 1, DONE:** `PayerService::resolveForSiteUser()` always creates a new payer, because the user needs one for later purchases. The email is unique, so when another payer already holds it the new payer is created with no email; it never adopts that payer and never fails. Test: `PayerServiceTest::test_a_signed_in_user_always_gets_a_new_payer_even_when_the_email_is_taken`.
* **Decision 2, DONE (kept):** the free-signup payer-by-email lookup in `MembershipController::store()` stays, so every member has a payer.
* **transfer_tenants.sh, DONE** (`www` and `members` copies, identical): before it deletes and re-inserts the tenant's `subdomain_properties` rows, it reads the `website_reference_num` the restored database already carries and inserts it again afterwards (only when there is one). The value is not in the job JSON, so it is taken from the moved database itself. Not run: it needs a real transfer; `bash -n` cannot check it on the Mac's bash 3.2.
