# 07 — Theming and Layout

Closes **F40** (the theme half), **F46**, **F47**, and the layout half of **F29**.

## 7.1 What exists

More than in any other part of the editor, and it is close to right:

- All **20 bundled themes** enumerated and wired to a dropdown (`SURVEY_THEMES`,
  `FormsEditorController.ts:107–128`).
- A **120-key `DEFAULT_FORM_STYLE`** covering the runtime's CSS-variable surface — colours,
  shadows, borders, corner radius, base unit, and the full article font scale
  (`:131–227`).
- A **Use Custom** toggle and a Styles tab that edits those variables (`buildStylesTab`,
  `:576`).
- `collectFormStyle()` (`:965`) assembling the theme object, and the envelope carrying it
  through to the public renderer, which applies it correctly
  (`external/forms/renderer.ts:60–66`).

Three things are wrong with it.

| # | Problem |
|---|---|
| **F40** | The **canvas ignores it entirely.** `setTheme()` re-renders through the hand-written renderer, which applies no theme. The dropdown appears to do nothing until the author opens the preview modal |
| **F41** | Custom style state persists to a single unkeyed `sessionStorage` entry, so it **leaks between forms** |
| — | The Styles tab is a flat list of 120 CSS variables. That is a data structure, not an interface |

## 7.2 Survey-level settings (F46, F47)

The Global tab has two controls — `completedHtml` and `showPreviewBeforeComplete`
(`buildGlobalTab`, `:486–575`). Everything else the runtime offers at survey level is
unreachable.

These move out of the cramped sidebar tab and into the **properties panel when the survey is
selected** ([05](05-form-builder.md) §5.4), grouped:

### Presentation

| Control | Property |
|---|---|
| Title / description | `title`, `description` (localizable — 08) |
| Logo | `logo`, `logoPosition`, `logoWidth`, `logoHeight`, `logoFit` |
| Width mode | `widthMode` (`auto` / `static` / `responsive`), `width` |
| Question numbering | `showQuestionNumbers` (`on` / `onPage` / `off`), `questionStartIndex` |
| Title layout | `questionTitleLocation`, `questionTitlePattern`, `requiredText` |
| Error placement | `questionErrorLocation` |
| Table of contents | `showTOC`, `tocLocation` |

### Navigation and pagination

| Control | Property |
|---|---|
| Page mode | `questionsOnPageMode` — `standard` / `singlePage` / `inputPerPage` |
| Progress bar | `showProgressBar`, `progressBarType`, `progressBarLocation` *verify names* |
| Navigation buttons | `showNavigationButtons`, `showPrevButton`, `pagePrevText`, `pageNextText`, `completeText` |
| Start page | `firstPageIsStarted`, `startSurveyText` |
| Preview before submit | `showPreviewBeforeComplete` — **see §7.3** |

`questionsOnPageMode: 'inputPerPage'` turns any form into a one-question-at-a-time flow, which
is a materially different product for mobile respondents and costs one dropdown.

### Completion

| Control | Property |
|---|---|
| Completed message | `completedHtml` (sanitised — [05](05-form-builder.md) §5.7) |
| Conditional completion | `completedHtmlOnCondition` ([06](06-logic-and-validation.md) §6.8) |
| Redirect | `navigateToUrl`, `navigateToUrlOnCondition` |
| Show completed page | `showCompletedPage` |
| Already-completed message | `completedBeforeHtml` |
| Loading message | `loadingHtml` |

`navigateToUrl` is the one tenants ask for first — "send them to my thank-you page" — and it is
one property.

### Behaviour

| Control | Property |
|---|---|
| Validation timing | `checkErrorsMode`, `textUpdateMode` ([06](06-logic-and-validation.md) §6.4.2) |
| Hidden-value handling | `clearInvisibleValues` |
| Timer | `maxTimeToFinish`, `maxTimeToFinishPage`, `showTimer`, `timerLocation` *verify* |
| Partial saving | `sendResultOnPageNext` ([10](10-submissions-and-data.md) §10.5) |
| Focus | `focusFirstQuestionAutomatic`, `focusOnFirstError` |
| Read-only | `mode` |

## 7.3 The `showPreviewBeforeComplete` shape (F47)

The Global tab writes the string `'noPreview'` / `'showAllQuestions'` / `'showAnsweredQuestions'`
(`:571`). That is the **v1** shape. In the v2 line the property became a boolean paired with a
separate `previewMode`.

If the bundled 2.5.20 build takes the v2 shape, then **the only survey setting with a working
UI writes a value the runtime ignores** — a control that appears to function and does nothing,
which is worse than an absent control because nobody looks for the bug.

Resolve it before building anything else on this tab:

```bash
grep -o "showPreviewBeforeComplete" public/assets/libs/surveyjs/survey.core.min.js | head
grep -o "previewMode" public/assets/libs/surveyjs/survey.core.min.js | head
```

Then write whichever shape the build reads, and **migrate existing definitions** that carry the
other one ([14](14-rollout-and-testing.md) §14.4). This is the concrete instance of the
version-verification rule in [00](00-overview.md) §0.4, and it is worth treating as the template
for every property marked *verify* in [06](06-logic-and-validation.md).

## 7.4 Themes

### Presets

The 20 bundled themes stay, and gain what the dropdown cannot show:

- **Visual previews** — a swatch strip per theme, not a name. "Layered Dark" means nothing until
  it is seen.
- **Light/dark pairing.** Every bundled theme has both. Present them as *theme* + *mode* rather
  than 20 unrelated names, and offer **"follow the visitor's system preference"** — which
  matters for an embedded form, because a light form on a dark site is jarring and it is
  entirely avoidable.
- **Panelless variants** — `isPanelless` is in the theme object and controls whether questions
  sit in cards. It is a big visual difference hidden in a JSON key.

### The custom editor

Replace the flat variable list with grouped controls that write the same variables:

| Group | Writes |
|---|---|
| **Brand** | `--sjs-primary-backcolor` and its light/dark/forecolor derivations — from **one** colour picker |
| **Accent** | `--sjs-secondary-*`, same derivation |
| **Background** | `--sjs-general-backcolor*`, `--sjs-general-dim-*`, plus `backgroundImage`, `backgroundImageFit`, `backgroundImageAttachment`, `backgroundOpacity` |
| **Text** | `--sjs-general-forecolor*` |
| **Shape** | `--sjs-corner-radius`, `--sjs-base-unit` (the spacing scale), border variables |
| **Depth** | the five `--sjs-shadow-*` variables, as a small preset set |
| **Status** | `--sjs-special-red/green/blue/yellow` and their light/forecolor pairs |
| **Typography** | font family, and the `--sjs-article-font-*` scale as **sizes**, not 40 individual keys |
| **Advanced** | the raw variable list, as it is today — the escape hatch |

The derivation is the point. A tenant picks one brand colour and the editor computes
`-light` (10 % alpha), `-dark` (a darkened stop) and `-forecolor` (black or white by contrast),
because the current UI asks them to pick four related colours by hand and get the relationship
right.

**Contrast checking** on every foreground/background pair, with a WCAG AA warning. A form is a
data-collection instrument; unreadable label text costs completions.

### Fonts

Custom fonts on an embedded form are a real trap: the form renders inside the tenant's page,
under the tenant's CSP, with no control over what the host loads. The editor offers a font-stack
field with a system-font default and warns that a webfont must be loaded by the host page.
`@import` inside injected CSS is not offered ([05](05-form-builder.md) §5.7).

### Theme library

Save a configured theme as a **named tenant theme**, reusable across that tenant's forms, with
"apply to all forms on this site" as an explicit bulk action. A tenant with fifteen forms should
configure brand colours once. Applying to all forms is a change to each form's **draft**, not to
published versions — the same rule as everything else ([09](09-publishing-and-versioning.md)
§9.3).

## 7.5 Theme state (F41)

`persistStyleState()` / `restoreStyleState()` write and read `sessionStorage['formEditorStyleState']`
— one key, no form reference (`:79–100`). Open form A, adjust the palette, open form B, and B
loads A's unsaved styles and saves them on the next save.

Fixed by [05](05-form-builder.md) §5.10: theme state lives on the per-open session, and any
local persistence keys on `formDraft:{reference_num}`.

## 7.6 Per-question layout

The runtime has a layout model and none of it is reachable (**F29**):

| Property | Effect |
|---|---|
| `startWithNewLine` | `false` puts a question **beside** the previous one |
| `width` / `minWidth` / `maxWidth` | Its share of the row |
| `indent` / `titleLocation` | Indentation and per-question title placement |
| `visible` | Static hiding, distinct from `visibleIf` |

`startWithNewLine: false` is the whole multi-column story, and no author will guess it. The
Layout tab presents it as a **column control** — "full width / half / third", with a small
diagram of the row — and writes `startWithNewLine` plus `width` underneath.

`panel` questions with `elements` inside them are the grouping mechanism, so First/Last name in
one row is a panel with two half-width questions. That is why `panel` is in the toolbox
([05](05-form-builder.md) §5.3) — layout is one of its two jobs, the other being scoped
conditions ([06](06-logic-and-validation.md) §6.2.4).

## 7.7 Responsive behaviour

Themes and layout look right on a desktop canvas and can fail on a phone, which is where most
respondents are.

- **Device preview** — mobile / tablet / desktop widths in the preview modal
  ([05](05-form-builder.md) §5.2).
- Multi-column rows **collapse to one column** below a breakpoint; the editor says so where the
  control is set, rather than letting an author ship a three-column row that is unreadable at
  375 px.
- `widthMode: 'responsive'` as the default for new forms.
- Matrices are the recurring mobile problem: a wide `matrixdropdown` is unusable on a phone. The
  editor warns above a column threshold and points at the runtime's mobile matrix rendering.

## 7.8 The embedded context

An embedded form inherits the host page's cascade, and the tenant controls neither.

- The public renderer already scopes everything under the SurveyJS class namespace; keep it and
  **do not** add global selectors.
- Offer an **iframe embed** alongside the inline one ([09](09-publishing-and-versioning.md)
  §9.6). It costs some height-management awkwardness and buys complete style isolation, which is
  the right trade for a tenant whose site CSS is fighting the form.
- The theme travels in the envelope, so a form looks the same wherever it is embedded — which
  is what makes the design work in the editor meaningful.

## 7.9 Acceptance

- Changing the theme changes the **canvas**, immediately, without opening the preview.
- Every one of the 20 bundled themes is selectable with a visual preview.
- Picking one brand colour produces a coherent set of primary variables.
- A foreground/background pair below WCAG AA is warned about.
- Opening form B after theming form A shows B's theme.
- A saved tenant theme applies to a new form in one action.
- `showPreviewBeforeComplete` is written in the shape the bundled build reads, and existing
  definitions carrying the other shape are migrated.
- `navigateToUrl` redirects after completion; a non-`https` absolute URL is refused.
- Two questions can be placed side by side without the author knowing what `startWithNewLine`
  is.
- A two-column row collapses to one column at 375 px, and the editor said it would.
- `questionsOnPageMode: 'inputPerPage'` renders one question per screen in the preview.
- An embedded form renders identically on a host page with aggressive global CSS, using the
  iframe embed.
