# 14 — appfactory studio Modifications

> **These are appfactory studio modifications, NOT Direct Payments API changes.** They change appfactory code — the code
> that charges appfactory memberships, creates rows in `brain_memberships` and enforces membership tiers — so that it keeps
> working when Direct Payments and processors stop knowing about it ([13](13-naming-and-separation.md)). Direct Payments
> does not depend on any of them.
>
> Code changes here carry the appfactory markers (`AFS_BILLING_MODIFIER_ADDITION`, `AFS_MEMBERSHIP_MODIFIER_ADDITION`),
> never the Direct Payments marker. Their natural home is [`../../billing/`](../../billing/README.md); they are listed here
> only so the two sides can be scheduled together. Nothing here is built without the owner's go-ahead.

## 14.1 How appfactory relates to Direct Payments after the separation

appfactory is a **user** of Direct Payments, exactly like a tenant website: it has its own Direct Payment(s), charges
memberships through the public API, and reacts to Direct Payments events. It knows Direct Payments; Direct Payments does
not know it.

```
 appfactory studio (App\Services\Billing, Brain controllers, MembershipController, brain_memberships)
     │  uses: public API · events · registers implementations of the generic extension points
     ▼
 Direct Payments + processors  (no billing / member / brain_ vocabulary — doc 13)
```

## 14.2 Modifications needed when Direct Payments removes its membership code (phase PA7)

Each one **moves** existing behaviour out of Direct Payments into appfactory code, unchanged, so a member sees no
difference.

| # | appfactory studio modification | Moves out of Direct Payments | Behaviour change for members |
|---|---|---|---|
| **M1** | `App\Services\Billing\MembershipSubscriberIdentity` implements Direct Payments' `SubscriberIdentity`: signed-in member (Checkpoint token) → `brain_memberships.payer_reference_num` | the identity block in `DirectPaymentSubscriptionController` (:205, :615, :745) | none |
| **M2** | `App\Services\Billing\MembershipBillingStateDecorator` implements `SubscriptionResponseDecorator` — or, simpler, the members dashboard reads billing state from appfactory's own endpoint | `resolveBillingState()` (:679–705) | none |
| **M3** | appfactory `PayerDisplayResolver` (payer → member reference) for the Money ledger on the appfactory deployment | `MoneyController.php:190` | none |
| **M4** | appfactory `AdvancedConfigAuthority`: the level-99 rule | `AdvancedConfigGate` | none |
| **M5** | appfactory `RetryPolicyDefaults` reading `brain_billing_settings` | `DunningPolicyResolver` | none |
| **M6** | `ApplyTierOnPlanChange` stops writing a `warning` into the Direct Payments event; it logs and alerts on its own failure | the `membership_tier_write_failed` warning returned by the DP API | the member's browser no longer receives that internal warning; staff still see it in logs |
| **M7** | The members dashboard and Brain staff tools call `dp.subscription` / the DP API without the `member_ref` option (removed from Direct Payments) | `member_ref` | none — identity comes from M1 |
| **M8** | Register M1–M5 in an appfactory service provider, enabled by appfactory configuration | — | — |

## 14.3 Set aside — OQ11 (owner, 2026-10-16: "put this one to the side … explain it to me more later")

Two appfactory readers depend on details of Direct Payments that subscription continuity ([07](07-subscription-continuity.md))
changes. They are **not** scheduled. They are written up here so the explanation is ready when the owner wants it.

| # | appfactory code | What it relies on today | What changes underneath it |
|---|---|---|---|
| **M9** | `App\Services\Billing\MembershipTierResolver` (`findGoverningRow()`, `deriveTierPaid()`) | Reads Direct Payments tables directly: joins `laravel_direct_payment_subscriptions.provider_subscription_id` to `laravel_processor_subscriptions`; its fallback matches the tier's single `processor_tier_id` column | A subscription can have more than one provider subscription over its life (legs), and a tier has a different price id on each processor |
| **M10** | `MembershipController` signup claim | Claims a subscription by its **provider** subscription id (`membership_id`) | The stable handle becomes the Direct Payments subscription reference (`sub_…`) |

### The explanation, in plain terms

Today, when appfactory decides "what tier has this member paid for?", it does not ask Direct Payments. It opens Direct
Payments' own tables and looks for *the Stripe (or PayPal, or Square) subscription id* of the member's subscription, then
looks at which provider price that subscription is on.

That works only while a subscription is exactly one provider subscription forever. Once a website owner can move a
subscription from Stripe to PayPal, the same subscription will have **two** provider ids over its life — the old Stripe
one (ending) and the new PayPal one (starting). Code that looks for "the" provider id will, during and after a move, find
the old one (which has ended) and conclude the member has no paid subscription — for a day, or until the next sync,
depending on timing.

The fix is **on the appfactory side** and is small: ask Direct Payments for the subscription by its own reference (which
never changes) and read its status and tier from there, instead of reading provider ids out of Direct Payments' tables.
Nothing about how tiers, levels or `brain_memberships` work changes.

**What happens if this stays set aside:** nothing changes for any tenant website. For appfactory itself, nothing changes
**until the owner moves appfactory's own Direct Payment to a different processor while members have live subscriptions**.
Until then the current code keeps working exactly as it does today. Recommendation: do M9–M10 before the first time
appfactory's own processor is changed, not before.

## 14.3a Noted while planning PA0

- `DirectPaymentsController::destroy()`'s docblock cites `plans/billing/16-allocation.md §16.4` (a per-domain Direct
  Payment allowance) but the method makes no release call today. With Direct Payments now archived instead of deleted
  once used (OQ2), **whether an archived Direct Payment still counts against a domain's allowance is appfactory's
  decision** — an appfactory studio modification if it should change. Direct Payments only reports `archived_at`.

- **Receipts sent through every handler attached to a Direct Payment** ([15](15-default-emails.md) §15.5). The
  database example shows appfactory's own "Welcome, Member" handler is attached to a Direct Payment this way, so it is
  sent as that DP's payment receipt. The default-emails plan keeps that behaviour for any Direct Payment without a
  `payment_receipt` setting, so this keeps working unchanged. If appfactory later sets a `payment_receipt` on that
  Direct Payment, it must point the setting at that handler — an appfactory studio modification.

## 14.4 Other appfactory code that reads Direct Payments tables directly

Found during the audit. They work today; they are listed because reading another system's tables is the coupling M9
describes. Each would move to Direct Payments' public read surface at appfactory's own pace.

| appfactory file | Reads |
|---|---|
| `Services/Billing/DunningService.php` | `DirectPaymentSubscription` |
| `Services/Billing/ApplyTierOnPlanChange.php` | the DP event's subscription row |
| `Services/Brain/Staff/StaffSubscriptionResolver.php`, `StaffSubscriptionPresenter.php`, `ResolvedSubscription.php` | `DirectPaymentSubscription`, provider subscription |
| `Http/Controllers/Brain/Staff/BrainStaffSubscriptionController.php`, `StaffPaymentsController.php` | subscriptions, invoices; writes `canceled_at` |
| `Console/Commands/BillingEnforceMembership.php` | subscriptions via `MembershipTierResolver` |
| `Http/Controllers/Portal/Admin/MembershipController.php` | `DirectPaymentSubscription`, `DirectPaymentTier` |

`config/billing.php` keeps `enforcement_enabled` (appfactory's); the three keys Direct Payments used move to
`config/direct_payments.php` ([13](13-naming-and-separation.md) §13.3).
