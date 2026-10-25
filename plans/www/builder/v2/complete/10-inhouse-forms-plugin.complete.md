# 10 — In-house forms plugin (complete)

## Status

**Done — all phases (P0–P4).** `grapesjs-plugin-forms` is gone: removed from
`resources/js/portal/builder/core/config.ts`, `resources/js/portal/admin/config/grapejsconfig.ts`,
`declarations.d.ts`, and `package.json`/`package-lock.json` (`npm uninstall
grapesjs-plugin-forms`, verified — `node_modules/grapesjs-plugin-forms` no longer exists).
Replaced by `grapesjs-plugin-forms-afs`
(`resources/js/portal/builder/core/editor/formsPlugin/`), which detects only its own components
(a `data-afs-forms` marker, never a bare tag name), so no future module can hit the "Send"
corruption bug this whole plan started from — see [09](../09-single-source-ui.md)'s follow-up and
this plan's own Context.

- **www** — uncommitted on `main` (pending, on request).
- **481 builder tests**, up from 465 before this plan (16 new: 6 for `isOwnComponent`, 4 for the
  legacy-forms migration, 6 for the `[Group Name]` option-groups parser). `tsc --noEmit` clean.
  `check:modules` clean. `build-external-ui-webpack` and `npm run build` both green — the second
  is the one that actually proves the app still builds with the npm dependency gone, not just
  that the remaining TypeScript type-checks.
- **Verified live** in the running builder, with the vendor plugin present (P0/P1) and then
  fully removed (P2) — DOM-inspected actual button text/attributes in the canvas iframe both
  times, not just screenshots. Site_users' Dashboard buttons (the ones that broke), events'
  Register button, appointments' Booking buttons + real slot times, and ecommerce's Add to
  Cart/Shop Now/cart-icon buttons all render correctly under the new plugin alone.

## What shipped

### P0 — the plugin itself

`resources/js/portal/builder/core/editor/formsPlugin/`:

- `types.ts` — `AFS_FORMS_ATTR = 'data-afs-forms'`, `isOwnComponent(el, tag, kind)`: tag name
  **and** (our marker **or** the legacy `data-gjs-type`). Never a bare tag match.
- `componentTypes.ts` — all nine parity types (`form`/`input`/`textarea`/`select`/`checkbox`/
  `radio`/`option`/`label`/`button`) plus `optgroup` (P3, new). No type has a destructive
  `init()` — including `button`.
- `selectOptionsTrait.ts` — `afs-select-options`, the newline-delimited options editor (parity),
  extended with P3's `[Group Name]` line syntax for `<optgroup>`.
- `blocks.ts` — the eight-block palette, category `'Forms'` (was `'Forms (AFS)'` during P0–P1
  while both plugins were registered, to keep two same-named categories from being confusing to
  test against — renamed back once P2 removed the vendor one).
- `afsFormsButtonCss.ts` / `injectButtonCss.ts` (P3) — button `variant`/`size`/`icon` styling,
  keyed off the same `--afs-*` tokens `branding.ts` already defines, injected into the canvas
  frame on `'load'`/`'frame:load'` (the same pair `contextMenu.ts` already uses to reach the
  live frame document after a page switch).
- `migrateLegacyForms.ts` (P1) — backfills `data-afs-forms` onto any element still carrying only
  the legacy `data-gjs-type`, on every project load. Idempotent.

**One deliberate simplification vs. the plan's exact wording:** the plan described the button's
label as "a separate trait rendered into a dedicated `<span data-afs-forms-label>` child." What
shipped instead: `editable: true` on the button itself — the same mechanism `label` already gets
via `extend: 'text'` — so a tenant double-clicks and types, no custom trait or child-management
code involved at all. Simpler, and it still fully satisfies the actual requirement ("no shape
inference, nothing to destroy"): there is no `init()`, no children logic, nothing that can ever
throw content away. The icon trait is CSS-only (a `::before` mask-image keyed on the
`data-afs-icon` attribute), so it never touches the button's children either.

### P1 — legacy migration

`migrateLegacyForms(editor)` runs in `createEditor.ts`'s existing `editor.on('load', …)` handler,
right where `retireLoadedProject` already runs (same precedent, not a new pattern). Unit-tested
directly via `migrateLegacyFormsHtml()`, including the specific case that mattered — a module
button with no `data-gjs-type` at all is left untouched, not swept up by the migration.

**Not verified live against a real pre-existing legacy project** — this local environment has
none (the demo site_users buttons went through the earlier `siteUsersIconButtons.ts` point-fix,
never the vendor plugin's actual button type, so there was no real `data-gjs-type="button"`
fixture to migrate on-screen). The migration function itself is unit-tested in isolation instead.

### P2 — vendor plugin removed

`config.ts`, `package.json`, `declarations.d.ts`: `grapesjs-plugin-forms` gone.
`siteUsersIconButtons.ts` (the original point-fix) deleted — redundant now that the actual
defect is gone rather than patched around. Category renamed `'Forms (AFS)'` → `'Forms'`.

### P3 — enhancements

- Button: `variant` (primary/secondary/ghost/outline), `size` (sm/md/lg), `icon` (arrow-right/
  check/plus) traits — all attribute-driven, styled via `afsFormsButtonCss()`.
- Input: `date`/`time`/`number`/`range`/`color`/`tel`/`url`/`file`/`hidden` added to the `type`
  trait's options (the vendor plugin only had text/email/password/number).
- Textarea: `rows` and `data-afs-resize` traits (previously it just inherited input's traits
  verbatim, none of which fit a multi-line field).
- Select: `multiple` trait, and `optgroup` support via `afs-select-options`' `[Group Name]` line
  syntax — additive to the existing `value::label` format, so a select with no `[...]` lines
  behaves exactly as before.

**Explicitly not built** (per the plan's own "not committed" line): a form `action`/submit-target
trait. Still needs its own investigation into this app's actual submission story (SurveyJS,
`afsapi.js`) before deciding what it should even do — unchanged residue, not silently dropped.

### P4 — the admin/MJML email editor

Investigation found `resources/js/portal/admin/config/grapejsconfig.ts`'s `grapesjsConfig` —
including its `grapesjs-plugin-forms` registration — was **dead code**. The actual
`grapesjs.init()` call in `afsemail.ts` (line 964) uses a separate, hand-inlined config
(`plugins: [grapesJSMJML]` only) and has done so since a `// let editor =
grapesjs.init(grapesjsConfig);` line was commented out at some point — `grapesjsConfig` was
imported but never referenced anywhere else in that file. `grapesjs-plugin-forms` was never
actually loaded in the live email-template editor. Cleaned up the dead import and config entries
in `grapejsconfig.ts` (minimal diff — the rest of that unused config object is untouched, out of
scope for this plan) so nothing referenced the package, but did not install
`grapesjs-plugin-forms-afs` there since there was no live consumer to replace. Also confirmed via
`grapesjs-mjml`'s own bundle that MJML content uses `mj-button` (a distinct custom tag), never
plain `<button>` — even if `grapejsconfig.ts` *had* been live, the vendor plugin's button-swallow
bug would rarely if ever have fired against real MJML markup.

## Verification

```
cd w/www
npx tsc --noEmit
npx tsc -p resources/js/external/ui/tsconfig.external.json --noEmit
npm run check:modules
npm run test:builder
npm run build-external-ui-webpack
npm run build
```

Live, DOM-inspected in the actual running builder (not just screenshots) at three points: with
both plugins coexisting (P0/P1), and again after the vendor plugin's full removal (P2) — site
users Dashboard (all 7 previously-"Send"-corrupted buttons), events Register, appointments
Booking (real availability slots + Confirm booking), ecommerce product grid/cart.

## Residue

| # | Item | Where it belongs |
|---|---|---|
| 1 | Drag-and-drop of a fresh block from the palette onto the canvas could not be verified through browser automation — GrapesJS's block DnD isn't reliably scriptable this way (true for every category, not specific to this plugin; native HTML5 DnD and synthetic mouse/DragEvent sequences were both tried and neither registered a drop). Block *registration* (category, count, labels) was confirmed correct live; the component types' own correctness is unit-tested and has no code path that depends on how the component was created (parsed HTML vs. a fresh type-based drop share the same `isComponent`/`model.defaults`). | A human interactively dragging a block once in the real app closes this gap; not worth a headless-GrapesJS-in-jsdom harness given the risk of that approach hanging (tried, aborted — `editor.destroy()` in jsdom did not return). |
| 2 | P1's migration is unit-tested but not live-verified against a real legacy project, because none exists in this environment (see P1 above). | If a real pre-10 tenant project surfaces, re-run the same live-verification P1's own gate calls for. |
| 3 | The form `action`/submit trait (P3's explicitly-deferred item) — needs investigation into this app's actual form-submission story before scoping. | A future pass, on request. |
| 4 | The rest of `resources/js/portal/admin/config/grapejsconfig.ts` (`grapesjsConfig`) is dead code beyond just its forms-plugin entry — never referenced by the actual `grapesjs.init()` call in `afsemail.ts`. Left alone; removing genuinely-dead code outside this plan's scope wasn't asked for. | Worth flagging to whoever owns `afsemail.ts`, not a code change here. |
