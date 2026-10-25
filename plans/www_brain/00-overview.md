# 00 — Overview

## 1. The problem

The Brain (`www`) already controls members, DNS zones, HTTP servers and allocations. Every other
app is a **black box to the Brain**: it has its own database, its own behaviour and no
configuration surface an operator can reach. Two concrete gaps prompted this plan:

1. **Email.** The `afsemail` builder — the tool that defines a mail handler (a designed message
   plus the event that triggers it) — exists only in the `www` admin. No other app can have an
   email response configured, and none sends email today.
2. **Everything else.** There is no way to change how an app behaves (a limit, a default, a feature
   switch, a maintenance flag) without editing that app's `.env` or code and redeploying.

The requirement is a **Brain-level control plane**: a section of the Brain admin where staff pick
an app and configure it — email first, but designed so a new kind of control is added by the
*app* declaring it, not by rebuilding the Brain.

## 2. Goals

| # | Goal |
|---|------|
| G1 | Staff can configure an **email response** for any registered app from the Brain admin, reusing the existing `afsemail` builder |
| G2 | An app can expose **any typed setting** (flag, number, string, list, JSON object) and staff can change it from the Brain without a deploy |
| G3 | Adding a new setting or mail event for an app requires **no app deploy and no Brain code change** — staff define it in the Brain ([10](10-brain-owned-config-and-push.md)) |
| G4 | An app **keeps working when the Brain is unreachable** (cached values, then built-in defaults) |
| G5 | Every change is **audited** (who, what, when, old → new) and **reversible** |
| G6 | Only staff can change configuration, and only a registered app can read its own |

## 3. Non-goals

- **Server-side mail transport.** Sending is done by `www` through the existing `MailHandlerSender` /
  `BillingMailer`; this plan specifies the *call*, not the transport ([05](05-mail-and-notifications.md)).
- **Replacing allocations.** Limits and tier ceilings belong to alloc_v2 and the
  [`members_brain`](../members_brain/README.md) console. This plan must not create a second place to
  set the same number.
- **A shared database.** Apps keep their databases. The Brain stores *configuration about* apps,
  never their data.
- **Per-member overrides in v1.** Settings are per app (global to that app). Per-member scope is a
  named extension ([04 §4.7](04-settings-and-controls.md)), not built first.
- **Merging the npm packages.** The `afs-client` consolidation discussed separately is *not* a
  prerequisite. The Brain UI runs inside `www`, so it uses `afsemail` directly.

## 4. Glossary

| Term | Meaning |
|------|---------|
| **Brain** | The `www` deployment on `appfactory.studio` and its `brain_*` tables |
| **App** | A registered application identified by a canonical Checkpoint name (`MARKET`, `NOTES_EDITOR`, …) |
| **Manifest** | A versioned JSON document an app registers describing the settings, mail events and variables it exposes |
| **Setting** | One typed key an app exposes; the Brain stores its current value |
| **Mail event** | A named trigger an app can fire (`order_shipped`), with a declared variable list |
| **Mail handler** | The `afsemail` object (design + subject + recipients) bound to `(app, event)` |
| **Effective value** | What the app uses: Brain value if set, else the manifest default |
| **Pull** | The app fetches its configuration from the Brain; the Brain does not have to reach the app |

## 5. The decisions

| # | Decision | Why |
|---|----------|-----|
| **B1** | **The Brain is authoritative for configuration; apps pull.** The Brain never needs to reach an app for configuration to work. | Apps behind firewalls, restarts and Brain outages must not break either side. A pull with a cache is the simplest thing that fails safe |
| **B2** | **An app must run with the Brain down.** Order: fresh cache → last-known-good cache → manifest defaults compiled into the app. | A control plane that can take every app down is worse than none ([08 §8.4](08-security-and-safety.md)) |
| **B3** | **The Brain defines settings and mail events; apps do not have to declare them.** Staff create them in the Apps screens; a manifest, if an app registers one, only upserts entries marked `origin = manifest` ([10](10-brain-owned-config-and-push.md)). | G3. The Brain controls the app, so a new control must not need an app release |
| **B4** | **Apps are identified by the canonical Checkpoint app names** (`CHECKPOINT_APPS`), not a new id scheme. | One vocabulary already exists and is kept in sync across `auth`, the TS client and the C++ client |
| **B5** | **Storage is `brain_app_*`, written only by `www`.** | `AGENTS.md` §5: only the Brain writes `brain_*`. Apps never write these tables even where they could reach them |
| **B6** | **Mail is stored as the `afsemail` handler object and *sent by `www`*.** Apps call `www`; they never render or transport a message. | The builder, MJML transform, SendGrid credentials and `MailHandlerSender` already live in `www`. Sending from eight places would duplicate all of it |
| **B7** | **App→Brain calls use a signed, per-app key, in a new group** — not the unauthenticated `api/v1/brain` group and not a user token. | Two audiences: staff (level 99 user) and services (signed app). Mixing them is exactly what caused the `portal.level99` disablement ([01](01-current-state-audit.md)) |
| **B8** | **Validation is the manifest's schema, enforced by the Brain on write and by the app on read.** | A bad value saved once must not be able to break an app; the app rejects what does not match its own schema |
| **B9** | **Every write is a new revision in an append-only history.** Rollback = write the old revision forward. | G5; matches the audit posture of `brain_membership_events` |
| **B10** | **"Am I the Brain?" is `IS_BRAIN=true` in `.env`, read through `config('brain.is_brain')`, plus — when `APP_ENV=production` — a hard check that `APP_URL`'s host is `www.appfactory.studio`.** Set `true` in the four Brain env sets under `www/_env/`; **omitted** from the tenant default `_env/www/.env`. The new routes and UI answer `404` unless it holds. | Tenants are clones of `www` but run on **separate servers with their own databases and no access to the Brain's**, so this is not a security boundary — it keeps Brain-only surfaces from existing on a tenant at all. A domain test (`BrainGuard`) breaks in every environment whose URL differs (`.test`, `appfactory.test1`, staging); an env flag does not ([08 §8.3](08-security-and-safety.md)) |

## 6. Decisions taken while building

The questions this plan raised, and what was decided:

| # | Question | Decision |
|---|----------|----------|
| **OQ-1** | Which apps are in scope for v1? | All seven registered (`MEMBERS`, `COORDINATOR`, `NOTES_EDITOR`, `PDF_EDITOR`, `FLOW_EDITOR`, `GRAPH_EDITOR`, `MARKET`); **`notes` is the pilot** and the only app with a manifest that declares anything |
| **OQ-2** | Do `members` and `auth` register? | `members` yes (empty manifest). `auth` no |
| **OQ-3** | Push an invalidation? | Built as pull only: a change applies within one cache lifetime (60 s for `hot` settings, 10 min otherwise). **Planned:** a Brain→app push and actions ([10](10-brain-owned-config-and-push.md), B13–B15) |
| **OQ-4** | Sender identity for each app's mail | One platform sender (`BRAIN_MAIL_FROM`, default `no-reply`) used by the built-in fallback message. A per-app override setting is **not built** |
| **OQ-5** | How is the PHP client shared? | A Composer package, `afs/brain-client`, in `packages/brain-client`. The apps use it through a **path repository** for now (§6.2) |
| **OQ-6** | Real controls beyond email for the pilot | `maintenance.enabled` and `maintenance.message` only |
| **OQ-7** | Do browsers read settings directly? | No — each app's server reads and passes down what its browser needs |
| **OQ-8** | Model for the app channel | The shared verifier already built for billing enforcement (`brain.surface`), not `market`'s or afs-connect's; no key is reused |
| **OQ-9** | Handler variable escaping | Every variable value is HTML-escaped when it goes into a message **unless the manifest declares that variable `html: true`** — the app pre-rendered it and vouches for it |
