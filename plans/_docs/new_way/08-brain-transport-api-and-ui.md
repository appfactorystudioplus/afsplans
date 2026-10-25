# 08 — Members to Brain: transport, API contracts, and the screens (website deployment)

> **Split 2026-10-23.** This step was written as one plan for domain names *and* website deployment. It is now two plans: this file is the **website deployment** half; the **domain name management** half is [../domain_name_management/08-brain-transport-api-and-ui.md](../domain_name_management/08-brain-transport-api-and-ui.md). Step, section (§), finding (F), decision (D) and report (E) numbers are unchanged, so a reference such as "04 §4.5" or "D19" means the same thing in both folders. Where a section moved to the other half, a pointer stands in its place. The website routes and the website screen are here; the channel itself (§8.1, §8.2: who does what, the signed surface) is shared and is defined in the domain-name half.

This step settles how the two apps talk (the open blocker D5 of feature 1), what each call means, and what changes in
the domains screens.

> **§8.1 (who does what) and §8.2 (the signed channel)** are shared by both features and are in the domain-name plan: [../domain_name_management/08-brain-transport-api-and-ui.md](../domain_name_management/08-brain-transport-api-and-ui.md).

## 8.3 Brain routes (all `POST`, under `/api/internal/brain/domains`)

Every request carries `member_reference_num` (from members' verified token) and an `X-Request-Id`; every mutation carries
an `idempotency_key`. Responses are `{success, data}` or `{success:false, code, message, details}`.

| Route | Purpose | Notes |
|---|---|---|
| `/{claim}/websites` | Start a deployment | 07 §7.1; 202 |
| `/{claim}/websites/status` | Deployment state, steps, percent, tenant summary | What the progress widget reads |
| `/{claim}/websites/retry`, `/cancel` | Resume from the failed step / compensate | |
| `/{claim}/websites/enable`, `/disable` | Site online/offline | 07 §7.9 |
| `/{claim}/websites/delete` | Real delete | 07 §7.10 |

(Ownership is enforced as in the domain-name plan's §8.3: a claim is looked up by `reference_num` **and** `member_reference_num`, a 404 otherwise.)

## 8.4 Members routes (browser-facing, `/api/v1/domains`, `checkpoint.verify`)

| Route | Maps to | Replaces |
|---|---|---|
| `POST /{ref}/website`, `GET /{ref}/website`, `POST /{ref}/website/{retry,cancel,enable,disable}`, `DELETE /{ref}/website` | `/websites…` | `createWebsite`, `listWebsites` |

The members controller does three things beyond forwarding: derive `member_reference_num` from the token; on a successful
deploy acceptance, upsert the `members_websites` mirror row by `reference_num` (idempotent, so a crash between the Brain's
202 and the mirror write heals on the next list); and report quota `consume`/`release` to the allocation client per feature
1.

**Progress.** The widget (`renderProgressWidget`) polls with a `tracker_key`. In the new flow that key is
`brain_website_deployments.tracker_key` and the members endpoint it polls answers by proxying `/websites/status`; no
website progress is stored in members and the `laravel_website_deployment_progress` table / ungated
`/api/v1/progress/record` route are retired at cut-over (07 §7.7). *To confirm in step 10 P0: the exact URL the shared
`afslibs/services/progress` client calls, so the proxy answers on it.*

## 8.5 Error codes

The website routes use the codes `DOM_E_QUOTA`, `DOM_E_NO_CAPACITY`, `DOM_E_WEBSITE_EXISTS` and `DOM_E_DISABLED` (the deploys kill switch), plus the shared `DOM_E_NOT_FOUND` and `DOM_E_STATE`. The whole list, and the rules for their messages and `details`, are in the domain-name plan: [../domain_name_management/08-brain-transport-api-and-ui.md](../domain_name_management/08-brain-transport-api-and-ui.md).

## 8.6 The screens (all under `resources/js/controllers/domains/`)


| File | Change |
|---|---|
| `website.ts` | *Create* calls the deploy endpoint and mounts the progress widget on the returned tracker; **`onComplete` reloads the claim from the server** (fixing the commented-out handler, F30). The "alternate domain" block shows the real sites name (not the `xxx.site…` placeholder, F15) with working **Open** buttons (the `window.open` lines are re-enabled). Enable/Disable and Delete call the real endpoints; Delete is a real delete with a clear warning; a *Retry* / *Cancel* pair appears on a failed deployment. A "certificate pending: waiting for DNS" note appears on the custom name when step 14 is deferred |
| `api.ts` | New methods for the routes above; `updateDomainJson` and `saveDomainZone` are removed |
| `resources/js/i18n/en.json` | New keys for states, reasons, steps and errors (the `Localizer` pattern in the README) |

The website view template gains the retry/cancel controls (the other template changes — the verification view, the state chip and reason line, the locked-record styling, the purchase-state strip — are the domain-name plan's).

## 8.7 Staff surface (Brain)

Level-99 endpoints in the existing `Staff*` family: deployment detail with the selection explanation (06 §6.5), retry/cancel; the kill switch `domains.deploys_enabled`. (The contested list, claim detail, force-verify, transfer, force-lapse and publish-job endpoints are the domain-name plan's.)

## 8.8 Phases

| Phase | Work |
|---|---|
| **P0** | Signed routes for websites/status; members pass-through for the same; website screen changes; progress proxy; deletion of the members provisioning half behind a feature flag (old path stays until cut-over) |
| **P1** | Delete; retry/cancel; staff endpoints (deployments); `members_websites` mirror reconcile on list |
| **P2** | Flutter Brain screens for the staff surface; Brain→members push events replace the polling in the list view |
