# 07 — Theming & layout (partial)

**Plan:** `plans/www/forms/07-theming-and-layout.md`
**Repo:** `www`
**Date:** 2026-08-31

> **Selection works; the parts that make theming *safe* and *reusable* do not.** A tenant can
> pick a theme and override its CSS. They cannot be warned that their colours are unreadable,
> derive a palette from one brand colour, or reuse a theme on the next form.

## What was built

### Theme state travels in the definition, not a column (§7.5)

There is **no theme column** on any forms table, and that is correct rather than an omission.
The theme is SurveyJS theme JSON stored inside the form definition, which `FormJson` owns
([`03-architecture.complete.md`](03-architecture.complete.md)) — so a theme is versioned and
published by exactly the mechanism that already versions and publishes the form, with no second
lifecycle to keep in step.

`DEFAULT_FORM_STYLE` in `FormsEditorController.ts`:

```ts
{
    themeName: "default",
    colorPalette: "light",
    isPanelless: true,
    backgroundImage: "", backgroundImageFit: "cover",
    backgroundImageAttachment: "scroll", backgroundOpacity: 1,
    cssVariables: { "--sjs-general-backcolor": …, "--sjs-general-forecolor": …, … }
}
```

### Selection and override (§7.4)

- A named theme list, including `ContrastLight` / `ContrastDark`.
- A theme dropdown wired in the editor (`:382`).
- **Use Custom** — *"Custom styles that override the selected theme"* (`:334`).
- `applyTheme(theme)` on the renderer (`resources/js/external/forms/renderer.ts`), so the
  published form renders the theme the editor produced.

## Acceptance — §7.9

| Criterion | State |
|---|---|
| Opening form B after theming form A shows B's theme | ✅ theme lives in the definition (§7.5) |
| Changing the theme changes the canvas immediately | ⚠️ dropdown is wired; "without opening the preview" unverified |
| Every bundled theme selectable **with a visual preview** | ⚠️ selectable by name; no visual preview |
| One brand colour produces a coherent set of primary variables | ❌ `cssVariables` are edited individually |
| A foreground/background pair below WCAG AA is warned about | ❌ **no contrast check** |
| A saved tenant theme applies to a new form in one action | ❌ no tenant-level saved theme |
| `showPreviewBeforeComplete` written in the shape the bundled build reads, old shapes migrated | ❌ not verified; §7.3 exists because the two shapes disagree |
| `navigateToUrl` redirects; a non-`https` absolute URL is refused | ❌ not verified |
| Two questions side by side without knowing what `startWithNewLine` is | ❌ §7.6 per-question layout not built |
| A two-column row collapses at 375 px, and the editor said it would | ❌ §7.7 not built |

**Two pass, two partial, six do not.**

## What is worth doing first, and why

**The WCAG AA contrast warning.** Every other item is convenience; this one is the difference
between a tenant publishing a form and a tenant publishing a form nobody can read. The theme
editor hands them raw `cssVariables` with no feedback, which makes an unreadable result the
easy outcome rather than an unlikely one.

**Then `showPreviewBeforeComplete` (§7.3).** It is the only item here that can silently
*break* an existing form: two shapes exist, the bundled build reads one, and definitions
carrying the other are not migrated. Unlike the rest, it degrades something already published.

## Deferred

| # | Work | §|
|---|---|---|
| 1 | WCAG AA contrast warning on the foreground/background pair | 7.4 |
| 2 | `showPreviewBeforeComplete` shape reconciliation **plus a migration** | 7.3 |
| 3 | Brand-colour → palette derivation | 7.4 |
| 4 | Visual theme previews | 7.4 |
| 5 | Tenant-level saved themes | 7.4 |
| 6 | Per-question layout — side-by-side without `startWithNewLine` | 7.6 |
| 7 | Responsive preview and the 375 px collapse | 7.7 |
| 8 | `navigateToUrl` with an https-only guard | 7.2 |

## Verification

```bash
cd www
grep -n "themeName\|applyTheme\|DEFAULT_FORM_STYLE" resources/js/portal/admin/controllers/forms/FormsEditorController.ts
grep -rn "theme" database/migrations/2026_04_11_000001_create_laravel_forms_tables.php   # empty — by design
```

Read directly on 2026-08-31: `FormsEditorController.ts` (`:155–175`, `:334`, `:382`),
`resources/js/external/forms/renderer.ts`, the forms migration.
