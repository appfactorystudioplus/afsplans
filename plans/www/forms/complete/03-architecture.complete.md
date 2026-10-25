# 03 — Architecture (complete)

**Plan:** `plans/www/forms/03-architecture.md`
**Repo:** `www`
**Date:** 2026-08-31

The shape forms was built to, recorded because it is the module the rest of the `www/`
programme is measured against — `module-form` is the only builder component that has worked
end to end since before any of these plans existed
(builder **v1** §1.2 — that folder is [legacy](../../builder/v1/README.md); see [builder v2](../../builder/v2/README.md) for the current model).

## What was built

### Services — `app/Externals/Forms/Services/`

| Class | Responsibility |
|---|---|
| `FormPublishService` | version → published, the publish/version contract ([`09-publishing-and-versioning.complete.md`](09-publishing-and-versioning.complete.md)) |
| `FormSubmissionValidator` | server-side validation; never trusts the client's copy of the logic |
| `FormAnswerDecomposer` | one submission → queryable per-answer rows ([`10-submissions-and-data.complete.md`](10-submissions-and-data.complete.md)) |
| `FormQuestionIndexBuilder` | the question index answers are decomposed against |
| `FormSubmissionQuotaService` | per-form submission limits |
| `FormAllocationService` | tier limits via `alloc_v2` ([`15-allocation.complete.md`](15-allocation.complete.md)) |
| `FormFileClaimService` | upload claim/commit, so an orphaned file is not a permanent one |
| `FormWebhookDispatcher` | outbound integrations ([`11-integrations-and-notifications.complete.md`](11-integrations-and-notifications.complete.md)) |

### Support — `app/Externals/Forms/Support/`

| Class | Responsibility |
|---|---|
| `FormJson` | the definition envelope — one place that knows the stored JSON's shape |
| `FormEnvelope` | request/response envelope |
| `FormSiteResolver` | which tenant site a form belongs to |

### Models

`Form` (with `default_locale`), `FormVersion` (with `locales`), `FormSubmission` (with
`locale`).

## The decisions worth recording

**Validation is server-side and duplicated deliberately.** `FormSubmissionValidator` re-runs
what the browser already ran. The client copy is UX; this one is the answer. Documented in
[`06-logic-and-validation.complete.md`](06-logic-and-validation.complete.md).

**Answers are decomposed, not stored only as a blob.** `FormAnswerDecomposer` +
`FormQuestionIndexBuilder` turn one submission into rows that can be aggregated across
submissions and across locales — which is what makes §8's *"two submissions in different
languages selecting the same choice aggregate as one value"* expressible at all.

**File uploads are claimed, then committed.** `FormFileClaimService` exists because a form
that accepts a file before the submission succeeds otherwise accumulates files belonging to
submissions that never happened.

**`FormJson` is the only thing that knows the definition's shape.** That is what keeps the
SurveyJS dependency from spreading through the codebase, and it is why the theming work
(§7) needed no schema change — the theme travels inside the definition
([`07-theming-and-layout.complete.md`](07-theming-and-layout.complete.md)).

## Verification

```bash
cd www
ls app/Externals/Forms/Services app/Externals/Forms/Support
php artisan test --filter=Form
```

Read directly on 2026-08-31: `app/Externals/Forms/`, `app/Models/Form*.php`,
`database/migrations/2026_04_11_000001_create_laravel_forms_tables.php`.
