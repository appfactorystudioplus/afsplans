# 09 — The rest of the Brain admin: what the app takes, and what it leaves

## Completion document

Built 2026-10-05 (P7; P9 deliberately not built; device and server verification pending): [complete/09-other-brain-sections.complete.md](complete/09-other-brain-sections.complete.md).

> **Superseded in part, 2026-10-05.** §9.3 to §9.6 (DNS, the HTTP servers and tenants, the Apps control plane, the storage fleet) were left to the browser. The request is now that the
> app manages **all** of the Brain's resources, so those four are taken up in [13](13-brain-infrastructure.md), with the server work that makes them safe from a phone. §9.2 (P7) is unchanged and built.

The request is to "mimic the brain implementation functionality" with **members** as the centre (add, remove, edit, see all) plus
subscription control. The Brain admin does more than that. This file decides, section by section, what the app takes now
(P7), what it keeps as a documented later option, and what it leaves to the browser — with the reason, so none of it is a silent omission.

Rule applied to all of it: **a section is in the app only if it is useful from a phone, its server routes are authenticated, and a
mis-tap cannot take the estate offline.**

## 9.1 Decision table

| Brain section | Web entry | Server gate | In the app? | Phase | Reason |
|---------------|-----------|-------------|:-----------:|:-----:|--------|
| Members list / detail / create / edit | `brain:memberships:*` | `ApiAuth` | ✅ | P1–P3 | The core ([06](06-members-feature.md)) |
| Subscription, Payments | `:subscription`, `:payments` | — (dead in the web, [BA8](01-current-state-audit.md)) | ✅ rebuilt on Direct Payments | P4–P5 | [07](07-subscriptions-direct-payments.md) |
| Allocations, overrides | `:allocations` | `level99` | ✅ usage + overrides; counter-adjust later | P6 / P6b | [08](08-allocations-and-overrides.md) |
| **Checkpoint errors** | `:checkpoint` | `ApiAuth` | ✅ | P7 | The create flow's `502` points here; it is a short list with a "resolve" action |
| **afs-connect** (per member + global flag) | `:afs` | `ApiAuth` | ✅ | P7 | Small, well-defined toggles; see §9.2 |
| Member domains + DNS zone records | `:domains` | `portal.admin.auth` (no level 99) | ❌ for now | — | §9.3 |
| DNS servers, HTTP servers, tenants, clusters, regions | (Brain infrastructure screens) | `portal.admin.auth` | ❌ | — | §9.4 |
| **Apps** control plane (settings, mail events, history, rollback) | `brain/apps` | `brain.only` + `level99` | ◻ optional | P9 | §9.5 |
| **Storage** fleet (servers, devices, token keys, members key) | `brain/storage` | `brain.only` + `level99` | ❌ | — | §9.6 |

## 9.2 P7 — Checkpoint errors and afs-connect

### Checkpoint errors

`GET /portal/api/v1/memberships/checkpoint-errors` → unresolved failures from `brain_membership_checkpoint_errors` (email,
username, membership tier attempted, error message, context with `code`, created); `PATCH …/checkpoint-errors/{id}/resolve` marks one
resolved. App: a list under **More → Checkpoint errors** with a count badge on the More tab; each row shows the email/username/time/code;
**Resolve** asks for confirmation (it only marks the row — it does not create the member or fix Checkpoint). Cross-linked from the Add Member
`502` result ([06](06-members-feature.md) §6.4).

### afs-connect

Two things, both `ApiAuth`:

- **Global flag** — `GET/PUT /memberships/afs-connect/global-flag` (`enabled`). A switch with a confirmation: it is estate-wide.
  Shown only on a **More → afs-connect** screen, not on a member.
- **Per member** — `GET/PUT /memberships/{ref}/afs-connect`: `enabled` plus `sources` (twelve booleans: `notes`, `dream_graph`, `flow`,
  `pdf_editor`, `www_forms`, `www_direct_payments`, `www_sponsorships`, `www_appointments`, `www_subscribers`, `www_analytics`,
  `www_ecommerce`, `www_events`), `disabled_website_domains[]`, and a short `reason` (≤ 32). Shown as a card on the member's Details tab
  below the plan, with the response's `membership_tier`.

Failure modes the screen must show honestly (they are the server's own messages): the coordinator is unreachable (502, with a TLS
hint when it is a certificate mismatch), and `upgrade_required` on save (the member's plan does not include the feature). These routes
proxy to `coordinator`, so on a lab where it is down the screen is "afs-connect unavailable", not an error dialog loop.

## 9.3 Member domains and DNS — not now

The web tab lists a member's domains and edits DNS zone records through `GET/POST /portal/api/v1/brain/dns/zones`. Those routes sit in the
group where `portal.level99` is **deliberately disabled** (`routes/portal.php`, `plans/TODOS.md`) and the zone editor writes live
DNS records, and the same route group exposes `named` reload operations. Editing zone text on a phone has an unusual blast radius for a small screen. A **read-only**
domains list could be added later with no server change; it is not in this plan's phases.

## 9.4 DNS/HTTP servers, tenants, clusters — no

`api/v1/brain/dns/*` and `api/v1/brain/http/*` create and delete servers, move tenants between hosts and rebuild BIND configuration.
They are infrastructure operations behind the weakest gate on the Brain (any valid token). They are not member management, they are
not what the request asks for, and a mis-tap here takes websites offline. Left to the browser.

## 9.5 Apps control plane — optional (P9)

`www_brain`'s Apps screens (`brain/apps`): registry of the estate's apps, typed settings with history and **rollback**, per-app mail
events and a test-send. They are `brain.only` + `level99`, fully authenticated, and genuinely useful to adjust from a phone (flip a
setting, roll one back). They are also a *different product* from "manage members", with their own definitions-vs-values model
(`defineSetting`, `defineMailEvent`) and a larger UI. The plan reserves **P9** and the capability `apps` for it; the build is a
straight client of `GET /brain/apps`, `GET /brain/apps/{app}/settings`, `PUT …/settings/{key}`, `GET …/history`, `POST …/history/{id}/rollback`.
Do it only if wanted after P7.

## 9.6 Storage fleet — no

`brain/storage` manages storage servers, devices and the **members↔storage master key and token keys** (`rotate-credential`,
`promote`). Key rotation from a phone is exactly the operation that should be deliberate, at a desk, with the runbook open
(`plans/storage-sharding/08`). Not in scope.

## 9.7 Acceptance

- The More tab lists exactly: Checkpoint errors, afs-connect, Settings, About (and Apps if P9 is built and `capabilities.apps` is true).
- A section whose capability is false or whose route 404s is **absent**, not disabled-and-broken (BF10).
- None of the sections in §9.3–§9.6 is reachable from the app.
