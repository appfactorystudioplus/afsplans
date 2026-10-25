# 03 — Processor Lock-in and Archive

> Owner requirement: *"lock in a payment processor so that once it's created it can never be deleted, only archived,
> so that all purchases can be referenced back to their provider."*

Fixes **A1–A9**, **A55**.

## 3.1 The lifecycle

```
          create (keys verified, account fingerprint captured)
             │
             ▼
        ┌─────────┐   first binding, first charge   ┌──────────────────┐
        │  draft  │ ──────────────────────────────▶ │ active (locked)  │◀──────────┐
        └─────────┘                                  └──────────────────┘           │ unarchive
             │  (never used — may still only be archived)   │ archive              │ (audited)
             ▼                                               ▼                      │
        ┌──────────┐                                  ┌──────────────┐              │
        │ archived │                                  │   archived   │──────────────┘
        └──────────┘                                  └──────────────┘
```

There is **no delete state and no delete transition**. "Draft" is not a stored status; it is simply
`identity_locked_at IS NULL`. Even a processor that was never used is archived rather than deleted — one rule, no
judgement about what "never used" means (a webhook may already have arrived, a customer may already exist at the
provider).

| | active | archived |
|---|---|---|
| New intents, sessions, orders, customers, saved cards, subscriptions | ✅ (only through a DP's active binding — [06](06-swap-engine-v2.md) §6.3) | ❌ `PROC_E_ARCHIVED` |
| Becoming the target of a swap / a new DP's processor | ✅ | ❌ |
| Refunds, captures of already-authorised payments, disputes | ✅ | ✅ |
| Cancel / update / change card on existing subscriptions | ✅ | ✅ |
| Webhooks verified and processed | ✅ | ✅ (always — [10](10-webhooks-history-and-integrity.md) §10.1) |
| Reads (Money ledger, history, receipts) | ✅ | ✅ |
| Key rotation (same account) | ✅ | ✅ — an archived processor whose keys expire would otherwise lose refunds |
| Shown in the "choose a processor" lists | ✅ | ❌ (shown under an "Archived" filter) |

`is_active` keeps its column but gets one meaning only: **a temporary switch for new business** (an operator pausing a
processor). `archived_at` is the permanent "closed to new business". Reachability for existing objects never depends on
either. The admin shows three labels: *Active*, *Paused* (`is_active = false`), *Archived*.

## 3.2 Archive preconditions

`POST /portal/api/v1/processors/{ref}/archive` `{reason}`:

| Check | Result if it fails |
|---|---|
| The processor is the **active** binding of any Direct Payment | `409 PROC_E_ARCHIVE_ACTIVE_BINDING` listing the DPs — swap them first ([06](06-swap-engine-v2.md)) |
| Retiring bindings, live subscription legs, open disputes, charges inside the refund window | **Allowed, with a warning** in the response and the admin — those obligations are exactly what an archived processor keeps answering |
| CONNECT: connected accounts with a balance or pending transfers | Allowed with a warning; payouts and transfers on existing accounts keep working |

`POST /portal/api/v1/processors/{ref}/unarchive` reverses it, audited (who, when, why) — owner decision **OQ1**. The processor
simply reopens to new payments; nothing was ever destroyed.

`DELETE /portal/api/v1/processors/{id}` is kept as a route so old admin builds get a clear answer:
`405 PROC_E_DELETE_FORBIDDEN — "Processors are never deleted. Archive it instead."` The admin's
`deleteProcessor()` (`ProcessorsService.ts:380`) becomes `archiveProcessor()`.

Every archive/unarchive writes a row to `laravel_direct_payment_audit_log` (subject, action, actor, reason, details, at —
[16](16-safety-rails-implementation.md) §1.1); PA1 records key rotations (§3.4) there too, so an operator can answer "who changed this
processor and when".

## 3.3 Making "never deleted" true at every layer

A controller rule alone is not enough: a tinker session, a future command or a cascade from another table could still
remove the row.

| Layer | Change |
|---|---|
| Route / controller | `destroy()` → 405 as above |
| Model | `Processor::booted()` registers `static::deleting(fn () => throw new ProcessorDeletionForbidden())`. Eloquent deletes, including relation deletes, stop here |
| Database — inbound cascades (**A2**) | `laravel_processor_connected_accounts`, `laravel_processor_transfer_intents`, `laravel_processor_webhook_endpoints`: drop the `onDelete('cascade')` foreign keys and recreate them `onDelete('restrict')` |
| Database — new restrictions | Add `restrict` foreign keys from `laravel_direct_payment_processor_bindings.processor_reference_num`, `laravel_direct_payment_payer_customers.processor_reference_num`, `laravel_direct_payment_tier_bindings.processor_reference_num` and the new subscription legs to `laravel_processors.reference_num`. A raw `DELETE` then fails as soon as anything references the processor |
| Invoices | `laravel_direct_payment_invoices.processor_reference_num` stays a soft reference (it is nullable, written by many paths, and the invoice table is large). Integrity there comes from the model guard and from §3.6's router |

The foreign keys are declared in the create migrations; since the app is in development with no data, there are no
orphans to clean up first ([02](02-target-model.md) §2.4). Collation of the referencing columns must match
`laravel_processors` (`utf8mb4_unicode_ci`, the connection default).

## 3.4 Locking the account (A3, A8)

### The fingerprint

At creation the server calls the provider with the submitted secret and stores who the keys belong to:

| Provider | Call | Stored as `provider_account_id` | Strength |
|---|---|---|---|
| Stripe | `GET /v1/account` | `acct_…` | Exact — every key of an account returns the same id |
| Square | `GET /v2/merchants/me` (or `/v2/merchants` with the access token) | merchant id | Exact |
| PayPal | `POST /v1/oauth2/token` with client id + secret (gives the REST `app_id`), **plus the PayPal merchant id the tenant enters** when adding the processor (shown in their PayPal business settings), verified where PayPal exposes it (e.g. the payee on a sandbox/test order read) † | `paypal:{merchant_id}` (app id kept beside it) | Owner decision **OQ7**. Two sets of keys from different apps of the same merchant are the same account, so they are a key rotation, not a processor change |

The same call also proves the keys work, and captures the account's default currency where the provider exposes it
(Stripe `default_currency`, Square merchant `currency`), filling the new `currency` column.

### What is locked, and when

`identity_locked_at` is set the first time the processor is bound to a Direct Payment or takes any payment, whichever is
first. From then on:

| Field | Rule after lock |
|---|---|
| `provider` | Immutable (already not in `update()`'s fillable — kept so) |
| `mode` | **Immutable.** Today it is editable (**A3**). Moving to live is a new processor |
| `provider_account_id` | Immutable; never accepted from a request |
| `publishable_key`, `secret_key`, `webhook_secret` | Rotatable **only if** the new secret verifies to the same `provider_account_id`. Otherwise `422 PROC_E_KEYS_OTHER_ACCOUNT — "These keys belong to a different account. Add them as a new processor and use Change processor."` |
| `extra_config` | Split: account-level keys (`currency`, `location_id`, `paypal_client_id`) follow the key rule — `location_id` must exist in the account (`GET /v2/locations/{id}`); behaviour keys (saved-card defaults, Connect profile through `ConnectProfileService`) stay editable |
| `name`, `use_hosted`, `is_active` | Editable |
| `reference_num` | Issued by us only (**A6**): `store()` stops accepting it |

Before lock (a processor just created and not yet used) the tenant may still correct a typo — but every change re-runs
the verification, so even then a processor's fingerprint always describes its keys.

With the fingerprint in place, `SwapService::kindOf()` stops comparing publishable keys and compares
`provider_account_id`: equal → "same account" (not a swap: rotate keys on the existing processor), different and same
provider → same-provider swap, different provider → cross-provider swap.

### Existing processors

None to back-fill (development, no data). Processors created after PA1 get their fingerprint at creation; development
processors are re-entered after the database rebuild.

## 3.5 Webhooks never stop for an archived processor (A55)

The webhook candidate rule becomes: **every processor of that provider** — no `is_active`, no binding status, no
`archived_at` filter; candidates without a secret (or PayPal webhook id) are skipped by the verifier, as today. Signature verification is per secret, so widening the candidate list does
not weaken authentication; it only stops events for old money being thrown away. The per-processor webhook URL in
[10](10-webhooks-history-and-integrity.md) §10.1 removes the scan entirely for new registrations.

Archiving never removes the provider-side webhook registration. `DELETE /processors/{ref}/webhook` on an archived or
retiring processor is refused with `PROC_E_WEBHOOK_STILL_NEEDED` while it has retiring bindings, live legs, open
disputes or charges inside the refund window.

## 3.6 One lookup, no precedence bug (A4, A9)

Every hand-written `Processor::where('reference_num', …)->orWhere('id', …)->where(…)` is replaced by `ProcessorLookup`
(PA0, [16](16-safety-rails-implementation.md) §2.2), on which PA2's router of [02](02-target-model.md) §2.3 builds.
The numeric `id` is no longer accepted as a public processor identifier; admin routes that take `{id}` resolve
`reference_num` first and accept a numeric id only for one release, logging each use.

From PA2, the public config endpoint (`ProcessorClientController::show()`) answers only for a processor that is the active binding
of at least one non-archived Direct Payment — a browser has no reason to configure itself for any other — and its
response includes the DP-level `binding_version` when called with `?dp=` ([08](08-sdk-and-implementer-contract.md) §8.2).

## 3.7 What the admin tells the tenant (OQ5, owner decision 2026-10-16)

Short explanations shown at the moment a decision is made, so nobody learns these rules by running into them. The texts
PA0 ships (processor Environment hint, Direct Payment processor hints, archive confirmation) are the approved wording in
[16](16-safety-rails-implementation.md) §7.1–§7.4, which is the source of truth; the key-rotation, Go live and Change processor
texts below appear with the PA1 and PA8 screens.

**Processor form — under the Mode field (create):**

> **Sandbox or live?** Sandbox takes test payments only; no real money moves. Live takes real payments. A processor's
> mode, provider and account can't be changed after it is first used — to go live, add your live keys as a new
> processor, then use **Go live** on your Direct Payment. Your website keeps working with no code changes.

**Processor form — under the API keys (edit, once locked):**

> You can replace these keys with new keys **from the same {provider} account** (for example after rotating them).
> Keys from a different account are refused — add them as a new processor and use **Change processor** instead.

**Processor list — Archive action:**

> Archiving closes this processor to new payments. It is never deleted: past payments stay refundable, live
> subscriptions keep working, and its notifications keep arriving.

**Direct Payment form — under the Processor field (create):**

> This is the account that takes payments for this Direct Payment. You can change it later with **Change processor**
> — including to a different provider — without changing your website. If you're testing, pick a sandbox processor;
> when you're ready, use **Go live** to switch to a live one.

**Direct Payment — Go live action (shown only when the current processor is sandbox):**

> **Go live** switches this Direct Payment from sandbox to a live processor. Your website code and references stay the
> same. Test subscriptions and test payments are not carried over — they stay in sandbox history and the test
> subscriptions are ended. Live payments start immediately after you confirm.

**Change processor — when the target is sandbox and the current processor is live:**

> A live Direct Payment can't be moved to a sandbox processor — it would stop taking real payments. Create a separate
> Direct Payment for testing.

## 3.8 Acceptance

- `DELETE /processors/{id}` → 405, row still present; `Processor::find(x)->delete()` throws; a raw SQL `DELETE` on a
  processor that has a binding fails on the foreign key.
- Deleting is impossible for a connected account's processor; connected accounts, transfer intents and webhook endpoints
  survive any attempt.
- Updating a locked processor's `mode` → 422; rotating to keys of another Stripe account → `PROC_E_KEYS_OTHER_ACCOUNT`;
  rotating to a new key of the same account → succeeds and writes an audit row.
- Archiving a processor that is any DP's active binding → `PROC_E_ARCHIVE_ACTIVE_BINDING`.
- After archive: create-intent / create-session / create-order / create-subscription / create-customer naming that
  processor → `PROC_E_ARCHIVED` (proves **A4** is fixed); refund of a past charge → succeeds; cancel of a live
  subscription leg → succeeds; a signed webhook → processed.
