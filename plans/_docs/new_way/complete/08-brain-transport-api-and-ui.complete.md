# 08 — Members to Brain: transport, API contracts and screens: completion report (website deployment)

> **Split 2026-10-23.** This completion report covered a step that served both features. It is now two reports: this file is the **website deployment** half; the **domain name management** half is [../../domain_name_management/complete/08-brain-transport-api-and-ui.complete.md](../../domain_name_management/complete/08-brain-transport-api-and-ui.complete.md). Numbers (§, F, D, E) are unchanged.

> **Status: built and tested on the server side (the Brain's signed routes and the members pass-through), and written and type-checked on the browser side; the screens have not been driven in a browser. The members routes are behind a flag that is OFF, so nothing the owner uses today changes.**
> Carried out 2026-10-09 against [08-brain-transport-api-and-ui.md](../08-brain-transport-api-and-ui.md). Nothing is committed. Code is in `w/www` (the Brain) and `w/members`.
>
> **Update 2026-10-09:** the members switch `DOMAINS_USE_BRAIN_SURFACE` described below was replaced by a per-member Brain setting (`new_flow.*`, `complete/00_extras.md` E9). Where this report says "the flag", read "the Brain's answer for that member".
>
> This document says what was actually built, including where it differs from the plan. For questions about current behaviour it supersedes the plan file.

## 1. What the step delivers

Members stops being the place where domain logic lives. Every domains call goes to the Brain over the **signed surface** (`POST /api/internal/brain/domains/…`, `brain.only` then `brain.surface:MEMBERS`); the Brain names the member from the call, checks that **every claim, purchase and website named belongs to that member** (a 404, never a 403, and the same message for "not yours" and "does not exist"), and answers one envelope. The browser never supplies a member, a price or a "paid" flag.

| Plan section | Built as |
|---|---|
| §8.3 Brain routes (websites) | `/{claim}/websites` (202), `…/status`, `…/retry`, `…/cancel`, `…/enable`, `…/disable`, `…/delete` — the last six of the 19 routes; the others, the channel, the envelope and the error codes are in the domain-name report |
| §8.4 members routes (websites) | `DomainSurfaceController` forwards the website routes and **upserts the `members_websites` mirror** when a deployment is accepted and on every list (heals a crash between the Brain's 202 and the write; marks the row inactive when the Brain no longer reports the website); a mirror failure never fails the list |
| §8.6 screens | see §3 — a second code path in the existing screens, switched on by the backend's own answer |
| §8.7 staff | **not built** (P1; see §4) |

## 2. Files

The code is shared with the domain-name half (one controller on each side serves both): see [../../domain_name_management/complete/08-brain-transport-api-and-ui.complete.md](../../domain_name_management/complete/08-brain-transport-api-and-ui.complete.md) §2. The website-specific parts: `resources/js/controllers/domains/website.ts` and `surfaceViews.ts` (the website screen), `resources/js/templates/views/domain_website.html` (one container added), the website cases of `DomainsSurfaceTest` and `DomainSurfaceControllerTest`.

## 3. How the screens change, and how both backends coexist

The owner is working on the old shell pipeline, and `createWebsite()` and the old routes are what the old screens call, so **replacing them was not an option before the cut-over (step 10)**. Instead:

- `DOMAINS_USE_BRAIN_SURFACE` (members `.env`, default off) chooses which group of routes `/api/v1/domains` serves: the old `DomainController` (unchanged) or the new pass-through.
- `GET /api/v1/domains` from the new group answers with `api: "brain-surface"`. The screens read that field once, in `loadDomains`: when present, `controller.surfaceMode` is on and each screen hands over to `SurfaceViews`; when absent, **every old code path runs exactly as before**. One build of the UI serves both.

What the surface-mode screens do (08 §8.6):

| Screen | Surface mode |
|---|---|
| website | **Create** starts a deployment (a fresh key per click) and shows a progress panel computed from the stored steps, with the step names, warnings (for example "pending DNS"), and **Retry / Cancel** on a failure; when done, the manage section shows the real sites name with working **Open** buttons (the placeholder `xxx.site…` and the commented-out `window.open` are not used in this mode) and Enable/Disable and Delete call the real endpoints; per-host toggles are disabled (later work) |

## 4. Where the build differs from the plan text

1\. **A flag, default off.** The plan says to delete the members provisioning half "behind a feature flag (old path stays until cut-over)". The old controller, its routes and `core/scripts` are **untouched**; the new routes replace them only when the flag is on. Nothing was deleted.
2\. **No progress-widget proxy.** The plan wants `renderProgressWidget` to poll a members endpoint that proxies `/websites/status`, "to confirm in step 10 P0: the exact URL the shared client calls". I did not touch `ProgressController` or the shared client; surface mode renders its own panel and polls `GET /api/v1/domains/{ref}/website`. The old widget and `/api/v1/progress/*` still serve the old path.
4\. **The staff surface (§8.7) is not built** — the contested list, force-verify, transfer, force-lapse, publish-job retry, the deployment detail with the selection explanation, and the kill switches as endpoints. The services behind most of them exist (`contested()`, `transfer()`, `suspend()`, `DnsPublisher::retry()`, `DeploymentService`, the `selection` stored on every deployment); the three kill switches are config (`claims_enabled`, `purchases_enabled`, `deploys_enabled`) with no endpoint to flip them.
5\. **Quota reporting from members is not built.** The plan has members report `consume`/`release` "to the allocation client per feature 1"; there is no allocation client in the members repository (`app/Services/Allocation` is empty). The Brain side has the `DeployQuota` seam (step 07).
7\. **Brain→members push events (P2)** and **Flutter staff screens (P2)**: not built.

## 5. Verification

The tests of this step cover both features in the same files (`DomainsSurfaceTest`, `DomainSurfaceControllerTest`, `surface.test.ts`) and are recorded in the domain-name report §5: [../../domain_name_management/complete/08-brain-transport-api-and-ui.complete.md](../../domain_name_management/complete/08-brain-transport-api-and-ui.complete.md).

## 6. Not verified, honestly

- **No screen was run in a browser.** The compiled-in logic is type-checked and its pure rules are unit-tested, but the DOM code in `surfaceViews.ts` (rendering, clicks, polling, the modals) and the new template were not exercised. The project's rule is that the Vite dev server is never started, and no build was made; the first real look is the owner's.
- **The members side with a real Brain.** The two halves were tested separately (the members test fakes the Brain; the www test calls the routes with a hand-signed request). They were not run against each other over HTTP.
- **`checkpoint.verify`** is not exercised by the members test (the controller is called with the token's attributes already set).
- **The `members_websites` table** was created in the shared lab test database by the members test where it did not exist (a guarded `Schema::create`, as the www tests do); the real table's columns were not compared with it.

## 7. To do next, in order

1\. **Open the screens in a browser** with `DOMAINS_USE_BRAIN_SURFACE=true` on a lab members and `WWW_BRAIN_SURFACE_URL` / `_KEY` set (they exist in `.env` already) and look at: the list, add-existing → verify, search → purchase (needs the Direct Payments product), the DNS tab, the website screen. Report what is wrong; the pure rules are tested, the layout is not.
2\. **Staff endpoints** (§8.7) and their Flutter screens (P2).
4\. **The cut-over (step 10):** turn the flag on, move `createWebsite()` and the selection off `members`, retire `/api/v1/progress/record`, and delete the old provisioning half of `DomainController` (≈ 900 lines) and the unused scripts.
5\. **Quota reporting** once the allocation client exists on the members side (feature 1).
