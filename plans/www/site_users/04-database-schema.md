# 04 — Database Schema

Six tables. Two exist in a shape that has to change; four are new.

## 4.1 The shape

```
laravel_site_user_modules                one per deployment of the module
  └── laravel_site_users                 the end users
        ├── laravel_site_user_identities       password | google | facebook | apple
        ├── laravel_site_user_sessions         one per device
        ├── laravel_site_user_verifications    email verify, password reset
        └── laravel_site_user_customer_links   → processor customers (08)

laravel_site_user_sso_integrations       provider credentials, per module
```

## 4.2 `laravel_site_user_modules` — replacing the sentinel row (**N3**, **M5**)

The module currently exists as a row in the *credentials* table with
`provider = '__systemsusers_marker__'`. It has no name, no settings and nowhere to put any.

| Column | Type | Notes |
|---|---|---|
| `id` | `id` | |
| `reference_num` | `string(32)` unique | `su-XXXX`; existing `sys-XXXX` values preserved ([02](02-the-rename.md) §2.5) |
| `name` | `string(120)` | Operator-facing label. A tenant with two modules needs to tell them apart |
| `is_active` | `boolean` default `true` | Off = public endpoints `404` ([03](03-architecture.md) §3.6) |
| `allow_password_auth` | `boolean` default `true` | A module can be SSO-only |
| `allow_registration` | `boolean` default `true` | Invite-only or admin-created populations |
| `require_email_verification` | `boolean` default `true` | §4.6 |
| `require_username` | `boolean` default `false` | §4.3's answer to **M7** |
| `password_min_length` | `smallint` default `12` | Policy lives **server-side**, not in the SDK (**M17**) |
| `session_lifetime_days` | `smallint` default `30` | |
| `allowed_origins` | `json` null | §4.7. Null = same-origin only |
| `created_by` | `string(64)` null | The portal admin who activated it |
| `timestamps` | | |

**`require_email_verification` defaults true and `password_min_length` defaults 12** — both
stricter than today's behaviour (no verification, `min:8`). Defaults are what most modules
will run with forever, so they should be the values you would choose deliberately, not the
weakest ones that pass.

## 4.3 `laravel_site_users` — changed

> **Extended by [13](13-identity-and-email-change.md) (2026-09-12).** `reference_num`'s
> format becomes `^u[a-z0-9]{11}$` — twelve alphanumeric characters, always starting with a
> letter — and it becomes **the identifier every other module stores**, replacing the bigint
> `site_user_id` on orders, registrations, bookings and the rest. The bigint `id` stays the
> primary key and the join key for this aggregate's own children (identities, sessions,
> verifications). `email` becomes changeable ([13](13-identity-and-email-change.md) §13.4);
> the `su_users_module_email_uq` index below is what makes "not already in use" enforceable.

| Column | Change |
|---|---|
| `site_users_id` | Renamed from `systemsusers_id`; now FK-by-convention to `modules.reference_num` |
| `password_hash` | **Moves** to `identities` (§4.4) |
| `provider`, `provider_id` | **Move** to `identities` (§4.4) — this is what lets one user hold several |
| `auth_token`, `token_expires_at` | **Move** to `sessions` (§4.5) — and are hashed there (**M4**) |
| `email` | `string(255)` null → stays nullable, but see the constraint below |
| `email_verified_at` | **New**, `timestamp` null |
| `display_name` | **New**, `string(120)` null — what a module shows; `username` stays the login handle |
| `last_login_at` | **New**, `timestamp` null |
| `is_active` | Unchanged |
| `metadata` | **New**, `json` null — a consuming module's own small facts. Not a dumping ground: see below |

```php
$table->unique(['site_users_id', 'email'], 'su_users_module_email_uq');
$table->unique(['site_users_id', 'username'], 'su_users_module_username_uq');
```

**Those two indexes are the fix for M7's second half.** Today uniqueness is checked in PHP
*only when the field is present*, so two users with no email coexist and the third collides.
A composite unique with MySQL's NULL-distinct behaviour gives exactly the right semantics:
many users may have no email; no two may share one within a module.

The first half of **M7** — a user with neither password nor provider — is fixed structurally
by §4.4: **a user with no identity row cannot authenticate, and creating one requires an
identity in the same transaction.**

On `metadata`: it is for a consuming module to stash something small and app-specific
(a preferred store currency, a default venue). It is **not** where Site Users' own fields
go — those get columns, for the reasons [12 §12.3 of the billing plan](../direct_payments/26-advanced-policy.md)
sets out. Reviewers should push back on any Site Users feature that proposes to live in it.

## 4.4 `laravel_site_user_identities` — new (**N5**)

One row per way a user can prove who they are.

| Column | Type | Notes |
|---|---|---|
| `id` | `id` | |
| `site_users_id` | `string(32)` index | Denormalised for scoping ([03](03-architecture.md) §3.3) |
| `user_id` | `unsignedBigInteger` index | → `users.id` |
| `type` | `string(20)` | `password` \| `google` \| `facebook` \| `apple` |
| `password_hash` | `string(255)` null | `type = password` only |
| `provider_id` | `string(255)` null | The provider's subject id — SSO only |
| `provider_email` | `string(255)` null | As reported by the provider at link time |
| `last_used_at` | `timestamp` null | |
| `timestamps` | | |

```php
$table->unique(['user_id', 'type'], 'su_identities_user_type_uq');
$table->unique(['site_users_id', 'type', 'provider_id'], 'su_identities_provider_uq');
```

The second index is the one that matters for **M3**: a provider subject id can map to **at
most one user per module**. Even with the code exchange in place, that constraint is what
stops a race from producing two accounts for the same Google identity.

**Account linking** falls out of this: a user who registered with a password and later signs
in with Google whose `provider_email` matches their verified `email` gets an identity added,
not a second account. That match must require `email_verified_at` — linking on an unverified
address lets someone claim an account by registering its address first
([06](06-sso.md) §6.5).

## 4.5 `laravel_site_user_sessions` — new (**N4**, **M4**, **M8**)

| Column | Type | Notes |
|---|---|---|
| `id` | `id` | |
| `site_users_id` | `string(32)` index | |
| `user_id` | `unsignedBigInteger` index | |
| `token_hash` | `string(64)` **unique** | `hash('sha256', $token)`. **The plaintext is never stored** |
| `identity_type` | `string(20)` | How they signed in this time |
| `ip_address` | `string(45)` null | |
| `user_agent` | `string(255)` null | So a user can recognise a device to revoke |
| `expires_at` | `timestamp` index | Enforced (**M13**) |
| `revoked_at` | `timestamp` null | |
| `last_seen_at` | `timestamp` null | |
| `timestamps` | | |

**SHA-256, not bcrypt.** A session token is 32+ bytes of CSPRNG output with no entropy to
strengthen, and it is verified on every authenticated request — bcrypt would put a
deliberate ~100ms cost on every page load to defend against a preimage attack that a random
256-bit value already makes infeasible. Passwords are different and are hashed with
`Hash::make` in §4.4.

Lookup is by `token_hash`, so the unique index is also the query path.

**Expired and revoked rows are deleted by a sweep, not left forever.** A tenant with 50 000
users at 30-day sessions accumulates rows indefinitely otherwise. Daily, delete where
`expires_at < now() - 7 days` — the extra week keeps "when did this session end" answerable
for support.

## 4.6 `laravel_site_user_verifications` — new (**M9**, **M10**)

One table for both email verification and password reset. They are the same mechanism: a
single-use, expiring, hashed token tied to a user and a purpose.

| Column | Type | Notes |
|---|---|---|
| `id` | `id` | |
| `site_users_id` | `string(32)` index | |
| `user_id` | `unsignedBigInteger` index | |
| `purpose` | `string(20)` | `email_verify` \| `password_reset` |
| `token_hash` | `string(64)` unique | Same hashing rationale as §4.5 |
| `sent_to` | `string(255)` | The address it went to — a reset must not follow a later email change |
| `expires_at` | `timestamp` | Verify: 24h. Reset: **1h** |
| `consumed_at` | `timestamp` null | Single use, enforced |
| `timestamps` | | |

`sent_to` rather than re-reading `users.email` at redemption is the detail that prevents a
real attack: request a reset, change the account email, redeem the old link.

Issuing a new token for the same `(user, purpose)` **consumes the previous one**, so a
forwarded old email cannot be used after a re-request.

## 4.7 `allowed_origins` and why it is on the module

The SDK is designed to run on sites the tenant does not control ([07](07-externals-api.md)).
The module id is public — it is in the page source. So the module id alone cannot be the
authorisation to create accounts against a module.

`modules.allowed_origins` is a JSON array of origins permitted to call the public endpoints.
Null means same-origin only, which is the safe default for a builder-published site. A tenant
using the SDK on `shop.example.com` adds that origin explicitly.

Enforced server-side against the `Origin` header, before any credential is read
([05](05-authentication.md) §5.6). Not CORS alone — CORS is a browser courtesy and a
`curl` ignores it.

## 4.8 `laravel_site_user_sso_integrations` — mostly unchanged

Keeps its shape from [01](01-current-state-audit.md) §1.1, with:

- `systemsusers_id` → `site_users_id`
- **The `__systemsusers_marker__` rows are deleted** once §4.2's modules table is populated.
  That is the whole point of §4.2
- `scopes` — **new**, `string(255)` null. Providers differ, and hardcoding scopes in the
  client is how a provider config change becomes a deploy

`client_secret_encrypted` is already encrypted at rest. Confirm it is written through
`Crypt::encryptString` and never returned by any endpoint — including the admin one, which
should return a `has_secret` boolean rather than the value.

## 4.9 Migrations

| File | Contents |
|---|---|
| `..._create_site_user_modules_table.php` | §4.2 |
| `..._create_site_user_users_table.php` | §4.3 — new table, copied from the old ([02](02-the-rename.md) §2.5) |
| `..._create_site_users_identities_table.php` | §4.4 |
| `..._create_site_user_sessions_table.php` | §4.5 |
| `..._create_site_user_verifications_table.php` | §4.6 |
| `..._create_site_user_sso_integrations_table.php` | §4.8 |
| `..._create_site_user_customer_links_table.php` | [08](08-direct-payments-link.md) §8.2 |
| `..._create_builder_routes_table.php` | [12](12-builder-integration.md) §12.5.1 — route protection config. Owned by the **builder**, not this module, but created here so the whole schema lands together |
| `..._create_api_guards_table.php` | [12](12-builder-integration.md) §12.8.3 — API path protection. Same ownership note |
| `..._backfill_site_user_from_systemsusers.php` | §4.10 |

All guarded with `Schema::hasTable()`, matching the codebase's existing style.

## 4.10 Backfill

1. **Modules** — for each distinct `systemsusers_id` in the old tables, create a module row.
   Name it `"Site Users"` if there is exactly one; `"Site Users ({id})"` otherwise.
2. **Users** — copy row for row, mapping `systemsusers_id` → `site_users_id`.
   `email_verified_at` is **null** for every migrated user.
3. **Identities** — one row per migrated user: `password` where `password_hash` is set,
   else the provider from `provider`/`provider_id`. **A user with neither gets no identity
   row and is reported**, not silently dropped — that is **M7**'s population and someone has
   to decide what those accounts are.
4. **Sessions** — **not migrated.** Existing `auth_token` values are plaintext (**M4**) and
   authenticate nothing (**M2**). Everyone is logged out at cutover, which is correct: the
   alternative is importing plaintext tokens into a table whose entire purpose is that they
   are hashed.

Step 2's decision deserves stating: **migrated users are marked unverified.** Their addresses
were never verified, and pretending otherwise imports an unknown quantity of unowned email
addresses into a system that now treats verification as meaningful. Modules with
`require_email_verification` on should run a one-off "confirm your address" campaign rather
than have the flag lie.
