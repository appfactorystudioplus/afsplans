# Forms Module Implementation

This file documents the new Forms module implementation added to the portal admin and external client.

## Summary

Implemented:
- Admin UI views for form management in `resources/js/portal/admin/views/forms.ts`
- Backend database tables for forms and form submissions using Laravel migrations
- Admin API controller for forms in `app/Http/Controllers/Externals/Forms/FormsController.php`
- Laravel Eloquent models for form data and submissions
- External forms API in `resources/js/external/forms/api.ts`
- External build integration via `resources/js/external/main.ts`
- `tsconfig.json` path exclusions for `public/_portal_` and `resources/js/portal/admin/afscore`

## Files added

- `app/Models/Externals/Form.php`
- `app/Models/Externals/FormSubmission.php`
- `app/Http/Controllers/Externals/Forms/FormsController.php`
- `database/migrations/2026_04_11_000001_create_laravel_forms_tables.php`
- `resources/js/portal/admin/views/forms.ts`
- `resources/js/external/forms/api.ts`
- `resources/js/external/forms/index.ts`
- `FORMS_MODULE_DOCUMENTATION.md`

## Admin UI behavior

The admin forms module now provides:
- A forms list view with a create button
- A table with columns: form id, name, created date, submission count, actions
- A blank title for the action column with a dropdown for Edit, Submissions, Response, Delete
- Edit view powered by `initBuilder` in `resources/js/portal/admin/controllers/forms/FormsEditorController.ts`
- Publish dialog for builder output creation in `laravel_builder_output`
- A submissions list view with view/delete actions
- A submission detail view showing responses
- A response view that loads `EmailConstructionBuilder` for the form reference

## Backend behavior

The backend now supports:
- `GET /portal/api/v1/forms` list all forms
- `POST /portal/api/v1/forms` create a new form with generated reference number
- `GET /portal/api/v1/forms/{reference_num}` retrieve one form
- `PUT /portal/api/v1/forms/{reference_num}` update a form
- `POST /portal/api/v1/forms/{reference_num}/publish` publish builder output into `laravel_builder_output`
- `GET /portal/api/v1/forms/submissions` list submissions for a form
- `GET /portal/api/v1/forms/submissions/{id}` retrieve a submission
- `DELETE /portal/api/v1/forms/submissions/{id}` delete a submission
- `POST /portal/api/v1/forms/submit` record external form submissions

## Form data model

`laravel_forms` contains:
- `id` INT AUTO_INCREMENT PRIMARY KEY
- `reference_num` unique lowercase 12 char string starting with `v`
- `name` form display name
- `json_data` SurveyJS configuration data
- `created_at`, `updated_at`

`laravel_form_submissions` contains:
- `id` INT AUTO_INCREMENT PRIMARY KEY
- `reference_num` form reference number
- `email`
- `submission_data` JSON payload
- `created_at`, `updated_at`

## External API behavior

The external API supports:
- `init(reference_num)` to load form metadata
- `submit(payload)` to POST submissions
- automatic notification creation via the existing external `notificationsApi`

When the external submit call completes, `notificationsApi.create()` is invoked with a `forms` category notification.

## Exclusions

Updated `tsconfig.json` to exclude:
- `public/_portal_`
- `resources/js/portal/admin/afscore`

This keeps the new implementation separate from legacy form templates and AFScore implementation files.
