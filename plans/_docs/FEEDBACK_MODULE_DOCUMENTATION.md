# Feedback Module Documentation

## Overview

This document describes the feedback module implemented in the portal admin and external client.
The feature adds a configurable feedback form builder, saves the generated form HTML/CSS/JavaScript into `laravel_builder_output`, and includes an external feedback API that submits feedback and updates the notification system.

---

## Files Added / Updated

### Admin feedback builder

- `resources/js/portal/admin/views/feedback.ts`
  - Builds the feedback form configuration UI.
  - Supports toggles for `name`, `email`, and `phone` fields.
  - Supports making each included field required.
  - Renders a live preview of the form in a preview pane.
  - Saves the generated form output to the backend as JSON containing `reference_num` and the combined HTML.
  - Generates a `feedback` response builder link using `reference_num`.

- `resources/js/portal/admin/html/views/feedback.html`
  - Contains the admin page shell for feedback configuration.
  - Hosts configuration controls and preview container.

### External feedback API

- `resources/js/external/feedback/api.ts`
  - Defines `ExternalFeedbackAPI`.
  - Implements `submit(payload)` to send feedback to `/portal/api/v1/feedback/submit`.
  - Creates a notification after successful submission using `notificationsApi.create(...)`.
  - Exposes `window.portalFeedbackApi` for embedded form scripts.

- `resources/js/external/feedback/index.ts`
  - Re-exports the API types and instance.

- `resources/js/external/main.ts`
  - Imports `./feedback` to include the external feedback client in the external bundle.

### Backend storage and API

- `app/Http/Controllers/Externals/Feedback/FeedbackController.php`
  - `getFeedbackOutput(reference_num)` retrieves saved builder HTML from `laravel_builder_output`.
  - `saveFeedbackOutput()` persists form output in `laravel_builder_output` with `typename = 'feedback'` and `typeaction = 1`.
  - `submitFeedback()` receives public feedback submissions and returns a simple success response.

- `routes/portal.php`
  - Registers backend API routes:
    - `GET /portal/api/v1/feedback/output/{reference_num}`
    - `POST /portal/api/v1/feedback/output`
    - `POST /portal/api/v1/feedback/submit`

---

## Behavior and Data Flow

### Admin builder flow

1. Administrator visits the feedback admin page.
2. `initFeedbackView()` reads the saved reference number from local storage or generates a new one.
3. The admin configures fields:
   - include name
   - require name
   - include email
   - require email
   - include phone
   - require phone
4. The preview button renders the generated form HTML in the preview pane.
5. The save button sends the generated HTML to `/portal/api/v1/feedback/output`.
6. The backend saves the content in `laravel_builder_output` with `typename = 'feedback'` and `typeaction = 1`.
7. A response-builder link is updated with the saved `reference_num`.

### External submit flow

1. Rendered feedback form HTML includes inline JavaScript that handles form submission.
2. On submit, the inline script calls `window.portalFeedbackApi.submit(payload)` if available.
3. `ExternalFeedbackAPI.submit()` sends payload to `/portal/api/v1/feedback/submit`.
4. After a successful response, it creates a notification through the existing external notifications API.

---

## Storage contract

Saved builder output uses the existing `laravel_builder_output` table with the following fields:

- `html`: saved HTML/CSS/JS of the feedback form
- `typename`: `feedback`
- `typeaction`: `1`
- `typeid`: the feedback `reference_num`

---

## Key implementation notes

- The admin builder serializes the full form presentation as a single HTML string.
- The external API is designed to work with published frontend pages by defining a global `window.portalFeedbackApi` object.
- The notification update is performed after feedback submission, and failure to create a notification does not stop the feedback submission flow.
- The frontend admin view is intentionally rendered with inline script injection so the previewed HTML is executable in the preview container.

---

## Verification

- `resources/js/portal/admin/views/feedback.ts` should compile without TypeScript errors.
- `resources/js/external/feedback/api.ts` should compile under `resources/js/external/tsconfig.external.json`.
- `app/Http/Controllers/Externals/Feedback/FeedbackController.php` should pass PHP syntax checks.
- Saving a feedback form should create or update a row in `laravel_builder_output` with `typename = 'feedback'` and `typeaction = 1`.
- Submitting feedback through the public script should hit `/portal/api/v1/feedback/submit` and then call `notificationsApi.create(...)`.

---

## Files to review for this feature

- `resources/js/portal/admin/views/feedback.ts`
- `resources/js/portal/admin/html/views/feedback.html`
- `resources/js/external/feedback/api.ts`
- `resources/js/external/feedback/index.ts`
- `resources/js/external/main.ts`
- `app/Http/Controllers/Externals/Feedback/FeedbackController.php`
- `routes/portal.php`

---

## External Feedback API Usage Example

Use the external feedback API from published frontend code like this:

```ts
import { feedbackApi, ExternalFeedbackAPI } from '@/external/feedback';

async function submitFeedback() {
  try {
    const response = await feedbackApi.submit({
      reference_num: 'feedback_abc123',
      name: 'Jane Doe',
      email: 'jane@example.com',
      phone: '(555) 123-4567',
      message: 'I love the site!'
    });

    console.log('Feedback submitted', response);
  } catch (err) {
    console.error('Failed to submit feedback', err);
  }
}
```

Or, if the form is rendered on the page with the external bundle included, use the global helper:

```js
window.portalFeedbackApi?.submit({
  reference_num: 'feedback_abc123',
  name: 'Jane Doe',
  email: 'jane@example.com',
  phone: '(555) 123-4567',
  message: 'The form works great!'
});
```

This will:

- POST the data to `/portal/api/v1/feedback/submit`
- create a notification through `notificationsApi` if submission succeeds

---
