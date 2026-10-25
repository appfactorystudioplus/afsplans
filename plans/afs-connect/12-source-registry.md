# 12 — The Source Registry

> **Status:** specification for the work planned as **step 60** in the Joystick
> engine plan repo (`JoystickEngine_v2/plan/steps/60_afs_connect_platform.md`).
> Its first consumer is **step 61** (the Joystick game engine as a `hosted`
> source).

---

## 12.1 The problem

Adding one source app to afs-connect currently touches, at minimum:

| # | Location |
|---|---|
| 1 | `config/afs_connect.php` → `source_apps` |
| 2 | `config/afs_connect.php` → `www_source_apps` (www modules only) |
| 3 | `config/afs_connect.php` → `source_app_base_urls` |
| 4 | `config/afs_connect.php` → `peer_keys` |
| 5 | `SourceAppAfsConnectClient::SOURCE_APPS` |
| 6 | an `afsconnect_settings` column migration |
| 7 | `AfsConnectSettingsController` validation |
| 8 | `AfsConnectEntitlementService`'s source map |
| 9 | the PM frontend label map |
| 10 | the Brain admin tab |
| 11 | the members settings UI (`apps/members/resources/js/settings/afsConnectSettings.ts` — a hardcoded `SOURCE_APPS` array that is already wrong: it omits every `www_*` module) |

Eleven places, **no cross-check**, and they are not equally forgiving. Location 5 — a bare `const` array on the outbound client class — is what `SourceAppAfsConnectClientRegistry` iterates, so it alone decides whether backfill, reconcile, and live item fetch see the source at all. Omit it and everything *looks* wired: webhooks arrive, PM displays rows, search works. Only the pull path is missing, so the failure surfaces days later as "the index is stale", with no error anywhere pointing at the cause.

## 12.2 The registry

One array of descriptors, in coordinator, from which every consumer derives.

```php
<?php
// apps/coordinator/config/afs_connect_sources.php

/**
 * The afs-connect source registry — the ONE place a source app is declared.
 *
 * Everything derives from this array: the outbound client registry, the
 * settings validator, the entitlement source map, the alloc_v2 meter keys,
 * the PM frontend label map, and both admin UIs. Adding a source is one entry
 * here, plus (for `provider` sources only) an env var holding its base URL.
 *
 * WHY: registration used to be spread across eleven locations with no
 * cross-check, exactly one of which silently governed whether the source was
 * ever pulled from. §12.5's consistency test converts that entire class of
 * mistake from a silent, days-later outage into a failing CI run.
 *
 * @see 11-billing-and-allocation.md   for the `meter` and `min_tier_index` fields
 * @see JoystickEngine_v2/plan/steps/60_afs_connect_platform.md  (build order)
 */
return [
    'sources' => [

        /**
         * @var string $key          Stable identifier. PERSISTED in
         *                           afsconnect_index.source_app,
         *                           afsconnect_links.source_app and the settings
         *                           JSON maps. Never rename without a data
         *                           migration.
         * @var string $label        Human-readable; shown in PM and both admin UIs.
         * @var string $kind         'provider' | 'hosted' | 'meta' — see §12.3.
         * @var string $base_url_env Env var holding the internal API base URL.
         *                           MUST be empty for 'hosted' (§12.5).
         * @var string $group        Settings-UI grouping: 'creative' | 'www' | 'engine'.
         * @var bool   $default_on   Whether a newly-enabled member gets this on.
         * @var int    $min_tier_index  Lowest tier INDEX (0-4) that may use it. Index, never a
         *                           name — labels are cosmetic, the index is the contract
         *                           (11-billing-and-allocation.md §11.3).
         * @var string $meter        alloc_v2 resource id metered for its index rows.
         */
        'notes' => [
            'key' => 'notes',
            'label' => 'Notes',
            'kind' => 'provider',
            'base_url_env' => 'AFS_CONNECT_NOTES_BASE_URL',
            'group' => 'creative',
            'default_on' => true,
            'min_tier_index' => 1,
            'meter' => 'afs_connect_index_rows',
        ],

        // flow / dream_graph / pdf_editor  — identical shape
        // www_forms … www_events          — kind: 'meta',   group: 'www'
        // joystick_engine                 — kind: 'hosted', group: 'engine'
    ],
];
```

Derivations replacing the eleven sites:

| Was | Becomes |
|---|---|
| `config('afs_connect.source_apps')` | `SourceRegistry::keys()` |
| `config('afs_connect.www_source_apps')` | `SourceRegistry::keysByGroup('www')` |
| `SourceAppAfsConnectClient::SOURCE_APPS` | `SourceRegistry::keysByKind('provider')` |
| `config('afs_connect.source_app_base_urls.*')` | `SourceRegistry::baseUrl($key)` |
| settings columns | keys of the `sources_enabled` JSON map (§12.4) |
| PM / Brain / members label maps | `GET /api/afs-connect/sources` |

## 12.3 Source kinds

```php
/**
 * 'provider' — a web app that SERVES /summary, /changes, /items/{id}, /search.
 *              Coordinator PULLS from it (notes, flow, dream_graph, pdf_editor).
 *              Requires a base URL and a peer key.
 *
 * 'meta'     — one app fronting many tenant databases (www / Brain). Same pull
 *              contract as 'provider', but items carry website_domain and
 *              website_reference_num, and Brain-admin-only module toggles apply.
 *
 * 'hosted'   — a client that CANNOT serve HTTP (a desktop editor, a CLI, CI).
 *              It PUSHES, and coordinator stores its data directly. There is
 *              nothing to pull because coordinator already holds everything.
 *              Requires NO base URL and NO peer key.
 */
```

### 12.3.1 Why `hosted` exists

Every source before now was a Laravel app, so "coordinator pulls" was a safe universal assumption. The Joystick game engine breaks it: a desktop editor on a developer's laptop is not addressable, is not running most of the time, and sits behind NAT. It also cannot safely hold `AFS_CONNECT_SERVICE_KEY`, since that secret would ship inside a public download.

An earlier design answered this with a **relay** — a web app registered as the source app, mirroring what the desktop client reported. It worked, and it was rejected: it gave an unrelated app a second personality, and it duplicated coordinator's own store purely to satisfy the shape of the pull contract.

`hosted` is the honest answer, and it generalizes past the engine — any future CLI, mobile app, or CI integration is `hosted`:

- **Reconcile and backfill skip hosted sources.** Not an engine special case: pulling from a source with no HTTP surface is meaningless by definition.
- **Live `items/{id}` reads coordinator's own store** rather than proxying, so the PM item drawer works with the client switched off.
- **Idempotency comes from full-state snapshots with a content hash**, not incremental events — self-healing in exactly the way reconcile is for provider sources.
- **Authentication is per-user, not per-app.** A hosted client presents a Checkpoint access token; coordinator derives `member_reference_num` from the validated identity and never accepts it from the client. No mesh secret ever reaches a desktop binary.

## 12.4 Settings: privacy and packaging are different axes

`afsconnect_settings`' column-per-source design (`source_notes`, `source_flow`, … — twelve booleans) is replaced by two JSON maps keyed by registry key. Note the same table already stores `disabled_website_domains` as `json`, so both idioms were present and the rigid one had been applied to the axis that actually grows.

```php
/**
 *   sources_enabled : Record<sourceKey, bool>
 *       The MEMBER's privacy choice — "may afs-connect index data from this
 *       app?". Owned by the member (or a Brain admin acting for them).
 *
 *   sources_allowed : Record<sourceKey, bool>
 *       The PLAN's packaging — "does this member's tier include this source?".
 *       DERIVED from the registry's min_tier_index and the member's tier index, recomputed
 *       on tier change. Never edited by a member.
 *
 * A source is live only when allowed AND enabled.
 *
 * Keeping them separate buys two properties that are impossible with one flag:
 *
 *   1. Downgrade is non-destructive. A member dropping below a source's
 *      min_tier_index keeps sources_enabled intact; only sources_allowed flips.
 *      Re-upgrading restores their exact prior configuration.
 *
 *   2. The upsell is honest. The UI renders a disabled row with an upgrade
 *      label instead of an unchecked box that looks like the member's own
 *      choice. apps/members' afsConnectSettings.ts currently has only the
 *      two-state rendering and therefore cannot tell the member which it is.
 *
 * Migration: each legacy source_<key> column becomes sources_enabled[<key>];
 * sources_allowed is computed from the member's tier at migration time. The
 * old columns are dropped in a SEPARATE later migration after one release of
 * dual-read, so a rollback cannot lose member preferences.
 */
```

## 12.5 The consistency test

A registry only helps if nothing can drift from it.

```php
/**
 * Guards the registry against the failure mode that motivated it: a source
 * declared in one place and forgotten in another. Every assertion maps to a
 * real omission that would otherwise be invisible in production.
 */
public function test_source_registry_is_internally_consistent(): void
{
    foreach (SourceRegistry::all() as $key => $source) {

        // A provider coordinator cannot reach never reconciles — silently.
        if (in_array($source['kind'], ['provider', 'meta'], true)) {
            $this->assertNotSame('', SourceRegistry::baseUrl($key));
            $this->assertArrayHasKey($key, config('afs_connect.peer_keys'));
        }

        // A hosted source has no HTTP surface; a stray base URL would make the
        // aggregator try to pull from it and log failures forever.
        if ($source['kind'] === 'hosted') {
            $this->assertSame('', SourceRegistry::baseUrl($key));
        }

        // A source metering nothing is invisible to billing (doc 11).
        $this->assertTrue(AllocRegistry::has($source['meter']));

        // An out-of-range min_tier_index silently allows everyone.
        $this->assertGreaterThanOrEqual(0, $source['min_tier_index']);
        $this->assertLessThanOrEqual(4, $source['min_tier_index']);
    }
}
```

## 12.6 Adding a source, after this lands

1. One entry in `afs_connect_sources.php`.
2. For `provider`/`meta` only: the base-URL env var and a peer key.
3. Run the test.

Everything else — settings, entitlement, metering, labels, both admin UIs, and the pull/skip decision — follows from the entry. Step 61's `joystick_engine` is the first source added this way, and its registration is a single block with no migration, no service key, and no base URL.
