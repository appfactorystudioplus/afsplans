# 13-identity-and-email-change — completion record

**Plan file:** [`../13-identity-and-email-change.md`](../13-identity-and-email-change.md)
**Rollout phase:** `connections/` K1 (§13.3 steps 1–2) + K11 (everything else)
**Repo:** `w/www`
**Date:** 2026-09-14
**Status:** ✅ Shipped in full — §13.1/§13.2 (the identifier format and generator), all five
steps of §13.3's migration (including the irreversible step 5 column drop, explicitly
confirmed with the user before running it), §13.4's complete email-change flow (backend + SDK),
§13.6's `siteusers:reformat-references`, and — once `connections/08`'s Dashboard shell existed
to build them into — §13.4.4's **Dashboard/Admin UI surfaces**: the Profile pane's email field
with *Change*/*Resend*/*Cancel*, and the admin per-user "set an address directly" screen. Nothing
from this plan remains unbuilt.

## Why this round covers all of K11 at once

This document's first pass (K1) shipped only §13.3 steps 1–2 and explicitly deferred
everything else to K11. K11 was picked up in this round because
[`../../connections/07-direct-payments-connection.md`](../../connections/07-direct-payments-connection.md)
§7.10 (the payer-email-propagation call) has no endpoint to be called FROM without it — closing
that gap meant building the email-change flow this document specifies, not routing around it.

## What shipped

### §13.1/§13.2 — the identifier format and generator

New `App\SiteUsers\SiteUserReference` — `generate()` (CSPRNG, `random_int`, not `Str::random`),
`isValid()` against `^u[a-z0-9]{11}$`. Both call sites that minted the old `'usr-' . Str::random(8)`
form switched over, keeping their existing retry-on-collision loop: `SiteUsersAuthService::register()`
and `SiteUsersSsoService::createUser()`. Existing `usr-` references are untouched and still
resolve everywhere — the column and the validator accept both formats; only the generator changed
(§13.2's own rule).

### §13.3 — the remaining three steps of the identifier migration

**Step 3 — read the reference.** `connections/04`'s K3 pass (a separate, earlier phase) had
already re-pointed most façade reads onto `site_user_reference`, but this round's own audit
(`grep -rn "site_user_id" app/` across every file the K1 migration touched, not a re-read of K3's
own claim) found **three real, live bigint reads K3 had missed** — all in the exact place a
signed-in buyer's own linked payer is looked up:

- `EcommerceOrderService::resolvePayerReferenceNum()` — `$cart->site_user_id`
- `EventOrderService::resolvePayerReferenceNum()` — `$order->site_user_id`
- `AppointmentBookingService::resolvePayerReferenceNum()` — a raw `?int $siteUserId` parameter

All three join against `laravel_site_user_payers`, which K1 never touched (it isn't one of
§13.3's thirteen tables — it's `direct_payments/05`'s own aggregate, keyed on `user_id` bigint
by that plan's own design). Fixing step 3 correctly meant **adding `site_user_reference` to
`laravel_site_user_payers` too** (new migration
`2026_09_14_000001_add_site_user_reference_to_site_user_payers.php`, backfilled), updating its
two writers (`SiteUsersPublicController::myPayer()`, `SiteUsersLinkCustomers`) to also write it,
and re-pointing all three lookups onto it. Caught before it could ship as a real regression:
`EcommerceOrderPayerTest`/`EventOrderPayerTest`'s existing "signed-in shopper's own linked payer
wins" tests failed immediately once the join changed, and were fixed to seed
`site_user_reference` instead of the bigint — proving the join, not just the code path, was
exercised.

Eight further crash-risk sites (not just stale reads — a raw `->site_user_id = X` property
assignment or a `->where('site_user_id', ...)` clause becomes a hard SQL error, "Unknown
column," the moment the column is gone) were found and fixed across
`EcommerceCartService`/`EcommerceCartMergeService`/`EcommerceCheckoutService`/`EcommercePromotionService`
and their two controllers — every one of these `EcommerceCart`/`EcommerceOrder`-touching call
sites now takes and threads a `?string $siteUserReference` instead of `?int $siteUserId`,
resolved from `EcommerceShopperAuth::siteUserReference()` (already existed) rather than the
newly-narrowed `siteUserId()` (kept — favorites still key on the bigint, a genuinely different,
untouched table).

**Step 4 — stop writing the bigint.** `site_user_id` removed from `$fillable` on all ten models
that had it (`EcommerceOrder`, `_Cart`, `_PromotionRedemption`, `_GiftCard`, `EventOrder`,
`EventRegistration`, `EventWaitlistEntry`, `EventPassRedemption`, `AppointmentBooking`,
`FormSubmission`); the two tables with no Eloquent model at all
(`laravel_appointment_credits`, `laravel_form_partials`) already had no live writer beyond the
user-deletion cleanup, confirmed before concluding step 4 needed no code change there.

**Step 5 — drop the column.** New migration
`2026_09_14_000000_drop_site_user_id_from_consumer_tables.php` drops `site_user_id` (or, for
`laravel_site_user_customer_links`, `user_id` — its two indexes dropped first, matched against
the real local schema via `SHOW INDEX`, not assumed from the create migration) from all thirteen
tables. **Explicitly confirmed with the user before running it** — this migration's own header
states plainly that its `down()` re-creates an empty column, not the data it held, and the
identifier migration's own step table marks step 5 "No" under Reversible for that reason.
`siteusers:reference-drift` (below) reported zero drift across all thirteen tables on this
deployment immediately before the migration ran, and the full test suite (366 passed at that
point) plus a live `SHOW COLUMNS` check against the local database both confirmed a clean
result immediately after.

**`siteusers:reference-drift`** (new) — the step 3 gate the plan names but never gave a
command for. Reports missing-reference and mismatched-reference counts per table; exit 0 only
when every one of the thirteen tables (same list the add-reference migration used) is clean.

### §13.4 — changing an email, end to end

`PATCH /portal/api/siteusers/public/me/email` (`SiteUsersVerificationService::requestEmailChange()`)
and the public, token-only `POST /email/change/confirm`
(`confirmEmailChange()`), both wired into `SiteUsersPublicController` and both route files.
Reuses the exact §4.6 mechanism email-verify/password-reset already ride
(`laravel_site_user_verifications`, `purpose = 'email_change'`, `sent_to` **is** the pending
address — no new column), with its own confirm logic rather than reusing `verifyEmail()`'s
private `consumeToken()`: that method's invariant (`sent_to === user.email`) is true for
verify/reset but never true for a pending email change by definition, so a dedicated check
(re-verifying module-scoped uniqueness inside the same transaction as the swap, `409` not a raw
integrity error on a race) was written instead of forcing the shared helper to serve two
different rules.

Every rule from §13.4.2, each with a passing test: uniqueness checked twice (request and
confirm); the current password required unless the account has no password identity (an
SSO-only account proceeds on session + a token to the new address alone); the **old** address
always notified (new `SiteUsersEmailChangedMail`, un-suppressible); other sessions revoked on
confirm, **except** the one confirming it, if any (resolved by hashing the confirming request's
own bearer/cookie token against `laravel_site_user_sessions` — the confirm route carries no
session guard, so there may be none); `email_verified_at` set to the confirm time; rate limited
(the `su-emailchange-user`/`-ip` buckets `connections/07` §7.9 already built, ordered ahead of
the generic `/me` bucket per X38 — this round only exercised them, since a route to hit was the
one thing missing).

**SDK**: `SiteUsersClient.changeEmail()` / `confirmEmailChange()`, plus the matching ambient
`PortalSiteUsersInstance` methods in `portal.d.ts`.

**§7.10 closed**: `confirmEmailChange()`'s success path resolves the caller's `SiteUserPayer`
link, if any, and calls `PayerService::updateEmail()` — best-effort, logged, never on the
critical path (a provider timeout must not undo an email change that already committed).
`PayerService::updateEmail()` itself was already built and tested by `direct_payments/05` (DP6);
this round only had to give it a real caller.

### §13.6 — `siteusers:reformat-references`

New. Per user, in one transaction: generates a fresh `u...` reference, updates
`laravel_site_users`, then rewrites every consumer table's `site_user_reference` column that
still names the old one — the same thirteen-table list `siteusers:reference-drift` reads, plus
`laravel_site_user_payers` (not one of the thirteen, but a real join three other modules make
by reference — a reformatted user whose payer link wasn't rewritten would silently stop
resolving their own saved payer on their very next purchase). `--dry-run` reports without
writing. Confirmed the command still functions correctly now that step 5 removed the bigint
entirely — matching is by `site_user_reference` value alone, nothing left to resolve through.

### §13.4.4 — the Dashboard/Admin UI surfaces

Built once `connections/08` gave the Profile pane somewhere to live in. Three new backend pieces
the plan's original pass had no caller for, closing the gap the first "Not implemented" note
named:

- **`pendingEmailChange(user)`** — the `sent_to` of a live, unconsumed `email_change` token, or
  null. Surfaced on `me()` as `user.pending_email` (not a column) so the Profile pane's pending-
  change notice survives a reload — `changeEmail()`'s own `{ pending }` response only covers the
  one request that made it.
- **`resendEmailChange(user)`** / `POST /me/email/resend`** — re-mails the SAME pending address.
  No `current_password` re-check: the original request already proved it, and `issueToken()`
  already invalidates the prior token (the same "a forwarded old link stops working" rule §5.5's
  resend uses).
- **`cancelEmailChange(user)`** / `DELETE /me/email`** — invalidates the live token. Nothing is
  notified: nothing was ever changed.

`external/ui/site_users/ui.ts`'s Profile section (`account:*:profile`) gained a second panel,
`buildEmailPanelMarkup()`: the current address, a *Change* form (new address + current password)
when nothing is pending, or the pending-address notice with *Resend*/*Cancel* when something is
— switching entirely on `user.pending_email`, re-rendered via `outerHTML` (and re-wired) after
every mutation rather than toggled with CSS, since the two states are genuinely different forms.

**The admin per-user "set email directly" screen** — a new `SiteUsersVerificationService::
adminSetEmail()`, unlike the self-service flow in every way §13.4.4 calls for: no token, no
current-password check, **every** session revoked (there is no "confirming session" to except),
**both** addresses notified (the new address has no confirmation email of its own to serve as
its notice — a new `SiteUsersAdminEmailSetMail`, distinct from the self-service
`SiteUsersEmailChangedMail` still sent to the old address), and any live self-service
`email_change` token is cancelled in the same transaction so a stale link can't later confirm a
different address than the one the operator just set. `POST /portal/api/siteusers/modules/
{ref}/users/{id}/email` (admin-only, `SiteUsersAdminController::updateUserEmail()`) and a new
"Email" card on the admin user-detail page (`site-users.ts`), mirroring the existing "Change
password" card's own modal pattern.

**"Distinguishable forever"** (§13.4.4's own phrase): a new, permanent, append-only
`laravel_site_user_admin_actions` table (`SiteUserAdminAction`, `UPDATED_AT` disabled) — one row
per operator-initiated action, `{old_email, new_email}` in `detail`, the acting admin's reference
in `actor`. Mirrors `laravel_site_user_deprecations`' own append-and-keep style rather than any
request-scoped log; nothing here is ever pruned.

## Not implemented

Nothing — every surface §13.4.4 names is built. See "Residue" for scope notes that are not gaps.

## Files

**New:** `app/SiteUsers/SiteUserReference.php`, `app/Console/Commands/SiteUsersReferenceDrift.php`,
`app/Console/Commands/SiteUsersReformatReferences.php`,
`app/Mail/SiteUsers/SiteUsersEmailChangedMail.php`,
`resources/views/mail/site_users/email_changed.blade.php`,
`database/migrations/2026_09_14_000000_drop_site_user_id_from_consumer_tables.php`,
`database/migrations/2026_09_14_000001_add_site_user_reference_to_site_user_payers.php`,
`tests/Unit/SiteUsers/SiteUserReferenceTest.php`,
`tests/Feature/SiteUsers/SiteUsersReferenceDriftTest.php`,
`tests/Feature/SiteUsers/SiteUsersReformatReferencesTest.php`,
`tests/Feature/SiteUsers/SiteUsersEmailChangeTest.php`,
`app/Mail/SiteUsers/SiteUsersAdminEmailSetMail.php`,
`resources/views/mail/site_users/admin_email_set.blade.php`,
`app/Models/Externals/SiteUsers/SiteUserAdminAction.php`,
`database/migrations/2026_09_14_000002_create_site_user_admin_actions_table.php`

**Modified:** `app/SiteUsers/SiteUsersAuthService.php`, `SiteUsersSsoService.php` (generator
switch), `SiteUsersVerificationService.php` (`requestEmailChange()`/`confirmEmailChange()` added
in the first pass; `pendingEmailChange()`/`resendEmailChange()`/`cancelEmailChange()`/
`adminSetEmail()` added in this one, `PayerService` injected), `app/Mail/SiteUsers/SiteUsersTokenMail.php` (`email_change`
purpose), `resources/views/mail/site_users/token.blade.php`,
`app/Http/Controllers/Externals/SiteUsers/SiteUsersPublicController.php` (`changeEmail()`/
`confirmEmailChange()` added in the first pass; `me()`'s `pending_email`, `resendEmailChange()`,
`cancelEmailChange()` added in this one),
`app/Http/Controllers/Externals/SiteUsers/SiteUsersAdminController.php` (`updateUserEmail()` added,
`SiteUsersVerificationService` injected),
`app/Http/Middleware/SiteUsersPublicThrottle.php` (comment
updated — the `/me/email` bucket built in K6 is live now), `routes/portal.php`, `routes/auth1.php`
(`PATCH`/`POST`/`DELETE /me/email*` on both prefixes; `POST .../users/{id}/email` on the admin
prefix), `resources/js/external/api/site_users/SiteUsersClient.ts` (`resendEmailChange()`/
`cancelEmailChange()` added), `resources/js/external/api/site_users/SiteUsersTypes.ts`
(`SiteUserPublic.pending_email`), `resources/js/external/api/portal.d.ts`
(`PortalSiteUserPublic.pending_email`, matching ambient methods),
`resources/js/external/ui/site_users/ui.ts` (`buildEmailPanelMarkup()` and its wiring in
`renderDashboard()`; `renderDashboard()` itself exported for `tests/dashboard.test.ts`),
`resources/js/portal/admin/controllers/site_users/site-users.ts` (the "Email" card and its
modal on the user-detail page), `tests/Support/CreatesSiteUsersSchema.php`
(`laravel_site_user_admin_actions` test schema),
`tests/Feature/SiteUsers/SiteUsersAdminFeatureTest.php` (admin set-email tests added);
`app/Models/Externals/SiteUsers/SiteUserPayer.php` and its two
writers (`SiteUsersPublicController::myPayer()`, `SiteUsersLinkCustomers`); the ten consumer
models (`$fillable` — `site_user_id` removed); `EcommerceCartService.php`,
`EcommerceCartMergeService.php`, `EcommerceCheckoutService.php`, `EcommerceOrderService.php`,
`EcommercePromotionService.php`, `EcommerceShopperAuth.php`,
`app/Http/Controllers/Externals/Ecommerce/EcommerceCartController.php`,
`EcommerceShopperListsController.php`, `EventOrderService.php`,
`AppointmentBookingService.php`, `AppointmentLifecycleService.php`,
`app/Http/Controllers/Externals/Events/EventController.php` (`site_user_id` → `site_user_reference`
in an admin JSON response, closing a needless bigint leak); `tests/Support/CreatesSiteUsersSchema.php`
(`laravel_site_user_payers` test schema gained `site_user_reference`);
`tests/Feature/Ecommerce/EcommerceOrderPayerTest.php`, `tests/Feature/Events/EventOrderPayerTest.php`
(re-seeded onto the reference, not the bigint, after the join changed)

## Acceptance

**The identifier** (§13.7)

| Criterion | Status | Evidence |
|---|---|---|
| A new user's `reference_num` matches `^u[a-z0-9]{11}$`; an existing `usr-` reference still resolves | ✅ | `SiteUserReferenceTest` (7 tests), `SiteUsersAuthFeatureTest::test_registration_generates_the_new_reference_format` (live `/register` call) |
| Every consumer table carries `site_user_reference`, backfilled, zero drift | ✅ | `siteusers:reference-drift` — zero on the real local database immediately before step 5 ran |
| `grep -rn "site_user_id" app/Externals/Ecommerce app/Events app/Externals/Appointments` is empty | ✅ | Confirmed after step 5 — only comments and the unrelated `AppointmentHold`/favorites bigints remain, both correctly untouched |
| Sessions, identities, verifications still join on the bigint | ✅ | Unchanged — `laravel_site_user_sessions`/`_identities`/`_verifications` were never in the thirteen-table list |
| No API response contains `laravel_site_users.id` except the admin's | ✅ | Fixed one needless leak found in passing (`EventController.php`'s registrations list) |

**The email change** (§13.7)

| Criterion | Status | Evidence |
|---|---|---|
| In-use address in the same module → `422` | ✅ | `test_change_email_rejects_an_address_already_used_in_this_module` |
| Same address in a different module → allowed | ✅ | `test_change_email_allows_an_address_used_in_a_different_module` |
| Not changed until confirmed | ✅ | `test_change_email_mails_a_token_to_the_new_address_and_changes_nothing_yet` |
| A race (claimed between request and confirm) → `409`, not an integrity error | ✅ | `test_confirm_returns_409_when_the_address_was_claimed_in_the_meantime` |
| Old address notified | ✅ | `test_confirm_swaps_the_email_verifies_it_and_notifies_the_old_address` |
| Other sessions revoked; the confirming one survives | ✅ | `test_confirm_revokes_other_sessions_but_not_the_confirming_one` |
| SSO-only account changes email with no password | ✅ | `test_change_email_skips_the_password_check_for_an_sso_only_account` |
| Linked provider customers' email updated at the provider | ✅ | `test_confirm_propagates_the_new_email_to_the_linked_payer` (connections/07 §7.10) |
| Order/registration/booking email snapshots not rewritten | ✅ (unchanged) | Nothing in this round touches `customer_email`/`attendee_email`/`user_email` — confirmed by grep, not just by omission |
| Fourth change in an hour → `429` | ✅ | `SiteUsersMeThrottleTest::test_the_me_email_bucket_is_reached_before_the_generic_me_bucket` |

**The Dashboard/Admin UI surfaces** (§13.4.4)

| Criterion | Status | Evidence |
|---|---|---|
| The Profile pane's pending-change notice survives a reload | ✅ | `test_me_reports_the_pending_email_once_requested` |
| *Resend* re-mails the same address and invalidates the prior token | ✅ | `test_resend_reissues_a_token_to_the_same_pending_address_and_invalidates_the_old_one` |
| *Cancel* invalidates the token; nothing is notified | ✅ | `test_cancel_invalidates_the_pending_token_without_notifying_anyone` |
| Admin set-email needs no token and works for a user with no working address at all | ✅ | `test_admin_can_set_a_users_email_directly_no_token_required` |
| Admin set-email revokes every session (no session to except) | ✅ | Same test — asserts the requester's own live session token no longer validates |
| Admin set-email notifies both the old and new address | ✅ | Same test — asserts both `SiteUsersEmailChangedMail` (old) and `SiteUsersAdminEmailSetMail` (new) were sent |
| Admin set-email is recorded, distinguishable forever from a self-service change | ✅ | Same test — asserts a `laravel_site_user_admin_actions` row with `action: 'email_change'` and both addresses in `detail` |
| A stale self-service token can't later confirm a different address than the one the operator set | ✅ | `test_admin_set_email_invalidates_any_live_self_service_change_token` |

## Verification

```bash
cd w/www
php artisan siteusers:reference-drift                                  # 0 drift, real local database
php artisan migrate --step                                             # all three new migrations, applied
php artisan test tests/Unit/SiteUsers/SiteUserReferenceTest.php tests/Feature/SiteUsers/SiteUsersReferenceDriftTest.php tests/Feature/SiteUsers/SiteUsersReformatReferencesTest.php tests/Feature/SiteUsers/SiteUsersEmailChangeTest.php tests/Feature/SiteUsers/SiteUsersMeThrottleTest.php tests/Feature/SiteUsers/SiteUsersAdminFeatureTest.php
php artisan test tests/Feature/SiteUsers/ tests/Feature/Connections/                       # 181 passed
npx tsc --noEmit -p resources/js/external/api/tsconfig.json            # exit 0
npx tsc --noEmit -p resources/js/external/ui/tsconfig.json             # exit 0
npm run build-external-webpack                                         # exit 0
npm run build-external-ui-webpack                                      # exit 0
npm run test:external-ui                                                # 62 passed (before doc 08's own §8.5/§8.8 gap-closing work landed alongside this)
```

## Residue

- **`SiteUserReferenceLookup` is now unused** by every call site this round touched (all three
  of its former consumers — `EcommerceCartService`, `EcommerceCheckoutService`,
  `EventWaitlistService` — either stopped needing it or already had the reference in hand
  directly). Left in place rather than deleted: it's a small, correct, general-purpose utility
  (`id → reference_num`, process-cached) that a future dual-write bridge could still need, and
  removing it was not necessary to finish this round's work.
- **The three-real-bug find in step 3** (above) is the kind of gap a plan's own "this phase
  already did X" claim can hide — K3's completion record says "`currentUserId()` is gone from
  every consumer," which was true for identity resolution, but the `SiteUserPayer` join DP6
  added afterward was never covered by that audit because DP6 shipped later. Worth remembering
  when a later phase adds a new cross-reference to an aggregate an earlier phase already
  believed it had fully swept.
- **The admin email-change mailable reuses `SiteUsersEmailChangedMail` for the OLD address but
  not the new one** — that mailable's copy ("if you did not make this change, this address can
  no longer receive account notices") is written for someone losing access, which reads oddly
  addressed to the address that just gained it. A second, small mailable
  (`SiteUsersAdminEmailSetMail`) was written instead of stretching one template to serve both
  audiences.
