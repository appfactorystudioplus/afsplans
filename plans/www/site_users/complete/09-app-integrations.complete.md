# 09 — App integrations (complete)

**Plan:** `plans/www/site_users/09-app-integrations.md`
**Rollout phase:** P9 — app adoption
**Repo:** `www`
**Date:** 2026-08-31, completed 2026-09-05

> ### The registry is now load-bearing
>
> The earlier state — `config/site_users_consumers.php` present, one entry, **no reader**,
> events and appointments each reading their own `site_users_mode` column — is resolved.
> `App\SiteUsers\SiteUsersConsumerRegistry` is the single resolver; all five modules are
> registered; ecommerce, events and appointments call the registry, not their own column.

## §9.2 — one contract (N9)

`config/site_users_consumers.php` — one array, five entries, each `{label, user_column,
supports, mode_source}`. `mode_source` names the `[model, attribute]` the per-instance mode
lives on (`null` for forms — guest-only, and for discussions — always required).

| Module | `supports` | Enforced by |
|---|---|---|
| ecommerce | guest, required | `EcommerceShopperAuth` → registry (`assertCheckoutAllowed`) |
| events | guest, required | `EventRegistrationService::register()` → `registry->assertAuthenticated('events', $event, …)` |
| appointments | guest, required | `AppointmentBookingService::book()` → `registry->assertAuthenticated('appointments', $appointment, …)` |
| forms | guest only | attribution via the API-guard `site_user` attribute; no gate (a public form that demands an account is not a contact form — §9.4) |
| discussions | required always | registry returns `'required'` unconditionally; no per-instance toggle |

`SiteUsersConsumerRegistry`:

- `modeFor($appKey, $instance)` — reads the model attribute, **coerces an unknown value to
  `off`** rather than trusting it, returns `required` unconditionally for a required-only
  module.
- `assertAuthenticated($appKey, $instance, $request)` — the gate: error payload when the
  instance is `required` and nobody is signed in, else null.
- `supportedModes($appKey)` — what the settings UI may offer (`['off', …supports]`).

`EcommerceShopperAuth` no longer reads `store.site_users_mode` — it delegates to the
registry. Its unit test was updated to construct it with the registry.

## §9.3 — what a consuming module does

`app(SiteUsersGateway::class)->currentUser($request)` → `?SiteUser`, `null` = guest, never
throws. `site_user_id` nullable columns exist on ecommerce, events, appointments and forms
tables.

## Acceptance — §9.7

| Criterion | State |
|---|---|
| Adding a consumer is one registry entry plus one nullable column | ✅ |
| A module declares support **once, in the registry** | ✅ all 5 registered; the registry is read by `SiteUsersConsumerRegistry` and the three enforcing modules |
| `off` changes nothing for an existing tenant | ✅ default `off`; unknown values coerce to `off` |
| `guest` attributes to a signed-in user; anonymous still completes | ✅ ecommerce; events/appointments attribute via `site_user_id` and never gate in guest mode |
| `required` blocks the action without an account | ✅ ecommerce, events, appointments — `SiteUsersConsumerRegistryTest` + the service gates |
| Forms offers `guest` only; discussions is always `required` | ✅ `supportedModes()` |
| `currentUser()` with no token → `null`, no exception | ✅ |
| The settings UI offers only declared modes | ⚠️ `supportedModes()` provides the data; the per-module settings **panel** (§9.2 "in the module's own admin section") is each module's own UI work |

## Deferred

| # | Work |
|---|---|
| 1 | Per-module settings **panels** (the three-radio-button UI, §9.2) in each module's admin section. The registry gives them `supportedModes()`; the widgets are per-module. Ecommerce's is the worked example (`ecommerce/01-site-users-guard.md` §1.5.3). |
| 2 | **Appointments' two identities** (§9.4) — an emailed booking link must keep working once accounts exist. Has a real deadline (every confirmation already sent). Belongs to `plans/www/appointments`. |
| 3 | **Discussions** — no plan folder exists. Registered as `required`-always; the module itself is unbuilt. |
| 4 | §9.5 email backfill of old rows — deferred to `08 §8.5`'s reconciler pattern, one solution for both. |

## Verification

```bash
cd www
php artisan test --filter="SiteUsersConsumerRegistry|EcommerceShopperAuth"
cat config/site_users_consumers.php
grep -rn "SiteUsersConsumerRegistry" app/    # ecommerce, events, appointments
```
