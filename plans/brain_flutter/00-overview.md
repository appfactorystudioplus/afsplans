# 00 — Overview

## Completion document

Not yet carried out. When it is: `complete/00-overview.complete.md`.

## 0.1 The problem

The Brain (`w/www`) is where appfactory studio's members and their resources are managed. Today that
is a browser SPA at `/portal/admin`, and it only works from a desk. There is no way to look up a
member, suspend one, change a plan, or see whether someone's payment failed from a phone.

A mobile client for the Brain admin needs three things the browser SPA does not give a phone for free:

1. **Authentication without a login.** The owner is the only user. A login form on a phone — typed
   password, session expiry, "remember me" — is friction for no benefit.
2. **Subscription control.** The browser's Subscription and Payments tabs do not work (audit
   [BA8](01-current-state-audit.md)), and the Direct Payments actions that do work are self-service
   only ([BA9](01-current-state-audit.md)).
3. **A real "remove member".** The API has none ([BA7](01-current-state-audit.md)).

## 0.2 Goals

| # | Goal |
|---|------|
| G1 | See **all** members: paged, searchable (email, reference, username, Direct Payment id), filterable by status and plan |
| G2 | **Add** a member, with the same server-side behaviour as the Brain's create form, including Checkpoint registration |
| G3 | **Edit** a member: profile fields, **plan** (reason + audit), **account status** (audit), with stale-record protection |
| G4 | **Remove** a member: an interim that works today, and the real soft/hard delete as soon as `account_close` provides it |
| G5 | **Control subscriptions** through Direct Payments: see state, cancel, change plan, sync from the provider, refund a charge, see dunning/billing state |
| G6 | See a member's **allocation usage** and manage **limit overrides** (the "give this customer five more" case) |
| G7 | Be **permanently signed in** as the Brain member `appfactorystudio` — no login screen, no stored password |
| G8 | **No hard-coded URL anywhere.** Switching environment is copying a file and rebuilding, exactly like `afsmobile_flutter` |
| G9 | Run on **one personal Android phone**, sideloaded |

## 0.3 Non-goals

- Play Store, App Store, iOS, desktop, web builds. (`flutter create --platforms android` only.)
- More than one user, or any user other than the Brain member. No sign-up, no password reset, no SSO.
- Offline *writes*. A queue of pending admin actions that replay later is a way to bill a member twice.
  Reads are cached for a fast start; every write needs the network.
- Talking to MySQL, Parse, Stripe, Square or PayPal directly. Only `www`'s HTTP API ([BF2](#04-decisions)).
- **Creating a subscription** for a member, or **adding a card**. Both need the member's own payment
  authentication (3-D Secure / SCA); there is no staff-side way to do that and the plan does not invent one.
- Free-form editing of every allocation counter (the web's JSON/Form editor). The app shows usage and
  manages **overrides**; see [08](08-allocations-and-overrides.md) §8.1 for why.
- DNS/HTTP server management, Apps settings, Storage fleet in the first release ([09](09-other-brain-sections.md)).
- Push notifications. (An open question, not scope.)
- Fixing the problems in `www` that this plan merely *finds* (the unauthenticated `processors/billing/*`
  routes, the dead web tabs, the stale route comments). They are listed in
  [01 §1.9](01-current-state-audit.md) and reported, not repaired here, unless a phase needs them.

## 0.4 Decisions

| # | Decision | Why |
|---|----------|-----|
| **BF1** | The app is **`afsbrain_flutter`**, in `C:\projects\vagrant\afs-eco\`, beside `afsmobile_flutter` and `afsdesktop5`. Android only. Application id **`com.appfactorystudio.afsbrain`**, launcher label **AFS Brain**. | Sibling placement means `use-env`, `check-env` and the lab docs find it the same way they find the other two clients. A different application id from `afsmobile_flutter` (`com.appfactorystudio.afsmobileapp`) lets both be installed on one phone. |
| **BF2** | The app talks to **`www`'s HTTP API only**. It never opens a MySQL connection and never talks to Parse. | Member creation registers a Checkpoint identity and seeds a Parse allocation row; tier changes write `brain_membership_events`; refunds go through the provider; none of that lives in the database alone. A phone with database credentials also holds the keys to every table, on a network where the lab's root user has no password. |
| **BF3** | Identity is a **device token**: a `laravel_user_tokens` row for `laravel_users.id 1` (the user behind `brain_memberships.reference_num = 'appfactorystudio'`), minted by `php artisan brain:device-token` run **by you**, pasted into the app's `.env` as `BRAIN_DEVICE_TOKEN`. | Every Brain admin route already authenticates this way; no auth middleware changes ([02](02-authentication-device-token.md)). The agent never logs in or sets a password (standing rule). |
| **BF4** | Every host, base URL and secret comes from **`.env`**. One `_env/<mode>/.env` per environment, copied over the root `.env` by `use-env`; the modes are the same five as the sibling client apps. The env files for this app are **gitignored** (they hold a token); a committed `.env.example` lists the keys. | The estate rule: nothing environment-dependent is decided in code ([ENVIRONMENTS.md](../../../../w/ENVIRONMENTS.md), opening rule). The token is the one thing that must not reach git. |
| **BF5** | `www` changes are **additive**. Where an existing route already does the right thing (member list, member lookup, status, tier, allocation, overrides, money ledger/refund), the app calls it unchanged. New endpoints exist only for the gaps ([04](04-server-additions.md)). | Keeps the browser SPA working and keeps one implementation of every rule. |
| **BF6** | Subscriptions are controlled through the **Direct Payments** layer: a new **staff** controller that reuses the provider actions, sync service and tier-binding resolver the self-service controller already uses. The app **never** calls the unauthenticated `processors/billing/*` routes. | [BA9](01-current-state-audit.md), [BA10](01-current-state-audit.md). One cancel/plan-change implementation, correct after a processor swap, with the audit and `SubscriptionPlanChanged` side-effects intact. |
| **BF7** | **Remove** = (a) **now**: suspend (`account_status 2`) or mark for deletion (`3` + close date) through the existing, audited status endpoint, clearly labelled as not-a-deletion; (b) **later, capability-gated**: the `account_close` soft/hard delete flow, shown only when `whoami` reports `capabilities.account_close = true`. | There is no deletion endpoint to call ([BA7](01-current-state-audit.md)), and building a second deletion engine in a phone-driven controller would be exactly the mistake `account_close` D2 rules out. |
| **BF8** | **Edit** covers first/last name, address, plan and status. **Email and username are read-only** until OQ2 is decided. | They are the member's Checkpoint identity (`ext_member_users`, the Parse row); changing them on `brain_memberships` alone would leave the member unable to sign in. |
| **BF9** | Every write sends `expected_updated_at` where the server supports it, and surfaces `409 STALE_RECORD` as a "reload and try again" state, never a silent overwrite. Every write the server requires a **reason** for (plan change, override, decrease) requires one in the app before the request is sent. | The server rules already exist ([membership_change/08](../membership_change/08-member-management.md) MM7); the app must not be the weaker client. |
| **BF10** | The app asks the server what it can do — `GET /brain/staff/whoami` returns `capabilities` — and **hides** anything not reported. An unknown capability is treated as `false`. | The server is the authority on what exists today (`account_close` is unbuilt; `apps`/`storage` 404 off the Brain). The app cannot be shipped ahead of the server and break. |
| **BF11** | A coloured **environment badge** is on every screen, and on `production` every destructive action asks for a typed confirmation. | One phone, several environments, one icon. The expensive mistake is editing production believing it is the lab. |
| **BF12** | State management is **`flutter_bloc` (Cubit)** with `http`, `flutter_dotenv`, `equatable` and `intl` — the sibling app's own stack. | No new paradigm to learn or maintain; code can be copied between the two apps. |
| **BF13** | The acting member (`appfactorystudio`) cannot be removed, suspended, re-planned or have its allocation edited **by the app**; the server already refuses the allocation case (`BrainAllocationController::store` self-edit guard). | A phone mistake must not lock the owner out of their own estate. |
| **BF14** | Phasing: **read before write; members before billing; billing before allocations; web-parity extras last** ([12](12-rollout-and-testing.md)). | Each phase is shippable to the phone on its own and is checkable against the lab. |

## 0.5 Glossary

| Term | Meaning |
|------|---------|
| **Brain** | `w/www`, the Laravel app that is both the real website and the control plane for the whole estate. `config('brain.is_brain')` is true there. |
| **Brain member** | The `brain_memberships` row `reference_num = 'appfactorystudio'`, `level = 99`, `membership = 4`, `user_id = 1`. The identity the app acts as. |
| **Level 99** | The platform-superuser sentinel on `brain_memberships.level`, checked **exactly** by `portal.level99`. Read from the database row, never from the request. |
| **Device token** | A long-lived bearer token, a row in `laravel_user_tokens`, registered in `brain_device_tokens` for labelling and revocation. |
| **Payer** | The Direct Payments identity (`payer_reference_num`, `pay_…`) that survives a processor swap. `brain_memberships.payer_reference_num` joins a member to it. |
| **Link row** | `laravel_direct_payment_subscriptions` — ties a provider subscription to a Direct Payment, a tier and a payer. |
| **Dunning / billing state** | `brain_membership_billing_state` — `current` → `past_due` → `downgraded`, written by `billing:enforce-membership`. |
| **Capability** | A boolean the server reports in `whoami` saying a feature exists on this deployment. |
| **Environment** | One of `development`, `production`, `vagrant`, `vagrant-lan`, `lab2` — the `_env/<mode>` folders. |

## 0.6 Open questions

| # | Question | Needed by | Recommendation |
|---|----------|-----------|----------------|
| **OQ1** | Device-token lifetime: never expires, or a fixed span with a renewal command? | P0 | Lab modes: never. `production`: 180 days via `--days=180`, re-minted by you. A lost phone is handled by `--revoke` either way ([02](02-authentication-device-token.md) §2.6). |
| **OQ2** | Should the app ever change a member's **email or username**? | P2 | Not in this plan. It needs a Checkpoint-aware server endpoint (update `ext_member_users` and the Parse identity in one transaction). Separate plan if wanted. |
| **OQ3** | Creating a member needs a **password** to establish the Checkpoint identity. Type one on the phone, generate one server-side and show it once, or create without a password (no sign-in possible until they reset)? | P2 | Generate on the phone, show once with a copy button, and send it to the server over TLS only. Matches the web form's rules (≥ 8 characters, no spaces). |
| **OQ4** | The web create form requires a `processor` reference. Which does the app offer? | P2 | A picker populated from the processors list; default to the processor of the Brain's own membership Direct Payment (`vfPSdk2xj` → `2uDJGCr` in lab2). |
| **OQ5** | Lab builds are **debug** builds (only debug trusts a user-installed CA, [03](03-environments-and-config.md) §3.6). Do you also want a release-grade build against the lab? | P8 | No. Use a debug build for the lab and a release build for production; revisit only if the debug build is too slow on the phone. |
| **OQ6** | App lock (biometric/PIN on open) for the `production` build? | P8 | Yes for production, off for lab, controlled by `APP_LOCK` in `.env`. It is local-only and is not a login. |
| **OQ7** | Push notifications when a member goes `past_due`? | after P8 | Out of scope; the Billing tab ([10](10-ui-and-navigation.md)) is pull-to-refresh. |
