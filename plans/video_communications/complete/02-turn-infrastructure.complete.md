# 02 — TURN infrastructure — completion

**Status:** Done in the vagrant lab (2026-09-19): a two-server Coturn fleet, built from the clone, with credential
issuance, load-aware selection and load reporting working end to end and **relayed media verified**. Production
deploy files are written and checked on the lab VM but have never run on a production server.

## Summary

Two independent Coturn servers run in the lab — `s1` on `web`, `s2` on `web2` (`sN.conference.members.appfactory.test1`;
production names are `.studio`). Each runs the image **built from `C:\projects\coturn-master`** (Coturn 4.18.0, the
clone's own `docker/coturn/debian/Dockerfile`) in Docker with host networking, under a systemd unit `afs-coturn`.
All servers share **one** TURN REST API secret (`TURN_STATIC_AUTH_SECRET` in `w/communications/.env`), so a client's
credential works on either — the property `02` §4 is built around. `w/communications` mints the credential and picks
two servers (least `active_sessions / capacity_weight`, fresh heartbeat only); a systemd timer on each Coturn box
reports Coturn's live allocation count back every 30 s. Region-aware selection stays deferred, as the plan says.

## Files changed

| Where | What |
|---|---|
| `C:\projects\vagrant\provision\deploy-coturn.sh` (new) | Builds the image if missing, writes the config (secret read from `w/communications/.env`), the `afs-coturn` unit, the heartbeat timer, registers the server in `members_communications_turn_servers` **inactive**, and switches it active once its first heartbeat has reached the deployed service (plan §6) |
| `C:\projects\vagrant\Vagrantfile` | `coturn` provisioner on `web` (server 1) and `web2` (server 2); `docker` provisioner added to `web2` |
| `C:\projects\vagrant\dns\test1.zone` | `s1.` / `s2.conference.members.appfactory` records (serial `2026091903`) |
| `C:\projects\vagrant\certs\conference.members.appfactory.test1{,-key}.pem` (new) | mkcert wildcard cert for the fleet names (TLS listener, 5349) |
| `C:\projects\vagrant\coturn-src\` (new) | Copy of the Coturn clone (4.4 MB) so the VMs can build it |
| `w/communications/scripts/lab-media.ts`, `scripts/media-probe/*`, `package.json` | `npm run e2e:turn` (`--relay`): the media check with every client forced through TURN using the ICE servers the service issued |
| `packages/afsserver/docker/builds/video_comminucation/coturn/docker-compose.yaml` + `.env` (new) | **Production** deploy file, per server; CentOS 9 steps and the firewall port table in its header |
| `C:\projects\vagrant\AFS-DEPLOYMENT-VAGRANT.md` | Coturn section |

## What is configured (plan §4) and what was added

`use-auth-secret`, one fleet-wide `static-auth-secret`, `realm`, `lt-cred-mech`, `fingerprint`, TLS on 5349, relay
range 49152-65535, Prometheus on loopback. Not in the plan, added on purpose:
- `denied-peer-ip` for loopback/link-local/private ranges (a TURN server relays to any address a credential holder
  names), with the lab's `192.168.56.0/24` allowed because LiveKit is there. Production LiveKit has a public address.
- `no-tcp-relay`, `no-multicast-peers`, per-user and total quotas. The admin CLI is off by default in 4.18 (`no-cli`
  is now logged as deprecated, so it is not in the config).

## Deviations from the plan

| Plan said | Built | Why |
|---|---|---|
| Load via `redis-statsdb` + a poller | A timer reads Coturn's Prometheus gauge `turn_total_allocations` and POSTs to `w/communications`' `/internal/v1/turn-servers/N/heartbeat` | No Redis to run; the endpoint already existed. A production heartbeat is a second compose service that reports nothing (not 0) when Coturn is unreachable |
| §6: insert row `active=false`, verify heartbeat, flip `active=true` | Automated in `deploy-coturn.sh`; a server that has reported in keeps its flag on re-provision | Same procedure, done by the script |
| §1 "built from the clone" | As planned; production builds on the server from a copy of the clone | The image is exactly what was tested |

## Verification

- **`npm run e2e:turn`** (in `w/communications`, lab up): three Chromium clients (fake camera/mic) join through LiveKit
  with ICE policy `relay` and the servers the service issued. Checks: the service hands out two fleet servers with one
  shared credential; all 12 tracks flow; every selected path is a **relay candidate** (`turn:s1|s2…:3478?transport=udp`);
  Coturn's live allocations arrive in `members_communications_turn_servers` through the heartbeat (e.g. `s1=6 s2=3`).
  Passed on repeated runs, including after a redeploy.
- **Directly, with `turnutils_uclient` from the image** (plan Phase 0's "confirm credentials work manually"): a credential
  minted from the fleet secret gets a relay allocation on **both** servers; a wrong password and an expired credential are
  refused; relaying to loopback is refused (`403`); the 5349 listener presents the mkcert cert and verifies.
- **`deploy-coturn.sh` from an empty fleet table:** row created inactive → heartbeat reaches the *deployed service* → row
  switched active, on both servers.
- **Production compose file, on a scratch instance in the lab (shifted ports, lab secret):** renders with the real `.env`
  and fails fast when a secret is missing; builds from `./src`; allocation OK / wrong password refused / private peer
  refused; `SIGUSR2` reloads the certificate; the heartbeat replaces a marker value in the table, shows a wrong key as a
  visible `401`, and with Coturn stopped logs "not reporting" and leaves the row untouched.

## Not verified / follow-up

- Everything that needs a real server: a relay from the internet, the firewalld/SELinux commands, certbot issuance and
  renewal, the DNS names, the production database insert, and the production `w/communications` env (it does not exist
  yet; it must carry the same `TURN_STATIC_AUTH_SECRET`, `INTERNAL_API_KEY`).
- Behaviour after a VM reboot (units are enabled; not rebooted). Coturn behind a real NAT. TLS relay (`turns:`) as the
  *selected* path (the listener works; Chromium always preferred UDP).
- Time-zone trap when checking heartbeats by hand: the columns are `TIMESTAMP` and the DB session zone is EDT, so compare
  with `NOW()`, not `UTC_TIMESTAMP()`. The service is not affected.
- The clone is a snapshot copy; refresh with the `robocopy` line at the top of `deploy-coturn.sh`, then re-provision with
  `COTURN_REBUILD=1`.
- `s3`+ only when load justifies it (plan §6); production steps for adding a server are in the compose header.

See also `01-architecture-and-media-topology.complete.md`, addenda 3 and 4.
