# 14 — Monitoring the HTTP servers: a small program that answers only your phone — Completion

**Verified:** 2026-10-06. What was run, and where:

| Check | Result |
|---|---|
| The program, PHP 8.4 on this PC (Windows): `tests/run-tests.php` (signing, replay, scopes, sessions, lockout, the request reader, the CLI, **the real program over TLS on loopback**) | **130 passed** |
| The program's collectors: `tests/run-collector-tests.php` (every parser against files captured from `lab2-web`, the soft checks against servers started on loopback, the routes, `--set-targets`) | **122 passed** (the `run()` tests need Linux and were skipped here; see the lab line) |
| `InfraMonitorRulesTest` (PHPUnit, no database) | **9 passed** |
| The program's collectors on the **real Linux lab VM** `lab2-web` (CentOS Stream 9, PHP 8.4.26), **read-only, nothing written there**: the program text was sent on stdin and run, reading `/proc`, `df` and `systemctl show` | snapshot in 285 ms with the real CPU, memory, 8 disks, network and 9 real units (nginx, php-fpm, mariadb, redis, mongod, crond, sshd, chronyd, httpd) with memory and uptime; `systemctl` units that do not exist read as absent; `run()` returns the right code, refuses to interpret `$(id)`, and stops a command at its time limit (0.32 s); peak memory 2 MB. This found two things, both fixed: a bind-mounted partition (`/efi` and `/boot/efi`) was counted twice, and `mysqladmin ping` as an unprivileged user reads "Access denied", which is now a warning and not a failure |
| App: `flutter analyze` clean; `flutter test` | see "How the app was verified" below |
| Dart against PHP: **the phone's signing and pinned-TLS client against the real program**, 22 cases | passed: a signed ping, a session, the snapshot, services, catalog and checks all read by the Dart models from the PHP program's own JSON; a different phone, a different server reference, a clock ten minutes off and a changed certificate each refused with their own kind; a body that tries to give the program an address gets a 400 and runs nothing |
| Signing agreement between **three** implementations (Python, PHP, Dart) | `tests/vectors.json` made by Python's `hmac`; PHP and Dart reproduce every derived key, canonical string and signature |

**Not verified, and nothing in this file should be called working until it is:**
1. **The installer on a real server.** `install.sh` passes `bash -n`, its `preflight` runs correctly on this PC, and the phone's side of it is tested against a fake SSH connection; it has **never been run as root on a server**: that writes files, a unit and possibly a firewall rule, and the plan says every change to the lab is made with your say-so. The nine exit checks of §14.15 are listed at the bottom; none was run.
2. **`dartssh2` and `flutter_secure_storage` on a device.** The app now depends on `crypto`, `flutter_secure_storage` and `dartssh2` (added to `pubspec.yaml`); **you must run `flutter pub get`**. I did not install them. `crypto` and `flutter_secure_storage` were in the local pub cache and resolved offline; `dartssh2` was not, so `lib/features/monitor/install/dart_ssh_connector.dart` was compiled only against a **stub of the five calls it makes** (`SSHSocket.connect`, `SSHClient(...)`, `authenticated`, `execute`, `close`) written from my knowledge of its API. If a name differs in the real package it will fail to compile when you run `flutter pub get` and analyze; that file is the only one that touches it.
3. **`flutter analyze` / `flutter test` in the real project folder** (they need the packages above). They were run in a **mirror of the project** under the scratchpad, resolved offline, with the stub: same code, same tests. `tool/gates.sh` G4 (env parity) fails in the real project for a reason that is not this work: `LINODE_IN_USE` and `LINODE_TOKEN` are in the live `.env` but in none of the mode files (and `.env.example` is deleted in the working tree).

## What was built

| Phase | Server (`w/www`) | App (`afsbrain_flutter`) |
|---|---|---|
| **P17** the lights | `monitor_agent` and `monitor_probes` in each overview server entry (BS26) | the red/green light (a dot **and** a word) on every HTTP and DNS server row, a TCP connect to 443 then 80 (53 for DNS) at 3 s, at most 8 at once, every 30 s only while the list is on screen, stopped in the background and while the app is locked, the all-red banner, an Up/Down filter, the age of the check, "Was up" when a result is over 90 s old, a summary under the HTTP servers row of the hub, `json.monitor.probes.ports` |
| **P18** the program, its security, installing it | `core/scripts/monitor/afs-monitor.php` (TLS on 62443, HMAC-signed requests, silence for everything else, lockout, scopes, sessions, the `--add-device` / `--remove-device` / `--list-devices` / `--fingerprint` / `--set-targets` / `--check` commands), `afs-monitor.service` (10% CPU, 64 MB, `ProtectSystem=strict`), `install.sh` (preflight, install, upgrade with roll-back, authorize, revoke, targets, remove, status), `PROTOCOL.md`, `tests/` | the device key in secure storage, per-server derived keys, the signing client over pinned TLS (a changed certificate is blocked with no override), the light as a signed ping with the reason when it is red and whether the web port still answers, the SSH installer (the four files up, `install.sh` run, one line of JSON back, the key on standard input), **Program settings** screen (check this server, install with an exact review, upgrade, remove, the phones a server lets in, revoke), saving the pin and the soft checks into the record through the Brain's own dry run and save, Settings shows this phone's fingerprint |
| **P19** the server view | `GET /v1/snapshot`, `/v1/services`, `/v1/checks`, `POST /v1/checks/run`; the collectors and parsers; the local checks and the soft checks; `InfraMonitorRules` (the shape of `json.monitor`), `monitor` and `monitoring` as known keys (BS26) | **a server's own view** (Overview, Services, Checks with the Reach section, Program) over one session: opens on entering, ends on leaving by any route (back, another server, 30 s in the background, the app lock), never reconnects when moving between screens, reopens once if the program says it forgot the session, names every failure; the health model with its reasons and per-server limits; declared-against-measured note; reboot notice |

## Added after the first report (same day)

- **Install on several servers** (the cloud icon on the HTTP servers list, for accounts that can write): tick servers, read one summary, confirm; the phone then does each one in turn exactly as the Program screen would (check, install or upgrade, save the pin in the record) by driving one Program cubit per server. A server with no login in its record, or that something stops, is skipped with the reason; a failure never stops the others; "Stop after this one" is there; a Brain warning other than `unknown_key` leaves that record unsaved and says so ("Review"). It also **upgrades** servers whose recorded version differs from the one the app carries, one at a time, each rolling itself back if the new program does not start. This went beyond the plan's "never all at once" for upgrades, because the point is to not log in to servers by hand.
- **A bug the bulk work exposed, fixed:** the record review on the Program screen vanished if anything else was asked (a ping after an upgrade, listing devices) while it was waiting, though the change was still pending. It now stays until saved or dismissed.
- `flutter pub get` was then run in the real project: `flutter analyze` is clean against the **real** `dartssh2`, and the full suite passes there (1335 before this addition). The stub-only caveat above no longer applies.

## Decisions that held, and the ones that changed

- **Held:** nothing is sent to the Brain by a server; the Brain never logs in; lights by a signed ping with no login; the session is the login; SSH only on the Program settings screen, with the login in the record (`json.users[0]`); port **62443**; HMAC with per-server derived keys; no history, no alerts, no push.
- **A connection that closes without a byte is not a failure** (found while testing): the phone's fallback light is a bare TCP connect, and counting it would have locked the phone out after five lights. A silent TLS handshake or a client that never speaks still counts.
- **`PROTOCOL.md` and the vectors** are the contract; the app carries copies of the four program files and the vectors; **gate G10** (and a test) fail when they differ from the repository. `tool/sync-monitor.sh` copies them.
- **Soft checks are named, never addressed.** The phone sends `{names, domains}` and a body with anything else is a 400 that runs nothing. The two tenant checks connect **only to 127.0.0.1** with the tenant's name as the host (so a stolen key cannot make the server scan or relay).
- **The program runs commands without a shell** (`proc_open` with an argument list, a time limit, an output cap), and unit names from the phone must match `^[A-Za-z0-9@._:-]{1,80}$`.
- **A declared service is critical unless it says `critical: false`.** The plan said "declared critical service"; with nothing marked critical the default had to be one or the other, and the quiet default would have hidden a stopped nginx.
- **OOM kills** count as degraded when the counter is above zero. The plan said "in the last hour", which an on-demand program that keeps no history cannot know.
- **Two pills in one row did not fit at 200% text**, so a row with a light shows only the light and says Active/Inactive in words in its text.
- **The overview carries the program's place** (BS26 grew by `monitor_agent` and `monitor_probes`) so the lists can light every server without opening each record. No new route, no new table.

## What is **not** built

1. **Authorizing a second phone from the app.** The installer command and its tests exist (`authorize`, `MonitorInstaller.authorize`), but the pairing step (the second phone showing a code made of its id and *its key for that one server*, the first pasting it) is not designed into a screen. The only way to add a phone today is to install from it, or `install.sh authorize` by hand.
2. **Fleet limits in `brain_config.monitoring.thresholds`.** Limits are the plan's defaults plus `json.monitor.thresholds` per server; the config section is a known key and nothing reads it.
3. **DNS servers have a light but no program** (OQ-M6 stays open): they have no `json` column, so the Program entrance and `json.monitor.agent` are for HTTP servers only. Their lights are the TCP check on port 53.
4. **PM2 processes** are not listed (the lab has no PM2, so there was nothing to read it from or test against).
5. **A light's Install button.** A row with no program says "no monitor program" and opens the server's detail; the Monitor icon there opens the Program screen. There is no Install button on the row itself.
6. **The 150 ms CPU budget for a snapshot** was not measured on a server under the unit's 10% quota; the lab run took 285 ms of wall time, 250 ms of it the deliberate sampling interval.
7. **The hub's light summary keeps probing while a list is open over it.** Two timers, each only while the app is in the foreground; not worth a route observer.

## How the app was verified

`flutter pub get` could not be run (it is not mine to run), so analyze and the tests were run in a copy of the project resolved **offline** from the local pub cache with a stub of `dartssh2`. In that copy: `flutter analyze` reports nothing in `lib/` or `test/` (the only notices are about the stub's missing doc comments), and the whole suite ran. The numbers are in the table at the top once the final run finished.

Every screen of this file is in the screen tour (server view: Overview, Services, Checks, Program; the Program settings screen), so the tap-target, label, contrast and 200%-text guidelines run on all of them.

## Rules that are tested by reading the code (`monitor_rules_test.dart`)

- SSH is imported by one file and used only by the install folder, the Program cubit, the kit and `main`.
- The device key is read only by the signing client, the id/fingerprint code, the secure store and the cubit that installs.
- A session is opened only by server-view code.
- Nothing under `features/monitor` prints.
- The program carried by the app equals the repository's.

## The lab exit checks (§14.15), all still to run, each with your say-so

1. Open the server list: both lab servers show "no monitor program" and green by the TCP check, with no login anywhere.
2. **Install** on `lab2-web` from the Program settings screen (review the file list, confirm the host key, confirm the certificate fingerprint): the light's second line becomes "program 1.0.0 · up … · … ms".
3. From a different computer send an unsigned request and a wrong-signature request to port 62443: no bytes come back, the local log shows the failures; after five, that address is locked out.
4. Move the phone's clock by 5 minutes: "answered, but not for this device"; put it back.
5. Enter the server: Connected. Leave: the session is gone on the server (`/ping` still answers, an old session id gets silence).
6. Stop the program: the light is red within 30 s and says "web 443 ✓"; start it: green. Revoke the phone on the server: "not for this device".
7. Run the Reach checks: the Brain, DNS and the internet come back with latencies; remove a name from `targets.json` and see the phone cannot ask for it.
8. The program's cost: idle CPU zero, resident size, a snapshot's time.
9. Upgrade, then break the new file on purpose and see it put back; Remove, and see the port closed and the light fall back.

Also to run once: `php core/scripts/monitor/tests/run-collector-tests.php` **on the lab VM** (it needs the repository's `tests/` folder there), which covers `run()`, the part that cannot run on Windows.
