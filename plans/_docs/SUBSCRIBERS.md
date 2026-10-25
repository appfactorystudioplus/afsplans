# Subscribers Module

This document describes the new Subscribers module implementation, including the database tables, admin UI flow, external API, and publish workflow.

## 1. Database Tables

A new migration was added to create the Subscribers module tables with the `laravel_` prefix:

- `laravel_subscriber_categories`
  - `id`
  - `name`
  - `description`
  - `active`
  - `created_at`
  - `updated_at`

- `laravel_subscribers`
  - `id`
  - `email`
  - `name`
  - `phone`
  - `active`
  - `created_at`
  - `updated_at`

- `laravel_subscriber_category_subscriber`
  - `id`
  - `subscriber_id`
  - `category_id`
  - `created_at`
  - `updated_at`

- `laravel_subscriber_articles`
  - `id`
  - `title`
  - `subject`
  - `content`
  - `category_id`
  - `status`
  - `emails_sent`
  - `published_page_html`
  - `published_at`
  - `created_at`
  - `updated_at`

## 2. Laravel Models

New Eloquent models were added:

- `app/Models/SubscriberCategory.php`
- `app/Models/Externals/Subscriber.php`
- `app/Models/SubscriberArticle.php`

These models support relationships and preserve the `laravel_` table prefix used by the application.

## 3. Backend API

A new controller was added:

- `app/Http/Controllers/Externals/Subscribers/SubscribersController.php`

New API routes were added in `routes/portal.php`:

- `GET /portal/api/v1/subscribers/categories` — list categories
- `POST /portal/api/v1/subscribers/categories` — create/update category
- `DELETE /portal/api/v1/subscribers/categories/{category_id}` — delete category
- `GET /portal/api/v1/subscribers` — list subscribers
- `POST /portal/api/v1/subscribers` — create/update subscriber
- `DELETE /portal/api/v1/subscribers/{subscriber_id}` — delete subscriber
- `POST /portal/api/v1/subscribers/subscribe` — public website subscriber signup
- `GET /portal/api/v1/subscribers/articles` — list subscriber articles
- `POST /portal/api/v1/subscribers/articles` — create article
- `PUT /portal/api/v1/subscribers/articles/{id}` — update article
- `POST /portal/api/v1/subscribers/articles/{id}/publish` — publish article
- `POST /portal/api/v1/subscribers/output` — save builder HTML output
- `GET /portal/api/v1/subscribers/output/{reference_num}` — load saved builder HTML
- `GET /portal/subscribers/articles/{id}/page` — render published article website page

## 4. Background Email Delivery

A queued job was created in:

- `app/Jobs/SendSubscriberEmails.php`

The job groups active subscriber emails by top-level domain (TLD), then sends one email per TLD in each round before waiting 2 seconds and continuing.

The actual outbound mail sending is intentionally left as a stub in `SendSubscriberEmails::handle()` so the implementation can be added later.

## 5. Admin UI Updates

Updated admin subscribers view at `resources/js/portal/admin/html/views/subscribers.html` and `resources/js/portal/admin/views/subscribers.ts`:

- Added phone field to subscriber creation/edit forms
- Added Builder tab with style selection, category selection, field display toggles, and iframe preview
- Category edit now opens a full `view.newSubView` route instead of a modal dialog
- Category detail view includes:
  - category name/description editing
  - subscriber list for the category
  - article list for the category
  - add/update subscriber support
  - create article support
- Article authoring now opens in a new `view.newSubView` page with:
  - title field
  - subject field
  - rich text editor using `Utilities.CreateEditor(app, '#editorContainer', function(editor) { ... })`
  - editor content access via `editor.getContent()` and `editor.setContent(content)`
- Publish flow opens a review subview with iframe preview and two publish options

## 6. External API Client

Created the external subscriber client library under:

- `resources/js/external/subscribers/api.ts`
- `resources/js/external/subscribers/index.ts`

This exposes a reusable browser client:

- `getCategories()` — fetch categories for a website signup form
- `subscribe(payload)` — submit a visitor subscription with `email`, `name`, `phone`, and category selection

The client also registers `window.portalSubscribersApi` for global access in published frontend environments.

## 7. Notes

- The builder output is saved to `laravel_builder_output` with `typename = 'subscribers'` and `typeaction = 1`.
- Email sending is queued and grouped by TLD; actual SMTP or mail transport must be implemented in the job stub.
- The new article publish page supports both direct email publishing and website link publishing.
