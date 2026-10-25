# 02 — The Target Model (complete)

## Status

**Complete — 2026-10-17.** This step defines vocabulary, ten invariants, two resolvers and a schema summary; it holds no
code of its own. Finishing it meant checking the model against the tables and classes that exist today, so that every
later step builds on a true "before" picture. **No application code or migration was changed by this step.** The model
itself is built by steps 03–16.

## Phases

| Phase | Work | Result |
|---|---|---|
| P0 | Check each entity in §2.1 against the create migrations and models | Existing entities confirmed; new ones confirmed absent |
| P1 | Check each invariant I1–I10 against the code | State recorded below |
| P2 | Check the two resolvers (§2.3) and the schema summary (§2.4) for accuracy | Accurate; no drift |

## §2.1 — entities: today versus target

| Entity | Exists today | Missing for the target |
|---|---|---|
| Processor | `laravel_processors` | `provider_account_id`, archive columns, currency (step 03) |
| Direct Payment | `laravel_direct_payments` | `archived_*`, `binding_version`, `currency` |
| Binding | `laravel_direct_payment_processor_bindings`, `active_marker` unique | `kind`, `refund_window_days`; foreign key to processors |
| Payer / payer customer | Both exist; `active_key` unique per payer | `source`, `superseded_reason`; foreign key to processors |
| Item / tier | Exist; tier is upserted by reference | `archived_at`; canonical tier price columns |
| Tier binding | Exists with `active_key` unique | `processor_plan_id`, `provisioned_by`; foreign key |
| Subscription | `laravel_direct_payment_subscriptions`, `dp_subscriptions_uq` | stable reference; contract columns |
| Subscription leg, migration, migration run | **Do not exist** | whole tables (steps 07) |
| Dispute | **Does not exist** (disputes are only logged) | `laravel_processor_disputes` (step 16) |
| Audit log, scheduled changes | **Do not exist** | steps 16 and 06 |
| `laravel_processor_charges` | Exists, unused (the charge model reads `laravel_direct_payment_invoices`) | dropped in step 10's clean-up |

## §2.2 — invariants: how many hold today

| # | Invariant | Holds today? |
|---|---|---|
| I1 | A processor is never deleted | **No** — `destroy()` deletes; cascades remain |
| I2 | Provider, mode, account never change | **No** — `update()` accepts `mode` and keys |
| I3 | New business goes to the DP's active binding, decided by the server | **No** — the request names the processor |
| I4 | Existing-object actions go to the object's own processor | **Partly** — refund and subscription actions do; the ownership check beside refund compares mixed id types |
| I5 | Archived processor answers existing objects and webhooks forever | **No** — there is no archive state; a retired, inactive processor drops out of webhook candidates |
| I6 | Provider ids are never a join key for the browser | **No** — `afs_customer_id` lives in `sessionStorage` |
| I7 | One active customer per (payer, processor), old kept | **Yes in the schema**; **not** guaranteed in code (two writers, in-place overwrite) |
| I8 | At most one billing leg per contract | **Not applicable yet** — no legs exist |
| I9 | Swap only between compatible processors | **No** — mode, webhook and Connect rules are missing from the swap |
| I10 | Direct Payments knows nothing about the host application | **No** — `Membership` and `brain_*` are referenced from DP code |

Result: of ten invariants, none is fully enforced today; I4 and I7 are half in place. This is the baseline the later
steps measure against.

## §2.3 — resolvers

`ProcessorRouter` does not exist yet. *Update 2026-10-17: `ProcessorLookup` now exists (step 16), and invariants I1 and I5 now hold in code, I3/I4 partly, I9 holds.* The hand-written lookups they replace are the 10 `orWhere`
sites listed in the 01 record (A4/A9).

## §2.4 — schema summary

Checked against both create migrations: every "existing" row is present, every "+" column is absent. The owner decision
that changes go straight into the two create migrations (no upgrade migrations) is recorded in step 16 §1.3 and agrees
with this file.

## Notes for the next step

- Step 16 is the first step that turns this model into code (archive, one processor lookup, compatibility rules, atomic
  swap, disputes). It is approved and untouched.
- The model leaves the façade contract of selling modules, `PaymentGateway` and `InvoiceSnapshot` alone (§2.5); that
  claim was not disturbed because nothing was changed.
