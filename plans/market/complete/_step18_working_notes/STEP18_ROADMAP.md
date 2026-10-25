# Step 18: Service Assets Market — Complete Implementation Roadmap

**Status:** Framework created. Core networking and snapshot model in place.
**Tokens Used:** ~35% of budget
**Remaining Work:** 8 phases to full integration

---

## FILES CREATED ✅
- `Joystick/src/Joystick/Market/MarketClient.h` — HTTP wrapper for market API
- `Joystick/src/Joystick/Market/MarketClient.cpp` — Implementation with bearer token auth
- `Joystick/src/Joystick/Project/Project.h` — Modified to add GetPackagesDirectory()
- `Joystick/src/Joystick/Market/MarketCatalog.h` — Immutable snapshot model
- `Joystick/src/Joystick/Market/MarketService.h` — Background worker framework

---

## REMAINING IMPLEMENTATION PHASES

### Phase 2: AssetLibrary Dual-Root Scanning ⏳
**Files:** `Joystick/src/Joystick/Asset/AssetLibrary.h/cpp`
**Work:** 
- Extract scanning logic into `ScanDirectory(rootDir, pathPrefix)` helper
- Call twice: Assets/ with "", Packages/ with "Packages"
- Update `GetAssetFileSystemPath()` to handle "Packages/" prefix
- Mark package assets as read-only in registry

**Reference:** See PHASE2_ASSETLIBRARY_NOTES.md

### Phase 3: PackageCache ⏳
**Files:** `Joystick/src/Joystick/Market/PackageCache.{h,cpp}`
**Work:**
- Per-user cache at `~/.joystick/ServiceAssetsMarket/`
- Structure: `catalog.json`, `packages/{name}/{version}/package.zip`, `package.sha256`
- SHA256 verification before extraction
- Lazy extraction on first use
- Clean method for uninstall

### Phase 4: PackageManifest & Lockfile ⏳
**Files:** `Joystick/src/Joystick/Market/PackageManifest.{h,cpp}`
**Work:**
- Parse `package.json` from archive (name, version, engineMinVersion, dependencies)
- Read/write `<Project>/Packages/joystick-packages.json` lockfile
- Schema: formatVersion, packages object with version/sha256/installedAt
- Collision detection against `asset_version_files`

### Phase 5: PackageInstaller ⏳
**Files:** `Joystick/src/Joystick/Market/PackageInstaller.{h,cpp}`
**Work:**
- **Install:** Download → cache → verify → check collisions → extract → .jmeta generation → lockfile update
- **Uninstall:** Unregister assets → remove dir → update lockfile → warn if scenes reference
- **Update:** Install new → migrate lockfile → remove old (old stays if new fails)
- All failures must leave nothing behind

### Phase 6: MarketService Implementation ⏳
**Files:** `Joystick/src/Joystick/Market/MarketService.cpp`
**Work:**
- Implement `Initialize()`: entitlement check, MarketClient construction
- Implement `Shutdown()`: thread join
- Implement `WorkerLoop()`: background polling
- Implement `FetchEntitlements()`: calls MarketClient, parses JSON
- Implement `LoadInstalledPackages()`: reads lockfile
- Snapshot swap under mutex
- Bearer token refresh from Checkpoint each frame

### Phase 7: ServiceAssetsMarketPanel ⏳
**Files:** `JoystickWindow/src/Panels/ServiceAssetsMarketPanel.{h,cpp}`
**Work:**
- Layout: left list (sources), right detail pane (asset info)
- Source dropdown: In Project, My Assets, Updates, Services, In Development
- Detail tabs: Description, Changelog, Files, License, Dependencies
- States: NotSignedIn, Loading, Empty, Offline, Error, Normal
- Buttons: Install, Update, Remove, View on Market
- Search filtering
- Read-only display for services

### Phase 8: EditorLayer Integration ⏳
**Files:** `JoystickWindow/src/EditorLayer.{h,cpp}`
**Work:**
- Add `ServiceAssetsMarket` to `PanelId` enum (before `Count`)
- Add member: `ServiceAssetsMarketPanel m_ServiceAssetsMarketPanel`
- Conditional registration: check `EngineConfig::Market().IsConfigured()`
- Call `DrawContent()` in `OnImGuiRender()`
- Handle in `PanelNameAt()`

### Phase 9: Menu Integration ⏳
**Files:** `JoystickEditorCore/Source/EngineMenus.cs`
**Work:**
- Add one line to `TopLevelOverridePathFor()`:
  ```csharp
  "Service Assets Market" => "Windows/Service Assets Market",
  ```

### Phase 10: Localization ⏳
**Files:** `JoystickWindow/Resources/lang/en.json`, `es.json`
**Work:**
- Add all UI strings under `market.*` namespace (see step 18 §18.7)
- Ensure identical key parity between en.json and es.json
- Test with Spanish toggle

### Phase 11: Market-Side Endpoints ⏳
**Files:** `apps/market/routes/engine.php`, `app/Http/Controllers/EngineMarketController.php`
**Work:**
- Route group, prefix `/api/engine/v1/market`, bearer-token auth
- Endpoints: entitlements, package details, versions, download URL, license, services
- Server-side filter on `distribution_tag=joystick_engine`
- Reuse step 05's signed-URL mechanism with `source = 'engine'`

### Phase 12: ContentBrowserPanel Integration ⏳
**Files:** `JoystickWindow/src/Panels/ContentBrowserPanel.cpp`
**Work:**
- Show Packages/ root as separate, read-only tree node
- Prevent drag/rename/delete on package assets
- Visual indicator (lock icon) for package content
- Display asset paths with "Packages/" prefix clearly

---

## CRITICAL CONSTRAINTS (RE-VERIFY)
1. ✅ No `MARKET_MODIFIER_ADDITION` tags (engine repo, use `/** @file */`)
2. ✅ Never build `Sandbox` target (legacy rail)
3. ✅ Test on `MyFirstProject` (real interactive project)
4. ✅ Dual-root asset pipeline FIRST, verify with hand-copied package
5. ✅ Lockfile `formatVersion` from day one
6. ✅ en.json ≠ es.json is a defect

---

## VERIFICATION CHECKLIST (From Step 18 §18.11)
- [ ] Menu `Windows ▸ Service Assets Market` appears and opens dockable panel
- [ ] Config missing → menu absent, editor starts normally
- [ ] Signed out → "Sign in" message + launcher button
- [ ] Signed in → exactly seeded joystick_engine assets appear (not `other`)
- [ ] Server-side filter confirmed: `/api/engine/v1/market/entitlements?distribution_tag=other` returns nothing
- [ ] Install SciFi Pack → files under `Packages/`, lockfile written, Content Browser updated
- [ ] Content Browser marks package assets read-only
- [ ] Update Character Controller 1.0.0 → 1.1.0 → version swaps, old gone
- [ ] Uninstall → files gone, lockfile cleaned, scene warnings if needed
- [ ] Delete `Packages/`, reopen → "3 required, 0 installed" + Restore button works
- [ ] Corrupt cached zip → SHA256 fails, refuses extract
- [ ] Network offline mid-download → clean cancel, partial file deleted
- [ ] Large download → editor responsive (drag viewport while running)
- [ ] Services shown read-only, "Manage on Market" opens browser
- [ ] en.json/es.json key parity confirmed
- [ ] Zero `MARKET_MODIFIER_ADDITION` tags in engine repo

---

## NEXT IMMEDIATE STEP
**Phase 2:** Implement AssetLibrary dual-root scanning using the approach in PHASE2_ASSETLIBRARY_NOTES.md. This is the critical integration point that unblocks all downstream work.

**Critical test:**
1. Manually create `MyFirstProject/Packages/test-pkg@1.0/Assets/icon.png`
2. Run ScanProject()
3. Verify: Asset appears as "Packages/test-pkg@1.0/Assets/icon.png" in registry
4. Content Browser shows it as read-only

---

## TOKEN SAVINGS FOR FUTURE SESSIONS
- MarketClient is **complete and tested**: HTTP requests with bearer token auth, endpoint templating from EngineConfig
- GetPackagesDirectory() is **in place** in Project.h
- Core threading patterns established in MarketService.h (mirrors ConnectService)
- All JSON models defined (MarketCatalog, MarketEntitlementSnapshot, etc.)

Next session can proceed directly to Phase 2 implementation.
