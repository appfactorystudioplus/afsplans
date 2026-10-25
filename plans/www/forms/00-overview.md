# 00 — Overview

## 0.1 What this module is for

A tenant builds a form in the portal admin, publishes it, embeds it on their site through the
externals SDK, and reads the responses back in the admin. That loop works today. What it does
not yet do is let the tenant *configure* the form beyond title, required and a list of choices
— and it does not keep the responses interpretable once the form changes.

Those are the two halves of this plan: **configuration depth**, and **data integrity**.

## 0.2 Goals

| # | Goal | Where |
|---|---|---|
| G1 | No forms endpoint is reachable without credentials, and no tenant can read another's forms or submissions | [02](02-security-and-tenancy.md) |
| G2 | A submission stays interpretable forever, whatever happens to the form afterwards | [04](04-database-schema.md), [09](09-publishing-and-versioning.md) |
| G3 | Every question type the bundled runtime supports is reachable from the toolbox | [05](05-form-builder.md) §5.3 |
| G4 | A question can be shown, hidden, enabled, required or cleared **by condition** | [06](06-logic-and-validation.md) §6.2 |
| G5 | An answer can be validated by pattern, range, count or expression, on the client **and** the server | [06](06-logic-and-validation.md) §6.4, §6.9 |
| G6 | A form can compute — expressions, calculated values, piping, scoring | [06](06-logic-and-validation.md) §6.5–§6.8 |
| G7 | A form can be authored in more than one language and served in the respondent's | [08](08-multi-language.md) |
| G8 | Publishing is a deliberate act with a versioned, immutable result | [09](09-publishing-and-versioning.md) |
| G9 | Every side effect of a submission is triggered by the server | [11](11-integrations-and-notifications.md) §11.2 |
| G10 | An operator can search, filter, page and export submissions | [10](10-submissions-and-data.md) §10.6 |
| G11 | The editor is usable on a tablet and by keyboard | [05](05-form-builder.md) §5.7 |

## 0.3 Non-goals

| Not building | Why |
|---|---|
| Replacing the runtime | SurveyJS v2.5.20 is bundled, MIT, and does the hard part well. Nothing here reimplements rendering, validation evaluation or expression parsing |
| Licensing Survey Creator | §0.4. If the licence is ever bought, [05](05-form-builder.md) §5.11 is the migration note — but this plan does not depend on it |
| A visual expression *designer* for arbitrary formulas | A condition builder for the common shapes plus a raw expression field with validation ([06](06-logic-and-validation.md) §6.3). A full formula IDE is its own product |
| Response quotas / panel management | Survey-panel features (quota by segment, respondent panels, incentives) are a different product from a website form builder. **Not to be confused with the plan quotas in [15](15-allocation.md)** — those are how many forms and submissions a *tenant's membership tier* allows, and they are very much in scope |
| A/B testing of form variants | Genuinely useful, cleanly separable, and needs an experiment framework that does not exist here |
| Offline submission | Requires a service worker, a client store and a conflict policy. Partial saves ([10](10-submissions-and-data.md) §10.5) cover the common case |
| E-signature legal compliance (eIDAS / ESIGN audit trails) | The `signaturepad` question captures a drawn signature. A legally-binding signature product needs identity proofing, tamper-evident sealing and an audit certificate |

## 0.4 The licensing constraint, stated once

The bundle in `public/assets/libs/surveyjs/` carries this header:

```
surveyjs - Survey JavaScript library v2.5.20
Copyright (c) 2015-2026 Devsoft Baltic OÜ
License: MIT
```

That is the **Form Library** — `survey.core.min.js`, `survey-js-ui.min.js`,
`survey-themes.js`, `survey-core.min.css`. It renders forms, evaluates conditions, runs
validators, resolves expressions and applies themes. All of it is free to use.

**Survey Creator** — the drag-and-drop designer with the Logic tab, Translations tab and Theme
editor — is a separate, commercially licensed product, and it is **not** in the bundle. Neither
are the Dashboard (`survey-analytics`) or PDF Generator (`survey-pdf`) add-ons; confirm their
licence terms before proposing either ([10](10-submissions-and-data.md) §10.7, §10.8).

This has one consequence that runs through the whole plan: **the editor is ours to build, but
the schema it writes is not ours to invent.** Every property the properties panel sets must be
a property the v2.5.20 runtime actually reads. A checkbox that writes `question.mandatory` when
the runtime reads `isRequired` produces a form that looks configured and behaves as though it
is not — and that failure is invisible until a respondent skips the question.

**So: each document that specifies a property names it exactly, and
[14](14-rollout-and-testing.md) §14.6 requires the property list to be verified against the
bundled build rather than against the online documentation**, which describes whatever version
is current on surveyjs.io and may be ahead of 2.5.20.

## 0.5 Glossary

| Term | Meaning here |
|---|---|
| **Definition** | The SurveyJS JSON describing pages, questions, logic and completion behaviour. Today: `laravel_forms.json_data` |
| **Envelope** | The wrapper the editor actually saves: `{ form_data, form_style, use_custom_style, form_style_custom }`. `form_data` is the definition; the rest is presentation |
| **Version** | An immutable snapshot of the envelope, created at publish. New in [09](09-publishing-and-versioning.md) |
| **Draft** | The mutable working copy the editor edits. Never served to a respondent |
| **Submission** | One completed response. Names the version it answered |
| **Partial** | An in-progress response, resumable. New in [10](10-submissions-and-data.md) §10.5 |
| **Answer** | One question's value inside a submission, keyed by question `name` |
| **Question name** | The stable key. **Not** the title. It is what expressions, piping tokens, triggers and every export column refer to |
| **Mail handler** | The existing `laravel_mail_handlers` mechanism configured through `EmailConstructionBuilder` in the `forms:response` view |
| **Site** | `website_domain` / `website_reference_num` — the multi-site scoping pair already present on `laravel_forms` and unused |

## 0.6 The four decisions

### D1 — Keep the custom editor; render the canvas with the real runtime

Rejected: hand-rolling more question renderers. `renderQuestion()` is already 180 lines
covering 12 types badly, and the types worth adding next (panel, dynamic panel, matrix
dropdown) are exactly the ones a hand renderer cannot approximate. Rejected also: buying
Survey Creator, which is a commercial decision this plan should not presume.

Chosen: mount a real `SurveyUI.renderSurvey()` in the canvas with a design-mode overlay for
selection, drag targets and inline actions ([05](05-form-builder.md) §5.2). The runtime draws
the question; we draw the chrome around it. This is what makes the theme selector honest
(**F40**) and what makes every new question type free.

### D2 — Publishing freezes a version

Rejected: storing a schema copy on every submission — cheap to build, and it multiplies a 40 KB
definition across 10,000 rows. Rejected: no versioning, reconstructing meaning from question
names — which is the status quo and is why **F17** exists.

Chosen: `laravel_form_versions`, immutable, one row per publish, and
`laravel_form_submissions.form_version_id` pointing at it
([04](04-database-schema.md) §4.3). A submission is read through the version it answered. The
draft is free to change without touching a single historical response.

### D3 — Answers are stored twice: as a document and as rows

The submission document (`submission_data` JSON) is the record of truth — complete, exactly as
the runtime produced it. But "how many people chose *Other*" and "show me submissions where
`budget > 5000`" cannot be asked of a JSON blob in a `TEXT` column.

Chosen: keep the document, and additionally write one row per answered question into
`laravel_form_answers` ([04](04-database-schema.md) §4.5). The rows are derived, rebuildable
from the document, and exist for querying, export and per-question analytics
([10](10-submissions-and-data.md) §10.7). **The document is authoritative; the rows are an
index.** If they ever disagree, the rows are rebuilt.

### D4 — Validation runs twice, and the server's answer wins

The runtime validates in the browser. That is a usability feature, not a guarantee — the public
submit endpoint accepts any JSON body today (**F8**).

Chosen: the submit handler re-evaluates the published version's requiredness, validators and
types server-side before writing ([06](06-logic-and-validation.md) §6.9). A form that says
"email required, must match a pattern" enforces it even against `curl`.

This is also where visibility and validation meet: a question hidden by `visibleIf` must not be
required. The server has to evaluate the same conditions the client did, against the same
answers — which is a real piece of work, and §6.9 specifies the reduced expression evaluator
that does it.

## 0.7 What "solid" means for this module

Six properties, each of which fails today, and each of which has an acceptance test in
[14](14-rollout-and-testing.md):

1. **Nothing is reachable without credentials.** (**F1**–**F14**)
2. **No save loses data.** Not choice values (**F32**), not the whole session (**F44**), not a
   long answer to a `TEXT` column (**F16**).
3. **No two questions share a name.** (**F31**)
4. **The editor shows what the respondent will see.** Theme included. (**F40**)
5. **A submission read today means what it meant when it was made.** (**F17**)
6. **Every configured side effect happens, exactly once, server-side.** (**F9**, **F57**)
