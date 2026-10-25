> **Retired 2026-09-23.** `packages/afsserver/bin/sync-checkpoint-client.sh` no longer
> exists. `resources/js/checkpoint/` was published as its own package,
> `@appfactory/checkpoint-client`, to a private registry
> (`packages/afsserver/docker/verdaccio/`) — every app now installs it via `npm install`
> instead of carrying a local copy kept in sync by this script. See the checkpoint-client
> repo's own history and `plans/membership_change/complete/00_extras.md` for the migration
> record. The rest of this document is kept for the record — it explains *why* the sync
> script existed and what it was built to prevent, which is exactly the drift class a real
> package (one copy, versioned, installed everywhere) now makes structurally impossible
> rather than merely checked-for.

`packages/afsserver/bin/sync-checkpoint-client.sh` — a bash script that copies the Checkpoint TypeScript client from one source app into all the others.

## Why it exists

The Checkpoint client (`alloc_v2.ts`, `resources.ts`, `app-names.ts`) isn't an npm package. It's duplicated into each app because, in its own words, *"these apps have no shared build and each lives in its own git repository."* Duplication is workable **only if the copies are mechanically synced** — so this script is the syncing mechanism.

Its header documents the drift that prompted it, and it's worth reading:

- six apps had identical `alloc_v2.ts`, but `www` had drifted by a statement and was missing `app-names.ts`
- **`auth` carried a copy a whole generation behind** — dotted resource ids (`member.domains.count`) and a *string* tier (`"starter"`) instead of the 0–4 index
- `resources.ts` was missing `afs_connect` and `secondary_users` entirely

…and *"nothing anywhere reported any of it."*

## How it works

```
SRC     = www/resources/js/checkpoint        ← source of record
TARGETS = members, market, auth, apps/{coordinator,dream_graph,notes,pdf_editor,flow}
```

It copies a **named file list**, not the whole directory. Two modes:

```bash
bash packages/afsserver/bin/sync-checkpoint-client.sh --check
```

`--check` verifies and exits non-zero on drift (for CI). No argument means write — it overwrites the targets from `www`.

## How it relates to what we just did

**This script is what created the 11 registry copies.** `checkpoint_registry_v3.json` was in its sync list, and it also ran a parity check of the bundled copies against auth's. So the duplication wasn't accidental — it was deliberately maintained infrastructure, built to solve the drift problem by *copying more carefully* rather than by not copying.

In my change I removed the registry from the sync list and inverted the parity check: it now **fails if it finds a bundled registry anywhere**, since the file is resolved at runtime instead. It still syncs the three `.ts` files, which genuinely do need duplicating until the SDK becomes a package (that's OQ-K in the plan).

## The part you should know about

**The script hasn't been able to run.** `APPS_ROOT` was one directory short — it resolved to `w/packages`, so `SRC` pointed at `w/packages/www/...`, which doesn't exist, and it aborted on line 1 of real work. `TARGETS` was stale too: it named `coordinator`, `dream_graph`, `notes`, `pdf_editor`, `flow` as top-level after they'd moved under `apps/`, and never listed `market`. I fixed both, so it runs now.

That's very likely *why* things drifted — the guard against drift was itself broken and silently doing nothing.

And now that it works, `--check` surfaces real pre-existing drift: **`www` has zero `MARKET` entries in `app-names.ts` while every target app has three.** Since `www` is the source, running write mode would strip Market support from all eight apps. I'd leave write mode alone until that's reconciled — `--check` is safe to run any time.

One housekeeping note: `membership_change/06-registry-distribution.md` (renumbered from `08-`, 2026-09-22) still opens with *"Nothing below is built, and no file has been deleted"* and describes the copies in the present tense, which is now out of date — the migration it describes is done. Want me to bring it in line?