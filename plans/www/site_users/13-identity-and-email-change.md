# 13 — The User Identifier, and Changing an Email

Two changes that belong together: **`reference_num` becomes the identifier every other module
stores**, and **a user can change the email address they sign in with**. The first makes the
second safe, and the second is why the first is worth doing.

> **Added 2026-09-12**, after P0–P9 landed. Extends [04](04-database-schema.md) §4.3
> (the format and the identifier rule), [05](05-authentication.md) §5.5 (the change flow
> reuses the verification mechanism) and [08](08-direct-payments-link.md) §8.2 (the link
> table re-keys). Consumed by
> [`../connections/`](../connections/README.md), which stores a user reference on every
> purchase, registration and booking.

## 13.1 The two identities a user has

| | What | Who sees it | Changes? |
|---|---|---|---|
| **`reference_num`** | The identifier every module stores | Nobody outside the system, and no human is asked to type it | **Never** |
| **`email`** | The address they sign in with and receive mail at | The user, on every screen and in every message | **Yes** — §13.4 |

That split is the whole design. Everything in the database points at a `reference_num`;
everything a person reads says an email address. The `laravel_site_users.id` bigint stays as
the table's primary key and its own aggregate's join key, and stops appearing anywhere else.

### One correction to the stated rationale, then the real ones

Changing an email does not, on its own, threaten a bigint foreign key — both `id` and
`reference_num` are stable across an email change, so "so a user can change their email
without migration issues" is not by itself an argument for this refactor.

The arguments that do hold:

- **Email is an implicit join key today in the two places that matter**, and those are the
  ones an email change breaks: the `email_match` reconciler
  ([08](08-direct-payments-link.md) §8.5) and the connections plan's guest-claim service
  ([`../connections/09`](../connections/09-module-self-service.md) §9.7). Making the stored
  identifier explicit and opaque is what lets email become mutable at all.
- **A reference is safe to expose; a sequential id is not.** `reference_num` already appears
  in `toPublicArray()`. A bigint in a URL or a payload leaks how many users a tenant has and
  invites enumeration.
- **A reference survives an export, a reseed and a tenant migration.** Auto-increment ids do
  not, and this estate moves data between environments.
- **It removes a whole class of silent bug.** `site_user_id` (bigint, a person) and
  `site_users_id` (string, a module) are one letter apart and both live on the same tables.
  Comparing the wrong one matches nothing rather than erroring.

## 13.2 The format

```
^u[a-z0-9]{11}$          12 characters · alphanumeric · always starts with a letter
                         uk3m9x2p1q7a
```

```php
/**
 * AFS_SITE_USERS_MODIFIER_ADDITION — plans/www/site_users/13-identity-and-email-change.md §13.2.
 *
 * Twelve alphanumeric characters, first character a letter, never a digit.
 *
 * Three choices inside that rule:
 *
 *  - The leading character is always `u`. The rule requires *a* letter; making
 *    it a constant one keeps the estate's "you can tell what a reference is by
 *    looking at it" property, which every other reference here has via a prefix
 *    (`ord_`, `evt_`, `crt_`, `su-`). A support engineer reading a log needs to
 *    know `uk3m9x2p1q7a` is a user without a lookup.
 *
 *  - Lowercase only. `laravel_site_users` is utf8mb4_unicode_ci, a
 *    CASE-INSENSITIVE collation, so `Uk3M` and `uk3m` would collide on the
 *    unique index and either could satisfy a lookup for the other. Generating
 *    mixed case would buy apparent entropy and real ambiguity. (If mixed case
 *    is ever wanted, the column has to become `_bin` first — a separate change,
 *    not a generator tweak.)
 *
 *  - No hyphen, per the requirement. The old `usr-` + 8 form was also twelve
 *    characters but not alphanumeric.
 *
 * 36^11 ≈ 1.3 × 10^17, about 57 bits. This is an identifier, not a credential —
 * holding one grants nothing, the session does — so 57 bits is generous. It is
 * enough that the birthday collision risk is negligible at any tenant size, and
 * the generator retries on the unique index regardless.
 */
final class SiteUserReference
{
    private const ALPHABET = 'abcdefghijklmnopqrstuvwxyz0123456789';
    public const PATTERN = '/^u[a-z0-9]{11}$/';

    public static function generate(): string
    {
        $out = 'u';
        for ($i = 0; $i < 11; $i++) {
            $out .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }
        return $out;
    }

    public static function isValid(string $value): bool
    {
        return (bool) preg_match(self::PATTERN, $value);
    }
}
```

`random_int` is required, not `Str::random`: it is the CSPRNG, and while a reference is not a
credential, a predictable one would let someone guess who exists before the session check
refuses them.

Both current call sites — `SiteUsersAuthService` (~line 85) and `SiteUsersSsoService`
(~line 464) — replace `'usr-' . Str::random(8)` with `SiteUserReference::generate()` and keep
their existing retry-on-collision loop.

### Existing `usr-XXXXXXXX` references

**They stay.** Regenerating them would mean rewriting every consumer row in lockstep, and the
gain is cosmetic. The column accepts both; the *validator* accepts both; only the *generator*
is new. A `siteusers:reformat-references` command exists for a tenant who wants a clean set,
and it is opt-in, transactional, and rewrites every consumer column in the same transaction —
described in §13.6 and not run by the migration.

## 13.3 Which references convert, and which do not

The rule, so a reviewer can decide a new table in one read:

> **A reference to a user that crosses a module boundary stores `reference_num`.
> A row that is part of the user aggregate and dies with the user keeps the bigint `id`.**

| Table | Column today | After | Why |
|---|---|---|---|
| `laravel_site_user_identities` | `user_id` bigint | **unchanged** | Inside the aggregate. Deleted with the user, never read outside authentication |
| `laravel_site_user_sessions` | `user_id` bigint | **unchanged** | Same, and it is the hottest lookup in the system — every guarded request resolves a session |
| `laravel_site_user_verifications` | `user_id` bigint | **unchanged** | Same |
| `laravel_site_user_customer_links` | `user_id` bigint | **`site_user_reference` char(12)** | The purchase join (§13.5). Read by the payments surface, and the one place a stable external reference actually earns its cost. **Its index moves too** — `su_customer_links_user_idx` becomes `(site_users_id, site_user_reference)`, or the card-list lookup falls off it (X39) |
| `laravel_ecommerce_orders`, `_carts`, `_promotion_redemptions`, `_gift_cards`, `_return_requests` | `site_user_id` bigint | **`site_user_reference` char(12)** | Cross-module |
| `laravel_event_orders`, `_registrations`, `_waitlist`, `_pass_redemptions` | `site_user_id` bigint | **`site_user_reference` char(12)** | Cross-module |
| `laravel_appointment_bookings`, `_waitlist` | `site_user_id` bigint | **`site_user_reference` char(12)** | Cross-module |
| `laravel_form_submissions`, `laravel_form_files` | `site_user_id` bigint | **`site_user_reference` char(12)** | Cross-module — and nothing writes it today ([`../connections/01`](../connections/01-current-state-audit.md) X33), so this one is free |

`char(12)` not `varchar`: every value is exactly twelve characters, fixed-width compares
faster, and the index is smaller. Nullable everywhere, for the same reason it is nullable
today — a guest writes nothing.

### The migration shape

Add → backfill → dual-read → cut over → drop. Five steps, three releases, no downtime, and
each step is safe to stop at.

```php
/**
 * AFS_SITE_USERS_MODIFIER_ADDITION — plans/www/site_users/13-identity-and-email-change.md §13.3.
 *
 * Step 1 of 5, repeated per table. Adds the column and backfills from the join.
 * The old column is untouched, so a rollback is a deploy, not a migration.
 */
Schema::table($table, function (Blueprint $t) {
    if (! Schema::hasColumn($t->getTable(), 'site_user_reference')) {
        $t->char('site_user_reference', 12)->nullable()->after('site_user_id')
          ->index($indexName);          // explicit name — several of these tables are near MySQL's 64-char limit
    }
});

DB::statement("
    UPDATE {$table} t
    JOIN laravel_site_users u ON u.id = t.site_user_id
    SET t.site_user_reference = u.reference_num
    WHERE t.site_user_id IS NOT NULL AND t.site_user_reference IS NULL
");
```

| Step | Contents | Reversible |
|---|---|---|
| 1 | Add + backfill every column above | Yes — drop the column |
| 2 | **Write both** on every insert and update. Read the bigint | Yes |
| 3 | **Read the reference**, keep writing both. `siteusers:reference-drift` reports any row where the two disagree, and must report zero for a full release | Yes |
| 4 | Stop writing `site_user_id`. Models drop it from `$fillable` | Yes, with a re-backfill |
| 5 | Drop `site_user_id` from all twelve tables | No |

Step 3's drift report is the gate. A row where the two columns disagree means a write path was
missed, and finding it by query beats finding it by a user seeing someone else's order.

## 13.4 Changing an email

**Verify before switching.** The new address is proved before it becomes the login, because a
typo would otherwise lock the user out of their own account and mail their notifications to a
stranger.

```
PATCH /portal/api/siteusers/public/me/email    { "email": "new@example.com", "current_password": "…" }
   │
   ├─ 401  no session
   ├─ 422  SU_E_EMAIL_IN_USE      another user in THIS module already has it
   ├─ 422  SU_E_EMAIL_INVALID
   ├─ 403  SU_E_PASSWORD_REQUIRED wrong current password (skipped for an SSO-only account — §13.4.2)
   └─ 202  { pending: "new@example.com" }   token mailed to the NEW address
   │
POST /portal/api/siteusers/public/email/change/confirm   { "module": "su-…", "token": "…" }
   │
   ├─ 422  SU_E_TOKEN_INVALID     expired, consumed, or unknown
   ├─ 409  SU_E_EMAIL_IN_USE      someone claimed it between request and confirm — re-checked here
   └─ 200  { user }               email swapped, old address notified, other sessions revoked
```

### 13.4.1 Where the pending address lives

`laravel_site_user_verifications` already models exactly this — a single-use expiring token
with a `purpose` and a `sent_to` ([04](04-database-schema.md) §4.6). It gains one purpose
value:

```php
'purpose' => 'email_change',     // alongside 'email_verify' and 'password_reset'
'sent_to' => $newEmail,          // the pending address IS the sent_to — no new column
```

No schema change. The pending address is not written to `laravel_site_users` until the token
is consumed, so an abandoned change leaves no trace beyond an expiring row, and a second
request supersedes the first (the service already deletes prior unconsumed tokens for a
`(user, purpose)` pair).

### 13.4.2 The rules

| Rule | Why |
|---|---|
| **Uniqueness is checked twice** — at request and again inside the confirm transaction | `su_users_module_email_uq` is per module, so the check is `where('site_users_id', …)->where('email', …)`. Between the two calls someone else can register that address; the index would then throw a raw integrity error instead of a clean `409` |
| **The current password is required** — unless the user has no password identity | An attacker on a borrowed session must not be able to move the account to their own address. An SSO-only user has no password to supply, so the session plus a token to the *new* address is the proof, and the old address is still notified |
| **The old address is notified**, always, and the message is not suppressible | It is the only signal a user gets that their account was taken over. It names the new address and says who to contact |
| **Other sessions are revoked** on success | An email change is a credential change. `SiteUsersSessionService::revokeAllForUser($id, exceptSessionId: $current)` already exists |
| **`email_verified_at` is set** to the confirm time | The new address is proved by the same mechanism that proves a new registration |
| **Rate limited** | `su-emailchange-user` 3/hour and `su-emailchange-ip` 10/hour, alongside the existing buckets in `SiteUsersPublicThrottle` — and the `/me/*` buckets that plan adds ([`../connections/07`](../connections/07-direct-payments-connection.md) §7.9) |
| **Username is unaffected** | Where a module uses `require_username`, that is a separate handle with its own uniqueness index |

### 13.4.3 What has to follow the change

```php
/**
 * AFS_SITE_USERS_MODIFIER_ADDITION — plans/www/site_users/13-identity-and-email-change.md §13.4.3.
 *
 * After the swap commits. Each is best-effort and logged: none of them may fail
 * the change, because the email is already the user's login and a half-applied
 * change is worse than a lagging side effect.
 */
```

| Follow-up | Detail |
|---|---|
| **Linked provider customers** | For every row in `laravel_site_user_customer_links`, update that customer's email at the provider — the logic already in `ProcessorCustomerController::updateEmail()`, extracted to `ProcessorCustomerService` by [`../connections/07`](../connections/07-direct-payments-connection.md) §7.5. This is what keeps receipts and provider-side support pointing at the right address. **The link itself does not move**: it keys on `provider_customer_id`, not email (§13.5) |
| **Nothing else** | Orders, registrations and bookings hold `customer_email` / `attendee_email` / `user_email` **snapshots** and keep the address in force when the purchase happened. That is deliberate everywhere in this estate and the change must not rewrite them — a receipt reprint has to match the receipt that was sent |

### 13.4.4 The surfaces

- **SDK**: `su.changeEmail({ email, current_password })` → `202`, and
  `su.confirmEmailChange(token)`. `portal.d.ts` in the same pass
  ([07](07-externals-api.md) §7.6).
- **Dashboard**: the Profile pane gains the field, showing the current address, a *Change*
  action, and a pending-change notice with *Resend* and *Cancel* while a token is live
  ([`../connections/08`](../connections/08-dashboard-sections.md) §8.4).
- **Admin**: the per-user screen can set an address directly, with no token, because an
  operator handling a support call is the fallback for a user locked out of both addresses.
  It revokes sessions and notifies both addresses, and is recorded in the deprecation-style
  audit log so an operator-initiated change is distinguishable forever.

## 13.5 Referencing a purchase to a site user

The proposal — *the provider customer id is how a purchase ties to a user* — is right for the
case it is aimed at, and it is worth being precise about which case that is, because there are
two and they need different joins.

```
                      ┌─ a purchase made THROUGH a module ─────────────────────┐
  site user  ─────────┤  order / event order / booking                          │
   reference          │  carries `site_user_reference` directly                 │
       │              └─────────────────────────────────────────────────────────┘
       │
       │              ┌─ a purchase made through Direct Payments ALONE ─────────┐
       └──────────────┤  a donation, a DP block, a subscription                 │
   customer link      │  no façade row exists — the ONLY join is the customer   │
                      └─────────────────────────────────────────────────────────┘
```

| | Façade purchases | Direct-Payments-only purchases |
|---|---|---|
| Join | `site_user_reference` on the row | `laravel_site_user_customer_links` → `provider_customer_id` → the invoice's `customer_id` |
| Exists when | The module created a record | Always, once the user has checked out on that processor |
| Survives an email change | Yes | **Yes — this is the point** |
| Survives a provider recreating the customer | n/a | Yes, via the resolver's `adopt()` ([`../connections/07`](../connections/07-direct-payments-connection.md) §7.3) |

So: **the customer link is not the only way to reference a purchase, but it is the only way
for a purchase with no façade record — and it is the only join that is guaranteed to exist for
every paid thing.** Both are used, and `/me/purchases` unions them
([`../connections/07`](../connections/07-direct-payments-connection.md) §7.6).

Three consequences worth stating:

- **All three providers mint a customer, so the join is universal.** Stripe, Square and PayPal
  each return a `provider_customer_id`, and `ProcessorCustomer` already stores it per
  `(processor, provider, mode)`. The link table's unique index is on
  `(processor_reference_num, provider_customer_id)`, so one provider customer maps to at most
  one site user — which is what stops one shopper's saved card reaching another's order.
- **The link must be created at the first signed-in checkout, not discovered later.** That is
  what `GET /me/customers?create=1` guarantees
  ([`../connections/07`](../connections/07-direct-payments-connection.md) §7.4). Without it the
  join is retrospective and depends on email matching, which is the thing this document is
  removing.
- **The invoice's `user_id` stamp is not a join.** It is client-settable, so it stays support
  metadata only ([`../connections/01`](../connections/01-current-state-audit.md) X23). With
  this refactor it carries `su:<reference_num>`, which is now a stable, opaque value — but the
  rule does not change.

`laravel_site_user_customer_links.user_id` becomes `site_user_reference char(12)` under §13.3,
which also removes the last reason for that table to know a bigint.

> **Then the table folds away entirely.**
> [`../direct_payments/05`](../direct_payments/05-implementer-contract.md) §5.5 replaces it
> with `laravel_site_user_payers` — one row per user, holding a Direct Payments **payer**
> reference rather than a per-processor provider customer id. The reasoning in §13.5 above
> survives intact and gets stronger: the join from a purchase to a person stops depending on a
> value the provider issued, so it survives a **provider change** as well as an email change.
> Site Users then holds no provider identifier at all.

## 13.6 `siteusers:reformat-references`

Opt-in, for a tenant who wants every user on the new format.

```bash
php artisan siteusers:reformat-references {module} [--dry-run]
```

Per user, in one transaction: generate a new reference, update `laravel_site_users`, then
update every consumer column in §13.3's table. Refuses to run before step 3 of the migration
(there is no `site_user_reference` column to update yet) and refuses on a deployment whose
drift report is non-zero.

**Not scheduled, and not part of any migration.** Rewriting identifiers is the kind of thing
that is fine until one external system had stored one.

## 13.7 Acceptance

**The identifier**

- A new user's `reference_num` matches `^u[a-z0-9]{11}$`; an existing `usr-` reference still
  resolves everywhere.
- Every table in §13.3's "after" column carries `site_user_reference`, backfilled, with no row
  where it disagrees with the bigint — `siteusers:reference-drift` reports zero.
- `grep -rn "site_user_id" app/Externals/Ecommerce app/Events app/Externals/Appointments` is empty after step 5.
- Sessions, identities and verifications still join on the bigint — asserted, so a later
  refactor does not "finish the job" and slow every guarded request.
- No API response contains `laravel_site_users.id` except the admin's.

**The email change**

- A change to an address held by another user in the same module → `422 SU_E_EMAIL_IN_USE`,
  and the email is unchanged.
- The same address held by a user in a *different* module → allowed.
- The address is **not** changed until the token is confirmed; an abandoned request expires
  and leaves the account untouched.
- A second user registering the pending address between request and confirm makes the confirm
  return `409`, not a database integrity error.
- The old address receives a notification naming the new one.
- Other sessions are revoked; the current one survives.
- An SSO-only account can change its email without supplying a password.
- Every linked provider customer's email is updated at the provider; the link rows are
  unchanged.
- Order, registration and booking email snapshots are **not** rewritten.
- Four changes in an hour → `429`.
