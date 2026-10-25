# 10 — Rollout and testing (domain name management)

> **Split 2026-10-23.** This step was written as one plan for domain names *and* website deployment. It is now two plans: this file is the **domain name management** half; the **website deployment** half is [../new_way/10-rollout-and-testing.md](../new_way/10-rollout-and-testing.md). Step, section (§), finding (F), decision (D) and report (E) numbers are unchanged, so a reference such as "04 §4.5" or "D19" means the same thing in both folders. Where a section moved to the other half, a pointer stands in its place. The domain-name checks, work order, production-data adoption, test cases and cut-over switch are here; the website-deployment ones are in the other half.

Order of work, the checks that must come first, how existing production data is adopted without breaking live sites, the
test matrix, the cut-over, and rollback.

## 10.1 Working rules for whoever builds this

These are the project's standing rules, restated where they bite this work:

- **Write the chunks, run the test suite once at the end.** Between chunks, static analysis only. One heavy process at a
  time, below-normal priority, kill leftover Node/Electron processes.
- **Do not install SDKs, runtimes or packages to test code.** Verify with stubs and fakes (the fake transport, the fake
  registrar); the owner runs the real install on the lab VMs.
- **Never commit, branch or push** on the assistant's initiative; say which of the independent `w/*` repositories (`www`,
  `members`) each change landed in. **Never start the Vite dev server.**
- **Never assume `php artisan config:cache`.** Read configuration through `config()`; no step depends on it being cached or
  not.
- **New TypeScript** in `members` follows the project TS rules: no optional chaining, no `any`; assert dependencies
  explicitly and throw. `directPayments.create()` stays typed.
- **`.env` files are not edited directly;** the lab keys go in `_env/lab2/.env` and the owner applies them.
- **`wwwafsportal_v1` is not read** (D1 explains why the template contract in 07 §7.3 is stated abstractly).
- **Off-plan fixes** found on the way go in `complete/00_extras.md`, not in a numbered doc.

## 10.2 P0 checks — before writing code that depends on them

Each is a question the plan assumes an answer to. Each gets a one-line result written into the first completion doc.

| # | Check | If the answer is "no" |
|---|---|---|
| C1 | lab2's database engine supports **stored generated columns with a unique index** (03 §3.2); same for production's engine | Use the `brain_active_names` side table (D8) |
| C2 | The queue driver in the Brain's `.env` is a real queue (Redis) and a worker can be supervised on the Brain VM | Add the queue + worker provisioner; do not fall back to `sync` |
| C7 | The Name.com base URL and sandbox behaviour (05 §5.3) | Use `FakeDriver` only until confirmed |
| C9 | `named`/BIND version on the DNS VM supports `rndc reconfig`, `rndc reload <zone>` and `check-names` as configured | Adjust the agent's commands |

## 10.3 Order of work

```
 W0  P0 checks · lab hazard fix (09 §9.1) · env keys · DNS VM + tenant host changes (09 §9.5-9.7)
 W1  Schema, config, normaliser, claim service                                   (02, 03)
 W2  Verifier, activation transaction, events, uniform responses                 (02)
 W3  Zone renderer, validator, publisher, afs-dns-agent, sites render            (04)   ← first real BIND change
 W4  Signed Brain routes, members pass-through, list/claim/DNS screens           (08)
 W7  Purchase saga on the fake driver, purchase screen                           (05)
 W8  P1: reconcile, lapse/promotion, certificates, enable/disable, delete, mail, NameCom sandbox, staff endpoints
 W9  P2 items as wanted
```

W1–W3 can be proved without W4–W6 using artisan commands; W4 puts a UI on them; W5 and W6 need W3 for DNS steps 10–11.
Per the working rules, W1–W7 are written first and the test suite is run once, then W8 starts from a green base.

## 10.4 Adopting existing production data

Adding the two unique indexes (03 §3.2) to a table that today **allows** duplicates would fail, and a careless backfill
could take a live customer's site down. The order is fixed:

1. **Additive migrations first** — new nullable columns, new tables. No index yet.
2. **`php artisan domains:backfill-claims --dry-run`** (Brain) produces a report and changes nothing:
   - normalises every zone's `fqdn`;
   - groups rows by `fqdn`; for each group with more than one row, **proposes** the incumbent: the row with a live tenant
     if any, else a purchased row (`own = 1`) with a recorded registrar order, else the oldest; the rest are proposed
     `contested`;
   - lists every group with more than one member so staff can decide before anything is applied;
   - proposes a `site_ref` for each live tenant by **reusing its existing sites label** (`Subdomain2` — the `x1s…` / `xrn…`
     names already in DNS and in customers' bookmarks are valid DNS labels), so no live sites name changes;
   - proposes `brain_dns_subdomains` rows from the tenants and **compares them with the live include file** on the DNS
     servers (read-only), listing entries that exist in DNS but not in the database and vice versa.
3. **Staff resolve the conflict list.** Nothing is deleted; losers become `contested`.
4. **`domains:backfill-claims --apply`** writes the statuses. Existing live claims get `proof_level = legacy`: they stay
   `active` without a TXT as long as nothing contests them, and enter ordinary re-verification (02 §2.8) only if a
   contest appears (**D5**).
5. **Add the unique indexes.** If a duplicate remains, the statement fails and nothing is half-applied.
6. **Adopt mode for the first publish.** The publisher compares what it would write with what is on the server. For the
   sites include (today an append-only file nobody rendered) it **refuses to replace a file that would lose lines** unless
   told `--accept-drop <list>`, so a hand-added or stale entry is never silently deleted. After the first clean adoption the
   check relaxes to a warning.

lab2's data is throw-away (feature 1, D6): it is cleaned or replaced, not adopted.

## 10.5 Test matrix

Tests live with the code they test (Brain: `w/www/tests`; members: `w/members/tests`; shell agents: scripts run on the lab
VMs). External systems are faked; the lab VMs are the integration environment.

| Area | Level | Cases |
|---|---|---|
| Normaliser / refusals | isolated | The 02 §2.12 list: case, dot, `www.`, IDN, reserved, public suffix, subdomain, lab suffix |
| Claim states | isolated + DB | Every transition in 02 §2.3; illegal transitions rejected; events written |
| Activation | DB (two connections) | 20 parallel activations of 2 claims ⇒ exactly one active; unique-index backstop exercised |
| Verifier | isolated + lab | Token at all / some / no authoritative servers; stale secondary; wrong token; recursion-only answer; NS delegated but token absent (the §2.5 case) |
| Uniform responses | feature | A name held by someone else returns the same shape and timing class as a free name |
| Renderer | golden | 04 §4.10 fixtures; `named-checkzone` on each |
| Serial | property | Monotonic over 1,000 edits and midnight |
| Publisher | fake transport + lab | Stage/check/rename/reload/verify order; failure at each sub-step leaves live data untouched; superseded jobs; convergence after corrupting the include |
| Records API | feature | Every rule in 04 §4.2; system records immutable; arbitrary-key writes rejected |
| Purchase | fake registrar | Success; unavailable; decline ⇒ refund; timeout ⇒ reconciler; NS failure ⇒ retry; double-click ⇒ one intent; price change ⇒ requote |
| Transport | feature | Unsigned/replayed/other-app-key requests rejected; ownership 404 on another member's claim |
| Guard | isolated | Server outside the allowlist refused before connecting; wrong-`DOMAINS_ENV` boot check |
| UI | manual script + type-check | Each state chip, the verify polling, locked records, the retry/cancel controls, progress to 100 % and the Open buttons |
| Backfill | DB | Dry-run reports without writing; apply is idempotent; duplicates block the index until resolved |

## 10.6 Cut-over

A per-member flag `domains.new_flow` (default off) switches the members controller between the old and the new path.

1\. Staff accounts on the lab, then on production, with the flag on, alone.
2\. Production bootstrap runbook (§10.7) completed and each server checked.
3\. Backfill applied (§10.4), indexes added, first adopted publish done.
4\. Flag on for all new claims and new websites; **existing** websites keep working untouched — nothing is redeployed.

(Steps 5–7 — remove the old provisioning code, retire `deploy-tenant.sh`, retire the legacy move chain — are in the website-deployment plan.)

## 10.7 Production bootstrap runbook (written when W3/W6 are done; outline)

- **Each DNS server:** install `afs-dns-agent`; add the `include` line and `rndc` control to `named.conf`; create staging and
  trash directories; authorise the Brain's key (or record the password transport); `named-checkconf`; `rndc reconfig`.
- **Brain:** `config/domains.php` values in `.env`; queue worker service; scheduler entries; the pinned template ref; kill switches
  default **off** until the dry runs pass.
- **Dry runs:** `domains:publish --dry-run <claim>` prints the zone and the server commands; the deploy dry run (as
  `InfraTransfer` offers) prints the payload for each step.
- **Name.com sandbox purchase** with real sandbox credentials.

## 10.8 Rollback

- Flag off ⇒ the old path serves again; new tables and columns are inert.
- Kill switches pause claims, purchases, deploys, or publishing separately; already-published zones and running sites keep
  serving.
- A bad zone publish never replaces a live zone (stage → check → rename); a bad vhost is restored by the agent.
- The unique indexes and generated columns are harmless if the new code is off.
- Database restore is not needed to roll back; the claim statuses are additive.

## 10.9 Observability

- `brain_domain_events` per claim; `brain_deploy_steps` per deployment; `brain_dns_publish_targets` per server.
- Counters: verification success rate and median time-to-verify; publish latency and failure rate; deploy duration per
  step; failures by step and by server; reservations expired; reconcile drift found.
- Alerts (staff screen and the existing Brain notification path): publish failing > 15 minutes; a deployment `running` with
  no heartbeat for 5 minutes; a server dropping out of eligibility; certificate `not_after` < 14 days; reconcile finding an
  invariant violation.

## 10.10 Cannot be proven from here — a person has to check

1\. A real registrar purchase and nameserver change end to end (sandbox, then production with a cheap name).
2\. Nameserver propagation behaviour through real recursive resolvers for a name moved to the platform.
5\. The production DNS servers' `named` configuration, versions and access method.
6\. Mail behaviour for a domain moved to the platform (out of scope here, but a moved NS can break existing mail).

## 10.11 Definition of done

- **P0 done:** the lab acceptance in 09 §9.9 items 1–6 pass; the guard is demonstrated; the P0 checks are answered in
  writing; `complete/` docs exist for each step carried out.
- **P1 done:** items 7–9, the backfill dry run against a copy of production data, the production bootstrap on one DNS and one
  HTTP server, a staff account cut over end to end.
- **P2:** as selected.
