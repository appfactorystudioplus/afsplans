# 03 — Architecture

## 3.1 The layers

```
    ┌──────────────────────────────────────────────────────────────┐
    │  RESPONDENT   embedded form · window.Portal.forms · SurveyUI  │  12
    └──────────────────────────────┬───────────────────────────────┘
                                   │  GET published version · POST submit
    ┌──────────────────────────────▼───────────────────────────────┐
    │  PUBLIC API   projection · throttle · schema validation       │  02 · 06
    └──────────────────────────────┬───────────────────────────────┘
                                   │
    ┌──────────────────────────────▼───────────────────────────────┐
    │  VERSION      immutable published envelope · locales          │  09
    └──────────────────────────────┬───────────────────────────────┘
                                   │  answered against
    ┌──────────────────────────────▼───────────────────────────────┐
    │  SUBMISSION   document + derived answer rows + files          │  04 · 10
    └──────────────────────────────┬───────────────────────────────┘
                                   │  queued
    ┌──────────────────────────────▼───────────────────────────────┐
    │  EFFECTS      mail · notification · webhook · ingest · stats  │  11
    └──────────────────────────────────────────────────────────────┘

    ┌──────────────────────────────────────────────────────────────┐
    │  DRAFT        the editor's mutable working copy               │  05 · 06 · 07 · 08
    │               publish ──► VERSION                             │  09
    └──────────────────────────────────────────────────────────────┘

    identity (optional, any layer):  Site Users  →  ../site_users/
    payment  (optional, on submit):  Direct Payments → 11 §11.9
```

The important shape here: **the draft is not on the respondent's path at all.** Today it is —
`GET /forms/{ref}` serves whatever the editor last saved (**F19**) — and that single edge is
what makes every editor change a production change.

## 3.2 The runtime owns semantics; we own authoring and storage

The boundary that decides how much of this plan is new work.

| We own | SurveyJS v2.5.20 owns |
|---|---|
| The editor and every control in it | Rendering the form |
| Which properties are configurable, and their UI | What each property *means* |
| Storage, versioning, tenancy | Condition evaluation (`visibleIf` et al.) |
| The submit pipeline and its effects | Validator execution |
| Server-side re-validation (§3.5) | Expression parsing (`{q1} > 5 and {q2} = 'yes'`) |
| Export, analytics, admin surfaces | Localization resolution |
| Themes we ship as presets | Theme application (`applyTheme`) |

**We never reimplement a runtime concept.** The properties panel writes `visibleIf`; it does
not build a parallel visibility system. The validation UI writes `validators[]`; it does not
build a parallel validator. This is what makes [06](06-logic-and-validation.md) — the largest
feature document here — mostly a UI exercise over a capability that is already loaded on the
page.

There is exactly one place where we are forced to duplicate the runtime's semantics, and it is
deliberate: **server-side validation** (§3.5).

## 3.3 The three identifiers, and not confusing them

| Identifier | Layer | Shape | Example |
|---|---|---|---|
| `form.reference_num` | Form | `v` + 11 lowercase alnum | `va1b2c3d4e5f` |
| `form_version.reference_num` + `version` | Version | same, plus an integer | `vf9e8d7c6b5a`, `3` |
| `submission.reference_num` | Submission | same | `v0z9y8x7w6v` |
| **question `name`** | **Definition** | author-chosen, stable | `attendee_email` |

The fourth is the one that gets treated casually and should not be. A question `name` is:

- the key in `submission_data`
- the column header in every export
- the token in every piping expression (`{attendee_email}`)
- the operand in every `visibleIf`, trigger and calculated value
- the join key between a submission and the version that describes it

It is currently auto-generated as `question1`, uneditable (**F30**), and prone to collision
(**F31**). [05](05-form-builder.md) §5.5 treats naming as a first-class feature for this
reason, not as a text field someone forgot to enable.

## 3.4 The envelope, and why it survives

The editor saves:

```json
{
  "form_data":         { /* SurveyJS definition: pages, questions, logic */ },
  "form_style":        "DefaultLight",
  "use_custom_style":  false,
  "form_style_custom": { /* SurveyJS theme JSON: cssVariables, backgroundImage, … */ }
}
```

This split is good and is kept. Definition and presentation change for different reasons, at
different rates, by different people — and a version ([09](09-publishing-and-versioning.md))
freezes both together, which is what makes a two-year-old submission renderable as it looked.

Two changes:

1. **The envelope gains a `schema_version`.** When the editor's output shape changes — and it
   will, at least for locales ([08](08-multi-language.md)) — a reader must be able to tell
   which shape it is holding. Today the reader guesses: `if (json.form_data)` in three separate
   places (`FormsEditorController.ts:239`, `renderer.ts:46`, `FormsViewsController.ts:587`),
   each with a slightly different fallback.
2. **One parser, one place.** Those three guesses become a single `parseEnvelope()` in a shared
   module, used by the editor, the renderer, and the submission viewer. The fallback for a bare
   definition stays — old rows exist — but it is implemented once.

## 3.5 The one duplication: server-side validation

The runtime validates in the browser. The public endpoint accepts anything (**F8**). Closing
that means the server must decide whether a submission satisfies the form — which means the
server must evaluate the same conditions the client did.

This is genuinely duplicated logic, and it is worth being precise about how much:

| Server must evaluate | Server does **not** evaluate |
|---|---|
| `isRequired`, against **visible** questions only | Rendering, layout, themes |
| `visibleIf` / `enableIf` on questions, panels and pages | Progress bars, page transitions |
| Question type and choice membership | `defaultValueExpression` display side effects |
| `validators[]` — numeric, text, email, regex, answercount, expression | Piping into titles (display only) |
| `calculatedValues` needed by the above | Timers, TOC, preview mode |

[06](06-logic-and-validation.md) §6.9 specifies the reduced expression evaluator — a
deliberately small subset of the runtime's grammar, with an explicit rule for what happens when
it meets an expression it cannot parse (**it fails closed and flags the submission for review,
rather than accepting it or rejecting the respondent**).

The alternative — running the runtime server-side under Node — was considered and rejected:
it puts a JS runtime in the request path of a PHP application for one function, and it couples
form submission availability to a second process.

## 3.6 Multi-site and multi-tenant

`website_domain` and `website_reference_num` are on `laravel_forms` and never written
(**F3**). After [02](02-security-and-tenancy.md):

- A form belongs to exactly one site.
- A tenant may own several sites.
- **Every admin query is scoped by the caller's site set**, through a model scope rather than
  through discipline.
- Public reads are scoped by the form's own reference, and additionally checked against the
  requesting `Origin` where configured ([02](02-security-and-tenancy.md) §2.8).

The enforceable version of the rule: a test asserts that no query against `laravel_forms` in an
admin code path executes without a `website_reference_num` predicate
([14](14-rollout-and-testing.md) §14.5).

## 3.7 State in the editor

The current editor keeps `survey`, `selectedQuestion`, `currentPageIndex`, `currentTheme`,
`useCustomStyle` and `formStyle` as module-level mutable singletons, and restores style from a
single unkeyed `sessionStorage` entry (**F41**). The module is lazily imported once and reused
across views, so opening a second form inherits the first form's state.

The target is one **editor session object**, created per form open and destroyed on teardown:

```
FormEditorSession
  ├── reference_num            the form this session edits
  ├── model                    the SurveyJS model (design mode)
  ├── selection                { kind: 'question'|'page'|'panel'|'survey', name }
  ├── history                  undo/redo stacks            05 §5.9
  ├── dirty                    since last save             05 §5.10
  ├── theme                    { name, useCustom, custom }
  └── locale                   the locale being authored   08 §8.4
```

Draft recovery keys on `reference_num` — `formDraft:{reference_num}` — so recovering form A
cannot restore into form B. `localStorage['surveyJSON']` (**F42**) is removed.

## 3.8 Failure posture

| Situation | Behaviour |
|---|---|
| Form soft-deleted, unpublished, or closed | Public fetch **404** ([09](09-publishing-and-versioning.md) §9.5) |
| Draft has unpublished changes | Respondents keep seeing the published version. The editor shows "N changes not published" |
| Submission fails server validation | **422** naming the questions, so the runtime can display them inline. Never a silent accept |
| Submission contains an expression the server cannot evaluate | Accept, store, **flag for review**. Never reject a respondent for our parser's limits (§3.5) |
| Submission stored, effect job fails | Submission stands. The job retries with backoff; the failure is visible on the submission's timeline ([11](11-integrations-and-notifications.md) §11.4) |
| Mail handler unreachable | Retry, then surface on the submission. **Never lose the submission because the email failed** |
| File upload fails mid-form | The question reports the failure; the rest of the answers survive; the respondent can retry that question |
| Version referenced by a submission is missing | Render the submission raw, keys and values, with a banner. **A submission is always readable, even degraded** |
| Two editors open on one form | Last write wins, but the second save is refused with a 409 naming who saved and when ([05](05-form-builder.md) §5.10) |
| Storage encoding is the legacy entity form | `parseEnvelope()` detects and decodes it. Both encodings readable through the migration window ([04](04-database-schema.md) §4.7) |

The two rows that matter most are the fourth and the last-but-two. **A respondent must never be
blocked by a limitation of ours**, and **a submission must never become unreadable** — those
are the failure modes that destroy trust in a form product, and both are live today (**F16**,
**F17**).

## 3.9 What this plan deliberately does not build

| Not building | Why |
|---|---|
| A rendering engine | §3.2 — the bundled runtime is MIT and better than what we would write |
| Survey Creator's designer | Commercially licensed and not bundled ([00](00-overview.md) §0.4) |
| A general expression language | The runtime has one. We build a UI over it, plus a reduced *evaluator* for the server (§3.5) |
| Real-time collaborative editing | Optimistic concurrency with a 409 covers the actual failure (two admins, one form). CRDTs are a project |
| A workflow/approval engine on submissions | Status, assignee and notes ([10](10-submissions-and-data.md) §10.9) — routing, SLAs and escalation are a different product |
| A BI layer | Per-question aggregates and export ([10](10-submissions-and-data.md) §10.7). Anything beyond that belongs downstream of the export |
| Response quotas and panel management | [00](00-overview.md) §0.3 |
| Our own file storage service | The upload path is hardened and given a real endpoint ([02](02-security-and-tenancy.md) §2.10); the storage backend stays whatever the deployment already uses |

Each is defensible, and each is a reason this plan ends where it does.
