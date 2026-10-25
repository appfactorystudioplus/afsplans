# 13 — Turning it on: claims as the only way, no domain settings

> **STATUS 2026-10-24: BUILT (P0, P1, P2).** What exists and what does not: [complete/13-turning-it-on.complete.md](complete/13-turning-it-on.complete.md). Written after an audit of what steps 02–05 and 08 left unconnected, and then cut down by the owner: the application has not been published, there are no domains and no members on an old flow, so there is **one** flow and **no domain settings**. Number 12 is taken by the tenant move in [../new_way/12-tenant-move.md](../new_way/12-tenant-move.md), so this step is 13.

## 13.1 The problem this step solves

Steps 02–05 and 08 built the answer to "who does a domain name belong to": a claim is inert until it proves control, and the database allows one active claim per name ([02 §2.1](02-domain-identity-and-collisions.md)). It was not the path members used: the members screens called a second, older code path (`DomainController` `store`, `purchase`, `update`, `search`, `destroy`) that checked only the caller's own zones and wrote zones without a claim, so two members could both add `example.com`.

## 13.2 What is built (P0)

* **One flow.** Domain names are claims on the Brain and nothing else. The members app routes list, search, release, add (`/claims`), buy (`/purchases`), verify, nameservers, records and the website routes to `DomainSurfaceController`, the signed pass-through. The old `DomainController` methods (`index`, `store`, `destroy`, `update`, `search`, `purchase` and their helpers), `DomainFlow`, `DomainFlowController`, the two flow middlewares and the Brain's `flow` endpoint are removed. What stays on the old controller is **building a tenant website** (the old shell pipeline, `createWebsite`) and the progress it reports.
* **No domain settings.** There is no `controller.domains` block in the Brain settings, no `domains:settings` command and no editor schema for it. Every value is in `DomainDefaults` and is read with `DomainSettings::get()`: the zone `sites.appfactory.studio`, its parent `appfactory.studio`, the host master, the name servers, the reserved names, the commands, the paths, the SOA timers, the limits and the tuning. A test overrides any of them with `config(['domains.<key>' => ...])`.
* **Development or production** is only `VITE_SUBDOMAIN` (`www15` development, `www` or missing production), through `AppEnvironment`, in both apps. The registrar is `controller.domain_registrar`; its credentials are the `controller.keys` entry with that name, in its `development` or `production` slot (E14).
* **The wildcard certificate** keeps only the pair that already was a setting, `controller.letsencrypt_cert_management.tenant_cert` and `tenant_cert_key`. The zone, the account e-mail and the commands are built in. There is no staging and no development or production certificate (E20).
* **No switches.** Adding domains, buying, publishing DNS and website building have no on/off setting; the allowed-server-networks guard and the lab seeder are gone (E13 to E19).
* **Staff path.** `BrainDomainStaffController` (`GET domains-staff/contested`, `POST domains-staff/claims/{claim}/suspend` and `/unsuspend`, `POST domains-staff/transfer`; level 99, a reason is required, every action is audited) and a **Domains** page in the Brain admin.

## 13.3 P1 — the old way closed

Done by removal (§13.2): nothing is left to switch over and nothing to close later. The database holds no domains (owner, 2026-10-23), so there was nothing to adopt either ([10 §10.4](10-rollout-and-testing.md), `domains:backfill-claims`, is not needed).

## 13.4 P2

* `domains:health` (already scheduled) raises `invariant_zone_without_claim` for any live `brain_dns_zones` row with a null `claim_status` (a zone written by something other than the claim service).
* A claim's data carries `last_check` (`reason`, `passed`, `checked_at`: `token_missing`, `token_mismatch`, ...) and the verify screen shows it when it opens on a claim that was already checked. It never says anything about another member.

## 13.5 Decisions

| # | Question | Answer |
|---|---|---|
| D30 | Is buying domains switchable? | **No.** Always on (owner, 2026-10-24; E18). |
| D31 | Does the old `purchase` route stay for a while? | **No.** It no longer exists. |
| D32 | Who counts as staff for §13.2? | Level 99, the same people who can edit the Brain settings. |
| D33 | Is there a per-member switch to the new flow? | **No.** There is one flow (owner, 2026-10-24; E19). |

## 13.6 Not part of this step, and still to do

* The old browser screens for the removed second backend (`api.ts`, `dns.ts`, `purchase.ts`, `search.ts`, `website.ts` and the `else` branches that run when `surfaceMode` is false) are dead code now; `surfaceMode` is always true. They are removed together with the screens that use them.
* A run on the dev server with two real accounts (both add one name; only the one that publishes the TXT record becomes active).
