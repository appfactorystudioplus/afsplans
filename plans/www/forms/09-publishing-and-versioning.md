# 09 — Publishing and Versioning

Closes **F17**, **F18**, **F19**.

## 9.1 What publish means today

```php
public function publishForm(Request $request, $reference_num)
{
    $form = Form::where('reference_num', $reference_num)->first();
    if (!$form) { return 404; }

    BuilderOutput::updateOrCreate(
        ['typeid' => $reference_num, 'typename' => 'forms'],
        ['typeaction' => 1, 'html' => '', 'name' => $form->name]
    );

    return response()->json(['success' => true, 'reference_num' => $reference_num]);
}
```

One row, `html => ''`, and **nothing in the forms path reads it**. Meanwhile
`GET /forms/{ref}` serves whatever the editor last saved, published or not. So:

- publishing changes nothing about what a respondent sees;
- **saving** does — every keystroke that reaches the draft is live;
- there is no way to work on a form that is collecting responses;
- and there is no record of what a form looked like when any given response was made
  (**F17**).

That last one is the expensive one. Rename a question, drop a choice, delete a page, and every
earlier submission silently loses its meaning. Nothing errors. Nothing is logged. The data is
simply wrong from then on, and there is no way afterwards to tell which submissions predate
which change.

## 9.2 The publish action

Publishing becomes the deliberate act that produces an immutable version
([04](04-database-schema.md) §4.3).

```
publish(form)
  1. validate the draft                          §9.2.1
  2. if checksum == current version's → no-op, tell the author
  3. freeze envelope → laravel_form_versions (version = max + 1)
  4. extract question_index                      04 §4.3.1
  5. extract locales                             08 §8.5
  6. forms.current_version_id = new version
  7. forms.status = published, published_at = now
  8. keep the BuilderOutput row                  §9.8
  9. event: form.published
```

### 9.2.1 Pre-publish validation

Publish is the last moment where a fix is cheap, and the only moment an author is definitely
paying attention. Three severities:

**Blocking:**

| Check | Why |
|---|---|
| Duplicate question names | Silent data loss ([05](05-form-builder.md) §5.5) |
| A logic reference to a question that does not exist | The condition never fires |
| Circular logic dependency | Undefined behaviour |
| Invalid expression syntax | Fails at runtime, in front of a respondent |
| Empty form (no questions) | Nothing to answer |
| A required question that can never be visible | The form cannot be completed as intended ([06](06-logic-and-validation.md) §6.2.5) |

**Warning — publishable, and named:**

| Check | Why |
|---|---|
| Questions still named `questionN` | Every export column is meaningless, and renaming after responses exist splits data across versions |
| An expression the **server** evaluator cannot parse | Submissions will be accepted as `unverified` ([06](06-logic-and-validation.md) §6.9.3) |
| Untranslated strings in a declared locale | Respondents in that locale see fallback text ([08](08-multi-language.md) §8.4) |
| No email question, with mail handlers configured | The confirmation email has no recipient ([11](11-integrations-and-notifications.md) §11.3) |
| A validator with no message | "Value is invalid" is what the respondent will read |
| A file question with no `acceptedTypes` | Anything uploadable |
| Contrast below WCAG AA | ([07](07-theming-and-layout.md) §7.4) |
| No published version yet **and** no mail handler or notification configured | Responses will arrive and nobody will be told |

**Breaking-change warnings — the ones that matter most**, shown only when the form already has
submissions, and each naming the count affected:

| Change | Consequence |
|---|---|
| Question renamed | Answers under the old name still exist under the old version and will not align in a combined export |
| Question deleted | Its answers survive in earlier versions but leave the current schema |
| Choice **value** changed or removed | Historical answers reference a value this version does not define |
| Question type changed | The old answers may not be interpretable as the new type |
| Required added to an existing question | Earlier submissions do not satisfy it — which is fine and worth saying, because it surprises people |

Each links to how many submissions are affected. **Publishing is still allowed** — the tenant
owns their form — but not accidentally.

## 9.3 Draft and published, side by side

| | Draft | Published version |
|---|---|---|
| Where | `laravel_forms.json_data` | `laravel_form_versions.envelope` |
| Mutable | yes, continuously | **never** |
| Who sees it | the editor, and signed preview links (§9.7) | respondents |
| Autosaved | yes ([05](05-form-builder.md) §5.10) | n/a |
| On publish | copied into a new version | created |

The editor header carries the state, always visible:

```
Event Registration        ● Published v3 · 4 unpublished changes     [Preview] [Publish]
```

"4 unpublished changes" comes from diffing the draft against `current_version`, and it is the
line that tells an author their edits are not live — the question the current design cannot
answer at all.

## 9.4 Version history

Per form:

| v | Published | By | Note | Submissions |
|---|---|---|---|---|
| 3 | 12 Aug 2026 14:02 | alice | added dietary question | 128 |
| 2 | 04 Aug 2026 09:11 | alice | fixed the email validator | 1,340 |
| 1 | 28 Jul 2026 16:45 | bob | initial | 87 |

Actions per version: **view** (read-only render), **compare with another version** (a structural
diff — questions added/removed/renamed, properties changed, logic changed), and **restore into
the draft**.

**Restore copies a version into the draft. It does not republish and it does not delete
anything.** Restoring v2 over a draft is an edit like any other, and it still has to be
published. A restore that silently went live would be the one destructive action in the module.

A version referenced by any submission can never be deleted — `ON DELETE RESTRICT`
([04](04-database-schema.md) §4.11). Old versions with no submissions can be pruned; the default
is to keep them, because they are small and their absence is only noticed when it matters.

## 9.5 Status and the response window

`laravel_forms.status` (**F18**) — `draft` · `published` · `closed` · `archived`:

| Status | Public fetch | Accepts submissions | In the admin list |
|---|---|---|---|
| `draft` | 404 | no | yes |
| `published` | 200 (current version) | yes | yes |
| `closed` | 200 **or** 404 — §9.5.1 | **no** | yes |
| `archived` | 404 | no | under "Archived" |

### 9.5.1 Closing

Three ways a form closes, all in `settings.submission`
([04](04-database-schema.md) §4.8):

- **Manually** — the operator closes it.
- **On a date** — `closes_at`, and optionally `opens_at` for a scheduled launch.
- **After N responses** — `close_after_responses`, enforced with the submission write so a race
  cannot overshoot.

A closed form shows its **closed message** rather than 404ing, because a respondent following a
link deserves "registration closed on 14 August" rather than a broken page. That message is
configurable per form and is the one place a closed form is still public. If the tenant prefers
a hard 404, that is the alternative setting.

Submissions to a closed form are rejected **409** with the closed message, and the runtime shows
it — including for a respondent who loaded the form before it closed and submitted after, which
is the case that actually happens.

## 9.6 Sharing and embedding

Publishing should produce something a tenant can immediately use. Today it produces nothing
usable at all.

| Method | Shape |
|---|---|
| **Direct link** | `https://{site}/forms/{slug}` — a hosted page rendering the form standalone, themed, with the tenant's logo |
| **Inline embed** | A `<script>` plus a mount div, using `Portal.forms.initAndRender` ([12](12-externals-sdk.md) §12.2) |
| **Iframe embed** | Full style isolation ([07](07-theming-and-layout.md) §7.8), with height auto-resize via postMessage |
| **Popup / button** | A snippet rendering a button that opens the form in a modal |
| **QR code** | Generated from the direct link — for print, posters, table cards |
| **Prefill link** | `?attendee_email=x@y.com&event=summer` populating named questions (§9.6.1) |

The **share dialog** presents all of these with copy buttons and a live preview, and it is what
turns Publish from a state change into a completed task.

`slug` ([04](04-database-schema.md) §4.2) is unique per site, defaults from the name, and is
editable. `v3k9x2m1p0q` is not a link anyone puts on a poster.

### 9.6.1 Prefill

Query parameters populate questions by name — which is another reason names are a
first-class concern ([05](05-form-builder.md) §5.5).

Two rules:

- **Only questions the form marks prefillable** accept a value. Otherwise a link can set any
  answer, including a hidden one carrying a price or an eligibility flag.
- Prefilled values are **recorded as prefilled** on the submission, so an operator can tell what
  the respondent typed from what the link supplied.

Prefill from a Site Users account ([11](11-integrations-and-notifications.md) §11.8) is the
same mechanism with a trusted source, and does not need the allowlist.

## 9.7 Preview links

Sharing an unpublished form with a colleague, without making it public
([02](02-security-and-tenancy.md) §2.7):

- A signed, expiring URL — `?preview_token=…`, default 7 days, revocable.
- Renders the **draft**.
- Marked as a preview on screen, and **submissions from it are stored with
  `status = 'preview'`** — or discarded, per a setting — so a review session does not pollute
  the response data.

That last point is the one that gets missed: without it, every test submission an author makes
is indistinguishable from a real one, and the tenant's first hundred responses include their own.

## 9.8 `BuilderOutput`

The existing row (`typename = 'forms'`, `typeid = reference_num`) is **kept and updated**, not
removed. Other parts of the ecosystem index on it — `WwwAfsConnectMetaProvider` counts modules
per site through this shape — and this plan does not renegotiate a shared contract as a side
effect of adding versions.

What changes: it is written on publish **and** on unpublish/close, so it reflects the form's
actual state rather than being a one-way flag (`typeaction => 1`, set once, never cleared).

## 9.9 Acceptance

- Editing and saving a published form does **not** change what a respondent sees.
- Publishing creates a version; the version's `envelope` never changes afterwards.
- Publishing an unchanged draft is a no-op and says so.
- A submission records the version it answered, and renders with that version's labels and
  choice texts even after five later publishes.
- The editor header shows the published version and the count of unpublished changes.
- Publishing a form with duplicate question names is **blocked**.
- Publishing a form whose required question can never be visible is **blocked**.
- Publishing a form that renames a question with 1,340 existing submissions **warns and names
  the count**, and is still allowed.
- Restoring v2 into the draft does not change the live form until Publish.
- A version with submissions cannot be deleted.
- A closed form shows its closed message; a submission arriving after close is rejected 409 with
  that message.
- `close_after_responses: 100` yields exactly 100 submissions under concurrent load.
- The share dialog produces a working direct link, inline embed, iframe embed and QR code.
- A prefill link populates only allowlisted questions, and the submission records which values
  were prefilled.
- A preview link renders the draft, expires, and its submissions do not appear in the tenant's
  response counts.
