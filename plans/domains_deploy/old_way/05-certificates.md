# 05 — Certificates (P0 serving on the lab, P1 issuing in production)

Fixes **OF14**. `LETSENCRYPT_CHECK` stays the owner's switch: `false` on `lab2`, `vagrant`, `vagrant-lan`, `local` (the sites name cannot be validated there), `true` on `dev` and `prod`.

## 5.1 The sites name: one wildcard

Let's Encrypt limits certificates per registered domain (about 50 a week; confirm the current figure before relying on it). One certificate per tenant under `appfactory.studio` reaches it as soon as sites are created in numbers. **Decision (owner): a wildcard `*.sites.<zone>`**, installed on every tenant host, instead of certbot per tenant.

**What changes in the build (P0, lab and production, same code):**

- Two settings in the Brain config document, delivered in the job like `tenant_env`: `controller.tenant_cert` and `controller.tenant_cert_key`, the file paths **on the tenant host** (lab: `/etc/nginx/certs/tenants.pem` and `tenants-key.pem`, already on the web VM; production: the owner names the path).
- After the vhosts are written, if both files exist, `app_install.sh` adds the `:443` server for the sites name using them (same safe-write: temp name, `nginx -t`, swap, reload, restore on failure). If they do not exist the sites name is served on plain HTTP and the build records a warning (it does not fail).
- The per-tenant certbot block (`app_install.sh:724`) is no longer used for the sites name. The `LETSENCRYPT_CHECK` variable stays in the job and now controls only whether the member-domain job (§5.2) may call Let's Encrypt.
- The wildcard files are placed on a tenant host **once**, at server initiation, like the node and registry tokens — not per tenant.

**Issuing and renewing the wildcard (P1, production)** needs a DNS-01 challenge: a `_acme-challenge.sites` TXT record in the sites zone. Options (OD4): **A** the Brain runs the ACME client with a hook that adds the TXT through its DNS path, waits, removes it, then copies the renewed pair to every tenant host (over `afs-ssh-run`) and reloads nginx, on a daily schedule with renewal at 30 days; **B** BIND dynamic update (RFC 2136) with a TSIG key limited to that name. Either way: Let's Encrypt staging first, a recorded `not_after`, an alert at 14 days, and a written manual fallback.

## 5.2 The member's own domain

Today: the helper exists (`SetupLetsencryptCerts` / `RunAFSDNSCheck`, `app_install.sh:108–135`) but its call in `run_create_repo` is commented out, and its test is that the domain's NS records name `ns1.appfactory.studio`. The owner has not built certificate issuance for a real domain because there was no real domain to test against, and because it must first be checked that the domain belongs to the member.

**This plan does not enable it.** It records the gate it must pass when it is enabled, so nobody turns the commented call on without it:

1. The domain is the member's: the claim for the name is `active` and its proof is accepted (`domain_name_management` step 02: per-claim TXT or registrar purchase). NS delegation alone is **not** proof.
2. A website exists for the name and is live on this server.
3. The apex and `www.<apex>` resolve, through the configured resolvers, to this tenant's server.
4. `LETSENCRYPT_CHECK` is `true`.
5. No valid certificate covering both names already exists.

Mechanism when it is built (P1, after the owner has a real test domain): `certbot certonly --webroot` with a platform-wide `location /.well-known/acme-challenge/` on nginx `:80` (so no web-server surgery per tenant), then the safe-write rewrite adding the `:443` server for the member's names; retry with backoff for up to 72 hours; a failure never takes the site down; renewal by `certbot.timer` with a reload hook. It runs after the build as its own job (the sites name must already resolve), not inside `app_install.sh`.

## 5.3 Testing

| What | How | Proven by |
|---|---|---|
| The `:443` sites server with the lab wildcard, and the restore on a bad file | on the lab web VM | the lab |
| The gate (§5.2), when built | feature tests with a fake DNS lookup and a fake host command, one test per refusal | PHPUnit on the real test database |
| The host script's flow | a stub `certbot` that writes a self-signed pair into `live/<name>/` | the lab |
| Real issuance (HTTP-01 and the wildcard DNS-01) | needs a name that resolves publicly: one inexpensive real domain pointed at a public server, Let's Encrypt **staging** first | only then; until then the completion document says "unverified" |

## 5.4 Work

| Phase | Work |
|---|---|
| **P0** | `controller.tenant_cert` / `_key` in the Brain settings and the job; the `:443` sites server in the vhost step; stop using the per-tenant certbot call; lab acceptance A9 |
| **P1** | The wildcard issue/renew/distribute command and the expiry alert (OD4); the member-domain job and its gate, once a real test domain exists |
| **P2** | Certificate-expiry list for staff |
