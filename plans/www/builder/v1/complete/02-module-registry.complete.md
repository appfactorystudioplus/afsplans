# 02 — The module registry (complete — P8 gated)

## Status

**P0–P7 done. P8 is gated on [05](../05-admin-module-configuration.md) §5.4 and is recorded as
gated rather than left open**, which is what §2.10 says to do when 05 has not landed.

**143 tests** (was 90 after [03](03-editor-architecture.complete.md)). The registry is a
**build gate**: `npm run build` now runs `check:modules` before `tsc`, so a module declared
without a runtime stops the build.

| Phase | Contents | State |
|---|---|---|
| **P0** | The types — `BuilderModule`, `ModuleRuntime`, `noRuntime()` — and an empty registry | ✅ shipped without changing a single block |
| **P1** | Migrate ecommerce (7 kinds), events (3), site_users (3) | ✅ declarations moved; implementations untouched |
| **P2** | Migrate `module-form` | ✅ declared; its `addType` stays in `components.ts` — see Residue |
| **P3** | The consistency check, twelve checks | ✅ **and wired into the build** |
| **P4** | `version` + `migrations` | ✅ **B14** — migration is pure, offered, never silent |
| **P5** | `availability` and the three palette states | ✅ **G7**'s upgrade prompt; fails open |
| **P6** | `pageBinding` declaration | ✅ ecommerce and events declare theirs |
| **P7** | `universalTraits` | ✅ site_users contributes its visibility trait to every block |
| **P8** | `instance-select` | ⏸ **gated** — needs 05 §5.4's `ModuleInstanceProvider.list()` |

## `runtime` is required, and that is the whole point

**B1** was four separate instances of one omission: `module-payment`, `module-subscriber`,
`module-commerce` and the dynamic types render markup and do nothing on a published page, with
no error and no warning.

`runtime` is now a required field on `BuilderModule`, so a module without one **does not
compile**. `noRuntime(reason)` is the explicit way to say a module is presentational — and it
**refuses an empty or throwaway reason**:

```ts
noRuntime('')      // throws
noRuntime('todo')  // throws
noRuntime('layout only — no behaviour on a published page')   // fine
```

That refusal is the load-bearing part. A module that can say `noRuntime('')` has re-opened the
hole this field exists to close: the reason is the *only* thing separating a decision from an
omission, and all four inert modules today are the second.

`noRuntime()` is also **not available** to a module that plainly needs behaviour. A payment
block that renders and takes no payment is not presentational (07 §7.5.7).

## The consistency check fails the build, not the tenant's page

§2.4's requirement, implemented literally. `npm run check:modules`, run first by
`npm run build`:

```
[builder] the module registry has 1 problem(s):
  check 2  forms: runtime is empty — use noRuntime("why") if that is deliberate

See plans/www/builder/02-module-registry.md §2.4 and §2.9.5.
```

**Verified by breaking it on purpose**: emptying forms' runtime exits 1; restoring it exits 0.

Nine of the twelve checks are implemented as a pure function over the registry. Three are not,
and each is noted where it belongs rather than quietly dropped:

| Check | Why not |
|---|---|
| 4 — `availability.tier` names a real registry resource | Needs `checkpoint_registry_v4.json`, fetched at runtime. Belongs in a build step that can read the file |
| 8, second half — version never decreases against git HEAD | Needs git |
| 11, in full | Only sees **declared** modules — see below |

**It returns every problem, never the first.** A registry with three faults should cost one
build run, not three.

### Check 11 cannot yet catch B18, and that is worth stating

`module-commerce` (`components.ts:603`, category *Module Components*, **inert**) still ships
beside the seven working Commerce blocks. Check 11 refuses two modules claiming one palette
category — but `module-commerce` is not in the registry, so the check cannot see it.

It catches it the moment that type is declared, which is [07](../07-module-handoffs.md) §7.5.1
item 2. Until then **B18 is open** and the check is armed rather than firing.

## P1 moved the declaration, not the implementation

Each of the three `register()` calls the same component file it always did:

```ts
register(editor) {
    registerCommerceComponents(editor);
    addCommerceBlocks(editor.BlockManager);
}
```

That is deliberate and it is what makes the migration provable. These are **the only modules
where a regression is detectable**, because they are the only ones that currently do anything
— so the component code had to stay untouched.

`createEditor()` went from three hand-written pairs of calls to:

```ts
for (const mod of BUILDER_MODULES) {
    mod.register?.(editor);
}
```

§2.3's "one file plus one line", now true.

**Three tests pin that nothing moved**: the four ids in order, each runtime pointing at a real
function, and the palette categories unchanged — because changing a category moves a tenant's
blocks.

## What the code said that the plan did not

**§2.9.1 held up.** The three modules that shipped independently on 2026-08-31 declared cleanly
against a shape written without reference to them. Nothing in `BuilderModule` had to be widened
to fit them, which is the strongest evidence available that the registry is an extraction
rather than a design imposed on the codebase.

**The fourth type-only import problem was not avoidable, and the fix was the right one.**
[03](03-editor-architecture.complete.md) recorded that Node's type stripping cannot elide
`import { Editor } from './types'` when `Editor` is an interface, and that twice the answer was
to stop the module importing that file at all. That does not work here: a module declaration
must import the real component file.

So **20 files were converted to `import type`** — which `isolatedModules: true` already wanted
and which nothing had enforced. It is a correct fix, not a workaround, and the prediction that
injection would eventually stop working held.

**The loader's extension guard was wrong.** It tested `/\.[a-z]+$/` and so treated
`./ecommerce.module` as already extensioned, refusing to try `./ecommerce.module.ts`. Dotted
filenames are ordinary — `*.module.ts`, `*.config.ts`, `*.d.ts` — so the guard now names the
extensions it knows.

**`site_users`' visibility trait is the case for `universalTraits`, and it is not a security
control.** `data-siteusers-visible-to` is the tenant's *intent*; what a visitor can actually
reach is route protection (`laravel_builder_routes`) and the module's API guard. A block hidden
client-side is not hidden from anyone reading the page source, and the declaration says so.

**Availability fails open, and that is the opposite of the authentication edge.** An unknown
tier, an unloaded registry, or a lookup that throws all resolve to *available*. Denying is
wrong for something advisory — [../../allocation.md](../../allocation.md) §7 says `alloc_v2` is
not a security boundary — because a broken lookup would otherwise empty a tenant's palette to
protect a limit the server enforces anyway.

Contrast `plans/00_ISSUES.md` **I8**, where a missing member *denies*. Same shape of question,
opposite safe direction, because the blast radius is opposite: there, admitting an
undescribable session; here, hiding a feature the tenant is paying for.

**Migration is pure by construction.** `migrateConfig()` computes and reports; it writes
nothing. §2.6 requires migration be run when a tenant next opens the page, shown as *"This
block was updated"*, and applied **on save** — a function that wrote as it computed would
already have rewritten a published page underneath its owner. A test asserts the input object
is not mutated.

A config from a **newer** version than the module is left alone rather than downgraded: that
means a newer deployment published the page, and guessing how to reverse a migration would lose
whatever it added.

## The tests

**143, up from 90.** `modules.test.ts` is 53 of them.

| Group | Pins |
|---|---|
| `noRuntime` | that an empty or throwaway reason is refused — the guard on B1 |
| registry invariants | the four declared modules, their runtimes, their categories |
| consistency | each check catching its own fault, and **all** faults reported at once |
| version/migrations | ascending chained order, purity, newer-version passthrough, unversioned → v1 |
| availability | the three states, hidden beating disabled, and failing open twice |
| universal traits | uninstalled contributes nothing, disabled still contributes, own traits win |

## Residue

**P8 is gated, not done.** `instance-select` needs `ModuleInstanceProvider.list()` from
[05](../05-admin-module-configuration.md) §5.4. All four declared modules still use free-text
reference traits — `data-store-ref`, `data-event-ref`, `data-siteusers-module` — so a tenant
types a `reference_num` from memory. For site_users that is worse than inconvenient: **no
screen in the portal lists those ids** (**B19**), so the block asks for a value the product
never shows. §2.9.2's picker cannot fix that alone; 05 §5.3's admin panel has to exist first.

**Forms has no `register()`.** Its `addType` sits at `components.ts:288` among five other
types, four of them the inert ones, so moving it is its own change (07 §7.5.5). It is declared
— the consistency check sees it — but `registerComponents()` still registers it. A test pins
that forms is the *only* module in that state, so the exception stays deliberate.

**Six modules are still undeclared**: direct payments, subscribers, listings, discussions,
feedback, appointments. Declaring one without a runtime is the omission this registry exists to
prevent, so they wait for [07](../07-module-handoffs.md) §7.5 to give them one. Until then their
blocks register exactly as they do today.

**§2.5's palette states are computed but not yet rendered.** `paletteState()` returns
available / hidden / disabled with a reason, and nothing in the editor reads it — wiring it
into the block palette needs [06](../06-editor-ux-and-styling.md)'s design for what a disabled
block looks like.

**§2.6's version attributes are not yet stamped on published output.** `versionAttributes()`
exists and is tested; the publish path does not call it. That is
[04](../04-publish-and-serve.md) §4.2's compile step, which is the next step in
[07](../07-module-handoffs.md) §7.6 after 05 §5.5.

## Verification

```bash
cd www
npm run check:modules    # module registry OK — 4 module(s) declared
npm run test:builder     # 143 pass
npx tsc --noEmit         # clean, exit 0
npm run build            # built in 3m, exit 0
```

The gate was verified in both directions: emptying a module's runtime exits 1 and names the
check; restoring it exits 0.

**Added** — `core/modules/`: `types.ts`, `index.ts`, `consistency.ts`, `migrations.ts`,
`availability.ts`, `universalTraits.ts`, and `{ecommerce,events,site_users,forms}.module.ts`.
`tests/`: `modules.test.ts`, `check-modules.mjs`.

**Changed** — `core/editor/createEditor.ts` (iterates the registry), `package.json`
(`check:modules`, and `build` runs it first), `tests/resolve-ts.mjs` (extension guard),
and **20 files** converted to `import type`.

## Next

[07](../07-module-handoffs.md) §7.6 item 3: [05](../05-admin-module-configuration.md) §5.5, the
reverse index — before instance-selects exist, so pickers and delete-refusal ship against a
populated table.

---

## Addendum — P8 landed (2026-09-01)

**P8 (`instance-select`) is no longer gated.** It was closed here as *"gated on
[05](05-admin-module-configuration.complete.md) §5.4's provider contract"*, on the grounds that
*a picker with nothing to pick from is worse than the text box it replaces*. 05 P2 built the
providers and 05 P3 built the trait; all five reference traits in the builder are now pickers.

Two things from that work reach back into this document:

- **§2.4 check 4 is implementable after all.** It was recorded as needing
  `checkpoint_registry_v4.json` at runtime. `resources/js/checkpoint/resources.ts` is the same
  list, lives in this repo, and is what every other allocation caller uses. `check-modules.mjs`
  now enforces it — and caught `forms.module.ts` gating on `forms_count`, which is not a
  resource (**B22**). The gate had been failing open in silence.
- **`BuilderModule` gained `foreignRefTraits`.** A block may name an instance of a *different*
  module — the ecommerce checkout block names a Direct Payment — and the reverse index has to
  file that under the module that owns the instance. See
  [05](05-admin-module-configuration.complete.md).

**§2.6's version stamping is still not wired into block registration.** No page published to
date carries `data-afs-module`, which is why 05 P0's backfill had to learn legacy markup.
