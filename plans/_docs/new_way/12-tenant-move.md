# 12 — Moving a website between servers

Moves a live site (its files, database, code, vhost, certificates and DNS) from one HTTP server to another with a short,
bounded freeze, a verified copy, a DNS cut-over, and a way back. Replaces `BrainHttpServerController::moveTenants` and the
`commands2.sh --transfer-tenants` chain, and finishes what the newer `InfraTransfer` started.

> Evidence for everything under "today" comes from the trace in the 2026-10-07 analysis of `moveTenants`, `InfraTransfer`,
> `www/core/scripts/commands2.sh`, `commands.expect` (`transfer_tenant_from_current`, `transfer_tenant_data`) and
> `remote/builder/app_install.sh` (`transfer_tenant_from_current`, `backup_database`, `complete_transfer_tenant`). Nothing was run.

## 12.1 What happens today, and what it gets wrong

```
 POST /http/tenants/move {target_server_ip, tenant}        (tenant row trusted from the request body)
   └ getTenantData: calls the Brain's own routes over HTTP with no credentials   → arrays likely empty
   └ payload JSON (all Brain secrets) written to /mnt/node1/appfactorystudio/tmp/main_*/vm_*.json
   └ popen nohup background_runner --transfer-tenants → commands2.sh → step1 zip builder, step2 expect:
        rsync job folder to BOTH servers (passwords via expect) · unzip on both
        ssh SOURCE: app_install.sh transfer_tenant_from_current → mysqldump|gzip → rsync /mnt/node1/<user> to target
        ssh TARGET: complete_transfer_tenant → userdel -r + useradd · git clone tenant's Gitea repo · composer + npm install/build
                    · create DB, restore dump · rewrite .env · copy to www-own · regenerate vhosts · reload
   └ response returns transfer_data and the whole getTenantData (including brain_config)
```

| # | Gap | Consequence |
|---|---|---|
| M-F1 | No step updates `brain_http_server_tenants` and none touches DNS in the traced path | After a "successful" move the member's records and the sites record still point at the old server |
| M-F2 | The source is never frozen, disabled or removed | The dump and the rsync run against a live site (writes lost); afterwards two live copies exist |
| M-F3 | Code comes from the tenant's Gitea repo, not from the source host | Anything on the source not pushed there is lost; each move rebuilds with `npm install` / `npm run build` |
| M-F4 | `userdel -r` on the target, then `useradd` | Destroys an existing home directory of that user on the target; not safe to re-run |
| M-F5 | Tenant row, secrets and the server's login are taken from the HTTP request / unauthenticated Brain calls | Spoofable and, per the `InfraTransfer` docblock, built from empty arrays |
| M-F6 | The response echoes the payload and the entire Brain config to the caller | Credential disclosure |
| M-F7 | The tracker is sized for 30 steps but nothing guarantees the transfer scripts make 30 `record_progress` calls (progress is additive, step `1` per call); the tracker key is generated twice so `redis_key` is stale | The bar may never reach 100% before the final status; nothing to resume |
| M-F8 | Three generations of transfer code (`--admin-transfer-tenants`, `transfer_tenants.sh --start-tenant-transfer`, `commands2.sh --transfer-tenants`) | Only the last matches the payload; the other two are dead weight |
| M-F9 | Creation and transfer use different DB passwords (a literal in `app_install.sh` versus the tenant JSON value); transfer works only because it rewrites `.env` | Fragile |
| M-F10 | `InfraTransfer` keeps its job record and locks in the **cache** | A cache flush loses the job and releases the locks mid-transfer |
| M-F11 | No integrity check between source and target | A truncated dump or partial rsync is reported as success |
| M-F12 | The tmp payload files under `packages/afsserver/tmp` are tracked in git and hold plaintext credentials | The new design must not write secrets to disk at all (§12.4 principle 4) |

`InfraTransfer` already got several things right and they are **kept**: a mandatory reason, an acknowledgement list for
warnings, a dry run, a client key for idempotency, an audit row written first, an enable switch, and the payload built from
the database instead of HTTP. What it still calls underneath is the old script chain, and it stores job state in the cache.

## 12.2 Principles

1. **Blue/green.** The target is fully built and verified while the source keeps serving. Only a short freeze separates the
   last copy from the cut-over.
2. **Identity does not change.** `site_ref`, system user, DB name/user, tenant `reference_num` and the claim are the same
   after the move. Only the server (and so the IP) changes.
3. **The cut-over is DNS plus one database transaction.** Everything before it can be undone by deleting the target; everything
   after it can be undone by moving back.
4. **No secrets in payloads.** The target agent generates the new DB password and writes it to `.env` (07 §7.2); the Brain never
   sees it. Server-to-server copying uses a **one-time key** the Brain creates per move, authorised on the source for a
   read-only, command-restricted `rsync` of that tenant's paths, and revoked at the end. No passwords through `expect`.
5. **Copy, verify, then switch.** A mismatch aborts before the cut-over.
6. **Keep the source until the move has proved itself** (the hold period, §12.8), as a forwarder, so stale DNS caches and
   members who keep their own DNS still reach the site.
7. **Never destroy what this move did not create.** The agent refuses to touch a user or database on the target that lacks
   this move's marker (fixes M-F4).
8. **A rollback is a move back.** One mechanism, tested once.

## 12.3 Who can start it, and the guards

- **P0: staff only,** through the existing route `POST /http/tenants/{ref}/transfer`
  `{target_server, reason, acknowledged[], dry_run, client_key}` (`StaffInfraJobController::transfer`). Same 422 / 423 / 428 /
  403 / 202 contract as today. The legacy `POST /http/tenants/move` is **removed** (410), not repaired.
- **Later:** drain a server (move all its tenants), and member-requested region moves (P2).
- **Preconditions (422 with reason codes):** the tenant and its claim are `active` (a `suspended` claim moves only with
  explicit acknowledgement); no other deployment or move is running for the tenant; the target passes the **hard filters of
  06 §6.2** (active, accepts tenants, not draining, role, agent version, reachable, free disk for the measured data size) and
  is not the source; capacity is **reserved** on the target in the same transaction (06 §6.4); the claim's zone exists
  (today's check, but by `claim_ref`, not by domain + member).
- **Warnings needing acknowledgement:** `transfer_inactive_tenant`, `target_not_head`, `target_at_capacity`,
  `transfer_moves_website` (kept) plus `external_dns_needs_member_action`, `large_database`, `mail_hosted_on_server`,
  `custom_certificate_copied`.
- **Audit row first**, then lock, then start; if the audit row cannot be written, nothing starts (kept).
- **Switch:** `domains.moves_enabled`, defaulting from the existing `BRAIN_INFRA_TRANSFER` value so the current setting keeps
  its meaning. A dry run always works.
- **Durable state.** The move is a row in `brain_website_deployments` with `kind = move` (03 §3.7) and rows in
  `brain_deploy_steps`; the cache keeps only a short index. Concurrency is a database rule (a unique key on the tenant for
  non-terminal operations), plus per-server **caps** (default 2 concurrent inbound, 2 outbound) instead of one one-hour
  exclusive lock per target.

## 12.4 The data a site is made of, and where each part goes

| Part | Where it lives on the source | How it moves | Notes |
|---|---|---|---|
| Code | `…/websites/releases/<sha>` + `current` | **Not copied** (`vendor/` and `node_modules/` never travel). The target fetches the **same template SHA** from the template repo and runs the usual `composer install` / `npm ci` / build (07 §7.3) | Same commit, same lockfiles; the source host is no longer the source of truth for code (fixes M-F3) |
| Config | `shared/.env` | **Regenerated** from the manifest template with the target's values and a new DB password | Never rsynced |
| User content | `shared/` uploads and `storage` | `rsync` (bulk while live, delta while frozen) | |
| Tenant storage | `/mnt/node1/<user>/…` (`modules`, `temp`, `tmp`, `server_configurations`) | `rsync` | Same two passes |
| Mailboxes | `/mnt/node1/<user>/mailbox` | `rsync`; **see D16** | Owned by the email feature |
| Database | `<db_name>` on the source | `mysqldump --single-transaction` piped to the target (initial), then again after the freeze (final) | Verified (§12.5 M10) |
| Vhost | Apache :808 + nginx | Rendered fresh on the target by `write-vhost` | Same hosts, same canonical host |
| Certificates | sites name: the wildcard already on every host. Custom domain: `/etc/letsencrypt/{live,archive,renewal}` for the domain | Custom-domain material copied over the restricted channel; renewal then runs on the target | Without this the custom domain would have no valid HTTPS until HTTP-01 could succeed against the new server |
| Cron / queue workers | per the release manifest | Registered on the target (disabled), enabled at cut-over; stopped on the source at the freeze | |
| DNS | sites record + (managed) `@ A` | Changed at the cut-over (04) | §12.7 |
| Registry | `brain_http_server_tenants`, `brain_dns_subdomains`, `members_websites` mirror | Updated in the cut-over transaction | |

## 12.5 The steps

Runs on the same runner as deploys (07 §7.8), same stored-step machinery, same resume rules. `S` = source, `T` = target.

| # | Step | Does | Compensation if it fails or is cancelled |
|---|---|---|---|
| M1 | `accept` | Guards (§12.3), reason, acknowledgements, audit row, tenant lock, reserve capacity on T, create the move | Release reservation and lock |
| M2 | `prepare` | Snapshot: `release_version`, hosts, canonical host, data size (files and DB), DNS mode, mail flag; compute the estimated freeze; create the one-time key pair | Delete the key pair |
| M3 | `agent_ping` | Both agents reachable and new enough | — |
| M4 | `lower_ttl` | Publish the sites record and (managed) `@ A` with TTL 60 and **wait one old TTL** before going on | Restore the previous TTL |
| M5 | `target_create_tenant` | T: system user, directories, DB and user with a **new** password, move marker | Remove everything carrying the marker |
| M6 | `target_install_release` | T: `fetch-source` at the tenant's SHA, `install-release` (composer + npm on T, whose preflight must be fresh); write `.env`; register (disabled) cron | Remove the release and `.env` |
| M7 | `authorise_copy` | S: authorise the one-time key, read-only and restricted to this tenant's paths | Revoke the key |
| M8 | `bulk_sync` | T pulls `rsync` of storage, uploads, mail (D16) and custom-certificate material from S while S serves. Resumable; progress by bytes | Delete the copied data on T |
| M9 | `db_initial` | `mysqldump --single-transaction` S → T; records duration and size | Drop the DB on T |
| M10 | `target_vhost_and_smoke` | T: vhost for all hosts (HTTP and, where a cert exists, HTTPS), `curl -H 'Host: …'` smoke over the copied data (read-only) | Remove the vhost |
| M11 | `freeze` | S: maintenance mode (HTTP 503 with `Retry-After`, "being moved" page), stop cron and workers. Start the freeze clock | **Unfreeze S** (`enable-site`), clear the clock |
| M12 | `final_sync` | Delta `rsync` of files; second DB dump into T; **verify:** file count and byte total per tree, `CHECKSUM TABLE` (small DBs) or table row counts plus a schema hash (large), custom-cert hash; any mismatch aborts | Unfreeze S, discard the delta |
| M13 | `target_enable` | T: enable the site, enable cron and workers, local health over every host | Disable T, unfreeze S |
| M14 | `dns_switch` | Sites record → T; (managed) `@ A` → T; publish through the DNS publisher and wait for every target (04 §4.4). **External DNS:** nothing the Brain can switch — see §12.7 | Point records back to S, unfreeze S |
| M15 | `public_check` | Resolve each name through `verify_resolvers`; health over the real names reaches T | (warning only until the hold period ends; a failure triggers rollback, §12.9) |
| M16 | `register` | **One transaction:** tenant `server_ref` / `public_ipv4` → T; `brain_dns_subdomains.ipv4`; `members_websites` mirror; reservation → real; `hold_until`; events; unfreeze clock stops | (committed; undo is a move back) |
| M17 | `source_forwarder` | S: replace the tenant's vhosts with a **forwarder** (proxy to T, preserving `Host`), keep data; revoke the copy key | — |
| — | *hold period* | S stays as the forwarder until `hold_until` (default 72 h, **D15**) | — |
| M18 | `finalize` | At `hold_until` or on staff "finalize now": archive S's DB and files to the backup volume (retention period), `remove-tenant` on S, remove S vhost/forwarder, delete S's certificate copy, release S capacity, event | — |

**Freeze budget.** M11–M14 are the only customer-visible downtime. The dry run reports the estimate from the measured delta
size and DB dump time; a configurable ceiling (default 10 min) aborts and unfreezes if M12 overruns it.

## 12.6 Failure, resume, cancel

- Steps are idempotent and recorded; a dead worker is resumed from the first step not `done` (07 §7.7). M8 and M12 are
  `rsync`, so a resume continues rather than restarts.
- **Before M16** every failure leaves S as the authoritative, serving (or immediately unfrozen) site and T disposable. Cancel
  runs compensations in reverse and releases the reservation.
- **M16 is the commit point.** A failure inside the transaction rolls back to "S is authoritative"; the DNS change made by M14
  is reverted by the compensation before the transaction is retried or the move abandoned.
- **After M16** the move is complete; problems are handled by rollback (§12.9) or ordinary support.
- A crash between M14 and M16 is the dangerous window: DNS already points at T but the registry still says S. The runner
  therefore resumes **only forward** from M14 (re-check, then M15/M16), never backward without a decision, and the reconcile
  job (04 §4.7) reports "DNS points at a server the registry does not list for this tenant" for the staff screen.

## 12.7 DNS, TTLs and members who keep their own DNS

- **Managed zones:** `@ A` is a system record (04 §4.2) and the `www CNAME` points at the sites name, so the cut-over changes
  **two records**: the sites record and `@ A`. M4 lowers their TTLs first and waits, so caches expire in about a minute.
- **External DNS:** the member's apex A points at S and the Brain cannot change it. The forwarder on S keeps that traffic
  working (it proxies to T), the sites name moves normally, and the UI shows the member **the new IP to set**
  (`external_dns_needs_member_action`, acknowledged by staff before the move). The forwarder for such a tenant is not removed
  at `hold_until` while the member's record still resolves to S; a periodic public-DNS check lets M18 run when it does not,
  or when a maximum (default 90 days, **D17**) is reached with notice.
- **Sites name:** always ours; no member action.
- **TTL restore:** after M18, TTLs return to their normal values with a final publish.

## 12.8 Hold period and the forwarder

For `hold_until` the source keeps the data and serves the forwarder only. This covers: resolvers that ignore TTL, members on
external DNS, a post-move defect that needs the original data, and rollback (§12.9). During the hold, no writes happen on
the source (it proxies), so the target is the only copy that changes.

## 12.9 Rollback

`POST /http/tenants/{ref}/transfer` with `target_server = <the old source>` and `reason = rollback of <ref>` — a normal move
in the other direction, using the same steps, valid while S still exists (before M18). Because the hold period leaves S's
data in place and T has accepted writes since the cut-over, the reverse move does a full fresh bulk + final sync from T. This
is the single rollback mechanism (principle 8). After M18 only the archive exists, and restoring from it is a staff
procedure, not a button.

## 12.10 Other things bound to a server

- **Mail (D16).** The tenant's mailbox directory and Dovecot configuration (`write_dovecot_files`) are per host. If mail is
  served from the HTTP server, the move must carry the mailbox in both syncs, run `setup-mail` on T (07 §7.2), and switch the
  mail host's A record (and anything pointing at it) at M14. The plan does not decide whether tenant mail stays server-bound;
  until D16 is answered, `mail_hosted_on_server` is a warning that needs acknowledgement and the dry run lists what would move.
- **Per-tenant ports** from the old `ports_blocks` are not part of the current vhost design and are not moved.
- **The `www-own` copy** disappears: one release, one vhost (07 §7.5).
- **Tenant JSON in the DB:** `Address`, `Address2`, `CertPrivateKeyLocation…` keep their values; only `server` changes.

## 12.11 API, dry run, and what the response may contain

- `POST /http/tenants/{ref}/transfer` and `GET /jobs/{key}` keep their routes and codes (`BS_E_*`); the job body now comes
  from the database.
- **Dry run** returns: the step list, the source and target, the measured data and DB size, the estimated freeze, the
  warnings (needing acknowledgement), what DNS will change, and which names the member must act on. **It contains no
  credentials**, because none are in the payload any more — which removes the "secrets included" caveat on today's dry run
  (and M-F6).
- Responses and logs never include the one-time key, the `.env`, or any password.
- Staff screens show the step list, bytes copied, freeze clock, hold countdown, **Finalize now** and **Roll back**.
- The member sees a "your site is being moved, back shortly" page during the freeze (M11–M14) and nothing else; an email after
  M16 is optional (**D15**).

## 12.12 Code this step retires (after the cut-over sign-off in step 10)

`moveTenants` and the Brain-side `getTenantData` copy; `InfraJobRunner::startTransfer`; `background_runner.php
--transfer-tenants` / `transferTenants`; `commands2.sh --transfer-tenants`, `step1`/`step2`; `commands.sh
--admin-transfer-tenants`; `commands.expect` `transfer_tenant_from_current`, `transfer_tenant_data`, `remote_transfers`;
`app_install.sh` `transfer_tenant_from_current`, `backup_database`, `complete_transfer_tenant`, `restart_http_after_tenant_transfer`;
`transfer_tenants.sh` and `transfer_tenant_script.sh`; the generation of `main_*/vm_*.json` payload files. The tracked
`packages/afsserver/tmp/*.json` files with plaintext credentials are removed from version control and the credentials rotated
(**outside this plan's scope to do, inside it to schedule**: step 10 §10.6 item 5).

## 12.13 lab2

A move needs two tenant hosts. `web` (10.0.0.244) is one. The second is, in this order of cost:

1. **P0:** the `brain` VM as a second host (`accepts_tenants = 1` for the test, with the agent installed) — no new VM.
2. **P1:** a `web2` VM at `10.0.0.245` (outside the DHCP pool that ends at `.239`, inside the guard's `10.0.0.0/24`), added to
   the `Vagrantfile` and `computers.*.json` like `web` (**D18**).

The one-time key replaces the missing server-to-server trust (`Setup-BrainTrust.ps1` only establishes Brain → others). The
guard (09 §9.1) applies to both servers and to the copy channel.

## 12.14 Tests

| Case | Expect |
|---|---|
| Happy path, site with uploads and a DB | Both names serve from T; S shows the forwarder; registry, DNS and mirror agree; checksums match |
| Writes during bulk sync | Present on T after M12 |
| Freeze overrun (inject slow dump) | Aborts at the ceiling, S unfrozen, T removed |
| Kill the worker at every step | Resumes; before M14 backward-safe, after M14 forward only |
| Cancel at every step before M16 | S serving, T gone, reservation freed, key revoked |
| Mismatch injected in M12 (flip a byte, drop a row) | Aborts before the cut-over |
| Target has a user of the same name without the marker | Refuses; nothing deleted |
| Two moves of one tenant at once | Second is 423 |
| Two moves into a server at its cap | Second waits or 423 per policy |
| External-DNS tenant | Forwarder persists; instructions shown; finalize deferred |
| Rollback within the hold period | Site back on S with T's writes; registry and DNS agree |
| Dry run | No credentials in the body; estimate present; no side effects (no lock, no reservation, no audit row) |
| Legacy `POST /http/tenants/move` | 410 |
| Guard | A server outside `10.0.0.0/24` is refused before any connection, including the copy channel |

## 12.15 Phases

| Phase | Work |
|---|---|
| **P0** | M1–M16 and M18 for the happy path with abort/compensation before the cut-over; durable state; one-time copy key; DB `--single-transaction` copy with file/row-count verification; DNS switch through the publisher; forwarder and hold; staff route, dry run without secrets; legacy route 410; `brain` as the lab's second host |
| **P1** | Rollback as reverse move; stronger verification (`CHECKSUM TABLE`, schema hash); custom-certificate copy; mail (D16); TTL pre-lowering for all modes; external-DNS handling; per-server caps; drain-a-server; `web2` VM; staff screens |
| **P2** | Member-requested region moves; live replication for very large databases; automatic rebalance proposals; estimated-downtime learning from past moves |
