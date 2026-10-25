# 12 — The Externals SDK

`resources/js/external/forms/` — `api.ts`, `renderer.ts`, `fileHandlers.ts`, `index.ts`.
Surfaced as `window.Portal.forms` (`portal.d.ts:1428`).

## 12.1 What exists, and what is right about it

346 lines, and the best-written part of the module.

- `ExternalFormsAPI` — `init()` and `submit()`, typed, with a shared response handler.
- `renderForm()` — mounts the **real runtime**, applies the theme, handles the envelope, falls
  back sensibly when `json_data` is a bare definition, dispatches a
  `portal:form:submitted` DOM event, and calls back through `onSubmit` / `onError`.
- `registerSurveyFileHandlers()` — `onUploadFiles` / `onClearFiles` wired correctly against the
  runtime's contract.
- `PortalFormsAPI` declared in `portal.d.ts:164–169`.

The respondent's experience is the soundest thing the module has. What is wrong is **where the
work happens**, not how it is written.

## 12.2 What changes

| Change | Because |
|---|---|
| `init()` returns the **published version**, not the draft | [09](09-publishing-and-versioning.md) §9.3 |
| The response drops `id` | It is the IDOR primitive; `PortalFormsInitResponse.form.id` (`portal.d.ts:137`) is currently public ([02](02-security-and-tenancy.md) §2.7) |
| **`mailHandlerApi.send()` removed** from `renderer.ts:103` | [11](11-integrations-and-notifications.md) §11.3 — this is the open relay |
| **`notificationsApi.create()` removed** from `:83` | §11.4 |
| **`analyticsApi.forms.completed()` removed** from `:118` | §11.7 — `loaded` at `:130` stays |
| `submit()` payload gains `idempotency_key`, `locale`, `duration_ms`, honeypot fields | [02](02-security-and-tenancy.md) §2.8 |
| `submit()` drops the client-chosen `email` | Server-derived from `settings.identity.email_question` ([04](04-database-schema.md) §4.4.1) |
| `submit()` response drops `submission_data` and `id` | [10](10-submissions-and-data.md) §10.2 |
| `submit()` handles **422** with per-question errors | [06](06-logic-and-validation.md) §6.9.4 |
| File handlers repoint at the new endpoints | [02](02-security-and-tenancy.md) §2.10 |

Removing four client-side integration calls makes `renderer.ts` substantially shorter and
`onComplete` a single `await`. The work does not disappear; it moves to
`ProcessFormSubmission` ([11](11-integrations-and-notifications.md) §11.2), where it can be
retried, logged and trusted.

## 12.3 The target surface

```ts
interface PortalFormsAPI {
    init(reference_num: string, options?: PortalFormsInitOptions): Promise<PortalFormsInitResponse>;
    submit(payload: PortalFormsSubmitPayload): Promise<PortalFormsSubmitResponse>;
    renderForm(container: HTMLElement, form: PortalFormsForm, options?: PortalFormsRenderOptions): PortalFormsInstance;
    initAndRender(container: HTMLElement, reference_num: string, options?: PortalFormsRenderOptions): Promise<PortalFormsInstance>;

    /** Resume a partial response from a token. 10 §10.5 */
    resume(token: string, container: HTMLElement, options?: PortalFormsRenderOptions): Promise<PortalFormsInstance>;
    /** Locales a published form offers. 08 §8.5 */
    locales(reference_num: string): Promise<string[]>;
}

interface PortalFormsRenderOptions {
    onSubmit?:      (result: PortalFormsSubmitResponse) => void;
    onError?:       (err: Error) => void;
    onValidationError?: (errors: Record<string, string[]>) => void;   // 06 §6.9.4
    onPageChange?:  (pageNo: number, pageCount: number) => void;
    onValueChanged?:(name: string, value: unknown) => void;

    locale?:        string;                      // 08 §8.6
    prefill?:       Record<string, unknown>;     // 09 §9.6.1 — allowlisted server-side
    theme?:         'form' | 'inherit';
    readOnly?:      boolean;
    startPage?:     number;
    previewToken?:  string;                      // 09 §9.7
}

interface PortalFormsInstance {
    readonly model: unknown;          // the SurveyJS model, for advanced hosts
    setLocale(locale: string): void;
    setValue(name: string, value: unknown): void;
    getData(): Record<string, unknown>;
    destroy(): void;                  // 12 §12.4
}
```

Three additions worth their weight:

- **`destroy()`.** `renderForm()` today mounts and never unmounts. A single-page host that
  navigates away leaks the model, its listeners and its timers. Any SDK that can mount must be
  able to unmount.
- **The returned instance.** Today `renderForm` returns `void`, so a host can neither set a
  value, switch locale, nor read the data. `initAndRender` returning the instance is what makes
  the SDK composable.
- **`onValidationError`.** Server-side validation ([06](06-logic-and-validation.md) §6.9) means
  a submit can now fail for reasons the client did not predict, and the host needs to know.

`renderForm()` returning an instance instead of `void` is a **breaking change** to a declared
contract — §12.6.

## 12.4 Events

The existing `portal:form:submitted` DOM event is kept and joined by a small set, all bubbling
from the container, all carrying `reference_num`:

| Event | When |
|---|---|
| `portal:form:loaded` | Definition fetched and rendered |
| `portal:form:page` | Page changed — `{ from, to, pageCount }` |
| `portal:form:validation-error` | Validation failed, client or server |
| `portal:form:submitted` | Submission accepted — **existing, unchanged shape** |
| `portal:form:error` | Any failure |
| `portal:form:destroyed` | `destroy()` completed |

DOM events matter more than callbacks in this context: a tenant embedding a form usually cannot
edit the JS that mounted it, but they can always add a listener — which is how analytics and
conversion tracking get attached on a real site.

## 12.5 The proxy endpoints

Two features need a server-side fetch rather than a browser fetch:

| Feature | Why the browser cannot |
|---|---|
| `choicesByUrl` ([05](05-form-builder.md) §5.6.3) | The endpoint must be public and CORS-enabled, which most tenant APIs are not |
| Remote validation ([06](06-logic-and-validation.md) §6.4.3) | Same, plus it usually needs a credential that must not reach the page |

`POST /portal/api/v1/forms/{ref}/proxy/{binding}` executes a **binding configured at authoring
time** — a named, stored URL with its own credential — rather than a URL supplied at call time.
An open proxy that fetches whatever a caller names is an SSRF endpoint with extra steps.

Bindings are validated on save (`https` only, no private ranges, DNS re-resolved at call time),
rate-limited per form, and their responses cached briefly.

## 12.6 `portal.d.ts`

**`portal.d.ts` is the contract, and it is hand-maintained.** Consuming projects never import
the externals TypeScript source; they take a **copy** of `portal.d.ts` (the established location
is `<app>/resources/js/checkpointHelper/static/portal.d.ts`) and consume the API at runtime
through `window.Portal.*`. A method added to the SDK and not declared in `portal.d.ts` is
invisible to every consumer's build.

So: **every change in this document lands in `portal.d.ts` in the same pass**, and the copies in
consuming projects are refreshed as part of shipping.

### The breaking changes, stated plainly

| Declared today | Becomes | Impact |
|---|---|---|
| `PortalFormsInitResponse.form.id: number` (`:137`) | **removed** | A consumer reading `.id` breaks — deliberately; it is the IDOR primitive |
| `PortalFormsSubmitPayload.email?: string` (`:150`) | **removed** | Server-derived |
| `PortalFormsSubmitResponse.submission.submission_data` (`:157`) | **removed** | No echo |
| `PortalFormsSubmitResponse.submission.id` (`:155`) | **removed** | Use `reference_num` |
| `renderForm(...): void` (`:167`) | `: PortalFormsInstance` | Widening a return type; existing callers are unaffected at runtime |
| `init(reference_num)` (`:165`) | `init(reference_num, options?)` | Additive |

Handling:

1. Add the new shape and mark the removed fields `@deprecated` in `portal.d.ts`, keeping them
   optional for one release.
2. Ship the server change that stops populating them.
3. Remove the declarations in the following release.
4. Refresh every consuming copy in step 1, not step 3 — a consumer whose types still promise
   `id` will keep being written against it.

## 12.7 The embed

What a tenant pastes ([09](09-publishing-and-versioning.md) §9.6):

```html
<div id="afs-form-va1b2c3d4e5f"></div>
<script src="https://{site}/external/portal.js"></script>
<script>
  Portal.forms.initAndRender(
    document.getElementById('afs-form-va1b2c3d4e5f'),
    'va1b2c3d4e5f',
    { locale: 'auto' }
  );
</script>
```

The snippet is generated by the share dialog with the reference filled in, so a tenant copies
rather than composes.

**A CSP note belongs in the embed documentation.** The form loads the SurveyJS bundle and its
CSS, applies inline styles from the theme, and posts to the portal origin. A host with a strict
CSP will block some of that, and the iframe embed
([07](07-theming-and-layout.md) §7.8) is the answer — better to say so up front than to have a
tenant debug a blank div.

## 12.8 The runtime dependency

The SDK declares `Survey`, `SurveyUI` and `SurveyTheme` as globals and checks for them before
rendering (`renderer.ts:30–33`), logging a clear error if absent. That is the right shape and
it stays. Three points to firm up:

- **Version pinning.** The bundle is v2.5.20 (`survey.core.min.js` header). Upgrading it changes
  behaviour for every published form at once. The version belongs in the deploy notes, and an
  upgrade belongs in its own release with the property-verification pass from
  [14](14-rollout-and-testing.md) §14.6 rerun.
- **Load failure is respondent-visible.** If the globals are missing, the container should show
  a human message, not an empty div and a console line.
- **Do not bundle a second copy.** The admin editor and the externals SDK both use the globals
  from `public/assets/libs/surveyjs/`. `imp_forms_main.ts` imports from the npm `survey-core`
  package instead (**F49**) — the one place a second runtime nearly entered the build, and one
  more reason to delete it ([05](05-form-builder.md) §5.12).

## 12.9 Acceptance

- `Portal.forms.initAndRender()` renders a published form on a third-party page.
- An **unpublished** form returns 404 from `init()` and the container shows a human message.
- `init()`'s response contains no `id`, no `website_*`, and no draft content.
- Closing the tab immediately after submit still triggers every server-side effect
  ([11](11-integrations-and-notifications.md) §11.11).
- `renderer.ts` contains no call to `mailHandlerApi`, `notificationsApi`, or
  `analyticsApi.forms.completed`.
- A 422 from submit surfaces per-question errors inline and fires
  `portal:form:validation-error`.
- `destroy()` removes the model, its listeners and its DOM, and fires
  `portal:form:destroyed`.
- `setLocale('fr')` switches language and preserves entered answers.
- `prefill` populates only allowlisted questions.
- A file uploaded through the SDK lands in `laravel_form_files` with a **server-chosen** name.
- A `choicesByUrl` binding resolves through the proxy; an arbitrary URL cannot be proxied.
- `portal.d.ts` declares every new method and option, and the consuming copies are refreshed in
  the same release.
- Removed fields are `@deprecated` for one release before deletion.
