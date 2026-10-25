# 02 — Security and Tenancy (P0)

Closes **F1**–**F14**. Depends on nothing else in this plan, and everything else in this plan
depends on it. Ship it on its own branch, before any feature work.

## 2.1 The shape of the problem

Ten routes. One is legitimately public (`POST /forms/submit`), one is public-but-restricted
(`GET /forms/{ref}` — the embed needs it), and **eight are administrative and currently open to
the internet**.

| Route | Today | Target |
|---|---|---|
| `GET /forms` | open, unscoped, all tenants | authenticated + site-scoped |
| `POST /forms` | open | authenticated + site-stamped |
| `PUT /forms/{ref}/json` | open, no ownership check | authenticated + ownership |
| `POST /forms/{ref}/publish` | open, no ownership check | authenticated + ownership |
| `DELETE /forms/{ref}` | **500 — method missing** | authenticated + ownership + soft delete |
| `GET /forms/{ref}/submissions` | open, unscoped, capped at 200 | authenticated + ownership + paged |
| `GET /forms/submissions/{id}` | open, IDOR by autoincrement id | authenticated + ownership, keyed by `reference_num` |
| `DELETE /forms/submissions/{id}` | open, IDOR | authenticated + ownership, keyed by `reference_num` |
| `GET /forms/{ref}` | open — **and must stay reachable** | public **projection**, published versions only |
| `POST /forms/submit` | open — **and must stay open** | public + throttled + validated + origin-checked |

The two public routes are the interesting part. Everything else is a middleware line.

## 2.2 Split the route group

Today all ten sit inside one `Route::prefix('api/v1')` group whose only wrapper is
`portal.cors` — a no-op (`app/Http/Middleware/PortalCorsMiddleware.php`). Split them:

```php
// AFS_FORMS_MODIFIER_ADDITION — plans/www/forms/02-security-and-tenancy.md §2.2
// Administrative surface. Requires a portal session or bearer token.
Route::prefix('api/v1/forms')
    ->middleware([ApiAuthMiddleware::class, FormsSiteScope::class])
    ->group(function () {
        Route::get('/',                        [FormsController::class, 'listForms']);
        Route::post('/',                       [FormsController::class, 'createForm']);
        Route::get('/{reference_num}/admin',   [FormsController::class, 'getFormForAdmin']);
        Route::put('/{reference_num}/json',    [FormsController::class, 'updateForm']);
        Route::post('/{reference_num}/publish',[FormsController::class, 'publishForm']);
        Route::delete('/{reference_num}',      [FormsController::class, 'deleteForm']);
        Route::get('/{reference_num}/submissions', [FormsController::class, 'listFormSubmissions']);
        Route::get('/submissions/{reference_num}', [FormsController::class, 'getFormSubmission']);
        Route::delete('/submissions/{reference_num}', [FormsController::class, 'deleteFormSubmission']);
    });

// Public surface. No credentials. Heavily constrained.
Route::prefix('api/v1/forms')
    ->middleware([FormsPublicThrottle::class])
    ->group(function () {
        Route::get('/{reference_num}',  [FormsPublicController::class, 'show']);
        Route::post('/submit',          [FormsPublicController::class, 'submit']);
    });
```

Two things to note.

**The admin detail route moves to `/{ref}/admin`.** Today one handler serves both the editor
and the embed (**F5**), so every field added for the editor leaks to anonymous visitors. A
separate `FormsPublicController` is the enforcement: the public class has no code path that can
reach an admin field, rather than a shared handler with a flag that someone will forget to
pass.

**Route ordering matters.** `GET /forms/{reference_num}` in the public group and
`GET /forms/submissions/{reference_num}` in the admin group can collide if `submissions` is
ever accepted as a reference. Reference numbers match `^v[a-z0-9]{11}$`
(`'v' . strtolower(Str::random(11))`, `FormsController.php:53`) — constrain the parameter and
the ambiguity disappears:

```php
->where('reference_num', '^v[a-z0-9]{11}$')
```

Apply the same constraint to every `{reference_num}` in both groups. It also removes a class of
malformed-input handling from every controller method.

## 2.3 Site scoping — and where the tenant must *not* come from

`laravel_forms` already has `website_domain` and `website_reference_num`, indexed, and
**always NULL** because `createForm()` never writes them (**F3**).

There is a trap here worth naming explicitly. `WwwAfsConnectTenantFromRequest::tryFrom()` reads
`member_reference_num`, `website_reference_num`, `website_domain` and `subdomain` **from the
request body or query string**. That is fine for its purpose — telling afs-connect which tenant
an ingest belongs to — but it is **client-supplied input and cannot be an authorisation
boundary**. Scoping forms by a `website_reference_num` the caller sent is the same hole as not
scoping at all, wearing a lanyard.

So:

| Purpose | Source |
|---|---|
| **Authorisation** — which forms may this caller see? | The authenticated principal from `ApiAuthMiddleware` (`$request->attributes->get('auth_user')`), resolved to the sites that principal owns |
| **Stamping** — which site does a new form belong to? | `WwwAfsConnectTenantFromRequest::tryFromManagedDomain()`, which derives from the served domain, **intersected with** the principal's owned sites |
| **Ingest routing** | `WwwAfsConnectTenantFromRequest::resolve()` as today — unchanged, after authorisation has already passed |

`FormsSiteScope` middleware resolves the principal's site set once and puts it on the request.
Every admin query then filters on it, and the filter is applied by a **model scope**, not by
remembering:

```php
// app/Models/Externals/Form.php — AFS_FORMS_MODIFIER_ADDITION
public function scopeForSites($query, array $websiteReferenceNums)
{
    return $query->whereIn('website_reference_num', $websiteReferenceNums);
}
```

### Backfill

Every existing row has NULL scoping columns, so `whereIn` matches nothing and every tenant's
form list goes empty on deploy. That is the correct failure direction — closed, not open — but
it is still an outage. The migration in [14](14-rollout-and-testing.md) §14.3 backfills from
the only evidence available: the `BuilderOutput` row written by `publishForm()` (`typename =
'forms'`, `typeid = reference_num`), and where that is absent, the afs-connect ingest history.
**Rows that cannot be attributed are marked `website_reference_num = null` and surfaced in an
admin "unassigned forms" list** rather than being guessed at or deleted.

## 2.4 Ownership, not just authentication

**F4** and **F6** survive authentication on their own. Every single-record handler resolves
through the scope, never through a bare `where('reference_num', …)`:

```php
$form = Form::forSites($request->attributes->get('forms.site_refs'))
    ->where('reference_num', $reference_num)
    ->firstOrFail();          // 404, not 403 — see §2.12
```

For submissions, ownership is transitive — a submission belongs to the caller if its form does.
That is a join, and it is also the fix for the IDOR:

```php
$submission = FormSubmission::query()
    ->where('reference_num', $reference_num)      // NOT ->find($id)
    ->whereIn('form_reference_num', function ($q) use ($siteRefs) {
        $q->select('reference_num')->from('laravel_forms')
          ->whereIn('website_reference_num', $siteRefs);
    })
    ->firstOrFail();
```

**Keying on `reference_num` rather than `id` is half the fix by itself** (**F6**). The column
already exists, is unique, and is populated on every row — the routes simply never used it. An
11-character random reference is not enumerable the way `1, 2, 3` is.

`GET /forms/submissions/{id}` and `DELETE /forms/submissions/{id}` therefore change shape.
[12](12-externals-sdk.md) §12.6 covers the SDK impact; the admin UI calls
(`FormsViewsController.ts:173`, `:537`) change with them.

## 2.5 The missing method (F7)

`deleteForm` has to be written. It is not a one-liner, because a form with submissions must not
vanish:

```php
public function deleteForm(Request $request, string $reference_num)
{
    $form = /* scoped lookup, §2.4 */;

    $submissionCount = FormSubmission::where('form_reference_num', $form->reference_num)->count();

    if ($submissionCount > 0 && ! $request->boolean('confirm_delete_submissions')) {
        return response()->json([
            'success' => false,
            'code'    => 'FORM_HAS_SUBMISSIONS',
            'message' => "This form has {$submissionCount} submissions.",
            'submissions' => $submissionCount,
        ], 409);
    }

    $form->delete();     // soft delete — §4.2
    // Submissions are retained and become read-only. Purge is a separate,
    // explicit action: §2.6.
}
```

The admin UI must then check the response, which `deleteForm()` in
`FormsViewsController.ts:530` currently does not — it fires and refreshes regardless, which is
why **F7** has gone unnoticed.

## 2.6 Retention and purge

Soft delete keeps the data recoverable, which is right for an accident and wrong for a data
subject request. Two explicit operations, both authenticated, both audited:

| Operation | Effect |
|---|---|
| **Delete form** | Soft delete. Form disappears from the list, public fetch 404s, submissions retained and readable through an "archived forms" view |
| **Purge form** | Hard delete of the form, its versions, its submissions, its answers and its uploaded files. Requires typing the form name to confirm. Irreversible, and says so |
| **Delete submission** | Soft delete, 30-day window |
| **Purge submission** | Hard delete including its files. This is the GDPR-erasure path |

Purging must delete the **files** too ([04](04-database-schema.md) §4.6), which is only
possible once uploads are tracked in a table rather than living as opaque names in a JSON blob
(**F12**).

## 2.7 The public read endpoint

`GET /forms/{reference_num}` stays open — an embedded form on an anonymous visitor's browser
must fetch it. What it returns changes:

| Field | Public? |
|---|---|
| `reference_num`, `name` | yes |
| the **published version's** envelope (`form_data`, `form_style`, `use_custom_style`, `form_style_custom`) | yes |
| `version` number and `locale` list | yes |
| `id` (autoincrement) | **no** — it is the IDOR primitive; nothing public needs it |
| `website_domain`, `website_reference_num` | **no** |
| submission counts, timestamps, internal name/notes | **no** |
| **the draft** | **no** — §2.8 |

And it 404s — not 403, not an empty 200 — when the form is soft-deleted, unpublished, closed,
or past its close date ([09](09-publishing-and-versioning.md) §9.5).

### Draft preview without publishing

The editor's preview needs the draft, and the draft must never be public. Preview is served
from the **admin** route (`GET /forms/{ref}/admin`), which is authenticated. For sharing an
unpublished form with a colleague, [09](09-publishing-and-versioning.md) §9.7 specifies a
signed, expiring preview link — a token in the URL, not an open endpoint.

## 2.8 The public submit endpoint

The one route that must accept anonymous writes. Six layers, cheapest first:

**1. Throttle.** Per IP and per form: burst 5 / minute, sustained 30 / hour. Laravel's rate
limiter, a named limiter so the numbers are configurable per deployment. A tenant running a
conference registration will exceed the default; the limit is a setting, not a constant.

**2. Payload cap.** Reject bodies over a configured size (default 256 KB) before parsing.
Signature and file answers are references after [04](04-database-schema.md) §4.6, so a
legitimate body is small.

**3. Origin check.** The form's site is known (`website_domain`). Compare the `Origin` header
against it and against an allowlist of extra origins configured per form. **Log-only in the
first release** — the header is absent on some legitimate flows, and turning it into a hard
block before the data says what real traffic looks like will silently break tenants. Promote to
enforcing in P6 with per-form opt-out.

**4. Honeypot + timing.** The renderer injects a hidden field and a mount timestamp; a
submission that fills the field, or arrives under 2 seconds from mount, is accepted with a
`spam_score` and flagged rather than rejected. **Never reject silently** — a false positive
that discards a real response is worse than a flagged row an operator can review.

**5. Captcha, per form, optional.** Off by default. When on, verified server-side. Provider
choice is a tenant setting ([13](13-admin-dashboard.md) §13.6).

**6. Schema validation.** The submission is validated against the published version — required
questions, types, choice membership, validators — server-side
([06](06-logic-and-validation.md) §6.9). This is the layer that makes the other five
supplementary: a `curl` that clears every rate limit still cannot write a submission that the
form does not describe.

### Duplicate suppression

An idempotency key on the submit payload (generated at form mount, resent on retry) collapses
double-submits from a double-click or a retried request. Without it, the retry that follows a
timeout writes a second row and sends a second email.

## 2.9 Server-side side effects

**F9**–**F11** move. `POST /forms/submit` becomes the single place where a submission's
consequences happen:

```
submit
  ├── validate against published version        06 §6.9
  ├── write submission + answers                04 §4.4, §4.5
  ├── dispatch ProcessFormSubmission (queued)   11 §11.2
  │     ├── mail handlers  (server reads laravel_mail_handlers — F57)
  │     ├── admin notification
  │     ├── webhooks                            11 §11.5
  │     ├── afs-connect ingest                  11 §11.6  (F58)
  │     └── analytics                           11 §11.7
  └── return { reference_num, redirect?, message? }
```

The response no longer carries `submission_data` back to the caller — echoing a submission to
an anonymous client serves no purpose and is a needless disclosure if the reference is ever
guessable.

`mailHandlerApi.send()`, `notificationsApi.create()` and `analyticsApi.forms.completed()` are
**removed from `renderer.ts`**. The recipient of a confirmation email is then decided by the
tenant's mail-handler configuration, from data the server holds — not by a field in a payload
the browser composed.

## 2.10 The file pipeline

**F12** and **F13** are the widest hole after **F1**, because they are reached without any
forms endpoint at all.

| Today | Target |
|---|---|
| Browser picks `new_name` and `savedirectory` | Server picks both; the browser sends only bytes and the original filename |
| POST to legacy `/_portal_/admin/core/php/request.php` | `POST /portal/api/v1/forms/{ref}/upload`, scoped to the form, throttled with the submit endpoint |
| Any extension accepted | Extension and MIME validated against the question's `acceptedTypes`; a server-side allowlist bounds it regardless of what the form says |
| No size limit beyond the question's client-side `maxSize` | Enforced server-side from the question definition, with a deployment ceiling |
| Files readable by name, unauthenticated | Served through `GET /forms/files/{file_reference_num}`, which checks that the caller either owns the form or holds the submission's own reference |
| Deletable over **GET** | `DELETE`, authenticated, and only before the submission is finalised |

Uploads before submission are **orphans** until a submission claims them: written with a
`form_reference_num` and no `submission_reference_num`, and swept after 24 hours by a scheduled
job. Without the sweep, the upload endpoint is free anonymous storage.

`registerSurveyFileHandlers()` (`external/forms/fileHandlers.ts`) is rewritten against the new
endpoints. Its structure — `onUploadFiles`, `onClearFiles`, the `content` URL it hands back to
the runtime — is right and is kept; only the URLs and the parameter ownership change.

## 2.11 Escaping (F35, F36)

Three template interpolations, one of which is reachable by an anonymous attacker:

| Location | Value | Source |
|---|---|---|
| `FormsViewsController.ts:395` | `submission.email` | **public submit payload** |
| `FormsViewsController.ts:340` | `form.name` | authenticated tenant |
| `FormsEditorController.ts:1285` | `q.title` in an attribute | authenticated tenant |

The first is the one that matters: a `curl` to the public endpoint with an `email` containing
markup executes in the admin dashboard the next time an operator opens the submissions list.

The fix is not "escape these three". `escapeHtml()` already exists in the editor
(`:102`) and was applied to exactly one of the places that needed it, which is what
sequential-fixing produces. Instead:

- A shared `escapeHtml` / `attr` helper in `portal/admin/utils/helpers.ts`, used by both
  controllers.
- **Replace the `innerHTML = rows.map(...).join('')` table builders with DOM construction**
  (`textContent` for cells, `dataset` for attributes). The list bodies are the only places
  building HTML from records; they are also the only places records reach the DOM.
- A lint rule — or, at minimum, a review checklist item in
  [14](14-rollout-and-testing.md) §14.5 — that flags a template literal containing `${` inside
  an `innerHTML` assignment.

Also validate `email` server-side on submit and store it normalised. The column is `nullable|email`
in the validator today (`FormsController.php:218`) — which is correct, and which the XSS payload
would have failed, **had the value gone into that column**. It does not: `email` is populated
from `submissionData['email']` in the renderer, and the raw `submission_data` blob is what the
admin renders from. Escaping the display is the fix; validating the column is not a substitute.

## 2.12 Response shape and information leakage

A missing form and a form belonging to another tenant must be **indistinguishable**: both 404.
Returning 403 for the second confirms the reference exists, which turns an authenticated
account into a cross-tenant reference oracle.

Errors keep the ecosystem's existing shape (`{ success, error: { message, code } }`, as
`ApiAuthMiddleware` returns) and never include the query, the table, or the reference that was
searched for.

## 2.13 Acceptance

- No `/portal/api/v1/forms/*` route except `GET /{ref}` and `POST /submit` responds to an
  unauthenticated request with anything but 401.
- `GET /forms` as tenant A never returns a form owned by tenant B. Verified by a test that
  seeds two tenants.
- `GET /forms/{ref}/admin` for another tenant's form returns **404**, not 403.
- `GET /forms/submissions/{ref}` cannot be reached by incrementing an integer — the route no
  longer accepts one.
- `DELETE /forms/{ref}` returns 200 for an empty form, 409 with a count for a form with
  submissions, and 404 for another tenant's form. **It does not 500.**
- The public `GET /forms/{ref}` response contains no `id`, no `website_*`, no draft content,
  and no submission counts — asserted field-by-field, so a future addition to the admin
  projection cannot leak silently.
- An unpublished form 404s publicly and loads in the editor.
- 6 submissions in a minute from one IP: the sixth is throttled.
- A submission whose `email` contains `<img src=x onerror=…>` renders as text in the admin
  submissions list.
- A file uploaded through the form is **not** retrievable without either owning the form or
  holding the submission reference.
- An upload not claimed by a submission within 24 hours is gone.
- Submitting the same idempotency key twice writes one row and sends one email.
- `AFS_FORMS_MODIFIER_ADDITION` is registered in [`../../markers.md`](../../markers.md) and
  present on every file changed by this phase.
