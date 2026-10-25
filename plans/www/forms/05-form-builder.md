# 05 — The Form Builder

`resources/js/portal/admin/controllers/forms/FormsEditorController.ts` — 2,238 lines today.
Closes **F25**, **F29**–**F44**, **F48**–**F50**.

This document covers the editor's *structure*: the canvas, the toolbox, the properties panel,
naming, selection, ordering and session lifecycle. The **logic, validation and expression**
surface — the reason a tenant reaches for a form builder at all — is
[06](06-logic-and-validation.md). Themes are [07](07-theming-and-layout.md); translations are
[08](08-multi-language.md).

## 5.1 What the editor is today

Three panes and four toolbox tabs, all of which are the right shape:

```
┌────────────┬────────────────────────────────┬─────────────┐
│ Toolbox    │ Canvas                          │ Properties  │
│ ○ Global   │  theme select · Use Custom      │             │
│ ○ Pages    │  page tabs                      │ title       │
│ ○ Languages│  hand-drawn question cards      │ required    │
│ ○ Styles   │                                 │ choices     │
└────────────┴────────────────────────────────┴─────────────┘
```

The layout is not the problem. The problem is that **the canvas draws its own approximation of
each question** (**F40**), the properties panel has three controls (**F29**), two of the four
toolbox tabs are placeholders (**F45**, **F46**), and the whole thing is module-level singleton
state (**F41**).

## 5.2 The canvas — the change everything else waits on

`renderSurveyPreview()` unconditionally calls `renderSurveyPreviewFallback()` →
`renderQuestion()` (`:1753–1988`). That renderer:

- must be taught every question type by hand — 12 today, and the ones worth adding next
  (`panel`, `paneldynamic`, `matrixdropdown`) are the ones it cannot approximate;
- **applies no theme**, so the Theme dropdown demonstrably does nothing on the canvas
  (`setTheme()` at `:1994` re-renders through the same path);
- cannot show layout (`startWithNewLine`, `width`), conditional visibility, piped titles, or
  computed values — everything [06](06-logic-and-validation.md) and
  [07](07-theming-and-layout.md) add;
- is a second implementation of the respondent's view, guaranteed to drift from the first.

### The replacement

Mount the **real runtime** with a design-mode wrapper:

```
SurveyUI.renderSurvey(designModel, canvasMount)

designModel = new Survey.Model(draft.form_data)
  · mode                      = 'display'      ← inputs inert, layout real
  · applyTheme(activeTheme)                    ← the canvas finally honours the theme
  · showInvisibleElements     = true           ← conditionally hidden questions stay visible,
                                                 badged "hidden by logic"  (06 §6.2)
  · onAfterRenderQuestion     → attach the design overlay
  · onAfterRenderPage         → attach drop zones
```

The overlay is ours; the question is the runtime's:

| Overlay element | Behaviour |
|---|---|
| Selection outline | Click selects; `Tab` / arrow keys move between questions |
| Drag handle | Reorder within a page, and **across page tabs** (**F38**) |
| Inline actions | Duplicate · Delete · Move to page · Convert type (**F39**) |
| Logic badge | Shown when the question has `visibleIf` / `enableIf` / `requiredIf` (06 §6.2) |
| Validation badge | Shown when `validators[]` is non-empty (06 §6.4) |
| Name chip | The question `name`, always visible, click to rename (§5.5) |
| Locale badge | Shown when the string is untranslated in the active locale (08 §8.6) |

`mode: 'display'` is what makes the canvas safe to click on: the runtime lays the question out
exactly as a respondent will see it, but typing into it does nothing, so a click is
unambiguously a selection.

**The name chip is deliberately always visible.** A question's name is the key in every export,
expression and piping token ([03](03-architecture.md) §3.3), and an editor that hides it
produces forms whose data nobody can read. It is currently not merely hidden but disabled
(**F30**).

### Preview stays separate, and gets modes

`renderActualSurvey()` (`:2005`) already mounts the real runtime correctly. It keeps its job —
a **live, interactive** preview where logic actually branches and validation actually fires —
and gains:

- device widths (mobile / tablet / desktop) driving `widthMode`;
- a **locale switcher** ([08](08-multi-language.md) §8.7);
- a **test-data panel** that shows the `data` object as the tester fills it in, which is how an
  author debugs a `visibleIf` that will not trigger;
- "reset preview" without leaving.

And `togglePreviewMode()`'s hand-rolled modal (**F50**) is replaced with the shared
`showModal`/`hideModal` the same file already imports at `:73`.

### Fallback

`renderSurveyPreviewFallback()` and `renderQuestion()` are **deleted**, not kept as a fallback.
A fallback renderer that is only reached when the runtime failed to load is a second
implementation maintained forever for a case where the page is broken anyway. If the globals
are missing, the canvas says so and links to the diagnostic — one message beats 180 lines of
divergence.

## 5.3 The toolbox — full type coverage (F25)

12 types today. The bundled v2.5.20 runtime has roughly twice that. Grouped, so a toolbox of 24
is navigable:

| Group | Types |
|---|---|
| **Text** | `text` (with `inputType`: text, email, tel, url, number, date, datetime-local, time, month, week, password, color, range), `comment`, `multipletext` |
| **Choice** | `radiogroup`, `checkbox`, `dropdown`, **`tagbox`**, **`boolean`**, **`imagepicker`** |
| **Scale** | `rating` (stars / smileys / labels), `ranking`, `nouislider` *if bundled — verify* |
| **Matrix** | `matrix` (single select), **`matrixdropdown`**, **`matrixdynamic`** |
| **Layout** | `html`, **`image`**, **`panel`**, **`paneldynamic`** |
| **Special** | `file`, `signaturepad`, **`expression`** |

Bold = not reachable today.

Three of these change what can be built rather than adding a variant:

- **`paneldynamic`** — repeating groups. "Add another attendee", "list your previous
  employers". The single most requested capability a form builder without it cannot serve.
- **`matrixdynamic`** — an add-a-row grid. Line items, schedules, quantities.
- **`expression`** — a computed, displayed, non-input value. Totals, scores, derived dates. It
  is the visible half of [06](06-logic-and-validation.md) §6.6.

### Toolbox presets

Alongside raw types, a small set of **preconfigured questions** — a type plus properties that
would otherwise take five minutes to set by hand:

| Preset | Expands to |
|---|---|
| Email | `text` · `inputType: email` · email validator · named `email` |
| Phone | `text` · `inputType: tel` · pattern validator · mask |
| Full name | `multipletext` with First / Last items |
| Address | `panel` containing line 1, line 2, city, region, postcode, country dropdown |
| Yes / No | `boolean` |
| Date range | `panel` with two dates and a validator asserting end ≥ start |
| Rating 1–10 | `rating` · `rateMax: 10` · end labels |
| Consent | `checkbox` · single choice · required · `html` above it |
| File upload | `file` · `acceptedTypes` · `maxSize` · `allowMultiple: false` |
| Signature | `signaturepad` · sized · required |

Presets are plain JSON in one module, not code. Adding one is a data change, and a tenant's
"save as preset" ([13](13-admin-dashboard.md) §13.4) writes the same shape.

### Adding a question (F31, F37)

Two defects, one control.

**Click must work.** Today `btn.addEventListener('click', e => e.preventDefault())` (`:474`)
cancels and adds nothing, leaving drag as the only path — and HTML5 drag does not fire on
touch, so **a form cannot be built on a tablet at all**. Click inserts after the current
selection, or at the end. Drag stays, with a keyboard equivalent (`Enter` on a focused toolbox
item).

**Names must not collide.** `question${page.questions.length + 1}` (`:1183–1184`) produces
`question3` on a page that already has one, and SurveyJS keys on name — two questions, one key,
silent data loss ([03](03-architecture.md) §3.3). Replace with a generator that checks the
**whole survey**, not the page:

```ts
function uniqueQuestionName(model, base = 'question') {
    const taken = new Set(model.getAllQuestions().map(q => q.name));
    let n = 1;
    while (taken.has(`${base}${n}`)) n++;
    return `${base}${n}`;
}
```

And a `validateNames()` pass run before every save that refuses to write a definition
containing a duplicate — because the collision may have arrived through an import, a
duplicate-question action, or a manual rename, not only through `addQuestion()`.

## 5.4 The properties panel (F29)

Three controls today: title, required, choices. The target is a tabbed panel whose contents
depend on the selected type — every control writing a property the v2.5.20 runtime actually
reads ([00](00-overview.md) §0.4).

```
Properties ▸ [ General | Logic | Validation | Layout | Data | Advanced ]
```

### General — every question

| Control | Property |
|---|---|
| Title | `title` (localizable — 08) |
| Description | `description`, `descriptionLocation` |
| Name | `name` — **editable**, §5.5 |
| Required | `isRequired`, `requiredErrorText` |
| Read only | `readOnly` |
| Visible | `visible` |
| Title location | `titleLocation` (default / top / bottom / left / hidden) |
| Hide number | `hideNumber` |
| Placeholder | `placeholder` — where the type has one |
| Default value | `defaultValue`, and `defaultValueExpression` (06 §6.6) |

### Per-type General additions

| Type | Controls |
|---|---|
| `text` | `inputType`, `min`, `max`, `step`, `maxLength`, `size`, `autocomplete`, **input mask** (`maskType` + `maskSettings` — *verify availability in 2.5.20*) |
| `comment` | `rows`, `maxLength`, `autoGrow`, `allowResize` |
| choice types | the **choices editor** (§5.6), `choicesOrder`, `showOtherItem` + `otherText` + `otherPlaceholder`, `showNoneItem`, `showSelectAllItem`, `separateSpecialChoices`, `colCount` |
| `dropdown` / `tagbox` | `choicesByUrl` (§5.6.3), `searchEnabled`, `allowClear`, `placeholder`, `closeOnSelect`, tagbox `maxSelectedChoices` |
| `checkbox` | `maxSelectedChoices`, `minSelectedChoices` |
| `rating` | `rateType` (labels / stars / smileys), `rateMin`, `rateMax`, `rateStep`, `rateValues`, `minRateDescription`, `maxRateDescription`, `displayRateDescriptionsAsExtremeItems` |
| `ranking` | `selectToRankEnabled`, `selectToRankAreasLayout` |
| `matrix` | rows, columns, `isAllRowRequired`, `rowsOrder`, `columnsOrder`, `cellType` (matrixdropdown), `alternateRows` |
| `matrixdropdown` / `matrixdynamic` | column designer (name, title, `cellType`, per-column choices, per-column validators), `rowCount`, `minRowCount`, `maxRowCount`, `addRowText`, `removeRowText`, `confirmDelete` |
| `paneldynamic` | template designer, `panelCount`, `minPanelCount`, `maxPanelCount`, `panelAddText`, `panelRemoveText`, `templateTitle`, `renderMode`, `confirmDelete` |
| `file` | `acceptedTypes`, `maxSize`, `allowMultiple`, `allowImagesPreview`, `storeDataAsText` (**forced false** — §5.8), `waitForUpload`, `sourceType` (file / camera) |
| `signaturepad` | `signatureWidth`, `signatureHeight`, `penColor`, `backgroundColor`, `showPlaceholder`, `signatureAutoScaleEnabled`, `dataFormat` |
| `imagepicker` | image list with values, `multiSelect`, `imageHeight`, `imageWidth`, `showLabel`, `contentMode` |
| `image` / `html` | source, dimensions, content — with the sanitisation rule in §5.7 |
| `expression` | `expression`, `displayStyle`, `currency`, `format` (06 §6.6) |
| `boolean` | `labelTrue`, `labelFalse`, `valueTrue`, `valueFalse`, `renderAs` |

### Logic · Validation · Layout · Data · Advanced

- **Logic** — `visibleIf`, `enableIf`, `requiredIf`, `resetValueIf`, `setValueIf`,
  `clearIfInvisible`. [06](06-logic-and-validation.md) §6.2–§6.3.
- **Validation** — the `validators[]` editor. [06](06-logic-and-validation.md) §6.4.
- **Layout** — `startWithNewLine`, `width`, `minWidth`, `maxWidth`, `indent`, `titleLocation`
  override, and a **column-layout affordance** so two questions can sit side by side without
  the author knowing that "uncheck start with new line" is what does it.
  [07](07-theming-and-layout.md) §7.6.
- **Data** — `valueName` (map two questions to one stored key), `useDisplayValuesInDynamicTexts`,
  `correctAnswer` (quiz — 06 §6.8), `defaultValueExpression`, and **"include in exports"**.
- **Advanced** — raw JSON for this question, editable, validated on blur. The escape hatch for
  any property the panel does not surface, and the honest answer to a runtime with a hundred
  properties and a panel with sixty. It is also how a bug in the panel stops being a blocker.

### Survey- and page-level selection

Selecting the survey title or a page tab puts *its* properties in the same panel — page
`title`, `description`, `visibleIf`, `maxTimeToFinish`, `navigationTitle`; survey-level settings
move out of the cramped Global tab into it ([07](07-theming-and-layout.md) §7.2). Today only a
question can be selected, so page properties are unreachable and survey properties live in a
sidebar tab with two controls (**F46**).

## 5.5 Naming (F30, F31)

Enable the field, and make it safe:

- **Editable**, with a rule: `^[A-Za-z_][A-Za-z0-9_]*$`. No spaces, no dots, no braces —
  because the name appears inside expressions (`{attendee_email} > 0`) and piping tokens, where
  those characters change the parse.
- **Uniqueness checked as you type**, across the whole survey.
- **Rename is a refactor.** Renaming a question rewrites every reference to it: `visibleIf`,
  `enableIf`, `requiredIf`, `resetValueIf`, `setValueIf`, expression validators, triggers,
  calculated values, piping tokens in titles and HTML, `completedHtml`, and
  `choicesFromQuestion`. The dialog lists what will change and how many, then does it in one
  undoable step. A rename that silently breaks three conditions is worse than no rename.
- **Suggest from the title.** Typing "What is your email address?" offers
  `what_is_your_email_address` and `email_address`. Most authors will take the suggestion, which
  is how forms end up with readable exports without anyone thinking about naming.
- **Warn on publish** if any question is still `questionN`
  ([09](09-publishing-and-versioning.md) §9.2). Not a block — some forms genuinely do not care
  — but the last honest moment to fix it, because renaming after responses exist splits the
  data across versions.

## 5.6 The choices editor (F32, F33, F34)

The current control is the module's clearest data-loss bug and it deserves a precise
replacement.

### 5.6.1 What is wrong

`radiogroup` and `dropdown` use a comma-separated textarea (`:1310–1312`), read as
`escapeHtml(c.text || c)` and written as `choices.split(',').map(c => c.trim())` (`:1457`).

- A choice containing a comma is impossible.
- `{value: 'US', text: 'United States'}` is read as `United States` and **written back as the
  string `United States`** — the stored value silently changes from `US` to `United States`, so
  every existing submission's answer stops matching any current choice.
- `if (choices && …)` (`:1456`) means clearing the box keeps the old choices (**F33**).
- `checkbox` gets a different editor entirely (**F34**), with different capabilities.

### 5.6.2 The replacement — one editor, all choice types

A row list, identical for `radiogroup`, `checkbox`, `dropdown`, `tagbox`, `imagepicker` and
matrix rows/columns:

| Column | |
|---|---|
| ⣿ | drag handle |
| **Text** | the displayed label (localizable — 08) |
| **Value** | the stored value. **Editable, and shown by default** |
| ⚙ | per-choice `visibleIf`, `enableIf`, image (imagepicker), score (quiz — 06 §6.8) |
| ✕ | remove |

Rules:

- **Text and value are separate and both preserved.** Round-tripping a choice never alters its
  value. This is the fix for **F32**, and it is non-negotiable because the alternative corrupts
  historical data.
- Leaving Value blank means value = text, written explicitly at save so the JSON is never
  ambiguous.
- **Bulk edit** — a "paste a list" mode, one choice per **line** (not comma), optionally
  `value|text`. This is how an author enters 50 countries, and lines make commas safe.
- Duplicate values are flagged. Two choices with one value is a silent data merge.
- **Changing a value that existing submissions use warns**, names the count, and offers to keep
  the old value as a hidden alias. Choice values are historical keys, and the editor should say
  so at the moment it matters.
- Clearing all choices clears all choices.

### 5.6.3 Dynamic choices

Both already supported by the runtime; neither reachable today:

- **`choicesByUrl`** — `{ url, valueName, titleName, path }`, with `{question}` piping in the
  URL for dependent dropdowns (country → region). The editor validates the URL, fetches a
  sample, and shows the parsed result, because a `path` that does not match the payload
  produces an empty dropdown with no error.
- **`choicesFromQuestion`** (carry-forward) — reuse or invert another question's choices:
  "which of the options you selected is most important?" `choicesFromQuestionMode` covers
  `all` / `selected` / `unselected`. *Verify both property names against the bundled 2.5.20
  build.*

For `choicesByUrl`, the fetch happens in the **respondent's** browser, against a third-party
URL, on a tenant's site. The editor warns that the endpoint must be CORS-enabled and public,
and [12](12-externals-sdk.md) §12.5 covers the server-side proxy alternative for endpoints that
are neither.

## 5.7 Safety inside the editor

| Risk | Rule |
|---|---|
| `q.title` interpolated raw into an attribute (**F35**) | Build the panel with DOM APIs, not `innerHTML` string templates. `escapeHtml` exists at `:102` and was applied once — the pattern, not the call site, is the fix ([02](02-security-and-tenancy.md) §2.11) |
| `html` question content | Author-supplied HTML is rendered to respondents by design. Sanitise on **save** against an allowlist (no `<script>`, no `on*` attributes, no `javascript:` URLs), show the author what was removed, and record the original |
| `completedHtml`, `completedHtmlOnCondition` | Same sanitiser |
| `choicesByUrl` URL | `https` only, no credentials in the URL, warn on a non-tenant origin |
| `navigateToUrl` | Absolute `https` or a same-site relative path |
| Raw-JSON escape hatch (§5.4) | Parsed, schema-checked against known question types, and refused if it introduces a duplicate name |

The `html` question is the one worth being explicit about: it is a **feature** that renders
author HTML, so the answer is not "block it" but "bound it, and tell the author what was
bounded".

## 5.8 File questions

`addQuestion()` currently sets `storeDataAsText: false`, `waitForUpload: true`,
`maxSize: 102400` (`:1218–1222`) — sensible defaults for a question whose upload path is the
legacy endpoint (**F12**).

After [02](02-security-and-tenancy.md) §2.10 and [04](04-database-schema.md) §4.6:

- `storeDataAsText` is **forced `false` and not offered**. `true` embeds the file as base64
  inside `submission_data` — which is exactly how a `TEXT` column gets truncated (**F16**), and
  it defeats file tracking, scanning and purge.
- `maxSize` is editable but clamped to a deployment ceiling, enforced server-side.
- `acceptedTypes` becomes a real control (extension list + MIME), enforced server-side too — a
  client-side accept filter is a convenience, not a control.
- The editor shows the effective limits, including the ceiling, so an author does not configure
  100 MB and discover the truth in production.

## 5.9 Undo, redo, duplicate (F39)

An editor without undo makes authors cautious, and cautious authors do not use the features
this plan is adding.

- **Undo / redo** over the whole model — properties, structure, pages, theme, translations.
  `Ctrl+Z` / `Ctrl+Shift+Z`, and buttons. Implemented as a bounded stack of definition
  snapshots (a form definition is tens of kilobytes; 50 snapshots is affordable and far simpler
  than a command log, which has to be right for every one of sixty properties).
- **Duplicate question** — deep copy with a fresh unique name and " (copy)" appended to the
  title.
- **Duplicate page**, with every question renamed uniquely.
- **Copy / paste** across pages and, via the clipboard as JSON, across forms.
- **Multi-select** (shift-click, marquee) for delete, move-to-page and duplicate.

## 5.10 Session, dirty state and saving (F41–F44)

### The session object

Module-level singletons plus one unkeyed `sessionStorage` entry mean opening form B inherits
form A's unsaved styles (**F41**). Replace with the per-open session from
[03](03-architecture.md) §3.7, created in `initBuilder()` and **explicitly destroyed** on view
teardown — which the lazy view loader must call, and currently does not.

Draft recovery keys on the form: `formDraft:{reference_num}`. `localStorage['surveyJSON']`
(**F42**) is deleted.

### Dirty tracking and autosave

| Behaviour | |
|---|---|
| Dirty flag | Set on any model change; drives the Save button and a "· unsaved" marker in the header |
| Autosave | Draft only, debounced 3 s after the last change, silent, never publishes |
| Local recovery | Every change written to `formDraft:{ref}` in `localStorage`; offered on reopen if newer than the server's copy |
| Unload guard | `beforeunload` when dirty, and an in-app confirm on the module's own back button — which is the more common exit (**F44**) |
| Concurrency | Save sends the version the editor loaded; the server refuses with **409** naming who saved and when. The editor offers "reload theirs" or "overwrite" — no silent last-write-wins ([03](03-architecture.md) §3.8) |

And the artificial `await new Promise(r => setTimeout(r, 800))` labelled *"Simulate network
delay for UX"* (`:1060`) is removed (**F43**). The save loader stays, driven by the actual
request.

## 5.11 If Survey Creator is ever licensed

Worth writing down once so the decision stays open rather than being foreclosed by this work.

Everything in this plan writes **standard SurveyJS JSON**. Nothing is stored in a proprietary
shape; nothing depends on a control the custom editor happens to provide. If the commercial
Survey Creator licence is ever bought:

- the definitions load into it unchanged;
- [06](06-logic-and-validation.md)'s Logic tab, [07](07-theming-and-layout.md)'s theme editor
  and [08](08-multi-language.md)'s translation grid have direct Creator equivalents, and the
  custom versions are retired;
- everything **outside** the definition — versioning, tenancy, submissions, answers, files,
  exports, effects, the admin surfaces — is unaffected, because none of it is an editor
  concern.

That is the practical test of whether the boundary in [03](03-architecture.md) §3.2 is being
respected: if licensing Creator would strand work, the boundary has been crossed somewhere.

## 5.12 Dead code (F49, F50)

- **Delete `imp_forms_main.ts`** — 936 lines, imported by nothing, sharing function names with
  the live editor. It imports `SurveyModel` from the npm `survey-core` package, which is not how
  the runtime is loaded (globals from `portal/admin/index.blade.php:21–24`), so it would not even
  work if it were wired up.
- **One modal mechanism.** `showModal`/`hideModal` is imported at `:73` and used for the reset
  and rename dialogs; `ensurePreviewContainer()`/`togglePreviewMode()` (`:1075–1123`) hand-roll
  the same thing and can leave `modal-open` on `<body>` if the view navigates while open.
- **Split the file.** 2,238 lines in one module, mixing 12 SVG icon strings, a 120-key theme
  default, the canvas, the toolbox, the properties panel and the save path. Target:

```
forms/editor/
  session.ts          FormEditorSession, dirty, undo, autosave      §5.9 §5.10
  canvas.ts           design-mode render + overlay                   §5.2
  toolbox.ts          types, groups, presets                         §5.3
  properties/
    index.ts          tab shell + dispatch
    general.ts        §5.4
    logic.ts          06 §6.2
    validation.ts     06 §6.4
    layout.ts         07 §7.6
    data.ts           §5.4
    advanced.ts       raw JSON
  choices.ts          the shared choices editor                      §5.6
  naming.ts           unique names, rename refactor                  §5.5
  icons.ts            the SVG map
  themeDefaults.ts    DEFAULT_FORM_STYLE                             07
```

Splitting is not cosmetic here: the properties panel is about to grow from three controls to
sixty across six tabs, and that does not fit in a function that also owns the canvas.

## 5.13 Accessibility and input (F37, F48)

- **Click-to-add works** (§5.3) — drag stops being the only path, and the editor works on
  touch.
- **Full keyboard operation**: `Tab` through toolbox and canvas, `Enter` to add or select,
  arrows to move selection, `Ctrl+↑/↓` to reorder, `Delete` to remove, `Ctrl+Z` to undo.
- **Focus is managed** on selection change, so the properties panel is reachable without a
  mouse.
- ARIA roles on the three panes; the canvas is a `listbox` of questions with
  `aria-selected`.
- **Delete `syncPropertiesPanelHeight()`** (`:1551`, canvas height + 500 px) and
  `scrollSelectedQuestionIntoView()`'s absolute-`top` positioning after a 200 ms timer
  (`:1241`). Both are layout by arithmetic and by timer (**F48**); a sticky properties panel in
  a flex column does the job with CSS, at every zoom level and viewport.
- The mobile drawers (`setupMobileMenus`) keep working, and now reveal panels that can actually
  be operated by touch.

## 5.14 Acceptance

- The canvas renders through `SurveyUI`. Changing the theme changes the canvas.
- A `paneldynamic` with a two-question template renders correctly in the canvas without any
  editor code that knows what a `paneldynamic` is.
- Every type in §5.3 can be added by **click** and by **drag**, on desktop and on a touch
  device.
- Adding a question after deleting one never produces a duplicate name; a definition with
  duplicate names cannot be saved.
- Renaming a question used by a `visibleIf` updates that condition, reports what it changed, and
  is undone by one `Ctrl+Z`.
- A choice authored as `{value: 'US', text: 'United States'}` is still `US` after ten
  open-edit-save cycles.
- A choice text containing a comma survives a save.
- Emptying the choices list empties the choices.
- The same choices editor appears for `radiogroup`, `checkbox`, `dropdown`, `tagbox` and matrix
  rows.
- A question title containing `"` and `<img onerror>` displays as text in the properties panel.
- An `html` question containing `<script>` is stripped on save, and the author is told.
- Opening form B after editing form A's theme shows **B's** theme.
- Closing the tab with unsaved changes warns; reopening offers recovery.
- Two admins editing one form: the second save gets a 409 naming the first.
- Saving takes as long as the request, with no artificial delay.
- `imp_forms_main.ts` is gone.
- The editor is fully operable by keyboard, and the properties panel stays in view without any
  JavaScript setting its height.
