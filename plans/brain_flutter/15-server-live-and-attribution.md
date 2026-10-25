# 15 — Monitoring the HTTP servers: the live view, and what is using the resources

## Completion document

**Built (P20 to P22) on 2026-10-06, tested on the authoring PC and read-only on the lab VMs, not yet run as root on a server or on a phone:** see [`complete/15-server-live-and-attribution.complete.md`](complete/15-server-live-and-attribution.complete.md), which records what was built, what was and was not verified, and where the build differs from this plan (it supersedes this file for questions about current behaviour). The program is **version 1.2.0**; each server must be upgraded from its Program screen before the Live and Top consumers screens work on it.

> **Revised 2026-10-06** with [14](14-server-monitoring-foundation.md): the data comes **straight from the small program on the server to the phone, inside the server's session (14 MD6), only while the screen is open, and nothing goes to the Brain.** The first draft's wake checks, Redis ring buffer and fleet-wide stored rankings are gone; they existed only to serve a program pushing to the Brain. The second draft read the server over SSH; the program replaced that.

---

[14](14-server-monitoring-foundation.md) gives one reading of a server. This file answers the two requests that need more:

1. **"See live network status — how much data is being transferred in and out, and the RAM and CPU."** → the **live view** (§15.1, §15.2).
2. **"Try and determine what is using the most network, CPU and RAM."** → **attribution** (§15.3 to §15.6): which process, service, tenant and remote address, and how sure the app is.

**Live numbers for the whole server are exact. Attribution is exact for some questions and an estimate for others**, and the screens say which (MD7). §15.4 is a table of exactly what can and cannot be known about network use on a Linux server, because "what is using the network" is the hardest of the three questions and the one most likely to be answered confidently and wrongly. §15.3 says plainly what the **lab's own setup** makes impossible.

Decisions continue **MD#**, questions **OQ-M#** from 14. Phases **P20 to P22**. **No server work at all.**

## 15.1 The live view

### How it works

```
phone ── is already inside this server's view, with its session (14 MD6)
phone ── GET /v1/live?since=<seq>   (signed, every second, with the session id)
program ── starts a 1-second sampler the first time it is asked, keeps the last 300 samples in memory
program ── answers with the samples since <seq>
phone ── parses, draws, keeps the last five minutes; stops asking when you leave the screen
program ── no request for 10 s, or the session ends  → the sampler stops
```

- **One small request a second, answered from memory.** A reply is the new samples (about 1 KB). The phone computes rates from the counters in consecutive samples.
- **It cleans up after itself.** The program's sampler runs **only while a session is open and the phone keeps asking**: 10 seconds without a request, or the session ending (`DELETE /session`, an idle expiry, the program restarting), stops it. Nothing is written to disk. With no session the program is idle again (14 MD2).
- **Nothing is stored anywhere.** The last five minutes (300 samples) live in the app's memory for the open screen; leaving the screen discards them. Nothing reaches the Brain. (There is therefore no "what happened last night"; 14 §14.12.)
- **It is bounded.** It runs **inside the server view's session**: it needs no separate login, and **leaving the server view ends the session and the sampler** (14 MD6). Leaving only the Live screen (to Overview, say) stops the requests and so the sampler, but keeps the session. At most one server's session per phone at a time, 15 minutes of Live at a stretch (the screen offers "Keep going"), ending on backgrounding after 30 seconds and on the app lock. It holds the phone awake only while the Live screen is showing.
- **Start-up** is the first `GET /live` on the open session (a fraction of a second; the sampler's first sample is ready about a second later); if the connection has dropped the screen says "Disconnected" with Reconnect, and the named reason from 14 §14.7.

### The live sample

| Field | Notes |
|-------|-------|
| `t` | the server's clock, to detect a stalled loop |
| `cpu_pct`, `cpu_cores[]` | per core, split user / system / iowait / steal |
| `mem_used`, `mem_available`, `swap_used` | and pressure figures **only if the kernel has them** (the lab does not) |
| `net[]` | per interface: bytes and packets in and out (counters); the phone derives bytes/s, packets/s, errors, drops |
| `tcp` | `established`, `syn_recv`, `time_wait`, retransmits |
| `load1` | |
| `top_cpu[]`, `top_mem[]` | the five highest, every sample (cheap form, §15.3) |
| `top_net[]` | the five highest by socket-counter delta, every 5th sample (§15.5 N3) |

## 15.2 What the live screen shows

```
┌ node1 · Live ──────────────  ● connected · 1 s ┐
│ ↓ IN    18.4 MB/s    peak 31.0 MB/s (1:12 ago)  │
│ ↑ OUT    3.1 MB/s    peak  9.8 MB/s             │
│ [ five-minute chart: in and out ]               │
│ since you opened this: ↓ 2.1 GB  ↑ 380 MB       │
├─────────────────────────────────────────────────┤
│ CPU  71 %  ▮▮▮▮▮▮▮▯▯▯   load 3.1               │
│  core0 ▮▮▮▮▮▮▮▮▮▯  core1 ▮▮▮▮▮▯▯▯▯▯            │
│  user 52 · system 14 · iowait 3 · steal 2       │
│ RAM  5.8 / 8.0 GB (73 %)  swap 0                │
├─────────────────────────────────────────────────┤
│ Right now: what is using it   [CPU][RAM][Net]   │
│  php-fpm            38 %  ▮▮▮▮▮▮▮▯             │
│  mariadb            17 %  ▮▮▮▯                 │
│  nginx               9 %  ▮▮                   │
│  [ Pause ]  [ Open top consumers ]              │
└─────────────────────────────────────────────────┘
```

- **Numbers large, chart secondary.** In and out in the unit that keeps them readable (KB/s, MB/s; a bytes/bits switch, remembered), with **current and peak of the visible window**. The default view sums the public interface; a toggle lists every interface, so traffic to a database on the private network is not hidden inside a total.
- **Pause** freezes the chart and the lists (samples keep arriving and are kept for resuming). **"Since you opened this"** is computed from counters, not by adding rates.
- **Staleness is visible.** If no sample arrives for 4 seconds the badge says "no data for 4 s", then "connection lost"; the chart shows the gap; the last values are labelled with their age.
- **Screen reader:** the headline numbers are one live region updated at most every 5 seconds; the detail is the table view.
- **Sustained-condition rules** that 14 §14.9 could not judge from one snapshot (CPU above 90% for five minutes) are judged here, over the last minutes of the session, and say so ("for the last 3 minutes of this session").

## 15.3 What is using the CPU and the RAM

### The measurement

The program reads `/proc` once per sample and the app differences two reads:

| Quantity | Source | Notes |
|----------|--------|-------|
| **CPU per process** | `/proc/<pid>/stat` `utime + stime` (ticks), differenced and divided by elapsed ticks → percent of one core | per-thread is not needed |
| **CPU per service** | the cgroup's own cumulative counter, `cpu.stat` `usage_usec` under `/sys/fs/cgroup/system.slice/<unit>.service` (cgroup v2, which the lab uses) | **includes the CPU of processes that have already exited**, so a service made of short-lived workers is not under-counted |
| **RAM — RSS** | `/proc/<pid>/status` `VmRSS` | cheap, but **counts shared pages in every process that maps them**: many PHP-FPM workers sharing one opcache each show it, so a sum of RSS overstates reality |
| **RAM — PSS** | `/proc/<pid>/smaps_rollup` `Pss` | **proportional**, so the sum is the real figure; costlier, so it is read **only for the top 25 processes by RSS** |
| **RAM per service** | the cgroup's `memory.current` | measured |
| **Disk I/O** | `/proc/<pid>/io` `read_bytes`, `write_bytes` (needs root), differenced | |
| **Identity** | uid → user name, `comm`, full command line, start time, cgroup, threads, open-files count | the command line is shown in full on the process screen only (OQ-M13) |

### From processes to answers a person can act on

| Group by | Key | Answers | How |
|----------|-----|---------|-----|
| **Process** | pid | "that `node` is the problem" | direct |
| **Service** | the unit, from `/proc/<pid>/cgroup` | "PHP-FPM is the problem" (the unit you can restart, 16) | cgroup path → unit; a PM2 child maps to its PM2 name |
| **Tenant** | the process's **uid** → the tenant whose `systemuser` it is | "**this website** is using the CPU" | the **phone** joins the uid against the tenants of this server (`brain_http_server_tenants` where `public_ipv4` is this server's address, loaded through 13's routes), so a rename in 13 takes effect with no change on the server |
| **User** | uid | anything that is not a tenant: `root`, `nginx`, `mysql`, `redis`, `apache` | direct |

An entity that is no tenant and no known service is `unattributed` with its user name.

### What the lab's own setup makes impossible, and what is unknown for production

- **PHP cannot be attributed to a tenant by process on the lab web server**: there is **one shared PHP-FPM pool** (`www.conf`), so every PHP worker runs as the same user. `php-fpm` as a **service** is measured exactly; "which tenant's PHP is busy" is **not available** there. Where production runs **one pool per tenant user**, the tenant column is reliable per process; where it runs one shared pool it is not (OQ-M11). The screen says "PHP is shared by every site on this server, so its use cannot be split by tenant" rather than showing a made-up split. The only per-site figure possible for a shared pool is nginx's per-site timing (§15.4 N2), which needs a log with the site's name in it, which the lab's nginx does not write today.
- **The lab has no tenant that runs on a lab server** (14 §14.1), so tenant attribution cannot be demonstrated on the lab without first creating a tenant system user and a process owned by it; that is a lab change and waits for your say-so.

### How accurate it is

- **Per-process and per-tenant CPU is sampled**: a process that starts and ends between two readings is invisible to it. Short PHP workers and cron jobs are exactly those. **Per-service CPU is measured** (the cgroup counter), so a service's total is trustworthy even when its workers are not individually visible. The screens label them *sampled* and *measured*.
- **Memory is a snapshot**, so it has no such blind spot; the caveat is RSS against PSS above. The screen shows **PSS** for the top 25 and labels any RSS-only figure *RSS (includes shared memory)*.
- **"Used" means `MemTotal − MemAvailable`**, not "not free". Cache and reclaimable memory count as available, because that is what the kernel gives up under pressure; a server at 90% *cache* is healthy. The overview says this in one sentence beside the figure, because it is the most common false alarm.
- **The cost of the scan is bounded**: the script reads at most 3,000 processes (beyond that it reads a rotating subset and says `partial`), and `smaps_rollup` for 25 only. Its duration is returned with every sample.

## 15.4 What is using the network — what can and cannot be known

**Linux does not count network bytes per process.** The kernel counts per interface, per socket, per connection (only with accounting switched on) and, if rules are written for it, per port range. Each answers a *different* question and none answers all of them. The plan uses what is available and **labels each answer; it never presents one as the answer to all of them.**

| # | Method | Answers | Accuracy | Needs on the server | Status here |
|---|--------|---------|----------|---------------------|-------------|
| **N1** | **Interface counters** (`/proc/net/dev`) | how much goes in and out of this server, now and since you opened it | **measured, exact** | nothing | **planned (P20)** |
| **N2** | **nginx per-site accounting**: read the tail of an nginx access log **that names the site** (`$server_name`, `$bytes_sent`, `$request_length`, `$status`, `$request_time`) and total it per site | "**which website** is serving the most data, requests and errors" | **measured, exact for HTTP(S) served by nginx** | a log format that includes the site name | **not available on the lab as it is**: its one global log is the default `main` format with **no site name** (14 §14.1). It becomes available by adding one small nginx config file and a reload: a reviewed action ([16](16-server-control.md) `enable_site_log`), never a side effect of looking, and **off until you choose it** (OQ-M10). Until then the Network screen says "Not collected on this server" and what would turn it on |
| **N3** | **Per-socket byte counters** (`ss -tinpH`: `bytes_sent`, `bytes_received` per TCP socket from the kernel's `TCP_INFO`), summed per owning process, then service, then tenant, differenced between readings | "which **process or service** is moving the most data right now" | **sampled**: counters are per live socket, so a connection that opened and closed **between two readings is missed**; **short HTTP connections are under-counted**, which is why N2 is the right answer for web traffic. Long-lived connections (uploads, streams, database links, `rsync`) are well captured. IPv4 and IPv6 TCP only; **UDP is not covered** | `ss` (present on the lab) | **planned (P22)**, run every 5th sample in live mode and once per snapshot, capped at 5,000 sockets (then `partial`) |
| **N4** | connection-tracking accounting (`nf_conntrack_acct`) | which remote addresses and local ports account for the traffic, including UDP | sampled | a kernel setting, and `nf_conntrack` loaded | **not planned.** On the lab the module is not even loaded. It would be a change to the server's kernel settings, and nothing in this plan needs it. It is listed so the option is on record |
| **N5** | per-port-range firewall counters | exact bytes on a tenant's own application ports | measured | firewall rules written to the server | **not planned**; `firewalld` is not even running on the lab |
| — | eBPF per-process network accounting | the exact per-process answer | exact | a compiler toolchain and kernel headers on every server | **rejected**: far more change than the answer is worth |

### What the screens may say

| Question | Answered by | The label on screen |
|----------|-------------|---------------------|
| Total in and out, now and since you opened the screen | N1 | *measured* |
| Which **website** is using the most web bandwidth | N2, when collected | *measured (nginx)* |
| Which **service or process** is moving the most data right now | N3 | *sampled; short connections under-counted* |
| Which **remote address** or **port** | N3's peer column | *sampled* |
| Which **tenant** uses the most network | N2 for web **and** N3's tenant column for everything else, as **two separate columns** (`web (nginx)` and `other (sampled)`), **never added together** | both labels, side by side |
| Whether it is **one thing** or **everything** | the shape: N1 up, and one entity holding a large share of N2 or N3 | derived (§15.5) |

**What it cannot say, and the screen says so:** the exact per-process network use of short connections; UDP per process; web traffic per site without N2; anything about network history. When the data for a question is not collected on that server, the card says **"Not collected on this server"** and what would turn it on, never an empty list that looks like "nothing".

### Remote peers

N3's socket list carries the **remote address** of each connection. The top peers by bytes (address, port, direction, bytes, the local process) are shown on the Network screen. A single remote address holding most of the inbound bytes is the shape of an abusive client or a flood, and the screen highlights it. A country for each is **not** looked up: that would mean sending addresses to a lookup service; if you want it, the Brain config already has a MaxMind key and it is a Brain-side lookup (OQ-M14).

## 15.5 "What is using the most" — the screens and the explanation

### Top consumers (one screen, four tabs)

**CPU · Memory · Network · Disk I/O.** Each tab lists the entities grouped by a switch (**Process / Service / Tenant / User**), sorted by the tab's measure, each row with the figure, a bar, its **share of the server total** ("php-fpm: 38% of the CPU" is what makes a list useful), the **accuracy word** and a trend arrow against the previous reading. A window selector chooses **Now** or **This session** (the last five minutes of the open Live screen, in memory). Tapping a **tenant** opens it in 13 with a **Resource use** line; a **service** opens it in 14 Services (and 16's actions); a **process** opens its detail. The Network tab shows N2 and N3 side by side.

### The process screen

pid, user, tenant, unit, **full command line**, executable, working directory, start time and age, threads, open files, CPU / PSS / RSS / disk / network (N3) with sparklines for the session, its listening ports, its parent. **No actions in this file**; signalling a process is in [16](16-server-control.md).

### The Network screen

Interfaces (rates and totals), TCP state counts and retransmits, **per-site web traffic** (N2, when collected), **top services** and **top peers and ports** (N3), each block labelled, each "not collected" block saying what would turn it on.

### The explanation card

A **"What's going on"** card at the top of Overview and Live: rule-based sentences made **in the app** from the same numbers, each linking to the screen that shows them. **No model, no guessing: every sentence is a rule over measured data and shows the figures it used.**

| Rule | Sentence (example) |
|------|--------------------|
| One entity holds ≥ 50% of the CPU | "**php-fpm** is using 62% of the server's CPU (1.2 of 2 cores)." (with the tenant when PHP is per-tenant, and "PHP is shared by every site here" when it is not) |
| CPU high, `iowait` high | "The CPU is waiting on the disk (iowait 38%), not computing. The heaviest disk writer is **mariadb** (41 MB/s)." |
| CPU high, `steal` high | "**The host is taking 18% of this server's CPU** (steal). Processes here are not the cause; this is the provider's host." |
| Memory `available` < 10% | "Memory is nearly full: **mariadb** holds 2.1 GB (PSS) and PHP-FPM 1.6 GB across 38 workers. Cache is not the reason (cache counts as available)." |
| An OOM kill, or a unit whose restarts rose | "The kernel killed **node (shop.example)** for memory; it has restarted 3 times." |
| One site holds ≥ 50% of web bytes out (N2) | "**shop.example** accounts for 71% of outbound web traffic." |
| One remote address holds ≥ 40% of inbound bytes (N3) | "**203.0.113.9** is sending 44% of inbound traffic (sampled)." |
| Inbound up sharply, nothing stands out | "Traffic is up 6× over the last minutes of this session and no single service accounts for it." |
| Resources normal, a check failing | "Resources are normal. The problem is a failing check: **nginx_local_http**." |

Thresholds are settings (`brain_config.monitoring.explain`), not constants. **A rule whose data is not collected says so** ("Network by site is not collected on this server") instead of staying silent.

## 15.6 Phases

| Phase | Name | Delivers | Needs |
|-------|------|----------|-------|
| **P20** | **The live view** *(built)* | the program's live sampler (`/v1/live`) and its self-stop, the **Live** screen (network in and out, CPU per core, RAM, load, pause, peaks, session total, staleness), the sustained-condition rules | [14](14-server-monitoring-foundation.md) P17 to P19 |
| **P21** | **CPU and RAM attribution** *(built)* | the process scan, grouping by process / service / tenant / user, the tenant join on the phone, the **Top consumers** screen (CPU, Memory, Disk I/O tabs), the **process** screen, the explanation card's CPU and memory rules | P20 |
| **P22** | **Network attribution** *(built; N2 not, pending OQ-M10)* | N3 (sockets), the **Network** screen and the Network tab, the network rules of the explanation card; **N2 only if OQ-M10 is a yes** | P21 |

## 15.7 Tests and acceptance

**App (a fake `MonitorClient` that replays captured responses, as in 14):** the live parser handles a sample with a missing `/proc/pressure`, a counter that wraps or resets, a stalled loop (no sample for 4 s) and a garbled line (dropped, counted, never crashes the screen); session totals come from counters, not summed rates; **Pause** freezes without dropping data; the byte/bit switch converts; **the live session ends on leaving the screen, on backgrounding after 30 s and on app lock** (a test with a fake clock), and opening a second server's Live ends the first; the grouping turns a uid into a tenant through the phone-side join, an unmatched uid into `unattributed`, and **a shared PHP pool into "shared, cannot be split by tenant" and never into an invented split**; shares add to at most 100%; **N2 and N3 stay in separate columns** (a test asserts they are never summed); every figure carries its accuracy word; every "not collected" case returns that, not an empty list; each explanation rule is true on a fixture that should trigger it, silent on one that should not, and says so when its data is missing; every new screen is in the **screen tour** (tap targets, labels, contrast, 200% text, dark theme).

**Program (fixtures captured from `lab2-web` and `lab2-brain`, plus a hand-made fixture with a per-tenant PHP pool):** per-process CPU from two stat readings; a process that exits between readings does not corrupt the delta; PSS read for the top 25 only; the scan stops at the cap and reports `partial`; **the sampler stops 10 s after the last `GET /live` and when the session ends, and the program is then idle (no CPU)**; a request with an ended session gets silence.

**Exit checks (lab, on the phone), using only what is already on the VMs, and any load generated **with your say-so** (the lab notes say read-only unless you say otherwise):**
1. Open Live on `lab2-web`; numbers move within a few seconds; leave the screen and confirm on the server that **the sampler has stopped and the program is idle again** (its CPU time stops rising).
2. **CPU:** with your permission, run a short busy loop on the lab VM; see it as the top process and its service; see its share fall when it stops; the service-level CPU (cgroup) agrees with the sum of its processes within a few percent.
3. **Memory:** compare a many-worker PHP-FPM pool's **PSS sum** with its RSS sum and see RSS is larger.
4. **Network:** `curl` a large file through nginx repeatedly; the interface total (N1) shows the bytes; with N2 on, that site tops the web list within a few percent of what `curl` received; without N2 the screen says "Not collected on this server".
5. **Sockets:** a long upload (`scp`/`curl --limit-rate`) shows under its process in N3 labelled *sampled*; a burst of many tiny requests is **visibly under-counted** in N3 and the screen says so.
6. **PHP on the lab:** open Top consumers → Tenant and see the "PHP is shared" line, not a split.
7. The phone's own cost: leave Live open five minutes and note battery and data used (about 1 KB/s).

## 15.8 Open questions

| # | Question | Needed by |
|---|----------|-----------|
| **OQ-M10** | **Per-site web traffic (N2) needs nginx to write the site name into a log.** That is one small config file and a reload on each server, done as a reviewed action, off until you choose, removable. Production's own vhost layout is unknown, so whether it works there cannot be said yet. Do you want it, on the lab first? If **no**, the per-site column is simply not shown | P22 |
| **OQ-M11** | **Does production run one PHP-FPM pool per tenant, or one shared pool as the lab does?** It decides whether the tenant column can show PHP use. The first connection to a production server shows it (the pool files are readable) | P21 |
| **OQ-M12** | **Live limits:** 15 minutes per run with "Keep going", one server's session at a time, ends 30 s after backgrounding, keeps the screen on only while showing. Acceptable? | P20 |
| **OQ-M13** | **Process command lines** can hold a secret (a `--password` argument). Consistent with 13 (nothing hidden) they are shown in full on the process screen only, never logged by the app, never cached. Confirm, or ask for redaction patterns | P21 |
| **OQ-M14** | **Country for remote peers:** none (proposed), or a Brain-side lookup using the existing MaxMind key? | P22 |
