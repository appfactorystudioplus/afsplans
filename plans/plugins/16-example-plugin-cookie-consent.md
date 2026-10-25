# 16 — Example plugin: cookie consent

## Completion document

When this plan step is completed, write
`complete/16-example-plugin-cookie-consent.complete.md`.

## Code documentation

Document all new and changed code with block comments naming this plan step, per the standard
in [README.md](README.md).

---

## 0. What this step is

A worked example — the first real plugin built through this entire system, end to end — and one
of the internal dogfood reference plugins [14 §2](14-rollout-and-testing.md) requires before any
external developer sees the marketplace. It is also the plugin that surfaced two real gaps in the
base plan while being designed: no efficient way to count something without giving a plugin its
own database, and no way for a plugin to render anything on the tenant's actual **published
website** at all. Both are now closed generally, not patched one-off for this plugin — see
[05 §12](05-security-and-sandboxing.md), [06 §2.1](06-extension-points-and-hooks.md), and
[03 §1](03-database-schema.md)'s `reachable_from_public_site` flag. This document is the concrete
application of all of that, plus everything else in 00–15, to one real, small, genuinely useful
plugin.

## 1. Scope

**What it does:**

- Shows a cookie-consent toast to a first-time visitor on every page of a tenant's published
  website (the [public site slot](06-extension-points-and-hooks.md) §2.1, `site.global`).
- Records the visitor's choice (accept / decline) as an aggregate, per-day count — not a
  per-visitor record (§3 explains why).
- Gives the tenant admin a **settings** screen (banner text, categories offered, position, colors)
  and a **stats** view (accepted vs. declined, by day, with a total and a percentage) inside the
  admin dashboard's Plugins area.

**Non-goals, stated up front so the example stays tractable:**

- **No actual script-blocking or consent-gated tag management.** This plugin shows a toast and
  records a choice; it does not gate which analytics/marketing scripts run based on that choice.
  A real consent-management platform (CMP) does far more than this — that's future work for a
  different, more ambitious plugin, not a reason to inflate this reference example.
- **No per-visitor identifiable tracking.** Deliberately, not as an oversight — see §3.
- **No multi-jurisdiction legal logic** (GDPR vs. CCPA banner variants, geo-detection, etc.). The
  banner text and categories are whatever the tenant admin configures in settings; the plugin does
  not make legal determinations.
- **No editor-placed, per-page block variant.** The toast is site-wide (`site.global`,
  [06 §2.1](06-extension-points-and-hooks.md)), not something a tenant drags onto one specific
  page via the builder's block palette — a cookie banner belongs on every page or none, which is
  exactly what the narrower `site.global` slot was designed for instead of extending the builder's
  own first-party `BUILDER_MODULES` registry ([01 §5](01-current-state-audit.md), left untouched
  per [00 §3](00-overview.md)'s non-goal).

## 2. The storage decision: no per-plugin SQLite

Raised directly during scoping: should every plugin get its own SQLite database "for safety"?
**No** — recorded here as this plan's answer, since this plugin is exactly the case that prompted
the question.

| | Per-plugin SQLite | The existing `plugin_data` model + `plugin_data.increment` |
|---|---|---|
| What the plugin gets | A raw file handle and a query language | Get/put by key, plus one atomic counter method — no query language at all |
| Concurrency under a public, high-traffic toast | File-level write locking — a known weak point for exactly this write pattern (many visitors, many concurrent page loads) | An atomic `UPDATE ... SET count = count + 1`-style operation against the existing shared database, which already handles this pattern estate-wide |
| Review burden | Arbitrary SQL the static analyzer must reason about, reopening exactly what [05 §5](05-security-and-sandboxing.md) closed for D7 | Nothing new to review — the method is already closed-set and enumerable |
| Operational burden | A new file per (plugin, tenant) install: its own lifecycle, its own backup story, outside the platform's existing MySQL backup process | None — it's a row in a table already backed up the way everything else is |
| Isolation "for safety" | Feels stronger (a separate file) but still trusts the plugin's own SQL logic within that file | Actually stronger — the plugin never gets to express a query that could reach outside its own scope, because it never gets a query language |

The instinct behind "give it a database" is right — this plugin's data should be genuinely
isolated. It already is, more strongly, under the existing model. What was actually missing was
an efficient way to *count* something, which [05 §4](05-security-and-sandboxing.md)'s new
`plugin_data.increment` capability now provides.

**Data shape used here:** one `plugin_data` collection, `consent_daily_counts`, one row per day
(`record_key = "2026-09-27"`), `payload_json = { "accepted": 0, "declined": 0 }`, updated with
`plugin_data.increment($collection, $date_key, $field, 1)` on every visitor choice. No
per-visitor row, no IP address, no identifier of any kind — the admin dashboard's stats view
(§5) reads and sums these small daily rows; it never has a "list of visitors" to show because one
was never recorded. This is also simply the right privacy posture for a *consent* plugin
specifically — logging identifiable visitor data would be an odd thing for this particular plugin
to do regardless of the storage question.

## 3. The builder integration — audit first, don't assume

**This plan has not examined how `resources/js/portal/builder` actually renders a published
page's shell**, or where a site-wide element like a toast would physically be injected (a footer
partial, a template hook point, something else in that pipeline). Per this workspace's own
verification discipline, that is stated plainly rather than guessed at. **Phase P0 of this step
is that audit** — read the builder's publish/render pipeline, identify the actual injection point
for a `site.global` slot, and confirm nothing about it conflicts with
[06 §2.1](06-extension-points-and-hooks.md)'s design before writing anything against it. Every
other phase in this document depends on P0's findings and may need revision once that audit is
actually done — this document describes the *intended* integration, [01](01-current-state-audit.md)-style
verification of the builder's own internals is still owed.

What can be said without that audit, because it follows from decisions already made elsewhere in
this plan rather than from the builder's internals specifically:

- The toast renders inside a sandboxed iframe, signed and integrity-checked exactly like an
  admin-dashboard plugin panel ([08](08-frontend-runtime-and-dynamic-loading.md), reused per its
  own note added for this step).
- It is injected once per published page, for every page, not placed per-page by an editor
  ([06 §2.1](06-extension-points-and-hooks.md)).
- Whatever the actual injection mechanism turns out to be, it must not require a tenant to touch
  the page builder's editor at all — a tenant installs the plugin and activates it
  ([07](07-installation-and-activation-lifecycle.md)); the toast simply starts appearing.

## 4. Manifest

Illustrative, not final (the actual `plugin.json` is a build artifact of the scaffold, per
[15](15-developer-scaffolding-and-local-development.md), not hand-written) — shown here to make
§1–§3 concrete against [04 §2](04-manifest-and-package-format.md)'s actual fields:

```json
{
  "slug": "cookie-consent",
  "name": "Cookie Consent",
  "version": "1.0.0",
  "summary": "A cookie-consent toast for your published website, with acceptance stats.",
  "developer": "…",
  "platform_compat": "^1.0",
  "capabilities": [
    "plugin_data.read",
    "plugin_data.write",
    "plugin_data.increment",
    "plugin_data.list"
  ],
  "hooks": [],
  "data_schema": [
    { "collection": "settings", "description": "Banner text, categories, position, colors", "public": true },
    { "collection": "consent_daily_counts", "description": "Per-day accepted/declined counters" }
  ],
  "frontend": {
    "entry": "frontend/dist/main.js",
    "nav": { "label": "Cookie Consent", "icon": "cookie" },
    "settings_panel": true,
    "site_global": true
  },
  "permissions_requested_summary": "Reads and writes its own settings, and keeps a daily count of accept/decline choices on your public site. Never reads or stores anything about individual visitors."
}
```

Notably **no hooks at all** — this plugin needs none of [06 §1](06-extension-points-and-hooks.md)'s
backend extension points; it is driven entirely by its own settings/stats capability calls and the
public consent-recording path. A good illustration that a plugin's manifest is only as large as
what it actually needs — four low-risk capabilities, nothing at `http.egress`, nothing at
`risk_tier: high` — exactly the minimal-request posture
[09 §3](09-admin-dashboard-ux.md)'s consent screen is designed to make easy to grant with
confidence.

## 5. Admin dashboard UX

Standard application of [09](09-admin-dashboard-ux.md), nothing new required of that document:

- **Settings** ([06 §2](06-extension-points-and-hooks.md)'s `settings.panel` slot): banner text,
  which categories to offer (e.g. "necessary," "analytics," "marketing" — presentation only, since
  §1 explicitly doesn't gate scripts on the choice), toast position and theme colors. Reads/writes
  the `settings` collection via `plugin_data.read`/`write`.
- **Stats**: a simple view (its own nav-linked page, or a `dashboard.widget` card linking to one)
  showing accepted/declined counts by day over a selectable range, a running total, and an
  acceptance percentage — computed by reading every `consent_daily_counts` row in the selected
  range with **one** `plugin_data.list` call ([05 §4](05-security-and-sandboxing.md)) and summing
  them client-side, not thirty separate `plugin_data.read` calls for a thirty-day range and not any
  new server-side aggregation capability; the daily granularity chosen in §2 keeps even a full
  year's list small enough to sum in the browser.

## 6. Building it with the scaffold

The intended developer flow, per [15](15-developer-scaffolding-and-local-development.md):

1. `plugin-cli new cookie-consent` — generates the layout in
   [15 §2](15-developer-scaffolding-and-local-development.md), pre-filled with this plugin's slug.
2. Fill in `plugin.json`'s `capabilities`, `data_schema`, and `frontend` block per §4 above.
3. Write the settings-panel and stats views against the local mock SDK
   ([15 §3](15-developer-scaffolding-and-local-development.md)), seeded with fixture
   `consent_daily_counts` rows in `dev.fixtures.json` so the stats view has something to render
   locally without a real tenant.
4. Write the `site.global` toast against the **public-slot local preview**
   ([15 §4](15-developer-scaffolding-and-local-development.md)'s addition for this step) — the
   fixture public-page mode, where only `plugin_data.increment` is reachable from the mock, the
   same restriction the real public endpoint enforces
   ([05 §12](05-security-and-sandboxing.md)).
5. `plugin-cli check` — the local lint ([15 §6](15-developer-scaffolding-and-local-development.md))
   confirms `plugin_data.increment` is the only capability the toast's code path actually calls,
   matching the manifest.
6. `plugin-cli build` — packages exactly [04 §1](04-manifest-and-package-format.md)'s layout,
   ready for submission ([10 §1](10-review-and-publishing-pipeline.md)).

## 7. Security notes specific to this plugin

Mostly a recap of general mechanisms, applied:

- The public consent-recording call resolves *which install* from the plugin's own slug in the
  URL path, against this deployment's own local table — never from anything the toast's own
  script sends ([05 §12](05-security-and-sandboxing.md)). There is no "another tenant's counter"
  a compromised bundle could even attempt to reach: this deployment's database is the only one
  the request could possibly be answered from ([00 §0](00-overview.md)).
- From the public path, the toast can do exactly two things: **read** the `settings` collection
  (declared `public: true`, §4) to render its banner text, and **increment** a
  `consent_daily_counts` counter. `plugin_data.write`, and `plugin_data.read` against
  `consent_daily_counts` (used by the admin stats screen), are **not** reachable from the public
  site at all — a visitor can read the one collection meant for them and record a choice, nothing
  more, even though the plugin's manifest also declares broader capabilities for its admin-side
  screens ([05 §12](05-security-and-sandboxing.md)'s per-collection carve-out, not a blanket grant).
- The toast's iframe on the public page carries the same CSP as an admin-dashboard plugin panel
  ([08](08-frontend-runtime-and-dynamic-loading.md)) — no `img-src`/`style-src` exfiltration path,
  no cookie/`localStorage` access — which matters more here, not less, because the audience is
  every visitor to a real tenant's live site.
- No PII, no per-visitor identifier, nothing beyond a daily aggregate count is ever stored — not
  just a privacy nicety but a direct reduction in what a compromised release could possibly expose
  even in the worst case.

## 8. Concrete walkthrough: how a visitor's choice gets saved

### 8.0 Quick reference: the whole loop, save and retrieve

**Save** — visitor clicks Accept on the toast:

```ts
// frontend/src/toast.ts — the plugin's own code, the only code it ships (§8.4)
async function onAccept(host: PluginHost) {
  const today = new Date().toISOString().slice(0, 10);
  await host.call('plugin_data.increment', {
    collection: 'consent_daily_counts',
    key: today,
    field: 'accepted',
    amount: 1,
  });
}
```

```php
// Platform code that call reaches — not plugin code, see §8.4
// No tenant parameter: this code runs inside one tenant's own deployment,
// on that deployment's own database, so $installId alone identifies the row.
public function increment(string $installId, string $collection,
    string $recordKey, string $field, int $amount): void
{
    $install = PluginInstall::find($installId)->activeOrFail();
    $decl = $install->currentRelease()->manifest()->dataSchemaCollection($collection);

    if (! in_array($field, $decl->increment->fields, true)) {
        throw new UndeclaredFieldException($field);
    }
    if ($amount < 1 || $amount > $decl->increment->maxAmount) {
        throw new AmountOutOfBoundsException($amount);
    }

    PluginData::query()
        ->where('install_id', $install->id)
        ->where('collection', $collection)
        ->where('record_key', $recordKey)
        ->increment("payload_json->{$field}", $amount);
}
```

**What's in the table afterward:**

```
install_id | collection             | record_key | payload_json
501        | consent_daily_counts   | 2026-09-27 | {"accepted": 42, "declined": 6}
```

**Retrieve** — admin stats screen loads the last 30 days:

```ts
async function loadStats(host: PluginHost, from: string, to: string) {
  const rows = await host.call('plugin_data.list', {
    collection: 'consent_daily_counts', keyFrom: from, keyTo: to,
  });
  return Object.values(rows).reduce(
    (t, d) => ({ accepted: t.accepted + d.accepted, declined: t.declined + d.declined }),
    { accepted: 0, declined: 0 },
  );
}
```

```php
public function list(string $installId, string $collection,
    string $keyFrom, string $keyTo): array
{
    $install = PluginInstall::find($installId)->activeOrFail();
    $install->currentRelease()->manifest()->dataSchemaCollection($collection);

    return PluginData::query()
        ->where('install_id', $install->id)
        ->where('collection', $collection)
        ->whereBetween('record_key', [$keyFrom, $keyTo])
        ->orderBy('record_key')
        ->limit(366)
        ->pluck('payload_json', 'record_key');
}
```

**Who writes what:** the TypeScript is this plugin's own code. The PHP is platform code the
developer never writes — see §8.4's opening note. Note there's no tenant parameter anywhere —
this code already runs inside one tenant's own deployment, on that deployment's own database
([00 §0](00-overview.md)), so `install_id` alone is enough to identify the row. §8.1–§8.6 below is
the same loop with the install-resolution and dispatcher-check detail spelled out in full.

### 8.1 The admin saves a setting (authenticated path, 08 §4)

Tenant admin opens **Plugins → Cookie Consent → Settings**, changes the banner text, clicks Save.

1. The settings panel (rendered in the admin-dashboard iframe, [08 §3](08-frontend-runtime-and-dynamic-loading.md))
   has no `connect-src` of its own — it `postMessage`s the new text to the admin shell.
2. The admin shell relays this, with the tenant's session, to the capability API as a
   `plugin_data.write` call: `{ collection: "settings", key: "banner", payload: { text: "…" } }`.
3. The dispatcher checks: is this install active, does its grant set include `plugin_data.write`,
   is `settings` a collection this release's manifest actually declared (§4)? All yes.
4. One row is written/updated in `plugin_data`: `install_id` = this install,
   `collection = "settings"`, `record_key = "banner"`, `payload_json = { "text": "…" }`.
5. The panel gets a success response over the same `postMessage` channel; nothing about this
   request ever touched anything but this one plugin's one collection, in this one deployment.

### 8.2 A visitor accepts the cookie banner (public path, 05 §12)

Anonymous visitor lands on the tenant's published site, sees the toast, clicks **Accept**.

1. On mount, the toast iframe reads its banner text: a `plugin_data.read` call against `settings`
   — reachable here *only* because `settings` is declared `public: true` (§4,
   [04 §4](04-manifest-and-package-format.md)). The dispatcher resolves *which install* from the
   plugin's own slug in the endpoint's URL path, against this deployment's own local
   `laravel_plugin_installs` table — not from anything the toast sent — then checks the
   collection's `public` flag before allowing the read.
2. Visitor clicks Accept. The toast calls its own public endpoint directly (§7's `connect-src`
   note, [08](08-frontend-runtime-and-dynamic-loading.md)): a `plugin_data.increment` call —
   `{ collection: "consent_daily_counts", key: "2026-09-27", field: "accepted", amount: 1 }`.
3. The dispatcher checks: is `plugin_data.increment` marked `reachable_from_public_site`? Yes,
   unconditionally. The install, again, comes from the URL's own slug, resolved locally. No
   session, no `install_id` field in the body to trust or distrust, because none was ever sent or
   needed.
4. One row in `plugin_data` is atomically updated: `install_id` = this install,
   `collection = "consent_daily_counts"`, `record_key = "2026-09-27"`,
   `payload_json = { "accepted": 41, "declined": 6 }` → `{ "accepted": 42, "declined": 6 }`. No
   row was created for this visitor specifically — there is no "this visitor" in the data at all,
   only a number that moved by one.

### 8.3 The admin views stats (reading it back)

Tenant admin opens **Plugins → Cookie Consent → Stats**, picks "last 30 days". The view issues
**one** `plugin_data.list` call against `consent_daily_counts` for that key range (via the
authenticated admin path, same as §8.1's write) and sums `accepted`/`declined` across whatever
rows come back to render the chart and percentage. The same collection a visitor just incremented
anonymously is read back only by the tenant's own authenticated admin session — the public path
could never read it, only write to it (§7), which is exactly the asymmetry a "visitors write, only
the tenant reads the results" statistic should have.

### 8.4 The code, concretely

Illustrative — showing the shape this takes, not literal source being added to the codebase now
(no code exists yet; this is a plan). Two things worth being explicit about first:

- **This plugin ships no backend PHP of its own.** `plugin_data.read`/`write`/`increment` are
  generic platform capabilities — the Plugin Runtime performs them directly against the
  `plugin_data` table once it has checked the manifest's declarations, without invoking any
  plugin-authored code. A plugin only needs its own backend file when it subscribes to a *hook*
  ([06 §3](06-extension-points-and-hooks.md)), and this plugin has none (§4). So the "code" for
  this plugin is entirely: its manifest (§4), and its frontend bundle (below) — which is also why
  its review surface is so small (10 §3 has almost nothing of the plugin's *own* logic to scan).
- What follows for step 2 of §8.2 is the toast's own TypeScript, and what follows for steps 3–4 is
  the **platform's** generic capability handler — the same code path every plugin's
  `plugin_data.increment` call goes through, not something written per plugin.

**The toast's frontend (`frontend/src/toast.ts`, part of this plugin's own bundle):**

```ts
// Runs inside the site.global iframe on the tenant's published page (06 §2.1).
// `host` is the narrow RPC client generated from plugin-host.d.ts (08 §5) — its
// only capability here is plugin_data.increment, per this plugin's manifest (§4).

async function onAccept(host: PluginHost): Promise<void> {
  const today = new Date().toISOString().slice(0, 10); // "2026-09-27"

  await host.call('plugin_data.increment', {
    collection: 'consent_daily_counts',
    key: today,
    field: 'accepted',
    amount: 1,
  });

  hideToast();
}
```

**The platform's generic increment handler** (not plugin code — the same handler every plugin's
`plugin_data.increment` call reaches, illustrating the checks [05 §12](05-security-and-sandboxing.md)
and [04 §4](04-manifest-and-package-format.md) describe in prose):

```php
/**
 * PLUGIN_SYSTEM_ADDITION — core platform code, not plugin-authored.
 * Reached from both the admin-authenticated relay (08 §4) and the public
 * unauthenticated endpoint (05 §12). No tenant parameter anywhere: this code
 * runs inside one tenant's own deployment, on that deployment's own local
 * database (00 §0) — $installId alone is enough to identify the row, and
 * there is no other tenant's row this database could even hold.
 */
public function increment(
    string $installId,
    string $collection,
    string $recordKey,
    string $field,
    int $amount,
): void {
    $install = PluginInstall::find($installId)->activeOrFail();

    $manifest = $install->currentRelease()->manifest();

    $collectionDecl = $manifest->dataSchemaCollection($collection)
        ?? throw new UndeclaredCollectionException($collection);

    $incrementDecl = $collectionDecl->increment
        ?? throw new CapabilityMisuseException(
            "《{$collection}》 has no increment declaration"
        );

    if (! in_array($field, $incrementDecl->fields, strict: true)) {
        throw new UndeclaredFieldException($field); // rejected — 05 §12, 04 §4
    }

    if ($amount < 1 || $amount > $incrementDecl->maxAmount) {
        throw new AmountOutOfBoundsException($amount); // rejected — same section
    }

    PluginData::query()
        ->where('install_id', $install->id)
        ->where('collection', $collection)
        ->where('record_key', $recordKey)
        ->increment("payload_json->{$field}", $amount); // one atomic SQL UPDATE
}
```

**The public endpoint that calls it** (also core platform code — this is *where* the request's
own slug identifies which install, per [05 §12](05-security-and-sandboxing.md)):

```php
/**
 * PLUGIN_SYSTEM_ADDITION — POST /plugins/{slug}/public/consent
 * No auth middleware. Which install this is comes from {slug} in the route
 * itself, resolved against this deployment's own local table — never from
 * anything in $request's body. There is no tenant to resolve at all: this
 * endpoint only ever answers for the one tenant it's deployed as (00 §0).
 */
public function recordPublicConsent(Request $request, string $slug): JsonResponse
{
    $capability = PluginCapability::find('plugin_data.increment');
    abort_unless($capability->reachableFromPublicSite, 403);

    $install = PluginInstall::findBySlug($slug)->activeOrFail();

    app(PluginDataService::class)->increment(
        installId: $install->id,
        collection: $request->string('collection'),
        recordKey: $request->string('key'),
        field: $request->string('field'),
        amount: $request->integer('amount'),
    );

    return response()->json(['ok' => true]);
}
```

Every `throw` above is a rejection with no partial write — an attacker POSTing directly to this
endpoint with `field: "something_else"` or `amount: 999999` gets an error and nothing in
`plugin_data` changes, regardless of what the legitimate toast script would ever have sent.

### 8.5 What's actually in the database after all this

One table, `plugin_data` ([03 §3](03-database-schema.md)), no columns or rows special-cased for
this plugin — this is the entire footprint of everything §8.1–§8.4 did, for one tenant, after a
few days of the plugin being live:

| `install_id` | `collection` | `record_key` | `payload_json` |
|---|---|---|---|
| 501 | `settings` | `banner` | `{"text": "We use cookies to improve your experience."}` |
| 501 | `consent_daily_counts` | `2026-09-25` | `{"accepted": 310, "declined": 47}` |
| 501 | `consent_daily_counts` | `2026-09-26` | `{"accepted": 340, "declined": 58}` |
| 501 | `consent_daily_counts` | `2026-09-27` | `{"accepted": 42, "declined": 6}` |

That is the complete data model for this plugin: one settings row, and one small row per day.
Three rows or three hundred, nothing here scales into a "should this really be SQLite" question —
which was the point of §2.

### 8.6 Retrieving it: the generic `read` and `list` handlers

Same pattern as §8.4's `increment` — this is core platform code every plugin's reads go through,
not code this plugin ships:

```php
/** PLUGIN_SYSTEM_ADDITION — core platform code. Backs plugin_data.read. No
 *  tenant parameter, same reasoning as increment() in §8.4. */
public function read(
    string $installId,
    string $collection,
    string $recordKey,
    bool $isPublicInvocation,
): ?array {
    $install = PluginInstall::find($installId)->activeOrFail();

    $collectionDecl = $install->currentRelease()->manifest()
        ->dataSchemaCollection($collection)
        ?? throw new UndeclaredCollectionException($collection);

    if ($isPublicInvocation && ! $collectionDecl->public) {
        throw new CapabilityMisuseException("《{$collection}》 is not public"); // 05 §12
    }

    return PluginData::query()
        ->where('install_id', $install->id)
        ->where('collection', $collection)
        ->where('record_key', $recordKey)
        ->value('payload_json');
}

/** PLUGIN_SYSTEM_ADDITION — core platform code. Backs plugin_data.list. Admin-only (05 §4: not reachable_from_public_site). */
public function list(
    string $installId,
    string $collection,
    string $keyFrom,
    string $keyTo,
): array {
    $install = PluginInstall::find($installId)->activeOrFail();

    $install->currentRelease()->manifest()->dataSchemaCollection($collection)
        ?? throw new UndeclaredCollectionException($collection);

    return PluginData::query()
        ->where('install_id', $install->id)
        ->where('collection', $collection)
        ->whereBetween('record_key', [$keyFrom, $keyTo])
        ->orderBy('record_key')
        ->limit(366) // a bound, not an arbitrary query — 05 §4
        ->pluck('payload_json', 'record_key');
}
```

**The toast reads its own settings on mount** (public path — reachable only because `settings` is
`public: true`, §4):

```ts
async function onMount(host: PluginHost): Promise<void> {
  const settings = await host.call('plugin_data.read', {
    collection: 'settings',
    key: 'banner',
  });

  renderToast(settings?.text ?? DEFAULT_BANNER_TEXT);
}
```

**The admin stats screen reads and sums a range** (admin path — one call, not thirty):

```ts
async function loadStats(
  host: PluginHost,
  fromDate: string,
  toDate: string,
): Promise<{ accepted: number; declined: number }> {
  const rows = await host.call('plugin_data.list', {
    collection: 'consent_daily_counts',
    keyFrom: fromDate,
    keyTo: toDate,
  });
  // rows: { "2026-09-25": {accepted:310,declined:47}, "2026-09-26": {...}, ... }

  return Object.values(rows).reduce(
    (totals, day) => ({
      accepted: totals.accepted + (day.accepted ?? 0),
      declined: totals.declined + (day.declined ?? 0),
    }),
    { accepted: 0, declined: 0 },
  );
}
```

Same round trip as §8.4, in reverse: a capability call → the dispatcher checks the install, the
declared collection, and (for the public case) the `public` flag → one bounded, indexed read
against `plugin_data` → a plain object back to whichever side asked, with no query language and no
row the caller wasn't already scoped to ever having existed.

## Phases

| Phase | Deliverable | Done when |
|---|---|---|
| **P0** | Audit `resources/js/portal/builder`'s published-page render pipeline; confirm the actual injection point for a `site.global` slot | A concrete, verified answer to "where does this element actually get inserted," replacing §3's stated assumption |
| **P1** | Scaffold the plugin ([15](15-developer-scaffolding-and-local-development.md)); settings and stats screens against the local mock SDK | Settings save/load and the stats view render correctly against fixture data, entirely offline |
| **P2** | The toast's frontend against the public-slot local preview; `plugin_data.increment` wired to the daily counter | A local "accept"/"decline" click increments the correct fixture day's counter and nothing else is reachable from that mode |
| **P3** | Submit through the real pipeline ([10](10-review-and-publishing-pipeline.md)) as an internal dogfood plugin (not yet public) | Clears automated intake and manual review; a signed release exists |
| **P4** | Install on a real (or lab) tenant, end to end | The toast appears on the actual published site, a real accept/decline updates the real counter, and the admin stats view reflects it |
| **P5** | Feed results back into [14 §4](14-rollout-and-testing.md)'s "Public site slot" test-matrix row | The public-site-slot test coverage that row describes is exercised against this real plugin, not only a synthetic one |
