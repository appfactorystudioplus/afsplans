# Complete — Step 1: declare `MemberResource` in `config.ts`

**Date:** 2026-08-31
**Plan:** `03-parse-cloud-functions.md` §3.2, §3.8 step 1
**File:** `w/auth/public/checkpoint/parse/config.ts`

> ✅ **Verified against a running Parse**, 2026-08-31, on local MongoDB via
> `build.sh --local`. Still **not applied to the remote registry** — see Residue.

## Why declare a class that already works

`MemberResource` was undeclared. Parse auto-provisioned it the first time something saved a
row, which means it inherited parse-server's **default** class-level permissions — public for
`find`, `count`, `get`, `create`, `update`, `delete` and `addField`.

That is how the ledger came to be world-writable and world-deletable: **nobody chose it.** It
was inherited by never declaring the class. Declaring it is what makes step 5's permission
change applicable at all.

## What was declared

Fourteen fields. The live set, plus the retention fields nothing has written yet —
`retired_at`, `purge_after`, `frozen_remaining_s`, `limit_override`.

**Declaring those now is the point, not tidiness.** Under `lockSchemas: true` a field that is
not declared cannot be added later without a config change and a deploy. Declaring them before
`membership_change/` needs them avoids a schema deploy landing in the middle of that work.

`registry_id` is **not** declared. Its values were deleted with the `MemberRegistry` anchor;
`deleteExtraFields: false` means the column survives on the server as an unused leftover
rather than being dropped, which is the safe behaviour.

## The index, and what it is not

```ts
ledger_key_lookup: { ledger_key: 1 }   // the lookup for every single call
member_lookup:     { member_reference_num: 1 }   // getAll()
retention_sweep:   { state: 1, purge_after: 1 }  // membership_change
```

**`ledger_key_lookup` is not a unique constraint** — parse-server's schema `indexes` creates
the index but cannot express uniqueness.

**That gap was measured, and it is not theoretical.** 200 concurrent first-consumes of a
counter that did not yet exist:

| | Rows created | Final `used` |
|---|---|---|
| Without the unique index | **42** | **159** |
| With it | **1** | **200** |

The counter split 42 ways and lost 41 increments. Find-or-create in application code cannot
prevent it: every racer queries, finds nothing, and creates. Only the database can close the
window between the read and the insert.

Worth knowing: **the 50-concurrent test passes without the index.** The failure only appears
above some threshold, so a smaller run says everything is fine and the defect surfaces later
as a member whose quota is quietly wrong.

So the constraint has to be created directly, and the command is in the file:

```js
db.MemberResource.createIndex({ ledger_key: 1 }, { unique: true, name: 'ledger_key_unique' })
```

It must be run against a database with no duplicates, which is itself the check worth running
first. The find-or-create compensation stays in every implementation regardless — it is the
only mitigation on a deployment that has not had the index created yet.

## A correction made during this step

The first draft wrote the **closed** permissions straight into the class declaration. That
would mean deploying step 1 also performs step 5 — closing writes before any client can call
the functions, breaking allocation estate-wide the moment the Parse app restarted, with a
second deploy as the only rollback.

Now there are three named constants:

```
CLP_OPEN_TODAY      ← referenced now; reproduces the inherited defaults VERBATIM,
                      so declaring the class changes nothing by itself
CLP_WRITES_CLOSED   ← step 5 swaps this in
CLP_LOG_CLOSED      ← step 5, for CheckpointAlloc_Log
```

**Step 1 is therefore safe to deploy on its own**, which is what makes it a separate,
reversible step rather than half of an irreversible one.

## `Registry` removed from the declaration

The pre-v2 class — `domain_processors`, `sync_storage` and friends — whose last consumers were
deleted on 2026-08-31. Removing it from `schemaDef` does **not** drop the collection
(`deleteExtraFields: false`). That is the intended order: stop declaring it, confirm it is
unread, then drop it. See `step-7-registry-class.complete.md` — **it is not empty**, and step 7
stopped there.

## Verification

- **`npm run build` compiles cleanly** (`tsc && tsc -p tsconfig.client.json`) against the real
  types and `node_modules` — the first full typecheck of this file.
- **parse-server accepted the definition on boot** and created all three declared indexes:

  ```
  _id_, ledger_key_lookup, member_lookup, retention_sweep
  ```

- Migrations ran and the server started on the declared port with `lockSchemas: true` and
  `strict: true` in force.
- The live remote schema and CLPs were read from the server before writing, not inferred.

## Residue

**The unique index exists only on the local MongoDB.** It was created by hand during testing;
the remote registry has none, and it holds 62 live rows. Until it is created there, that
deployment can split a counter under load. Check for duplicates before running the command,
since it fails on a database that already has them.

`recreateModifiedFields: false` and `deleteExtraFields: false` are unchanged, so this
declaration is additive. It cannot narrow an existing field's type or remove a column — which
is the conservative choice, and also means the leftover `registry_id` column stays until
someone removes it deliberately.
