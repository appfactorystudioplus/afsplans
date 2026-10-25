# 06 — Logic, Validation and Expressions

Closes **F26**, **F27**, **F28**, and the logic half of **F29** and **F46**.

This is the document the plan exists for. A form that cannot branch, cannot validate and cannot
compute is a styled `<form>` tag with a database behind it — and that is exactly what the module
is today. Everything specified here is **already implemented in the bundled runtime**; none of
it is reachable from the editor.

> **A note on property names.** Every property below is named exactly as the runtime expects.
> Where a name changed between SurveyJS v1 and v2, or where the property is new enough that the
> bundled 2.5.20 build may predate it, it is marked *verify*. Confirm against
> `public/assets/libs/surveyjs/survey.core.min.js` — not against surveyjs.io, which documents
> whatever version is current ([00](00-overview.md) §0.4,
> [14](14-rollout-and-testing.md) §14.6). A property panel that writes a name the runtime does
> not read produces a form that looks configured and behaves as though it is not, and that
> failure is invisible until a respondent hits it.

## 6.1 The surface, in one table

| Capability | Property | Level | §    |
|---|---|---|---|
| Show / hide by condition | `visibleIf` | question · panel · page · choice | 6.2 |
| Enable / disable by condition | `enableIf` | question · panel · choice | 6.2 |
| Require by condition | `requiredIf` | question | 6.2 |
| Clear when a condition holds | `resetValueIf` *verify* | question | 6.2 |
| Set when a condition holds | `setValueIf` + `setValueExpression` *verify* | question | 6.2 |
| Clear when hidden | `clearIfInvisible` | question · survey | 6.2 |
| Validators | `validators[]` | question · matrix column | 6.4 |
| Computed default | `defaultValueExpression` | question | 6.6 |
| Computed display | `expression` question | question | 6.6 |
| Named computation | `calculatedValues[]` | survey | 6.6 |
| Piping into text | `{question}`, `{panel.q}`, `{row.col}` | anywhere | 6.7 |
| Triggers | `triggers[]` | survey | 6.5 |
| Quiz scoring | `correctAnswer` + completion expressions | question · survey | 6.8 |
| Input type and mask | `inputType`, `maskType` *verify* | `text` | 6.3.4 |
| Server-side enforcement | — | submit pipeline | 6.9 |

## 6.2 Conditional logic (F26)

Five properties, one condition language, and a UI decision that determines whether tenants
actually use them.

### 6.2.1 What each one does

| Property | Meaning | Use it when |
|---|---|---|
| `visibleIf` | Show only when true. **Hidden questions are not validated** | Branching. The workhorse |
| `enableIf` | Visible but read-only until true | The respondent should see that something exists and why it is locked |
| `requiredIf` | Required only when true | "Company name" required only if "Are you a business?" is yes |
| `resetValueIf` | Clear the answer when true | An answer that has been invalidated by a later change |
| `setValueIf` / `setValueExpression` | Set the answer when true | Deriving one field from others without hiding it |
| `clearIfInvisible` | `none` \| `onComplete` \| `onHidden` \| `onHiddenContainer` | Whether a hidden question's answer is kept, and this is a **data** decision, not a display one |

`clearIfInvisible` deserves its own line in the UI rather than being buried in Advanced. A
respondent answers "yes", fills in three follow-ups, changes to "no", and submits: whether those
three answers are in the data depends entirely on this property. The default (`onComplete`) is
usually right; the times it is not are exactly the times a tenant needs to be able to change it.

### 6.2.2 The condition builder

Raw expression text is not an authoring surface for most tenants. The builder produces the
expression; the expression remains the stored truth.

```
Show this question when   ┌ All ▾ ┐  of the following match:

  ┌───────────────────┬────────────┬──────────────────┬───┐
  │ Are you a business ▾│ equals    ▾│ Yes            ▾ │ ✕ │
  ├───────────────────┼────────────┼──────────────────┼───┤
  │ Number of staff    ▾│ greater than│ 10             │ ✕ │
  └───────────────────┴────────────┴──────────────────┴───┘
  + Add condition     + Add group

  Result:  {is_business} = 'yes' and {staff_count} > 10        [ Edit as text ]
```

- **Question dropdown** lists only questions that can precede this one — earlier pages, and
  earlier on the same page. A condition on a later answer never fires, and offering it is how
  authors lose an afternoon.
- **Operators depend on the question type.** A dropdown offers `equals`, `not equals`, `is
  empty`, `is not empty`, `any of`; a number offers comparisons and `between`; a checkbox offers
  `contains`, `does not contain`, `is exactly`; a date offers before/after/between.
- **Value input matches the type** — a choice list for choice questions (showing text, storing
  value), a date picker for dates, and a **"compare to another question"** toggle for
  cross-field conditions.
- **Groups** give `and` / `or` nesting without parenthesis syntax.
- **The generated expression is always visible**, and "Edit as text" switches to raw. Editing
  raw and switching back re-parses; if it cannot, the builder stays in text mode and says why
  rather than silently discarding the expression.

Round-tripping is the hard part and the place to be strict: **the builder must never destroy an
expression it cannot represent.** Parse-fail means read-only text mode, not an empty builder.

### 6.2.3 Operators the runtime supports

`=`, `!=`, `>`, `<`, `>=`, `<=`, `contains`, `notcontains`, `anyof`, `allof`, `empty`,
`notempty`, `and`, `or`, `not`, parentheses, and `{question}` / `{question.property}` /
`{panel.question}` / `{row.column}` references. String literals in single quotes; `true` /
`false`; arrays as `['a','b']`.

Two shapes the builder must offer explicitly because authors reach for them constantly and the
syntax is not guessable:

```
{q1} anyof ['a','b']                     multi-select: any of these
{q1} allof ['a','b']                     multi-select: all of these
{q1} empty                               unanswered
{panel.rate} > 3                         inside a dynamic panel, this panel's value
{row.quantity} > 0                       inside a matrix row, this row's value
```

### 6.2.4 Page and panel logic

`visibleIf` on a **page** is how a form skips a whole section, and it is far cheaper than
putting the same condition on nine questions. On a **panel**, it groups without a page break.

Both are unreachable today because pages and panels cannot be selected at all
([05](05-form-builder.md) §5.4). Panels are also not in the toolbox (**F25**).

### 6.2.5 Seeing the logic

Two views, because a form with thirty conditions is unreadable one properties panel at a time:

- **Badges on the canvas** — a question with logic is marked, hover shows the condition in
  plain language ([05](05-form-builder.md) §5.2).
- **A Logic tab** listing every rule in the form: what it affects, its condition, its type.
  Editable and deletable from there. **This is where an author finds the rule that is hiding a
  question they cannot see**, which is otherwise a hunt through every question in the form.

Plus two checks run on demand and at publish:

| Check | Why |
|---|---|
| Reference to a question that no longer exists | A rename or delete that missed a reference. The rename refactor ([05](05-form-builder.md) §5.5) prevents most of these; import does not |
| Question can never be visible | A condition contradicting itself, or depending on a question that is itself always hidden |
| Circular dependency | A depends on B depends on A |
| Condition on a later question | Never fires |
| Required **and** always hidden | The form cannot be completed — and this is the one that produces "nobody can submit my form" support tickets |

The last row is worth the whole check. `visibleIf` suppresses validation for hidden questions,
so it *is* completable — but if the author intended the question to be reachable, every
submission is missing it.

## 6.3 Question type configuration (F28)

### 6.3.1 `inputType`

Every `text` question is a plain string today. The runtime supports:

`text` · `email` · `tel` · `url` · `number` · `password` · `date` · `datetime-local` · `time` ·
`month` · `week` · `color` · `range`

This is not cosmetic. `inputType` changes the mobile keyboard, enables native pickers, brings
browser validation, and — with `min` / `max` / `step` — bounds the value before any validator
runs. A phone-number question that raises the alphabetic keyboard is a lower completion rate.

### 6.3.2 Bounds

`min`, `max`, `step` for numbers and dates; `minValueExpression` / `maxValueExpression`
*verify* for bounds relative to another answer — "not before the start date" without writing a
validator.

### 6.3.3 Text length

`maxLength` on `text` and `comment`, plus a length validator when a minimum is wanted.

### 6.3.4 Masks

*Verify against 2.5.20.* Input masking (`maskType`: `pattern` / `numeric` / `currency` /
`datetime`, with `maskSettings`) arrived in the 1.9.13x line. Where available, it is the right
answer for phone numbers, postcodes, card-like references and currency — the display formatting
and the stored value stay separate (`saveMaskedValue`), which regex validation cannot do.

Where unavailable in the bundled build, a regex validator plus a placeholder is the fallback,
and the editor should not offer a mask control that silently does nothing.

## 6.4 Validation (F27)

`isRequired` is the entire validation surface today. The runtime has six validators.

| Validator | Configures | Example |
|---|---|---|
| `numeric` | `minValue`, `maxValue` | Age between 18 and 120 |
| `text` | `minLength`, `maxLength`, `allowDigits` | Reference at least 6 characters |
| `email` | — | A valid address |
| `regex` | `regex` | Postcode, VAT number, employee id |
| `expression` | `expression` | `{end_date} >= {start_date}` |
| `answercount` | `minCount`, `maxCount` | Choose 2 to 4 options |

Each carries a `text` — the message shown on failure. **The editor requires it**, because
"Value is invalid" tells a respondent nothing about a postcode pattern, and the default is what
gets shipped when the field is optional.

### 6.4.1 The validators editor

A list on the Validation tab: add, reorder, remove, each row a type plus its parameters plus
its message. Validators are additive and all run.

For `regex`, the editor provides a **test box** — type a sample, see pass or fail live — and a
library of common patterns (email, UK/US postcode, phone, URL, alphanumeric, digits-only) as a
starting point. An untested regex in a live form is a form that rejects valid answers, and the
tenant hears about it from respondents rather than from the product.

For `expression`, the same expression editor as §6.2, with the whole survey's questions in
scope. This is the cross-field validator — end after start, total not over budget, at least one
of three contact methods — and it is the one that has no other expression.

### 6.4.2 When validation runs

Survey-level, on the Global settings ([07](07-theming-and-layout.md) §7.2):

- `checkErrorsMode` — `onNextPage` (default) · `onValueChanged` · `onComplete` ·
  `onValueChanging`
- `textUpdateMode` — `onBlur` (default) · `onTyping`
- `questionErrorLocation` — `top` · `bottom`

`onValueChanged` gives immediate feedback and is usually better for long forms; `onComplete`
suits a short form where interrupting is worse. It is a real choice and it should be exposed
rather than defaulted forever.

### 6.4.3 Async and server validation

`onServerValidateQuestions` lets the runtime call an endpoint mid-form — "is this promo code
valid", "is this email already registered". The editor exposes it as a **per-question remote
check**: URL, the questions to send, and the message on failure. The request is proxied through
the portal ([12](12-externals-sdk.md) §12.5) rather than issued to a third party from the
respondent's browser, so the endpoint need not be public or CORS-enabled and the tenant's
credential never reaches the page.

## 6.5 Triggers (F46)

Survey-level `triggers[]` — actions fired when a condition becomes true. Not reachable today,
and not derivable from anything else.

| Trigger | `type` | Does |
|---|---|---|
| Complete | `complete` | Ends the survey early. Screening: "not eligible" → finish |
| Set value | `setvalue` | Writes a value into another question |
| Copy value | `copyvalue` | Copies one answer to another — "same as billing address" |
| Skip to | `skip` | Jumps to a named question or page |
| Run expression | `runexpression` | Evaluates an expression, optionally storing the result |

The editor presents these as **rules**, in the same Logic tab as §6.2.5, because to an author
they are the same concept: *when this, then that.* Splitting "conditional visibility" from
"triggers" is an implementation detail of the runtime, not a distinction a tenant should have to
learn.

```
When   {age} < 18
Then   Complete the survey  and show  "Sorry, this survey is for adults only."
```

`skip` needs a warning in the UI: it moves the respondent but does **not** clear the answers it
skipped past, and a skip that jumps backwards can loop. The editor detects a backward skip whose
condition its own target can re-satisfy, and says so.

## 6.6 Expressions and computed values

Three mechanisms, easy to confuse, each with a distinct job:

| Mechanism | Where | Stored in data? | Visible? |
|---|---|---|---|
| `defaultValueExpression` | on a question | yes, as that question's answer | yes, editable |
| `expression` question | a question type | only if `includeIntoResult`-style config *verify* | yes, read-only |
| `calculatedValues[]` | survey level | only if `includeIntoResult: true` | no |

### 6.6.1 `defaultValueExpression`

Pre-fills an answer from other answers, and **recomputes when they change** until the respondent
edits it. "Total attendees" defaulting to `{adults} + {children}`, still overridable.

### 6.6.2 The `expression` question

A displayed, non-editable computed value: order totals, scores, derived dates, a summary line.
`displayStyle` (`decimal` / `currency` / `percent` / `date`), `currency` and `format` control
presentation — `format: "Total: {0}"` wraps the value in text.

### 6.6.3 `calculatedValues`

Named intermediate values, survey level:

```json
"calculatedValues": [
  { "name": "subtotal", "expression": "{qty} * {unit_price}", "includeIntoResult": true },
  { "name": "vat",      "expression": "{subtotal} * 0.2",     "includeIntoResult": true }
]
```

Referenced by conditions, validators, other expressions and piping, exactly like a question.
`includeIntoResult` decides whether they appear in the submission — and they usually should,
because a total that is displayed to a respondent and absent from the export is the kind of
discrepancy that surfaces during a dispute.

The editor gives them a **Calculated values** panel in the Logic tab: name, expression, include
flag, and a live evaluation against the preview's current data.

### 6.6.4 Built-in functions

`iif()` · `age()` · `today()` · `currentDate()` · `getDate()` · `sum()` · `avg()` · `min()` ·
`max()` · `count()` · `sumInArray()` · `avgInArray()` · `minInArray()` · `maxInArray()` ·
`countInArray()` · `displayValue()` · `propertyValue()`

The `*InArray` family is what makes dynamic panels and matrices computable —
`sumInArray('line_items', 'amount')` totals a repeating panel — and without them a
`matrixdynamic` is a data-entry grid nobody can add up.

The expression editor offers these with signatures and one-line descriptions, and validates
arity before saving. Custom functions (`FunctionFactory.Instance.register`) are **not** exposed
to tenants: they are code, they run in every respondent's browser, and a form builder that
accepts arbitrary JavaScript from a tenant is a different security review.

### 6.6.5 The expression editor

Shared by conditions, expression questions, calculated values, validators and triggers:

- syntax highlighting for `{tokens}`, operators and functions;
- **autocomplete on `{`**, listing every question name, calculated value and panel/row variable
  in scope — which is also what makes readable question names ([05](05-form-builder.md) §5.5)
  pay off;
- live validation with the parse error positioned;
- **live evaluation** against the preview's current data, so the author sees the result rather
  than guessing;
- a reference of operators and functions, one keystroke away.

## 6.7 Piping (F29)

`{question_name}` inside any text — titles, descriptions, HTML blocks, `completedHtml`, choice
texts, error messages — substitutes the answer:

```
"Thanks {first_name}, which of these applies to your {business_type} business?"
```

Variants that matter:

| Token | Gives |
|---|---|
| `{question}` | The value |
| `{question.displayValue}` *verify* | The **text** of a selected choice, not its stored value |
| `{panel.question}` | Within a dynamic panel, this panel's value |
| `{row.column}` | Within a matrix row, this row's value |
| `{calculated_value}` | A calculated value |

`displayValue` is the one authors need and do not know exists: piping a `radiogroup` raw gives
`us` where the author meant `United States`. `useDisplayValuesInDynamicTexts` (survey-level,
default true) governs the global behaviour; the per-token form is the override.

The editor supports piping with an **insert-token** control on every localizable text field
rather than expecting authors to type braces, and the canvas shows piped titles resolved against
sample data so `{first_name}` does not appear literally in the design view.

## 6.8 Quiz mode

The runtime supports scoring, and it costs little on top of §6.2–§6.6.

- `correctAnswer` per question.
- `completedHtml` with `{correctedAnswers}`, `{questionCount}`, `{correctAnswerCount}` *verify
  exact token names in 2.5.20*.
- `completedHtmlOnCondition` — a different completion screen by score:

```json
"completedHtmlOnCondition": [
  { "expression": "{correctAnswers} >= 8", "html": "<h3>Passed</h3>" },
  { "expression": "{correctAnswers} <  8", "html": "<h3>Not this time</h3>" }
]
```

- `maxTimeToFinish`, `maxTimeToFinishPage`, `showTimer`, `timerLocation` *verify names* — timed
  assessments.
- `showCorrectAnswer` / review mode where supported.
- Per-choice scores for weighted scoring, via `calculatedValues` over the answers.

Scoring is stored on the submission ([04](04-database-schema.md) §4.4) as a calculated value
with `includeIntoResult: true`, so it is exportable and reportable rather than being a number
the respondent saw once.

## 6.9 Server-side enforcement (F8, D4)

The runtime validates in the browser. `POST /forms/submit` accepts any JSON today. Closing that
is the last piece of "solid", and it is the one place this plan duplicates runtime semantics on
purpose ([03](03-architecture.md) §3.5).

### 6.9.1 The pipeline

```
POST /forms/submit
  1. resolve the PUBLISHED version                      04 §4.3
  2. reject unknown question names                      (extra keys = not from this form)
  3. evaluate calculatedValues needed by 4 and 5
  4. evaluate visibleIf / enableIf, page and panel down
  5. for every VISIBLE question:
       · isRequired / requiredIf satisfied
       · type coercion and range
       · choice membership (value must exist in the version's choices)
       · every validator in validators[]
  6. normalise: trim, canonicalise dates, coerce numbers
  7. write submission + answers                         04 §4.4 §4.5
```

Step 5's *choice membership* check is quietly one of the most valuable: it is what stops a
crafted payload writing `"role": "admin"` into a question whose only choices were
`applicant` and `referee` — a value that then flows into an export, a webhook, or a downstream
system that trusts it.

### 6.9.2 The reduced evaluator

A deliberately small subset of the runtime's grammar:

- comparison: `= != > < >= <=`
- membership: `contains`, `notcontains`, `anyof`, `allof`
- emptiness: `empty`, `notempty`
- boolean: `and`, `or`, `not`, parentheses
- references: `{question}`, `{calculated}`, `{panel.q}`, `{row.col}`
- literals: numbers, single-quoted strings, `true`/`false`, arrays
- functions: `iif`, `age`, `today`, `currentDate`, `getDate`, `sum`, `avg`, `min`, `max`,
  `count`, and the `*InArray` family

Deliberately **not** supported: custom functions, and anything the parser does not recognise.

### 6.9.3 When it cannot parse

The rule that keeps this from becoming a respondent-facing failure:

> **An expression the server cannot evaluate never rejects a submission.** The submission is
> accepted, stored, and marked `validation_state = 'unverified'`
> ([04](04-database-schema.md) §4.4), with the unparsed expression on the submission's event
> log. The admin sees a badge; the respondent sees nothing.

The alternative — failing closed against the respondent — means a parser limitation silently
destroys real responses, and the tenant discovers it from a drop in their numbers. Failing open
with a flag puts the cost on the operator, who can act on it, rather than on the respondent, who
cannot.

**Publish warns** when a form contains an expression the server evaluator does not support, so
the author learns at authoring time rather than at the first flagged submission
([09](09-publishing-and-versioning.md) §9.2).

### 6.9.4 The response

Failures return **422** with per-question messages, in the shape the runtime can display inline:

```json
{ "success": false,
  "code": "VALIDATION_FAILED",
  "errors": { "attendee_email": ["Enter a valid email address."],
              "staff_count":    ["Must be 1 or more."] } }
```

Not a flat message — a respondent whose form comes back saying only "invalid" abandons it.

## 6.10 Dynamic panels and matrices

The types [05](05-form-builder.md) §5.3 adds are also where logic gets its own scope, which is
why they land here as well:

| Concern | Handling |
|---|---|
| Conditions inside a template | `{panel.q}` / `{row.col}` — scoped to the current panel or row |
| Validators inside a template | Per-column and per-template-question `validators[]` |
| Aggregating across rows | `sumInArray()`, `countInArray()` (§6.6.4) |
| Panel/row count driven by an answer | `panelCount` / `rowCount` bound to an expression *verify* |
| Storage | Decomposed into `laravel_form_answers` with `row_key` ([04](04-database-schema.md) §4.5) |
| Export | One row per panel/row in a **long** export, or numbered columns in a wide one ([10](10-submissions-and-data.md) §10.6) |
| Server validation | The evaluator walks each panel/row with its own scope (§6.9) |

Repeating groups are the point at which a form platform stops being a contact form, and the
point at which every downstream concern — validation, storage, export, analytics — needs an
explicit answer rather than an implicit flattening.

## 6.11 What is deliberately not exposed

| Not exposed | Why |
|---|---|
| Custom JS functions (`FunctionFactory`) | Tenant-authored code executing in every respondent's browser |
| `onValueChanged` and other JS event hooks | Same |
| Arbitrary CSS/JS injection into the form | [07](07-theming-and-layout.md) covers theming through variables, which is bounded |
| Direct `survey.data` manipulation from a form | Triggers cover the legitimate cases |

Each of these is an escape hatch that would let a tenant run code in their visitors' browsers
through a form definition stored in our database and served by our API. The raw-JSON editor
([05](05-form-builder.md) §5.4) is bounded by the same rule: it is validated against known
question types and known properties, not passed through.

## 6.12 Acceptance

- A question with `visibleIf: "{q1} = 'yes'"` hides and shows in the preview, and is **not**
  required while hidden.
- The condition builder round-trips: build → text → build produces the identical expression.
- An expression the builder cannot represent opens in text mode and is **not** discarded.
- A question dropdown in the condition builder offers only questions that precede the target.
- A `regex` validator can be tested against a sample inside the editor.
- An `expression` validator comparing two dates rejects end-before-start, client and server.
- `{first_name}` in a title resolves in the preview and in the delivered form.
- `{q.displayValue}` pipes the choice **text**, not its value.
- A `calculatedValue` with `includeIntoResult: true` appears in the submission and the export.
- `sumInArray()` over a dynamic panel totals correctly with 0, 1 and 20 panels.
- A `complete` trigger ends the survey early and records a complete submission.
- A `copyvalue` trigger populates the target and the target is exported.
- A `curl` POST omitting a required visible question is rejected **422** with that question
  named.
- A `curl` POST supplying a choice value that is not in the version's choices is rejected.
- A `curl` POST containing a question name not in the version is rejected.
- A form containing an expression the server cannot parse: the submission is **accepted**,
  marked `unverified`, and flagged in the admin — and publishing it warned the author first.
- The logic checker finds: a reference to a deleted question, a question that can never be
  visible, a circular dependency, and a required-and-always-hidden question.
- The Logic tab lists every rule in the form and each is editable from there.
- `inputType: 'tel'` raises the numeric keypad on a mobile device.
