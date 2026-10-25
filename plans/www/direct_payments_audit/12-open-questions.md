# 12 — Owner Decisions

## 12.1 Decided (2026-10-16)

| # | Question | Decision | Where it is applied |
|---|---|---|---|
| **D-SEP** | How separate are Direct Payments and appfactory studio? | **Completely.** *Billing*, *member*, *membership* and `brain_memberships` belong to appfactory studio. Direct Payments and processors contain no such vocabulary, read no appfactory tables, import no appfactory code. Anything needed on appfactory's side is an **appfactory studio modification, not a Direct Payments API change** | [13](13-naming-and-separation.md), [09](09-extension-points.md), [14](14-appfactory-studio-modifications.md) |
| **OQ3** | How are live subscriptions moved on a processor change? | **Copy the cards whenever possible** (S1, no action, heads-up e-mail). **Everyone else re-enters payment details through the easiest path available** (S2: signed link, no sign-in, wallets first, pre-filled details, optional in-site banner), while their old subscription keeps renewing. Decided per subscriber, automatically | [07](07-subscription-continuity.md) §7.4 |
| **OQ5** | Sandbox ↔ live | **Live → sandbox refused. Sandbox → live only through a "Go live" action** that keeps the Direct Payment reference. **A short explanation is shown when creating a processor and a Direct Payment**, and on the Go live and Change processor screens | [03](03-processor-lock-in-and-archive.md) §3.7, [06](06-swap-engine-v2.md) §6.4 |
| **OQ6** | Stripe Connect | **Agreed:** (a) the Connect platform-migration program is a separate plan for later; (b) **strict rule** — a Direct Payment on a processor with Connect enabled can only move to another Stripe processor (with Connect enabled), and only to another Stripe platform once no live connected accounts remain or a completed platform migration exists | [05](05-provider-parity-and-portability.md) §5.5 |
| **OQ11** | appfactory's tier resolver and signup claim | **Set aside.** It is appfactory studio work, not Direct Payments. Written up — with a plain-language explanation for a later conversation — in [14](14-appfactory-studio-modifications.md) §14.3 (M9, M10) | [14](14-appfactory-studio-modifications.md) |
| **OQ13** | Old account must stay open under S2 until the last subscriber re-enters details or a closing date passes | **Agreed.** The preview shows how many subscribers are still on the old account | [07](07-subscription-continuity.md) §7.6 |

## 12.1a Decided later (2026-10-16)

| # | Decision | Where it is applied |
|---|---|---|
| **D-DEV** | The app is still in development with no real data: every schema change goes straight into the existing create migrations; no upgrade migrations, back-fill commands or orphan clean-up | [02](02-target-model.md) §2.4, [16](16-safety-rails-implementation.md) §1.3 |
| **D-PA0** | The PA0 implementation plan is approved, all eight steps; implementation waits for the owner's go-ahead | [16-safety-rails-implementation.md](16-safety-rails-implementation.md) |
| **D-EMAILS** | Default templates for **every** Direct Payments email; every email sent through **`MailHandlerSender`** — when a Direct Payment has no handler, the default MJML is sent without a database row. Templates live in afsemail's `mjml-templates/{mjml,html}/defaults/dp/` so the gallery shows them; a tenant changes one by saving it from the gallery as a normal handler (revised 2026-10-17). Not wired yet | [15](15-default-emails.md) |

## 12.2 Decided (2026-10-16, second pass)

| # | Question | Decision | Where it is applied |
|---|---|---|---|
| **OQ1** | May an archived processor be unarchived? | **Yes, audited** (who, when, why) | [03](03-processor-lock-in-and-archive.md) §3.2 |
| **OQ2** | Are Direct Payments archive-only? | **Archive once used**: a DP that has ever had an invoice, subscription or binding can only be archived (and unarchived, audited); a never-used DP can still be deleted | [05](05-provider-parity-and-portability.md) §5.3 |
| **OQ4** | A tier that cannot be provisioned on the target | **Block the change** until every tier has a working plan on the target; the wizard names the failing tier and offers retry | [06](06-swap-engine-v2.md) §6.4 |
| **OQ7** | PayPal account fingerprint | **App id + PayPal merchant id**: the tenant enters the merchant id when adding a PayPal processor; verified where PayPal allows | [03](03-processor-lock-in-and-archive.md) §3.4 |
| **OQ8** | Returning guests' saved cards | **Same-browser signed payer token only**; never by e-mail. Signed-in users always see theirs | [04](04-payer-customer-identity.md) §4.3 |
| **OQ9** | Scheduled processor changes | **Include now** (differs from the recommendation) — designed in [06](06-swap-engine-v2.md) §6.8, built in PA2 (engine) and PA8 (wizard) | [06](06-swap-engine-v2.md) §6.8 |
| **OQ10** | Renewal page location | **Website domain + SDK**: a ready-made page at `/portal/dp/renew/{token}` on the website's own domain, plus `dp.subscription.reauthorise()` for developers | [07](07-subscription-continuity.md) §7.6 |
| **OQ12** | Promote Stripe's card copy for Stripe → Stripe | **Recommend it in the wizard**, never wait on it | [07](07-subscription-continuity.md) §7.5 |
| **OQ14** | Renewal e-mails | **Configurable per DP with built-in default templates**, so they are never silently off during a change | [07](07-subscription-continuity.md) §7.6 |
| **OQ15** | Existing billing markers in Direct Payments code | **Re-tag once** to `AFS_DIRECT_PAYMENTS_MODIFIER_ADDITION`, recorded in `plans/markers.md` as an owner-approved exception; appfactory code keeps its own marker | [13](13-naming-and-separation.md) §13.3 |

**Every question is now answered.** The only item not scheduled is OQ11 (set aside — appfactory studio work,
[14](14-appfactory-studio-modifications.md) §14.3), to be explained to the owner separately.

## 12.3 Things this plan deliberately does not do

- Move card numbers between providers — impossible; only Stripe can copy its own card data, and only by request.
- Merge two payers who turn out to be one person — still its own design (`../direct_payments/06` §6.8 item 4).
- Route charges across two **active** processors at once — still a follow-up.
- Change anything in appfactory studio — that is [14](14-appfactory-studio-modifications.md), scheduled separately.
