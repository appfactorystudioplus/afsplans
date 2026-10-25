# Step 18 — Joystick Engine: the Service Assets Market

**On completion:** write `complete/18_joystick_engine_service_assets_market_complete.md`, **and**
append the same report to
`JoystickEngine_v2/plan/_guides/steps_plan_execution/something24-step-90-service_assets_market.md`
(that repo's own convention — append, never overwrite).

**Mirror:** this step is duplicated as
`JoystickEngine_v2/plan/steps/090_service_assets_market.md` so it appears in the engine's step
index. **Execution happens here.** The engine copy is a mirror; if the two diverge, this file wins.

**Depends on:** [03](03_market_database_schema.md), [05](05_asset_storage_and_upload_pipeline.md),
[13](13_market_cart_and_checkout.md), [17](17_seed_data.md). Nothing to install without a purchase.

**Testing:** never build the `Sandbox` / `JoystickSandbox` target or anything on the legacy
non-Filament rail. Interactive verification uses `~/Documents/joystick_projects/MyFirstProject`.

---

## 18.1 What is being built

> *"the system will be like Unity's Asset Store and the editor's Package Manager. But instead of
> using the name Package Manager, name my system Service Assets Market and place it in the Windows
> menu item so Windows/Service Assets Market and basically just duplicate Unity's Package Manager
> and apply it to our system."*

`Windows ▸ Service Assets Market` — a dockable editor panel with Unity's Package Manager layout and
behaviour, backed by market entitlements.

Unity's Package Manager is worth copying closely because its shape solves real problems: a left
list scoped by a source dropdown, a right detail pane with a version selector, an explicit
install/remove action rather than drag-and-drop, and a per-project lockfile that makes the installed
set reproducible. All four are load-bearing, and all four are reproduced.

---

## 18.2 Menu placement — no new machinery needed

`JoystickEditorCore/Source/EngineMenus.cs` already builds the Windows menu dynamically:
`RegisterDynamicItems()` walks `MenuBridge_PanelCount()` and, for each panel, calls
`TopLevelOverridePathFor(name)` — which promotes a panel out of `Windows/Panels/` onto its own path.
It already contains:

```csharp
"Connect"          => "Settings/Connect",
"Dream Graph Sync" => "Settings/Dream Graph Sync",
```

So the entire menu change is one line:

```csharp
"Service Assets Market" => "Windows/Service Assets Market",
```

Priority band: `WindowsBase - 1`, so it sorts above `Windows/Panels/`. The panel is a destination,
not one of a list of panes.

> **A `[MenuItem]` attribute cannot be used here.** `[MenuItem]` paths must be compile-time
> constants and therefore cannot carry a `JS_TR` lookup; the C++ `DrawMenuNodes` resolves
> `menu.<path>` through a `HasKey`-gated fallback instead. The localization for this entry goes into
> the lang files under that key, not into the attribute.

---

## 18.3 Configuration

Step 02 adds the `market` section to `Resources/EngineConfig.json` and a `MarketSection` to
`Joystick/src/Joystick/Core/EngineConfig.h`. This step consumes them.

`Market().IsConfigured()` false ⇒ the panel is not registered and the menu item does not appear.
Same degradation `afsConnect` already implements. No error, no dialog, no half-working panel.

**No credential in that file, ever.** The Checkpoint session lives in the per-user session file that
`Joystick::ConnectIdentity` already polls. That is where the bearer token for market requests comes
from.

---

## 18.4 The panel

`JoystickWindow/src/Panels/ServiceAssetsMarketPanel.{h,cpp}`.

Registered as `PanelId::ServiceAssetsMarket`, appended **before `Count`** in the enum at
`JoystickWindow/src/EditorLayer.h:249`, with `PanelName()` returning the translated
`"Service Assets Market"`. Follows the `ConnectPanel` / `DreamGraphSyncPanel` convention: a
`DrawContent()` that draws no `ImGui::Begin/End` — the caller owns the window.

### Layout

```
┌───────────────────────────────────────────────────────────────────────┐
│ [Packages: My Assets ▾]  [🔍 Search        ]           [⟳] [⚙]        │
├──────────────────────┬────────────────────────────────────────────────┤
│ ▸ In Project     (3) │  Sci-Fi Prop Pack Vol. 1                       │
│   Sci-Fi Props   1.0 │  Studio Name · com.studio.scifiprops            │
│   UI Kit — Neon  2.1 │  ┌──────────────────────────────────────────┐  │
│   Ambient Loops  1.0 │  │            gallery image                  │  │
│                      │  └──────────────────────────────────────────┘  │
│ ▸ My Assets      (6) │  Version  [1.1.0 ▾]   Installed 1.0.0          │
│   Pixel Tileset      │  ────────────────────────────────────────────  │
│   Character Ctrl  ⬆  │  Description · Changelog · Files · License ·    │
│   Particle FX        │  Dependencies                                   │
│                      │                                                 │
│ ▸ Updates        (1) │  [ Update to 1.1.0 ]  [ Remove ]  [ View on ↗ ] │
│ ▸ Services       (2) │                                                 │
├──────────────────────┴────────────────────────────────────────────────┤
│ Signed in as member@example.com          Last refreshed 2 minutes ago │
└───────────────────────────────────────────────────────────────────────┘
```

Sources in the dropdown:

| Source | Contents |
|---|---|
| **In Project** | Installed, from the project lockfile |
| **My Assets** | Every owned `joystick_engine` entitlement |
| **Updates** | Installed where a newer version exists |
| **Services** | Purchased services — **read-only**, no install |
| **In Development** | Local packages under `<Project>/Packages/` (Unity parity; a seller testing their own package before upload) |

Detail tabs: Description · Changelog · Files (from `asset_version_files`, so contents are visible
before installing) · License (the entitlement's snapshot) · Dependencies.

### States, each drawn deliberately

| State | Display |
|---|---|
| Not signed in | *"Sign in from the Joystick Launcher to see your purchased assets."* + a button that launches the launcher. **No in-editor login form** — `ConnectPanel`'s established rule, and the launcher's `SignInView` remains the only sign-in UI. |
| Not configured | Panel absent entirely |
| Loading | Skeleton rows, never a modal spinner — the editor must stay usable |
| Empty | *"No purchased assets yet."* + Open Market (system browser) |
| Offline | Cached list, greyed, with a banner; installs disabled, already-cached packages still installable |
| Error | Message + Retry; never a crash, never a silent empty list |

Services render as informational cards: title, seller, order status, due date, and *"Manage on the
Market"* opening the portal in the system browser. Explicitly not installable — a service is work,
not a file.

---

## 18.5 The client

`Joystick/src/Joystick/Market/MarketClient.{h,cpp}`, built on `Joystick/src/Joystick/Net/HttpClient`.

| Call | Endpoint |
|---|---|
| `FetchEntitlements()` | `GET /api/engine/v1/market/entitlements?distribution_tag=joystick_engine` |
| `FetchPackage(name)` | `GET /api/engine/v1/market/packages/{package}` |
| `FetchVersions(name)` | `GET .../versions` |
| `RequestDownload(name, v)` | `GET .../versions/{v}/download` → a short-lived signed URL (step 05) |
| `FetchLicense(name)` | `GET .../license` |
| `FetchServices()` | `GET /api/engine/v1/market/services` |

Auth: the Checkpoint bearer token from `ConnectIdentity`.

**The server filters on `distribution_tag` regardless of the query parameter.** The parameter makes
intent visible in traffic logs; it is not the enforcement. An `other`-tagged asset must be
unreachable from the engine even for the member who owns it, and that must not depend on the client
asking nicely.

### Threading

Follow `ConnectService` exactly: a background worker performs HTTP and disk work; the panel reads an
immutable snapshot on the main thread; the snapshot swaps under a mutex.

`ImGui` draw code must never block on a socket. A 30-second market timeout that freezes the editor
is worse than no feature — and a partially-drawn frame from a snapshot mutated mid-draw is the
crash class that is hardest to reproduce.

Cancellation: closing the panel or quitting mid-download must abort cleanly, with no partial file
left in the cache.

---

## 18.6 Cache and install

### Cache

Per-user, outside any project — the same package installed into three projects downloads once:

```
<user config dir>/JoystickEngine/ServiceAssetsMarket/
├── catalog.json                              entitlements, TTL from config
└── packages/{package_name}/{version}/
    ├── package.zip
    ├── package.sha256
    └── extracted/                            lazily populated
```

sha256 is verified against the server's value **before** extraction, every time. A truncated
download must fail loudly rather than install a broken package — a corrupt install manifests as
mysterious asset-import errors hours later.

### Install target

```
<Project>/Packages/
├── joystick-packages.json           the lockfile
└── com.studio.scifiprops@1.1.0/
    ├── package.json
    ├── Assets/…
    └── LICENSE.txt
```

`Packages/`, not `Assets/`, deliberately — Unity's split, for Unity's reasons:

- Package content is **immutable and not user-authored**. Mixing it into `Assets/` makes "my work"
  and "bought work" indistinguishable in the Content Browser and in version control.
- A user's `.gitignore` can exclude `Packages/*/` and restore from the lockfile, keeping purchased
  binaries out of their repository.
- Uninstall is a directory removal with no risk of deleting user work.

`Project::GetAssetDirectory()` gains a sibling `GetPackagesDirectory()`. The asset pipeline
(`AssetLibrary`, `AssetMeta`) must scan both roots, and `.jmeta` sidecars for package content are
generated into the package directory.

> **This is the largest integration risk in the step.** The whole asset pipeline currently assumes a
> single root. Do this seam **first**, verified with a hand-copied package and no networking at all,
> before any UI work. If the pipeline cannot address two roots, the fallback is to install into
> `Assets/ServiceAssets/<package>@<version>/` and accept the mixing — record that as a deviation
> with its consequences, do not silently pick it.

### Lockfile

`<Project>/Packages/joystick-packages.json`:

```jsonc
{
  "formatVersion": 1,
  "packages": {
    "com.studio.scifiprops": {
      "version": "1.1.0",
      "assetReferenceNum": "as_XXXXXXXX",
      "entitlementReferenceNum": "en_XXXXXXXX",
      "sha256": "…",
      "installedAt": "2026-08-18T12:00:00Z",
      "source": "market"
    }
  }
}
```

Committed to the user's repo. It is what makes a project reproducible: a teammate opening it sees
*"3 packages required, 0 installed"* and one **Restore** button.

Step 89's version-stamping and migration rules apply — `formatVersion` is present from day one so
this file never joins the list of unversioned authored formats.

### Install sequence

1. Resolve version (explicit, or latest satisfying `engine_min_version`).
2. Request the signed URL.
3. Download to the cache with progress; verify sha256.
4. **Conflict check** against `asset_version_files` — refuse and list collisions rather than
   overwriting. Silently overwriting a file a user edited is unforgivable.
5. Extract to a temp directory, then atomically rename into `Packages/`.
6. Generate `.jmeta`, register with `AssetLibrary`.
7. Update the lockfile.
8. Refresh the Content Browser.

Failure at any step leaves nothing behind. Extract-then-rename is what guarantees it.

**Uninstall**: unregister assets, remove the directory, update the lockfile, warn (with a list) if
scenes reference assets from the package — removing a package a scene depends on breaks the scene,
and the user should decide that knowingly.

**Update**: install the new version, migrate the lockfile, remove the old — in that order, so a
failed update leaves the working version in place.

---

## 18.7 Localization

Every user-visible string is a `JS_TR` key, added to **both** `JoystickWindow/Resources/lang/en.json`
and `es.json`. The two files must remain at exact key parity; adding to one only is a defect.

Namespace: `market.*` — `market.title`, `market.source.in_project`, `market.source.my_assets`,
`market.action.install`, `market.state.signed_out`, `market.error.download_failed`, and so on.
The menu path key goes under `menu.Windows/Service Assets Market` per §18.2.

---

## 18.8 Files

> **No `MARKET_MODIFIER_ADDITION` tagging in this step.** The marker is a three-Laravel-project
> convention (brain, members, market) and **does not apply to `JoystickEngine_v2`**. The engine has
> its own established documentation conventions — the dense `/** @file … */` headers seen throughout
> `JoystickWindow/src/Panels/` — and this work follows those instead. Nothing in this repository
> carries the marker, and `Resources/EngineConfig.json` gets **no** `"_marketModifierAddition"` key.
>
> The engine's own change record is its plan step and the execution log named in the header, not a
> grep marker.


**Created:**
`JoystickWindow/src/Panels/ServiceAssetsMarketPanel.{h,cpp}`,
`Joystick/src/Joystick/Market/MarketClient.{h,cpp}`,
`.../MarketCatalog.{h,cpp}` (snapshot model),
`.../PackageCache.{h,cpp}`,
`.../PackageInstaller.{h,cpp}`,
`.../PackageManifest.{h,cpp}` (`package.json` + lockfile read/write),
`.../MarketService.{h,cpp}` (background worker, `ConnectService`-shaped).

**Modified:**
`Resources/EngineConfig.json` (step 02),
`Joystick/src/Joystick/Core/EngineConfig.{h,cpp}` (step 02),
`JoystickWindow/src/EditorLayer.{h,cpp}` (PanelId, PanelName, panel construction and draw),
`JoystickEditorCore/Source/EngineMenus.cs` (the `TopLevelOverridePathFor` line),
`Joystick/src/Joystick/Project/Project.h` (`GetPackagesDirectory()`),
`Joystick/src/Joystick/Asset/AssetLibrary.cpp` (dual-root scan),
`JoystickWindow/src/Panels/ContentBrowserPanel.cpp` (show the Packages root, read-only),
`JoystickWindow/Resources/lang/{en,es}.json`,
`Joystick/CMakeLists.txt`, `JoystickWindow/CMakeLists.txt`.

Documented per the engine's own conventions — a `/** @file … */` header on every new file explaining
purpose and reasoning, matching the density in `JoystickWindow/src/Panels/ConnectPanel.h`. **No
`MARKET_MODIFIER_ADDITION` markers**; that convention stops at the Laravel projects.

---

## 18.9 Market-side endpoints

New route group in market, `routes/engine.php`, prefix `api/engine/v1/market`, bearer-token
authenticated against Checkpoint.

Every query goes through `Entitlement::scopeActive()` + `scopeForEngine()` (step 03). No controller
hand-writes the tag filter.

Download requests reuse step 05's signed-URL mechanism unchanged, with `source = 'engine'` recorded
on the `asset_downloads` row so engine installs are distinguishable from portal downloads in
reporting.

---

## 18.10 Explicit non-goals

Named so they are not attempted under schedule pressure, and not mistaken for oversights:

- **No in-editor purchasing.** Browse and buy on the website. Payment UI inside an ImGui panel means
  card handling in a C++ desktop app — a scope and compliance problem with no matching benefit.
- **No in-editor sign-in.** The launcher owns it (`ConnectPanel`'s existing rule).
- **No package dependency resolution.** The tab shows declared dependencies; it does not resolve
  them transitively. Version solving is a project in itself and there is no dependency data to solve
  against yet.
- **No package publishing from the editor.** Sellers publish from members (step 06).
- **No auto-update.** Updates are always explicit. A package changing under a user mid-project is
  never acceptable.

---

## 18.11 Verification

- [ ] `Windows ▸ Service Assets Market` appears in the menu bar, opens a dockable panel, and docks/undocks/re-opens correctly.
- [ ] Removing the `market` section from `EngineConfig.json`: the menu item is absent, the editor starts normally, nothing logs an error.
- [ ] Signed out: the sign-in-from-launcher state, and the button launches the launcher.
- [ ] Signed in as the buyer: exactly the seeded `joystick_engine` entitlements — the four `other` assets do not appear. Verify against the seed list, not by eye.
- [ ] Confirm server-side filtering by calling the endpoint directly with `distribution_tag=other`: no `other` assets returned.
- [ ] Install Sci-Fi Prop Pack into `MyFirstProject`: files land under `Packages/`, `.jmeta` generated, assets visible in the Content Browser, lockfile written.
- [ ] Content Browser shows package content as read-only and distinguishable from `Assets/`.
- [ ] Install Character Controller Toolkit 1.0.0, then update to 1.1.0: version swaps, lockfile updated, old directory gone, no leftovers.
- [ ] Uninstall: files gone, assets unregistered, lockfile updated, no dangling `.jmeta`.
- [ ] Delete `Packages/` but keep the lockfile, reopen: *"3 packages required, 0 installed"* and Restore works.
- [ ] Corrupt a cached zip: sha256 verification fails, install refuses, nothing is extracted.
- [ ] Disconnect the network mid-download: clean cancel, no partial file, panel shows the error and offers Retry.
- [ ] Offline with a warm cache: cached list shown greyed, cached packages still installable.
- [ ] The editor stays responsive throughout a large download — verify by dragging a viewport gizmo while it runs.
- [ ] Purchased services listed read-only; "Manage on the Market" opens the system browser at the portal.
- [ ] `en.json` and `es.json` have identical key sets (count and diff them).
- [ ] `grep -rn MARKET_MODIFIER_ADDITION JoystickEngine_v2` → **zero**. The marker must not leak into the engine repository.
- [ ] Both `complete/18_…_complete.md` and the `something24-step-90-…` execution log written.
