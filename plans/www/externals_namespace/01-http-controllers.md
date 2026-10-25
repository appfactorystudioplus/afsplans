# 01 — Externals namespace: the HTTP controllers

Status: **done 2026-10-09 (both rounds)** (see `complete/01-http-controllers.complete.md`). Repository `w/www`. Step [00](00-overview.md) moved the services, support classes and models; this step moves the controllers that serve the client-side SDK.

## 1. The rule

The owner's instruction: *anything in `resources/js/external/api` — move the PHP routes to `app/Http/Controllers/Externals`.* The SDK areas (`resources/js/external/api/<area>`) were matched to the routes they call, and each route's controller was found with `php artisan route:list`.

A controller moves when the SDK calls a route it serves **or** when it belongs to an SDK area whose services and models already moved in step 00 (so a feature is not split between `Externals` and the application folders). That second half is why whole folders moved: `DirectPayments`, `Processor`, `SiteUsers`, `SystemsUsers`, `Sponsorships` and the per-module storefront folders.

## 2. What moves (64 files, in two rounds)

Round 1 (48 files) followed the rule above. Round 2 (16 files) was added by the owner: the dashboard controllers for the same modules and the chat controllers.

| From (`app/Http/Controllers/…`) | To (`…/Externals/…`) |
|---|---|
| `DirectPayments/*` (6) | `DirectPayments/` |
| `Processor/*` (15, incl. the webhook, money and charges controllers) | `Processor/` |
| `SiteUsers/*` (5, incl. the admin controller) | `SiteUsers/` |
| `SystemsUsers/*` (2) | `SystemsUsers/` |
| `Sponsorships/*` (3) | `Sponsorships/` |
| `Portal/Ecommerce/*` (5) | `Ecommerce/` |
| `Portal/Events/*` (1) | `Events/` |
| `Portal/Appointments/*` (1) | `Appointments/` |
| `Portal/Forms/*` (2) | `Forms/` |
| `Portal/Admin/FormsController` | `Forms/` |
| `Portal/Admin/DocumentController` | `Documents/` |
| `Portal/Admin/FeedbackController` | `Feedback/` |
| `Portal/Admin/ListingsController` | `Listings/` |
| `Portal/Admin/SubscribersController` | `Subscribers/` |
| `Portal/Api/AnalyticsApiController` | `Analytics/` |
| `Portal/Api/MailHandlerApiController` | `MailHandler/` |
| `Portal/Api/NotificationApiController` | `Notifications/` |
| **Round 2:** `Portal/Admin/EventController` | `Events/` |
| **Round 2:** `Portal/Admin/EcommerceController`, `EcommerceSettingsController`, `EcommercePromotionsController` | `Ecommerce/` |
| **Round 2:** `Portal/Admin/AppointmentsController` | `Appointments/` |
| **Round 2:** `Portal/Admin/Concerns/*` (3 traits: `AppointmentsAuthorizesSites`, `EcommerceAuthorizesSites`, `EventsAuthorizesSites`) | `Concerns/` |
| **Round 2:** `Portal/Chat/*` (6, plus `sse/` 2) | `Chat/` (and `Chat/sse/`) |

**Left where they are, on purpose:**
- `Brain/*`. The SDK's `brain` client calls `/portal/api/v1/brain/member`, but the Brain controllers are the platform's own surface (members, devices, infrastructure), not a resource a developer configures for their website.
- The remaining `Portal/Admin/*` dashboard controllers (admin dashboard, builder, email account/provider, membership, SendGrid domain auth): they are the application's own admin screens. After round 2 these six files are all that is left in `Portal/Admin/`.
- `MailHandlerController` (root), `Portal/Api/SettingsController`, `Portal/Mail/*`.

Some moved controllers serve both SDK routes and dashboard routes (for example `SiteUsersPublicController`, `SubscribersController`); a controller is not split, so it moves whole.

## 3. How it was checked

Same tool and checks as step 00, plus one that fits controllers: **`php artisan route:list` was captured before and after and compared entry by entry** (domain, method, URI, name, controller@method, middleware): identical, 730 routes. 349 routes now point at an `Externals` controller and nothing else changed.
