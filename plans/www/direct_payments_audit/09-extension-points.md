# 09 — Extension Points

Direct Payments is used by every website on the platform, and by host applications built on top of it. It must work for
all of them without knowing any of them. This document defines the generic extension points through which a host
application adds its own behaviour, and the defaults Direct Payments ships. The naming rule and the inventory of code
that currently breaks it are in [13](13-naming-and-separation.md); host-application changes are never planned here
(appfactory's are in [14](14-appfactory-studio-modifications.md)). Fixes **A50–A53** on the Direct Payments side.

## 9.1 The principle

Direct Payments **announces** (events) and **asks** (interfaces). It never imports host-application code, never reads
host-application tables, and never returns host-application fields in its own responses. A host application registers
implementations from its own service provider; Direct Payments resolves them through the container.

## 9.2 The interfaces

All in `App\Externals\DirectPayments\Contracts`.

```php
/** "Which payer is making this request?" — behind dp.subscription and every …/me/* endpoint. Implementations form a chain;
 *  the first that recognises the request wins. Returns null when this source does not recognise it. */
interface SubscriberIdentity
{
    public function resolve(Request $request): ?ResolvedSubscriber;   // { payer_reference_num, source, display_name? }
}

/** Extra fields a host application wants on a subscription response. Default: none. */
interface SubscriptionResponseDecorator
{
    public function decorate(DirectPaymentSubscription $contract, array $response): array;
}

/** How the Money ledger labels a payer. Default: the payer's own e-mail / name. */
interface PayerDisplayResolver
{
    /** @param string[] $payerRefs  @return array<string, string> payer_ref => label */
    public function labels(array $payerRefs): array;
}

/** Who may write a Direct Payment's advanced (grace / retry) settings. Default: any dashboard administrator. */
interface AdvancedConfigAuthority
{
    public function allows(Request $request): bool;
}

/** Default grace period and reminder ladder for a Direct Payment that has none of its own. Default: built-in values. */
interface RetryPolicyDefaults
{
    public function defaults(): RetryPolicy;
}
```

| Interface | Direct Payments ships |
|---|---|
| `SubscriberIdentity` | `SiteUsersSubscriberIdentity` — signed-in Site User → `SiteUserPayer`; `TokenSubscriberIdentity` — a payer token or a subscription-management token issued by Direct Payments (in receipts and renewal e-mails) |
| `SubscriptionResponseDecorator` | none |
| `PayerDisplayResolver` | payer e-mail / name |
| `AdvancedConfigAuthority` | dashboard administrator |
| `RetryPolicyDefaults` | built-in defaults |

Which implementations are active is configuration (`config/direct_payments.php` → `identity_sources`, plus the bindings
a host application's service provider makes), never inferred by Direct Payments from what tables exist.

## 9.3 Contact e-mail

Notification recipients come from the **payer** (`laravel_direct_payment_payers.email`), then the provider customer's
e-mail, then nothing (the send is recorded as `skipped: no_recipient`). No other source is consulted.

## 9.4 Events

Direct Payments events (`ChargeSucceeded`, `SubscriptionPlanChanged`, `SubscriptionLegScheduled`, `SubscriptionMigrated`,
`SubscriptionMigrationFailed`, `SubscriptionEndedUnmigrated`, `SubscriptionEnded`, `DirectPaymentProcessorSwapped`) carry Direct Payments data only.
Listeners react; they do not write back into the event to change a Direct Payments response — `SubscriptionPlanChanged`'s
`warning` property is removed ([13](13-naming-and-separation.md) §13.2).

## 9.5 Acceptance

- With no host application registered, a signed-in Site User can show, cancel, update and change the card of their own
  subscription through `dp.subscription`.
- Removing every host-application binding leaves Direct Payments fully working with its defaults.
- Gate G5 ([11](11-rollout-and-testing.md) §11.5) and the §13.6 vocabulary gate are clean.
