# 00 — Overview (domain name management)

> **Split 2026-10-23.** This step was written as one plan for domain names *and* website deployment. It is now two plans: this file is the **domain name management** half; the **website deployment** half is [../new_way/00-overview.md](../new_way/00-overview.md). Step, section (§), finding (F), decision (D) and report (E) numbers are unchanged, so a reference such as "04 §4.5" or "D19" means the same thing in both folders. Where a section moved to the other half, a pointer stands in its place.

## 0.1 The goal

A member can:

1. **Get a domain name** two ways — **buy** it through the platform (the platform registers it and points its
   nameservers at ours) or **connect** one they already own.
2. Have that name **served from the platform's BIND** (managed DNS), with the zone file written to the DNS servers
   automatically.
3. Get a **second name** for free, `<site_ref>.sites.appfactory.studio`, created with the domain and listed in the
   platform's own subdomains list.

(Item 4 of the original list, deploying a website on the domain, is in the website-deployment plan.)

Two members must never be able to take each other's name, accidentally or on purpose. That is the hard part, and step 02
exists for it.

## 0.2 The domain-name flow on one page

```
 MEMBER (members UI)                    BRAIN (www)                              INFRA
 ───────────────────                    ───────────                              ─────
 add / buy a domain ─────────signed────▶ 02  create CLAIM (pending)
                                          05  purchase: reserve → charge → register → NS set
                                          02  connect:  issue proof token (TXT) ........... member publishes it
                                          02  verify: TXT at the real authoritative DNS
                                               │
                                               ▼  UNIQUE(active_fqdn) decides: exactly one ACTIVE claim per name
                                          03  allocate site_ref  (never reused)
                                          04  render zone + sites record ───publish job──▶ BIND (all DNS servers)
                                          04  named-checkzone → rndc reload → verify serial
```

(From "create website" on, the flow is the website-deployment plan's: [../new_way/00-overview.md](../new_way/00-overview.md).)

## 0.3 Principles

1\. **The Brain is the authority; members is the client.** Who owns a name, which server hosts a site, what is in a zone —
   all Brain decisions, in Brain tables, written once. Members asks, over the signed surface
   (`/api/internal/brain/*`, `BrainSurfaceClient`), and never receives the Brain's whole config.
2\. **Exactly one active claim per name, enforced by the database**, not by an application check. Everything that
   publishes (BIND zone, vhost, certificate) reads only the active claim.
3\. **Proof beats typing.** Adding a name is a claim. Serving it requires proof of control. Buying it *is* the proof.
4\. **Convergent, not appended.** Zone files, the member-zone include, the sites include and vhosts are **rendered in full
   from the database** and written atomically. Never `echo … >>` a line into a live file (the current `build_dns`).
6\. **System records are not member records.** Records the platform owns (apex, `www`, mail host, sites name) are
   regenerated when a site moves; records the member owns are never touched by that.
7\. **Names are never reused.** A `site_ref` that has been issued is retired, not recycled, so an old link or an old
   cache can never land on someone else's site.
8\. **Explain every choice.** Server selection and every refusal record *why*, so support can answer without a developer.

(Principle 5, "every remote action is an idempotent, resumable step", belongs to website deployment.)

## 0.3a Delivery order

Claims, DNS, transport and purchase (02–05, 08) do not depend on how the website pipeline is delivered (Path A or Path B, see the website-deployment plan 0.3a).

## 0.4 What changes, in short (domain names)

| Area | Today | Plan |
|---|---|---|
| Who owns a name | Nobody. `store()` only checks the *same member's* zones, so two members can both add `example.com` | One active claim, DB-enforced; proof for connected names (02, 03) |
| Adding a domain writes BIND | **No.** `store()` saves a row on the Brain; `reload*` routes are stubs; the UI edits JSON that never reaches a name server | Publish job renders and pushes zone + include, verifies the serial (04) |
| Second name | Invented at deploy time, `xs…` or `x1s…`, rows written in two places, appended to a file | Allocated at domain creation, stored once, rendered into the sites include (03, 04) |
| Purchase | Charge in the browser, then registrar call, then the browser saves the row. Registrar uses `api.dev.name.com` | Server-side saga with an idempotency key, compensation, reconciliation (05) |
| lab2 | Seed data points `brain_dns_servers`/`brain_http_servers` at **production IPs** | Lab seed rows plus a guard (09) |

## 0.5 Out of scope

- Re-doing billing or the direct-payments product (step 05 uses it as is).
- Email hosting for the domain (mailboxes, Dovecot). The plan only makes sure the zone has working mail records and that
  the deploy does not break what `write_dovecot_files` does today.
- Registrars other than Name.com. The code is shaped so a second registrar is an adapter (05 §5.3), not a rewrite.

## 0.6 Phases at a glance

| Phase | Contents | Steps |
|---|---|---|
| **P0** | Claims + the unique index; verification; zone render and publish to one BIND (lab2); sites name; signed transport; lab seed + guard | 02, 03, 04, 05 (connect), 08, 09 |
| **P1** | Purchase saga and reconciliation; periodic re-verification and lapse; abuse limits | 05, 02, 10 |
| **P2** | Multiple DNS servers with AXFR; second registrar | 04, 05 |
