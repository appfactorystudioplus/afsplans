# 05 — The two ways in: connect a domain, buy a domain

> **Moved 2026-10-23** from `domains_deploy/new_way/` to `domains_deploy/domain_name_management/` when the plan was split into domain names and website deployment. The text is unchanged; step, section and finding numbers are the same.

Both end the same way: an **active claim** (02), a **site reference** and **sites name** (03/04), and a **published
zone** (04). They differ in how the claim earns the name.

## 5.1 Connect an existing domain ("just add it")

### Sequence

```
 member            members UI                 members API               Brain (signed surface)                    DNS
 ──────            ──────────                 ───────────               ───────────────────────                  ───
 types name ──▶ normalise (client hint only) ─▶ POST /api/v1/domains/claims ─▶ POST /domains/claims
                                                                               normalise + refuse (02 §2.9)
                                                                               create claim = pending_verification
                                                                               create challenge (token)
                ◀───────────── { claim_ref, state, challenge{host,value}, nameservers[], steps[] }
 sees: "Add this TXT record at your DNS provider" and, optionally, "Point your nameservers here"
 publishes TXT ─────────────────────────────────────────────────────────────────────────────────▶ (their provider)
 presses Verify ─▶ POST /domains/{ref}/verify ─▶ POST /domains/{ref}/verify ─▶ verifier (02 §2.4) ──queries──▶ authoritative DNS
                ◀──── { state: active | pending_verification (why) | contested }
 active: activation txn (02 §2.6) ─▶ site_ref + sites record ─▶ publish job (04) ─▶ zone live on lab/prod BIND
 "Move your nameservers to ns1…ns4" shown once the zone is published; NS check flips dns_mode to managed
```

### Behaviour

- **Creating a claim is cheap and inert.** It never publishes. The response is identical whether or not anyone else holds
  the name (02 §2.9 rule 6).
- **Two modes, chosen by the member, changeable later:**
  - **Managed (recommended):** they point nameservers at the platform. We serve the zone; the DNS tab works; the
    `www`/apex records follow the site automatically.
  - **External:** they keep their DNS. We never publish their zone, but the site and sites name still work; the UI shows
    the exact records to create at their provider (`@ A <server ip>` and `www CNAME <site_ref>.sites.…`, with the warning
    that the apex A must be updated by them if the site ever moves server).
- **Existing records.** A member who moves NS to us loses whatever their old provider served unless the records are
  copied. P0 shows a warning at the NS step; P2 offers record import (04 §4.6).
- **Verification status is always explained:** "TXT not found at ns1.theirprovider.com", "found at 2 of 3 servers",
  "NS still point to another provider", "your claim is waiting for another account to release this name".
- **Abandoned claims expire** after 14 days (`expired_unverified`) and their challenge dies with them.
- **Deleting an unproven claim** is immediate and silent.

## 5.2 Buy a domain — a saga, not three browser calls

Today the browser charges the card, then calls the registrar endpoint, then calls the record endpoint (F34). Any step can
fail and the earlier ones are not undone. The replacement is a **server-owned purchase** with an idempotency key, a
stored state, compensation, and a reconciler.

### States

```
 search ─▶ quote ─▶ intent(created) ─▶ awaiting_payment ─▶ paid ─▶ registering ─▶ registered ─▶ nameservers_set ─▶ active
              │          │                    │               │         │                │
              │          │                    ▼               ▼         ▼                ▼
              │          │                 abandoned       refund_*  registrar_failed  ns_retry (domain is ours; keep trying)
              │          └─ quote expired / price changed ─▶ requote
              └─ name unavailable / claimed by a verified member ─▶ refused
```

### Steps

1. **Search (Brain, via the registrar driver, §5.3).** Results are filtered: a name with an `active` claim is shown as
   unavailable regardless of what the registrar says (it cannot be purchasable if registered, but a stale registrar cache
   must not tempt a member). Each price comes with a **quote** (`quote_id`, price, currency, expiry minutes).
2. **Create the purchase intent** — `POST /domains/purchases {name, quote_id, idempotency_key}`:
   - normalise and refuse (02 §2.9); reject if the member already has a live claim on the name that is not
     `pending_verification`;
   - **re-quote at the registrar** and compare to the quote; a changed price returns `requote` with the new price;
   - create a claim `purchasing` (this is the **name lock**: while any claim for the name is `purchasing`, activation of
     any connected claim for that name is deferred, 02 §2.11);
   - create the **invoice on the server** for the **server-side price**. The browser no longer decides the amount (F36).
   - return the invoice reference for payment. The same `idempotency_key` returns the same intent.
3. **Payment** stays in the browser because card authentication (3-D Secure) needs it (`directPayments.confirmPayment` /
   `charge`). The change is who believes the result: the Brain **confirms the invoice is paid with the billing system**
   (a webhook or a lookup — the call belongs to `plans/billing`, **D10**) and only then moves to `paid`. A browser that
   says "paid" is not evidence.
4. **Register** at the registrar, idempotently:
   - use the registrar's own order reference where it has one; on a timeout or ambiguous error, **look the name up in our
     registrar account before retrying**, so a second request can never double-buy;
   - registrant contact data comes from the member's profile (the existing `provider_response.contacts` shows the shape the
     registrar returns); missing data is collected *before* payment (**D6** decides whether the member or the platform is
     registrant of record).
5. **Set nameservers** to the platform NS (already coded in `_runNameCom` as `setNameservers`; its failure is today only
   logged). A failure here is **retried by the saga**, not swallowed: the name is ours at this point.
6. **Activate**: `proof_level = registrar`, supersede any unproven claims on the name (notify their owners), assign
   `site_ref`, insert the sites record, publish the zone (04). The zone exists *before* the NS change propagates, so
   there is no outage window.
7. **Finish**: record `quote`, amount charged, registrar price, registrar order id, expiry, auto-renew; event
   `purchase_done`; receipt mail through the Brain mail surface.

### Failure table

| Failure | Handling |
|---|---|
| Payment fails / abandoned | No registrar call. Intent `abandoned` after 30 minutes; claim `failed`; name lock released |
| Paid, registrar says unavailable/declined | **Automatic refund**, claim `failed`, message to the member, event with the registrar's error |
| Paid, registrar timeout | Reconciler (below) decides: registered to our account ⇒ continue at step 5; not registered ⇒ refund |
| Registered, nameserver call fails | Retry with backoff (`ns_retry`); the member sees "registered, finishing set-up". Never refunded automatically |
| Registered, publish fails | Claim is `active`; `publish_state = failed` is shown; publisher retries (04) |
| Browser closed at any point | Nothing is lost: the saga is server-side and resumes from stored state |
| Double click / retry | Same `idempotency_key` ⇒ same intent |

### Reconciler (scheduled, every 5 minutes)

Finds intents stuck in `paid`, `registering`, `registered` or `ns_retry` past their timeout, asks the registrar for the
truth, and moves them forward or refunds. This is also the only code path allowed to refund, and every refund is an event
and an audit row.

## 5.3 Registrar adapter

A `RegistrarDriver` interface — `search`, `quote`, `register`, `getDomain`, `setNameservers`, `setAutoRenew`, `renew`,
`lock` — with:

- **`NameComDriver`**: the existing request bodies from `_searchNameCom` / `_runNameCom`, moved. The base URL and
  credentials come from `config('domains.registrar_env')` (`production` → `https://api.name.com`, `sandbox` →
  `https://api.dev.name.com`), **not** from comparing the request host to `members15.appfactory.studio` (F35).
  (Both URLs must be confirmed against Name.com's current documentation before the first real purchase; the code today
  only ever calls the dev host.)
- **`FakeDriver`** (lab2): deterministic and free. Names ending `-taken` are unavailable, `-slow` times out once, `-fail`
  declines; everything else registers instantly. It lets the saga, the refund path and the reconciler be tested without a
  card or a registrar account.
- A second registrar is a new class and a config value (out of scope here).

Credentials are read by the **Brain** from its config and never sent to members (F33). Members calls the Brain; the Brain
calls the registrar.

## 5.4 Quota, limits, abuse

- **Domain names are exempt from plan quotas** (feature 1, F1.0). Adding, connecting and buying are never counted or
  banded. A downgrade never touches a zone.
- **Websites** are what count. This plan only provides the hook points feature 1 needs: `canUse` before a deploy is
  accepted, `consume` on acceptance, `release` on failed deploy and on real delete (07 §7.1, §7.10). Because the browser
  check is advisory (feature 1, known limits), the Brain's deploy endpoint also does a **server-side count** of the
  member's live tenants against the plan allowance and refuses beyond it — **D11** asks whether to turn that on in P0.
- **Abuse controls (not entitlement):** at most 20 live unproven claims per member, claim-creation and verify rate limits
  per member and per IP, a daily purchase cap per member until the account has history, and a staff kill switch
  (`domains.claims_enabled`, `domains.purchases_enabled`).

## 5.5 Releasing and deleting a domain

- **Connected:** the member can release at any time. A site on it is removed first (07 §7.10, confirmed in the UI), then
  withdrawal runs (04 §4.8) and the next contested claim, if any, is promoted.
- **Purchased:** the registration is an asset the platform paid for. Releasing **stops auto-renew** and removes the
  claim from the account **at expiry**; until then the zone and any site stay. Immediate deletion of a purchased name is
  a staff action (refund policy is a billing decision, **D12**).
- **Account closure** (`plans/account_close`) calls the same release path for every claim.

## 5.6 What the UI changes (details in step 08)

The "Managed / Unmanaged" badge (today `own`) is replaced by the claim state and `dns_mode`. The add screen becomes a
two-step: *name → what to do next* (TXT + optional NS). The purchase screen shows the server quote and the saga's live
state. Both sit on the same domain list with the state, the reason, and the next action for each claim.

## 5.7 Phases

| Phase | Work |
|---|---|
| **P0** | Claim create/verify/delete API and the add screen; activation hand-off to 04; `RegistrarDriver` + `FakeDriver`; purchase intent → server invoice → confirmed payment → register → NS → activate (happy path) on the fake driver |
| **P1** | `NameComDriver` with config-selected environment; refund/compensation paths; the reconciler; renewals and expiry handling; supersession notices; abuse limits and kill switches; release/delete flows; server-side website count (D11) |
| **P2** | Record import on connect; transfer-in; second registrar; domain-expiry dashboard for staff |
