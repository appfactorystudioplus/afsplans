# forms — Planning Index

Turning a hand-rolled SurveyJS wrapper into a real form platform: conditional logic,
validation, expressions, multi-language, themes, versioned publishing, and a submission store
that can still be read a year after the form was edited.

> ### 🔴 Three things are true today and the first one gates everything
>
> **1. The entire forms API is unauthenticated.**
> `/portal/api/v1/forms/*` sits inside `Route::middleware('portal.cors')`
> (`routes/portal.php:75`), and `PortalCorsMiddleware::handle()` is `return $next($request)`
> with a docblock saying CORS is Nginx's job. That group contains `POST /forms`,
> `PUT /forms/{ref}/json`, `POST /forms/{ref}/publish` and `DELETE /forms/submissions/{id}`.
> `listForms()` is an unscoped `Form::orderBy(...)->get()`, so **one unauthenticated GET
> returns every form on the deployment**, and `GET /forms/submissions/{id}` walks every
> submission on it by autoincrement id.
>
> **2. The browser is trusted to send the tenant's email.**
> `resources/js/external/forms/renderer.ts:96` calls
> `mailHandlerApi.send(form.reference_num, { recipient, subject, submission_data })` **from the
> respondent's browser**, with a recipient and subject the browser chose. That is an open relay
> on the tenant's mail provider — and if the respondent closes the tab, no email is sent at all.
>
> **3. `DELETE /forms/{reference_num}` calls a method that does not exist.**
> `routes/portal.php:425` points at `FormsController::deleteForm`. The class has no such
> method. Deleting a form from the admin UI 500s, every time.
>
> See [01-current-state-audit.md](01-current-state-audit.md). Phase **P0**
> ([02](02-security-and-tenancy.md)) closes all three and depends on nothing else here.

Read in this order:

| Step | Document | Contents |
|---|----------|----------|
| — | [README.md](README.md) | This index |
| 00 | [00-overview.md](00-overview.md) | What this is for, goals, non-goals, glossary, and the four decisions that shape everything after |
| 01 | [01-current-state-audit.md](01-current-state-audit.md) | Every file, table and route that exists, and the 61 findings |
| 02 | [02-security-and-tenancy.md](02-security-and-tenancy.md) | **P0** — authentication, site scoping, IDOR, the missing delete, abuse control on the public endpoint |
| 03 | [03-architecture.md](03-architecture.md) | The layers, what owns the schema, and why a definition and a response are different objects |
| 04 | [04-database-schema.md](04-database-schema.md) | Forms, **versions**, submissions, answers, files, partials — and the end of `htmlentities(json_encode())` |
| 05 | [05-form-builder.md](05-form-builder.md) | The editor: the full question set, a real properties panel, naming, reordering, undo, and a canvas that stops lying |
| 06 | [06-logic-and-validation.md](06-logic-and-validation.md) | **The advanced surface** — `visibleIf`, validators, expressions, triggers, piping, calculated values, quiz scoring |
| 07 | [07-theming-and-layout.md](07-theming-and-layout.md) | Themes, custom CSS variables, layout, progress, pagination modes, branding |
| 08 | [08-multi-language.md](08-multi-language.md) | The Languages tab made real — localizable strings, a translation grid, locale resolution |
| 09 | [09-publishing-and-versioning.md](09-publishing-and-versioning.md) | Draft vs published, the **immutable version**, scheduling and close rules, embed and share |
| 10 | [10-submissions-and-data.md](10-submissions-and-data.md) | Storage, files, partial saves, export, per-question analytics, PDF |
| 11 | [11-integrations-and-notifications.md](11-integrations-and-notifications.md) | Server-side mail handlers, notifications, webhooks, afs-connect, Site Users, paid forms |
| 12 | [12-externals-sdk.md](12-externals-sdk.md) | `window.Portal.forms`, the renderer, and `portal.d.ts` |
| 13 | [13-admin-dashboard.md](13-admin-dashboard.md) | The operator surface — list, editor, submissions, settings |
| 14 | [14-rollout-and-testing.md](14-rollout-and-testing.md) | Phases, the security gate, the test matrix, and migrating the data that already exists |
| 15 | [15-allocation.md](15-allocation.md) | **alloc_v2** — forms per site, submissions per month, and why only one of the two can be a client-side check |

## Completion documents

Each numbered document gets a completion document in [`complete/`](complete/) — source
basename plus `.complete.md`. Include status, summary, files changed, verification steps and
follow-up work.

## Code documentation standard

Document all new and changed code thoroughly with block comments — PHPDoc for PHP, JSDoc for
TypeScript. Every file, exported symbol, public function, route handler, migration and
non-trivial type must explain purpose, parameters, return values, side effects and plan
references.

New code carries `AFS_FORMS_MODIFIER_ADDITION`, registered in
[../../markers.md](../../markers.md). Site Users work carries
`AFS_SITE_USERS_MODIFIER_ADDITION`; allocation work carries `AFS_ALLOC_V2_TAG` as well
([15](15-allocation.md)). Where a file already has a marker, **add alongside; never replace.**

## The four rules this plan is built on

**1. The bundled SurveyJS is the runtime; the editor is ours.**
`public/assets/libs/surveyjs/` is the Survey JavaScript Library **v2.5.20** — `survey.core`,
`survey-js-ui` and `survey-themes`, all MIT. **Survey Creator — the official visual builder —
is a separate commercial product and is not bundled.** The hand-rolled editor is therefore not
an accident to be deleted; it is the consequence of a licensing decision. This plan improves
it, and every property it edits must be a real property of the v2.5.20 runtime
([00](00-overview.md) §0.4).

**2. The canvas renders through the runtime, not around it.**
The editor currently paints its own approximation of each question
(`renderSurveyPreviewFallback` → `renderQuestion`). That renderer must be taught every type by
hand, ignores the selected theme entirely, and cannot show a panel, a dynamic panel or a matrix
column correctly. Everything in [05](05-form-builder.md) and
[06](06-logic-and-validation.md) depends on replacing it with a real `SurveyUI` render in
design mode ([05](05-form-builder.md) §5.2).

**3. A submission is answered against a version, not against a form.**
Answers are keyed by question `name`. Rename a question, drop a choice, or delete a page, and
every prior submission becomes uninterpretable — silently, with no error anywhere. Publishing
freezes an immutable version, and a submission names the version it answered
([09](09-publishing-and-versioning.md) §9.3). This is the single change that makes the data
trustworthy.

**4. Nothing that matters happens in the respondent's browser.**
Mail, notifications, analytics and validation are triggered server-side from the submit
handler ([11](11-integrations-and-notifications.md) §11.2). The browser reports; the server
decides. Today the browser is trusted to name the recipient of the tenant's email (**F9**).

## One-paragraph summary

The forms module today is a working shell around a good runtime: SurveyJS v2.5.20 renders the
public form correctly, and files, notifications, mail and analytics are all wired — from the
browser, over an API that requires no credentials. Behind it sits a hand-rolled editor that
exposes three properties per question, no conditional logic, no validators, no expressions and
no translations; a `name` field that is disabled and auto-generated into collisions; a choices
editor that destroys choice values on every save; two `TEXT` columns holding
`htmlentities(json_encode(…))`; and no notion of a version, so editing a live form quietly
invalidates every submission it already has. This plan closes the security hole first, gives
the definition a real schema with immutable published versions, then builds the configuration
surface the runtime already supports — **conditional visibility, validation, expressions,
triggers, piping, calculated values, quiz scoring, per-question layout, themes and
multi-language** — and moves every side effect of a submission out of the browser and onto the
server.
