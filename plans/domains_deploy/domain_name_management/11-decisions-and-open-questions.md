# 11 — Decisions and open questions (domain name management)

> **Split 2026-10-23.** This step was written as one plan for domain names *and* website deployment. It is now two plans: this file is the **domain name management** half; the **website deployment** half is [../new_way/11-decisions-and-open-questions.md](../new_way/11-decisions-and-open-questions.md). Step, section (§), finding (F), decision (D) and report (E) numbers are unchanged, so a reference such as "04 §4.5" or "D19" means the same thing in both folders. Where a section moved to the other half, a pointer stands in its place. Decisions D3–D10, D12, D14, D19–D22, D24–D27 and D29 (claims, DNS, purchase, the shared settings/ssh/transport/guard/cut-over decisions) are here; D1, D2, D11, D13, D15–D18, D23 and D28 (the website decisions) are in the other half.

Everything the owner has to settle, with a recommendation for each. "Needed before" says which step's work depends on the answer, so the rest can proceed.

**Status as of 2026-10-09** (steps 02–10 are built; 12 is not). Each decision below is in one of four states, shown in the board that follows:

- **Owner answered** — the owner said it in words or by instruction.
- **Built as recommended — confirm** — nobody has answered, but the code embodies the recommendation. It stays a decision until the owner says "yes"; if the answer is different, the cost of changing it is stated.
- **Open — needs the owner** — the code does not decide it and something real waits on it.
- **Not reached** — belongs to step 12 (tenant move), not built.

## Status board

| # | State | What the build did | What is left |
|---|---|---|---|
| D3 disputes | **Open — needs the owner** | Contested claims are stored, listed in the service (`contested()`), and transferable (`transfer()`); **no staff endpoint or screen exists** (08 §8.7 not built) | A named owner, a response target, then the staff endpoints and the Flutter screen |
| D4 subdomains | **Built as recommended — confirm** | A subdomain is refused with "add the main domain" (02) | None unless the owner wants record-level delegation |
| D5 legacy claims | **Built as recommended — confirm** | `proof_level = legacy`, active, re-verified only when contested (02, backfill) | The backfill dry run on a copy of **production** data (P1) |
| D6 registrant | **Owner answered 2026-10-09 (for now: the platform; later: the member)** | The platform is the registrant: `NameComDriver` sends no contacts, so the Name.com account's default contacts are used | Member as registrant is filed in `plans/TODOS.md` (contact collection before payment, transfer-out, what "release" means) |
| D7 sites name at activation | **Built as recommended — confirm** | The sites name exists at activation pointing at the parking address; the apex has no address until a site exists (04) | — |
| D8 one active claim | **Built as recommended** (a) | Stored generated columns + unique indexes; check C1 passed on MariaDB 10.5.29 | Production's engine/version was **not** checked |
| D9 DNS per site | **Built as recommended — confirm** | Per-site A records in the sites list (04) | — |
| D10 payment confirmation | **Answered as built** (in-process lookup) | `PaymentGateway::snapshot()` | Whether the Direct Payments product accepts a dynamic item is **not verified** (see D29) |
| D12 refund/deletion | **Built as recommended — confirm** | A purchased domain: release stops auto-renew and ends at expiry; staff-only immediate removal (05) | Refund rules are billing's |
| D14 signed GET | **Answered as built** (a) | All calls are a signed `POST` | — |

## Decisions

| # | Question | Options | Recommendation | Needed before |
|---|---|---|---|---|
| **D3** | **Who settles a dispute the machine cannot** (both claimants appear to control the DNS)? | Staff queue with evidence and an audited transfer action | As written (02 §2.7, 08 §8.7). Needs a named owner and a response target | 02 P1 |
| **D4** | **Subdomain claims** (`blog.example.com`) | (a) refuse in v1; (b) allow as record-level delegation | (a). Subdomains bring zone cuts and dangling-subdomain takeover; revisit after v1 | 02 P0 |
| **D5** | **Existing (legacy) live claims** — grandfather without a TXT? | (a) `legacy` proof, active, re-verified only if contested; (b) require a TXT within N days | (a). (b) risks taking live customer sites down for a paperwork reason | 10 §10.4 |
| **D6** | **Registrant of record for purchased names** — the platform or the member? | (a) platform (simpler renewals/NS control; the member does not own the name outright); (b) member (ICANN contacts are theirs; transfer-out is straightforward; the platform needs registrar delegation rights) | Needs a legal/product view, not a technical one. Affects contact collection before payment, transfer-out, and what "release" means (05 §5.5) | 05 P1 |
| **D7** | **Publish the sites name at activation (pointing at parking), or only at first deploy?** And does the apex/`www` of a managed domain point at parking before a site exists? | (a) sites name at activation, apex omitted until a site exists; (b) nothing until deploy; (c) both at activation | (a): the name the requirement says is "created with the domain" exists and resolves; a member who moved their NS to us and has no site yet is not given a parking page on their own apex without asking | 04 P0 |
| **D8** | **How to guarantee "one active claim per name":** stored generated column + unique index, or a side table | (a) generated column (03 §3.2); (b) `brain_active_names` side table | (a) if check C1 passes on both engines; otherwise (b) — same guarantee | 03 P0 |
| **D9** | **Per-site DNS records, or a wildcard behind a routing layer?** | (a) per-site A records in the sites list (as required); (b) `*.sites` to an edge proxy | (a). (b) adds a proxy tier everything depends on, and contradicts "applied to the subdomains list" | 04 P0 |
| **D10 (answered as built 2026-10-08: (b), in-process)** | **How the Brain learns an invoice is paid** (05 §5.2 step 3). *As built: the Brain IS the Direct Payments host, so the purchase saga asks `PaymentGateway::snapshot()` directly (which itself confirms with the processor when the row lags); no webhook is needed.* Original question: | (a) webhook from billing; (b) lookup call to the billing/direct-payments service; (c) payment performed by the Brain | Decided with `plans/billing`'s owner; (a) or (b) must exist before purchase goes beyond the fake driver. Not (c): card authentication is browser-side | 05 P1 |
| **D12** | **Refund and deletion policy for purchased domains** | Immediate delete + partial refund / no refund, stop auto-renew, release at expiry | Stop auto-renew and release at expiry; staff-only immediate removal; the refund rules are billing's | 05 P1 |
| **D14** | **Add a signed `get()` to `BrainSurfaceClient`?** | (a) all calls `POST`; (b) add signed `GET` | (a) for P0 (matches `/billing/enforce`); (b) only if read-heavy screens need cacheability | 08 P1 |
| **D19 (owner answered 2026-10-09)** | **Where do the domains settings live?** | `.env` / config files / the Brain settings document | **The Brain settings document only — no `.env`, no defaults, no fallbacks** (the owner's instruction). Built: 95 settings under `controller.domains`, read through `DomainSettings::get()`, which stops and names a missing one | done — `complete/00_extras.md` E7 |
| **D20 (owner answered 2026-10-09; closed)** | **How does the Brain log in to other servers?** | (a) ssh keys; (b) a password, with bash driving and `expect` answering the prompts | **(b), no key management** (the owner's instruction). Built: `core/scripts/ssh/afs-ssh-run` (+`.expect`); the login is read from each server's own record. **The owner closed the sub-questions on 2026-10-09:** plain-text passwords in the database are acceptable; production servers allow password login; the production Brain's OpenSSH and `expect` are expected to work (not independently checked) | E8 |
| **D21 (owner answered 2026-10-09; built)** | **Name.com API: move `NameComDriver` from the legacy v4 API to CORE v1?** | (a) move to CORE v1; (b) keep v4 | **(a)** (the owner's instruction). Built from Name.com's OpenAPI reference 1.35.0 (`/core/v1`), keys from the Brain settings document; the lab's stored sandbox credentials were accepted by Name.com's sandbox (`hello`, `checkAvailability` and `search` answered 200 in the shapes the driver reads). **Run on 2026-10-09:** a sandbox registration, retry, set-nameservers, auto-renew, lock and renew through the driver (E10). **Still to do before a real purchase:** production credentials (filed in `plans/TODOS.md`) | E10 |
| **D22 (owner answered by instruction; confirm the details)** | **How is the cut-over switched, and for whom?** | (a) a global flag in `.env`; (b) per member, decided by the Brain settings | **(b)**: `new_flow.all` / `new_flow.members` in the Brain settings; members asks the Brain and caches a minute; an unreachable Brain leaves the member on the old flow. Confirm: the 60-second delay, and the fail-safe direction | E9 |
| **D24** | **Queue and scheduler on the Brain.** The plan assumed Redis; the lab Brain uses the `database` queue and has **no scheduler and no queue worker at all** | (a) keep `database`, install `lab2/provision/brain-worker.sh` (unit + cron); (b) switch to Redis | (a) — deployments make progress without a worker (`domains:deploys`), but the scheduler is required for everything scheduled | before the lab Brain runs the new code; production: ask what runs `schedule:run` there |
| **D25** | **Where do domains alerts go?** `domains:health` writes each alert to the application log every 15 minutes; there is no staff screen and no use of the Brain's notification path | (a) log only; (b) the Brain notification path; (c) a staff screen | (b) + (c), once D3's staff surface is built | 10 §10.9 |
| **D26** | **Server inventory completeness.** The fleet guard refuses to build websites and publish while any active server is outside `allowed_server_cidrs`; in production that list must be the whole fleet | The owner supplies production's networks | Needed before the guard is switched on in production; a wrong list stops publishing | 09 §9.1 production |
| **D27** | **Translations.** 109 new UI keys exist in English only | Translate to the other five languages, or ship English first | The owner / product | 08 P1 |
| **D29 (owner answered 2026-10-09; built)** | **Who builds the domain invoice, and where does the Brain learn the Direct Payment?** | The Brain writes the invoice; or the browser builds it with the SDK like every selling façade | **The browser builds it with the SDK** (the owner's instruction: the Direct Payments API is already built, use it as the playground samples do). The members app uses its Direct Payment and its purchase item (`VITE_DIRECT_PAYMENT_ID`, `VITE_DIRECT_PAYMENT_PURCHASE_ITEM_ID` — a dynamic item) at the amount of the Brain's quote, and **sends the invoice, Direct Payment and processor references to the Brain** (`/purchases/{ref}/invoice`); the Brain verifies the invoice through `PaymentGateway`. Nothing about the Direct Payment is configured on the Brain. The wider invoice rules (server-priced lines, status only from the provider) are filed in `plans/www/direct_payments/TODOS.md` | E11 |

## Settled in this plan (recorded so they are not re-opened by accident)

- **Domain names are exempt from plan quotas; only websites count** — feature 1, F1.0/D3, restated.
- **NS delegation is not proof of identity; a per-claim TXT or the registrar purchase is** — 02 §2.5.
- **A healthy incumbent cannot be displaced by a later proof** — 02 §2.7.
- **The Brain owns state and authority; members is a client over the signed surface** — 08 §8.1–8.2.
- **`site_ref` is issued at activation and never reused**, and is separate from the system user and database names — 03 §3.3, 07 §7.4.
- **Zones, includes and vhosts are rendered in full, written atomically, verified** — 00 §0.3.
- **Lab test domains are `tenantN.10-0-0-243.sslip.io`**, hosted on the brain VM; the DNS server is made authoritative for them with a zone file and a zone stanza in `/etc/named.conf` — 09 §9.3, §9.11, 04 §4.4. The running lab database already points at lab servers; the seed file still contains production rows, so a freshly built lab needs `domains:seed-lab`; the guard in code **is** built and refuses to publish or build while an active server is outside the allowed networks — 09 §9.1, `complete/09-lab2-environment.complete.md`.
- **Who is on the new flow is decided per member by the Brain settings (`new_flow.all` / `new_flow.members`)**; the screens tell the backends apart by `api: "brain-surface"`, so one UI build serves both until the cut-over — E9, 08 report §3.

## Open questions that are checks, not decisions

The P0 checks C1–C11 of 10 §10.2 were answered on 2026-10-09 (`complete/10-rollout-and-testing.complete.md` §2). The domain ones (C1, C2, C7, C9) are answered; **C7** found that Name.com's API v4 is being retired (D21). C6 and C11 are the website plan's.

## What the owner should read first

1. **D21** — the driver now speaks Name.com's CORE v1 and the sandbox accepts the lab's stored credentials; a sandbox registration through it has been run (E10); production credentials are filed in `plans/TODOS.md`.
2. **D29** — the invoice product for domain purchases (D6 is answered for now and filed in `plans/TODOS.md`).
3. **D24** — the scheduler and worker on the Brain (the lab Brain has neither).
4. **D3** — the product decision with nothing built behind it: dispute handling.
