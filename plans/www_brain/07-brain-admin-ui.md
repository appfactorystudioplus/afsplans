# 07 — Brain admin UI
The **Apps** section of the Brain admin. It lives in `www` (`resources/js/portal/admin`), so it uses
the `afsemail` builder in place — no packaging (see [00 non-goals](00-overview.md)).

## 7.1 Where it goes

The Brain nav item is shown only to `level == 99` ([01 W7](01-current-state-audit.md)) — a sidebar
hide, not authorization. Add **Apps** beside Members / DNS / HTTP under it:

```
Brain
 ├─ Members        (existing — brain_membership.ts)
 ├─ DNS / HTTP     (existing)
 └─ Apps           ← new
     ├─ list of registered apps
     └─ {app}
         ├─ Overview
         ├─ Settings
         ├─ Mail
         └─ History
```

New code goes in **new files** under `controllers/brain/apps/` (not into the 4,357-line
`brain_membership.ts`): `apps_list.ts`, `app_overview.ts`, `app_settings.ts`, `app_mail.ts`,
`app_history.ts`, plus a small `BrainAppsApi.ts`. Every edit to an existing file carries
`AFS_WWW_BRAIN_MODIFIER_ADDITION`.

## 7.2 Apps list

| Column | Source |
|--------|--------|
| App | `label` / `app_name` |
| Status | `active` / `disabled`; toggle (level 99) |
| Manifest | `current_manifest_version` |
| Last seen | `last_seen_at`, red if older than a threshold |
| Settings | count set / count declared |
| Mail | handlers configured / events declared |

"Add app" creates a registry row (staff only); it does **not** create a key — keys are provisioned
in `.env` on both sides ([08 §8.2](08-security-and-safety.md)).

## 7.3 Overview

Manifest version and registered-at, last heartbeat, the app's declared settings and events, and any
**problems**: an invalid stored value, an orphaned key, a manifest that has not been re-registered
since the app's last deploy.

## 7.4 Settings tab — generated from the schema

The form is **generated from each setting's JSON Schema** (boolean → switch, enum → select,
integer with bounds → number input with min/max, list → repeatable rows, object → nested group),
grouped by the manifest's `group`. This is the same idea as the generated form in
[`members_brain/04-form-generation.md`](../members_brain/04-form-generation.md); reuse its approach
rather than inventing another.

Per row: label, control, the **default** (greyed), the **source** (`brain` / `default` /
`default_invalid`), **Reset to default**, **History**. Saving sends the row's `revision`
([04 §4.6](04-settings-and-controls.md)); a `409` shows "changed by X since you opened this" with
the current value, not a silent overwrite.

Keys flagged **invalid** or **orphaned** are shown at the top with the reason and a one-click
"reset to default".

## 7.5 Mail tab — the existing builder, scoped

- Lists the app's **declared events** (label, key, variable list, handler status).
- Selecting an event opens the **`afsemail` builder** with:
  - the variables dialog populated from **that event's** variables (not the whole system list),
  - the handler loaded via `normalizeHandler()` and saved back to `brain_app_mail_handlers`.
- **Enable/disable** toggle per handler; disabled shows "the built-in fallback will be sent".
- **Send test** to a staff-entered address using the event's `sample` values (rate limited;
  logged with `source = test`).
- Recent sends per event from `brain_app_mail_log` (status counts, no addresses).

Reuse notes: the builder currently imports `www` admin helpers and reads
`app/Mail/variables.json`; both are fine here because the UI *is* in `www`. If the builder is ever
moved to a shared package, the coupling inventory in the separate `afs-client` discussion applies —
it is **not** part of this plan.

## 7.6 History tab

Reverse-chronological merge of setting and handler revisions: who, what, old → new, reason.
**Roll back** on any row writes the old value forward as a new revision (B9), after the same
validation as any write.

## 7.7 Server routes (staff group)

New group, separate from the app→Brain group and from every existing one:

```
Route::prefix('api/v1/brain/apps')->middleware(['portal.admin.auth','portal.level99','brain.only'])
```

| Route | Purpose |
|-------|---------|
| `GET /` , `POST /` , `PATCH /{app}` | list, add, enable/disable |
| `GET /{app}` | overview incl. manifest, problems |
| `GET /{app}/settings` , `PUT /{app}/settings/{key}` , `DELETE …/{key}` | read (with revisions), write (CAS), reset |
| `GET /{app}/mail` , `PUT /{app}/mail/{event}` , `POST …/{event}/test` | list, save handler, test send |
| `GET /{app}/history` , `POST /{app}/history/{id}/rollback` | history and rollback |

`portal.level99` is applied here on purpose: it was disabled on the first group because *service*
callers had no user row ([01 W1](01-current-state-audit.md)); this group is **staff-only** and
services use the separate signed group.

## 7.8 Acceptance

- A level-99 staff user can list apps, edit a setting and a mail handler; a non-level-99 user gets
  `403` from the API even if they craft the URL.
- The Mail tab's variables dialog for an event shows only that event's declared variables.
- Editing a setting stale (another staff saved first) shows the conflict and does not overwrite.
- Rollback from History restores the value and appears as a new history row.
- Disabling an app makes its app→Brain calls fail with a clear status ([08](08-security-and-safety.md)).

## 7.9 As built

Built 2026-09-26 and type-checked (`tsc --noEmit`); **not exercised in a browser** — the API behind it is covered by tests and
was driven end to end in the lab with `curl`, the screens have not been clicked through.

- **Where:** `www/resources/js/portal/admin/controllers/brain/apps/brain_apps.ts` (one module); views `brain:apps` and
  `brain:apps:detail` in `views/brainView.ts`; an **Apps** button on the Brain landing page (`brain_content.html`,
  `imp_brain.ts`).
- **List:** apps, status, manifest version, last seen (flagged stale after six hours), settings set/declared, mail
  configured/declared; add an app; enable/disable.
- **Detail tabs:** Overview (problems), Settings (generated from each schema: switch, select, number with min/max, text,
  JSON for lists and objects; source badge; save with revision; reset; `confirm: true` asks first), Mail, History (with
  roll back).
- **Mail tab:** lists events with variables, handler status and 7-day counts; **Edit handler** opens the existing afsemail
  builder with the event's own variables as the handler's client variables (so the variables dialog shows exactly what the
  event provides); **Send test** sends the event with its sample values to an address you type.
- **Not built:** typed confirmation, a manifest viewer, editing handlers for more than one variant per event.
