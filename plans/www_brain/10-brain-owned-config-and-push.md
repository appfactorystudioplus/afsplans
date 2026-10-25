# 10 — Brain-owned configuration, and two-way communication

Status: **Q1 built (Brain-owned settings and mail events); Q2–Q4 planned.** It changes who defines settings and mail events (the Brain, not the app), and adds a
Brain→app channel. Everything in [02](02-app-registry-and-manifest.md)–[09](09-rollout-and-testing.md) that says
"the app declares it" is superseded here for **new** work; what is already built keeps working ([10.9](#109-what-is-kept)).

## 10.1 The model

The apps are parts of the AppFactory estate; the Brain runs it. So:

- **The Brain defines and stores** each app's configuration and its email responses. Staff create them in the Apps screens.
- **The apps read** what the Brain holds for them, and use it.
- **The Brain can also reach the apps**: tell one its configuration changed, ask for its state, or run an action it
  accepts. An app can report events back.
- **The Brain sends every email.** An app never renders or transports mail (B6 stands): it says "send `note_shared` to this
  person with these values" and `www` sends it. No mail code is duplicated in any app.

```
   staff ──▶ Brain (www) ── owns: settings, mail events + handlers, app registry ──┐
                 ▲   │                                                              │
   pull config   │   │  push: "refresh" · run action · read state                   │
   send-mail     │   ▼                                                              │
   report event  app  (notes, market, …)  ◀─────────── signed, both directions ─────┘
```

## 10.2 Decisions

| # | Decision | Why |
|---|----------|-----|
| **B3** (replaced) | **The Brain defines settings and mail events.** Staff add a setting (key, type, value, optional bounds, group, description) or a mail event (key, variables, handler) to an app from the UI. The app does not have to declare it first. A manifest is **optional**: if an app registers one, its entries appear as Brain-defined entries marked `source = manifest` and can be edited like any other | The Brain controls the app, not the reverse. A new control must not need an app deploy |
| **B11** (built) | **The definition travels with the value.** A pull returns each setting as `{key, value, type, schema, revision}`; the app validates against **that** schema (not a compiled-in manifest) and still falls back to the default written at the call site (`Brain::setting('direct_payments_id', null)`) | Keeps B2/B8: a bad value cannot break an app, and the app still runs with the Brain down |
| **B12** | **Mail events are Brain-defined; sending is Brain-only.** A send names an event key. The Brain refuses an event that does not exist or is disabled (`422`); there is no per-app mail transport and no fallback mailer in the app | B6, without the manifest step |
| **B13** | **Communication is two-way, and pull stays the source of truth.** A push is an accelerator and a command channel, never the only path: if it fails the app still converges by pulling | An unreachable app must not stop the Brain, and a missed push must not leave an app stale |
| **B14** | **Each direction has its own verifier and path prefix.** App→Brain stays `/api/internal/brain/…` (`brain.surface`). Brain→app is `/api/internal/brain-app/…` on the app, verified by the same HMAC scheme with the app's key. A signature for one path cannot be replayed on the other (the path is signed) | One scheme to reason about; no confusion between "I am calling the Brain" and "the Brain is calling me" |
| **B15** | **An app decides which actions it accepts.** The Brain can only run an action the app has registered in its own config (allowlist). "Run any command" does not exist | The Brain being compromised must not become remote execution on seven apps |

## 10.3 What staff can do (UI)

Apps → {app}:

- **Settings tab:** **Add setting** — key, label, type (boolean / integer / string / enum / list / JSON), default, value,
  bounds, group, `hot` (short cache), `confirm` (ask before changing). Edit, reset, history and rollback exist already.
  Example: `direct_payments_id` (string).
- **Mail tab:** **New email** — key, name, the variables it provides, then the existing `afsemail` builder; enable/disable;
  send test. The event and its handler are created together.
- **Overview:** the app's base URL and last push result; a **Refresh now** button; the app's reported **state**; a list of
  the **actions** the app accepts, each with a Run button.
- **Events:** events the app has reported (§10.6).

A setting nobody reads is inert: the app must call `Brain::setting('key', <default>)` somewhere. (The pull returns the whole
document, so the Brain cannot tell which individual key an app read; a per-setting `last read` is not built.)

## 10.4 Data model changes

| Table | Change |
|-------|--------|
| `brain_app_setting_defs` (new, **built**) | The Brain-defined setting: `app_name`, `setting_key` (unique together), `label`, `group_name`, `description`, `schema` json, `default` json, `hot`, `confirm`, `updated_by`. `brain_app_settings` (value, revision, history) is unchanged |
| `brain_app_mail_events` (new, **built**) | `app_name`, `event_key` (unique together), `label`, `variables` json (`{name,type,sample,html}`), `fallback` json (`{subject, body_text}`), `essential`, `updated_by`. The handler stays in `brain_app_mail_handlers` |
| `brain_app_registry` | Add `base_url`, `push_enabled`, `last_push_at`, `last_push_status`, `state` json, `state_at` |
| `brain_app_events` (new) | `app_name`, `type`, `payload` json (size-capped), `created_at`; retention 30 days |
| `brain_app_actions` (new) | `app_name`, `action_key`, `label`, `confirm`, `seen_at` — what the app last reported it accepts |

The manifest tables stay. **As built, a manifest is not copied into the definition tables:** `AppDefinitions` merges the current
manifest's entries (`origin = manifest`) with the Brain's own rows (`origin = brain`) whenever it reads, so a manifest stays
immutable and a staff definition is never overwritten by it. One key has one definition: defining a key the manifest already
declares is `409 DECLARED_BY_APP`. Manifest-origin entries are edited by changing the manifest; staff change only their value.
A registration never orphans a Brain-defined setting's stored value.

## 10.5 Brain → app

Each app exposes, through `afs/brain-client`'s service provider (no per-app code):

| Route (on the app) | Purpose |
|--------------------|---------|
| `POST /api/internal/brain-app/refresh` | Drop the cache; the next read pulls. Body may name keys |
| `GET  /api/internal/brain-app/state` | Version, environment, health, and app-supplied facts (`Brain::reportState(fn)`) |
| `GET  /api/internal/brain-app/actions` | The actions this app accepts |
| `POST /api/internal/brain-app/actions/{key}` | Run one; unknown keys `404` |

- **Verifier:** the same HMAC scheme, skew ±300 s, nonce cache, headers `X-Brain-Timestamp/Nonce/Signature`, key =
  the app's existing `WWW_BRAIN_SURFACE_KEY`; the app checks the signed path is under `brain-app/`.
- **Built-in actions:** `cache.clear`, `maintenance.on/off`. An app adds its own in `config/brain-client.php`
  (`actions => ['reindex' => [Handler::class, 'confirm' => true]]`); an unlisted action is refused.
- **When it fires:** on saving a setting the Brain queues one job per app: `POST refresh`, 3 s timeout, one retry, result
  written to `last_push_status`. Failure never fails the save; the app catches up by polling.
- **`hot` settings** use the push, so a save reaches the app in about a second instead of one cache lifetime.

## 10.6 App → Brain (additions)

| Call | Purpose |
|------|---------|
| `Brain::mail($event, $to, $vars)` | As built; the Brain sends. An event key the Brain does not hold is `422` |
| `Brain::setting()` / `settings()` | As built; the pull now carries the definition and updates `last_read_at` |
| `Brain::report($type, $payload)` | Tell the Brain something happened (error, milestone). Stored in `brain_app_events`, size-capped, rate-limited; staff see it under Events. The Brain does not act on it in v1 |
| `Brain::registerActions()` | Sent with the heartbeat so the Brain knows what the app accepts |

## 10.7 Security

- **`base_url` is staff-supplied and the Brain will call it** — an SSRF risk. It must be `https`, must resolve to a public
  address (the lab and local environments allow a configured suffix such as `.appfactory.test1`), no redirects followed, and
  only level 99 may set it.
- **The Brain is the only caller an app trusts** on `brain-app/`: HMAC with the app's own key; a wrong or missing signature
  is a `401` with no detail. A tenant clone of `www` holds no app keys ([08 §8.3](08-security-and-safety.md)).
- **Actions** are an allowlist in each app's config (B15); a destructive one sets `confirm`, and the UI asks first.
- **Audit:** every push, action run and setting change records who and when.
- **Payloads are data.** Reported events and state are shown, escaped, never executed or interpolated into a message.

## 10.8 Phases

| Phase | Deliverable | Done when |
|-------|-------------|-----------|
| **Q1** (built) | Brain-owned settings and mail events: the two definition tables, `AppDefinitions` (merges manifest and Brain definitions), `SettingsService` and `AppMailService` reading it, the pulled document carrying each setting's `schema` and `hot`, the client validating against it and reading every key the Brain sends, staff routes `PUT/DELETE …/definitions/settings/{key}` and `…/definitions/mail/{event}`, **Add setting / New email** (with edit and delete) in the Apps screens, `brain:send-test` for events the manifest does not declare | Staff add `direct_payments.id` and a mail event to `notes` with no manifest and no app deploy; the app read the value and fired the event (lab, §10.11) |
| **Q2** | Push refresh: registry `base_url`, the client's `brain-app/` routes and verifier, the queued push job, `last_push_status` in the UI | A saved `hot` setting reaches `notes` in seconds; the Brain down or the app down does not break the save |
| **Q3** | State and actions: `state`, `actions`, the allowlist, the Overview panel | The Brain shows `notes`' state and can run `cache.clear`; an unlisted action is `404` |
| **Q4** | Inbound events: `Brain::report`, the table, the Events tab | An app error appears in the Brain |

Q1 is the one that answers "I cannot create anything from the Brain"; Q2 is the one that makes it two-way.

## 10.9 What is kept

The registry, signed pull with ETag and last-known-good, per-app keys, settings history/rollback/compare-and-set, the mail
log, rate and recipient limits, `BillingMailerTransport`, the `notes` maintenance pilot, the Apps screens and the
`afs/brain-client` package are kept. What changes is who defines settings and events (Q1) and the added Brain→app direction
(Q2–Q4).

## 10.10 Acceptance

- From the Apps screen, with no app deploy, staff can add a setting and a mail event to an app and the app uses both.
- An app never sends mail itself; the only code path that sends is `www`'s.
- A `hot` setting change is visible in the app within a few seconds; with the app down, the save still succeeds and the app
  converges when it returns.
- The Brain can read an app's state and run only actions that app listed; every other action is `404`.
- A request to `brain-app/` with a wrong key, an expired timestamp or a replayed nonce is `401`.
- `base_url` values that are `http`, private, or redirecting are refused outside the lab.

## 10.11 As built — Q1

**Checked.** `www` Brain suites: 140 tests (13 new in `BrainOwnedDefinitionsTest`); `brain-client`: 26 tests; `tsc --noEmit`: 0 errors.
On the vagrant lab, with a throwaway level-99 token (deleted afterwards): the definition tables migrated; a setting and a mail
event were defined on `NOTES_EDITOR` with no manifest entry; `Brain::setting('direct_payments.id')` in `notes` returned the value
set in the Brain and `Brain::settings()` listed the new key beside the manifest's; `brain:send-test` of the new event reached the
Brain's fallback path (`via=fallback`, `provider_failed` — the lab still cannot deliver mail); an event the Brain does not hold
was refused `UNKNOWN_EVENT`. The definitions and value were removed afterwards (history rows remain — it is append-only).

**Found and fixed on the way.** Laravel's `ConvertEmptyStringsToNull` turned `''` into `null` in `$request->all()`, so a setting
value or default of an empty string, or a mail variable of `''`, was rejected or stored as `null`. The staff setting/definition
routes and the app mail route now read the raw JSON body.

**Not verified.** The **Add setting / New email** screens are type-checked but have not been opened in a browser, and the served
bundle (`public/build`) has not been rebuilt with them. The other six apps still hold the previous `afs/brain-client` copy
(`composer update afs/brain-client` in each) — only `notes` on the lab was given the new client for the check above.
