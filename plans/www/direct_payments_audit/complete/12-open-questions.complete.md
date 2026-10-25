# 12 — Owner Decisions (complete — every decision checked against the code and applied)

## Status

**Checked 2026-10-17.** This file is a record of owner decisions, not a build. The work was to confirm that each decision is applied, and to close the two small gaps that were not owned by another step. Nothing is committed from this step. Only a `tsc --noEmit` run and code searches back this up — no test was run for it, and the wizard text was not looked at in a browser.

## Decision by decision

| Decision | Applied? | Where / evidence |
|---|---|---|
| **D-SEP** completely separate from appfactory studio | ✅ | code side step 09; vocabulary gate (§13.6) clean and enforced by `VocabularyGateTest` (step 13); appfactory-side changes step 14 |
| **OQ3** copy cards when possible, otherwise an easy re-entry path | ✅ | step 07 (S1/S2, per subscriber, automatic) |
| **OQ5** live → sandbox refused; sandbox → live only by "Go live"; explanation shown | ✅ (gap closed here) | refusal in `SwapCompatibility`, Go live routes `…/go-live(/preview)`; hint texts on the create forms (step 16). **Added now:** the Change processor wizard's Prepare step shows a "sandbox and live are separate worlds" note whenever the two processors' modes differ |
| **OQ6** Connect strict rule | ✅ | step 05 (`SwapCompatibility`) |
| **OQ11** tier resolver / signup claim set aside | ✅ nothing to do | written up in step 14 §14.3 |
| **OQ13** old account stays open under S2; preview counts who is still on it | ✅ | step 07 (retire blocked while live legs exist) and the wizard's "Live subscriptions (stay on the current processor)" row |
| **D-DEV** schema straight into the create migrations | ✅ | followed in every step (no upgrade migrations) |
| **D-PA0** PA0 plan approved | ✅ | step 16 |
| **D-EMAILS** default templates for every email, all through `MailHandlerSender` | ✅ (no real send) | step 15; `BillingMailer` no longer used by Direct Payments |
| **OQ1** unarchive, audited | ✅ | step 16 (`…/unarchive` for processors and Direct Payments) |
| **OQ2** archive once used | ✅ | step 16 (`BindingRegistry`, Direct Payment archive) |
| **OQ4** block the change until every tier can be provisioned | ✅ | step 06 (preview blocks, wizard names the tier) |
| **OQ7** PayPal app id + merchant id | ✅ | step 03 (`ProcessorAccountGuard`/`Verifier`, Merchant ID field) |
| **OQ8** returning guests: same-browser signed token only | ✅ | step 04 / 08 (payer token; never by e-mail) |
| **OQ9** scheduled processor changes | ✅ | step 06 (`ScheduledChangeService`, `…/swap/scheduled` routes, wizard) |
| **OQ10** renewal page on the website's domain + `reauthorise()` | ✅ | step 07 (`/portal/dp/renew/{token}`) and step 08 (SDK) |
| **OQ12** recommend Stripe's card copy for Stripe → Stripe | ✅ (gap closed here) | **Added now:** the wizard's Prepare step shows the recommendation when both processors are Stripe, saying plainly that nobody has to wait for it |
| **OQ14** renewal e-mails configurable per Direct Payment with built-in defaults | ✅ | step 07 + step 15 (a handler per event, or the "Default template"; the four processor-change e-mails cannot be switched off) |
| **OQ15** re-tag existing markers once | ✅ (routes file excepted) | step 13: 92 files re-tagged; `routes/portal.php` markers left because the file mixes appfactory and Direct Payments blocks |

## Changed

`resources/js/portal/admin/controllers/direct_payments/changeProcessorWizard.ts` — the two notes above, English only (no new locale keys; same as the rest of the wizard). `tsc --noEmit` is clean.

## Still open

Nothing is owned by another step any more (steps 13, 14 and 15 are built). Open only: anything that needs the remote server - real provider sandboxes, a real e-mail send, browser checks - listed in `11-rollout-and-testing.complete.md`.
