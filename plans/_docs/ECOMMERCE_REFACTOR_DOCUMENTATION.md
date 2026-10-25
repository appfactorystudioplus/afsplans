# E-Commerce Module — Reference Number Refactor & Bug Fixes

> **Date:** April 16, 2026  
> **Scope:** Full refactor of product/category identification from client-generated `id` to server-generated `reference_num`, plus cascading bug fixes and a builder page manager fix.

---

## Table of Contents

1. [Overview](#overview)
2. [Architecture](#architecture)
3. [Database Changes](#database-changes)
4. [Backend Changes](#backend-changes)
5. [Frontend — TypeScript Types](#frontend--typescript-types)
6. [Frontend — DataStore (State Management)](#frontend--datastore-state-management)
7. [Frontend — Admin Views](#frontend--admin-views)
8. [Frontend — Navigation Chain & Direct Payment Flow](#frontend--navigation-chain--direct-payment-flow)
9. [Frontend — Builder Page Manager Fix](#frontend--builder-page-manager-fix)
10. [Route Definitions](#route-definitions)
11. [Bug Fixes Applied](#bug-fixes-applied)
12. [File Index](#file-index)

---

## Overview

### What Was Done

The e-commerce module originally used client-generated UUIDs as the `id` primary key for products and categories. This was refactored so that:

1. **`id`** remains the primary key but is now generated **server-side** (UUID via `Str::uuid()`)
2. **`reference_num`** was added as a new unique column — also server-generated — used as the **public-facing identifier** for all client-server communication
3. All frontend code was updated to reference entities by `reference_num` instead of `id`
4. All CRUD operations (create, read, update, delete) now use `reference_num` for lookups
5. The `addCommerceProduct()` and `addCommerceCategory()` DataStore methods became **async** — they POST to the server first, then populate the local store with the server response (including server-generated `id` and `reference_num`)
6. A migration was created to add `reference_num` to existing tables and backfill existing rows

Additionally, a bug was fixed in the GrapesJS page builder where changing a page's type to "E-Commerce" did not re-render the page list to show the expandable subpage group.

### Why

- Security: Clients should not generate their own primary keys
- Consistency: Server is the single source of truth for identity
- Decoupling: `reference_num` serves as a stable external identifier separate from the internal `id`

---

## Architecture

### Data Flow

```
┌─────────────┐     POST (no id/ref)      ┌──────────────────┐     INSERT with UUID    ┌────────────────┐
│  Browser UI  │ ──────────────────────── → │  EcommerceController │ ──────────────────── → │  MySQL Database  │
│  (TypeScript) │ ← ──────────────────────  │  (Laravel PHP)       │ ← ──────────────────  │                  │
└─────────────┘   Response with id+ref_num └──────────────────┘   Saved row returned    └────────────────┘
```

**Create flow:**
1. Client submits product/category data **without** `id` or `reference_num`
2. Server generates both `id` (UUID) and `reference_num` (UUID) via `Str::uuid()`
3. Server inserts the record and returns the full row including both generated fields
4. Client stores the server response in the local DataStore

**Update flow:**
1. Client sends `reference_num` + updated fields
2. Server looks up the record by `reference_num`
3. Server updates and returns the saved row
4. Client merges the server response into the local DataStore

**Delete flow:**
1. Client sends `DELETE /portal/api/ecommerce/product/{reference_num}`
2. Server deletes by `reference_num`

### Key Principle

The `id` field is **never sent from the client**. It exists only as a database primary key. All client-facing operations use `reference_num`.

---

## Database Changes

### Migration File

**File:** `database/migrations/2026_04_16_200000_add_reference_num_to_ecommerce_tables.php`

**Purpose:** Adds `reference_num` column to both `laravel_ecommerce_products` and `laravel_ecommerce_categories` tables, then backfills existing rows with UUIDs.

**Schema changes:**
- `laravel_ecommerce_products`: Added `reference_num` (string, nullable, unique) after `id`
- `laravel_ecommerce_categories`: Added `reference_num` (string, nullable, unique) after `id`

**Backfill logic:** After adding the column, queries all existing rows and sets `reference_num = Str::uuid()` for each.

**Rollback:** Drops the `reference_num` column from both tables.

**To run:** `php artisan migrate`

### Existing Table Schemas

**`laravel_ecommerce_products`:**
| Column | Type | Notes |
|--------|------|-------|
| `id` | string | Primary key, server-generated UUID |
| `reference_num` | string | Unique, server-generated UUID |
| `store_reference_num` | string | FK to store |
| `name` | string | |
| `description` | text | |
| `price` | decimal(10,2) | Stored as string in DB, parse with `parseFloat()` |
| `images` | json | JSON-encoded array of image URLs |
| `direct_payment_item_reference_num` | string/null | FK to direct payment item |
| `category1` through `category5` | string/null | Category reference_nums |
| `created_at`, `updated_at` | timestamp | |

**`laravel_ecommerce_categories`:**
| Column | Type | Notes |
|--------|------|-------|
| `id` | string | Primary key, server-generated UUID |
| `reference_num` | string | Unique, server-generated UUID |
| `store_reference_num` | string | FK to store |
| `name` | string | |
| `description` | text | |
| `created_at`, `updated_at` | timestamp | |

**`laravel_ecommerce_stores`:**
| Column | Type | Notes |
|--------|------|-------|
| `id` | auto-increment int | Primary key |
| `reference_num` | string | Unique |
| `name` | string | |
| `direct_payment` | string/null | Reference to a direct payment entity |
| `created_at`, `updated_at` | timestamp | |

---

## Backend Changes

### File: `app/Http/Controllers/Externals/Ecommerce/EcommerceController.php`

All methods use `reference_num` for entity identification. Key methods:

#### `saveProduct(Request $request)`
```
Input:  reference_num (optional), name, description, price, images[], 
        store_reference_num, direct_payment_item_reference_num, category1-5
Logic:  If reference_num provided → update existing row found by reference_num
        If no reference_num → insert new row with id=Str::uuid(), reference_num=Str::uuid()
Output: Full product row with parsed images JSON
```

#### `saveCategory(Request $request)`
```
Input:  reference_num (optional), name, description, store_reference_num
Logic:  Same upsert pattern as saveProduct
Output: Full category row
```

#### `deleteProduct($referenceNum)` / `deleteCategory($referenceNum)`
```
Input:  reference_num as URL parameter
Logic:  DELETE FROM table WHERE reference_num = $referenceNum
Output: JSON success response
```

#### `getProducts(Request $request)` / `getCategories(Request $request)`
```
Input:  Optional ?store= query parameter to filter by store_reference_num
Output: Array of all matching rows (products have images JSON-decoded)
```

---

## Frontend — TypeScript Types

### File: `resources/js/portal/admin/types/index.ts`

#### `CommerceProduct` interface
```typescript
export interface CommerceProduct {
    id: string;
    reference_num: string;
    name: string;
    description: string;
    price: number;
    images: string[];
    store_reference_num?: string;              // Optional — DataStore sets it automatically
    direct_payment_item_reference_num?: string; // Optional — only set when linked to a DP item
    category1: string;
    category2: string;
    category3: string;
    category4: string;
    category5: string;
    createdAt: Date;
}
```

#### `CommerceCategory` interface
```typescript
export interface CommerceCategory {
    id: string;
    reference_num: string;
    name: string;
    description: string;
    store_reference_num?: string; // Optional — DataStore sets it automatically
    createdAt: Date;
}
```

**Important:** `store_reference_num` and `direct_payment_item_reference_num` are marked optional (`?`) because the DataStore's `addCommerceProduct()` accepts `Omit<CommerceProduct, 'id' | 'reference_num' | 'createdAt'>` and attaches `store_reference_num` automatically from `activeStoreReferenceNum`. Making them required would cause TS2345 errors at call sites that don't explicitly set them.

---

## Frontend — DataStore (State Management)

### File: `resources/js/portal/admin/store/DataStore.ts`

The DataStore is a singleton (`DataStore.getInstance()`) that manages all client-side state. Commerce data is scoped to the active store via `activeStoreReferenceNum`.

### Initialization

```typescript
async initEcommerceData(storeRef?: string): Promise<void>
```
- Fetches products and categories from the API filtered by `store_reference_num`
- Sets `this.activeStoreReferenceNum`
- Saves to localStorage for offline access

### Product Methods

| Method | Async | Description |
|--------|-------|-------------|
| `getCommerceProducts()` | No | Returns `this.products` array |
| `getCommerceProduct(referenceNum)` | No | Finds by `p.reference_num === referenceNum` |
| `addCommerceProduct(product)` | **Yes** | POSTs to server, gets back server-generated id/reference_num, pushes to local array |
| `updateCommerceProduct(referenceNum, updates)` | **Yes** | Updates local first, POSTs to server, merges server response back |
| `deleteCommerceProduct(referenceNum)` | No | Splices from local array, fire-and-forget DELETE to API |

### Category Methods

| Method | Async | Description |
|--------|-------|-------------|
| `getCommerceCategories()` | No | Returns `this.categories` array |
| `getCommerceCategory(referenceNum)` | No | Finds by `c.reference_num === referenceNum` |
| `addCommerceCategory(category)` | **Yes** | Same server-first pattern as products |
| `updateCommerceCategory(referenceNum, updates)` | **Yes** | Same pattern |
| `deleteCommerceCategory(referenceNum)` | No | Same fire-and-forget pattern |

### Key Detail: Server-First Create Pattern

When adding a new product/category:
1. The method does **not** generate an `id` or `reference_num` client-side
2. It POSTs the data to the server (with `store_reference_num` auto-attached)
3. The server generates both `id` and `reference_num` and returns the full record
4. The client pushes the server response into the local array
5. The method returns the server-created object (with real `id` and `reference_num`)

This ensures the client always has the authoritative server-generated identifiers.

---

## Frontend — Admin Views

### Products View: `resources/js/portal/admin/views/commerce-products.ts`

#### Key Functions

| Function | Description |
|----------|-------------|
| `initCommerceProductsView(storeReferenceNum?, storeDirectPaymentRef?, app?)` | Main entry point. Sets store context, renders products grid, populates category dropdowns, attaches event delegation. |
| `renderProducts(productsToRender?)` | Renders card or table view. All action buttons use `data-ref="${product.reference_num}"` and `data-action="editProduct"/"deleteProduct"`. |
| `initCommerceProductFormView(productRefNum?, app?, storeReferenceNum?, storeDirectPaymentRef?)` | Renders the product create/edit form. If `productRefNum` is provided, looks up existing product via `dataStore.getCommerceProduct(productRefNum)` and populates the form. |
| `saveProduct()` | Async. Reads form values, calls `dataStore.updateCommerceProduct(referenceNum, ...)` or `dataStore.addCommerceProduct(...)`, navigates back to products list on success. |
| `openProductModal(productRefNum?)` | Dispatches a `navigate` CustomEvent to `ecommerce:product-form` with params including `productRefNum`. |

#### Data Attributes

All product references in the DOM use `data-ref` (not `data-id`):
```html
<button data-action="editProduct" data-ref="${product.reference_num}">Edit</button>
<button data-action="deleteProduct" data-ref="${product.reference_num}">Delete</button>
```

Event delegation reads `data-ref` from the clicked button to identify which product to act on.

#### Price Display Fix

Product prices come from MySQL as strings (decimal column). Display uses:
```typescript
parseFloat(String(product.price)).toFixed(2)
```

#### Category Dropdown Population

Category dropdowns use `reference_num` as option values:
```typescript
categories.forEach(c => {
    const option = document.createElement('option');
    option.value = c.reference_num;  // reference_num, not id
    option.textContent = c.name;
    select.appendChild(option);
});
```

Category filtering in the product table also compares by `reference_num`:
```typescript
const categoryMatch = c.reference_num === product.category1;
```

### Categories View: `resources/js/portal/admin/views/commerce-categories.ts`

Same pattern as products. Key points:
- Table rows use `data-ref="${category.reference_num}"`
- Modal edit/create populates from `dataStore.getCommerceCategory(refNum)`
- Save calls async `dataStore.updateCommerceCategory(referenceNum, ...)` or `addCommerceCategory(...)`
- Delete calls `dataStore.deleteCommerceCategory(refNum)`
- Product count per category checks all 5 category slots on each product

---

## Frontend — Navigation Chain & Direct Payment Flow

### File: `resources/js/portal/admin/views.ts`

The admin portal uses a custom `navigate` CustomEvent system for SPA-like routing. The e-commerce navigation chain passes `store_direct_payment` through multiple levels:

```
Store Listing (ecommerce)
  └─ data-dp="${store.direct_payment || ''}"
  └─ Dashboard button dispatches navigate with { store_reference_num, store_name, store_direct_payment }
     │
     ▼
Store Dashboard (ecommerce:store-dashboard)
  └─ Receives store_reference_num, store_name, store_direct_payment from params
  └─ Products button dispatches navigate with { store_reference_num, store_direct_payment }
  └─ Categories button dispatches navigate with { store_reference_num, store_direct_payment }
     │
     ▼
Products List (ecommerce:products)
  └─ Calls initCommerceProductsView(store_reference_num, store_direct_payment, app)
  └─ Edit button dispatches navigate with { productRefNum, store_reference_num, store_direct_payment }
     │
     ▼
Product Form (ecommerce:product-form)
  └─ Calls initCommerceProductFormView(productRefNum, app, store_reference_num, store_direct_payment)
  └─ Uses store_direct_payment to filter the Direct Payment item picker dropdown
```

**Why `store_direct_payment` matters:** Each store can be linked to a Direct Payment entity. When editing a product, the "Direct Payment Item" dropdown only shows items from that specific Direct Payment. The `store_direct_payment` value is the `reference_num` of the parent Direct Payment, used to filter `SINGLE_PAY` items.

---

## Frontend — Builder Page Manager Fix

### Problem

When a user changed a page's type to "E-Commerce" via the `#page-type-select` dropdown in the right sidebar, the page list in the left sidebar did not update. The page list is supposed to show an expandable group with subpages ("products", "cart") for e-commerce pages, but `renderPages()` was only triggered by `page:add`, `page:remove`, and `page:select` events — not by page model property changes like `pageType`.

### Solution

Two changes were made:

#### 1. `resources/js/portal/builder/core/pageManager.ts` — Expose `renderPages`

After the event listener setup, `renderPages` was exposed on the editor instance:
```typescript
// Expose renderPages so external code (e.g. imp.ts page-type change) can trigger a re-render
(editor as any).__renderPages = renderPages;
```

#### 2. `resources/js/portal/builder/core/imp.ts` — Call `renderPages` on page type change

In the `pageTypeSelect.addEventListener('change', ...)` handler, after setting the page type:
```typescript
pageTypeSelect.addEventListener('change', () => {
    const page = getCurrentPage();
    if (page && !isIndexPage(page)) {
        page.set('pageType', pageTypeSelect.value);
        showConditionalSections(pageTypeSelect.value);
        // Re-render the page list to reflect the new page type
        if (typeof (editor as any).__renderPages === 'function') {
            (editor as any).__renderPages();
        }
    }
});
```

### Page Manager — SUBPAGES Map

The page manager defines which page types get expandable subpage groups:
```typescript
const SUBPAGES: Record<string, string[]> = {
    'e-commerce': ['products', 'cart'],
    'events': ['event', 'cart'],
    'appointments': ['appointment', 'confirmation', 'cart'],
};
```

When `renderPages()` runs, it checks each page's `pageType` against this map. If a match is found, the page is rendered as an expandable group with clickable subpage items. Clicking a subpage sets `page.set('activeSubpage', subpage)` on the GrapesJS page model.

---

## Route Definitions

### File: `routes/portal.php`

#### Admin E-Commerce Routes (prefix: `/portal/api/ecommerce`)

| Method | Path | Controller Method |
|--------|------|-------------------|
| GET | `/categories` | `getCategories` |
| POST | `/category` | `saveCategory` |
| DELETE | `/category/{reference_num}` | `deleteCategory` |
| GET | `/products` | `getProducts` |
| POST | `/product` | `saveProduct` |
| DELETE | `/product/{reference_num}` | `deleteProduct` |
| POST | `/store` | `createStore` |
| GET | `/stores` | `getStores` |
| DELETE | `/store/{reference_num}` | `deleteStore` |
| GET | `/direct-payments` | `getDirectPayments` |
| POST | `/direct-payment` | `saveDirectPayment` |

#### Builder Routes (prefix: `/portal/api/v1/builder`)

| Method | Path | Handler |
|--------|------|---------|
| GET | `/stores` | Inline closure — queries `laravel_ecommerce_stores`, returns `reference_num` + `name` |
| GET | `/store-products/{reference_num}` | Inline closure — queries `laravel_ecommerce_products` by `store_reference_num`, JSON-decodes images |

---

## Bug Fixes Applied

### 1. TS2345 — Type Mismatch on Product/Category Creation
**Problem:** `store_reference_num` and `direct_payment_item_reference_num` were required in the interfaces, but the DataStore's `addCommerceProduct()` method accepts an `Omit<>` type and adds `store_reference_num` automatically. Call sites that didn't set these fields caused TypeScript errors.  
**Fix:** Made both fields optional (`?`) in the `CommerceProduct` and `CommerceCategory` interfaces.

### 2. PDO Error — Missing `reference_num` Column
**Problem:** The migration adding the `reference_num` column to existing tables hadn't been run on the remote server.  
**Fix:** Run `php artisan migrate` on the server.

### 3. `price.toFixed is not a function`
**Problem:** MySQL returns `decimal(10,2)` values as strings. Calling `.toFixed(2)` on a string throws a runtime error.  
**Fix:** Wrapped in `parseFloat(String(product.price)).toFixed(2)`.

### 4. Direct Payment Filter Not Working
**Problem:** The `store_direct_payment` value (needed to filter Direct Payment items in the product form) was not being passed through the full navigation chain from store listing → store dashboard → products → product form.  
**Fix:** Added `data-dp` attribute to the store Dashboard button, captured it in the store dashboard view, and passed it through all subsequent navigation events.

### 5. Builder Page List Not Updating on Page Type Change
**Problem:** Changing page type to "e-commerce" didn't re-render the page list to show subpages.  
**Fix:** Exposed `renderPages()` via `(editor as any).__renderPages` and called it from the `pageTypeSelect` change handler.

## Recent Builder & Footer Enhancements

### Footer template state persistence
- Added `footerTemplate` / `footerVariant` selection support to the GrapesJS builder property panel.
- Footer choice is now stored on the current page model and copied into all e-commerce subpages (`products`, `cart`, `checkout`) so the variant persists across page transitions.
- The current footer choice is read from page metadata in `resources/js/portal/builder/core/imp.ts` and written into page render state.

### Footer variant rendering improvements
- Updated `resources/js/portal/builder/core/ecommerceTemplates.ts` with unique structural variants for footer display.
- `grid` now renders a business-like commerce footer with card-style sections and CTA.
- `list` now uses a resource panel approach with newsletter-style action copy, link blocks, and social icons.
- `minimal` now uses a centered text strip with inline nav links and a subtle lower note.
- `banner` now uses a hero-style CTA footer with a strong visual surface and prominent button.
- Footer output is now full-width via a `width:100%` wrapper and page-spanning layout.

### Page-template-specific footer styling
- Footer styling now more closely matches each page template:
  - `grid` page layouts get a polished commerce card aesthetic.
  - `list` page layouts get clean panel-based resource styling.
  - `minimal` pages get a stripped-back centered footer.
  - `banner` pages get a dramatic hero-style footer with gradient background.

### Build validation
- Verified the footer and builder updates with `npm run build --silent`.

---

## File Index

### Files Created This Session
| File | Purpose |
|------|---------|
| `database/migrations/2026_04_16_200000_add_reference_num_to_ecommerce_tables.php` | Migration to add `reference_num` column to products and categories tables |

### Files Modified This Session
| File | Changes |
|------|---------|
| `app/Http/Controllers/Externals/Ecommerce/EcommerceController.php` | `saveProduct()` and `saveCategory()` generate server-side UUIDs for `id` and `reference_num`. Delete methods use `reference_num` parameter. |
| `resources/js/portal/admin/types/index.ts` | Added `reference_num` to `CommerceProduct` and `CommerceCategory`. Made `store_reference_num` and `direct_payment_item_reference_num` optional. |
| `resources/js/portal/admin/store/DataStore.ts` | All commerce CRUD methods refactored to use `reference_num`. Add methods made async with server-first pattern. |
| `resources/js/portal/admin/views/commerce-products.ts` | All `data-id` → `data-ref`. All product lookups by `reference_num`. `saveProduct()` made async. Price display fixed. Category dropdowns use `reference_num`. |
| `resources/js/portal/admin/views/commerce-categories.ts` | All `data-id` → `data-ref`. All category lookups by `reference_num`. `saveCategory()` made async. |
| `resources/js/portal/admin/views.ts` | Added `store_direct_payment` to navigation chain through store dashboard → products → product form. |
| `resources/js/portal/builder/core/pageManager.ts` | Exposed `renderPages` via `__renderPages` on editor instance. |
| `resources/js/portal/builder/core/imp.ts` | Added `__renderPages()` call in `pageTypeSelect` change handler. |
| `routes/portal.php` | Delete routes use `{reference_num}` parameter. |

### Files Not Changed (But Referenced)
| File | Role |
|------|------|
| `resources/js/portal/builder/core/components.ts` | Registers `module-commerce` GrapesJS component type. Uses `storeNames` map from API. |
| `resources/js/portal/builder/core/ecommerceTemplates.ts` | Defines 4 e-commerce display templates (grid, list, minimal, banner). |
| `resources/js/portal/builder/main.ts` | Bootstrap — fetches builder components, initializes editor. `fetchBuilderComponents()` enriches commerce rows with store names. |
| `app/Models/Integrations/EcommerceStore.php` | Eloquent model for `laravel_ecommerce_stores` table. |
