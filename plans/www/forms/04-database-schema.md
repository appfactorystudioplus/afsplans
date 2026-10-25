# 04 — Database Schema

Closes **F15**–**F24**. Ships as **P1**, immediately after the security gate, and before any
feature writes richer definitions into columns that cannot hold them.

## 4.1 The shape

Two tables become seven. Five are new; two are altered.

```
laravel_forms                  the form and its draft            (altered)
laravel_form_versions          immutable published snapshots     (new)
laravel_form_submissions       the response document             (altered)
laravel_form_answers           one row per answered question     (new)
laravel_form_files             uploaded files, tracked           (new)
laravel_form_partials          resumable in-progress responses   (new)
laravel_form_events            what happened to a submission     (new)
```

The split that carries the plan: **`laravel_forms` holds the draft, `laravel_form_versions`
holds what respondents see, and a submission points at a version** — not at a form
([00](00-overview.md) §0.6 D2).

## 4.2 `laravel_forms` — altered

```php
laravel_forms
    id
    reference_num          string(32)  unique
    name                   string
    slug                   string(160) nullable            ← public URL segment, unique per site
    status                 enum         draft|published|closed|archived   default draft
    json_data              longText     nullable            ← the DRAFT envelope
    schema_version         unsignedSmallInteger default 1   ← 03 §3.4
    current_version_id     unsignedBigInteger nullable      ← FK → laravel_form_versions.id
    default_locale         string(12)   default 'en'        ← 08 §8.5
    settings               json         nullable            ← §4.8
    website_domain         string(255)  nullable  index
    website_reference_num  string(64)   nullable  index
    created_by             unsignedBigInteger nullable
    updated_by             unsignedBigInteger nullable
    published_at           timestamp    nullable
    closes_at              timestamp    nullable            ← 09 §9.5
    deleted_at             timestamp    nullable            ← soft delete, 02 §2.6
    timestamps

    index (website_reference_num, status, deleted_at)
    index (website_reference_num, created_at)
    unique (website_reference_num, slug)
```

| Column | Closes | Why |
|---|---|---|
| `status` | **F18** | Draft / published / closed / archived. The public endpoint serves only `published`, and only up to `closes_at` |
| `current_version_id` | **F19** | Makes publish mean something. Today it writes a `BuilderOutput` row nothing reads |
| `schema_version` | — | So a reader knows which envelope shape it holds ([03](03-architecture.md) §3.4) |
| `deleted_at` | **F21** | Deleting a form must not orphan its submissions |
| `slug` | — | A shareable URL that is not an 11-character random string ([09](09-publishing-and-versioning.md) §9.6) |
| `settings` | — | Per-form behaviour that is not part of the SurveyJS definition — §4.8 |
| `created_by` / `updated_by` | — | "Who changed this form" is currently unanswerable |

`json_data` stays `longText` and stays the draft. What changes is **what is written into it** —
§4.7.

## 4.3 `laravel_form_versions` — new

The centre of the plan. Closes **F17**.

```php
laravel_form_versions
    id
    reference_num          string(32)  unique
    form_reference_num     string(32)  index
    version                unsignedInteger                  ← 1, 2, 3 … per form
    envelope               longText                         ← the frozen { form_data, form_style, … }
    schema_version         unsignedSmallInteger default 1
    locales                json         nullable            ← ['en','fr'] — 08 §8.5
    question_index         json         nullable            ← §4.3.1
    checksum               string(64)                       ← sha256 of envelope
    published_by           unsignedBigInteger nullable
    published_at           timestamp
    note                   string(500)  nullable            ← "added the dietary question"
    created_at             timestamp

    unique (form_reference_num, version)
    index  (form_reference_num, published_at)
```

**Immutable.** No `updated_at`, no update path in any model, and a test asserting that no code
outside the publish action writes to the table. A version that can be edited is not a version;
it is a second draft with extra steps, and it reintroduces **F17** exactly.

`checksum` makes "did anything actually change since the last publish" answerable in one
comparison, which the editor uses to grey out the Publish button and the version list uses to
avoid showing identical versions.

### 4.3.1 `question_index`

A denormalised map extracted at publish, so that reading a submission does not require parsing
a 40 KB definition:

```json
{
  "attendee_email": { "type": "text", "title": "Your email",  "page": 0, "order": 2, "inputType": "email" },
  "dietary":        { "type": "checkbox", "title": "Dietary needs", "page": 1, "order": 0,
                      "choices": [ { "value": "veg", "text": "Vegetarian" },
                                   { "value": "gf",  "text": "Gluten free" } ] }
}
```

This is what makes export headers, the submission viewer, and per-question analytics fast and —
more importantly — **correct per version**. Two submissions against versions 2 and 4 render each
with its own labels and its own choice texts, which is the whole point of versioning.

It is derived data. It is rebuildable from `envelope`, and a rebuild command exists
([14](14-rollout-and-testing.md) §14.4).

## 4.4 `laravel_form_submissions` — altered

```php
laravel_form_submissions
    id
    reference_num          string(32)  unique              ← now the ROUTE key (F6)
    form_reference_num     string(32)  index
    form_version_id        unsignedBigInteger nullable index  ← FK → laravel_form_versions.id
    form_version           unsignedInteger nullable          ← denormalised for display
    submission_data        longText     nullable            ← was TEXT (F16)
    email                  string(320)  nullable index      ← §4.4.1
    status                 enum  complete|flagged|spam|preview   default complete
    locale                 string(12)   nullable
    site_user_id           unsignedBigInteger nullable index ← 11 §11.8
    ip_address             string(45)   nullable            ← IPv6-length
    user_agent             string(512)  nullable
    referrer               string(1024) nullable
    source_url             string(1024) nullable
    duration_seconds       unsignedInteger nullable
    spam_score             unsignedTinyInteger nullable     ← 02 §2.8
    idempotency_key        string(64)   nullable
    validation_state       enum  valid|unverified|invalid   default valid   ← 06 §6.9
    internal_note          text         nullable            ← 10 §10.9
    assigned_to            unsignedBigInteger nullable
    read_at                timestamp    nullable
    deleted_at             timestamp    nullable
    timestamps

    index  (form_reference_num, created_at)                 ← F24
    index  (form_reference_num, status, created_at)
    unique (form_reference_num, idempotency_key)            ← 02 §2.8
```

| Column | Closes | Why |
|---|---|---|
| `submission_data` → `longText` | **F16** | `TEXT` is 64 KB. One signature pad answer is 30–80 KB. Silent truncation produces invalid JSON and an unreadable submission, with no error anywhere |
| `form_version_id` | **F17** | The submission names the schema it answered |
| `ip_address` … `duration_seconds` | **F20** | Every question an operator asks about a suspicious submission |
| `status`, `spam_score` | **F8** | Flag, do not discard ([02](02-security-and-tenancy.md) §2.8). `preview` marks a submission made through a preview link ([09](09-publishing-and-versioning.md) §9.7) so it is excluded from counts and exports. **`partial` is not a status here** — a partial lives in `laravel_form_partials` (§4.9) and only becomes a submission on completion |
| `validation_state` | — | `unverified` is the honest answer when the server met an expression it could not evaluate ([06](06-logic-and-validation.md) §6.9) |
| `deleted_at` | **F21** | 30-day recovery window |
| index on `created_at` | **F24** | The module's only real query sorts on it |

**`submission_data` stays `longText` even after files become references** (§4.6). A dynamic
panel with twenty rows of a matrix is large without any file at all, and the failure mode of
guessing too small here is silent data corruption.

### 4.4.1 The `email` column (F22)

Today it is filled from `submissionData['email']` — whatever question is *named* `email`
(`renderer.ts:70`) — which, given auto-generated names (**F30**), is almost never the email
question. The column is null on most rows and the submissions list shows "N/A".

The fix is a per-form setting, not a convention: `settings.identity.email_question` names which
question supplies it (§4.8). The editor offers it as a dropdown of the form's email-typed
questions and defaults to the first one, so it is right without the tenant thinking about it,
and correctable when it is not. The server fills the column at submit; the client stops sending
it.

The same mechanism supplies `settings.identity.name_question`, which the submissions list uses
for a human label — a list of email addresses is barely better than a list of ids.

## 4.5 `laravel_form_answers` — new

Derived, rebuildable, and the reason the module can answer a question about its own data
([00](00-overview.md) §0.6 D3).

```php
laravel_form_answers
    id
    submission_reference_num  string(32)  index
    form_reference_num        string(32)  index
    form_version_id           unsignedBigInteger nullable
    question_name             string(255) index
    question_type             string(64)
    value_text                text        nullable          ← display / search
    value_number              decimal(20,6) nullable         ← numeric questions, for range queries
    value_date                timestamp   nullable
    value_bool                boolean     nullable
    value_json                json        nullable          ← the raw answer, always populated
    choice_values             json        nullable          ← selected choice values, for counting
    row_key                   string(255) nullable          ← matrix row / dynamic panel index
    created_at                timestamp

    index (form_reference_num, question_name, created_at)
    index (form_reference_num, question_name, value_number)
```

**`submission_data` remains authoritative.** These rows exist so that "how many chose
*Vegetarian*", "submissions where `budget > 5000`", and a 40-column CSV export are index scans
rather than a full-table JSON parse. If the two ever disagree, the rows are wrong by definition
and are rebuilt from the document.

`row_key` is what lets a `matrixdynamic` or `paneldynamic` answer — inherently a list of
records — decompose into countable rows instead of collapsing into one JSON cell
([06](06-logic-and-validation.md) §6.10, [10](10-submissions-and-data.md) §10.4).

A rebuild command (`forms:rebuild-answers`) regenerates the table for a form, a version, or
everything. It is needed after the initial migration, after any change to the decomposition
rules, and any time the derived data is suspected.

## 4.6 `laravel_form_files` — new

Closes **F12** and **F13** on the storage side; [02](02-security-and-tenancy.md) §2.10 covers
the endpoints.

```php
laravel_form_files
    id
    reference_num             string(32)  unique            ← the download key
    form_reference_num        string(32)  index
    submission_reference_num  string(32)  nullable index    ← null until claimed
    question_name             string(255) nullable
    original_name             string(255)                    ← as the respondent named it
    stored_name               string(255)                    ← as the SERVER named it
    storage_disk              string(32)
    storage_path              string(1024)
    mime_type                 string(160)
    size_bytes                unsignedBigInteger
    checksum                  string(64)  nullable
    scan_state                enum  pending|clean|infected|skipped   default skipped
    uploaded_ip               string(45)  nullable
    claimed_at                timestamp   nullable
    deleted_at                timestamp   nullable
    timestamps

    index (form_reference_num, claimed_at)
    index (submission_reference_num)
```

Four things this table makes possible that are impossible today:

1. **The server names the file** (`stored_name`), not the browser (**F12**).
2. **Downloads are authorised** — `reference_num` is the key, and the handler checks ownership
   ([02](02-security-and-tenancy.md) §2.10).
3. **Purge can actually purge.** Erasing a submission today leaves its files on disk forever,
   because nothing records that they exist ([02](02-security-and-tenancy.md) §2.6).
4. **Orphans are sweepable** — `claimed_at IS NULL AND created_at < now() - 24h`. Without this,
   the upload endpoint is anonymous free storage.

`scan_state` defaults to `skipped` so that no deployment is blocked on a scanner it does not
have. Where one exists, the column carries the result and the download handler can refuse
`infected`.

## 4.7 The encoding (F15)

`Utils_encodeJson()` is `htmlentities(json_encode($json))`; `Utils_decodeStringToJson()` is
`json_decode(html_entity_decode($str), true)` (`FunctionsController.php:3162–3175`).

It entity-encodes JSON that is already safe to store, inflating a definition by 30–60 %, making
the column unqueryable by any JSON function, and making both directions depend on
`htmlentities`' default charset matching. It is also about to get worse: conditional logic
(**F26**) fills a definition with `<`, `>`, `&&` and quoted strings — precisely the characters
this mangles.

**Target: store JSON as JSON.**

Three constraints make this a migration rather than an edit:

1. Existing rows are entity-encoded and must stay readable throughout.
2. `Utils_encodeJson` is used by other modules; **this plan does not change that shared helper**
   — it stops forms from calling it.
3. A partially-migrated table must be readable by both old and new code during the deploy.

So:

| Step | Action |
|---|---|
| 1 | Add `encoding` (`entities` \| `json`) to `laravel_forms`, defaulting to `entities` |
| 2 | Introduce `FormJson::decode()` / `FormJson::encode()` in the forms namespace. `decode()` reads the flag, and additionally sniffs for `&quot;` as a safety net for rows whose flag is wrong |
| 3 | Replace every `FunctionsController::Utils_*` call **in the forms code paths only** with the new pair, writing `encoding = 'json'` |
| 4 | Backfill command rewrites rows in batches, decoding with the old pair and re-encoding with the new |
| 5 | After the backfill verifies (§14.4), drop the sniff; keep the column |

`laravel_form_versions.envelope` is **JSON from the start** — nothing legacy ever writes it.

The commented-out `// 'hello' => html_entity_decode($form->json_data)` in `getForm()`
(`FormsController.php:100`) goes with it.

## 4.8 `settings` — what is not SurveyJS

The SurveyJS definition describes the *form*. It says nothing about how this product should
treat it. That goes in `laravel_forms.settings`, a JSON column with a versioned, validated
shape:

```json
{
  "identity":   { "email_question": "attendee_email", "name_question": "full_name" },
  "submission": { "allow_multiple": true, "require_login": false,
                  "close_after_responses": null, "allow_partial": true,
                  "partial_ttl_hours": 168 },
  "spam":       { "honeypot": true, "min_seconds": 2, "captcha": "off",
                  "origin_check": "log" },
  "notify":     { "admin_notification": true, "mail_handler_enabled": true },
  "webhooks":   [ { "url": "...", "secret_ref": "...", "events": ["submission.created"] } ],
  "export":     { "include_ip": false, "include_user_agent": false },
  "retention":  { "purge_after_days": null }
}
```

Kept out of the SurveyJS JSON deliberately: it is ours, it is not versioned with the
definition, and changing "notify the admin" must not create a new form version or invalidate
anything.

**`webhooks[].secret_ref` is a reference, never a secret.** The value lives wherever the
deployment keeps credentials; the column holds a pointer
([11](11-integrations-and-notifications.md) §11.5).

## 4.9 `laravel_form_partials` — new

Backs resumable responses ([10](10-submissions-and-data.md) §10.5).

```php
laravel_form_partials
    id
    reference_num          string(32)  unique               ← the resume token
    form_reference_num     string(32)  index
    form_version_id        unsignedBigInteger nullable
    site_user_id           unsignedBigInteger nullable index
    email                  string(320) nullable
    data                   longText                          ← answers so far
    current_page           unsignedSmallInteger default 0
    locale                 string(12)  nullable
    expires_at             timestamp   index
    completed_at           timestamp   nullable              ← set when it becomes a submission
    timestamps
```

A partial is **not** a submission — it is not exported, not counted, not notified on, and it
expires. Conflating the two is how "2,400 responses" turns out to include 1,900 people who
opened the form and left.

`expires_at` is mandatory and swept. Partials hold answers a respondent has not chosen to
submit, and keeping those indefinitely is a liability, not a feature.

## 4.10 `laravel_form_events` — new

```php
laravel_form_events
    id
    submission_reference_num  string(32)  index
    form_reference_num        string(32)  index
    type                      string(64)      ← submission.created, mail.sent, mail.failed,
                                              ← webhook.delivered, webhook.failed, status.changed,
                                              ← exported, flagged, purged
    actor_type                string(32)  nullable   ← system | user | respondent
    actor_id                  unsignedBigInteger nullable
    payload                   json        nullable
    created_at                timestamp   index
```

This is what makes "was this person emailed, and when" answerable — the single most common
question a form's support surface receives, and one the module cannot answer at all today
([10](10-submissions-and-data.md) §10.9).

## 4.11 Relationships and integrity

```
laravel_forms ──1:N──► laravel_form_versions        (form_reference_num)
laravel_forms ──1:1──► current_version_id
laravel_form_versions ──1:N──► laravel_form_submissions   (form_version_id)
laravel_form_submissions ──1:N──► laravel_form_answers    (submission_reference_num)
laravel_form_submissions ──1:N──► laravel_form_files      (submission_reference_num)
laravel_form_submissions ──1:N──► laravel_form_events     (submission_reference_num)
laravel_forms ──1:N──► laravel_form_partials              (form_reference_num)
```

The existing tables join on **string references**, not on ids, and there are no foreign keys
(**F21**). The new tables keep the string-reference convention — it matches the rest of this
codebase and it survives the export/import paths the ecosystem uses — with two additions:

- `form_version_id` is a real FK with `ON DELETE RESTRICT`. **A version referenced by a
  submission cannot be deleted.**
- Every reference column is indexed, and the composite indexes above match the queries the
  admin actually runs rather than being one index per column.

Cascades are handled in the application (the purge action, [02](02-security-and-tenancy.md)
§2.6), not by the database, because purging must also delete files from disk — which no
`ON DELETE CASCADE` can do.

## 4.12 Migration order

One migration per table, in this order, each reversible:

1. `alter laravel_forms` — new columns, all nullable or defaulted
2. `create laravel_form_versions`
3. `alter laravel_form_submissions` — **`submission_data` TEXT → LONGTEXT first**, before
   anything else touches the table
4. `create laravel_form_answers`
5. `create laravel_form_files`
6. `create laravel_form_partials`
7. `create laravel_form_events`
8. `add FK laravel_form_submissions.form_version_id → laravel_form_versions.id`

**P8 adds a ninth**, in its own migration and not before the rest:
`create laravel_form_submission_quota` ([15](15-allocation.md) §15.6). It is deliberately not
listed above — it belongs to no other work here, it has no foreign keys, and it is the one table
in this module that is a **counter rather than a record**.

Then, as commands rather than migrations ([14](14-rollout-and-testing.md) §14.4):

- `forms:backfill-sites` — the scoping backfill ([02](02-security-and-tenancy.md) §2.3)
- `forms:migrate-encoding` — §4.7
- `forms:seed-initial-versions` — one version per existing form, from its current draft, so
  every existing submission can point at *something* rather than at null
- `forms:rebuild-answers` — populate `laravel_form_answers` from existing documents

Step 3 first, and alone, is worth insisting on: it is the one change that stops ongoing data
loss (**F16**), it is backward compatible, and it does not depend on any other step here.

## 4.13 Acceptance

- A submission containing two 80 KB signature answers stores and reads back byte-identical.
- Editing and re-publishing a form does not change how any earlier submission renders — labels,
  choice texts and question order all come from that submission's version.
- `laravel_form_versions` rows are never updated: a test that attempts one fails.
- Deleting a form soft-deletes it; its submissions remain readable; purging removes rows **and
  files**.
- `GET /forms/submissions/{reference_num}` is the only key; no route accepts an integer id.
- A form definition containing `visibleIf: "{q1} > 5 and {q2} = 'yes'"` round-trips through
  save and load with no entity mangling.
- After `forms:migrate-encoding`, no `laravel_forms.json_data` value contains `&quot;`.
- `forms:rebuild-answers` produces answer rows whose values equal the document's, for every
  question type including matrix and dynamic panel.
- An unclaimed upload is gone 24 hours later; a claimed one survives.
- Counting choice selections for a 10,000-submission form is an index scan, not a JSON parse.
- The submissions list shows a human name where `settings.identity.name_question` is set, and
  the email column is populated regardless of what the email question is named.
