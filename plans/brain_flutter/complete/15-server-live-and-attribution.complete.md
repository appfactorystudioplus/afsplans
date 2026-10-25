# 15 — The live view, and what is using the CPU, memory, network and storage — Completion

**Verified:** 2026-10-06, on the authoring PC (Windows) and, read-only, on the two lab VMs. **Nothing was run as root on a server, and nothing has run on a phone.**

| Check | Result |
|---|---|
| The program, PHP 8.4 on this PC: `tests/run-tests.php` (signing, sessions, scopes, the real program over TLS) | **130 passed** |
| `tests/run-collector-tests.php` (parsers against files captured from `lab2-web`, the routes, **the live sampler**: ring and reply limits, 10 s self-stop, stop on `DELETE /v1/session`, stop when a session vanishes, two sessions, counters, default route, pressure) | **168 passed** (the `run()` tests need Linux and were skipped here) |
| `tests/run-attribution-tests.php` (new: a fake `/proc`, `ss` output, the process scan and its differences, services from cgroup counters, PSS against RSS, grouping by user, per-socket network deltas and their blind spots, one process in full, the live sample's top lists, the routes) | **93 passed** |
| App: `flutter analyze` | clean |
| App: the whole suite, including the screen tour (48 dp tap targets, labels, contrast, text at 200%) for every new screen | **1557 passed** (1428 before P21 and P22) |
| The phone's client against the **real PHP program over TLS on loopback**: a live session (asking starts the sampler, samples are read by the Dart models, closing the session stops it and the next session is a new run), `/v1/top`, `/v1/network`, `/v1/process` (including the refusals) | passed |
| The program's new scanners on the **real Linux lab VMs**, **read-only, nothing written there** (the program text sent on stdin to `php` as the unprivileged `vagrant` user over the lab's read-only SSH key) | see below |

**The lab runs (read-only, unprivileged):**

| | `lab2-web` | `lab2-brain` |
|---|---|---|
| Processes read, time for one scan | 176, **41 ms** | 198, **45 ms** |
| `/v1/top` as a whole (two scans and the 0.5 s between them) | 569 to 589 ms | 593 ms |
| `/v1/network` as a whole (two `ss` readings 0.6 s apart) | 659 to 716 ms | 666 ms |
| The program's own peak memory | 4 MB | 4 MB |
| Services found from the cgroups | 22 (`mongod`, `httpd`, `redis`, `rsyslog`, `mariadb`, …) | includes `pm2-root`, `containerd` |
| A service against its processes | `mongod.service` 4.5% of a core against its process's 3.7%: the service figure is larger because it counts what exited | |
| `ss -tinpH` | the real output (a socket line, then an indented info line with `bytes_sent:` and `bytes_received:`) is exactly what `parseSs` reads; 1 socket on the web VM, 22 on the Brain VM | |

**Not verified, and nothing here should be called working until it is:**
1. **The program as installed (root) on a server.** As an unprivileged user the lab runs could not read `/proc/PID/io`, other users' `smaps_rollup` (so PSS is only read for the user's own processes and the PSS-against-RSS check of §15.7.3 is not demonstrated: the sums differ only by that user's processes), or `ss -p` owners (every socket was "unattributed"). The installed program runs as root and reads all three; that is the untested part. The program is **version 1.2.0**: each server must be upgraded from its Program screen (or the cloud icon on the HTTP servers list) before the Live and Top consumers screens work on it. An older program says so on the screen.
2. **The screens on a phone**, their battery and data cost, and the sampler's CPU cost on a server under the unit's 10% quota. A sample costs one scan of the process table (about 45 ms on the lab VMs) plus a socket table every fifth second; the budget in `afs-monitor.service` (10% of a core) allows it, and it runs only while a screen is asking.
3. **Exit checks 1 to 7 of §15.7 on the lab.** They need the program installed on a lab VM, and 2, 4 and 5 need load (a busy loop, repeated `curl`, a long upload). Each changes the lab, so each waits for your say-so.

## What was built

| Phase | Server (`afs-monitor.php`, version 1.2.0) | App (`afsbrain_flutter/lib/features/monitor/`) |
|---|---|---|
| **P20** the live view | `LiveSampler` and `GET /v1/live`: one reading a second into a ring of 300, started by the first ask, stopped 10 s after the last ask or when the asking session ends, then everything is forgotten; the serving loop wakes only when a sample is due | the **Live** screen: network in and out (large, peak with "ago", since-you-opened total from counters, five-minute chart), CPU per core with the user/system/iowait/steal split, RAM as total minus available, TCP, pressure where the kernel has it, pause, bytes/bits and every-interface switches (remembered), staleness at 4 and 10 s, the sustained-condition notes, the 15-minute limit with Keep going, stop on backgrounding, Reconnect, "upgrade the program" |
| **P21** CPU and RAM attribution | `ProcScanner`: the process table (at most 3000), CPU by process (matched by pid and start time), services from cgroup `cpu.stat` and `memory.current`, PSS for the largest 25, storage rates, users; `GET /v1/top`, `GET /v1/process?pid=`; the live sample's `top_cpu` and `top_mem` | the **Top consumers** screen (CPU, Memory, Disk I/O) by process, service, tenant or user, with each row's share, accuracy word and trend, "Now" and "This session"; the phone-side **tenant join** and the shared-PHP finding; the **process** screen; the **explanation card** |
| **P22** network attribution | `NetScanner`: `ss -tinpH` twice, per-socket byte deltas by process, service, user, remote address and local port; `GET /v1/network`; the live sample's `top_net` (every fifth sample) and `oom_kill` | the Network tab (rows, remote addresses, local ports, "web traffic by site: not collected on this server", the program's caveat), the network rules of the explanation card, "Right now: what is using it" on the Live screen |

New and changed files: `w/www/core/scripts/monitor/afs-monitor.php`, `PROTOCOL.md`, `tests/run-attribution-tests.php`; in the app `data/{live_models,live_rules,top_models,top_grouping,explain_rules}.dart`, `cubit/{live_cubit,top_cubit}.dart`, `ui/{live_page,top_page,process_page,explain_card,monitor_notice}.dart`, `server_view_page.dart` and `server_view_cubit.dart` (the entrances, `read()` for polling screens, `tabRequest`), `home_shell.dart` (the wiring, the route name that lets "Open in Services" come back to a server's view), `core/format.dart` (`formatBitRate`), `assets/monitor/*` (the program's copies, synced), and the tests and fakes.

## Decisions that held, and how the open questions were settled

- **Held:** nothing is stored anywhere; the sampler runs only while a screen asks, inside the server view's session, and stops by itself; the Live and Top screens never open or end a session; nothing goes to the Brain; no actions here (signalling a process is plan 16).
- **OQ-M10 (per-site web traffic, N2): not built here; plan 16 built it as `enable_site_log` (see [its completion document](16-server-control.complete.md)).** It needs your yes, because it changes a server's nginx configuration. Until then the Network tab says, in words and in the explanation card, "not collected on this server" and what would turn it on; in the tenant view the two columns are shown as "other (sampled)" and "web (nginx): not collected", never added.
- **OQ-M11 (PHP pools):** the app detects it. PHP workers that run as a user that is no tenant's system user are a **shared pool**: the screen says "PHP is shared by every site on this server, so its use cannot be split by tenant" and credits no tenant; workers that run as a tenant's own user are that tenant's. The lab's pool is shared (`www.conf`); production is read the first time its processes are seen.
- **OQ-M12 (limits):** as proposed: 15 minutes a run with Keep going, one server's session at a time, stop at once on backgrounding, a screen reader hears one sentence at most every 5 s.
- **OQ-M13 (command lines):** shown in full on the process screen only, with a note, never logged, cached, or put in a report.
- **OQ-M14 (country for remote peers):** none.

## Where the build differs from the plan

- **`tcp.syn_recv`** is not carried (it needs a scan of `/proc/net/tcp` every second); the sample carries established connections, TIME_WAIT and retransmit counters from files already read.
- **"The public interface"** is the interface of the **default route**; the every-interface switch covers the lab's bridged card. Remembered.
- **The phone is not kept awake** while Live shows: that needs a wake-lock package, which this project does not have and I do not install. One dependency and a few lines; your decision.
- **Over 3000 processes** the program reads the **lowest 3000 pids** and says `partial`, not a rotating subset: a rotating subset would make two readings incomparable.
- **The Live screen and the Top consumers screen are pushed pages** from a server's view, not tabs, so leaving one is a clean "stop asking" event; the Network *screen* of §15.5 is the Network tab of Top consumers.
- **A process's page** shows its CPU for the readings taken while Top consumers was open (a sparkline), not for the Live session.
- **"Open it in 13"** for a tenant opens the tenant's detail in the Brain's infrastructure screens; "open a service" returns to the server's view on its Services tab.
- **The explanation limits** are `json.monitor.explain` on a server's record (alongside `json.monitor.thresholds`), not `brain_config.monitoring.explain`: nothing reads the fleet-wide section yet (the same open item as 14's fleet thresholds).
- **`/v1/process` for a process that has exited is a 200 with `ok:false, error:"gone"`**, not a 404, because the phone reads a 404 as "this program does not know the route: upgrade it".
- **Version 1.1.0 was never released:** P20 and P21/P22 were built the same day, so the program is 1.2.0 and the 1.1.0 live-only shape never reached a server.

## What is **not** built

1. Per-site web traffic from the web server's log (N2), until you decide (OQ-M10).
2. Keeping the screen awake on the Live screen.
3. A per-service storage figure from the cgroup (`io.stat`); per service the app sums the largest processes and says "at least".
4. UDP, connection-tracking accounting (N4), firewall counters (N5) and eBPF (rejected in the plan).
5. Fleet-wide explanation limits.
6. Any history: there is no "what happened last night" by design (14 §14.12).

## Rules that are tested by reading the code (`monitor_rules_test.dart`, which still passes)

- SSH is imported by one file; the device key is read only by the signing client and the install code; a session is opened only by server-view code (the Live, Top and process cubits ask the view to `enter()`, never the client); nothing under `features/monitor` prints; the program carried by the app equals the repository's (gate G10, `tool/sync-monitor.sh`).

## Left behind

Nothing on the lab VMs (read-only). On this PC the attribution test removes its own temporary folder (an earlier version of the test left four `afs-monitor-attr-*` folders, which were removed); the older `afs-monitor-collect-*` folders from before today's work are not mine and are still there.
