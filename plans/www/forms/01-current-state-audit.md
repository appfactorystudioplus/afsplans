# 01 — Current State Audit

Read from the repository. Findings are numbered `F#` so later documents can cite them.

## 1.1 What exists

| Layer | File | LOC |
|---|---|---|
| Migration | `2026_04_11_000001_create_laravel_forms_tables.php` — forms, submissions | 40 |
| Models | `app/Models/Externals/Form.php`, `app/Models/Externals/FormSubmission.php` | 30 / 26 |
| API | `app/Http/Controllers/Externals/Forms/FormsController.php` — 9 methods | 259 |
| Routes | `routes/portal.php:419–429` — `/portal/api/v1/forms/*` | 10 |
| Admin editor | `resources/js/portal/admin/controllers/forms/FormsEditorController.ts` | 2238 |
| Admin views | `resources/js/portal/admin/controllers/forms/FormsViewsController.ts` | 898 |
| Admin seed | `resources/js/portal/admin/controllers/forms/surveyConfig.ts` | 17 |
| **Dead** | `resources/js/portal/admin/controllers/forms/imp_forms_main.ts` | **936** |
| View registration | `resources/js/portal/admin/views/formsView.ts` — 6 subviews | 150 |
| Externals SDK | `resources/js/external/forms/` — `api.ts`, `renderer.ts`, `fileHandlers.ts`, `index.ts` | 346 |
| SDK contract | `resources/js/external/portal.d.ts:164` — `PortalFormsAPI` | 6 |
| Runtime | `public/assets/libs/surveyjs/` — v2.5.20, MIT, loaded as globals in `portal/admin/index.blade.php:21–24` | — |
| afs-connect | `WwwAfsConnectIngestDispatcher::formUpserted()` | — |

### The schema, in full

```php
laravel_forms
    id · reference_num (unique, 32) · name (nullable)
    json_data LONGTEXT (nullable)
    website_domain (nullable, indexed) · website_reference_num (nullable, indexed)
    timestamps

laravel_form_submissions
    id · reference_num (unique, 32) · form_reference_num (indexed, 32)
    email (nullable) · submission_data TEXT (nullable)
    timestamps
```

Two tables, ten columns of substance. That is the whole persistence layer.

### The routes, in full

```
GET    /portal/api/v1/forms                          listForms
POST   /portal/api/v1/forms                          createForm
GET    /portal/api/v1/forms/{ref}                    getForm          ← also the public endpoint
PUT    /portal/api/v1/forms/{ref}/json               updateForm
POST   /portal/api/v1/forms/{ref}/publish            publishForm
DELETE /portal/api/v1/forms/{ref}                    deleteForm       ← method does not exist
GET    /portal/api/v1/forms/{ref}/submissions        listFormSubmissions
GET    /portal/api/v1/forms/submissions/{id}         getFormSubmission
DELETE /portal/api/v1/forms/submissions/{id}         deleteFormSubmission
POST   /portal/api/v1/forms/submit                   submitForm       ← public
```

## 1.2 Security — the gate

| # | Finding | Consequence |
|---|---|---|
| **F1** | **The whole group is unauthenticated.** `Route::prefix('api/v1')` (`routes/portal.php:383`) sits directly inside `Route::middleware('portal.cors')` (`:75`), and `PortalCorsMiddleware::handle()` is `return $next($request)` — a documented no-op. No `ApiAuthMiddleware` wraps it | Unauthenticated create, read, update, publish, and submission delete on every deployment |
| **F2** | `listForms()` is `Form::orderBy('created_at','desc')->get()` with no predicate (`FormsController.php:20`) | **Every form on the deployment, with its full definition, to anyone.** Combined with **F1**, a single GET enumerates every tenant's forms |
| **F3** | `createForm()` never writes `website_domain` or `website_reference_num` (`:57–62`) despite both columns existing and being indexed | The scoping columns are **NULL on every row ever created**. Scoping cannot be switched on without a backfill, and there is no data to backfill *from* except the afs-connect tenant resolution that happens two lines later |
| **F4** | `getForm`, `updateForm`, `publishForm` all do `Form::where('reference_num', $ref)->first()` with no ownership check | Even after **F1** is fixed, one authenticated tenant reads and overwrites another tenant's form by naming its reference |
| **F5** | `GET /forms/{ref}` is **both** the admin detail endpoint and the public one — `ExternalFormsAPI.init()` calls exactly this route (`external/forms/api.ts:47`) | There is no separate public projection. Any field added for the admin (submission counts, internal notes, owner email) is served to every anonymous visitor of the embedding site |
| **F6** | `getFormSubmission($id)` and `deleteFormSubmission($id)` key on the **autoincrement id** (`:191`, `:201`), never on `form_reference_num` and never on the submission's own `reference_num` — which exists, is unique, and is unused by both routes | Classic IDOR. `GET /forms/submissions/1`, `2`, `3`… walks every submission on the deployment, across every tenant. `DELETE` does the same |
| **F7** | `routes/portal.php:425` registers `FormsController::deleteForm`. **The class has no `deleteForm` method** — only `deleteFormSubmission` | Every "Delete" from the admin form list 500s. The UI's `deleteForm()` (`FormsViewsController.ts:530`) does not check the response, so the row silently stays |
| **F8** | `submitForm()` has no throttle, no captcha, no honeypot, no origin/referer check, no duplicate suppression, and no cap on payload size beyond PHP's | The public endpoint is a free write to the tenant's database, and to their mail provider via **F9** |
| **F9** | **Mail is sent by the browser.** `renderer.ts:103` calls `mailHandlerApi.send(form.reference_num, { recipient, subject, submission_data })` in `onComplete`, with `recipient` taken from the answers and `subject` composed client-side | Two failures at once: (a) an attacker posts arbitrary `recipient` and `subject` to the mail-handler endpoint and relays mail through the tenant's provider; (b) an honest respondent who closes the tab on the "thank you" screen gets no email, and neither does the tenant — the submission is stored, the notification never sent |
| **F10** | Admin notification creation is also client-side (`renderer.ts:83`) | Notification body, title and metadata are attacker-controlled. Combined with **F36**, this reaches the admin UI as markup |
| **F11** | Analytics `forms.loaded` / `forms.completed` are client-side (`renderer.ts:130`, `:118`) | Completion metrics can be inflated or suppressed by the page hosting the form |
| **F12** | File uploads POST to the legacy `/_portal_/admin/core/php/request.php` with a **client-composed** `new_name` (`file_<random>.<ext>`), `savedirectory: '/modules/forms'` and `category: 'forms'` (`fileHandlers.ts:38–45`). **`public/_portal_` does not exist in this repository** — the whole legacy tree is absent, and `routes/portal.php` `include_once`s two files under it, failing silently | Two readings, and which applies depends on the deployment. If the tree is absent in production too, **file upload questions are simply broken** — `options.callback` receives a parse error and the answer is lost. If the tree exists in production but not in this repo, the browser chooses the stored filename, its extension and the directory, without credentials. **Establish which before designing the fix** ([02](02-security-and-tenancy.md) §2.10) |
| **F13** | Uploaded files are read back through `?savedirectory=forms&api_download_file=<name>` and deleted through `?savedirectory=forms&delete_file=<name>` **over GET** (`fileHandlers.ts:58`, `:106`) | Subject to the same caveat as **F12**. Where the endpoint exists, submission attachments — CVs, ID photos, whatever the form asked for — are served to anyone holding the name, with no authorisation, and deletion over GET is reachable by any page that can cause a request. Where it does not, uploads never completed and there is nothing to serve |
| **F14** | PDF export POSTs `pdfcontent` (full HTML) plus a client-chosen `tmp_filename` to the legacy endpoint, then opens a second request to download it (`FormsViewsController.ts:755–786`) | Client-controlled filename and file body written to the server's filesystem. Also `title: data.name` is passed where `data` has `title` — the parameter is always `undefined` |

**F1**–**F14** are **P0**. [02](02-security-and-tenancy.md) closes them and depends on nothing
else in this plan.

## 1.3 The data model

| # | Finding | Consequence |
|---|---|---|
| **F15** | `json_data` is stored as `htmlentities(json_encode($x))` — `FunctionsController::Utils_encodeJson()` (`:3172`) — and read back with `json_decode(html_entity_decode($str), true)` (`:3162`) | Every `<`, `>`, `"`, `&` and accented character in a form definition is entity-encoded **inside already-valid JSON**. It inflates a definition by 30–60 %, makes the column unqueryable by any JSON function, and depends on `htmlentities`' default charset matching on write and read. The commented-out `// 'hello' => html_entity_decode($form->json_data)` left in `getForm()` (`:100`) is the scar tissue from debugging it |
| **F16** | `laravel_form_submissions.submission_data` is **`TEXT`** — 65,535 bytes in MySQL | A `signaturepad` answer is a base64 PNG; a 600 px signature is comfortably 30–80 KB. Two signatures, or one signature plus a long comment, and MySQL **truncates silently** in non-strict mode. The stored value is then invalid JSON and the submission is unreadable — with no error recorded anywhere |
| **F17** | **A submission records no schema.** Answers are keyed by question `name`, and nothing links a submission to the definition it was answered against | Rename a question and every prior answer to it becomes an orphan key. Delete a choice and prior submissions reference a value that no longer exists. Delete a page and its answers become unlabelled. This happens **silently, on every edit of a live form**, and there is no way afterwards to tell which submissions predate which change |
| **F18** | `laravel_forms` has no `status` column | No draft, no published, no closed, no archived. A form is live the moment it is created, and unpublishing means deleting |
| **F19** | `publishForm()` writes one `BuilderOutput` row — `['typeid' => $ref, 'typename' => 'forms']` with `html => ''` — and nothing else (`:160–172`) | Publish has no effect on what a respondent can fetch: `GET /forms/{ref}` serves the draft regardless. The button changes a row nothing in the forms path reads |
| **F20** | A submission stores `reference_num`, `form_reference_num`, `email`, `submission_data`, timestamps. Nothing else | No IP, no user agent, no referrer, no locale, no duration, no completion state, no source page, no `site_user_id`. Every question an operator asks about a suspicious submission is unanswerable |
| **F21** | No soft deletes; `form_reference_num` is a bare indexed string with no foreign key and no cascade | Deleting a form (once **F7** is fixed) orphans its submissions permanently. Deleting a submission is immediate and unrecoverable |
| **F22** | The `email` column is populated from `submissionData['email']` — whatever question happens to be **named** `email` (`renderer.ts:70`) | Since names are auto-generated `question1`, `question2`… (**F30**), the email question is almost never named `email`. The column is null on most rows, and the submissions list shows "N/A" |
| **F23** | `listFormSubmissions()` is `->orderBy('created_at','desc')->limit(200)->get()` — no pagination, no cursor, no total (`:177–183`) | Submission 201 is invisible in the admin, forever. There is no UI path to it and no API parameter that reaches it |
| **F24** | `created_at` is not indexed on either table; only `form_reference_num` is | The one query the module actually runs — submissions for a form, newest first — sorts on an unindexed column |

## 1.4 The editor — question coverage and configuration

This is where the module's ceiling is, and it is low.

| # | Finding | Consequence |
|---|---|---|
| **F25** | The toolbox offers **12** types: `text`, `checkbox`, `radiogroup`, `dropdown`, `rating`, `matrix`, `ranking`, `comment`, `html`, `signaturepad`, `file`, `multipletext` (`FormsEditorController.ts:40–53`). The bundled runtime ships roughly twice that | Unreachable today: `boolean`, `tagbox` (multi-select dropdown), `imagepicker`, `image`, `expression` (computed display), `matrixdropdown`, `matrixdynamic` (add-a-row grids), `panel` (grouping), `paneldynamic` (repeating groups). A form needing "add another attendee" cannot be built |
| **F26** | **No conditional logic exists anywhere in the editor.** `visibleIf`, `enableIf`, `requiredIf`, `resetValueIf`, `setValueIf` are never written, never read, never surfaced | Every question is always visible and always in the same state. This is the largest single capability gap in the module: a branching form is the most common reason a tenant reaches for a form builder rather than a static HTML form |
| **F27** | **No validators.** The `validators[]` array — numeric ranges, text length, email, regex, expression, answer count — is never written. `isRequired` is the entire validation surface | "Must be a valid postcode", "between 1 and 10", "pick exactly three" are all unexpressible |
| **F28** | `text` questions have no `inputType` control | Every text question is a plain string. No email, number, date, time, tel, url or password input; no `min`/`max`/`step`; no input masking. On mobile, every text question raises the full keyboard |
| **F29** | The properties panel exposes **three** properties: `title`, `isRequired`, and choices (`updatePropertiesPanel`, `:1263–1433`) | Not reachable: `description`, `defaultValue`, `placeholder`, `readOnly`, `visible`, `titleLocation`, `descriptionLocation`, `hideNumber`, `valueName`, `clearIfInvisible`, `correctAnswer`, `showOtherItem`, `showNoneItem`, `showSelectAllItem`, `choicesOrder`, `colCount`, `separateSpecialChoices`, `rateType`/`rateValues`/`rateDescriptions`, matrix `cellType` and `isAllRowRequired`, file `acceptedTypes`/`maxSize`/`allowMultiple`, and the whole layout group (`startWithNewLine`, `width`, `minWidth`, `maxWidth`, `indent`) |
| **F30** | The `Name` input is rendered **`disabled`** (`:1289`) | A question's name is permanently `question1`, `question2`, `question3`. That name is the key in `submission_data`, the column header in every export, and the identifier every expression, piping token and trigger must refer to. The module's most important field is read-only and machine-generated |
| **F31** | `addQuestion()` names by `page.questions.length + 1` (`:1183–1184`) | Delete question 2 of 3, then add one: the new question is named `question3`, which **already exists**. SurveyJS treats `name` as the key — two questions with one name is silent, immediate data loss, and it takes two clicks to reach |
| **F32** | `radiogroup` and `dropdown` choices are edited as a **comma-separated textarea**. On read it flattens `{value, text}` to `c.text \|\| c` (`:1312`); on save it does `choices.split(',').map(c => c.trim())` (`:1457`) | Two defects in one control. (a) A choice containing a comma is impossible — "Yes, definitely" becomes two choices. (b) **Every save destroys choice values**: a choice authored as `{value: 'US', text: 'United States'}` is read as `United States` and written back as the string `United States`, so the stored value changes from `US` to `United States` — and every existing submission's answer stops matching any current choice |
| **F33** | `saveProperties()` guards with `if (choices && [...].includes(type))` (`:1456`) | Emptying the choices textarea is a no-op; the old choices silently remain. There is no way to clear choices |
| **F34** | `checkbox` gets a per-choice row editor; `radiogroup` and `dropdown` get the textarea (`:1300–1318`) | Two different editors for the same underlying property, with different capabilities and different bugs. Which one a tenant gets depends on the question type they picked |
| **F35** | `q.title` is interpolated raw into `value="${q.title \|\| ''}"` (`:1285`). The file defines `escapeHtml()` at `:102` and uses it **exactly once**, for choices (`:1312`) | A title containing a double quote breaks the properties panel. A title containing an event-handler attribute executes in the admin. The escaping helper exists and was not applied to the field most likely to contain punctuation |
| **F36** | The admin list interpolates `form.name` raw (`FormsViewsController.ts:340`) and the submissions list interpolates `submission.email` raw (`:395`) | The second is the serious one: `email` arrives from `POST /forms/submit`, which is public and unauthenticated (**F1**, **F8**). **A respondent controls markup that executes in the admin dashboard.** Public → admin stored XSS, reachable with one `curl` |
| **F37** | Toolbox items are drag-only. The click handler is `btn.addEventListener('click', e => e.preventDefault())` (`:474`) — it cancels and adds nothing | HTML5 drag-and-drop does not fire on touch, so **a form cannot be built on a tablet or phone at all**, and there is no keyboard path to adding a question. The `d-md-flex` toolbox is hidden below `md` anyway, and the mobile drawer (`setupMobileMenus`) reveals a panel whose only interaction is dragging |
| **F38** | Reordering is Up/Down buttons **inside the properties panel** (`:1502–1535`); the canvas has no drag-to-reorder | A question must be selected before it can be moved, moving is one position per click, and there is no way to move a question to another page at all |
| **F39** | No duplicate, no copy/paste, no multi-select, no undo, no redo | Building a form with eight similar questions means configuring each from scratch, and any mistake is permanent |
| **F40** | The canvas is a **hand-written renderer** — `renderSurveyPreview()` always calls `renderSurveyPreviewFallback()` → `renderQuestion()` (`:1753–1988`) — never the runtime. `setTheme()` re-renders it (`:1994`), but that renderer applies no theme at all | **The Theme dropdown does nothing on the canvas.** The theme is only visible in the preview modal, which is a separate code path (`renderActualSurvey`). Beyond the theme: layout properties, panels, dynamic panels, matrix columns and conditional visibility can never be shown correctly by a renderer that must be taught each type by hand — which is why **F25**–**F29** are hard to fix without fixing this first |

## 1.5 The editor — state, persistence and lifecycle

| # | Finding | Consequence |
|---|---|---|
| **F41** | Module-level singletons (`survey`, `selectedQuestion`, `currentTheme`, `currentPageIndex`, `useCustomStyle`, `formStyle`) plus `restoreStyleState()` reading a **single** `sessionStorage` key `'formEditorStyleState'` with no form reference (`:79–100`, `:266`) | Open form A, adjust the palette, navigate to form B: **B loads with A's unsaved styles**, and saving B writes them. The lazy-loaded module is not re-instantiated between views, so the leak is the normal path, not an edge case |
| **F42** | `localStorage.setItem('surveyJSON', json_data)` on every save (`FormsViewsController.ts:73`) | One global key for every form on every tenant, written and never read |
| **F43** | `await new Promise(resolve => setTimeout(resolve, 800))` with the comment *"Simulate network delay for UX"* on the save path (`FormsEditorController.ts:1060`) | Every save is 800 ms slower than the network requires, deliberately |
| **F44** | No dirty tracking, no autosave, no `beforeunload` guard | Navigating away — including via the module's own back button — discards everything since the last manual Save, with no warning. Style state survives in `sessionStorage` (**F41**); the form itself does not |
| **F45** | The Languages tab is a hard-coded, **disabled** checkbox reading "English — default" (`:311–316`) | It is a placeholder that looks like a feature. No `locale`, no localizable strings, no translation surface, and no way to tell from the UI that none exists |
| **F46** | The Global tab exposes **two** settings: `completedHtml` and `showPreviewBeforeComplete` (`buildGlobalTab`, `:486–575`) | Absent: `showProgressBar`, `progressBarType`, `questionsOnPageMode`, `showQuestionNumbers`, `questionTitleLocation`, `questionErrorLocation`, `checkErrorsMode`, `textUpdateMode`, `showTOC`, `widthMode`, `navigateToUrl`, `completedHtmlOnCondition`, timer settings, `firstPageIsStarted`, `triggers`, `calculatedValues`, `clearInvisibleValues` |
| **F47** | `showPreviewBeforeComplete` is written as a **string** — `'noPreview'` / `'showAllQuestions'` / `'showAnsweredQuestions'` (`:571`) | That is the v1 shape. Verify against the bundled 2.5.20 build: v2 moved to a boolean plus a separate `previewMode` property. If so, the only global setting with a UI writes a value the runtime ignores — a control that appears to work and does nothing |
| **F48** | `syncPropertiesPanelHeight()` sets the properties panel height to `surveyContainer.clientHeight + 500` px (`:1551`); `scrollSelectedQuestionIntoView()` positions the properties card by absolute `top` after a 200 ms `setTimeout` (`:1241`) | Layout by arithmetic and by timer. It breaks on browser zoom, on a long form, on a narrow viewport, and whenever the canvas re-renders faster or slower than 200 ms |
| **F49** | `imp_forms_main.ts` — **936 lines** — is a near-duplicate earlier copy of the editor, importing `SurveyModel` from the npm `survey-core` package (which the runtime does not use; it uses globals). `grep` across `resources/` finds **no importer** | Dead code that reads like live code, sharing function names with the real editor. The next person to fix a bug has a 50 % chance of fixing it in the wrong file |
| **F50** | Two modal mechanisms in one file: `showModal`/`hideModal` imported from `utils/helpers` (`:73`) and used for the reset/rename dialogs, and `ensurePreviewContainer()`/`togglePreviewMode()` which set `style.display` and toggle `.show`/`.modal-open` by hand (`:1075–1123`) | The hand-rolled one leaves `modal-open` on `body` if the page navigates while open, and duplicates a Bootstrap behaviour that already exists |

## 1.6 Submissions and the operator surface

| # | Finding | Consequence |
|---|---|---|
| **F51** | No export at all — no CSV, no XLSX, no JSON download | The data cannot leave the product. For most tenants this is the reason they collected it |
| **F52** | The submissions list has no search, no filter, no date range, no sort control and no pagination (**F23**) | With more than a handful of responses the screen is unusable, and past 200 it is incomplete |
| **F53** | No per-question aggregate anywhere | "What did people answer to question 4" requires opening submissions one at a time |
| **F54** | `buildSubmissionResponseModel()` (`FormsViewsController.ts:569`) flattens elements — including nested panels, `templateElements` and matrix rows — and pairs each with `submissionData[element.name]`, with no type awareness | A file answer renders as an object, a signature as a base64 string, a matrix answer as `[object Object]`, a multi-select as a comma-joined dump. The flattener is careful; the renderer that consumes it is not |
| **F55** | The PDF path (**F14**) also passes `title: data.name` where `data` defines `title` (`:781`) | The download's title parameter is always `undefined` |
| **F56** | A submission has no status, no triage, no internal note, no "mark as read", and no way to resend a notification | There is no workflow. A submission is a row that is either present or deleted |

## 1.7 Integration

| # | Finding | Consequence |
|---|---|---|
| **F57** | The `forms:response` view configures mail handlers through `EmailConstructionBuilder` and saves them under `ViewsCategories.FORMS` (`FormsViewsController.ts:196–230`). **`FormsController::submitForm()` never reads them** — `grep` for `MailHandler` in that controller returns nothing | The tenant configures email responses in a dedicated screen, and the only thing that sends mail is the browser call in **F9**. The configuration surface and the sending path are not connected |
| **F58** | `WwwAfsConnectIngestDispatcher::formUpserted()` fires on create and update (`FormsController.php:65`, `:143`). There is no dispatch on **submit** and none on **delete** | afs-connect knows a form's definition changed but never that it received a response, so `WwwAfsConnectMetaProvider`'s per-site `forms` count is a count of forms, not of activity |
| **F59** | No outbound webhook | A tenant cannot route a submission into their CRM, Zapier, or anything else, except by polling an unauthenticated list endpoint |
| **F60** | No Site Users link — no `site_user_id` on a submission | "My submissions", prefill-from-account, and "one response per account" are all impossible ([site_users](../site_users/README.md)) |
| **F61** | No payment path, despite Direct Payments being the ecosystem's payment engine | A registration form that takes a fee cannot be built here |

### 1.7.1 Adjacent — found while tracing the forms path, **not** forms defects

Both belong to the mail-handler module and should be raised there. They are recorded because
forms is a consumer, and a forms plan built on top of them without saying so would be building
on sand.

| Observation | Detail |
|---|---|
| **Mail-provider credentials are served unauthenticated** | `GET /portal/api/v1/mail-handler/{reference_num}` (`routes/portal.php:778`) is in the same `portal.cors` group and returns the full provider record. The SDK's own type declares that record as carrying `key` and `secret` (`resources/js/external/mail_handler/api.ts:20–32`) |
| **The client's recipient overrides the tenant's configuration** | `MailHandlerApiController::send()` does `$han['emails'] = [$data['recipient']];` for every configured handler (`app/Http/Controllers/Externals/MailHandler/MailHandlerApiController.php:143`). Whatever the tenant configured in the response builder, the browser's value wins. This is what makes **F9** an open relay rather than a mere layering complaint |

Removing the client-side `mailHandlerApi.send()` call ([11](11-integrations-and-notifications.md) §11.3)
reduces the forms module's exposure to both, but does not close either.

## 1.8 What is genuinely good

Worth stating, because the plan builds on all of it rather than replacing it:

- **The runtime choice is right.** SurveyJS v2.5.20 is MIT, actively maintained, and already
  does conditional logic, validators, expressions, localization and theming. Almost everything
  in [06](06-logic-and-validation.md), [07](07-theming-and-layout.md) and
  [08](08-multi-language.md) is **exposing capability that is already loaded in the page**, not
  building it.
- **The public renderer is correct.** `external/forms/renderer.ts` mounts the real
  `SurveyUI.renderSurvey()`, applies the theme, handles the custom-theme envelope, and falls
  back sensibly when `json_data` is a bare definition. The respondent's experience is the
  soundest part of the module.
- **The envelope is a good idea.** Splitting `{ form_data, form_style, use_custom_style,
  form_style_custom }` cleanly separates definition from presentation and is what makes
  versioning ([09](09-publishing-and-versioning.md)) straightforward.
- **`website_domain` / `website_reference_num` are already on the table.** Multi-site scoping
  was anticipated; it was just never written to (**F3**) or read from (**F2**).
- **`reference_num` on submissions already exists** and is unique — the fix for the IDOR
  (**F6**) is to use a column that is already there.
- **The mail handler, notifications and analytics integrations all work.** They are called from
  the wrong side of the wire (**F9**–**F11**), which is a relocation, not a rebuild.
- **`flattenSurveyElements()` is thoughtful** — it already walks `elements`, `templateElements`
  and matrix `rows` recursively, which is exactly what panels and dynamic panels will need
  ([10](10-submissions-and-data.md) §10.4).
- **The theme list is complete.** All 20 bundled themes are enumerated and wired to the
  dropdown; only the canvas fails to apply them (**F40**).

## 1.9 Summary

| Group | Findings | Phase |
|---|---|---|
| Security and tenancy | **F1**–**F14** | **P0** — ships first, depends on nothing |
| Data model | **F15**–**F24** | **P1** — before anything new reads or writes the data |
| Editor: coverage and configuration | **F25**–**F40** | **P2**–**P4** — the canvas (**F40**) unblocks the rest |
| Editor: state and lifecycle | **F41**–**F50** | **P2**, alongside the canvas |
| Submissions and operator surface | **F51**–**F56** | **P5** |
| Integration | **F57**–**F61** | **P6**–**P7** |
| Allocation | **F62**–**F66** | **P8** — defined in [15](15-allocation.md) §15.2, because they are only visible once the limits are being wired |

The order matters in two places. **Fix the storage encoding and add versions before building a
configuration surface that writes richer definitions into them** — a `visibleIf` expression
full of `>` and `&&` characters is exactly what `htmlentities` mangles (**F15**), and a form
whose logic changes is exactly what invalidates old submissions (**F17**). And **fix the canvas
renderer before adding question types** — every type added to the current renderer is another
type to hand-draw and another way for the editor to disagree with what the respondent sees.
