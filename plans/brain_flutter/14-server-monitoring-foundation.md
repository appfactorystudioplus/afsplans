# 14 — Monitoring the HTTP servers: a small program that answers only your phone

## Completion document

**Built 2026-10-06 (P17 to P19), unverified on a server and a device:** see [`complete/14-server-monitoring-foundation.complete.md`](complete/14-server-monitoring-foundation.complete.md) for what was built, what was run, what was not (the installer on a real server, `dartssh2` on a device, the lab exit checks) and the decisions that changed on the way. The text below is the plan as written; where the two differ, the completion document is right.

> **Revised 2026-10-06, the third time, after review.** History of this design, so the reasons are not lost:
> 1. *First draft:* a program on every server **pushing** measurements to the Brain every 30 seconds, installed by the Brain over SSH. **Rejected:** continuous data into the Brain is traffic nobody asked for, and the Brain does not log into servers.
> 2. *Second draft:* no program at all; the phone read each server over SSH. **Replaced:** you asked for a **program on each server that answers on a port**, so the server list can show lights by asking the program (no login), and so the program can run **soft checks on other systems and report back to the device**.
> 3. *This draft:* **a small program on each server, answering only your phone, on demand.** Nothing is sent to the Brain. The phone logs in to a single server (opens a **session** with its program) only while you are inside that server's view, and logs out when you leave.

---

The request: from the app, **see the services running on each HTTP server and check their health; see live network status (data in and out); see RAM and CPU; work out what is using the most network, CPU and RAM — and control the servers.** The server list shows a **red or green light** per server; **inside a server** you get the detail. Three files:

| File | What it decides | Phases |
|------|-----------------|--------|
| **14** (this file) | The program on each server, **how it answers only your device**, installing it, the lights, the session (login/logout), what it collects, services, checks, soft checks, health, the first screens | **P17 to P19** |
| [15](15-server-live-and-attribution.md) | The **live** view; **what is using the most** CPU, RAM and network, and how accurate each answer is | **P20 to P22** |
| [16](16-server-control.md) | **Controlling** the servers: reload, restart, stop, signal, reboot | **P23, P24** |

Phases continue [13](13-brain-infrastructure.md)'s numbering. Decisions are **MD#**, open questions **OQ-M#**, shared across the three files. It builds on 13 (the HTTP servers as *records*).

## 14.1 What was checked, and what was not

**Checked on 2026-10-06, read-only** (the lab's `REMOTE-ACCESS.md` allows read-only SSH with the key in `lab2/ssh`; nothing was changed, restarted or installed; no password was used or printed). The output of the first inventory is kept in [`_evidence/14-lab2-web-inventory-2026-10-06.txt`](_evidence/14-lab2-web-inventory-2026-10-06.txt). *(An earlier line in this project's notes said the lab database could not be reached from this PC. That was an untried assumption and it was wrong: lab2 answers on HTTPS, SSH and MariaDB.)*

| Fact on `lab2-web` (10.0.0.244) and `lab2-brain` (10.0.0.243) | Why it matters |
|------|----------------|
| **CentOS Stream 9, kernel 5.14, systemd 252, cgroup v2**; web has 2 CPUs and 1.7 GB RAM | per-service CPU and memory are read exactly from `/sys/fs/cgroup`; `systemd-run`, `flock` and `openssl` are present, which the program uses |
| **PHP 8.4 is on both, with the `openssl`, `hash`, `sockets`, `pcntl`, `posix`, `json` and `mbstring` extensions and TLS 1.2/1.3 stream support; the `sodium` extension is NOT installed** | **the program can be written in PHP and serve TLS with no new software**; but a signature scheme that needs `sodium` (Ed25519) is **not available**, which is why §14.4 uses HMAC with per-server keys |
| **No `/proc/pressure` (PSI is absent)** | pressure figures are shown only where a kernel has them |
| **nginx 1.20.1, Apache on :808, php-fpm, MariaDB, Redis, MongoDB, crond, chronyd, sshd** are the running services | the default list of services to watch |
| **`ss`, `nft`, `iptables`, `chronyc`, `curl`, `journalctl`, `rndc`, `mysql`, `redis-cli` are present; `pm2`, `node`, `conntrack`, `vnstat` are not** | everything needed to read a server is already there |
| **nginx writes one global log in the default `main` format, with no site name** | web traffic cannot be attributed to a website from the log as it is ([15](15-server-live-and-attribution.md) §15.4) |
| **PHP-FPM has one shared pool (`www.conf`), not one per tenant** | PHP use cannot be attributed to a tenant by process on this machine |
| **Port 62443 is free on both; the kernel's temporary-port range is 32768 to 60999; `firewalld` is inactive; `nf_conntrack` is not loaded** | the proposed port is unused on the lab, and (with the tenants' port blocks, which run **2000 to 60000** in every server record's `json.ports.full_range`) it shows why the port has to be **above 61000**: a port inside a tenant block could be handed to a tenant, and one inside the temporary-port range could be taken by an outgoing connection at the moment the program starts. There is no host firewall on the lab to open a hole in |
| **Both lab server records carry `json.users[0].username` (`root`) and `json.users[0].password`.** Both lab servers **refused** root password login when first checked (`PermitRootLogin without-password`); **you then enabled it, and a read-only check on 2026-10-06 confirms `permitrootlogin yes` and `passwordauthentication yes` on both** | the install login you named is in the lab records **and now works the same way on the lab as it is expected to on production** (the provisioning scripts connect as root with a password; production itself was not checked). *(A first check of these records said they had no users: that was wrong: the JSON is stored HTML-entity-encoded, so a search for `"users"` found nothing.)* |
| **The lab has two server rows: the Brain host (`server_type = 'Brain'`, 10.0.0.243) and a Head (10.0.0.244)** | the Brain host **is** a row, monitored like any other |
| **The lab's four tenants point at two addresses that are not lab servers** (copied production data) | tenant attribution cannot be demonstrated on the lab without creating a tenant |

**Not checked, because it cannot be from here:** the **production** servers. They were not logged into. Everything this plan says about production (the distro, PHP version and extensions, whether a host firewall or a provider firewall blocks a new port, nginx layout, PM2, PHP pools) is **inferred** from the provisioning scripts and listed in §14.16.

**Nothing in the estate measures a server today.** The Brain's only per-server numbers are the declared sizes (`specs.cpu_size`, `ram_size`, `tenant_size`) and the tenant count; 13's load bar is tenants against quota, not utilisation. `brain_http_servers.active` is a flag a person sets.

## 14.2 Decisions

| # | Decision | Why |
|---|----------|-----|
| **MD1** | **Nothing is sent to the Brain by a server, and the Brain never logs into a server.** The phone talks to a server directly. | The request, as given |
| **MD2** | **A small program runs on each server and answers on one port (default **62443**, chosen above the tenants' port blocks and the kernel's temporary ports, §14.1).** It is a single PHP file run by systemd; PHP is already on every server (§14.1) and needs nothing installed. **It does nothing between visits**: no sampling, no outbound connections, no history, no alerts; it is blocked waiting for a connection and uses no CPU. | The request: a program that answers on a port, so the phone can ask it for a light, for data, and to run checks from the server's side. *Costs, stated plainly:* a resident process of roughly 15 to 30 MB, one open port per server (and, where a host or provider firewall exists, one rule), and an install and upgrade path. P18 measures the process against the budget in §14.3. If it does not fit, it is rewritten in Go behind the same protocol: the protocol, not the language, is what this file fixes |
| **MD3** | **It answers only your device.** Every request must carry a valid **signature made with a key only your phone can produce**; anything else gets **no answer at all** (the connection is closed without a byte). Traffic is **TLS** with a certificate the phone has pinned, and replays are impossible. Layers and reasons are §14.4. | The question asked: "is there a way to secure the program so that it only answers my device, like a token?" Yes, and the token is a key that is **never sent**, so a captured request cannot be used again |
| **MD4** | **The device key is made on the phone, lives only on the phone (in the Android keystore-backed secure storage), and is never shown or sent.** Each server is given **only a key derived for itself** (`K_server = HMAC(device_key, "afs-monitor:v1:" + server_reference)`), pushed over the SSH session at install time. | The same derivation storage-sharding uses so that one compromised server cannot impersonate your phone to any other ([storage-sharding 08](../storage-sharding/08-security.md)). Stealing a server's key reaches only that server |
| **MD5** | **The server list's light is the program's answer to a signed ping (scope `ping`), with no login of any kind.** A server without the program yet falls back to a plain TCP connect to its web port. Green / red with the word and the reason. | The request: lights in the list, by asking the program, without logging in |
| **MD6** | **Entering one server opens a session with its program; leaving ends it.** The session is the login: it needs the stronger `read` scope, lives in the program's memory only, expires after 2 idle minutes, and is closed by the phone on every way of leaving the server view (back, switching server, backgrounding 30 s, app lock). While open, one connection serves every screen of that server; moving between its screens does not log in or out. | The request, exactly: log in when entering the server, log out on leaving. No password is involved; the session is proved by the device's signature |
| **MD7** | **The program runs soft checks on other systems, by name, from an allowlist on the server, and reports back to the phone.** A **soft** check is a TCP connect, an HTTP `HEAD`, a DNS lookup or a TLS handshake with a short timeout: it never logs in, sends no payload and writes nothing. The phone can only name a check from the list the server holds; it cannot give the program an address. | The request. The allowlist is the difference between "run my checks" and "a scanner and relay for anyone holding the key" (§14.4 threat table) |
| **MD8** | **SSH from the phone is used only to install, upgrade, authorize or revoke a device on, and remove the program, and the login it uses is the server record's own: `json.users[0].username` and `json.users[0].password`** (the fields the provisioning scripts already use; a DNS server uses its own `username` and `password` columns). After install the phone does **not** use that password for monitoring or for control: reads and actions go through the program. The Brain still never logs in. | Keeps the root credential off the phone's everyday path, and uses the credential that is already in the record rather than adding a second place for it. Whether the phone may hold it for the install is OQ-M1 |
| **MD9** | **Every number says what it is and how it was measured** (`measured`, `sampled`, `estimated`), in the data and on screen. | "What is using the network" cannot be exact for every kind of traffic ([15](15-server-live-and-attribution.md) §15.4) |
| **MD10** | **Health is judged by the app from the program's snapshot and from settings in the server's own record** (`json.monitor`: services that should run, thresholds, soft-check names, the program's port and certificate pin), with fleet defaults in `brain_config.monitoring`; edited with 13's JSON editor so they are versioned and in history. | No second configuration store; free version, dry run and rollback. The only change the Brain needs (§14.13) |
| **MD11** | **The app uses `fl_chart`** (decided 2026-10-06: "whatever's easiest"); one `TimeSeriesChart` widget wraps it; every chart has a text summary and a table view. | Less code than drawing charts |
| **MD12** | **The app adds these packages: `dartssh2` (install/upgrade/authorize/remove only), `cryptography` (HMAC), `flutter_secure_storage` (the device key), `fl_chart`.** They go in `pubspec.yaml`; **you** run `flutter pub get`; none is installed to test the plan. | A pure-Dart SSH client and an audited crypto package are better than writing either |
| **MD13** | **There is no history and there are no alerts**, and the plan says so plainly: the program does nothing between visits, so there is nothing to chart from last night and nothing to alert from. The Live view keeps the last few minutes of the current session. §14.12 says what would change that. | The honest consequence of "on demand". Alerts were never requested; they appeared in the first draft only because a pushing agent made them possible |
| **MD14** | **The program is sandboxed, budgeted and self-limiting** (§14.3): `CPUQuota`, `MemoryMax`, a read-only filesystem except its own directory, input size and time limits, and a restart-after-N-requests to bound any leak. | It must not cause the trouble it exists to find |
| **MD15** | **Monitoring is part of the Infra destination**, not a sixth bottom-bar item: lights in the HTTP and DNS server lists and on the hub card; a **Monitor** entrance on an HTTP server's detail. | The bar is at its limit of five ([10](10-ui-and-navigation.md)) |

## 14.3 The program

**What it is.** One file, `core/scripts/monitor/afs-monitor.php` (in `w/www`, with its installer next to it), plus a systemd unit `afs-monitor.service`. The app carries a **bundled copy** of the same file as an asset (generated by a script, with a gate that fails the build if the two differ by a hash), so the phone can install it over SSH without the Brain serving anything.

**How it runs.** `Restart=always`, `CPUQuota=10%`, `MemoryMax=64M`, `ProtectSystem=strict` with write access only to `/etc/afs-monitor` and `/run/afs-monitor`, `PrivateTmp`, `ProtectKernelTunables` (read of `/proc` stays), no network access other than the listening socket and the soft checks. **It runs as root** because reading every process's `/proc/<pid>/io` and `smaps_rollup`, and (in [16](16-server-control.md)) restarting units, need it. That is a real exposure (§14.4 "What is still weak") and the reason the program's request parser is small, has hard limits and does no dynamic code loading.

**What it listens on.** `tls://0.0.0.0:62443` (port in `json.monitor.agent.port`), a certificate made at install and pinned by the phone (§14.5). A single-threaded loop: it accepts a connection, reads one small HTTP request (headers ≤ 8 KB, body ≤ 16 KB, **2 seconds** to deliver them or the connection is dropped), checks the signature **before parsing anything else**, answers, closes. No keep-alive beyond a request, no WebSocket, no static files, no directory listing: it has **no behaviour at all for an unsigned request**.

**Its endpoints** (all under `/v1`, JSON, all signed, §14.4):

| Call | Scope | Purpose |
|------|-------|---------|
| `GET /ping` | `ping` | alive: `{ok, version, uptime_s, load1, time}`: what a light needs and nothing more |
| `POST /session` / `DELETE /session` | `read` | open and close the session (the login and the logout, MD6) |
| `GET /snapshot` | `read` + session | the §14.8 snapshot |
| `GET /live?since=` | `read` + session | the live samples ([15](15-server-live-and-attribution.md)); starts a 1-second sampler **only while a session exists** |
| `GET /top`, `GET /network`, `GET /services`, `GET /process/{pid}`, `GET /logs?source=&lines=` | `read` + session | attribution and detail ([15](15-server-live-and-attribution.md), [16](16-server-control.md)) |
| `GET /checks`, `POST /checks/run` | `read` + session | local checks (§14.9) and the named soft checks (MD7) |
| `POST /actions`, `GET /actions/{id}` | `control` + session | [16](16-server-control.md) |

**Budget (measured in P18, enforced by the unit file):** idle: 0% CPU; ≤ 30 MB resident; answering a ping ≤ 5 ms; a snapshot ≤ 150 ms of CPU and ≤ 3 KB; a live session ≤ 5% of one core and ≈ 1 KB/s. The program reports its own resident size and request time in `/ping`, so a bloated one is visible in the list.

## 14.4 How it answers only your device

### The layers

| # | Layer | What it stops |
|---|-------|---------------|
| 1 | **Signed requests.** Every request carries `X-AFS-Device` (an id), `X-AFS-Time`, `X-AFS-Nonce`, `X-AFS-Sig`, where `Sig = HMAC-SHA256(K_server, "AFS1\n" + time + "\n" + nonce + "\n" + METHOD + "\n" + path-and-query + "\n" + hex(sha256(body)) + "\n" + session-id)`. The key is **never sent**. The program recomputes it and compares in constant time. | anyone who does not hold the device key: a scanner, a person on the same network, a stolen *network capture*. The signature covers the method, path, body and session, so a captured request cannot be altered or used for anything else |
| 2 | **Freshness and replay protection.** `Time` must be within ±120 s of the server's clock; each `Nonce` is accepted **once** (a bounded cache of the last 5 minutes). | replaying a captured request |
| 3 | **TLS with a pinned certificate.** The program serves TLS 1.2/1.3 with a self-signed certificate made at install; the phone stores its SHA-256 fingerprint in the server's record and **refuses any other certificate**. | eavesdropping (the data is in the clear without it: process names and command lines are sensitive); a rogue host pretending to be the server |
| 4 | **Silence on failure.** An unknown device id, a bad signature, a stale time, a repeated nonce, an oversized or malformed request: the program **closes the connection without sending a byte** (no error text, no banner, no hint which check failed). It writes one line to its local log. | learning anything by probing; the program looks like nothing is there |
| 5 | **Lockout.** 5 failures from one address in 60 seconds drops every connection from that address for 15 minutes; at most 8 open connections and 20 new ones a second overall. | guessing, flooding, and slow-connection (slowloris) attacks |
| 6 | **Scopes.** A device's entry on the server lists what it may do: `ping`, `read`, `control`. A key that is only allowed to ping cannot read; the `control` scope is separate. | a key leaking its full power |
| 7 | **The program's own gates for dangerous actions** ([16](16-server-control.md) MD20): `allow_dangerous = false` in `/etc/afs-monitor/agent.conf` unless a person set it on that server by hand. | a stolen device key being enough to stop or reboot a server |
| 8 | **An optional source-address allowlist** (`allow_from` in `agent.conf`, CIDR list): for the lab, the LAN. A phone on mobile data has no stable address, so this is off by default in production. | everything outside the allowed networks, before TLS is even answered |

### The device key and the servers

- The phone **generates a random 256-bit key on first use** and keeps it in the Android keystore-backed secure storage; it is never displayed, never sent, never logged, never in a backup. Its short **fingerprint** (a hash, like `7F3A-91C2`) is shown in Settings so a person can tell devices apart.
- **Per server**, the phone computes `K_server = HMAC-SHA256(device_key, "afs-monitor:v1:" + server_reference)` and pushes it to that server during install or authorize (§14.5). The server stores only its own `K_server`, in `/etc/afs-monitor/authorized_devices` (mode `0600`, root), on a line `device-id  label  scopes  K_server  added  [expires]`.
- A second phone is a second line with its own device key. **Revoking a device is deleting its line**, effective at once (the program rereads the file when it changes).
- **A lost phone:** log in to each server from a computer and delete the line (a one-line command is printed in the install result and kept in the plan's runbook); the keystore protection and the app lock (production builds) are the barrier until then.

### Why this scheme, among the options

| Option | Verdict |
|--------|---------|
| **A shared token sent on every request (bearer)** | simple, but the token travels each time: one leak, or one request captured when TLS is not pinned, and the attacker is you indefinitely. Rejected |
| **HMAC-signed requests, per-server derived keys** *(chosen)* | the secret is never sent; replay-proof; needs only PHP's always-present `hash` extension; the same pattern the Brain's own `BrainSurfaceSignature` and the storage servers use. A compromised server exposes only its own derived key, which is a key to a machine the attacker already has |
| **Asymmetric signatures (Ed25519 or ECDSA)** | the server would hold only a *public* key, so a server compromise exposes nothing. Ed25519 needs PHP's `sodium`, **which is not installed on the lab**; ECDSA P-256 works through `openssl` but needs signature-format conversion between the phone's library and PHP. Stronger in principle, more moving parts; a candidate upgrade of the same wire format if wanted (OQ-M2) |
| **Mutual TLS (a client certificate)** | the strongest: the TLS handshake itself fails for anyone without the certificate, so nothing answers at all. Heavier: a client certificate has to be generated, stored on the phone and trusted by every server, and Dart's client-certificate support on Android is fiddly. A candidate hardening on top of this design (OQ-M2) |

### The threats, and what is **still weak**

| Threat | Handling |
|--------|----------|
| Someone scans the internet and finds port 62443 | they get a TLS handshake and then silence for every request they can make; 5 failures lock their address out. They learn only that *something* speaks TLS there |
| Someone on the path reads or alters traffic | TLS pinned on the phone; signatures cover method, path and body |
| Someone replays a captured request | time window plus single-use nonce |
| The phone is stolen | the key is in the keystore, behind the screen lock; production builds also have the app lock; revoke by deleting the server-side lines |
| A server is compromised | the attacker gets that server's `K_server` only: no other server, and not the device key |
| The device key is stolen from a rooted phone | it can act as the phone against every server until revoked: **this is the residual risk of any token**, narrowed by the `ping`/`read`/`control` scopes and by `allow_dangerous` being off on each server |
| **Someone abuses the soft checks to scan or relay** | the phone names a check from an allowlist **on the server**; it cannot supply an address. Defaults are only the fleet's own addresses and a few well-known endpoints |
| **A flaw in the program's own request handling** | **this is the real exposure: the program runs as root and listens on the network.** Mitigations: nothing is parsed before the signature is checked; the parser is a few dozen lines with hard size and time limits; no dynamic includes, no `eval`, no shell with request data; the catalog of actions is fixed ([16](16-server-control.md)). A privilege-separated design (an unprivileged reader plus a small root helper for actions) is the next hardening if you want it (OQ-M3) |
| A provider/cloud firewall blocks the port | the install checks reachability from the phone and says so; opening the provider's firewall is outside this plan |

## 14.5 Installing, upgrading, authorizing, removing

All four are actions in the server view, done **from the phone over SSH** (MD8), previewed and reviewed with the same flow as [16](16-server-control.md), audited like any change. The Brain does not take part.

- **Install** (`install_monitor_program`, class B). Preflight shown first: PHP ≥ 8.1, the `openssl` extension, systemd, root, whether port 62443 is free, whether `firewalld` is running. The review shows **exactly what will be written**: the program file (with its SHA-256, which the phone checks against its bundled copy), `/etc/afs-monitor/{agent.conf, authorized_devices, targets.json, tls.crt, tls.key}` with modes, and `afs-monitor.service`; the certificate is made on the server (`openssl req -x509`, EC P-256, 10 years, CN = the server's hostname); this device is added to `authorized_devices` with its derived key; **if `firewalld` is running it opens the port** (`--add-port=62443/tcp`, warning `opens_port`); nothing else on the server is touched. It starts the unit and returns the certificate's SHA-256, and the phone saves `json.monitor.agent {port, tls_sha256, version}` in the server's record through 13's normal reviewed save (a visible diff, in history). **Install by hand** is possible too: the repository holds the same installer; run it from your own computer.
- **Authorize / revoke a device** (`authorize_device`, `revoke_device`). Adds or removes one line of `authorized_devices`; the only way trust on a server changes, and it needs SSH, so it needs a login that is not the device key.
- **Upgrade** (`upgrade_monitor_program`, B). The phone pushes the bundled file, the server checks `php -l`, keeps the previous copy, swaps atomically and restarts; if the new one does not answer a ping within 2 minutes the unit's restart restores the previous copy. The list shows each server's program version and "N behind"; **upgrading is one server at a time**, never "all".
- **Remove** (`remove_monitor_program`, C). Stops and disables the unit, removes the files, closes the port it opened. The record's `json.monitor.agent` is cleared by a reviewed save; the light falls back to the TCP check.

**Between installs the phone does not need the root password.** The install, upgrade, authorize, revoke and remove actions log in over SSH as **`json.users[0].username` with `json.users[0].password` from the server's record**, held in memory for that one SSH session and never stored (**you confirmed the phone may hold it**). **If a server refuses that login** (root password login turned off, a wrong password), the sheet says exactly why and offers the two ways forward: install by hand from your own computer with the repository's installer, or pick another login (a user with `sudo`, with a key kept in the phone's secure storage) in the Connect sheet. It never tries to change the server's SSH settings.

## 14.6 The lights

- **What a light is.** For a server with the program installed (`json.monitor.agent` present): a **signed `ping`** to its port, timeout 3 s. **Green + Up** = the program answered; the second line gives what it said ("program 1.0 · up 12 d · load 0.4 · 41 ms"). **Red + Down** = it did not, with the reason ("no answer in 3 s"; "refused"; "answered, but not for this device: check the device key"); and, because the program can be down while the server is fine, the second line always adds **whether the web port still answers** ("web 443 ✓"). For a server **without** the program: a TCP connect to its web port (443, else 80; DNS servers 53), and the second line says "no monitor program: web port only" with an **Install** entrance. A grey ring + **Checking…** before the first result.
- **A light is a dot and a word**, never a colour alone. **A green light means "answering", not "everything is healthy"**; the server view is for that.
- **When:** when the list or the hub opens, every 30 seconds while it is on screen, on pull-to-refresh. **Never in the background, never when the app is locked.** At most 8 at a time. Each probe is one small signed request and one small reply (well under 1 KB), only while you are looking.
- **A red light is "this phone could not reach it".** The list shows a banner when **every** server is red ("Every server is unreachable from this phone. Check this phone's network.") and each row says what was observed, never "server down". The age of the check is shown; a result older than 90 s reads as stale.

## 14.7 The session: logging in and out of one server

- **Entering a server's view** shows "Connecting…", opens a TLS connection to the program (checking the pinned certificate), sends `POST /session` signed with the `read` scope, receives a session id (random, in the program's memory only), then takes the first snapshot. **Leaving** (back, switching to another server, backgrounding the app for 30 seconds, app lock, the route disappearing for any reason) sends `DELETE /session`, **drops the session id from the phone's memory**, and the program discards it. If the phone vanishes, the session **expires after 2 idle minutes** and the program stops its sampler.
- **While inside:** one session, every screen of that server uses it; moving between Overview, Services, Checks, Live, Top consumers, Network, a process, Logs and actions **does not log in or out**. The header always shows the state: **Connecting… · Connected · Disconnected (Reconnect)**.
- **Only one server at a time** has a session on a phone; opening another server ends the first.
- **Leaving while an action is running** asks first; the action keeps running on the server (16).
- **Failure kinds are named:** *unreachable* (timeout), *refused* (nothing on the port), *not for this device* (the program closed the connection silently: the signature or the device is not accepted: wrong key, revoked, or the phone's clock is off by more than 2 minutes, which the app checks and says), *certificate changed* (the pinned fingerprint does not match: **blocked, no override**), *program error*. A "no program here" server shows the Install entrance, never an error.

## 14.8 What the program collects

It reads the kernel directly (`/proc`, `/sys/fs/cgroup`, `systemctl show`, `ss`), never parsing the output of `top` or `free`, whose formats change. Counters are returned as counters and rates are computed from two readings; a counter that goes backwards is a reset, never negative traffic; a `boot_id` change is a reboot.

| Group | What | Source |
|-------|------|--------|
| **Host** | hostname, uptime, kernel, `boot_id`, load, run-queue, the server's clock | `/proc/uptime`, `/proc/loadavg`, `/proc/sys/kernel/random/boot_id`, `date` |
| **CPU** | overall and per core: user / system / iowait / steal / irq / nice | `/proc/stat` (two readings) |
| **Memory** | total, **available**, used (= total − available), cache, buffers, swap, `oom_kill`; pressure figures **only if** `/proc/pressure` exists (not on the lab) | `/proc/meminfo`, `/proc/vmstat` |
| **Disk** | each real filesystem: size, used, available, inodes; per device throughput and utilisation | `df -P`, `/proc/diskstats` |
| **Network** | per interface: bytes, packets, errors, drops (the rate comes from the two readings); TCP states and retransmits | `/proc/net/dev`, `/proc/net/snmp`, `/proc/net/sockstat` |
| **Services** | each declared unit (and any failed unit): state, sub-state, restart count, since, main pid, memory, CPU | `systemctl show` (one call), `systemctl list-units --state=failed` |
| **PM2** | each PM2 process: name, status, restarts, memory, CPU | `pm2 jlist`, only if `pm2` exists (it does not on the lab) |
| **Top** | the top processes, grouped | [15](15-server-live-and-attribution.md) |

## 14.9 Health, services, checks and soft checks

### The health model

**Inside a server's view** the app derives one `health` from a snapshot and **always gives the reason**. (The list shows only the light of §14.6.)

| `health` | When | Example reason |
|----------|------|----------------|
| `critical` | a declared critical service is not `active`; disk ≥ 95%; memory `available` < 3% or an OOM kill in the last hour; `nginx_local_http` fails | `service_down:nginx`, `disk_full:/home`, `oom_kill` |
| `degraded` | CPU > 90%, `iowait` > 40% or `steal` > 15% (over the snapshot's two readings; sustained conditions are judged in the Live view); memory `available` < 10%; swap in use; a crash loop; a check at `warn`; a certificate under 14 days; the clock off by more than 120 s; pressure `full` > 5% *where pressure exists* | `cpu_high`, `steal_high`, `memory_low`, `crash_loop:php-fpm` |
| `unknown` | no session could be opened | `unreachable`, `not_for_this_device`, `certificate_changed` |
| `healthy` | none of the above | — |

Thresholds are defaults in `brain_config.monitoring.thresholds`, overridable in `json.monitor.thresholds`. The declared sizes are used only to **flag a mismatch** ("declared 8 GB, the kernel reports 1.7 GB"), never to compute health. **The app never changes `active`**: acting on health is a person's decision ([16](16-server-control.md)), and moving tenants off a server is 13's transfer.

### Services and local checks

The default list to watch, from the lab inventory and **to be confirmed on a production server** (OQ-M5): `nginx`, `httpd`, `php-fpm`, `mariadb`/`mysqld`, `redis`, `mongod`, `crond`, `sshd`, `chronyd`, `afs-monitor` itself, and on DNS servers `named`. Which units **should** run on a given server is **declared** in `json.monitor.services`: "mariadb is not running" is only a problem where it is meant to run. A running unit that is not declared is `undeclared` (information); a declared unit that is absent or inactive is the alarm; a rising restart count is its own finding even when the unit is `active` now. PM2 processes (where PM2 exists) are listed separately, named `<domain>-<type>` and joined to a tenant by domain.

Local checks (run by the program on demand; each returns `ok | warn | fail | unknown`, a latency and its **full output**): `nginx_config` (`nginx -t`), `nginx_local_http` (`curl` to 127.0.0.1 with a 3 s timeout), `php_fpm`, `mysql` (`mysqladmin ping` with the server record's database password, passed in the environment, never on a command line), `redis`, `named` (`rndc status`), `disk_space`, `clock_sync` (`chronyc tracking`), and **on demand and capped at 50 tenants a run**: `tls_expiry` (the certificates the tenants' records name) and `tenant_probe` (`HEAD https://<tenant domain>/` **from the server to its own address**).

### Soft checks on other systems (MD7)

The program can check, **from the server's side**, whether it can reach things other than itself, and report to the phone. This is the check nobody else can do: *can this server reach the Brain? the DNS servers? its database host? the internet?* The Brain cannot answer that, and neither can a phone on the lab Wi-Fi.

- **The list lives on the server** (`/etc/afs-monitor/targets.json`), written at install from `json.monitor.targets` and changed afterwards by the reviewed action `set_targets` (16). Each entry has a **name**, a kind and its parameters: `tcp host port`, `http url expect-status`, `dns name resolver`, `tls host port`. Sensible defaults the phone proposes at install from the Brain's own records: the **Brain's API** (`https`, `HEAD`), each **DNS server** (53), each **other fleet server's** web port, the **database and Redis hosts** the server uses, an outbound-internet reference (`1.1.1.1:443`), mail submission (`:587` on the configured relay).
- **The phone asks by name:** `POST /v1/checks/run {names: [...]}`. The program runs them (at most 8 at once, each ≤ 5 s), and returns, per name, `ok | slow | fail`, the latency and the exact reason (`connection refused`, `timeout`, `certificate expires in 6 days`, `status 502`).
- **What a soft check never does:** log in anywhere, send a body, follow redirects off the target, write anything, or accept an address from the phone. It cannot be pointed at something that is not on the list, so a stolen key cannot turn the server into a scanner or a relay.
- **Result on screen:** a **Reach** section in Checks: "Can reach the Brain ✓ 38 ms · dns1 ✓ · dns2 ✗ timeout · database ✓ · internet ✓", each row opens to the full reason.

## 14.10 Which servers

- **HTTP servers** (`brain_http_servers`): all, every `server_type` (Head, Standby, Backup, Clone, **Brain**). A standby meant to be idle must not be flagged for low load: its declared services are what matter.
- **The Brain host** is a row with `server_type = 'Brain'` (confirmed on the lab) and gets the program like any other; control actions on it carry an extra warning ([16](16-server-control.md) MD26).
- **DNS servers** (`brain_dns_servers`) run the same program with `named` declared; their monitoring settings (no `json` column) live in `brain_config.monitoring.dns_servers` (OQ-M6). Their SSH login (for install) is their own `username`/`password` columns (**confirmed on the lab, 2026-10-06: three rows, each with `username` `root` and a password set**). None of those three rows is the lab's own DNS VM (`lab2-dns`, 10.0.0.242): their addresses are public ones copied from production, so the lab has no DNS server row to install on, and **the lab's server lists will probe those real public addresses** (a connect to port 53 or 443, no credentials sent). Either a lab DNS row is added, or the lab database is cleaned to list only lab machines (OQ-M20).
- **Storage servers** already report to the Brain by their own design ([storage-sharding 07](../storage-sharding/07-health-polling-and-reporting.md)) and are not touched.

## 14.11 The app

Two places: the **server list** (lights, no login) and the **server view** (one session).

```
┌ HTTP servers   (checked 12 s ago) ───┐        ┌ node1 · Head        ● Connected      ┐
│ ● Up    node1 (Head)                 │        │ healthy                              │
│   program 1.0 · up 12 d · 41 ms      │  tap   │ CPU 12%  RAM 33%  ↓ 40 KB/s ↑ 12 KB/s│
│   web 443 ✓                          │  ───▶  │ [Overview][Services][Checks][Live]   │
│ ● Up    server1 (Brain)              │        │ …                                    │
│   program 1.0 · up 3 d · 38 ms       │        └──────────────────────────────────────┘
│ ○ Down  node3                        │         leaving this view ends the session
│   no answer in 3 s · web 443 ✗       │
│ ● Up    node4  no monitor program    │
│   web 443 only            [Install]  │
└──────────────────────────────────────┘
```

| Screen | Content |
|--------|---------|
| **Server list (HTTP and DNS servers)** | one row per server: the **light** (dot **and** word), the reason, the age of the check, name and type, tenants against capacity, the program's version (and "update available"), **Install** where there is no program; sort and filter by light; the all-red banner. **No session is opened here.** The same counts are the hub card |
| **Server view · Overview** | headline numbers (CPU, RAM, network, load, disk bars), uptime and reboot notice, kernel, **declared vs measured**, the health and its reason, **Check now**, **Live** ([15](15-server-live-and-attribution.md)). The connection state is always in the header |
| **Server view · Services** | units *running as declared* / *problem* / *undeclared*; PM2; a unit opens its full detail and ([16](16-server-control.md)) its actions |
| **Server view · Checks** | local checks with status, latency and full output; the **Reach** section (soft checks); **Check certificates** and **Check websites** |
| **Server view · Program** | the program's version, port, certificate pin, the devices authorized on it (this phone's fingerprint marked), **Upgrade**, **Authorize another device**, **Remove**; reaches SSH only here and says so |
| **Install sheet** | the preflight, **exactly what will be written** (§14.5), the SSH login to use (and where it came from), then the result with the certificate fingerprint to confirm |

Every figure carries its accuracy word where it is not `measured`. A server that cannot be logged in to shows the named reason and a **Retry**, never blank charts, and its **light stays as the last probe said**.

## 14.12 What this design cannot do, and what could be added

**It cannot show history** (what the CPU did last night) **or alert you when the app is closed**: the program does nothing between visits and nothing reaches the Brain. If history is ever wanted, the way that keeps to your rules is **a recorder inside the same program**: while idle it would append one small line a minute to a local file (48 hours, a few hundred KB) that the phone fetches **only when you open History**. It sends nothing anywhere, but it means the program is no longer idle between visits, so it is a separate decision (OQ-M7). **Alerts** need something that tells you when you are not looking, which is exactly what was ruled out; they are not offered.

## 14.13 The one server change (BS26)

| BS | What |
|----|------|
| **BS26** | add `monitor` to the known keys of an HTTP server's `json` (`InfraWarnings::SERVER_KNOWN`) and the `monitoring` section to the config's known keys (`InfraConfigRules`), so saving them raises no `unknown_key`; document their shape (`json.monitor`: `agent {port, tls_sha256, version}`, `probes`, `services`, `thresholds`, `targets`, `ssh {user, port}`). **No new table, no new route, no new traffic.** |

The only other server work in files 14 to 16 is the small audit endpoint in [16](16-server-control.md) (BS27).

## 14.14 Phases

| Phase | Name | Delivers | Needs |
|-------|------|----------|-------|
| **P17** | **The lights, first version** | the TCP web-port probe, the lights in the HTTP and DNS server lists and the hub card, the all-red banner, the 30-second refresh while on screen and its stop in the background, `json.monitor.probes`. **Useful on its own; needs no program, no credential and no new package** | none |
| **P18** | **The program, its security, and installing it** | the program (listener, TLS, signature check, silence, lockout, scopes, `ping`, sessions) and its sandbox; the phone's device key and the signing client; `install` / `upgrade` / `authorize` / `revoke` / `remove` over SSH, the pinned certificate, the Install sheet; the lights upgraded to signed pings; **cross-tested signing between the phone's library and the program with fixed test vectors**; the program measured against the budget on `lab2-web` and `lab2-brain` | P17; OQ-M1; the packages of MD12 added to `pubspec.yaml` and installed by you |
| **P19** | **The server view: session, snapshot, screens, health, soft checks** | the session (enter = open, leave = close, MD6), the snapshot and its parsers, Overview, Services, Checks with the **Reach** section, the Program screen, the health model and its `json.monitor` settings, `tls_expiry`, `tenant_probe`, BS26 | P18 |

## 14.15 Tests and acceptance

**The program (PHP, run against fixtures and, on the lab, for real):** an unsigned request, a bad signature, a wrong device id, a stale time, a repeated nonce, a body altered after signing, a path altered after signing, an oversized header, a slow request, and malformed HTTP **each produce no bytes at all** (the connection closes) and one local log line; 5 failures from an address lock it out for 15 minutes; a `ping`-scope key cannot call `/snapshot`; a `read`-scope key cannot call `/actions`; a session expires after 2 idle minutes and a request with an expired session gets silence; `DELETE /session` ends it at once and stops the sampler; **the process is idle (no CPU, no sampling) with no session** (measured); the parsers against `/proc` files captured from the lab; a soft check names only entries of `targets.json` and **rejects any address supplied by the caller** (a request carrying one is treated as malformed); the budget of §14.3 holds.

**Cross-compatibility (the part most likely to go wrong, so tested first):** the phone's HMAC and the program's HMAC agree on fixed vectors for a set of requests (GET with a query, POST with a body, empty body, unicode in a path); `K_server` derived on the phone equals the one the installer writes.

**The phone (a fake `MonitorClient` that replays captured responses; no real network in a unit test):** each failure kind shows its own message; **a changed certificate pin blocks with no override**; the device key and every `K_server` never appear in a log line, an exception, the cache or a `toString` (a test fails if they do); lights: a signed ping that succeeds is green with the program's own line, a silent close is red with "not for this device", a program that is down while the web port answers is red with "web 443 ✓"; a server with no program uses the TCP check and offers Install; no probe runs in the background or while locked (fake lifecycle); at most 8 at once; **all red shows the banner**; a stale check reads as stale; **entering a server opens exactly one session and leaving closes it on every way of leaving** (back, switching servers, backgrounding 30 s, app lock, route removal), with the fake recording `open` and `close`, **and the session id is dropped from memory**; moving between a server's screens does not reconnect; **no screen outside a server view can open a session** (a test searches for it); SSH is reachable only from the Install sheet and the Program screen (a test searches for it); no monitor screen sends a write (`/actions`) except through the review flow of 16; every screen is in the **screen tour**.

**Server (BS26):** saving `json.monitor` raises no `unknown_key`; it round-trips exactly; a malformed `monitor` is an error with its path.

**Exit checks (lab, on the phone, on the lab Wi-Fi; every change with your say-so):**
1. Open the server list: both lab servers show **no program** and **green** by the TCP check, with no login anywhere.
2. **Install** the program on `lab2-web` (review the file list first, confirm the certificate fingerprint); the light's second line now reads "program 1.0 · up … · … ms".
3. From a **different computer**, send an unsigned request and a request with a wrong signature to port 62443 and confirm **no bytes come back** and the local log shows the failures; after 5 failures, confirm that address is locked out.
4. Change the phone's clock by 5 minutes and see "not for this device: check this phone's clock" (and the program close the connection); put it back.
5. Enter the server: **Connected**; leave it, and confirm on the server that the session is gone (`/ping` still answers; `/snapshot` with the old session id gets silence).
6. Stop the program: the light goes **red** within 30 s and says "web 80 ✓"; start it: green. Revoke the device on the server and see "not for this device".
7. Run the **Reach** checks: the Brain, the DNS server and the internet come back with latencies; remove one name from `targets.json` and see the phone cannot ask for it.
8. The program's cost: idle CPU is zero; resident size under 30 MB; a snapshot under 150 ms.
9. Upgrade, then break the new file deliberately and see it restored; Remove, and see the port closed and the light fall back to the TCP check.

## 14.16 Open questions

| # | Question | Needed by |
|---|----------|-----------|
| **OQ-M1** | ~~Is the phone allowed to hold a server login for the install?~~ **Answered 2026-10-06: yes.** The install logs in as `json.users[0].username` with `json.users[0].password` from the server's record, in memory for the install session only, never stored. The lab now allows root password login (you enabled it; confirmed read-only on `lab2-web` and `lab2-brain`), so the lab installs exactly as production will. A server that refuses the login gets the two fallbacks of §14.5 | — |
| **OQ-M2** | **Signing scheme:** HMAC with per-server keys (proposed; works on every server, `sodium` or not). Stronger alternatives are asymmetric signatures (the server then holds no secret) or mutual TLS (nothing answers without the certificate). Start with HMAC and keep the wire format ready to move, or do the stronger one first? | P18 |
| **OQ-M3** | ~~Accept the program running as root?~~ **Answered 2026-10-06: yes**, with the hardening of §14.4. A privilege-separated design (an unprivileged reader and a small root helper for actions) stays listed as a possible later hardening, not planned | — |
| **OQ-M4** | ~~Which port?~~ **Answered 2026-10-06: a higher port, 62443** (above the tenants' blocks and the temporary ports). Still to confirm on production: that nothing already listens on it (the install checks with `ss` and refuses if it is taken) and that **no host or provider firewall blocks it** (the install opens the host firewall only when `firewalld` is running and cannot touch a provider's, such as Linode's). It can be changed per server in `json.monitor.agent.port`, and an `allow_from` address list can restrict who may connect | P18 |
| **OQ-M5** | **What do production servers run?** The lab is one data point; production was not logged into. PHP version and extensions, units, nginx layout and PHP pools are shown on the first install's preflight; the default service list is a setting, not code | P18 |
| **OQ-M6** | **Where do DNS servers' monitoring settings live?** They have no `json` column: proposed `brain_config.monitoring.dns_servers` rather than a migration on a legacy table the browser writes with raw SQL | P19 |
| **OQ-M7** | **History later?** If wanted: a recorder inside the program (§14.12). Not planned now | later |
| **OQ-M8** | **Light ports for servers without the program:** 443, else 80 for HTTP servers; 53 for DNS (overridable per server in `json.monitor.probes`). Right for yours? | P17 |
| **OQ-M9** | **Soft-check defaults** proposed at install (the Brain's API, each DNS server, each other fleet server's web port, the database and Redis hosts, `1.1.1.1:443`, mail submission): anything to add or remove? | P19 |
| **OQ-M20** | **The lab database lists production machines** (three DNS server rows with real-looking public addresses and root passwords, and an HTTP server row from a copy of production). The phone's lights and the install would reach those real addresses from the lab app. Clean the lab data to lab machines only, add a row for `lab2-dns`, or leave it as it is? | P17 |
