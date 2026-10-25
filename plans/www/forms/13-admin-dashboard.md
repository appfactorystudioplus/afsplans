# 13 — The Admin Dashboard

`resources/js/portal/admin/controllers/forms/` and `views/formsView.ts`.

## 13.1 What exists

Six registered subviews (`formsView.ts`): `forms:init`, `forms:edit`, `forms:preview`,
`forms:submissions`, `forms:submission-response`, `forms:response`. The routing, lazy loading
and template registration are all sound and are kept.

What they render is thin:

| View | Today |
|---|---|
| List | Id, name, created, submission count, and an **Actions dropdown** |
| Edit | The builder ([05](05-form-builder.md)) |
| Preview | A read-only render |
| Submissions | Id, email, date, "View Response" — capped at 200, no filters (**F23**, **F52**) |
| Submission response | One submission, rendered without type awareness (**F54**) |
| Response | The `EmailConstructionBuilder` — configuring mail handlers nothing reads (**F57**) |

Two structural problems. The **Actions dropdown** is a `<select>` whose `change` navigates
(`FormsViewsController.ts:355–365`), which is a control that cannot be scanned, cannot show
which actions are unavailable, and cannot warn before a destructive one. And **Delete goes to a
route whose method does not exist** (**F7**), while the client never checks the response
(`:530`), so the failure is invisible.

## 13.2 The shape

```
Forms
├── Overview          §13.3   what needs attention today
├── Forms list        §13.4   status · responses · last response · quick actions
│   ├── Editor        05 · 06 · 07 · 08
│   ├── Preview       05 §5.2
│   ├── Versions      09 §9.4
│   ├── Submissions   10 §10.9
│   │   └── Detail    10 §10.9
│   ├── Results       10 §10.7
│   ├── Share         09 §9.6
│   └── Settings      §13.6
├── All submissions   §13.5   across every form
└── Templates         §13.7   start from a template
```

Six views become a module. The editor is one screen among several rather than the whole product.

## 13.3 Overview

The screen an operator opens in the morning. *What needs me today*, not a chart nobody acts on.

- **New responses** since last visit, per form, linked.
- **Flagged** — spam-scored or `validation_state = 'unverified'`
  ([06](06-logic-and-validation.md) §6.9.3).
- **Failed effects** — mail handlers or webhooks that exhausted their retries
  ([11](11-integrations-and-notifications.md) §11.2). Currently invisible; a tenant's first
  sign is a customer complaint.
- **Forms with unpublished changes** ([09](09-publishing-and-versioning.md) §9.3).
- **Closing soon** — `closes_at` within 7 days, or near `close_after_responses`.
- **Setup warnings** — published with no mail handler and no notification (responses arrive and
  nobody is told); a file question with no `acceptedTypes`; a form with no email question but a
  confirmation handler configured.
- Response volume, 7d / 30d, and completion rate.

The setup warnings are what stop a tenant publishing a form that silently collects into a void —
the forms equivalent of a shop that cannot take money.

## 13.4 The forms list

| Column | |
|---|---|
| Name | With the slug beneath ([09](09-publishing-and-versioning.md) §9.6) |
| Status | Draft · Published v3 · **4 unpublished** · Closed · Archived |
| Responses | Total, and new since last visit |
| Last response | Relative time — the single best signal of whether a form is working |
| Site | When the tenant has more than one |
| Updated | By whom |

**Actions become buttons and a proper menu**, not a `<select>`: Edit · Preview · Share ·
Submissions · Results · Versions · Duplicate · Settings · Close/Reopen · Archive · Delete.

- Unavailable actions are **disabled with a reason**, not absent — "Share: publish first".
- **Delete confirms**, names the submission count, and requires typing the form name when
  responses exist ([02](02-security-and-tenancy.md) §2.5). It also **checks the response**,
  which the current implementation does not.
- **Duplicate** copies the definition, theme, translations and settings into a new draft — never
  the submissions. The most-wanted missing action, because a tenant's second form is usually
  their first form with three changes.

Plus filters (status, site, has-responses, date), search across name and slug, sort, and bulk
archive / delete / export.

Creation ([13](13-admin-dashboard.md) §13.7) offers a blank form, a template, or an import.

## 13.5 All submissions

A cross-form view the module has no equivalent of. Every submission the tenant owns, filterable
by form, date, status and flag, searchable by email or answer text — for the operator whose
question is "did anyone contact us today", which currently means opening each form in turn.

## 13.6 Per-form settings

One screen, and the place a tenant decides how a form behaves. Everything here writes
`laravel_forms.settings` ([04](04-database-schema.md) §4.8), and none of it touches the SurveyJS
definition or creates a version.

| Section | Contents |
|---|---|
| **General** | Name, slug, description, site, default locale |
| **Availability** | Status, `opens_at`, `closes_at`, `close_after_responses`, the closed message ([09](09-publishing-and-versioning.md) §9.5) |
| **Responses** | Allow multiple, require login ([11](11-integrations-and-notifications.md) §11.8), partial saving and its TTL, resume links, allow editing after submit |
| **Identity** | Which question supplies the email; which supplies the display name ([04](04-database-schema.md) §4.4.1) |
| **Spam** | Honeypot, minimum completion time, captcha, origin check mode ([02](02-security-and-tenancy.md) §2.8) |
| **Notifications** | Admin notification on/off, digest, conditional rule, recipients ([11](11-integrations-and-notifications.md) §11.4) |
| **Email responses** | The existing `EmailConstructionBuilder` — now actually wired ([11](11-integrations-and-notifications.md) §11.3) |
| **Webhooks** | Endpoints, events, secrets (write-only), delivery log ([11](11-integrations-and-notifications.md) §11.5) |
| **Payments** | Direct Payment, amount or amount expression ([11](11-integrations-and-notifications.md) §11.9) |
| **Data** | Export defaults, PII inclusion, retention |
| **Danger** | Close, archive, delete, **purge** ([02](02-security-and-tenancy.md) §2.6) |

Identity and Notifications sit adjacent deliberately: "who gets emailed" depends on "which
question holds the email", and a tenant meeting the second question after configuring the first
is how confirmations end up going nowhere.

## 13.7 Templates

A tenant's second form is rarely built from nothing.

- **System templates** — contact, registration, feedback, application, RSVP, order, survey,
  quiz, support request. Each a full definition with sensible logic, validation and naming, so a
  template is also a worked example of the features in
  [06](06-logic-and-validation.md).
- **Tenant templates** — save any form as a template for that tenant.
- **Import / export** a definition as JSON, which is also the migration path from another tool,
  and the disaster-recovery path.

Import runs the same validation as publish ([09](09-publishing-and-versioning.md) §9.2.1) —
imported definitions are the most likely source of duplicate names and dangling logic
references, since they did not come through the editor's guards.

## 13.8 What the admin cannot do

| Not offered | Why |
|---|---|
| Edit a submitted response's answers | A submission is a record of what someone said. Corrections are a note, or an edit-by-respondent recorded as a revision ([11](11-integrations-and-notifications.md) §11.8) |
| Edit a published version | Versions are immutable by definition ([09](09-publishing-and-versioning.md) §9.4). Change the draft and publish |
| Delete a version with submissions | It is the only thing that makes those submissions readable |
| See a mail provider's key or secret | Provider admin, and never in a response ([11](11-integrations-and-notifications.md) §11.3) |
| See a webhook secret after saving | Write-only; rotate instead |
| Change another tenant's form | [02](02-security-and-tenancy.md) |
| Run custom JavaScript in a form | [06](06-logic-and-validation.md) §6.11 |

The first is the one operators will ask for. Editing answers makes the stored response disagree
with the confirmation email, the export already taken and the webhook already delivered — and
afterwards there is no way to tell which was right.

## 13.9 Front-end structure

`FormsViewsController.ts` is 898 lines covering six views, the API client, the list renderers,
the submission model builder and a jQuery PDF path. It grows with everything in this plan.

```
controllers/forms/
  api.ts                    the admin API client, typed
  views/
    overview.ts             §13.3
    list.ts                 §13.4
    submissions.ts          10 §10.9
    submissionDetail.ts     10 §10.9
    results.ts              10 §10.7
    versions.ts             09 §9.4
    share.ts                09 §9.6
    settings.ts             §13.6
    templates.ts            §13.7
  editor/                   05 §5.12
  shared/
    envelope.ts             parseEnvelope — 03 §3.4
    renderAnswer.ts         per-type answer rendering — 10 §10.4
    escape.ts               02 §2.11
```

Three rules for the rewrite:

- **No `innerHTML` from records.** The list builders are where **F36** lives — public submitter
  markup reaching the admin DOM. DOM construction, `textContent`, `dataset`
  ([02](02-security-and-tenancy.md) §2.11).
- **One envelope parser**, one answer renderer, shared with the editor and the SDK
  ([03](03-architecture.md) §3.4).
- **Drop jQuery from the PDF path.** `print_button_press_event` and `_init_init_submission` use
  `$` and `$.post` against a legacy endpoint; the server-side renderer
  ([10](10-submissions-and-data.md) §10.8) removes the need for either.

`formsView.ts`'s registration pattern — `makeLazyViewListener`, template, `localizeDOM`,
`applyFeatureFlagsUI` — is good and every new view follows it. New views must also **destroy
their session on teardown**, which the editor currently does not ([05](05-form-builder.md)
§5.10).

## 13.10 Acceptance

- The Overview shows new responses, flagged submissions, failed effects, unpublished changes and
  setup warnings, each linked.
- The list shows status, version, unpublished-change count and last-response time.
- Actions are buttons and a menu; unavailable ones are disabled with a reason.
- Delete confirms with the submission count, requires the name, **checks the response**, and
  does not 500.
- Duplicating a form copies definition, theme, translations and settings — and no submissions.
- A form's settings can be changed without creating a version.
- Configuring an email response in the Response view causes an email to be sent on the next
  submission.
- Webhook secrets are never returned after saving.
- The submissions list pages, filters, searches and exports
  ([10](10-submissions-and-data.md) §10.10).
- All-submissions shows responses across every form the tenant owns.
- A submission's answers cannot be edited from the admin.
- A published version cannot be edited from the admin.
- Importing a definition with duplicate names is refused with the same message publish gives.
- No admin list builds HTML from a record via string interpolation.
