# www_brain — Planning Index

The Brain (`www`) as the **control plane for every app in the estate**: one place where staff
configure how each other app behaves — starting with its email responses, then any setting an app
chooses to expose — without each app growing its own admin interface.

> **Status (2026-09-26): P0–P4 built and checked by tests and in the vagrant lab; P5 (more controls) is deliberately empty.**
> The app registry, manifests, typed settings with history/rollback, per-app mail, the staff Apps screens and
> the `afs/brain-client` package exist; `notes` is the pilot and the other six apps are registered with an
> empty manifest. Not enabled in production (`dev`/`prod` keys are unset). What was built, how it was checked and
> what is not done: [`complete/09-rollout-and-testing.complete.md`](complete/09-rollout-and-testing.complete.md).
>
> **Phase Q1 built ([10](10-brain-owned-config-and-push.md) §10.11):** settings and mail events are defined in the Brain (Add setting / New email), not declared by the apps; mail is still sent only by `www`. **Q2–Q4 planned:** the Brain reaching the apps (push refresh, state, actions, reported events).

## Why this folder exists

Today the Brain admin ([`www/resources/js/portal/admin/controllers/brain`](../../../../w/www/resources/js/portal/admin/controllers/brain))
manages *members*, DNS and HTTP servers, and allocations. The other apps — `market`, `coordinator`,
`notes`, `flow`, `dream_graph`, `pdf_editor` — have **no Brain-style control surface at all**, and
each keeps its own database (five of them share `afs_apps_db`). None of them sends email today, so
there is nothing to migrate, but there is also nowhere to configure an email response, or any
other behaviour, for one of them.

Related but distinct plans:

| Plan | Relationship |
|------|--------------|
| [`members_brain`](../members_brain/README.md) | One screen — member *allocations*. This plan is app-level, not member-level; it must not duplicate allocation control |
| [`billing`](../billing/README.md) / [`account_close`](../account_close/README.md) | Both need a `members`→`www` signed channel. This plan needs `app`→`www`. **Design the channels together** ([02](02-app-registry-and-manifest.md) §2.5, [08](08-security-and-safety.md)) |
| [`www/direct_payments`](../www/direct_payments/README.md) | Owns `MailHandlerSender`/`BillingMailer`, which the send path here reuses |

## Documents

| # | Document | What it decides |
|---|----------|-----------------|
| 00 | [00-overview.md](00-overview.md) | Problem, goals, non-goals, glossary, decisions **B1–B10**, open questions |
| 01 | [01-current-state-audit.md](01-current-state-audit.md) | What exists today (verified) and what does not |
| 02 | [02-app-registry-and-manifest.md](02-app-registry-and-manifest.md) | How an app becomes known to the Brain: registry, manifest, versioning, the signed channel |
| 03 | [03-data-model.md](03-data-model.md) | The `brain_app_*` tables and who may write them |
| 04 | [04-settings-and-controls.md](04-settings-and-controls.md) | The general mechanism: typed settings an app exposes and the Brain edits |
| 05 | [05-mail-and-notifications.md](05-mail-and-notifications.md) | Per-app mail handlers, event keys, variables, the send call, the fallback |
| 06 | [06-app-integration.md](06-app-integration.md) | What each app must add: client, cache, defaults, manifest command |
| 07 | [07-brain-admin-ui.md](07-brain-admin-ui.md) | The Apps section of the Brain admin, reuse of `afsemail` |
| 08 | [08-security-and-safety.md](08-security-and-safety.md) | Trust, keys, tenant-clone risk, failure modes |
| 09 | [09-rollout-and-testing.md](09-rollout-and-testing.md) | Phases, tests, acceptance |
| 10 | [10-brain-owned-config-and-push.md](10-brain-owned-config-and-push.md) | **Planned:** the Brain defines settings and mail events; two-way communication (push, state, actions, events); decisions B3, B11–B15 |

## Conventions

- Marker for edits in existing files: `AFS_WWW_BRAIN_MODIFIER_ADDITION`.
- Completion docs go in `complete/` when a phase is carried out (see `w/AGENTS.md` §4).
- Decisions are **B1–B15** here so they cannot be confused with `billing`'s D-numbers or
  `account_close`'s.
