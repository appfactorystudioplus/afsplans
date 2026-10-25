# Completion records — `alloc-v2-parse-direct`

One file per step of [`../03-parse-cloud-functions.md`](../03-parse-cloud-functions.md) §3.8,
plus the plan rewrite that had to happen before any of it could be executed.

| File | Step | State |
|---|---|---|
| [00-plan-rewrite](00-plan-rewrite.complete.md) | — | ✅ the folder retargeted from `MemberRegistryCounter` to what actually exists |
| [step-0-service-auth](step-0-service-auth.complete.md) | 0 | ✅ done — verified 401 / 401 / 201 / 200 |
| [step-1-declare-schema](step-1-declare-schema.complete.md) | 1 | ✅ done — verified on a running Parse; all three indexes created |
| [step-2-cloud-functions](step-2-cloud-functions.complete.md) | 2 | ✅ done — **46/46 checks pass** (32 service-key, 14 token), including P1 and §3.4.2 |
| [step-3-coordinator-gateway](step-3-coordinator-gateway.complete.md) | 3 | ✅ done behind a flag, 18 tests |
| [step-4-alloc-v2-clients](step-4-alloc-v2-clients.complete.md) | 4 | ✅ done behind a flag, tsc clean |
| [step-5-close-permissions](step-5-close-permissions.complete.md) | 5 | ⏸ **prepared, gated on deployment** |
| [step-6-retire-routes](step-6-retire-routes.complete.md) | 6 | ✅ done |
| [step-7-registry-class](step-7-registry-class.complete.md) | 7 | ⛔ **stopped — the class is not empty** |

## The headline result

**P1 is closed.** 50 concurrent consumes of 1 unit against a limit of 40 land on **exactly
40** — one row, 40 accepted, 10 refused. That was the single test the plan called the most
important, and it is the reason the cloud functions exist.

## Three things testing changed

**1. The unique index on `ledger_key` is required, not advisory.** Without it, 200 concurrent
first-consumes split one counter into **42 rows** and lost 41 increments. With it: one row,
`used` 200. The plan previously called find-or-create "correct but unenforced"; that was too
generous. See §3.2.4 and [step-1](step-1-declare-schema.complete.md).

**The 50-concurrent test passes without the index**, so a smaller run gives false confidence.

**2. Step 1 nearly shipped step 5 with it.** The first draft wrote the closed permissions into
the class declaration, which would have broken allocation estate-wide the moment the Parse app
restarted. They are now separate constants; step 5 is a one-word change at two call sites.

**3. Step 7 stopped on evidence.** `Registry` is not empty — 2 objects, one holding
`domain_processors: {count: 5, appfactorystudio: 9}`. Dropping a class is one-way, so it is
removed from the declaration and left on the server for a person to decide about.

## What is not done

| | |
|---|---|
| **The unique index on the remote registry** | Exists on local MongoDB only. The remote has 62 live rows and no constraint |
| **`CHECKPOINT_BASE_URL` / `ALLOC_SERVICE_KEY` on the deployed Parse** | Set in `.env.dev`/`.env.local`; the running instance still refuses every call without them |
| **Step 5** | Gated: needs the functions deployed *and* every caller flipped over first |
| **The feature flags** | `ALLOC_USE_CLOUD_FUNCTIONS` / `VITE_ALLOC_USE_CLOUD_FUNCTIONS` are off everywhere. Turning them on is the cutover; removing them and the direct-write path is the cleanup |

## Closed since: the memberless token

This table used to carry a row for the one token case that could not be produced — a valid
token belonging to a user with no `brain_memberships` row. **It is now verified, and the
behaviour it verifies has changed.**

It was produced by minting a token while the member existed and deleting the row underneath
the still-valid JWT, which is the realistic shape of it anyway: a session outliving its member.

Checkpoint refuses it, and now does so **everywhere** — `attachMember()` used to hard-fail only
when `isProduction()`. `resolveCaller()`'s own check is therefore unreachable in practice and
stays as defence in depth, because it asserts something different: that the member actually
arrived in the response, not that Checkpoint decided correctly. An empty reference reaching
`ledgerKey()` would write every member's usage onto one shared row.

Full write-up, including the three secondary-user paths that had no check at all and the
`require_2fa` policy a missing member silently disabled, is [`../../00_ISSUES.md`](../../00_ISSUES.md) **I8**.

## Running the tests again

```bash
cd w/auth/public/checkpoint/parse
./build.sh --local          # .env.local, local MongoDB, NODE_EXTRA_CA_CERTS, no pm2 needed
```

Then the write-path suite against `http://localhost:1342/parse`. Two things that will waste an
hour if forgotten, both learned the hard way:

- **Strip quotes when parsing `.env`.** `APP_ID` is quoted; sending the quote characters gets a
  bare `{"error":"unauthorized"}` from Parse that says nothing about why.
- **Node does not read the Windows certificate store.** Without
  `NODE_EXTRA_CA_CERTS=C:/laragon/etc/ssl/laragon.crt`, every call to `https://auth.test` fails
  with `DEPTH_ZERO_SELF_SIGNED_CERT` and the function refuses — correctly, and for a reason
  invisible from the caller's side. `build.sh --local` exports it.
