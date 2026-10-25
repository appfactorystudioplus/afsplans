# Builder Saving Strategy Plan

## Goal

Replace the current split-save strategy (`pages_data`, `styles_data`) with a single JSON payload stored in a new `data` column on `laravel_builder_projects`.

The new strategy must:
- store all builder state in one normalized JSON object
- preserve normal pages and special page types
- preserve page/subpage relationships and page metadata
- support expandable special page configuration for e-commerce, events, registration, login, appointments, etc.
- restore the builder exactly as it was on reload
- avoid schema fragmentation by centralizing builder state in a single `data` field

## Migration change

Update `database/migrations/2026_04_16_000001_create_laravel_builder_projects_table.php`:
- remove `pages_data` column
- remove `styles_data` column
- add `data` column with `longText()->nullable()`

This makes the `builder_projects` table store the builder payload in one place.

## Backend contract

### Save path

`BuilderController::saveProject(Request $request)` should accept a new payload shape:

```php
payload = {
  name,
  reference_num,
  status,
  data: { ... complete builder state ... }
}
```

The controller will:
- validate `reference_num`
- encode `data` with `FunctionsController::Utils_encodeJson()`
- persist it into the `data` field
- optionally keep `settings` inside `data` as `data.settings`
- include the GrapesJS editor project dump under `data.projectData` when available

### Load path

`BuilderController::getProject()` and `getBuilderProjectData()` should:
- decode `$project->data` with `FunctionsController::Utils_decodeStringToJson()`
- return `data` as a fully parsed object to the frontend

## JSON schema design

### Root payload

```json
{
  "version": 1,
  "project": {
    "name": "...",
    "reference_num": "...",
    "status": "draft|active|archived",
    "settings": { ... }
  },
  "pages": [ ... ],
  "globalStyles": "...",
  "metadata": {
    "lastSavedAt": "...",
    "builderVersion": "..."
  }
}
```

### Page object

Each page in `pages` should include:

```json
{
  "id": "<page-id>",
  "name": "index|home|about",
  "type": "normal|e-commerce|events|registration|login|appointments",
  "subpageOf": "<parent-page-id>|null",
  "pageSubpage": "products|cart|checkout|event|appointment|confirmation|login",
  "component": "<html or GrapesJS component JSON>",
  "styles": "<css string>",
  "pageData": {
    "commerce": { ... },
    "events": { ... },
    "appointments": { ... },
    "auth": { ... },
    "registration": { ... }
  },
  "meta": {
    "pageCommerceFooterTemplate": "...",
    "pageCommerceTemplate": "...",
    "pageCommerceCartTemplate": "...",
    "pageCommerceCheckoutTemplate": "...",
    "activeSubpage": "...",
    "pageType": "..."
  }
}
```

### Special page extensibility

Use `page.type` and `page.pageData` as the extensibility mechanism for special page types. This keeps the schema generic and future-proof.

Example:
- `page.type = "e-commerce"`
- `page.pageData.commerce = { storeRef, products, footerVariant, templateId, cartTemplateId, checkoutTemplateId }
- `page.type = "events"`
- `page.pageData.events = { eventType, ticketOptions, dates, registrationFormRef }
- `page.type = "appointments"`
- `page.pageData.appointments = { providerId, serviceIds, availability, confirmationPageId }
- `page.type = "login"`
- `page.pageData.auth = { authProvider, loginFormRef, redirectPath }
- `page.type = "registration"`
- `page.pageData.registration = { formId, planSelection, redirectAfterRegister }

Use `page.meta` for display-specific or UI-only fields that are not part of the page payload itself.

## Frontend save strategy

### Single save payload

Replace the current `pages_data` / `styles_data` save payload with a single `data` object.

`saveProjectToApi()` should send:

```ts
const payload = {
  name: data.name || 'Untitled Project',
  reference_num: projectId,
  status: data.status || 'draft',
  data: {
    project: {
      name: data.name,
      reference_num: projectId,
      status: data.status,
      settings: data.settings || {}
    },
    pages: data.pages,
    globalStyles: data.style || '',
    metadata: data.metadata || {},
    projectData: data.projectData || {} // editor.getProjectData() output
  }
};
```

If the builder cannot provide `projectData` directly, construct the object from:
- all editor pages and selected page state
- saved HTML/components per page
- per-page styles
- page metadata and pageData fields
- builder settings

### Page states and subpages

Persist page relationships and subpages explicitly:
- `subpageOf` points to the parent page id
- `pageSubpage` identifies the subpage role
- `activeSubpage` preserves the active child page of a parent

This is the only reliable way to restore the components panel and page tree exactly as the user configured it.

## Frontend restore strategy

### On reload

1. Fetch `project.data` from the backend.
2. Decode the JSON into a normalized object.
3. Iterate over `data.pages` and rehydrate builder pages.
4. Rebuild the page manager UI with:
   - normal pages
   - special page types
   - subpage relationships
   - active subpage state
5. Restore each page's:
   - `component`
   - `styles`
   - `pageData`
   - `meta`
6. Reapply `globalStyles` to the editor canvas.
7. Load any builder-wide settings from `data.project.settings`.

### Page manager reconstruction

The page list must be rebuilt from the `pages` array and not from the current editor canvas alone.

To restore content and structure, the page manager should:
- create every page record with `id`, `name`, `type`, and `pageData`
- attach child pages to parent pages using `subpageOf`
- set the currently selected page if available
- restore `activeSubpage` for parent pages

### Special page types

When restoring special page types, use the `page.type` and `page.pageData` values to rehydrate any dedicated controls.

For example:
- e-commerce pages get commerce settings and footer/template configuration
- events pages get event metadata and registration config
- appointments pages get availability, provider, and confirmation settings
- login/registration pages get auth form and redirect configuration

This avoids hard-coding special logic into the page tree and makes the format expandable.

## Expandability and future-proofing

### Use a schema version

Store `data.version` on the root object.
This allows safe migration later.

### Use generic fields for unknown special data

Allow `page.pageData` to contain arbitrary JSON for custom page types.
This keeps the model extensible without requiring schema changes for each new page type.

### Keep UI-specific metadata separate

Use `page.meta` for fields that you only need in the builder UI and do not need to render on the public page.

## Rollout path

1. Create migration: replace `pages_data` + `styles_data` with `data` longText.
2. Update backend save/load to use `data`.
3. Update frontend save payload to send complete `data` object.
4. Update frontend load path to initialize from `project.data`.
5. Add compatibility fallback for records still using old columns during migration.
6. Confirm page manager restores subpages, special page metadata, and full page styles.

## Notes

- `settings` can be nested under `data.project.settings` for complete centralization.
- `globalStyles` should remain part of the same `data` object so styling is persisted alongside pages.
- The new `data` field should be the only place where builder layout/state is stored for this table.

## Example final payload

```json
{
  "version": 1,
  "project": {
    "name": "My Site",
    "reference_num": "proj123",
    "status": "draft",
    "settings": {
      "brandColor": "#123456"
    }
  },
  "globalStyles": ".hero { margin: 0; }",
  "metadata": {
    "lastSavedAt": "2026-04-18T00:00:00Z",
    "builderVersion": "1.0"
  },
  "pages": [
    {
      "id": "page-1",
      "name": "index",
      "type": "normal",
      "subpageOf": null,
      "pageSubpage": null,
      "component": "<div>...</div>",
      "styles": ".page { color: #000 }",
      "pageData": {},
      "meta": {}
    },
    {
      "id": "page-2",
      "name": "store",
      "type": "e-commerce",
      "subpageOf": null,
      "pageSubpage": null,
      "component": "<div>...</div>",
      "styles": ".store { ... }",
      "pageData": {
        "commerce": {
          "storeRef": "store-1",
          "templateId": "list",
          "footerVariant": "grid"
        }
      },
      "meta": {
        "activeSubpage": "products"
      }
    }
  ]
}
```
