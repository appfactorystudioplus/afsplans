# Handoff to the server agent — wildcard certificate `*.sites.appfactory.studio`

> Written 2026-10-09 by the planning session (Claude Code on the owner's PC). The planning session cannot reach the production servers; **you can**. This file says what to do first and what to report back.
> Background: `packages/afsserver/plans/domains_deploy/old_way/05-certificates.md` §5.1 (the plan) and `07-decisions-and-open-questions.md` OD4 (the open choice).

## 1. Why

Every tenant website also answers on `<ref>.sites.appfactory.studio`. Today each one gets its own Let's Encrypt certificate from `certbot` at build time. That will hit Let's Encrypt's per-registered-domain limit (about 50 certificates a week), so the owner wants **one wildcard certificate** for `*.sites.appfactory.studio`, installed on every tenant host.
Let's Encrypt only issues wildcards through the **DNS-01 challenge**: a TXT record at `_acme-challenge.sites.appfactory.studio` must be answered by our public name servers while the certificate is being issued.

The planning session does not know how the production DNS and hosts are actually set up, so **this first task is discovery only**. The answers decide which of two designs is used:

- **A.** certbot hook scripts that add and remove the TXT record the way our DNS scripts already do (ssh to the DNS server, edit an include file, bump the serial, reload `named`).
- **B.** a permanent `_acme-challenge.sites CNAME` pointing at a tiny dynamic-update zone (`acme.appfactory.studio`) updated with a TSIG key and certbot's `dns-rfc2136` plugin, so the main zones are never edited at renewal time.

## 2. Ground rules

1. **Read-only.** Change nothing: no edits to `named.conf`, zone files, nginx or Apache, no `rndc reload`, no installs, no certificate requests (not even `--staging`), no ssh logins that change state.
2. **No secrets in the report.** Do not print private keys, TSIG/`rndc` keys, passwords, tokens or full `.env` files. Where a command would show one, show the line with the value replaced by `<hidden>`. Paths and file names are fine.
3. **Trim output.** First 30 lines of a zone file, counts instead of full listings, `head` on anything long. Say how you trimmed.
4. If a command fails or the thing does not exist, **say so** ("not installed", "no such file") — an honest "not found" is a useful answer. Do not guess.
5. Stop after the report. Wait for the next file from the planning session before doing anything else.

## 3. What to check

Write the answer to each numbered item in the report (section 4).

### 3.1 DNS (the authoritative servers)

1. Which servers are authoritative, and which are ours: `dig +short NS appfactory.studio`, `dig +short NS sites.appfactory.studio`, and for each name server its IP and which machine it is. Is **`sites.appfactory.studio` a zone of its own** (does `dig SOA sites.appfactory.studio` answer with its own SOA) **or only names inside the `appfactory.studio` zone**?
2. On the DNS server that holds the zone: OS (`cat /etc/os-release | head -3`), `named -v`, `systemctl is-active named`.
3. The zone stanzas: `grep -n -B1 -A8 'appfactory.studio' /etc/named.conf` (hide any `key` secret). Show whether the stanzas have `allow-update`, `update-policy` or `allow-transfer`, and whether `named.conf` defines any `key` (names only) and `include`s other files.
4. Where the zone data lives: `ls -l /var/named/zones | head -40`. For the `appfactory.studio` zone file and any `*include*` / `sites` file: the first 30 lines and `wc -l`, plus `grep -n '\$INCLUDE' ` in them, and **how a tenant's record looks** (one example line, no need to list all).
5. How a DNS change is applied today and by whom: which script or program edits these files (name and path), how the serial is bumped (format, e.g. `YYYYMMDDnn`), and the command that reloads (`rndc reload`, `systemctl reload named`, …). Is `afs-dns-agent` (from `w/www/core/scripts/dns/`) installed here? `command -v afs-dns-agent; ls /usr/local/bin | grep -i afs`.
6. Does `rndc` work from root here (`rndc status | head -5`)? Is any dynamic-update key already defined?
7. CAA records that could block Let's Encrypt: `dig +short CAA appfactory.studio`, `dig +short CAA sites.appfactory.studio`.
8. Is any `_acme-challenge` record present now: `dig +short TXT _acme-challenge.sites.appfactory.studio`.
9. Are the name servers reachable from the public internet on UDP and TCP 53? (Let's Encrypt must reach them.) From this server: `ss -lnu | grep ':53 '`, `ss -lnt | grep ':53 '`, and the firewall zone/ports (`firewall-cmd --list-all` or the equivalent). Say whether an outside check is still needed.

### 3.2 Where the certificate will be requested from

The machine that runs the ACME client will be one that can (a) reach Let's Encrypt, (b) change DNS or ask for it to be changed, (c) log in to every tenant host. The Brain host is the likely candidate. On **the Brain host** and on **the DNS server**, report:

10. `command -v certbot acme.sh lego`; versions of whatever exists (`certbot --version`); `certbot plugins 2>/dev/null | head -20`; Python version; how certbot was installed (package, snap, pip).
11. Existing certificates and accounts: `ls /etc/letsencrypt 2>/dev/null`, `certbot certificates 2>/dev/null | grep -E 'Certificate Name|Domains|Expiry' | head -60`, and the **count** of certificates under `/etc/letsencrypt/live`. How many are for `*.sites.appfactory.studio` names? (Do not print file contents.)
12. Renewal: `systemctl list-timers --all | grep -i -E 'certbot|renew'`, `crontab -l`, `ls /etc/cron.d`, any `--deploy-hook` in `/etc/letsencrypt/renewal/*.conf` (`grep -h -E 'hook|authenticator|installer' /etc/letsencrypt/renewal/*.conf | sort | uniq -c`).
13. Outbound access to Let's Encrypt: `curl -sS -o /dev/null -w '%{http_code}\n' https://acme-v02.api.letsencrypt.org/directory` and the same for `https://acme-staging-v02.api.letsencrypt.org/directory`.
14. How many certificates for `*.sites.appfactory.studio` names were issued in the last 7 days (the rate-limit exposure): `curl -s 'https://crt.sh/?q=%25.sites.appfactory.studio&output=json'` and count entries with `not_before` in the last 7 days (or say crt.sh was unreachable).

### 3.3 The tenant hosts

15. The list of active tenant (HTTP) servers the Brain uses: from the Brain database table `brain_http_servers` — columns `reference_num`, `public_ipv4`, `server_type`, `active` only (no logins). If you cannot read the database, say so.
16. How the Brain logs in to those hosts today (the script or program name; the owner said: password, driven by bash and `expect`). From the Brain host, run **one harmless command** (`hostname`) on **one** tenant host the same way and report whether it works. Do not print the credentials.
17. On one tenant host (pick one, say which): `nginx -v 2>&1`, `systemctl is-active nginx httpd`, `nginx -T 2>/dev/null | grep -n -E 'ssl_certificate' | head -20` (paths only), `ls -l /etc/nginx/certs 2>/dev/null | head`, how many per-tenant files exist in `/etc/nginx/conf.d` (count) and one example `server_name` + the `ssl_certificate` lines of a tenant that already has a certificate. Does a tenant's nginx block listen on 443 itself, or is TLS ended elsewhere?
18. Free disk and who owns `/etc/nginx/certs` (so we know where a shared pair can live).

### 3.4 Two questions for the owner (put them in the report if you can answer, otherwise leave them)

19. Where should the wildcard pair live on a tenant host (suggestion: `/etc/nginx/certs/sites-wildcard.pem` and `sites-wildcard-key.pem`, root-owned, key mode `0600`)?
20. Is there a real domain, other than `appfactory.studio`, that the owner can point at a public server for an end-to-end test of the member-domain certificate later? (Not needed for this task.)

## 4. Report format

Append a section to **this file** called `## Agent report — <date>` (or, if you cannot write here, send it to the owner as one message). Use this layout so the planning session can read it quickly:

```
## Agent report — <date>

Machines: <role → hostname/IP>, e.g. DNS: ns1 (…), Brain: … (…)

3.1  DNS
1. …
2. …
(… one numbered answer per item above …)

Things that look wrong or risky: …
Things I could not check, and why: …
Anything I changed: none  (if not "none", say exactly what)
```

Mark each answer `OK`, `NOT FOUND` or `COULD NOT CHECK` at the start of the line.

## 5. What happens next (so you know what you are working toward)

The planning session will read the report and reply in a new file with: the chosen design (A or B), the exact hook scripts or zone/`named.conf` changes to review, and a **staging** run plan (`--staging` certificates are not trusted and do not count against the production limits). Nothing is installed or changed until the owner has read that and said yes.
