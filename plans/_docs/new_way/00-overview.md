# 00 — Overview (website deployment)

> **Split 2026-10-23.** This step was written as one plan for domain names *and* website deployment. It is now two plans: this file is the **website deployment** half; the **domain name management** half is [../domain_name_management/00-overview.md](../domain_name_management/00-overview.md). Step, section (§), finding (F), decision (D) and report (E) numbers are unchanged, so a reference such as "04 §4.5" or "D19" means the same thing in both folders. Where a section moved to the other half, a pointer stands in its place.

## 0.1 The goal

Given an **active claim** on a domain name (the domain-name plan: the member bought or connected the name, proved control, and the platform serves its zone), a member can:

- **Deploy a website** on the domain. The system picks the best HTTP server, builds the site there, and the site answers
   on **both** names.

## 0.2 The website-deployment flow on one page

```
 MEMBER (members UI)                    BRAIN (www)                              INFRA
 ───────────────────                    ───────────                              ─────
 (an ACTIVE claim already exists and its zone is published — domain-name plan: claim, proof, zone, sites name)

 "create website" ───────────signed────▶ 05  quota canUse/consume (websites only)
                                          06  select + RESERVE best HTTP server
                                          07  deployment pipeline (resumable, idempotent steps)
                                               ├─ tenant user, DB, directories ───────────────▶ HTTP server
                                               ├─ template ref → composer/npm install on the host → site → vhost (BOTH names)
                                               ├─ DNS: apex/www + sites name → server IP ──────▶ BIND
                                               ├─ TLS: wildcard for sites, per-domain for custom
                                               └─ health check by Host header, then public check
 progress widget ◀────────signed poll──── 07  steps + percent
 "open site" enabled                      site live on  https://<site_ref>.sites…  and  https://example.com
```

## 0.3 Principles

1\. **The Brain is the authority; members is the client.** Who owns a name, which server hosts a site, what is in a zone —
   all Brain decisions, in Brain tables, written once. Members asks, over the signed surface
   (`/api/internal/brain/*`, `BrainSurfaceClient`), and never receives the Brain's whole config.
4\. **Convergent, not appended.** Zone files, the member-zone include, the sites include and vhosts are **rendered in full
   from the database** and written atomically. Never `echo … >>` a line into a live file (the current `build_dns`).
5\. **Every remote action is an idempotent, resumable step** with a recorded result, a retry policy, and a clean-up
   (`deprovision`) that works from any point.
8\. **Explain every choice.** Server selection and every refusal record *why*, so support can answer without a developer.

(Principles 2, 3, 6 and 7 — one active claim, proof, system versus member records, names never reused — belong to the domain-name plan.)

## 0.3a Delivery order

The target design replaces the shell pipeline with an agent and a queued runner (steps 04, 07). Because the shell pipeline is what is being
fixed and run on the lab today, **it is delivered in two paths**: Path A fixes the existing scripts in place (07 §7.0), Path B builds the agent
and runner after that. Claims, DNS, selection, transport and moves (02–06, 08, 12) do not depend on the choice.

## 0.4 What changes, in short (website deployment)

| Area | Today | Plan |
|---|---|---|
| Best server | First active `Head` server; the "least tenants" and geo-distance code is unused | Scored, reserved, explainable selection (06) |
| Deploy | zip the builder, `scp`, `expect` with a password, `npm install` and `npm run build` on every tenant, **two copies** of the site (`www`, `www-own`) | Pinned template ref, dependencies installed on the host the same way every time (credentials set once at server initiation), one docroot, one vhost with both names, symlink swap, rollback (07) |
| Where it runs | PHP-FPM `popen` of a script under hard-coded `/home/appfactorystudio/...` paths | A queued job on the Brain with a stored state machine (07, 08) |

## 0.5 Out of scope

- Per-domain modules (forms, ecommerce, …) and their retention. Feature 1 owns "retire = site offline"; step 07 §7.9
  only provides the switch it needs.
- Bulk rebalancing and member-requested region moves (step 12 P2). Moving one site between servers **is** in scope: step 12.

## 0.6 Phases at a glance

| Phase | Contents | Steps |
|---|---|---|
| **P0** | Select + deploy on lab2 `web`; signed transport (the website routes); the tenant host changes | 06, 07, 08, 09 |
| **P1** | TLS automation; resumable deploy and rollback; quota hooks | 07, 10 |
| **P2** | Wildcard cert via DNS-01, release channels, geo-aware placement | 06, 07 |
