# 08 — Multi-language (partial — the model is right, the tooling is not built)

**Plan:** `plans/www/forms/08-multi-language.md`
**Repo:** `www`
**Date:** 2026-08-31

> **The hard half is done.** Where locale lives, how it is scoped, and how answers stay
> comparable across languages are all decided and built. What is missing is the tooling a
> translator uses — the grid, the CSV round-trip, the token check, the completion percentage.

## What was built

### Schema

`database/migrations/2026_04_11_000001_create_laravel_forms_tables.php`:

| Column | Table | Meaning |
|---|---|---|
| `default_locale` (`varchar 12`, default `'en'`) | forms | the fallback |
| `locales` (`json`) | form versions | which languages this version offers |
| `locale` (`varchar 12`, nullable) | submissions | the language it was answered in |
| `locale` (`varchar 12`, nullable) | per-answer rows | carried through decomposition |

Cast on the models: `Form::$fillable` has `default_locale`; `FormVersion` casts
`locales` to `array`.

### Why `locales` is on the **version**, not the form

This is the decision the rest of the document depends on and it was made correctly.

A published form's language set is fixed at the moment it was published. Adding French to the
draft must not change what a visitor sees on the page that is live, and a submission's `locale`
must always name a language its own version actually offered — otherwise an exported answer
cannot be attributed to any question text that ever existed.

Putting `locales` on the version makes that structural rather than a rule someone has to
remember, and it is the same reasoning as the builder's per-instance module version
(builder **v1** §2.6 — [legacy](../../builder/v1/README.md); [builder v2](../../builder/v2/README.md) is current): a published
artifact carries the contract that produced it.

### Answers stay comparable across languages

`FormAnswerDecomposer` + `FormQuestionIndexBuilder`
([`03-architecture.complete.md`](03-architecture.complete.md)) decompose to the choice
**value**, not its displayed text. That is what §8.10's *"two submissions in different languages
selecting the same choice aggregate as one value"* requires, and it falls out of the
architecture rather than needing i18n-specific work.

### Runtime and editor

- `FormsPublicController` accepts `locale` on submit (`:72`, `:169`) and returns the version's
  `locales` (`:221`), defaulting to `['en']`.
- `FormsEditorController.ts` (`:526–535`) renders a **Languages tab**: a real checkbox list of
  supported locales, marking the default with a badge.

The Languages tab being real closes §8.10's first criterion — *"the disabled placeholder
checkbox is gone"*.

## Acceptance — §8.10

| Criterion | State |
|---|---|
| The Languages tab manages real languages; the placeholder is gone | ✅ |
| A choice's `value` is byte-identical across locales; only `text` differs | ✅ structural — decomposition keys on value |
| Two submissions in different languages selecting the same choice aggregate as one | ✅ same |
| Adding French and translating a title renders it when `locale = 'fr'` | ⚠️ locale is accepted and stored; **per-string translation storage not verified** |
| An untranslated string falls back to the default **per string**, not per form | ⚠️ unverified — this is the criterion most likely to be wrong |
| The translation grid filters to untranslated and shows a completion percentage | ❌ no grid |
| CSV export → external edit → import round-trips without loss | ❌ not built |
| A translated string that lost a `{token}` present in the source is flagged | ❌ not built |
| Machine translations are marked and listed for review | ❌ not built |
| Switching locale mid-form preserves entered answers | ❌ not verified |

## The one to check before building anything else

**Per-string fallback.** §8.10 asks for fallback *per string, not per form* — meaning a form
with one untranslated label shows that one label in the default language and everything else in
French, rather than dropping the whole form back to English.

Whether SurveyJS's own localisation does this by default, or whether the stored definition
must carry per-string fallbacks, determines the shape of the translation grid and the CSV
format. **Answer it before designing either**, because getting it wrong makes the grid model
the wrong thing and the CSV round-trip lossy in a way that only shows up on a partially
translated form.

## Deferred

| # | Work | § |
|---|---|---|
| 1 | Confirm per-string fallback behaviour — **do this first** | 8.10 |
| 2 | Translation grid: untranslated filter, completion percentage | 8.x |
| 3 | CSV export/import round-trip | 8.x |
| 4 | `{token}` preservation check between source and translation | 8.x |
| 5 | Machine-translation marking and review queue | 8.x |
| 6 | Mid-form locale switch preserving answers | 8.10 |

## Verification

```bash
cd www
grep -n "locale" database/migrations/2026_04_11_000001_create_laravel_forms_tables.php
grep -n "locale" app/Http/Controllers/Externals/Forms/FormsPublicController.php
grep -n "locales" resources/js/portal/admin/controllers/forms/FormsEditorController.ts
php artisan test --filter=Form
```

Read directly on 2026-08-31: the forms migration (`:28`, `:55`, `:77`, `:153`),
`app/Models/Externals/Form.php`, `app/Models/Externals/FormVersion.php`, `FormsPublicController.php`,
`FormsEditorController.ts` (`:526–535`).
