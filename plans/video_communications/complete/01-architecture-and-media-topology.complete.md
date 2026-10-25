# 01 — Architecture and media topology — completion

**Status:** First slice implemented and **deployed to the vagrant lab** (2026-09-19):
the `w/communications` service, its database schema, its lab deployment, and (see the addenda at the
end) a **LiveKit** server with verified media (addendum 2) and a two-server **Coturn** fleet with verified relayed
media (addendum 3). The website's guest join page (`w/members`) is the first client to call it; the host UI, desktop and Flutter do not yet.

Each plan doc also has its own report in this folder: [`02`](02-turn-infrastructure.complete.md),
[`03`](03-signaling-and-session-model.complete.md), [`04`](04-database-schema.complete.md),
[`05`](05-feature-tiers-and-entitlements.complete.md). The website half has its own: [`website/01`](../website/complete/01-web-client-and-guest-join.complete.md) (guest join) and [`website/02`](../website/complete/02-host-controls-and-ui.complete.md) (host controls and the Meetings screen) and [`website/03`](../website/complete/03-advanced-features.complete.md) (screen share, chat, hands, reactions).
This one holds the shared findings and the addenda.

Covers the service half of `01`, and the backend halves of `03` (signaling/session model),
`04` (schema) and `05` (entitlements). `02` (TURN fleet) is implemented only as credential issuance
and load-aware server selection; the Coturn servers themselves were deployed afterwards (addendum 3).

## Decisions taken (confirmed with the user)

- **LiveKit** is the SFU (`01` §1's recommendation, now settled). `w/communications` is Node.js
  (TypeScript, Fastify, `livekit-server-sdk`, `mysql2`).
- **`w/communications` is its own service in `w/`**, separate from `www`/`members`; the schema is
  migrated by **`www`** per `w/AGENTS.md` §5.
- First slice = service core + migrations. No git repo was initialised for `w/communications` (left to the user).

## What was built

| Area | Where |
|---|---|
| Service | `w/communications` — rooms, sessions, guest join-by-code, waiting room, rejoin, host controls, LiveKit token minting, TURN credentials, duration/participant caps, webhooks |
| Schema | `w/www/database/migrations/2026_09_18_000001_create_members_communications_tables.php` (5 `members_communications_*` tables) |
| Lab deployment | `C:\projects\vagrant`: `Vagrantfile` (new `kind: "node"` app), `provision/deploy-node-app.sh` (new), `dns/test1.zone` (`communications.appfactory`), `AFS-DEPLOYMENT-VAGRANT.md` |
| Deployed at | https://communications.appfactory.test1 — nginx → node on `127.0.0.1:3100`, systemd `afs-communications` |

## How it authenticates and limits hosts (this was the open integration question)

- **Identity:** the service POSTs the host's bearer token to `w/auth` `POST /checkpoint/auth/token/validate`.
  That one response carries `member.reference_num`, `membership` (plan 0-4), `level` and `account_status`,
  so **no second lookup is needed**. Local JWT verification was rejected: the JWT is HS256 with a shared
  secret, the JWKS endpoint exposes no key, and it would skip Checkpoint's "log out everywhere" check.
  Successes are cached 60 s, definite rejections 5 s; an outage is reported as `503 upstream_unavailable`,
  never as "invalid token"; secondary users are refused.
- **Limits:** `resources.communications` of `checkpoint_registry_v4.json`, resolved in the registry's own
  order (level 99 → account status → `feature_flag` → `pass` → `tiers[plan].enabled`) and **failing closed**
  if the resource or a tier field is missing. Limits are **snapshotted onto the session** at start.

## Deviations from the plan documents

| Plan said | Built | Why |
|---|---|---|
| `04`: participants had no status; `03` §3 refers to a `pending` participant row | `status` enum (`pending/admitted/denied/left/removed`) and a per-session-unique `identity` | The waiting room and reconnection need them |
| `04`: bigint ids | a public `uuid` on every table; bigint ids never leave the service | Unguessable references; a waiting guest's request id is the participant uuid |
| `04`: sessions snapshot two limits | plus `cloud_recording_enabled`, `live_transcription_enabled`, and **`cap_deadline_at`** | The cap deadline is persisted so a restart cannot let a capped meeting run on |
| `04`: rooms not unique per member | `UNIQUE(member_reference_num)` | Makes concurrent "get or create" race-safe; v1 has one personal room per member (relax when multiple rooms are wanted) |
| `03` §5: waiting-room events over a WebSocket | **REST polling** (`GET /v1/join-requests/:id`, `POST …/admit\|deny`) | Keeps slice 1 small; the WebSocket is a follow-up |
| `03` §6: reconnection = token refresh | a signed **rejoin token** issued on admission | A guest has no account to prove identity with; it maps back to the same participant without a second seat |
| `05` §5: duration cap "for sessions with 3+ participants" | the clock starts when the **third participant connects** and is set once | Leaving/rejoining must not restart it; 1:1 calls are never capped |
| `02`: deploy Coturn with a Redis heartbeat poller | `POST /internal/v1/turn-servers/:n/heartbeat` (X-Internal-Key) writes load | Whatever reports Coturn load can call it; no Redis needed by this service |
| `01` §1: one mediasoup/LiveKit choice | LiveKit, using LiveKit's webhooks as the source of truth for who is connected | As planned |

## Verification

- **Unit (136 tests, no DB/network):** join codes, entitlements **against the real registry file**, TURN
  credentials (checked against `openssl` HMAC vectors), rejoin tokens, config, the full meeting lifecycle,
  the HTTP layer, the Checkpoint adapter (fake `fetch`), and the **real LiveKit SDK** (decoded tokens: room
  binding, roomAdmin only for host/co-host; webhook signatures verified and forged/tampered ones rejected).
  Five deliberate breakages of the service (cap clock, seat cap, removed-can-rejoin, suspended-can-host,
  TURN picks the *most* loaded) were each caught by the suite.
- **Database (26 integration tests) on MySQL 8.4 and on the lab's MariaDB 10.5.29:** unique-key → error
  mapping, UTC round-trips, and **seat-cap races** — 20 simultaneous admits into 5 seats admit exactly 5;
  15 concurrent guests into a 4-seat meeting seat exactly 3 plus the host.
- **Migration:** passes `www`'s `MigrationOwnershipTest`; ran on strict MySQL 8.4 and the lab MariaDB; rollback and re-run are clean.
- **Deployed lab checks, over real HTTPS from the host:** `/healthz` 200; host routes 401 without a token;
  a **bogus token gets a clean 401 (not 503)**, proving the VM reaches Checkpoint; CORS preflight; unsigned
  webhook 401. The service's database user can only `SELECT/INSERT/UPDATE/DELETE` the five tables — DROP,
  ALTER, CREATE, TRUNCATE and reads of other tables are denied — and cannot connect from the lab network.
- **End-to-end with a seeded host session** (rows inserted directly, then deleted): a guest joined with only
  the code and received a room-bound token with no admin grant, two TURN servers ordered least-loaded first
  with a time-limited credential, the session limits, and a rejoin token that returned the same participant
  without a second seat; a forged rejoin token was refused.

## Not verified

- **A real member hosting a meeting.** No member credentials were available (and none were forged), so
  `POST /v1/rooms` and `POST /v1/rooms/:id/sessions` have never run with a real Checkpoint token, and
  creating a real LiveKit room has never run (there is no LiveKit server). Both are covered only by fakes.
- **A real LiveKit server**: room create/delete/remove-participant and real webhook delivery.
- Coturn, and a browser/desktop/mobile client connecting end to end.
- Behaviour after a VM reboot (the unit is `enabled`, but a reboot was not performed).

## Not built / follow-ups

- ~~Deploy LiveKit~~ and ~~deploy Coturn~~ — done in the lab, see addenda 1-3. Production deploy files for both are in `packages/afsserver/docker/builds/video_comminucation/livekit` and `.../coturn` (addendum 4).
- Signaling WebSocket, recording (Egress), co-host promotion, breakout rooms.
- Client integrations (desktop `01-webrtc-client.md`, website, mobile).
- `deploy-node-app.sh` pins the **nvm** Node path it finds on `web`; an nvm default change needs a re-provision.

## Findings outside this work

- `w/auth` `AllocationV2Controller::authorizeRequest` compares a system key against a **literal in source**
  (non-constant-time) and logs every request header, including the presented key. Raised as a separate task;
  the key should be treated as compromised and rotated.
- The lab README's "fixed by hand, not baked in" DNS issue on `web` was real (the VM could not resolve
  `auth.appfactory.test1`); `deploy-node-app.sh` now applies the fix idempotently.

---

## Addendum — LiveKit deployed in the lab (2026-09-19)

**LiveKit v1.13.7** (pinned) now runs on `web` as the systemd unit `afs-livekit` wrapping the official Docker
image in host-networking mode, behind nginx at `wss://livekit.appfactory.test1` (DNS record added). Media ports:
UDP 7882 (single-port mux) and TCP 7881; signaling 7880 behind nginx. Deployed by the new
`provision/deploy-livekit.sh`, registered as the `livekit` provisioner in the lab `Vagrantfile`.

- The API key/secret are **read from `w/communications/.env`** when the config is generated, so they cannot drift.
  `/etc/livekit/livekit.yaml` is root-only (0600).
- `room.auto_create: false` — **a security setting, not a tuning one.** LiveKit's default creates a room the first
  time anyone presents a token for it, which would let a stale token resurrect an ended meeting with **no
  participant cap**. Verified: a valid token for a room the service never created, and for an ended room, are refused.
- `turn.enabled: false` (Coturn is the plan); `node_ip` is the lab address (`192.168.56.11`), not the NAT adapter's.
- The kernel UDP receive buffer is raised (`net.core.rmem_max`, persisted in `/etc/sysctl.d/99-livekit.conf`),
  which cleared LiveKit's only startup warning.
- The webhook URL is the service on loopback; LiveKit signs webhooks with the same key/secret.

### Verified (`npm run e2e:lab`, against the running lab; only the member login is faked)

- The service really creates the LiveKit room with the Free-tier cap (40); three people connect over LiveKit's
  **real signaling WebSocket through nginx**; LiveKit lists exactly those three.
- Guest tokens carry no `roomAdmin`; host tokens do.
- **The deployed service receives LiveKit's real webhooks**: LiveKit deleting a room itself makes the service
  verify the signature, end the session and release every seat in the database. All five webhooks LiveKit sent
  during a run (`room_started`, three `participant_connection_aborted`, `room_finished`) got HTTP 200.
- A second meeting in the same room gets a fresh LiveKit room name; the host ending it removes the LiveKit room.

### Found while doing it

- LiveKit sends **no `participant_joined` until the WebRTC peer connection completes**; signal-only clients get
  `participant_connection_aborted` instead. The duration-cap clock and connected-participant bookkeeping hang off
  `participant_joined`, so they are covered by fakes and by the real SDK's webhook decoding, **not** by a real join.
- `web`'s Node is an nvm install; `deploy-node-app.sh` now resolves and pins its absolute path.

### Still not verified

- Coturn (not deployed), and a real member hosting through the HTTP API.
- Media from the website, the desktop app's own UI, or Flutter (only Chromium/Electron driven by a test script so far).
- Media across a real NAT/internet (the lab is one flat network), and the clean host-only path (see below).

---

## Addendum 2 — real media verified (2026-09-19)

`npm run e2e:media` (`scripts/lab-media.ts`, with `scripts/media-probe/`): three real WebRTC clients — the desktop
app's Electron (Chromium 120) with `livekit-client` 2.22.3, fake camera and microphone — join one meeting built by
the service (host + two guests by code) and stay for ~14 s.

**Verified**
- All 12 remote tracks (2 per participant × 3) arrive; for each, bytes received rise between two samples 3 s apart
  and video frames / audio samples are decoded. Audio only decodes once it is attached to an `<audio>` element, as
  a real client would.
- The path is UDP, direct (not TCP, not a TURN relay). Server side, `tcpdump` on the VM counted ~10,000 UDP packets on
  port 7882 during one run, so LiveKit's mux port is the socket carrying the media.
- **The real `participant_joined` path**: the deployed service marked all three connected, and the duration-cap clock
  started when the third connected (deadline ≈ 39.6 min out, cap 40). After everyone left, `participant_left` cleared
  the connected count and the deadline did **not** move (set once). This closes the gap noted in the first addendum.
- LiveKit lists three participants, each publishing two tracks; ending the meeting removes the room; the seed data is deleted.

**Found: LiveKit answered ICE from the wrong interfaces.** With only `node_ip` set, LiveKit also bound the VirtualBox
NAT adapter (`10.0.2.15:7882`) and `docker0`, and replied to ICE checks from those addresses. `deploy-livekit.sh` now
sets `rtc.interfaces.includes: [eth1]`; after redeploying, LiveKit binds only `192.168.56.11:7882` and every packet it
sends has that source address.

**Caveat — not the direct host-only path.** On this Windows PC Chromium picks its VMware VMnet8 address
(`192.168.20.1`) as the local candidate, and the reported remote candidate is peer-reflexive with a NAT-assigned
port (so the test does not assert "port 7882"). The capture shows the traffic going through the VM's NAT adapter
(`eth0`), not `eth1`. It works, but the clean `192.168.56.1 ↔ 192.168.56.11:7882` path was probed (some ICE checks
arrive on `eth1`) and not the one selected, so it is not separately proven. Cause: a multi-homed dev PC, not the service.

**Notes**
- `livekit-client` is a dev dependency (used only by the test script); it warns that its `machina` dependency wants
  Node ≥ 22.22 while this machine has 22.18.0. Harmless here.
- The Electron used is afsdesktop5's (`ELECTRON_PATH` overrides). The probe accepts a certificate error only for
  `*.appfactory.test1`; on this run Chromium reported none needing that, or the script would have printed a note.

---

## Addendum 3 — Coturn fleet deployed in the lab (2026-09-19)

Two Coturn servers, `s1` on `web` and `s2` on `web2` (`sN.conference.members.appfactory.test1`), built from the clone
at `C:\projects\coturn-master` (version 4.18.0; copied to `C:\projects\vagrant\coturn-src`) using the clone's own
`docker/coturn/debian/Dockerfile`, run in Docker with host networking under `afs-coturn`. Deployed by the new
`provision/deploy-coturn.sh` (Vagrant provisioner `coturn` on both boxes; `docker` provisioner added to `web2`).
DNS records and a wildcard mkcert cert for the fleet names were added; the lab firewall stays off.

**Verified**
- **`npm run e2e:turn`** (same Chromium/LiveKit test as `e2e:media`, every client forced to ICE policy "relay" with
  the ICE servers the service issued): the service picks two fleet servers with one shared credential; Coturn accepts
  it; all 12 tracks flow; every client's selected path is a **relay candidate** (via `turn:s2…:3478?transport=udp`);
  and Coturn's live allocations come back through the heartbeat into `members_communications_turn_servers`
  (`s1=6 s2=3` on the last run).
- Directly with `turnutils_uclient` from the image: a credential minted with the fleet secret gets a relay
  allocation on **both** servers (the fleet-wide-secret property); a wrong password and an **expired** credential are
  refused; relaying to loopback is refused (`403`); the TLS listener (5349) presents the mkcert cert and verifies.
- The `deploy-coturn.sh` flow end to end from an empty fleet table: row created inactive → first heartbeat reaches
  the *deployed service* → row switched active. Re-provisioning leaves a server that has reported in as it was.

**Deviations from plan 02**
- **Load reporting**: not Coturn's `redis-statsdb` + a poller. A systemd timer on each Coturn box reads Coturn's own
  Prometheus gauge (`turn_total_allocations`) and POSTs to the service's heartbeat endpoint (as noted in the table
  above). No Redis.
- **Relay hardening** beyond the plan: `denied-peer-ip` for loopback/link-local/private ranges (with the lab's
  `192.168.56.0/24` allowed because LiveKit lives there), `no-tcp-relay`, per-user and total quotas (the admin CLI is off by default in this Coturn version; an early `no-cli` line was removed because 4.18 logs it as deprecated).
  In production the SFU has a public address, so nothing needs allowing.
- **Time-zone trap when checking heartbeats by hand**: `members_communications_turn_servers` timestamps are `TIMESTAMP` columns;
  a shell/SQL check must compare with `NOW()`, not `UTC_TIMESTAMP()` (the session zone is EDT), or freshness looks
  four hours stale. The service itself was not affected.

**Not verified / not built**
- Behaviour after a VM reboot (units are enabled; not rebooted). Coturn behind a real NAT or across the internet
  (the lab is one flat network). TLS relay (`turns:`) as the *selected* path: the listener works, but Chromium always
  preferred UDP. (The production file was written afterwards, see addendum 4.)
- The clone is a snapshot copy; re-run the `robocopy` line and `COTURN_REBUILD=1 vagrant provision … --provision-with coturn`
  to pick up upstream changes.

---

## Addendum 4 — production deploy files for LiveKit and Coturn (2026-09-19)

`packages/afsserver/docker/builds/video_comminucation/livekit/` and `.../coturn/`: a `docker-compose.yaml` each (CentOS 9, host networking,
config injected with compose `configs: content:` so secrets live only in the per-server `.env`), with the firewall
port table and the exact deploy commands in the header. The lab does not use them (lab boxes are provisioned by
`provision/deploy-livekit.sh` / `deploy-coturn.sh`, and the lab firewall stays disabled).

**Coturn compose specifics:** builds the image on the server from a copy of the clone (`./src`) with the clone's own
Dockerfile; a second `heartbeat` service (pinned `curlimages/curl`) reports the live allocation count to
`/internal/v1/turn-servers/N/heartbeat` every 30 s and, when Coturn's metrics are unreachable, reports nothing (so a
dead Coturn goes stale instead of looking idle); TLS from Let's Encrypt with a certbot deploy hook that copies the
certificate and reloads Coturn with `SIGUSR2` (no restart); the header covers adding server 2, 3, ... (change two
`.env` lines), registering a server in the fleet table, and taking one out of rotation.

**Checked on the lab VM (a scratch instance of the production file on shifted ports, with the lab secret):** the
config renders with the real `.env` and fails fast when a secret is missing; the image builds from `./src`; a relay
allocation with a credential minted from the secret succeeds, a wrong password is refused, relaying to a private peer
is refused (403); the cert-reload signal is handled; the heartbeat reaches the *deployed service* (a marker value in the
table was replaced by the scratch instance's own count), a wrong `INTERNAL_API_KEY` shows as a visible `401`, and with
Coturn stopped it logs "not reporting" and leaves the row untouched. The copy-paste blocks in the headers were extracted
from the files and run as pasted (nginx config passes `nginx -t`; the certbot hook is a valid script starting with `#!`).
Found and fixed on the way: heredocs in a comment block cannot be pasted (the `EOF` ends up indented), so both headers
use single-command here-strings; the deprecated `no-cli` line.

**Not verified (needs a real server):** the public-IP behaviour (`detect-external-ip` returns an address in the
container; a real relay from the internet has not been made), the firewall commands and SELinux setting, certbot
issuance and renewal, the DNS names, the production database insert (placeholders for the db login), and the
production `w/communications` env, which does not exist yet and must be given the same `TURN_STATIC_AUTH_SECRET`,
`INTERNAL_API_KEY` and LiveKit key/secret.

---

## Addendum 5 — the lab reachable from other computers (2026-09-19)

Until now every test ran on the one Windows PC that hosts the lab: the VMs were on a host-only network
(`192.168.56.0/24`) nobody else could reach, which is why "two separate computers in one call" was never tested. `web` and
`web2` now also have a **bridged** network card (`eth2`) on the router's own network, so a real second computer can be a
client. Router: Cisco/Xfinity XB3 (DPC3941T) at `10.0.0.1`; the bridge is to the host's Wi-Fi adapter.

| Box | Host-only (`eth1`, unchanged) | Router network (`eth2`, new) |
|---|---|---|
| `web` | `192.168.56.11` | `10.0.0.240` |
| `web2` | `192.168.56.12` | `10.0.0.241` |

`dns` is not bridged: other computers use hosts-file entries instead of the lab DNS. Making the DNS box answer LAN clients
differently from lab clients would need BIND views and a second copy of the zone (and the tenant zone that
`deploy-tenant.sh` edits over SSH), which is much more to keep in step than three lines in a hosts file.

### Files changed (all in `C:\projects\vagrant`)

| File | What |
|---|---|
| `Vagrantfile` | `LAN_*` constants; `public_network` (bridged, static IP, promiscuous allow-all) on `web` and `web2`, declared after the host-only card so `eth1` stays host-only; a named `lan` provisioner on both; the LAN address handed to the Coturn provisioners. `AFS_LAN=0` leaves the card off |
| `provision/lan.sh` (new) | Checks `eth2` has its address and the router answers on it (a warning, not a failure); makes **SSH key-only on the LAN address** with an sshd `Match LocalAddress` drop-in |
| `provision/deploy-livekit.sh` | With `eth2` present: **no `node_ip`**, and `rtc.interfaces.includes` lists `eth1` and `eth2` |
| `provision/deploy-coturn.sh` | With `eth2` present: listens and relays on both addresses, no `external-ip`; a sixth argument adds LiveKit's LAN address to `allowed-peer-ip`. Also fixed a backtick inside the config heredoc that ran `cli` as a command (`line 117: cli: command not found` in every earlier run; harmless, it only blanked a word in a comment) |
| `AFS-DEPLOYMENT-VAGRANT.md` | New §8 "Testing from another computer on the LAN" |

Applied with `vagrant reload web web2`, then `vagrant provision web --provision-with lan,livekit,coturn` and
`vagrant provision web2 --provision-with lan,coturn`.

### Findings worth knowing

- **`node_ip` cannot be used with a second interface.** It names one address to advertise and replaces every interface's
  address with it, so other computers would have been offered `192.168.56.11`, unreachable from them. Without it LiveKit offers the
  address of every interface `rtc.interfaces` allows. (When the LAN card is absent the script still writes `node_ip`, exactly as before.)
- **Coturn refuses relaying to private peers**, so a relay path that ends at LiveKit's LAN address would have been
  refused (`denied-peer-ip` covers `10.0.0.0/8`). Only that one address is allowed, not the network.
- **Exposure of the VMs to the home network.** The lab has no firewall (deliberately), so `eth2` exposes everything the boxes
  listen on. Checked: Redis and MongoDB listen on loopback only; MariaDB listens on `0.0.0.0` but its wildcard accounts
  (`root@%`, `appfactorystudio@%`, `afs_idpdb_test_test_user@%`) all have credentials; **SSH accepted passwords**, now refused on
  the LAN address only. **`AFS-DEPLOYMENT-VAGRANT.md` §4 says root is granted only from `192.168.56.%`, but a `'root'@'%'`
  account also exists** (created by `deploy-app.sh` for an app's `CHECKPOINT_DB_*` connection, with its own password and
  privileges on one database); the guide was not changed for that.
- On reload Vagrant reported an SSH port collision for both boxes and chose `2200` (`web`) and `2201` (`web2`); the lab's own scripts
  reach the boxes by their host-only addresses, not those forwards, and `vagrant ssh` looks the port up itself.

### Verified (from the Windows PC, the only other device on the network today)

- `eth2` up on both boxes; each reaches the router (`10.0.0.1`) through the Wi-Fi bridge; the PC reaches each.
- Over `10.0.0.240` with a hosts entry and the lab CA: `/healthz`, the guest join page and LiveKit answer `200`; TCP 443,
  7881, 3478 and 5349 open on `.240`, 3478 and 5349 on `.241`; Coturn's TLS on 5349 verifies against the lab CA.
- **LiveKit offers both addresses and answers on both:** `tcpdump` on `web`'s `eth2` during `npm run e2e:media` shows the PC's
  Wi-Fi address `10.0.0.78` sending ICE checks to `192.168.56.11:7882` **and** `10.0.0.240:7882`, and LiveKit answering from both.
- TURN relay allocations succeed on the LAN addresses (`turnutils_uclient -y` from `web2` to `web`, relay address `10.0.0.240:…`).
- **`npm run e2e:media` and `npm run e2e:turn` pass unchanged.** In the relay run the selected path is *local relay
  `10.0.0.241` (via `turn:s2…:3478`) → remote host `10.0.0.240:7882`*: the media went through Coturn's LAN address to
  LiveKit's LAN address, so the `allowed-peer-ip` line is doing its job.
- `test-lab-health.sh`: all three boxes healthy (`firewalld` still masked, ports open). SSH to `10.0.0.240` with a password is refused (`publickey` only).

### Not verified

- **A second physical computer.** The PC's traffic to its own bridged VMs may not cross the router, so router forwarding, Wi-Fi client
  isolation and the macOS/Safari/Firefox behaviour are unproven. The hand-off for that test is `D:\AFS-LAB-MACOS-TEST-GUIDE.md`.
- The XB3's DHCP pool: if it covers `10.0.0.240`/`.241` it may hand them to another device. Nothing was changed on the router.
- Behaviour after the PC changes network or the Wi-Fi drops (VirtualBox may not start a VM bridged to an adapter without a link; `AFS_LAN=0`).
- A full `vagrant destroy && vagrant up` was not run; the new `lan` provisioner is placed before the apps, LiveKit and Coturn, so the order should hold.
