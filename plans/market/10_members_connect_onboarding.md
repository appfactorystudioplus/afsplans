# Step 10 — Members: the Connect dashboard, self-hosted with embedded components

**On completion:** write `complete/10_members_connect_onboarding_complete.md`.
**Depends on:** [08](08_stripe_connect_brain_api.md), [04](04_internal_cross_api.md).
**Blocks:** the paid-listing gate in [06](06_members_asset_publishing.md) and [07](07_members_freelance_publishing.md); [16](16_payouts_fees_and_ledger.md).

This is goal 2 of the brief made visible: the externals Connect API (step 08), consumed in the
members dashboard, alongside the processors and Direct Payments surfaces it already hosts.

---

## 10.1 What this replaces

Both members controllers implement "payment methods" themselves: a type selector
(`bank` / `paypal` / `stripe`), account and routing numbers, stored in a PHP session and rendered as
`****1234`.

Delete all of it, in order of severity:

1. Raw bank credentials in a PHP session file is a compliance and liability problem with no
   compensating benefit.
2. It does not pay anyone — nothing reads those records.
3. Connect already solves identity verification, KYC, tax forms, payout scheduling and cross-border
   currency. Re-implementing any of it badly is worse than not having it.

The replacement is a v2 connected account, onboarded and managed **entirely inside the members
dashboard**.

---

## 10.2 The white-label commitment

Per step 08's decision: `dashboard: "none"` with `onboarding_mode: "embedded"`.

**The seller never sees a `stripe.com` URL.** No Express dashboard, no hosted redirect, no Stripe
branding. Onboarding, requirements remediation, payouts, balances and documents all render inside
`members15.appfactory.studio` as Connect embedded components.

What that costs, stated plainly here because it is where the consequence lands: `requirements_collector`
resolves to `application`, so **the platform carries negative-balance liability and owns risk
management** for these accounts. That is not a side effect to discover later — it is the price of the
white label, and §10.6 lists the operational obligations it creates.

The one exception: when a requirement can only be satisfied by a Stripe-hosted flow — `proof_of_liveness`
is the common case — step 08's `startOnboarding` returns `mode: 'hosted'` and the UI redirects. It is
rare, it is explained in the UI when it happens, and it is the documented fallback rather than a
silent inconsistency.

---

## 10.3 One integration, three states

Everything routes through step 08's mode-agnostic call, so this UI does not know or care which
onboarding mode the platform profile selects:

```ts
/** MARKET_MODIFIER_ADDITION */
// Connect is reached through the Direct Payment, never through a processor
// reference — step 08 §08.5.1. The DP resolves its own processor.
const dp = window.Portal.directPayments.create(cfg.directPaymentRef);
await dp.initializeDirectPayment();

const handle = await dp.connect.startOnboarding(accountRef, {
  return_url: cfg.membersUrl + cfg.connectReturnPath,
});
if (handle.mode === 'hosted') window.location.href = handle.url;
else await dp.connect.mountOnboarding(handle, '#connect-onboarding');
```

**1 — Not started.** *"To sell paid assets or services, set up payouts."* One button. Creates the v2
account with the merchant **and** recipient configurations (recipient is required for the
destination and separate-transfer flows the market uses) and mounts onboarding immediately.

**2 — In progress.** The `account_onboarding` component mounted inline, with the
`notification_banner` component above it — the banner is what surfaces newly-due requirements
without you parsing and translating `requirements.entries[]` yourself. Plus a plain-language summary
derived from step 08's `getStatus()`, and `requirements.disabled_reason` shown **verbatim**. Do not
paraphrase a compliance message.

**3 — Active.** `charges_enabled` and `payouts_enabled` both true. Mount `account_management`,
`balances`, `payouts` and `documents` components. No "Manage on Stripe" link — there is nowhere to
send them, by design. Surface `future_requirements` when present: an account that is fine today and
restricted in 30 days should say so now.

Set two component features deliberately:

- `disable_stripe_user_authentication: true` — with `dashboard: none` there is no Stripe login to
  authenticate against, and Stripe's docs note that production custom accounts need no user
  authentication.
- `external_account_collection: true` — leave it on. You have no third-party bank-collection
  provider, and turning it off means building bank-detail collection yourself, which is the thing
  §10.1 just deleted.

State is read via `dp.connect.getStatus()`, which fronts step 08's `GET .../connect/{id}/status` (which retrieves with the required
`include` array), proxied through members, and mirrored into market's `seller_profiles` so the
publish gate evaluates without a live Stripe call on every publish.

---

## 10.4 The flow

```
members UI                brain (externals)              Stripe            market
──────────                ─────────────────              ──────            ──────
[Set up payouts]
   │
   ├─ dp.connect.createAccount ─────▶ v2/core/accounts
   │                                     dashboard: "none"
   │                                     configuration: merchant + recipient
   │                                     defaults.responsibilities:
   │                                       fees_collector:   "application"
   │                                       losses_collector: "application"
   │                                     include: [...]        ← or everything reads null
   │◀──────────────────────────────────── account id
   │
   ├─ dp.connect.startOnboarding ───▶ v1 AccountSession(v2 account id)
   │◀──────────────────────────────────── { mode: 'embedded', client_secret }
   │
   ├─ mountOnboarding(handle, '#connect-onboarding')   ← in-page, your branding
   │
   ├─ (component completes; ConnectJS onExit)
   │
   ├─ dp.connect.getStatus ─────────▶ v2/core/accounts/{id}?include=...
   │
   └────── POST market /sellers/{ref}/connect-status ──────▶ seller_profiles updated
                                                             charges_enabled, payouts_enabled
```

Two properties are load-bearing:

- **`onExit` is not proof of completion.** The component fires it whether or not the account is
  usable. Re-fetch status; trusting the callback is the embedded-mode equivalent of the classic
  `return_url` mistake.
- **Account events keep the mirror fresh afterwards.** An account restricted weeks later must be
  reflected without the seller revisiting the page. Subscribe to both v1 `account.updated` and the
  v2 `v2.core.account` events during migration (step 08 §08.7) and record which fired.

---

## 10.5 The publish gate

**A listing with `price > 0` cannot be published unless the seller's `charges_enabled` is true.**
Free listings are unaffected.

Enforced in three places, deliberately:

| Where | Form | Why not just one |
|---|---|---|
| Market, `POST /publish` | `422`, code `MARKET_E_PAYOUTS_REQUIRED` | The authoritative check. Everything else is UX. |
| Members wizard, stage 5/6 | Inline banner + a button that mounts onboarding **in place** | So the seller learns before finishing the work, not after |
| Members listing table | "Payouts required" badge on affected drafts | So it is visible without opening anything |

Because onboarding is embedded, the wizard's remedy is a mount, not a navigation — the seller fixes
it without losing wizard state. That is a direct benefit of the embedded choice and worth building
rather than falling back to a link.

The error names the fix and links to it. A bare "validation failed" here means a seller who uploaded
a 400 MB package cannot work out why they cannot sell it.

---

## 10.6 Platform obligations this configuration creates

`requirements_collector: application` and `losses_collector: application` are not free. Record these
as operational commitments in the completion report, with an owner:

- **Negative balances are the platform's.** A refunded or charged-back sale against an empty seller
  balance lands on the platform account (step 16's ledger must show it honestly).
- **Risk monitoring is the platform's.** Stripe will not intervene on these accounts.
- **Requirement changes must be tracked.** Embedded components handle *collection*, so the six-month
  API-onboarding re-collection obligation does not apply — but the platform still owns communicating
  and chasing.
- **Support is the platform's.** Stripe will not contact these sellers, and they cannot contact
  Stripe.

If any of these is unacceptable, the profile switch to `integration_mode: express` is a
configuration change in step 08 with no consumer code change here — which is precisely why step 08
encapsulated it. Making that switch reversible was worth the abstraction.

---

## 10.7 Earnings and payouts

Two data sources, deliberately distinguished:

- **Market's ledger** (step 16) is authoritative for balance, per-sale fee and net, and payout
  history as the marketplace understands it.
- **Stripe's `balances` / `payouts` embedded components** show the connected account's own view.

Render market's figures as the primary numbers, with the Stripe components below as the
authoritative payout detail. Where they disagree, show market's figure with a "syncing" hint and let
step 16's reconciliation resolve it. Silently picking one is how a seller loses trust in both.

Plus: ledger table (date, item, gross, platform fee, net, status), CSV export, and **Request payout**
where the schedule is manual.

---

## 10.8 Files

> **Tagging (step 01 §01.4) — every file below.** Created files carry
> `MARKET_MODIFIER_ADDITION` in their top doc block; modified files carry it at each changed line or
> region. `grep -rn MARKET_MODIFIER_ADDITION` must return this list in full, or the change index is
> already wrong.


**Created:** `members/resources/js/market/ConnectOnboarding.ts`,
`members/resources/js/market/ConnectComponents.ts` (thin wrapper over
`dp.connect.mountComponent`), `members/resources/js/market/EarningsView.ts`,
`members/resources/js/templates/views/payouts.html`,
`members/app/Http/Controllers/Market/ConnectController.php` (proxy to brain's externals endpoints),
`members/app/Services/Market/BrainConnectClient.php`.

**Modified:** `AssetStoreController.ts` and `FreelanceController.ts` (payment-method UI removed,
payouts view added), `assetstore.html`, `freelance.html`, `views.ts`, `i18n/en.json`,
`members/app/Http/Controllers/{AssetStore,Freelance}Controller.php` (payment-method methods
deleted), `members/routes/web.php` (payment-method routes removed, connect routes added).

**Env (step 02):** `MEMBERS_CONNECT_RETURN_PATH`, `MEMBERS_CONNECT_REFRESH_PATH`,
`VITE_BRAIN_URL` (for `window.Portal.httpUrl`), and the SDK script tag on the members pages that
mount components.

---

## 10.9 Verification

- [ ] `grep -rn "bankAccount\|bankRouting\|addPaymentMethod" members/resources/js members/app` → zero.
- [ ] Full onboarding for `m1xTHpRmdtH2Y` completes **without a single `stripe.com` navigation**. Verify from the browser network log, not by impression.
- [ ] The created account retrieves with `dashboard: "none"` and `requirements_collector: "application"`.
- [ ] The account has both `merchant` and `recipient` in `applied_configurations` — without recipient, destination and separate-transfer charges fail later, and the failure surfaces far from here.
- [ ] Firing `onExit` on an incomplete account leaves the UI in state 2, not state 3.
- [ ] `seller_profiles.charges_enabled` is true in market after onboarding, with no manual refresh.
- [ ] Publishing a $10 asset before onboarding: refused, with the payout-specific message, and the button mounts onboarding **inside the wizard** without losing state.
- [ ] Publishing a $0 asset before onboarding: allowed.
- [ ] Restricting the test account flips `charges_enabled` in market via the account event, and the members UI reflects it on next load.
- [ ] Switching the platform profile to `express` changes no file in `members/` — re-run onboarding for a fresh seller and confirm the redirect path works through the same call.
- [ ] Simulate a `proof_of_liveness` requirement: `startOnboarding` returns `mode: 'hosted'`, the UI explains the redirect, and the seller returns to state 2 or 3 correctly.
- [ ] The §10.6 obligations are recorded in the completion report with a named owner.
- [ ] Every file created or modified by this step carries `MARKET_MODIFIER_ADDITION` (§01.4). Verify by `grep -rn`, and reconcile the result against this step's Files section — a file in the list without a tag, or a tagged file not in the list, are both defects.
- [ ] `complete/10_members_connect_onboarding_complete.md` written.
