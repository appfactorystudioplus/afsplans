# 17 — Linode account management (power, create, delete, and the rest)

**Status: P25 to P28 built (2026-10-06), tested against fakes only; nothing has been sent to the real Linode API.** See §17.9 and [the completion document](complete/17-linode-management.complete.md). Written 2026-10-06 from Linode's published OpenAPI spec (version 4.215.0, `https://api.linode.com/v4`). Phases **P25–P28**. One small server change (BS27 accepts the `linode.*` action names, §17.9). No Linode call that changes anything was made while writing this; the only live calls were public, no-token ones (`/v4/regions` headers, `/v4/linode/types`).

## 17.1 What you asked for

Production runs on Linode (Akamai). You put `LINODE_TOKEN` and `LINODE_IN_USE` in the app's `.env`. You want the app to manage the account's services: turn servers on and off, create servers, and the other operations the API offers.

## 17.2 What the API gives us (checked in the spec)

- **Base URL** `https://api.linode.com/v4`; header `Authorization: Bearer <token>`; JSON in and out.
- **Rate limit** seen on a public call: **1840 requests per window, `Retry-After: 60`**, headers `X-RateLimit-Limit/Remaining/Reset`. The app must read them and back off, never loop.
- **Token scopes** are per area: `linodes`, `volumes`, `firewall`, `domains`, `ips`, `nodebalancers`, `images`, `events`, `account`, `stackscripts`, each `read_only` or `read_write`. A token can be limited to only what we use (§17.7).
- **Server (Linode instance) operations**, all `linodes:read_write` unless marked:

| Operation | Call | Notes |
|---|---|---|
| List / get | `GET /linode/instances`, `/{id}` | read_only |
| **Boot (power on)** | `POST /linode/instances/{id}/boot` | picks the config profile if there is only one |
| **Shut down** | `POST …/shutdown` | waits for queued actions |
| **Reboot** | `POST …/reboot` | |
| **Create** | `POST /linode/instances` | required: `type`, `region`; usual: `image`, `root_pass` or `authorized_keys`, `label`, `firewall_id`, `tags`, `backups_enabled`, `stackscript_id`, `booted`. **Starts billing** |
| Update (label, tags, alerts) | `PUT …/{id}` | |
| **Delete** | `DELETE …/{id}` | **irreversible, disks gone** |
| Resize | `POST …/resize` | to another type; billing changes |
| **Rebuild** | `POST …/rebuild` | **wipes all disks**, needs an image |
| Clone / migrate / rescue / upgrade / reset root password | `…/clone`, `/migrate`, `/rescue`, `/mutate`, `/password` | |
| Backups | list, snapshot, enable, cancel, **restore** (overwrites) | |
| Disks | list, create, resize, clone, **delete** | |
| Usage | `…/stats`, `…/transfer` (and `/account/transfer`) | CPU, IO, network series; monthly transfer used against the pool |

- **Around the servers:** Firewalls (`/networking/firewalls` and rules, with history), Volumes (create, attach, detach, resize, delete), IPs and reverse DNS, NodeBalancers (and stats), Images, StackScripts, SSH keys, tags, regions and availability, Linode types with prices, **Events** (what happened on the account, with progress), account, maintenance and notifications, Object Storage, Kubernetes, Databases, VPCs.
- **Changes are asynchronous.** A boot or reboot returns at once; the real progress shows in `GET /account/events` (and the instance's `status`: `running`, `offline`, `booting`, `shutting_down`, `provisioning`, …). The app must watch the instance status and event until the action finishes, not assume it worked.

## 17.3 How this fits with plans 14–16

Plans 14–16 monitor and control **inside** a server (the program on it, SSH from the phone). Linode's API controls the **machine from outside** (power, create, delete, firewall, resize), including when the server is down and the program cannot answer. They complement each other:

- A red light from plan 14 can offer **Boot** (Linode power) when the Linode is `offline`.
- Plan 16's reboot (inside) and Linode's reboot (outside) both exist; the app prefers inside, and the outside one is the fallback when the server is hung.
- **The link:** each HTTP server record gets `json.linode.id` (the Linode instance id) so the app knows which Linode is which row. The app can list the Linodes, match them to rows by IP address (`ipv4`), and offer to fill the id.

**Same rules as plans 14–16 apply:** the phone talks to Linode **directly**; nothing is pushed through the Brain; the Brain does not call Linode; no alerts, no Firebase.

## 17.4 `LINODE_IN_USE`

`LINODE_IN_USE=false` (or missing): the whole Linode area is hidden, no Linode call is ever made. `true`: the area appears (under Infrastructure, next to the server lists). Default on the lab: false, because the lab VMs are not Linodes.

## 17.5 Decisions

| # | Decision | Why |
|---|---|---|
| **LD1** | A new area **Linode** in the Infrastructure hub, shown only when `LINODE_IN_USE` is true and a token is present. | Matches the switch you added. |
| **LD2** | Calls go **phone to `api.linode.com` directly**, with one small client in the app that handles the bearer header, JSON, pagination (`page`, `page_size`, up to 500), `Retry-After`, the rate-limit headers, and Linode's error list (`errors: [{field, reason}]`). | One place for the awkward parts. |
| **LD3** | **Read first, change second.** P25 is read-only (list, details, status, usage, prices, events). Nothing changes anything until P26. | Lets you check the token and the data safely before anything can cost money. |
| **LD4** | **Every change is a two-step action:** a review sheet naming the server (label, id, IP, region) and the exact effect and cost, then a confirm. **Dangerous** actions (delete, rebuild, resize, restore a backup, delete a disk, delete a volume, delete a firewall, reset root password) also require **typing the server's label**. | Delete and rebuild destroy data and cannot be undone; resize and create change the bill. |
| **LD5** | **Create** is a form: region (with availability), type (with monthly price shown), image, label, tags, firewall, backups on/off, and access (**SSH key preferred**; a generated root password is shown once and not stored). The review sheet shows the **monthly and hourly price** before the confirm. | Creating is the one action that starts a charge. |
| **LD6** | After any change the app **follows the instance status and its event until done** (poll every 3 s for the first minute, then 10 s, stop at 10 minutes and say it is still in progress), showing progress. | The API is asynchronous (§17.2). |
| **LD7** | **Audit:** each change is first recorded on the Brain through the same audit-first path as plan 16 (BS27), with a `linode.*` action name (no token, no password), then performed. If the record cannot be saved, the change is not made. | Same rule as server control; reuses the work. |
| **LD8** | **Safety switches:** a Settings switch **"Allow Linode changes"** (default off) gates every write; **"Allow destructive Linode actions"** (default off) gates delete, rebuild and the other dangerous ones. With them off the area is read-only. | Two independent gates, as in plan 16. |
| **LD9** | **No history and no alerts.** Usage charts (CPU, network, IO from `…/stats`; transfer from `…/transfer`) are fetched on demand when you open a server, drawn with fl_chart. | Same as plan 15. |
| **LD10** | **Firewalls, volumes, IPs/rDNS, tags, backups, images, StackScripts** follow in P27, one list-and-edit screen each, using the same review/confirm/audit pattern. Object Storage, Kubernetes, Databases, VPCs, Support tickets, Longview and Managed are **out of scope** until you ask. | Covers "other operations" without building every product at once. |

## 17.6 Phases

- **P25 — Read-only Linode area.** The API client (LD2), the `LINODE_IN_USE` gate (LD1/§17.4), account summary (balance, month's transfer against the pool), Linode list with status lights, detail page (specs, IPs, region, type price, disks, backups, firewalls, tags, recent events), usage charts, and linking a Linode to a server row (`json.linode.id`, §17.3). Tests with a fake client; a test that the area is absent when the switch is false.
- **P26 — Power and lifecycle.** Boot, shut down, reboot (class "safe-ish": review plus confirm), status/event following (LD6), audit (LD7), the two Settings switches (LD8), then **create** (LD5) and **delete** (LD4, label typed). The red-light "Boot" shortcut from plan 14's list.
- **P27 — The rest of the operations.** Resize, rebuild, rescue, clone, backups (enable, snapshot, restore), disks, firewalls (rules with version history), volumes (attach/detach), IPs and rDNS, tags, SSH keys.
- **P28 — Hardening.** Rate-limit behaviour under load, error wording for the common failures (insufficient grants, limit reached, region out of stock, payment needed), accessibility and navigation tour entries, the completion document.

## 17.7 Open questions (needing your answer)

| # | Question | Default if you don't say |
|---|---|---|
| **OQ-L1** | ~~Where does the token live?~~ **Answered 2026-10-06, changed the same day: it stays in `.env`** (`LINODE_TOKEN`, with `LINODE_IN_USE`), because that is easier. The app reads it from there like its other settings; it is masked on screen and never written to logs, the audit record or a screenshot-visible field. The cost, stated once: `.env` is listed as an asset in `pubspec.yaml`, so **the token is packaged inside the APK** that is built with it. It is not in git. Settings still shows the token's scopes | P25 |
| **OQ-L2** | ~~Token breadth?~~ **Answered: the token has full access.** So the app shows the token's scopes in Settings (from the `X-OAuth-Scopes` header) with a plain warning that a full-access key on a phone can do everything, and recommends replacing it with a limited token (Linodes, Firewalls, Volumes, Events read/write; Account read-only; an expiry date). The app works with either; the two safety switches (LD8) stay the protection meanwhile | P25 |
| **OQ-L3** | ~~Restricted user?~~ **Answered: the account is the owner**, so there are no grant restrictions to handle (the app still shows Linode's error if one ever appears) | — |
| **OQ-L4** | ~~Create in the first release?~~ **Answered: yes**, in P26 behind the LD8 switch, with the price shown before confirming | P26 |
| **OQ-L5** | ~~Linode DNS?~~ **Answered: no, you run your own DNS.** Linode Domains and records are **removed from scope** (the Brain's own DNS tools cover it) | — |

## 17.8 What I will not do

- Call any Linode endpoint that changes or bills anything before you say go, and not while testing (tests use a fake client).
- Print, log, or put the token in a commit, a plan, or a chat message.
- Make the Brain a Linode client or store the token on the Brain.

## 17.9 Build notes (2026-10-06)

All four phases are built. The full account of what was built, what was checked and what was not is in [complete/17-linode-management.complete.md](complete/17-linode-management.complete.md). In short:

- **P25** read-only area; **P26** boot, shut down, reboot, create, delete, with the follow (LD6), the audit-first record (LD7) and the two switches (LD8); **P27** resize, rebuild, rescue, clone, migrate, upgrade, reset root password, rename and tags, backups (enable, cancel, snapshot, restore), disks (add, resize, clone, delete), firewalls (create, rules with version history, enable and disable, delete), volumes (create, attach, detach, grow, delete), IP reverse DNS, SSH keys (add, remove), and read-only lists of images and StackScripts; **P28** error wording, rate-limit behaviour, accessibility and navigation tour entries, the completion document.
- **The server change the plan said was not needed:** the Brain's audit route (BS27) refused any action name outside plan 16's catalog, so it now also accepts the 33 `linode.*` names (`StaffMonitorAuditController::LINODE_ACTIONS`). A test in the app reads that PHP list and fails if the two ever differ. The PHPUnit test for it is written and **not run** (same reason as BS27's).
- **Decisions that changed or were settled while building:** the charts are hand-drawn, not fl_chart; the token's scopes show on the Linode front page *and* in Settings; images and StackScripts are lists only (nothing in the app edits them); a firewall's rules are edited as JSON in a text box with a count of the rules before and after, not rule by rule; **all changes need the Brain's record**, so the Linode area is read-only whenever `BRAIN_MONITOR_CONTROL` is off or the account has no `monitor_control` capability (this reuses plan 16's switch; there is no second one).
