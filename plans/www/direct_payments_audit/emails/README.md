# Default email drafts — superseded

> **Superseded 2026-10-17.** The approved templates now live, as complete MJML files with previews, in
> `w/www/resources/js/portal/admin/afsemail/mjml-templates/mjml/defaults/dp/` and `.../html/defaults/dp/`. These drafts
> are kept only as the record of what was approved.

Drafts for [15-default-emails.md](../15-default-emails.md). Nothing here is used by the app yet; when approved they move
to `w/www/resources/mail/direct_payments/`.

| File | What it is |
|---|---|
| `_layout.mjml` | The shared shell: brand-colour top bar, logo (or the brand name when there is no logo), the email body, sign-off, footer |
| `<key>.mjml` | One body per email (14), MJML sections only |
| `defaults.php` | Subjects, inbox preview lines, always-on flags and the variables each email reads |
| `previews/<key>.html` | Each email rendered with sample values (brand "Northside Yoga", plan "Unlimited Monthly") — open in a browser to read them as a customer would |

## Checked

- All 14 compile under MJML strict validation (the project's own `mjml-browser`), with no `${…}` left after the sample
  values are applied.
- No `&nbsp;` anywhere: the sender strips it (`SendEmailBuilder()` removes `&nbsp;` from the HTML).
- The literal text of the body slot appears nowhere but the slot itself, so the renderer cannot fill the wrong place.
- Every attribute is inline (no `mj-attributes` / `mj-class`), so a customised copy opens unchanged in afsemail.

## Values the renderer fills (not set by the tenant)

`dp_brand_name`, `dp_brand_color`, `dp_brand_logo`, `dp_footer_line`, `dp_preview`, `dp_greeting` ("Hi Sam," or
"Hello," when no name is known), `dp_card` ("Visa ending 4242" or "your saved payment method" when unknown). These avoid
the `- NOT SET -` text and empty fragments like "Hi ," or " ending ".

## To change the wording

Edit the fragment, then regenerate the previews — or just tell me what to change.
