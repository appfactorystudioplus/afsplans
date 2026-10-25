# 06 — Swap Engine v2

The first swap ([`../direct_payments/03`](../direct_payments/03-the-swap.md)) is a correct database transition. This
document adds what makes it safe while a website is taking payments: the server decides where every payment goes, open
browser sessions notice the change, the target is proven ready before anything moves, and the transition is atomic and
reversible. Fixes **A11–A15**, **A19–A22**.

## 6.1 What a website should experience

```
 t0  Tenant opens "Change processor", picks the target, presses Preview.
     ├─ compatibility + readiness checks run (§6.4)            nothing written
     ├─ tier/discount provisioning dry run (05 §5.3)            nothing written
     └─ impact report: payers, live subscription legs, open invoices, what does not carry over

 t1  Tenant confirms (impact_hash echoed).
     ├─ provisioning runs for real (idempotent, outside the swap transaction)
     ├─ one transaction: old binding → retiring, new binding → active, binding_version += 1
     └─ event DirectPaymentProcessorSwapped → subscription migration planner (07)

 t1+ A visitor who loaded the page before t1 presses Pay.
     ├─ their SDK sends binding_version = n; server is at n+1
     ├─ server answers 409 DP_E_BINDING_CHANGED with the new public config
     └─ SDK re-mounts the new provider's payment UI in place; the visitor sees a fresh card form, nothing else

 t1+ A visitor who already confirmed a payment on the old account (intent created before t1)
     └─ completes on the old account; its webhook is accepted (retiring); invoice records the old processor

 later  Subscriptions move leg by leg (07). When nothing is outstanding, the tenant retires the old binding,
        and may archive the old processor (03). Nothing is ever deleted.
```

## 6.2 `binding_version` — how a live page learns about a swap

`laravel_direct_payments.binding_version` (unsigned int, default 1) increments inside the swap transaction. It is:

- returned by `GET /directpayments?direct_payment=…` and by every DP-addressed checkout response;
- sent back by the SDK on every checkout call;
- compared by `ProcessorRouter::forNewBusiness($dpRef, $callerVersion)`: a mismatch is `409 DP_E_BINDING_CHANGED`
  with `{binding_version, processor: <public config>}` so the SDK can re-initialise without a second round trip
  ([08](08-sdk-and-implementer-contract.md) §8.3).

A caller that sends no version (an old SDK build) is routed to the active binding, never refused — old pages keep
working, they just cannot detect the change themselves.

## 6.3 The server decides where a payment goes (A20, A21, A22)

**Depends on [`../direct_payments/TODOS.md`](../direct_payments/TODOS.md) P0** (server-created invoice). The two land
together: once an invoice is created by the server, the invoice is what the browser pays, and the processor is stamped on
the invoice by the server at the moment the provider object is created.

| Operation | Today | Target |
|---|---|---|
| Create intent / session / PayPal order / Square payment | `processor_reference_num` from the request | `POST /directpayments/{dpRef}/invoices/{invRef}/pay` — processor = `forNewBusiness(dpRef)`; stamped on the invoice with the provider object id. A pending invoice created before a swap is re-stamped to the new processor on its first pay call |
| Capture PayPal order, read intent status | processor from request | `forExisting(invoice)` |
| Create subscription | processor + plan id from request | `POST /directpayments/{dpRef}/subscriptions/start` `{tier_ref, payer token}` — processor from the binding, price from `TierBindingRegistry::priceFor()`, customer from `PayerService::customerFor()`, link row written **in the same request** (fixes **A31**) |
| Save a card | processor + customer from request | `…/me/payment-methods/setup` ([04](04-payer-customer-identity.md) §4.6) |
| Public processor config | any processor | `GET /directpayments/{dpRef}/checkout-config` → the active binding's public config + `binding_version` |

The `/processors/stripe/create-intent`-style routes stay for one release behind a compatibility shim: if the named
processor is not the active binding of the invoice's DP, answer `409 DP_E_BINDING_CHANGED` instead of charging. That is
the exact moment the retiring rule (**A21**) starts being enforced. `processor_override` is ignored and logged (**A22**).

Every host application that charges through Direct Payments uses these same endpoints, so it gets the same behaviour
with no application-specific code ([09](09-extension-points.md)).

## 6.4 `SwapCompatibility` — one check for both paths (A12, A14, A15)

One service, called by the preview, by the execute, and by `saveDirectPayment()` (which keeps refusing any processor
change with `DP_E_PROCESSOR_CHANGE_REQUIRES_SWAP`, but now validates the **initial** processor of a new DP with the same
checks).

**Blockers** — the swap is refused:

| Code | Rule |
|---|---|
| `SWAP_E_SAME_ACCOUNT` | Same account as the current processor — a key rotation on the existing processor, not a change ([03](03-processor-lock-in-and-archive.md) §3.4). PA0 detects it by equal publishable keys; PA1 by `provider_account_id` |
| `SWAP_E_ALREADY_ACTIVE` | The target is already the DP's processor |
| `SWAP_E_TARGET_ARCHIVED` / `SWAP_E_TARGET_PAUSED` | Target archived, or `is_active = false` |
| `SWAP_E_TARGET_UNVERIFIED` | PA0: the target has no secret key. PA1: its keys do not verify right now (live call, not cached) |
| `SWAP_E_MODE_LIVE_TO_SANDBOX` | Live → sandbox, always (**A14**, owner decision **OQ5**) |
| `SWAP_E_MODE_USE_GO_LIVE` / `SWAP_E_GO_LIVE_NOT_APPLICABLE` | Sandbox → live through an ordinary change is refused: it is its own **Go live** action (same DP reference, same checks, sandbox subscriptions ended, recorded as `go_live`); Go live itself only goes sandbox → live |
| `SWAP_E_WEBHOOK_NOT_READY` | The target cannot verify its webhooks: no signing secret (Stripe, Square) or no `webhook_id` (PayPal) — exactly what the webhook handler verifies with (**A15**). The preview offers "Register now" (`ProcessorWebhookAdminController::register()`) |
| `SWAP_E_CURRENCY` | Target currency ≠ the DP's (PA0: from `extra_config`; PA1/PA2: the account's verified currency and the DP's own `currency`, [05](05-provider-parity-and-portability.md) P9) |
| `SWAP_E_PORTABILITY` | Target outside the DP's portability class ([05](05-provider-parity-and-portability.md) §5.2), naming the feature (e.g. "live subscriptions use a discount — PayPal discounts are not available yet") — PA4 |
| `DP_E_CONNECT_PROVIDER_CHANGE` / `DP_E_CONNECT_PROVIDER_UNSUPPORTED` / `DP_E_CONNECT_TARGET_NOT_ENABLED` / `DP_E_CONNECT_ACCOUNTS_PRESENT` | The Connect rules of [05](05-provider-parity-and-portability.md) §5.5 — the codes `saveDirectPayment()` already used, now emitted by both paths |
| `SWAP_E_PROVISIONING` | A SUBSCRIPTION DP whose tiers cannot be provisioned on the target. Owner decision **OQ4**: block until every tier has a working plan; the wizard names the failing tier and offers retry — PA4 |
| `SWAP_E_MIGRATION_IN_PROGRESS` | A previous change's subscription migration run is still active ([07](07-subscription-continuity.md) §7.6) — one migration at a time per DP — PA6 |

**Warnings** — shown, acknowledged through `impact_hash`: capability differences (PayPal card change, Square retry
policy), retry policy to reconfigure, saved cards that will be re-collected (now a real count of payers with a saved
method on the current processor, read from `laravel_direct_payment_default_methods` plus a capped provider read — the
"upper bound" of today's preview is replaced), live subscription legs and the migration strategy the tenant picked for
them, open invoices, open disputes, other DPs still bound to the old processor.

## 6.5 Executing the swap (A13, A19)

```php
// SwapService::execute() — shape, not a patch.
$provisioned = $this->provisioner->ensureAll($dp, $target, dryRun: false);   // idempotent; outside the transaction

DB::transaction(function () use ($dp, $target, $hash, $actor, $note, &$binding) {
    $locked = DirectPayment::whereKey($dp->id)->lockForUpdate()->firstOrFail();
    $preview = $this->preview($locked, $target);                              // recomputed under the lock
    $this->assertNoBlockers($preview);
    $this->assertHash($preview, $hash);                                       // DP_E_IMPACT_CHANGED

    $binding = $this->bindings->swap($locked, $target, boundBy: $actor, note: $note);
    //  BindingRegistry::swap(): validates the target FIRST, then retires the current binding,
    //  creates the new one, increments binding_version, updates the cache column.
    $this->audit->record('swap', $locked, $target, $actor, $preview);
});

event(new DirectPaymentProcessorSwapped($dp->reference_num, $from, $target->reference_num, $binding->id));
```

- PA0 implements this transaction ([16](16-safety-rails-implementation.md) §4.2–§4.3) without provisioning and
  `binding_version`, which arrive with PA4 and PA2.
- `bound_by` is the authenticated operator from the request's auth context, never a body field (**A19**).
- `saveDirectPayment()` stops writing `processor_reference_num` from the request on an existing DP (**A11**); the column is
  written only by `BindingRegistry`.

## 6.6 Payments already in flight

| Situation at swap time | Outcome |
|---|---|
| Provider object created on the old processor (PaymentIntent, PayPal order, Square payment started) | Completes on the old processor. Its webhook is accepted (retiring). The invoice records the old processor. Refunds go there forever |
| Invoice created, no provider object yet | First pay call after the swap re-stamps it to the new processor |
| Subscription start in progress (provider subscription created, approval pending — PayPal) | Completes as a leg on the old processor; becomes part of the migration run like any other live leg |
| Visitor on a stale page | `DP_E_BINDING_CHANGED` → SDK re-mounts the new provider's form (§6.2) |

## 6.7 Reverting

A swap back to the processor of a **retiring** binding is an ordinary swap (it passes the same checks) with one
difference the registry handles: it reactivates that binding row instead of creating a new one, so the history reads
"A active → B active → A active again" without a duplicate A row. Payer customers on A were never superseded by the
swap, so returning payers keep their cards. Subscription legs created on B during the attempt are handled by the
migration run's cancel path ([07](07-subscription-continuity.md) §7.6). An archived processor cannot be reverted to until
unarchived (allowed, audited — **OQ1**).

## 6.8 Scheduled processor changes (OQ9 — owner decision: include now)

A tenant can confirm a change now and have it take effect at a chosen moment (a quiet hour, the start of a month, the
day a new account is approved). The same rules apply as for an immediate change; scheduling only moves *when* the
transaction of §6.5 runs.

### Storage

New `laravel_direct_payment_scheduled_changes`:

| Column | Meaning |
|---|---|
| `reference_num` | `swc_` + 12 |
| `direct_payment_reference_num`, `target_processor_reference_num` | what changes |
| `kind` | `change` · `go_live` · `revert` |
| `effective_at` | UTC; the admin shows and accepts it in the website's time zone |
| `options` (json) | the choices made in the wizard: closing date for the old account (S3), note |
| `scheduled_hash` | the material fingerprint at scheduling time (below) |
| `status` | `scheduled` · `running` · `completed` · `failed` · `cancelled` · `superseded` |
| `scheduled_by`, `cancelled_by` | the authenticated operator |
| `precheck_at`, `precheck_result`, `ran_at`, `result` (json), `error` | what happened |
| `pending_key` | DP reference while `scheduled`/`running`, else NULL — unique, so **one scheduled change per Direct Payment** |

### Lifecycle

1. **Schedule** — the wizard runs the full preview, then performs provisioning for real (tier plans and discounts on
   the target are created now, so the run itself has less to do and any provider rejection surfaces days early, not at
   3am). It stores the material fingerprint and the options. A Direct Payment with a scheduled change shows a banner in
   its admin: *"Processor changes to {target} on {date, time}. Cancel or reschedule."*
2. **Pre-check** — one hour before `effective_at` (or immediately, if scheduled less than an hour ahead) the readiness
   checks of §6.4 run again (keys verify, webhooks registered, target not archived or paused). A failure notifies the
   operator who scheduled it and every dashboard admin, so it can be fixed before the moment arrives.
3. **Run** — at `effective_at`, `direct_payments:run-scheduled-changes` (every minute through the Laravel scheduler)
   locks the row, recomputes the preview, and decides:
   - any **blocker** → `failed`, nothing changes, operator notified with the reason;
   - the **material fingerprint** changed → `failed`, operator notified with the new preview to re-confirm;
   - otherwise → executes §6.5 exactly as a confirmed immediate change, records the actual impact in `result`,
     notifies the operator. Subscription handling (07) starts from that moment.
4. **Cancel / reschedule** — allowed while `scheduled`. Any immediate change, archive of the target, or archive of the
   DP marks the scheduled change `superseded`.

### What "material" means

`impact_hash` (§6.4) includes counts — payers, charges, subscriptions — that change every hour on a live site; a
scheduled change compared on it would almost never run. The scheduled fingerprint covers only what changes the
*decision*: the target and its account fingerprint, the kind, the DP's portability class, the set of capability
differences and warnings by type, and the tier/discount provisioning state. Counts are recomputed at run time and
reported, not compared.

### Dependencies

The run must happen within a minute of `effective_at`. The Direct Payments scheduler today is bootstrapped by
`commands.sh --update-cron-task` (`../direct_payments/21`); PA2 verifies that the installed crontab runs Laravel's
`schedule:run` every minute and changes the bootstrap if it does not.

### Acceptance

- A change scheduled for T executes within one minute of T; before T, every new payment still goes to the current
  processor.
- A blocker appearing between scheduling and T (target paused, webhook removed) → no change, operator notified at the
  pre-check and again at T.
- New payers and charges between scheduling and T do not stop the run; a new capability difference does.
- Two scheduled changes for one DP cannot exist; an immediate change cancels the scheduled one as `superseded`.
- Go live and revert can be scheduled the same way.

## 6.9 Retiring the old binding

Unchanged from the first plan, with its inputs fixed: live **legs** now end when the provider ends them
([10](10-webhooks-history-and-integrity.md) §10.2, **A36**), disputes are stored and checked (**A58**), the refund window
is configurable per binding (`refund_window_days`, default 180 — PA2). Retire never deletes anything and never disables
webhooks.

## 6.10 Admin surface

No admin UI exists for any of this today (`../direct_payments/complete/03`). The Direct Payment edit form replaces its
processor dropdown on an existing DP with **Change processor…**, a four-step wizard:

1. **Choose** — only compatible, verified, non-archived processors are selectable; incompatible ones are listed with the
   reason, using the explanations of [03](03-processor-lock-in-and-archive.md) §3.7 (sandbox / live, Connect, other account).
2. **Prepare** — readiness checklist with one-click fixes (register webhooks, provision tiers/discounts).
3. **Review** — impact and consequences; choose the subscription strategy ([07](07-subscription-continuity.md) §7.4).
4. **Confirm** — **now** or **at a date and time** (§6.8); then shows the binding history and the migration dashboard.

The Choose step's wizard text also recommends, for Stripe → Stripe, asking Stripe to copy saved cards first and importing
the mapping file afterwards (OQ12) — never waited on.

The processor list gains *Paused* / *Archived* states and an **Archive** action in place of delete.

## 6.11 Acceptance

- A swap whose target fails any blocker writes nothing (bindings, version, column, provisioning dry run only).
- Killing the process between "retire current" and "create new" is impossible: both are one transaction; a failed target
  lookup leaves the DP exactly as it was (**A13**).
- A browser holding `binding_version = n` after a swap gets `DP_E_BINDING_CHANGED` and, after re-init, pays the new
  processor; no request can create a new provider object on a retiring or archived processor.
- A CONNECT DP cannot be swapped to PayPal or Square, nor to another Stripe platform while connected accounts exist,
  through **either** path (**A12**).
- Live → sandbox is refused and sandbox → live is possible only through **Go live** (**A14**); a target without a
  registered webhook is refused (**A15**).
- A swap and an immediate revert leave exactly two binding rows for the DP and no superseded payer customers.
