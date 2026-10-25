# Analytics Module Implementation

This document describes the analytics module that was added to the app, including database schema, REST endpoints, external API client, and sample usage.

## 1. New database table

A new migration was created at:

- `database/migrations/2026_04_26_000001_create_laravel_analytics_tables.php`

It creates a prefixed analytics table:

- `laravel_analytics_events`

### Table columns

- `id` — primary key
- `module` — analytics module name, e.g. `forms` or `subscribers`
- `event` — event type, e.g. `page_load`, `form_loaded`, `form_completed`, `subscriber_signup`
- `resource_name` — human-readable module resource name such as a form name or list name
- `resource_reference` — optional internal reference number
- `route` — URL route captured when the event occurred
- `metadata` — optional JSON payload for additional dimensions
- `created_at` / `updated_at`

This schema is intentionally generic so the analytics module can scale across multiple portal modules.

## 2. Laravel model

A new Eloquent model was created at:

- `app/Models/AnalyticsEvent.php`

The model is configured to use `laravel_analytics_events` and automatically casts `metadata` to an array.

## 3. API controller and routes

A new controller was added at:

- `app/Http/Controllers/Externals/Analytics/AnalyticsApiController.php`

The controller exposes two endpoints under the external portal API:

- `POST /portal/api/v1/analytics/events` — record an analytics event
- `GET /portal/api/v1/analytics/events` — list recent analytics events (filters: `module`, `event`, `resource_name`, `resource_reference`)

The route definitions were integrated into `routes/portal.php`.

## 4. External analytics client

A new external API client was created under:

- `resources/js/external/analytics/api.ts`
- `resources/js/external/analytics/index.ts`

The client exposes:

- `analyticsApi.initPageLoad()` — initialize and count a page load, capturing the current route
- `analyticsApi.trackEvent(...)` — send a generic event payload
- `analyticsApi.trackModuleEvent(...)` — send module-specific analytics events
- `analyticsApi.forms.loaded(...)` — record a form load event
- `analyticsApi.forms.completed(...)` — record form completion
- `analyticsApi.subscribers.pageLoad(...)` — record a subscriber page load event
- `analyticsApi.subscribers.signup(...)` — record a subscriber signup event

A global browser helper is also exposed as `window.portalAnalyticsApi` for non-module consumers.

## 5. Portal namespace integration

The new analytics API was added to the shared Portal object in:

- `resources/js/external/main.ts`

This means external websites can now access analytics through:

```js
window.Portal.analytics
```

## 6. Example usage

A sample usage file was added at:

- `resources/js/external/samples/analytics-example.js`

It demonstrates how to:

1. initialize page load tracking
2. emit forms module events for load and completion
3. emit subscribers module page loads and signups

## 7. Design decisions

- The analytics backend is intentionally generic and module-aware.
- The `module` field allows future modules to be added without schema changes.
- The `route` field ensures every page load is recorded with its path.
- `resource_name` and `resource_reference` allow form/module names and IDs to be stored.
- The external client provides both generic event tracking and helper methods for the first two modules: `forms` and `subscribers`.

## 8. Notes

- The `resources/js/external/samples/` folder is excluded from the external TypeScript build, so sample files can be edited independently.
- The `public` folder was intentionally ignored, as requested.
