# 07-decisions-and-open-questions — completion record

**Plan file:** [`../07-decisions-and-open-questions.md`](../07-decisions-and-open-questions.md)
**Date:** 2026-10-22
**Status:** 🟡 Not closed — most decisions are yours. This record states, for each OD and each owner question, what has been done under the plan's recommendation, what evidence the work found, and what is still waiting for an answer. **No decision below is recorded as made unless the plan or you made it.**

## Decisions (OD1–OD7)

| # | Plan recommendation | What was done / found | Waiting for |
|---|---|---|---|
| **OD1** signed routes | A, with fallback | **Built** (plan 11) and completed in step 02. Live on dev: the members app got HTTP 200 and the build data over the signed call (2026-10-22). | Nothing. Fallback removal is step 02's P1 and waits for production's Brain. |
| **OD2** value fixes | V1, V2, V4 and failed status now; V3 after the template check; V5 in P1 | **All built** (plan 11/12, step 03): V1, V2, V4, failed status, V5 (Brain facts from `subdomain_properties`); V3 is the job's value (`owner`), because nothing in the template reads that row (checked by plan 12). | Your confirmation of V3 and V5, which change values the tenant may read. |
| **OD3** `SUBDOMAIN_PROPERTIES_SUBDOMAIN` | `www` on the lab, `SUBPATH` matching | Superseded by plan 11 for dev: the setting stays `www15`, the script folder comes from `base_path()` and the environment from `VITE_SUBDOMAIN`. Step 02 makes an empty setting fall back to `VITE_SUBDOMAIN`. One effect to decide: the member's address on dev is `www15.<their domain>` (the vhost, `APP_URL`) while the stored `Address` row says `www.<their domain>`. | Which label a member's own address uses. |
| **OD4** wildcard issue/renew | A (Brain runs the ACME client with a DNS-01 hook) | **Decided by the owner 2026-10-22: A.** Built and tested (step 05, "OD4 option A"); the scripts passed 30 of 30 real checks on dev, and nothing has been requested from Let's Encrypt yet. | The settings entered on dev, then a first staging run; and the production path for the wildcard files (question 3). |
| **OD5** setuid wrapper | Decide after the first run | Dev, read-only 2026-10-22: `core/scripts/bin/afs-website` is `apache:apache 755`, not setuid, and the tenant build compiles its own setuid copy (`g++`, now checked). The owner said they are fixing the members copy. | The first dev run, and whether anything in `commands.sh` needs root locally. |
| **OD6** `npm ci` vs `npm install` | `npm ci` once lock files travel | The copy script now carries `composer.lock` and `package-lock.json` (step 04); `app_install.sh` still runs `npm install`. | Your decision; then a one-line change. |
| **OD7** Gitea password | Keep the `expect` push for now | Kept. Its login now comes through the environment, the token and repository address are no longer logged, and the push result is checked (step 03). | Revisit after the first dev run. |

## Questions only the owner can answer

1. **Which Brain release does production run?** Not answerable from here (production is not reachable). Dev's Brain already has the signed routes (route list 2026-10-22).
2. **How is a member's own-domain certificate obtained in production today?** The code does not obtain one: the call is commented out (and now carries the gate, step 05). If something else does it, it is outside these scripts.
3. **Where does the production wildcard live on a tenant host, and who issues it?** Open. Settings `controller.letsencrypt_cert_management.tenant_cert` / `tenant_cert_key` take the answer; dev has no `/etc/nginx/certs`.
4. **Does production's members tree run the setuid wrapper, and from where?** Open; dev: not setuid today.
5. **How will Checkpoint learn a tenant's custom domain under `enforce`?** Open; a dependency of sign-in on member domains, not of this plan.

## New questions found by the audit and the work (2026-10-22)

1. **Free domain purchase.** `POST /api/v1/domains/purchase` (members `routes/web.php:440`) buys on the registrar account without checking a payment, for any logged-in member. The new flow verifies the payment through the Brain. Should the old route be closed (refuse unless the member is on the old flow *and* a paid invoice is presented), or removed for everyone?
2. **Domain ownership in the old flow.** Anyone can add any name as `own = 1` and build a site for it. Is that acceptable until claims are used, or should the old build refuse names with no accepted claim?
3. **DNS and HTTPS for a member's own domain.** The old flow builds the site but nothing publishes the member's zone to the name servers or points `www15.<domain>` at the tenant host (`zones/save` writes the Brain's database only; `reload*` are stubs; `domains:*` are unscheduled), and the site has no HTTPS listener. Is the intended path the new way's DNS engine for these zones, or the legacy `reload_dns_zone` of the Brain portal?
4. **Mail on tenant hosts.** `app_install.sh` gives every tenant's `no-reply` mailbox the same password (in the repository) and overwrites the shared Dovecot `auth-sql.conf.ext` and `sni.conf` with the newest tenant's (the `sni.conf` also names another customer's host). Fix now, or is mail out of scope for the old flow?
5. **nginx CORS on the tenant's `/portal/api`.** The vhost template reflects any `Origin` with credentials, which contradicts the externals CORS contract. Remove it (the Laravel side decides) or keep it for the Parse apps?
6. **A GitHub token in source.** A personal access token is written in a comment in `w/www/.../brain_controller_schema.ts`; it should be revoked and the comment deleted.
7. **Cleaning up a failed build.** Which of the failed build's leftovers (Linux user, database, Git repository, `/mnt` directories, DNS line, the Brain tenant row that stays `active = 1`) should a removal script delete, and should it run automatically or on a staff action?
8. **Dev run.** The dev run itself — add a domain, *Create website*, watch to `complete` — changes real DNS and issues a real certificate, and has not been done.
