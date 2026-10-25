# 10 — Submissions and Data

Closes **F16**, **F20**, **F22**, **F23**, **F51**–**F56**.

## 10.1 What exists

`submitForm()` validates three fields, generates a reference, `json_encode`s the data and
inserts a row (`FormsController.php:213–258`). The admin lists up to 200 of them, newest first
(`:177`), and renders one at a time through `buildSubmissionResponseModel()`.

That is a submission store. What it is not is a place an operator can work.

## 10.2 The submit path

```
POST /forms/submit
  ├── throttle · payload cap · honeypot · origin        02 §2.8
  ├── resolve the PUBLISHED version                     09 §9.3
  ├── validate against it                               06 §6.9
  ├── claim uploaded files                              04 §4.6
  ├── write submission                                  04 §4.4
  ├── decompose into answers                            §10.4
  ├── record the event                                  04 §4.10
  ├── dispatch ProcessFormSubmission (queued)           11 §11.2
  └── 200 { reference_num, message?, redirect? }
```

Two changes to the response worth calling out:

- It **no longer echoes `submission_data`**. Returning a submission to an anonymous caller
  serves nothing and discloses something.
- It **may carry a redirect or a completion message**, resolved server-side from the version's
  `navigateToUrl` / `completedHtml` rules ([07](07-theming-and-layout.md) §7.2), so a
  conditional completion screen cannot be bypassed by editing client state.

## 10.3 What a submission records (F20)

Beyond the answers ([04](04-database-schema.md) §4.4): `form_version_id`, `locale`, `status`,
`ip_address`, `user_agent`, `referrer`, `source_url`, `duration_seconds`, `spam_score`,
`validation_state`, `site_user_id`, `idempotency_key`.

Each earns its place by answering a question an operator actually asks:

| Question | Field |
|---|---|
| "Is this spam?" | `spam_score`, `ip_address`, `duration_seconds` |
| "Where did this come from?" | `referrer`, `source_url` |
| "Which version of the form was this?" | `form_version_id` |
| "Why is this flagged?" | `validation_state`, the event log |
| "Is this the same person as that one?" | `site_user_id`, `email` |
| "Did they rush it?" | `duration_seconds` |

`ip_address` and `user_agent` are personal data in most jurisdictions.
`settings.export.include_ip` controls whether they leave the product
([04](04-database-schema.md) §4.8), and `settings.retention.purge_after_days` can drop them on a
schedule while keeping the answers. A tenant who does not want to hold IPs should be able to not
hold them.

## 10.4 Decomposition into answers (F54)

Each answer becomes a row in `laravel_form_answers` ([04](04-database-schema.md) §4.5), typed by
the question, so it can be counted, filtered and exported without parsing JSON.

| Question type | `value_json` | Typed columns | `row_key` |
|---|---|---|---|
| `text` | the string | `value_text`; `value_number` if numeric `inputType`; `value_date` if a date type | — |
| `comment` | the string | `value_text` | — |
| `radiogroup` · `dropdown` | the value | `value_text` = display text, `choice_values` = `[value]` | — |
| `checkbox` · `tagbox` | the array | `value_text` = joined display texts, `choice_values` = the array | — |
| `boolean` | bool | `value_bool` | — |
| `rating` | number | `value_number` | — |
| `ranking` | ordered array | `choice_values` in order | — |
| `matrix` | `{row: col}` | one row per matrix row | the row name |
| `matrixdropdown` | `{row: {col: v}}` | one row per row × column | `row.column` |
| `matrixdynamic` | `[{col: v}]` | one row per row × column | `index.column` |
| `paneldynamic` | `[{q: v}]` | one row per panel × question | `index.question` |
| `multipletext` | `{item: v}` | one row per item | the item name |
| `file` | file references | one row per file, `value_text` = original name | index |
| `signaturepad` | file reference | `value_text` = the file reference | — |
| `expression` · calculated | the computed value | typed by result | — |

Two rules make this reliable:

- **Display text is resolved through the submission's own version** — that version's
  `question_index` ([04](04-database-schema.md) §4.3.1), never the current draft. A choice
  renamed last week must not change how a submission from last month reads.
- **The document stays authoritative.** These rows are derived and rebuildable
  ([00](00-overview.md) §0.6 D3). If decomposition has a bug, it is fixed and rerun; no data is
  lost in the meantime.

The existing `flattenSurveyElements()` (`FormsViewsController.ts:594`) already walks `elements`,
`templateElements` and matrix `rows` recursively — it is the right traversal and it should be
lifted into the shared parser rather than rewritten.

## 10.5 Partial saves and resume

`laravel_form_partials` ([04](04-database-schema.md) §4.9) backs the runtime's
`sendResultOnPageNext`: answers are posted on each page transition and a resume token issued.

| Enabled | Behaviour |
|---|---|
| Off (default) | Nothing is stored until completion |
| On, anonymous | Resume token in a cookie and in a resume link; expires per `partial_ttl_hours` |
| On, signed in | Keyed to `site_user_id`; resumes on any device ([11](11-integrations-and-notifications.md) §11.8) |

Three rules that keep this from causing harm:

1. **A partial is not a submission.** Not exported, not counted, not notified on. A "2,400
   responses" figure that includes 1,900 abandonments is a lie the product told its tenant.
2. **Partials expire, always.** They hold answers a respondent chose not to submit. Indefinite
   retention of those is a liability, not a feature.
3. **Turning it on is a decision with a privacy consequence**, and the setting says so — the
   tenant is now storing answers from people who did not submit.

Abandonment data is genuinely useful — "70 % of drop-offs happen on page 3" is actionable — and
§10.7 surfaces it in aggregate, without needing the individual answers to persist.

## 10.6 Export (F51)

The single biggest omission in the operator surface. For most tenants, export is why the data
was collected.

| Format | Shape |
|---|---|
| **CSV** | One row per submission. Configurable delimiter and encoding (**UTF-8 with BOM** by default — without it Excel mangles every accented character, which is the most common export complaint any form product receives) |
| **XLSX** | Typed cells, a header row, frozen panes, and a second sheet with the question metadata |
| **JSON** | The raw documents, for a developer |
| **PDF** | Per submission, or a batch ([§10.8](#108-pdf)) |

### Column strategy

The hard part of exporting form data, and the reason to decide it once rather than per export:

| Case | Wide (default) | Long |
|---|---|---|
| Simple question | one column | one row per answer |
| Checkbox | one column of joined texts, **plus** optional one boolean column per choice | one row per selected choice |
| Matrix | one column per row × column | one row per cell |
| Dynamic panel | numbered columns (`attendee_1_name`, `attendee_2_name`…) up to the max present | one row per panel |
| Files | one column of URLs | one row per file |

Wide is what a tenant opens in Excel; long is what survives an unbounded dynamic panel. Offer
both, default to wide, and **cap wide expansion** with a warning rather than generating a
4,000-column file.

### Cross-version export (F17, again)

Exporting submissions that answered different versions is where versioning pays off and where a
naive implementation loses data:

- The header is the **union** of questions across the versions in range.
- A question a submission's version did not contain is **empty**, distinguishably from an
  unanswered one — a blank versus a `—`, documented in the metadata sheet.
- A renamed question is exported under both names unless the operator maps them; the export
  dialog offers the mapping, prefilled from the rename history
  ([05](05-form-builder.md) §5.5).
- Optionally restrict the export to a single version, which is the honest way to get a clean
  matrix.

### Mechanics

- Filters and search from the list view carry into the export — an operator exports what they
  are looking at.
- Column selection, with a saved default per form.
- PII columns (`ip_address`, `user_agent`) governed by `settings.export`
  ([04](04-database-schema.md) §4.8).
- **Queued for large exports**, delivered as a download link with an expiry, because a
  20,000-row XLSX generated in a request times out.
- Every export writes an event ([04](04-database-schema.md) §4.10) — who exported what, when,
  how many rows. Bulk extraction of personal data should leave a trace.

## 10.7 Per-question analytics (F53)

"What did people answer" currently requires opening submissions one at a time.

A **Results** view per form, driven by `laravel_form_answers` — index scans, not JSON parsing:

| Question type | View |
|---|---|
| Choice types | Counts and percentages per choice, bar chart, ordered by count |
| Rating | Distribution, mean, median |
| Boolean | Yes/no split |
| Numeric | Min, max, mean, median, histogram |
| Date | Distribution over time |
| Text | A paged list, with search; word frequency as a secondary view |
| Matrix | A heat grid of row × column counts |
| Dynamic panel | Rows per panel distribution, plus per-template-question aggregates |
| File | Count, total size, type breakdown |

Plus form-level:

- Submissions over time.
- **Completion funnel** — views → started → per-page drop-off → completed. This is the number
  that changes a form: "62 % of people abandon on page 3" points at a specific question.
- Median completion time.
- Source breakdown from `referrer` / `source_url`.
- Locale breakdown ([08](08-multi-language.md)).

**Everything here is filtered by version by default, and can be aggregated across versions with
a warning.** Aggregating a choice question across a version where a choice was renamed produces
two bars that should be one, and the interface should say so rather than quietly showing it.

*If the commercial `survey-analytics` package is ever licensed, it renders most of this from the
definition and the data directly — see [00](00-overview.md) §0.4. This section assumes it is
not.*

## 10.8 PDF (F14, F55)

The current path posts a full HTML document plus a client-chosen `tmp_filename` to the legacy
endpoint, then opens a second request to download it — and passes `title: data.name` where
`data` has `title`, so the parameter is always `undefined`
(`FormsViewsController.ts:755–786`).

Replace with a server-side renderer:

- `GET /forms/submissions/{reference_num}/pdf`, authenticated and ownership-checked
  ([02](02-security-and-tenancy.md) §2.4).
- Rendered from the submission's **version**, so the PDF shows the form as the respondent saw
  it — including the theme.
- A batch mode producing a zip, queued.
- No client-supplied filename and no client-supplied HTML anywhere in the path.

Signature answers render as images; file answers as a list with names, sizes and (optionally)
links. A signature that renders as a base64 string in a printed PDF — the current behaviour — is
the specific thing that makes the existing export unusable for the forms that most need it.

*The commercial `survey-pdf` package produces a fillable PDF from a definition; confirm its
licence before proposing it ([00](00-overview.md) §0.4).*

## 10.9 The operator surface (F52, F56)

### The list

Replaces a 200-row, unpaginated, unfiltered table (**F23**, **F52**):

- **Columns**: reference, name/email (from `settings.identity` —
  [04](04-database-schema.md) §4.4.1), date, status, version, plus **up to three question
  columns the operator pins**. Pinning is what makes the list about the form's actual content
  rather than about metadata.
- **Filters**: date range, status, version, locale, flagged, has files, assignee, read/unread,
  and **per-question filters** — "where `budget` > 5000", "where `region` = North" — which the
  answer rows make cheap.
- **Search** across answer text and email.
- **Sort** on any column.
- **Cursor pagination**, not `limit(200)`.
- **Bulk actions**: export selection, mark read, flag, assign, delete.

### The detail

`buildSubmissionResponseModel()` pairs each flattened element with its answer and renders it
without type awareness (**F54**) — a file answer shows as an object, a signature as base64, a
matrix as `[object Object]`.

Rendered per type instead, through the submission's version:

| Type | Rendering |
|---|---|
| Choice | The selected **text**, with the stored value on hover |
| Checkbox | A list of selected texts |
| Matrix | A table |
| Dynamic panel | Numbered cards |
| File | Name, size, type, thumbnail for images, authorised download link |
| Signature | The image |
| Expression / calculated | The value, marked as computed |
| Empty | "Not answered", visually distinct from an empty string |
| Not in this version | Marked as such |

Plus the panels an operator needs and cannot have today:

- **Metadata** — date, duration, locale, version, source, IP (permission-gated).
- **Timeline** — every event: created, mail sent/failed, webhook delivered/failed, exported,
  status changed, flagged ([04](04-database-schema.md) §4.10). **"Was this person emailed, and
  when" is the most common question a form's support surface receives**, and it is currently
  unanswerable.
- **Internal note** and **assignee**.
- **Actions** — resend the notification, mark read/unread, flag as spam, delete, purge, export,
  PDF, and *"view the form as this respondent saw it"*, which renders the version read-only with
  the answers filled in.

### Triage

`status` plus `read_at`, `assigned_to` and `internal_note` give a minimal workflow: unread →
read → assigned → handled. Deliberately minimal — routing rules, SLAs and escalation are a
different product ([03](03-architecture.md) §3.9), and a form product that grows a workflow
engine usually does it badly.

## 10.10 Acceptance

- A submission with two 80 KB signatures stores and re-renders intact.
- The submissions list pages past 200 and shows a total.
- Filtering "where `budget` > 5000" returns the right rows without parsing JSON.
- Pinning a question adds it as a list column.
- CSV opens in Excel with accented characters intact.
- A dynamic panel exports as numbered columns in wide mode and one row per panel in long mode.
- Exporting across two versions where a question was renamed produces both columns, and offers
  the mapping.
- A question absent from a submission's version is distinguishable from unanswered.
- A 20,000-row export completes as a queued job with a download link.
- Every export is recorded with who, what and how many.
- The Results view shows choice counts, a rating distribution and a completion funnel.
- Aggregating across versions with a renamed choice warns.
- A submission PDF shows the signature as an image and the form as that version looked.
- The detail view renders every question type correctly, including matrix and dynamic panel.
- The timeline shows a failed mail handler with its error and its retries.
- "View as the respondent saw it" renders the correct version with answers filled in.
- Partials are not counted as submissions anywhere, and expire.
