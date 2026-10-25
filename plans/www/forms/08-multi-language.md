# 08 — Multi-language

Closes **F45**.

## 8.1 What exists

A toolbox tab titled "Languages" containing one **disabled** checkbox reading "English —
default" (`FormsEditorController.ts:311–316`), above the text *"Select which languages this form
supports."*

Nothing behind it. No `locale`, no localizable strings, no translation surface, no locale
resolution at render, and no locale on a submission. It is a placeholder that reads as a
feature, which is worse than an absent tab: an author who needs a French form will click it,
read that sentence, and conclude the product supports something it does not.

The runtime, meanwhile, has full localization built in and loaded.

## 8.2 How the runtime does it

Any string property becomes an object keyed by locale, with `default` as the fallback:

```json
{
  "type": "text",
  "name": "attendee_email",
  "title": { "default": "Your email address", "fr": "Votre adresse e-mail", "es": "Tu correo" },
  "placeholder": { "default": "name@example.com" },
  "isRequired": true,
  "requiredErrorText": { "default": "Email is required", "fr": "L'e-mail est obligatoire" }
}
```

Setting `survey.locale = 'fr'` re-renders every localizable string in French, falling back to
`default` per string — **not** per form. A partly-translated form is a coherent thing: it shows
French where French exists and the default elsewhere, rather than switching wholesale.

`surveyLocalization` supplies the runtime's own UI strings — "Next", "Previous", "Complete",
built-in validation messages — for the locales the build ships. Those come free; only the
author's own strings need translating.

## 8.3 What is localizable

| Level | Properties |
|---|---|
| Survey | `title`, `description`, `completedHtml`, `completedBeforeHtml`, `loadingHtml`, `pagePrevText`, `pageNextText`, `completeText`, `startSurveyText`, `questionTitlePattern`, `requiredText` |
| Page | `title`, `description`, `navigationTitle`, `navigationDescription` |
| Panel | `title`, `description` |
| Question | `title`, `description`, `placeholder`, `requiredErrorText`, `commentText`, `otherText`, `otherPlaceholder`, `noneText`, `selectAllText`, `labelTrue`, `labelFalse`, `minRateDescription`, `maxRateDescription`, `addRowText`, `removeRowText`, `panelAddText`, `panelRemoveText`, `templateTitle`, `html` (for `html` questions) |
| Choice | `text` — **not `value`** |
| Validator | `text` |
| Matrix | row and column `text` |

The choice rule is the one to be firm about: **the `value` never translates.** A choice's stored
value is a key, identical across locales, or the same answer arrives in the database as three
different strings depending on which language the respondent used — and no export, filter or
count works across them afterwards. The choices editor ([05](05-form-builder.md) §5.6) already
separates text from value, which is what makes this straightforward.

## 8.4 The translation surface

### The Languages tab, made real

```
Languages

  ● English (en)        default        100 %   ⋯
  ○ French (fr)                         62 %   ⋯   [ Set default ] [ Remove ]
  ○ Spanish (es)                         8 %   ⋯

  + Add language ▾

  ☐ Detect the visitor's language automatically
  ☐ Show a language switcher on the form
```

- Adding a language adds it to the form's locale list and to the translation grid.
- The percentage is translated strings over total localizable strings — the number an author
  needs before publishing.
- Removing a language warns and names how many translated strings will be discarded.
- The default locale is the fallback for every untranslated string.

### The translation grid

The tab is for managing languages; the **grid** is for doing the work. A full-width view, not a
sidebar:

| Location | Key | English (default) | French |
|---|---|---|---|
| Survey | title | Event registration | Inscription à l'événement |
| Page 1 | title | About you | À propos de vous |
| Page 1 › attendee_email | title | Your email address | Votre adresse e-mail |
| Page 1 › attendee_email | requiredErrorText | Email is required | *(empty)* |
| Page 1 › diet › choice `veg` | text | Vegetarian | Végétarien |

- Filter to untranslated only — the default view, because that is the work.
- Search across both columns.
- Two locales side by side; a locale selector to change the right-hand column.
- Inline editing, keyboard-navigable down a column.
- **Import / export CSV or XLIFF**, so a professional translator can work outside the product
  and the result can be reimported. Without this, translating a 60-question form means 60
  round-trips through a web UI, and tenants will not do it.
- Piping tokens (`{first_name}`) highlighted and **protected** — a warning when a translated
  string has lost a token the source has, because a `{first_name}` translated to
  `{prénom}` renders as literal text and the author will not notice.

The grid is also where the locale badge on the canvas ([05](05-form-builder.md) §5.2) links to.

### Machine translation

A "translate all untranslated" action against a configured provider, with **every machine
translation marked as such** and listed for review. Useful for a first pass; never silently
presented as finished. If no provider is configured, the action is absent rather than disabled.

## 8.5 Storage

Localized strings live inside `form_data` — they are part of the SurveyJS definition and the
runtime reads them directly. No parallel translation table.

Around it:

| Where | Field | Purpose |
|---|---|---|
| `laravel_forms` | `default_locale` | The fallback ([04](04-database-schema.md) §4.2) |
| `laravel_form_versions` | `locales` JSON | Which locales this version was published with |
| `laravel_form_submissions` | `locale` | Which locale the respondent used |
| `laravel_form_partials` | `locale` | Resume in the same language |

`versions.locales` is denormalised from the definition so the public projection can advertise
available languages without parsing the whole envelope, and so the admin can show "published
with en, fr" per version.

**`submissions.locale` matters more than it looks.** Two respondents choosing the same choice
value in different languages must aggregate together (§8.3), and an operator reading a
submission needs to see the labels the respondent actually saw. Both need the locale recorded.

## 8.6 Locale resolution

Order, first match wins:

1. **Explicit** — `Portal.forms.initAndRender(el, ref, { locale: 'fr' })`, or `?lang=fr`
   ([12](12-externals-sdk.md) §12.3).
2. **Switcher** — the respondent's choice, if the form shows one.
3. **Auto-detect** — `navigator.language`, matched against the form's locales, if enabled.
4. **Default** — `default_locale`.

Matching is by primary subtag: `fr-CA` matches `fr`. Exact matches win over primary matches.

Switching locale mid-form **preserves the answers** — values are locale-independent (§8.3) — and
re-renders the labels. That only works because choice values do not translate, which is the
practical payoff of the rule.

## 8.7 The editor and the preview

- A **locale selector in the editor header**. Selecting French shows the canvas in French, and
  editing a title there writes the French string, not the default. The current locale is always
  visible, because editing in the wrong locale is otherwise silent and destroys the default
  string.
- The preview's locale switcher ([05](05-form-builder.md) §5.2) tests the switch itself.
- Untranslated strings are **badged** on the canvas, not silently shown in the default language
  — the fallback is correct at runtime and misleading at authoring time.

## 8.8 RTL

Arabic and Hebrew need `dir="rtl"`, which the runtime supports.

- A locale marked RTL sets the direction on the form root.
- The theme's spacing and alignment variables mirror ([07](07-theming-and-layout.md)).
- The preview honours it, so an author can see the result.
- **The editor chrome itself stays LTR.** Mirroring the whole admin is a separate project, and
  authoring an RTL form in an LTR editor is normal and workable.

## 8.9 What is deliberately not built

| Not building | Why |
|---|---|
| Per-locale form **definitions** (different questions per language) | One definition, translated strings. Different questions per locale is a different form, and should be one |
| Translation memory / glossary | Belongs in a translation tool; CSV/XLIFF export is the seam to one |
| Locale-specific themes | Beyond RTL mirroring, one design |
| Localized validation *rules* (as opposed to messages) | A regex that differs by locale is a different question; use `visibleIf` on the locale instead |

## 8.10 Acceptance

- The Languages tab manages real languages, and the disabled placeholder checkbox is gone.
- Adding French and translating a title renders that title in French when `locale = 'fr'`.
- An untranslated string falls back to the default **per string**, not per form.
- A choice's `value` is byte-identical across every locale; only `text` differs.
- Two submissions in different languages selecting the same choice aggregate as one value.
- The translation grid filters to untranslated and shows an accurate completion percentage.
- CSV export → external edit → import round-trips without loss.
- A translated string that has lost a `{token}` present in the source is flagged.
- Machine translations are marked and listed for review.
- Switching locale mid-form preserves entered answers.
- `?lang=fr` on the embed renders French; `fr-CA` matches `fr`.
- The submission records which locale was used.
- An RTL locale renders right-to-left in the preview and on the delivered form.
