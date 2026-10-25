# 10 — The Admin Dashboard

`resources/js/portal/admin/controllers/site_users/site-users.ts` — 663 lines today,
renamed from `systems_users/systems-users.ts`.

## 10.1 What exists

| Screen | State |
|---|---|
| Activation | Works — `/status`, `/activate`. But activation writes a sentinel row (**M5**) |
| SSO integrations | List, save, delete. Per provider |
| User list | Paginated, searchable |
| User detail | View, delete, set password |

The UI is in reasonable shape. What changes is mostly what sits behind it, plus three
additions the new schema makes possible.

## 10.2 Module management — new (**M5**, **M6**)

Activation becomes real module creation ([04](04-database-schema.md) §4.2), and the admin
gets a settings screen it has never had:

| Setting | Notes |
|---|---|
| Name | A tenant with two modules must be able to tell them apart |
| Active | Off → public endpoints `404` |
| Allow password auth / allow registration | SSO-only and invite-only populations |
| Require email verification | Default **on** |
| Password minimum length | Default **12**. Server-enforced ([05](05-authentication.md) §5.4) |
| Session lifetime | Days |
| **Allowed origins** | The list from [04](04-database-schema.md) §4.7 |
| Link payment customers | [08](08-direct-payments-link.md) §8.6, default off |

**Allowed origins deserves care in the UI**, because it is a security control an operator
will meet without context. Show it with the consequence spelled out — *"Only these sites may
create accounts or sign users in. Leave empty to allow only this website."* — and validate
that entries are origins (`https://shop.example.com`), not URLs with paths, which is the
mistake that silently breaks matching.

The admin also stops guessing which module it is operating on: every endpoint takes the
module reference explicitly (**M6**), even while most tenants have exactly one.

## 10.3 User management — extended

Existing screens gain what the new schema exposes:

| Addition | Source |
|---|---|
| **Identities** — password / Google / Facebook / Apple, with linked date | [04](04-database-schema.md) §4.4 |
| **Sessions** — device, IP, last seen, with per-session revoke | §4.5 |
| **Email verified** — status, plus "resend verification" | §4.6 |
| **Linked payment customers** — processor, provider, customer id | [08](08-direct-payments-link.md) |
| **Revoke all sessions** | [05](05-authentication.md) §5.2 |

The sessions panel is the one with real operational value: *"a user says someone else is in
their account"* is answerable — and fixable — for the first time.

Set-password stays, and now **revokes every session** as a consequence
([05](05-authentication.md) §5.2). An admin resetting a password after a compromise report
must log the attacker out; today it would not.

## 10.4 SSO configuration — corrected

The screen keeps its shape and changes what it returns.

**The secret is never sent to the browser** ([06](06-sso.md) §6.6). The list endpoint returns
`has_secret: true|false`; the form shows a "Secret set — replace?" state rather than a
populated field.

This is not theoretical hygiene. With **M1** unfixed, the admin endpoint is reachable without
authentication, so today `GET /auth1/api/systemsusers/sso` is an unauthenticated read of
every tenant's OAuth client secrets. Fixing **M1** closes the exposure; not returning the
secret means a future auth mistake is not immediately fatal.

Also added: `scopes` per provider ([04](04-database-schema.md) §4.8), and a **redirect URI
helper** that shows the exact URI to paste into the provider's console — mismatched redirect
URIs are the single most common reason an OAuth setup fails, and the value is derivable.

## 10.5 What the admin cannot do

| Not here | Why |
|---|---|
| Read a user's password | Hashed. The screen offers *set*, not *view* |
| Read an SSO client secret | §10.4 |
| Read a session token | Hashed ([04](04-database-schema.md) §4.5) |
| Impersonate a user | Deliberately absent. A support tool that mints a session for an arbitrary account is the thing **M3** accidentally built; if it is ever wanted it needs its own audit trail and its own decision |
| Create a user with no identity | [04](04-database-schema.md) §4.4 — the schema refuses |

## 10.6 Rename surface

Per [02](02-the-rename.md): directory, controller file, view ids `systems-users` →
`site-users`, route `/systems-users` → `/site-users`, nav label **System Users** →
**Site Users**, and all `/portal/api/systemsusers/*` fetch URLs.

The admin UI is a **Phase A** rename — it is internal, has no published-page consumers, and
nothing outside the portal calls it. It does not need the deprecation window the public API
does.

## 10.7 Acceptance

- A second module can be created, listed and operated independently (**M6**).
- Every admin endpoint takes an explicit module reference.
- No admin response contains `client_secret`, `password_hash` or `token_hash` — assert
  against a seeded module.
- Setting a user's password from the admin revokes their sessions.
- The sessions panel lists two devices for a user signed in twice, and revoking one leaves
  the other working.
- An origin entered as `https://shop.example.com/login` is rejected with a message naming the
  expected form.
- The redirect-URI helper's value, pasted into a provider console, completes an SSO login
  end to end.
