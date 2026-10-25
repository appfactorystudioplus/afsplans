# 04 — Manifest and package format

## Completion document

When this plan step is completed, write `complete/04-manifest-and-package-format.complete.md`.

---

This document defines what a developer actually builds and uploads. Every field here exists to
be checked by the review pipeline ([10](10-review-and-publishing-pipeline.md)) or enforced by the
Plugin Runtime ([05](05-security-and-sandboxing.md)) — nothing is decorative.

## 1. Package layout

A submission is a single zip:

```
my-plugin.zip
├── plugin.json                 (the manifest — §2)
├── backend/
│   ├── src/                    (PHP, namespaced Plugins\{Slug}\...)
│   └── hooks/                  (one file per subscribed extension point — see 06 §3)
├── frontend/
│   └── dist/
│       └── main.js             (single ESM bundle — see 08 §1 for build constraints)
├── assets/
│   ├── icon.png
│   └── screenshots/*.png
└── CHANGELOG.md
```

There is no `migrations/` directory with raw SQL or Laravel migration classes — per
[03 §3](03-database-schema.md) and [05 §5](05-security-and-sandboxing.md), a plugin never ships
DDL. Its data schema is declared in `plugin.json` itself (§4).

A developer does not assemble this layout by hand: the scaffold/packaging tool in
[15](15-developer-scaffolding-and-local-development.md) generates it, adds development-only
directories alongside it for local testing, and produces exactly this layout — nothing more — as
the final packaging step.

## 2. `plugin.json` — required fields

| Field | Type | Notes |
|---|---|---|
| `slug` | string | Immutable once first submitted (`plugins.slug`). Lowercase, hyphenated, globally unique across the marketplace — namespaces this plugin's routes (`/plugins/{slug}/...`), asset paths, and data collections |
| `name` | string | Display name |
| `version` | semver string | Must be strictly greater than the plugin's previously published version |
| `summary`, `description` | string | Marketplace copy |
| `developer` | string | Must match the authenticated submitter's `plugin_developers` account — the pipeline rejects a mismatch, not just flags it |
| `platform_compat` | semver range | Which [06 §4](06-extension-points-and-hooks.md) extension-point catalog versions this release was built against |
| `capabilities` | array of capability declarations | Every capability this release's code actually calls, drawn only from `plugin_capabilities_catalog` ([03 §1](03-database-schema.md)) — see §3. Most are bare keys (`"orders.read"`); a capability the catalog marks as parameterized (currently only `http.egress`) is an object naming its parameter explicitly (`{ "key": "http.egress", "hosts": ["api.example.com"] }`) — see §3.1 |
| `hooks` | array of `{ extension_point, handler_file, kind }` | Which backend extension points this release subscribes to, and whether each is a `notify` or `filter` hook ([02 §6](02-architecture-overview.md)) |
| `data_schema` | array of collection declarations | See §4 |
| `frontend` | object, nullable | `{ entry: "frontend/dist/main.js", nav: { label, icon }, settings_panel: true|false }` |
| `permissions_requested_summary` | string | A one-paragraph, plain-language explanation of *why* the plugin needs what it's asking for — required, read by the human reviewer and optionally shown on the consent screen |

## 3. Capabilities are declared, not inferred

A manifest lists every capability it uses **explicitly**. The review pipeline's static analyzer
cross-checks this list against what the code actually attempts to call through the plugin SDK
([10 §3](10-review-and-publishing-pipeline.md)) — a call to a capability not declared in the
manifest is an automatic rejection, not a warning, because an undeclared capability is exactly
the thing the tenant consent screen (D8) depends on being complete. This is the manifest's most
security-relevant field; everything else here is metadata, this is the actual boundary.

### 3.1 `http.egress` is parameterized: the manifest names the hosts, the tenant approves them

`http.egress` cannot be a bare capability grant the way `orders.read` is — "may make outbound
HTTP requests" with no further scoping is not something a tenant can meaningfully approve or a
reviewer can meaningfully audit. So the manifest declares the **specific hostnames** the release
needs, as a parameter on the capability itself:

```json
"capabilities": [
  { "key": "http.egress", "hosts": ["api.some-saas.example", "hooks.some-saas.example"] }
]
```

This settles, as a single answer, a question the plan previously left inconsistent between this
document, [05 §4](05-security-and-sandboxing.md), and [09 §3](09-admin-dashboard-ux.md): **the
developer declares the hosts, at submission time, reviewed like every other part of the
manifest; the tenant approves or rejects that declared list verbatim at grant time and never types
or edits a hostname themselves.** Reasoning:

- A developer-declared list is **auditable at review time** — the static analyzer
  ([10 §3](10-review-and-publishing-pipeline.md)) can cross-check the declared hosts against
  literal URL strings it finds in the code, flagging a mismatch (code that references a host not
  in the declared list, or a suspiciously broad declared list for what the plugin claims to do)
  before a human reviewer ever sees it. A tenant-entered list has no equivalent check — nobody
  reviews what an individual tenant typed into a text box.
- It keeps the consent screen's job the same shape as every other capability: **show what was
  declared, get a yes or no** ([09 §3](09-admin-dashboard-ux.md)) — not "collect a technical input
  from a non-technical admin," which is also where a tenant is most likely to over-grant (a
  plugin's own setup instructions saying "enter `*.example.com`" is a phishing-adjacent pattern
  this design avoids entirely by never giving the tenant a text field to fill in).
- The stored grant (`plugin_grants`, [03 §2](03-database-schema.md)) records the **exact approved
  host list** as it was at consent — not just that `http.egress` was granted — so a later manifest
  update that adds a host is a change to what was approved, not an internal detail invisible to
  the tenant (§6 below, and [07 §3](07-installation-and-activation-lifecycle.md)).

A capability the catalog marks `requires_parameters` (currently only `http.egress`) is rejected by
automated intake ([10 §2](10-review-and-publishing-pipeline.md)) if submitted without its
parameter, the same way a missing required field anywhere else in the manifest is rejected.

## 4. `data_schema` — how a plugin declares storage without DDL

Since a plugin never ships a migration ([03 §3](03-database-schema.md)), it declares each
`plugin_data` collection it needs:

```json
"data_schema": [
  { "collection": "orders_cache", "description": "Cached copy of orders this plugin has processed" },
  { "collection": "settings_backup", "description": "Snapshot of settings before a bulk change" },
  { "collection": "settings", "description": "Cookie banner text and appearance", "public": true },
  {
    "collection": "consent_daily_counts",
    "description": "Per-day accepted/declined counters",
    "increment": { "fields": ["accepted", "declined"], "max_amount": 1 }
  }
]
```

At install time, these collection names are simply the values the platform allows this install to
write under `plugin_data.collection` for this `install_id` — no schema migration runs at all
(§3 above already noted there is no DDL to run). A plugin cannot write to a collection it did not
declare; the capability-scoped SDK checks the declared list, not just the (tenant, plugin) pair.

**`public: true`** (added for [16](16-example-plugin-cookie-consent.md)) declares that a
collection's contents are intended to be read by anyone, not just the tenant's own admin — the
only way `plugin_data.read` is ever reachable from a
[public site slot](06-extension-points-and-hooks.md) §2.1
([05 §12](05-security-and-sandboxing.md)). Default `false`. Marking a collection public is a
developer declaration reviewed the same way every other capability claim is
([10 §3](10-review-and-publishing-pipeline.md)): a collection actually holding anything
tenant-sensitive should never be marked this way, and a reviewer checks that it isn't — but this
is a narrow, per-collection carve-out, not a general public-read capability; every other
collection stays exactly as unreachable from the public path as before.

**`increment`** (also added for [16](16-example-plugin-cookie-consent.md)) is **required** on any
collection the plugin ever passes to `plugin_data.increment` ([05 §4](05-security-and-sandboxing.md)),
whether that call happens from the admin dashboard or a public site slot. It declares the only
`field` names the platform will ever write to for this collection, and a `max_amount` per call.
This is not advisory metadata — the Plugin Runtime validates every `plugin_data.increment` call
against it at the moment of the call ([05 §12](05-security-and-sandboxing.md)), and rejects a call
naming an undeclared field or exceeding the declared amount, regardless of what the plugin's own
frontend intended to send. This matters most, and is checked hardest
([10 §2–§3](10-review-and-publishing-pipeline.md)), for a collection also reachable from a public
site slot: an anonymous visitor can POST anything to a public endpoint, not only what the
legitimate toast's own JavaScript would ever construct, so the bound has to be enforced
server-side against the declaration — the manifest cannot rely on "the frontend only ever sends
`amount: 1`" being true, because nothing stops a request that isn't the frontend at all.

## 5. Signing — the artifact that actually executes

The developer's raw zip is **never** the thing installed or executed (D6). The pipeline
([10 §4](10-review-and-publishing-pipeline.md)):

1. Extracts and statically analyzes the raw submission.
2. On passing automated checks and manual review, **re-packages** the backend code and frontend
   bundle as two separate artifacts, computes `sha256` over each, and writes both hashes plus the
   full manifest into `plugin_releases`.
3. The reviewer's approval ([10 §4](10-review-and-publishing-pipeline.md)) issues a short-lived
   **reviewer approval assertion** naming the exact artifact digest just approved
   (`plugin_releases.reviewer_approval_ref`, [03 §1](03-database-schema.md)) — the signing service
   checks this assertion, not merely that the calling application is the review pipeline, before
   it will sign anything ([05 §11](05-security-and-sandboxing.md)'s reasoning for why the two are
   different guarantees).
4. Signs `sha256(manifest_json) + backend_artifact_sha256 + frontend_bundle_sha256` with the
   marketplace's private signing key, and records which key (`signing_key_id`,
   [03 §1](03-database-schema.md)) — needed once the key is ever rotated so verification and any
   future compromise response know which releases used which key ([05 §11](05-security-and-sandboxing.md)).
5. The Plugin Runtime ([05 §2](05-security-and-sandboxing.md)) and the Plugin Host
   ([08 §4](08-frontend-runtime-and-dynamic-loading.md)) both verify this signature against the
   public key identified by `signing_key_id` before ever reading a byte of the artifact — a
   release whose signature does not verify is refused, full stop, regardless of how it got onto
   disk.

This means a compromised storage path, a database row edited by hand, or a stale cached artifact
can never cause unreviewed code to run — the signature is over content the review pipeline itself
produced, not over anything a developer's upload directly controls the bytes of.

## 6. Versioning and updates

- **Every version bump is a new `plugin_releases` row** and goes through the full pipeline again
  ([10](10-review-and-publishing-pipeline.md)) — there is no "patch releases skip review" path in
  the first delivery (00 §6 Q2 notes this as a possible later relaxation, not a current one).
- `platform_compat` is checked against the live extension-point catalog version at both submission
  time and at every Plugin Runtime dispatch — a release built against a retired hook contract is
  refused activation with a clear message, rather than silently receiving a shape of data it
  wasn't built for.
- A tenant's install record points at exactly one release at a time
  ([03 §2](03-database-schema.md), `plugin_installs.release_id`); updating is changing that
  pointer after the tenant confirms any *new* capabilities the update's manifest adds (an update
  that adds no new capability can be applied without a fresh consent screen — one that does
  cannot, ever, regardless of how minor the developer considers the change). **A changed
  parameter on an already-granted capability counts as new for this purpose** — an update that
  adds a host to `http.egress`'s declared list (§3.1) requires the same re-consent as adding a
  wholly new capability key, even though `http.egress` itself was already granted. Otherwise a
  version bump would be a silent path to expanding what a plugin can already reach, which is
  exactly the gap a capability-by-capability consent model exists to prevent.

## 7. What a developer cannot put in a manifest

- A capability not in `plugin_capabilities_catalog` — the field is validated against that table,
  not free text.
- A `platform_compat` range that includes a retired catalog version whose hooks changed shape
  incompatibly (05 §4's SDK versioning makes this a hard validation, not a courtesy warning).
- Any reference to another plugin's slug, table, or capability — there is no plugin-to-plugin
  dependency in the first delivery (00 §3 non-goal).
- Raw SQL, a Composer `require` outside an allowlisted set of pure-logic libraries vetted by the
  review pipeline (no network clients, no filesystem libraries, no process-execution libraries —
  the capability SDK is the only sanctioned way to reach any of those), or a build step that
  executes at install/activate time — the frontend bundle and backend artifact are both build
  **outputs** the developer produces before submission, never something built on the platform.
