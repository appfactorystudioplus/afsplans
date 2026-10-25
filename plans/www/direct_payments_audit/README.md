# direct_payments_audit — Planning Index

> **Status (2026-10-17): implemented.** Every numbered document has a completion record in [`complete/`](complete/) (00–16); the rollout record is
> [`complete/11-rollout-and-testing.complete.md`](complete/11-rollout-and-testing.complete.md). What could not be done without the remote server (real provider
> sandboxes, a real e-mail send, browser checks) is listed there and in each record. Nothing is committed yet. All owner questions are answered ([12](12-open-questions.md)). Every finding below was read in the code on
> 2026-10-16. Paths are relative to `w/www/` unless stated otherwise. Line numbers will drift; symbol names will not.

This folder is a full audit of **Direct Payments** and the **Processor** layer, and the plan to make the one promise
they exist for actually hold:

> A developer puts the Direct Payments API on a website once. The website owner can later replace the payment provider
> behind it — Stripe for another Stripe account, or Stripe for PayPal or Square — and the website, its single payments
> and its subscriptions keep working, with no code change and no payment interruption.

It builds on, and does not replace, [`../direct_payments/`](../direct_payments/README.md). That folder designed and
shipped (DP0–DP6, 2026-09-12/13) the payer, the processor binding, tier bindings and a first swap. This audit checks what
shipped against the code, finds what the first pass did not cover, and plans the parts that were left as follow-ups —
above all **subscriptions that keep renewing across a provider change** ([`../direct_payments/06`](../direct_payments/06-rollout-and-testing.md)
§6.8 item 3), **processors that can never be deleted**, and **parity between Stripe, PayPal and Square**.

## Scope rule — Direct Payments knows nothing about billing or memberships

**Owner decision, 2026-10-16:** the terms *billing*, *member* and *membership*, and the `brain_memberships` table, belong
to the appfactory studio ecosystem. Direct Payments and processors are a separate system that appfactory **uses**, the
same way any tenant website uses it. Therefore:

- Direct Payments and processor code, routes, configuration, SDK, playground and these plan documents use Direct
  Payments vocabulary only — payer, subscriber, subscription, charge, renewal ([13](13-naming-and-separation.md)).
- Direct Payments never reads appfactory tables and never imports appfactory code. A host application adds behaviour
  only through generic extension points ([09](09-extension-points.md)).
- Anything that has to change on the appfactory side is an **appfactory studio modification, not a Direct Payments API
  change**, and is listed separately in [14](14-appfactory-studio-modifications.md).

## Reading order

| Step | Document | Contents |
|---|---|---|
| — | [README.md](README.md) | This index |
| 00 | [00-overview-and-verdict.md](00-overview-and-verdict.md) | What works, what does not, the target in one picture, the ten things that matter most |
| 01 | [01-audit-findings.md](01-audit-findings.md) | Every finding, **A1–A61**, with file, line, consequence and severity |
| 02 | [02-target-model.md](02-target-model.md) | The model the rest of the plan builds: processor, Direct Payment, binding, payer, catalog, subscription contract and legs |
| 03 | [03-processor-lock-in-and-archive.md](03-processor-lock-in-and-archive.md) | A processor is never deleted, only archived; its account identity is locked once created |
| 04 | [04-payer-customer-identity.md](04-payer-customer-identity.md) | One `payer_reference_num`, many provider customer ids, created and held only by the server |
| 05 | [05-provider-parity-and-portability.md](05-provider-parity-and-portability.md) | The Stripe / PayPal / Square capability matrix, the parity work, and the portability class of every feature (Connect = Stripe only) |
| 06 | [06-swap-engine-v2.md](06-swap-engine-v2.md) | The swap made safe for a live website: server-side routing, binding version, preflight, provisioning, revert |
| 07 | [07-subscription-continuity.md](07-subscription-continuity.md) | **The core of this plan.** Subscriptions that keep renewing across a swap: legs, the three migration strategies, no gap and no double charge |
| 08 | [08-sdk-and-implementer-contract.md](08-sdk-and-implementer-contract.md) | What the SDK, the playground and the developer contract change |
| 09 | [09-extension-points.md](09-extension-points.md) | The generic interfaces and events a host application plugs into; the defaults Direct Payments ships |
| 10 | [10-webhooks-history-and-integrity.md](10-webhooks-history-and-integrity.md) | Webhooks for archived processors, provider-side subscription ends, the charge/invoice data model, integrity defects |
| 11 | [11-rollout-and-testing.md](11-rollout-and-testing.md) | Phases **PA0–PA9**, ordering gates, test matrix, grep gates, rollback |
| 12 | [12-open-questions.md](12-open-questions.md) | Owner decisions — all answered 2026-10-16 (OQ11 set aside) |
| 13 | [13-naming-and-separation.md](13-naming-and-separation.md) | The vocabulary rule and every billing / member reference in Direct Payments and processor code to rename or remove |
| 14 | [14-appfactory-studio-modifications.md](14-appfactory-studio-modifications.md) | **appfactory studio modifications — not Direct Payments.** What appfactory code changes to keep working, and the set-aside OQ11 explained |
| 15 | [15-default-emails.md](15-default-emails.md) | Default templates for every Direct Payments email (in afsemail's `mjml-templates/{mjml,html}/defaults/dp/`), sent through `MailHandlerSender` with or without a database row, and the receipt / variable bugs found |
| 16 | [16-safety-rails-implementation.md](16-safety-rails-implementation.md) | **Phase PA0 (safety rails): the exact code proposed, step by step — approved, not yet applied** |

## Relationship to other plan folders

| Folder | Relationship |
|---|---|
| [`../direct_payments/`](../direct_payments/README.md) | The first portability plan. This audit verifies it (01 §1.1) and extends it. Where the two disagree, this folder says so explicitly and gives the reason |
| [`../direct_payments/TODOS.md`](../direct_payments/TODOS.md) | "Every charge goes through a server-owned invoice" (F1–F11, P0–P2). **A prerequisite for [06](06-swap-engine-v2.md) §6.3**, not duplicated here. Phase PA2 lands with or after TODOS P0 |
| [`../externals_security/`](../externals_security/) | Route authentication (`externals.dashboard` modes). Security findings here are listed only where they affect portability; the fix belongs there |
| [`../../market/`](../../market/) step 08 §08.5.8 | `laravel_processor_migrations*` — the Connect platform-migration schema, built but not wired. [05](05-provider-parity-and-portability.md) §5.5 decides how Connect DPs are swapped |
| [`../../billing/`](../../billing/README.md) | appfactory studio. Never touched by Direct Payments; its own changes are in [14](14-appfactory-studio-modifications.md) |

## Conventions for the code this plan will produce

Same as [`../direct_payments/README.md`](../direct_payments/README.md): PHPDoc/JSDoc on every new or changed symbol,
the new **`AFS_DIRECT_PAYMENTS_MODIFIER_ADDITION`** marker followed by the plan reference (e.g.
`AFS_DIRECT_PAYMENTS_MODIFIER_ADDITION — plans/www/direct_payments_audit/07-subscription-continuity.md §7.4`).
`AFS_BILLING_MODIFIER_ADDITION` is appfactory's and is not used in Direct Payments code; the existing ones are re-tagged
once, on the owner's instruction ([13](13-naming-and-separation.md) §13.3). New TypeScript: no optional chaining, no `any`. Each numbered document gets a
completion record in `complete/` when its work lands.

**Nothing in this folder is to be implemented until the owner has answered the open questions in [12](12-open-questions.md)
that the phase depends on and approved the phase.** Code shown in these documents is illustrative of the design, not a patch.
