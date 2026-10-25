# 11 — Rollout and Testing

## 11.1 Phases

Each phase ships on its own, leaves the system working, and has a completion record in `complete/`. Nothing starts before
the owner approves it and answers the open questions it depends on ([12](12-open-questions.md)).

| Phase | Contents | Findings | Depends on | Owner decisions applied |
|---|---|---|---|---|
| **PA0 — Safety rails** ([implementation plan](16-safety-rails-implementation.md)) | Processor archive (columns, endpoints, model guard, `DELETE` → 405), Go live action and the admin explanations of [03](03-processor-lock-in-and-archive.md) §3.7, Direct Payment archive (OQ2), audit log, FK cascade → restrict, `ProcessorLookup` with the precedence fix applied to every lookup, `SwapCompatibility` (Connect parity, mode, archived target, webhook readiness), atomic swap, `saveDirectPayment()` column/pay-type/items/tiers fixes, `bound_by` from auth, subscription-link fixes (stable reference, no tier overwrite), provider-ended subscriptions, webhook candidate rule + orphans, disputes table | A1, A2, A4, A5, A9–A19, A32, A33, A36, A55, A56, A58 | — | OQ1, OQ2, OQ5, OQ6 |
| **PA1 — Account lock** | `provider_account_id` + verification on create/rotate, identity lock, server-issued references, swap kind by fingerprint, processor currency | A3, A6, A7, A8 | PA0 | OQ7 (PayPal merchant id field) |
| **PA2 — Server routing** | `binding_version`, DP-addressed pay/subscription-start/config endpoints, retiring enforcement, compatibility shim for old routes, DP currency, **scheduled processor changes** (table, pre-check, per-minute runner, crontab check — [06](06-swap-engine-v2.md) §6.8) | A20, A21, A22 (server side), A31, A47 | PA0, **TODOS P0** | OQ9 |
| **PA3 — Payer and customer** | `PayerService` as single writer, `replaceCustomer`, payer token, guest/account e-mail rule, PayPal vault customer + single client id, `…/me/payment-methods`, card list = active binding only, retire open payment-method routes | A23–A29, A39, A44, A45, A59 | PA2 | OQ8 |
| **PA4 — Catalog and parity** | Tier canonical price, `TierPriceProvisioner`/`ProcessorPlanProvisioner`, canonical discounts, Square trial and plan change, Stripe coupon reuse, `ProviderCapabilities`, swap provisioning step | A40–A43, A46 (server), A16–A18 follow-through | PA1 | OQ4 (block) |
| **PA5 — Contracts and legs** | Legs table, contract columns, linker via legs, server-side subscription start writing contract + leg, processor-scoped subscription lookups | A30, A31, A37 | PA0, PA2 | — |
| **PA6 — Migration engine** | Migration runs/rows, S1/S2/S3, deferred start on all three providers, deterministic idempotency keys, per-subscriber strategy (copy cards where possible, otherwise easiest re-entry), hosted renewal page with wallets first, in-site `action_required` flag, the four processor-change emails and their default templates ([15](15-default-emails.md)), `direct_payments:subscription-migrate`, scheduler hook | A34, A35, A48, A49 | PA3, PA4, PA5 | OQ3, OQ10, OQ12, OQ13, OQ14 |
| **PA7 — Separation** | Extension points of [09](09-extension-points.md) with Direct Payments defaults; removal of every appfactory dependency and the renames of [13](13-naming-and-separation.md) §13.3 (classes, routes with one-release aliases, config, log tags, Connect owner column, markers). **Must ship together with appfactory's M1–M8** ([14](14-appfactory-studio-modifications.md) §14.2) so appfactory keeps working | A50–A53, A61 | PA0 (can run in parallel with PA1–PA6) | OQ15 |
| **PA8 — Admin** | Processor Archive/Paused states, Change-processor wizard, binding history, migration dashboard | (surfaces PA0–PA6) | PA1, PA4, PA6 (archive UI can ship with PA0) | OQ9 (schedule picker), OQ12 (wizard text) |
| **PA9 — SDK, playground, clean-up** | SDK changes of [08](08-sdk-and-implementer-contract.md), playground walkthrough, README contract, remove deprecated routes/endpoints after one release, drop `laravel_processor_charges`, move remaining readers off the contract's cached provider columns | A22, A23, A29, A46, A47, A57, A60 | all | — |
| **DE — Default emails** ([15](15-default-emails.md), approved) | `MailHandlerSender` gains `mailhandler_json` (send a default without a database row) and returns a result; `DirectPaymentDefaultEmail` reads the templates in afsemail's `defaults/dp` folders; `NotificationDispatcher` sends through `MailHandlerSender` (E3); nullable notification handler and the new events; receipt rule (E1); afsemail gallery reads sub-folders; admin "Default template" option. E2 fixed in `MailHandlerSender` after one test send confirms it. The four processor-change templates ship with PA6 | E1–E5 ([15](15-default-emails.md) §15.2) | — (may ship before or alongside PA0) | scope = all DP emails; everything sent through `MailHandlerSender`; templates in afsemail `defaults/dp` |

**The ordering gates that must not be relaxed**

1. **PA0 before anything else.** It removes the ways to destroy history (delete, cascade) and to charge an archived
   processor; every later phase assumes them gone.
2. **PA2 lands with or after TODOS P0.** Server-side routing presumes a server-created invoice.
   **DE (default emails) has no ordering constraint** except that its four processor-change templates ship with PA6.
3. **PA5 lands before PA6 is enabled for any DP.** A migration needs every subscription to have its leg.
4. **PA6 is enabled per Direct Payment**, first on a sandbox DP on dev, then on one real tenant DP chosen by the owner.

## 11.2 Test matrix

All under `tests/Feature/DirectPayments/` (the existing 22 tests there stay green throughout). Provider calls go through
fakes that **record which account was called, with which ids, start dates and idempotency keys** — the evidence bar the
first plan set.

| Phase | Test | Proves |
|---|---|---|
| PA0 | 14 tests, listed with what each asserts in [16](16-safety-rails-implementation.md) §8.2 | archive and no-delete, the A4 lookup regression, RESTRICT keys, DP archive, the five save fixes, the compatibility rulebook, atomic change and revert, Go live, the subscription link, provider-ended subscriptions, webhook candidates and orphans, disputes |
| PA1 | `ProcessorAccountLockTest` | mode immutable; keys of another account refused; same-account rotation accepted and audited |
| PA2 | `BindingVersionTest` | stale version → `DP_E_BINDING_CHANGED` with new config; no version → routed to active binding |
| PA2 | `RetiringEnforcementTest` | no new provider object on a retiring or archived processor through any route |
| PA2 | `ScheduledChangeTest` | runs within a minute of its time; blocker or material change → no change and operator notified; count changes do not stop it; one per DP; an immediate change supersedes it |
| PA3 | `PayerCustomerSingleWriterTest` | forged `customer_id` changes nothing; guest with a Site User's e-mail gets a guest payer; recreate supersedes |
| PA3 | `PayPalVaultCustomerTest` | first vault records PayPal's customer id on the payer; second reuses it |
| PA4 | `TierProvisioningTest` | dry run writes nothing; real run creates one plan per tier on the target and binds it; re-run is a no-op |
| PA4 | `CanonicalDiscountTest` | one Stripe coupon per discount; Square/PayPal provisioning per §5.3 |
| PA5 | `SubscriptionLegsTest` | subscription start writes the contract and its first leg in one request; paid-through from the period end |
| PA6 | `MigrationVaultCopyTest` (S1) | target leg first charge = old period end; old leg ended only after verification |
| PA6 | `MigrationReauthoriseStripeTest` / `…SquareTest` / `…PayPalTest` (S2) | deferred start per provider; idempotent double submit |
| PA6 | `MigrationSafetyTest` | early-start target leg cancelled; old leg untouched; no two renewing legs ever |
| PA6 | `MigrationNoActionTest` / `MigrationDeadlineTest` | old leg renews and migration rolls; deadline ends at period end; reactivation keeps the contract reference |
| PA7 | `SubscriberIdentityChainTest` | a Site User manages their own subscription with no host application registered; removing every host binding leaves Direct Payments working |
| PA7 | `VocabularyGateTest` (CI script) | the §13.6 gate is empty; `plans/markers.md` records the one-time re-tag (OQ15) |
| DE | `DefaultEmailsTest` | the acceptance list of [15](15-default-emails.md) §15.7: default sent through `MailHandlerSender`, no `${…}` left, brand fallbacks, HTML stripped from names, gallery shows the defaults, no notification handler sent as a receipt |
| PA9 | `SdkContractTest` (Vitest) | no provider id stored; re-mount on `DP_E_BINDING_CHANGED` |

### End-to-end on dev, with sandbox accounts only

Dev is a real environment; these scenarios use **sandbox** processors only, never live keys, and are run by the owner or
with the owner present.

| Scenario | Tools |
|---|---|
| Stripe A → Stripe B with a live test subscription, S2, then advance time past the boundary | Stripe **test clocks** advance the subscription's clock so the hand-over is observed, not assumed |
| Stripe → PayPal, S2 through the hosted page | PayPal sandbox buyer; verify `start_time` and that no charge occurs on approval |
| Stripe → Square, S2 | Square sandbox; verify `start_date` and card on file |
| A page open during a swap | the playground walkthrough of [08](08-sdk-and-implementer-contract.md) §8.7 |
| Swap and revert | binding history shows A → B → A; returning payer keeps cards on A |
| Archive the old processor, then refund an old charge and replay an old webhook | refund lands on the archived account; webhook processed |

## 11.3 Rollback

| Phase | Rollback |
|---|---|
| PA0 | Code revert restores old behaviour; new columns/tables are unused by old code. FK restrictions may stay (they only refuse deletes) |
| PA1 | Lock is a column check; revert code to unlock. Fingerprints stay as data |
| PA2 | The compatibility shim means old SDK builds never depended on the new endpoints; revert routes to the old ones |
| PA3 | `payer_customers` rows written are correct data; old code ignores `source`/`superseded_reason` |
| PA4 | Provisioned plans are real provider objects; leaving them is harmless. Tier canonical columns are additive |
| PA5 | Legs are additive; the contract's cached provider columns are still written, so old readers keep working |
| PA6 | Disable per DP (`direct_payments.migrations_enabled` per run). **In-flight migrations are resumed, not abandoned** — a disabled engine still finishes "end old leg" for any migration already `scheduled`, so no subscriber is left with two renewing legs |
| PA7 | Interfaces bound to the moved implementations; revert = re-inline |

## 11.4 Definition of done (whole plan)

- A tenant can switch a live Direct Payment from Stripe to PayPal (and to Square, and to another Stripe account) from the
  admin, with no code change on their website, while visitors are paying.
- New single payments go to the new provider immediately; past payments refund on the account that took them.
- Every live subscription either migrates automatically (vault copy) or after one customer action, with no gap and no
  double charge — or keeps renewing on the old account until it does.
- No processor can be deleted; every one can be archived and keeps answering refunds, disputes and webhooks.
- A Connect Direct Payment can only ever move between Stripe accounts, and only safely.
- `grep` gates G1–G8 are clean and run in CI.

## 11.5 Grep gates

```bash
cd w/www

# G1 — no provider customer id or processor override in the SDK (I6)
grep -rnE "afs_customer_id|SESSION_KEY_CUSTOMER_ID|processor_override" resources/js/external | grep -v "deprecated"

# G2 — no hand-written processor lookups outside the router (A4/I3/I4)
grep -rn "Processor::where('reference_num'" app | grep -v "Routing/ProcessorRouter.php\|Processor/ProcessorLookup.php"

# G3 — no processor deletes (I1)
grep -rnE "Processor(::|->)[a-zA-Z(]*->delete\(|\\\$processor->delete\(" app

# G4 — BACKWARD questions never ask the binding (first plan's gate 3, now enforced)
grep -rn "BindingRegistry\|forNewBusiness" app/Externals/DirectPayments/Money app/Externals/DirectPayments/Subscriptions

# G5 — Direct Payments does not depend on appfactory studio (I10); the wider vocabulary gate is 13 §13.6
grep -rnE "App\\\\Models\\\\Membership|brain_|CheckpointIdentityResolver" \
  app/Externals/DirectPayments app/Externals/Processor app/Http/Controllers/Externals/DirectPayments app/Http/Controllers/Externals/Processor

# G6 — laravel_processor_customers written only by PayerService
grep -rn "ProcessorCustomer::create\|ProcessorCustomer::firstOrCreate\|ProcessorCustomer::updateOrCreate" app | grep -v PayerService

# G7 — no reader of the contract's cached provider columns outside the leg layer (before they are dropped)
grep -rn "->provider_subscription_id" app | grep -v -i "leg"

# G8 — no uniqid() idempotency keys in provider calls (A48)
grep -rn "uniqid(" app/Http/Controllers/Externals/Processor app/Externals
```

Each gate is expected to be empty (G4, G7 reviewed by hand where a hit is legitimate). They run in CI from PA0 for the
gates whose phase has landed.
