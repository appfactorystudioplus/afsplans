# Step 09 — Brain: the Connect playground examples

**On completion:** write `complete/09_direct_payments_connect_example_complete.md`.
**Depends on:** [08](08_stripe_connect_brain_api.md).
**Blocks:** 10 (members mounts the same components) and 13 (market's checkout is written against these).

> ## ▶ This runs FIRST, interleaved with [08](08_stripe_connect_brain_api.md)
>
> Project owner's sequencing: the external TypeScript API and the Laravel server side are built,
> the owner compiles `sdk.js`, and **these pages are the first thing that consumes them** — before
> any market or members work.
>
> The two folders land at different times, because they need different amounts of step 08:
>
> | | Needs | Lands after |
> |---|---|---|
> | **`connect_setup/`** | Profile, account creation, onboarding, components, status | §08.5 + §08.6 + §08.7.5, then a build |
> | **`marketplace_split/`** | The split charge path, and connected accounts that already exist | §08.7, then a rebuild — and it uses the accounts `connect_setup` created |
>
> So `connect_setup/` is written first and `marketplace_split/` second. They are not a pair to be
> built together.
>
> These pages are the executable specification of step 08's API: if the Connect surface changes,
> they change first and the members dashboard and market checkout follow. That is worth the most at
> the front of the plan, where the API is still moving.

---

## 09.1 Location and shape

> **Tagging (step 01 §01.4).** Every file this step creates or modifies carries
> `MARKET_MODIFIER_ADDITION` — created files in the top doc block, modified files at each changed
> line or region. `grep -rn MARKET_MODIFIER_ADDITION` is the change index for this whole effort.


Two new folders, matching the existing convention exactly:

```
apps/www/public/playground/direct-payments/
├── single_charge/          existing — the style model
├── subscription/           existing
├── dashbaord/              existing (sic — leave the typo, renaming it is unrelated churn)
├── connect_setup/          NEW — goal 1: configure Connect, onboard an account
└── marketplace_split/      NEW — goal 2: use those accounts through Direct Payments
```

Each folder is an `.html` plus a `-example.js`, copying `single_charge/direct-payments-charge.html`'s
conventions because consistency across the playground is the playground's point:

- `<script src="https://api-sandbox.appfactory.studio/sdk.js"></script>` in `<head>`.
- The same CSS custom-property block (`--bg-primary: #0b0f19`, `--accent: #4f75ff`,
  `--card-radius: 16px`, …), the same Inter/Outfit pair, the same `.item-card` / `.status-ok` /
  `.status-error` classes.
- An IIFE with `'use strict'`, `window.Portal.httpUrl` set at the top, DOM refs collected into named
  vars, then numbered `// ── Step N – …` section comments.
- Plain ES5-flavoured `var` JS. Static files, no build step; matching the neighbours matters more
  than modern syntax.

Both carry `MARKET_MODIFIER_ADDITION` in their opening comment.

---

## 09.2 `connect_setup/` — how a consumer reads and applies the configuration

**The configuration is authored in the Processor dashboard, not here.** Step 08 §08.3 puts the
Connect profile in `laravel_processors.extra_config.connect`, edited through the authenticated admin
UI. This page is a **consumer**: it reads that profile through the external API and behaves according
to it. That is the whole demonstration — the same thing members' seller dashboard (step 10) and
market's checkout (step 13) will do.

This page is deliberately **not** a profile editor: that would duplicate the admin UI, and it would
imply the public SDK can write processor configuration — which §08.5.5 establishes it must not.

### What the page shows

1. **The profile, read-only.** `dp.connect.getProfile()` — no processor ref; the Direct Payment already resolved it → the narrowed
   `PublicConnectProfile`. Rendered as a panel with a line stating where it comes from:
   *"Configured in the Processor dashboard. This page reads it; it cannot change it."* — with a link
   to the dashboard.

2. **The derived values, computed client-side from the profile:**

   ```
     integration_mode         self_hosted        ← configured in the dashboard
     → dashboard                    none         ← derived
     → requirements_collector       application  ← derived by Stripe, not settable
     → KYC collected by             YOUR PLATFORM
     → negative balances            YOUR PLATFORM
     → seller sees                  nothing from Stripe
   ```

   With a note that changing any of it means editing the processor and creating a **new** account —
   `dashboard` is immutable once an account exists (§08.3).

3. **Create an account using that profile.** No mode picker on this page. The button reads
   *"Create account (self_hosted / embedded)"*, taking both values from the profile, and the request
   body shown alongside contains **no** mode fields — the server resolves them from the processor
   record. That absence is the point worth seeing.

4. **`startOnboarding` through one code path**, with the resolved `mode` shown as a badge:

   ```js
   var dp = window.Portal.directPayments.create(DP_REF);
   await dp.initializeDirectPayment();          // resolves the DP → processor → connect profile

   var handle = await dp.connect.startOnboarding(accountRef);
   if (handle.mode === 'hosted') window.location.href = handle.url;
   else await dp.connect.mountOnboarding(handle, '#connect-onboarding');
   ```

   Three lines, unchanged whichever mode the dashboard selected. **To demonstrate it, change
   `onboarding_mode` in the Processor dashboard, reload this page, and watch the badge and the
   behaviour change with no edit here.** Put that instruction on the page — it is the clearest
   possible proof of the encapsulation, and it costs nothing.

5. **Component gallery** — `dp.connect.mountComponent(...)` for `account_management`,
   `notification_banner`, `payouts`, `balances`, `documents`, so step 10 is a layout exercise rather
   than an integration one.

6. **Status panel** — `dp.connect.getStatus()`: `charges_enabled`, `payouts_enabled`, `details_submitted`,
   `applied_configurations`, `requirements.entries[]`, `disabled_reason`, `future_requirements`.

7. **An `onExit`-is-not-completion demonstration.** Exit the component early; show the status still
   reporting incomplete. The mistake this page exists to prevent.

8. **Two absences demonstrated on purpose.**
   - A button *"Try to change the fee from the SDK"* calling the non-existent
     `dp.connect.saveProfile(...)`, rendering the `TypeError` beside a comment explaining that
     profile writes live in the authenticated admin API only (§08.5.5).
   - An **unsupported-provider panel**: `dp.connect.isSupported()` / `.support()` rendered for the
     current Direct Payment, with a note that pointing this page at a PayPal- or Square-backed DP
     produces `{ supported: false, reason: 'connected_accounts_unsupported' }` rather than an
     exception (§08.5.3). Worth showing, because it is what lets a caller avoid a provider `switch`.

9. **A commented `// what the server validates` block** — the profile rules and where each is
   enforced, so a reader learns the client's copy is a convenience and never the authority.

### What it does not do

- Does not edit the profile.
- Does not pick an integration or onboarding mode.
- Does not name a processor — it names a **Direct Payment**, and everything else resolves from that.
- Does not name a provider, or branch on one.
- Does not hold a secret key, or call a provider directly with anything but a publishable key.

Each of those is a boundary from step 01 D6, made visible by its absence.

## 09.3 `marketplace_split/` — the payment example (goal 2)

1. **Multi-seller cart**, items pre-tagged with `seller_ref`, at least two distinct sellers — so the
   split path is the default, not an edge case.
2. **Live split preview**:

   ```
     Seller A · "Sci-Fi Prop Pack"        $40.00   fee $4.00   → $36.00
     Seller B · "Ambient Loop Bundle"     $20.00   fee $2.00   → $18.00
     ─────────────────────────────────────────────────────────────────
     Tax                                   $4.95
     Buyer pays                           $64.95
     Platform receives                     $6.00
   ```
3. **Mode badge** — `destination` or `separate_transfers` — flipping live as items are checked and
   unchecked, so a reader can see *why* the mode changed by toggling one checkbox.
4. **The full invoice lifecycle**, mirroring `single_charge`: `create(ref)` →
   `initializeDirectPayment()` → `createInvoice()` → `addItem({item_ref, quantity, seller_ref})` →
   `lock` → `setupSinglePayPayment()` → mount → `confirmPayment()`.
5. **Cart mutation after lock destroys the payment session and unlocks** — the
   `destroyPaymentSession()` / `modifyCartBtn` behaviour `single_charge` already models. Reproduce
   it; a stale payment session against a changed cart is the highest-value bug this page prevents.
6. **Per-seller readiness**, from `dp.connect.getStatus()`: refuse to lock the invoice if any
   seller cannot be paid, naming which one. Include a seller whose `recipient` configuration is
   missing — destination and separate transfers both need `stripe_balance.stripe_transfers`, and
   that failure otherwise surfaces far from its cause.
7. **Result panel**: PaymentIntent id, `transfer_group`, per-transfer status, application fee. The
   panel a developer copies into their own integration.
8. **Every failure rendered, not thrown**: insufficient balance, restricted destination, declined
   card, and a tampered split rejected by §08.7's invariants — each showing the server's actual
   message. An example that only shows the happy path teaches nothing about the error envelope.
9. **A commented "what the server checks" block** listing §08.7's five invariants inline.

---

## 09.4 Deliberate constraints

- **No framework, no bundler, no npm.** Static files served directly.
- **Test mode only**, with a banner saying so.
- **No real seller data.** Fixture `seller_ref` values, clearly fictional titles.
- `window.Portal.httpUrl` stays a literal in these two files — standalone playground pages with no
  server-side render and no build step, exactly as `single_charge` does it. This is the **one**
  documented exception to step 01's D5, noted in each file's header so it is not mistaken for a
  pattern to copy. Every application surface reads the value from env.
- The preview API version string is shown in the page as a labelled constant, so a reader
  understands v2 needs it and where it would go in their own code.

---

## 09.5 Documentation

- New `resources/js/external/connect/CONNECT_DOCUMENTATION.md`: the profile shape, the
  `integration_mode` → `dashboard` → `requirements_collector` derivation table, the three onboarding
  modes with their trade-offs, the `OnboardingHandle` union, the component list, and the v1-endpoint
  /v2-account-id note for `AccountSession`.
- A new section in `resources/js/external/processors/PROCESSOR_DOCUMENTATION.md`: the two split
  modes and when each is selected, the `split` payload, the five server-side invariants, the
  transfer lifecycle, and links to both examples.
- If `resources/js/external/direct_payments/` has no README, add one. An 1881-line `api.ts` with an
  undocumented split feature will be re-derived from source by the next reader.

---

## 09.6 Expected rework

Revision is expected. Likely triggers, named so they are recognised rather than rediscovered:

- The split plan may belong on `lockInvoice()` rather than `setupSinglePayPayment()`, so the server
  validates it before any card details are collected.
- `seller_ref` may be better derived server-side from the item configuration — the client's copy is
  validated anyway, so passing it may be pure redundancy.
- Multi-seller may be deferred (§08.10), collapsing `marketplace_split` to a single-seller
  destination charge with a constant mode badge.
- Stripe's preview version string will change. When it does, these pages are where it is noticed
  first.

On revision, **append** to the completion report with the date and reason. The revision history is
the useful part of this file's record.

---

## 09.7 Verification

- [ ] Both pages load with no console errors and are visually indistinguishable in style from `single_charge`.
- [ ] `connect_setup`: the profile panel renders from `getProfile()` and states that it is read-only.
- [ ] `connect_setup`: changing `integration_mode` **in the Processor dashboard** and reloading the page updates `dashboard` and `requirements_collector` in the derived panel, for all three values — with no edit to the playground.
- [ ] `connect_setup`: changing `onboarding_mode` in the dashboard flips the badge and the behaviour, again with no edit to the playground. This is the encapsulation test.
- [ ] `connect_setup`: the account-creation request body contains **no** mode fields — the server resolves them from the processor record.
- [ ] `connect_setup`: `dp.connect.saveProfile` does not exist, and the page demonstrates that deliberately.
- [ ] `connect_setup`: **no method call anywhere on the page passes a processor reference.** Grep the file — the only identifier it names is the Direct Payment ref.
- [ ] `connect_setup`: pointed at a Direct Payment whose processor is PayPal or Square, the page renders the unsupported panel and throws nothing.
- [ ] The admin dashboard refuses `self_hosted` + `losses_collector: stripe` with the derivation explained — verified there, since that is where the rule is enforced.
- [ ] `connect_setup`: an account created as `self_hosted` retrieves with `dashboard: "none"`, `requirements_collector: "application"`, and both `merchant` and `recipient` in `applied_configurations`.
- [ ] `connect_setup`: embedded onboarding completes with **no `stripe.com` navigation** in the network log.
- [ ] `connect_setup`: switching `onboarding_mode` to `hosted` changes only the badge and the branch taken — the caller code is byte-identical.
- [ ] `connect_setup`: exiting the component early leaves the status panel reporting incomplete.
- [ ] `connect_setup`: a request made without the preview version string fails visibly, with the cause named.
- [ ] `marketplace_split`: toggling to a single seller flips the badge to `destination`; adding the second flips it to `separate_transfers`.
- [ ] `marketplace_split`: a full test-mode purchase yields one charge, two transfers, one `transfer_group` in Stripe's dashboard.
- [ ] `marketplace_split`: a seller missing the `recipient` configuration blocks the lock and says so specifically.
- [ ] `marketplace_split`: a declined card renders the decline message rather than throwing.
- [ ] The split preview matches §08.7's arithmetic to the cent on a price ending in `.99`.
- [ ] `CONNECT_DOCUMENTATION.md` written; `PROCESSOR_DOCUMENTATION.md` section added; both link to the examples.
- [ ] Every file created or modified by this step carries `MARKET_MODIFIER_ADDITION` (§01.4). Verify by `grep -rn`, and reconcile the result against this step's Files section — a file in the list without a tag, or a tagged file not in the list, are both defects.
- [ ] `complete/09_direct_payments_connect_example_complete.md` written.
