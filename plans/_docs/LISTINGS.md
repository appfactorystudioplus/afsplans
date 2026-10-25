# Listings Module

## Overview

The Listings module provides a portal admin UI for creating listing objects, adding list items, and publishing the combined HTML/CSS/JavaScript output to the existing `laravel_builder_output` table.

This implementation includes:

- A Listings admin page under the portal sidebar.
- A `ListingsController` with CRUD endpoints for listing objects and list items.
- Dedicated backend tables prefixed with `laravel_`.
- A client-side listings API in `resources/js/external/listings/index.ts`.
- Output publishing using `typename = listings` and `typeaction = 1` in `laravel_builder_output`.

## Files Added

- `app/Models/Externals/Listing.php`
- `app/Models/ListingItem.php`
- `app/Http/Controllers/Externals/Listings/ListingsController.php`
- `database/migrations/2026_04_04_120000_create_laravel_listings_tables.php`
- `resources/js/external/listings/index.ts`
- `resources/js/portal/admin/views/listings.ts`
- `LISTINGS.md`

## Backend Schema

### `laravel_listings`

- `listing_id` (primary string)
- `name`
- `internal_id` (unique)
- `style_variant`
- `details_page_enabled`
- `config` (JSON)
- timestamps

### `laravel_listing_items`

- `listing_item_id` (primary string)
- `listing_id`
- `title`
- `description`
- `images` (JSON array)
- `details_title`
- `details_content`
- `details_url`
- `sort_order`
- `config` (JSON)
- timestamps

## API Endpoints

All endpoints are registered under `/portal/api/v1/listings`.

### Listing object endpoints

- `GET /portal/api/v1/listings` — list all Listings
- `GET /portal/api/v1/listings/{listing_id}` — retrieve a Listing and its items
- `POST /portal/api/v1/listings` — create a Listing
- `PUT /portal/api/v1/listings` — update a Listing
- `DELETE /portal/api/v1/listings/{listing_id}` — delete a Listing and its items

### Item endpoints

- `GET /portal/api/v1/listings/{listing_id}/items` — list items for a Listing
- `POST /portal/api/v1/listings/{listing_id}/items` — create a Listing item
- `PUT /portal/api/v1/listings/{listing_id}/items/{item_id}` — update a Listing item
- `DELETE /portal/api/v1/listings/{listing_id}/items/{item_id}` — delete a Listing item

### Output endpoints

- `GET /portal/api/v1/listings/output/{listing_id}` — fetch saved published output
- `POST /portal/api/v1/listings/output` — save output HTML/CSS/JS to `laravel_builder_output`

## Client API

The client API in `resources/js/external/listings/index.ts` includes:

- `fetchListings`
- `fetchListing`
- `createOrUpdateListing`
- `deleteListing`
- `fetchListingItems`
- `createOrUpdateListingItem`
- `deleteListingItem`
- `fetchListingOutput`
- `saveListingOutput`
- `uploadListingImage`

The upload helper uses `app.window.documents.upload(file, ViewsCategories.LISTINGS.toString(), {}, app)` and returns `descriptor.url` as the image source.

## Admin UI

The Listings admin view is implemented in `resources/js/portal/admin/views/listings.ts`.

It provides:

- Listing creation and editing
- Internal ID plus style selection
- Multiple item image uploads
- Item description editing via `Utilities.CreateEditor(app, "#editorContainer", function(editor: any) { })`
- Details page title/content/url options
- Published output generation and storage in `laravel_builder_output`

## Notes

- Output is stored with `typename = listings` and `typeaction = 1`.
- The client-side API is documented inline with comments.
- Image uploading uses the shared portal document upload mechanism and stores uploaded image URLs in listing item data.
