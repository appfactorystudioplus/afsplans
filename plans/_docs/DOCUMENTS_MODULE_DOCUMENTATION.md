# Documents Module Documentation

**Version:** 1.0.0  
**Date:** 2026-05-04  
**Module path (admin):** `resources/js/portal/admin/documents/`  
**Module path (external):** `resources/js/external/documents/`  
**Laravel controller:** `app/Http/Controllers/Externals/Documents/DocumentController.php`  
**Migration:** `database/migrations/2026_05_04_000002_create_laravel_documents_table.php`

---

## Table of Contents

1. [Overview](#1-overview)
2. [Architecture Diagram](#2-architecture-diagram)
3. [Database — `laravel_documents`](#3-database--laravel_documents)
4. [Laravel Backend](#4-laravel-backend)
   - 4.1 [DocumentController](#41-documentcontroller)
   - 4.2 [Routes](#42-routes)
   - 4.3 [Document Model](#43-document-model)
5. [Admin JavaScript API](#5-admin-javascript-api)
   - 5.1 [DocumentsAPI class](#51-documentsapi-class)
   - 5.2 [Configuration options](#52-configuration-options)
   - 5.3 [analyseFiles()](#53-analysefiles)
   - 5.4 [uploadFiles()](#54-uploadfiles)
   - 5.5 [downloadDocument()](#55-downloaddocument)
   - 5.6 [listDocuments()](#56-listdocuments)
   - 5.7 [deleteDocument()](#57-deletedocument)
   - 5.8 [Resumable uploads (LocalStorage)](#58-resumable-uploads-localstorage)
6. [Web Worker Architecture](#6-web-worker-architecture)
   - 6.1 [UploadCoordinator](#61-uploadcoordinator)
   - 6.2 [UploadWorker (child)](#62-uploadworker-child)
   - 6.3 [Message protocol](#63-message-protocol)
   - 6.4 [Concurrency and directory traversal](#64-concurrency-and-directory-traversal)
7. [External JavaScript API](#7-external-javascript-api)
   - 7.1 [ExternalDocumentsAPI class](#71-externaldocumentsapi-class)
   - 7.2 [Configuration](#72-configuration)
   - 7.3 [Methods](#73-methods)
8. [File Storage Paths](#8-file-storage-paths)
9. [File Naming (Standard Mode)](#9-file-naming-standard-mode)
10. [Categories](#10-categories)
11. [Client-Side Validation](#11-client-side-validation)
12. [Using the Admin API in a Module](#12-using-the-admin-api-in-a-module)
13. [Using the External API on a Public Page](#13-using-the-external-api-on-a-public-page)
14. [Using the API in the Sponsors Dashboard](#14-using-the-api-in-the-sponsors-dashboard)
15. [TypeScript Types Reference](#15-typescript-types-reference)
16. [Security Considerations](#16-security-considerations)

---

## 1. Overview

The Documents Module provides a reusable, configurable file-upload and file-download API that can be consumed by **every portal module** (forms, sponsorships, commerce, subscribers, etc.) as well as by **external integrations** outside the admin portal.

Key capabilities:

| Feature | Description |
|---------|-------------|
| **Progressive upload** | Per-file XHR progress events forwarded to the UI for progress-bar rendering. |
| **Worker-based concurrency** | Files are uploaded in parallel (up to 5 concurrent workers) on a background thread, keeping the main UI thread responsive. |
| **Directory upload** | Full directory trees can be drag-and-dropped; the API recursively traverses them. |
| **Client-side validation** | File type and size are checked *before* any network request. Invalid files are rejected immediately with descriptive error messages. |
| **File rename** | In standard mode, original filenames are replaced with a 32-character cryptographically random alphanumeric string (starting with a letter) plus the original extension, preventing filename collisions and path-traversal issues. |
| **Category tagging** | Every upload is tagged with a `ViewsCategories` key so each module can filter its own documents. |
| **Resumable uploads** | In-progress upload sessions are persisted to `localStorage`; interrupted batches can be resumed after a page reload. |
| **Static mode** | `save_static: true` keeps original filenames, skips the database, and writes files to a flat directory — useful for assets that don't need tracking. |
| **External SDK** | `window.Portal.documents.Client` exposes the same upload/download/list API to non-admin (public) pages via the portal external bundle. |

---

## 2. Architecture Diagram

```
Browser (admin portal)
│
├─ DocumentsAPI.ts
│   ├─ analyseFiles()       → validates locally, returns FileAnalysisResult
│   ├─ uploadFiles()        → reads files into ArrayBuffers
│   │    │
│   │    └─ spawns UploadCoordinator (Worker)
│   │         │
│   │         ├─ groups files by directory
│   │         ├─ spawns UploadWorker x5 (child Workers)
│   │         │   └─ XMLHttpRequest → POST /portal/api/v1/documents/upload
│   │         │        │
│   │         │        └─ child_progress / child_result → Coordinator
│   │         │
│   │         └─ progress / complete / error / batch_done → main thread → onProgress callback
│   │
│   ├─ downloadDocument()   → <a href> trigger
│   ├─ listDocuments()      → GET /portal/api/v1/documents
│   └─ deleteDocument()     → DELETE /portal/api/v1/documents/{ref}
│
└─ localStorage             ← upload sessions written here for resumability

Laravel (server)
│
├─ POST   /portal/api/v1/documents/upload      → DocumentController::upload()
├─ GET    /portal/api/v1/documents             → DocumentController::index()
├─ GET    /portal/api/v1/documents/{ref}/download → DocumentController::download()
└─ DELETE /portal/api/v1/documents/{ref}       → DocumentController::destroy()
     │
     └─ /mnt/node1/{member_reference_num}/modules/documents/{stored_name}
     └─ laravel_documents table (DB record)
```

---

## 3. Database — `laravel_documents`

**Migration file:** `database/migrations/2026_05_04_000002_create_laravel_documents_table.php`

Run the migration:

```bash
php artisan migrate
```

### Table columns

| Column | Type | Description |
|--------|------|-------------|
| `id` | BIGINT (PK, auto-increment) | Internal primary key. |
| `member_reference_num` | VARCHAR | The `Username` from `subdomain_properties`. Determines storage path. |
| `reference_num` | VARCHAR(36), UNIQUE | UUID used in all API calls. |
| `original_name` | VARCHAR | Filename as uploaded by the user (e.g. `Q1-Report.pdf`). |
| `stored_name` | VARCHAR | Server-side filename: 32-char random string + original extension. |
| `file_path` | VARCHAR(512) | Absolute path to the stored file on disk. |
| `category` | VARCHAR(64) | `ViewsCategories` key (e.g. `sponsorships`, `forms`). |
| `mime_type` | VARCHAR(128), nullable | MIME type reported by the browser. |
| `file_size` | BIGINT UNSIGNED | Size in bytes. |
| `description` | TEXT, nullable | Optional free-text description. |
| `created_at` | TIMESTAMP | Row creation timestamp. |
| `updated_at` | TIMESTAMP | Row last-updated timestamp. |
| `deleted_at` | TIMESTAMP, nullable | Soft-delete timestamp. |

### Indexes

- `member_reference_num` (single-column)
- `category` (single-column)
- `(member_reference_num, category)` (composite — used by filtered list queries)

---

## 4. Laravel Backend

### 4.1 DocumentController

**File:** `app/Http/Controllers/Externals/Documents/DocumentController.php`

#### `upload(Request $request): JsonResponse`

Accepts a `multipart/form-data` POST with the following fields:

| Field | Required | Description |
|-------|----------|-------------|
| `file` | Yes | The uploaded file. |
| `member_reference_num` | Yes | Owner username (`window.subdomain_properties.Username`). |
| `save_static` | No | Boolean string `"true"` / `"false"`. Default `false`. |
| `category` | No | `ViewsCategories` key. Default `"documents"`. |
| `description` | No | Free-text description. |

**Standard mode response (`save_static = false`):**

```json
{
  "success": true,
  "document": {
    "id": 42,
    "member_reference_num": "johndoe",
    "reference_num": "550e8400-e29b-41d4-a716-446655440000",
    "original_name": "Q1-Report.pdf",
    "stored_name": "aXk3mQpRz7bN9vLwYj6sTcFeDhRuOiP2.pdf",
    "file_path": "/mnt/node1/johndoe/modules/documents/aXk3mQpRz7bN9vLwYj6sTcFeDhRuOiP2.pdf",
    "category": "documents",
    "mime_type": "application/pdf",
    "file_size": 204800,
    "description": null,
    "created_at": "2026-05-04T10:00:00.000000Z",
    "updated_at": "2026-05-04T10:00:00.000000Z"
  }
}
```

**Static mode response (`save_static = true`):**

```json
{
  "success": true,
  "stored_path": "/mnt/node1/johndoe/documents/Q1-Report.pdf",
  "original_name": "Q1-Report.pdf"
}
```

#### `index(Request $request): JsonResponse`

List documents. Query params: `member_reference_num` (required), `category` (optional), `page`, `per_page` (max 200, default 50).

#### `download(Request $request, string $ref): StreamedResponse|JsonResponse`

Streams the file as a binary download with the original filename in `Content-Disposition`.

#### `destroy(Request $request, string $ref): JsonResponse`

Soft-deletes the database record and removes the physical file from disk.

---

### 4.2 Routes

All routes are registered in `routes/portal.php` under the `ApiAuthMiddleware`:

```
POST   /portal/api/v1/documents/upload         → DocumentController::upload
GET    /portal/api/v1/documents                → DocumentController::index
GET    /portal/api/v1/documents/{ref}/download → DocumentController::download
DELETE /portal/api/v1/documents/{ref}          → DocumentController::destroy
```

All endpoints require a valid Bearer token (or `portal_auth_token` cookie) managed by `ApiAuthMiddleware`.

---

### 4.3 Document Model

**File:** `app/Models/Document.php`

- Table: `laravel_documents`
- Uses `SoftDeletes`
- Cast: `file_size → integer`, timestamps → `datetime`

---

## 5. Admin JavaScript API

### 5.1 DocumentsAPI class

**File:** `resources/js/portal/admin/documents/DocumentsAPI.ts`

```typescript
import { DocumentsAPI, documentsApi } from '@/portal/admin/documents';

// Use the pre-built singleton (inherits memberRef from window.subdomain_properties.Username)
const docs = documentsApi;

// OR create a fresh instance with a specific memberRef
const docs = new DocumentsAPI();
docs.setMemberRef('johndoe');
```

The singleton `documentsApi` reads `window.subdomain_properties.Username` automatically on construction.

---

### 5.2 Configuration options

```typescript
docs.configure({
    save_static:     false,            // default: false
    size_limit_mb:   50,               // default: 200
    multiple:        true,             // default: true
    allow_directory: true,             // default: true
    allowed_types:   ['.pdf', 'image/png'], // default: [] (all)
    category:        'sponsorships',   // default: 'documents'
});
```

| Option | Type | Default | Description |
|--------|------|---------|-------------|
| `save_static` | `boolean` | `false` | Keep original name, skip DB, write to `/documents`. |
| `size_limit_mb` | `number` | `200` | Per-file size cap in MB. |
| `multiple` | `boolean` | `true` | Allow multi-file selection. |
| `allow_directory` | `boolean` | `true` | Enable directory drag-and-drop upload. |
| `allowed_types` | `string[]` | `[]` | MIME types or extensions. Empty = all. |
| `category` | `DocumentCategory` | `'documents'` | ViewsCategories key for every upload. |

---

### 5.3 analyseFiles()

```typescript
const analysis: FileAnalysisResult = await docs.analyseFiles(fileInput.files);

console.log(analysis.total_files);        // number
console.log(analysis.total_size_label);   // "4.2 MB"
console.log(analysis.valid_files);        // AnalysedFile[]
console.log(analysis.invalid_files);      // InvalidFile[]
console.log(analysis.all_valid);          // boolean
```

No network requests are made. Returns immediately with validation results.

---

### 5.4 uploadFiles()

```typescript
const documents = await docs.uploadFiles(fileInput.files, (evt) => {
    switch (evt.type) {
        case 'progress':
            progressBar.value = evt.overall_percent;
            label.textContent = `${evt.filename}: ${evt.percent}%`;
            break;
        case 'complete':
            console.log('Uploaded:', evt.document?.reference_num);
            break;
        case 'error':
            console.error(`${evt.filename}: ${evt.error}`);
            break;
        case 'batch_done':
            console.log(`Done. ${evt.succeeded} ok, ${evt.failed} failed`);
            break;
    }
});
```

**`onProgress` event types:**

| Type | Key fields |
|------|-----------|
| `progress` | `filename`, `loaded`, `total`, `percent`, `files_done`, `files_total`, `overall_percent` |
| `complete` | `filename`, `document` (DocumentRecord or null in static mode) |
| `error` | `filename`, `error` (string) |
| `batch_done` | `succeeded`, `failed`, `documents` (DocumentRecord[]) |

---

### 5.5 downloadDocument()

Triggers a browser Save-As dialog:

```typescript
docs.downloadDocument('550e8400-e29b-41d4-a716-446655440000');
```

For programmatic blob access:

```typescript
const blob = await docs.fetchDocumentBlob(referenceNum);
const url  = URL.createObjectURL(blob);
```

---

### 5.6 listDocuments()

```typescript
const { documents, total, page, last_page } = await docs.listDocuments({
    category: 'sponsorships',
    page:     1,
    per_page: 20,
});
```

---

### 5.7 deleteDocument()

```typescript
await docs.deleteDocument(referenceNum);
```

Soft-deletes the DB record and removes the physical file.

---

### 5.8 Resumable uploads (LocalStorage)

Every upload batch is saved to localStorage under `documents_upload_session_{uuid}`.  
Each file's status is updated in real time: `pending → uploading → done | error`.

After a page reload or network interruption:

```typescript
const pending = docs.getPendingSessions();
// pending: UploadSession[]  — each has .files with status 'pending' or 'error'

// Clear all sessions
docs.clearAllSessions();
```

The session is automatically cleared when a batch completes without errors.

---

## 6. Web Worker Architecture

### 6.1 UploadCoordinator

**File:** `resources/js/portal/admin/documents/workers/UploadCoordinator.ts`

The coordinator runs in a dedicated `Worker` thread spawned by `DocumentsAPI.uploadFiles()`.

Responsibilities:
1. Receive the full serialised file list from the main thread.
2. Group files by their parent directory path.
3. Process directories one at a time.
4. Within each directory, spawn up to **5 concurrent** `UploadWorker` child workers.
5. Forward `child_progress` and `child_result` messages from children to the main thread as `UploadEvent` messages.
6. Emit `batch_done` when all files have been processed.

### 6.2 UploadWorker (child)

**File:** `resources/js/portal/admin/documents/workers/UploadWorker.ts`

Each child worker:
1. Receives a single `SerializedFile` (ArrayBuffer transferred for zero-copy).
2. Builds a `FormData` from the ArrayBuffer blob.
3. Sends a `POST` request via `XMLHttpRequest`.
4. Emits `child_progress` on every XHR progress event.
5. Emits `child_result` when the XHR load or error/timeout event fires.
6. Self-terminates after completing.

### 6.3 Message protocol

**Main thread → Coordinator (`CoordinatorStartMessage`):**

```typescript
{
    type:      'start',
    files:     SerializedFile[],   // ArrayBuffers transferred
    config:    ResolvedUploadConfig,
    memberRef: string,
    uploadUrl: string,             // '/portal/api/v1/documents/upload'
    authToken: string | null,
    sessionKey: string,
}
```

**Coordinator → UploadWorker (`UploadMessage`):**

```typescript
{ type: 'upload', file: SerializedFile, config, memberRef, uploadUrl, authToken }
```

**UploadWorker → Coordinator:**

```typescript
// Progress tick
{ type: 'child_progress', relative_path, loaded, total }

// Final result
{ type: 'child_result', relative_path, success, document?, error?, loaded, total }
```

**Coordinator → main thread:**

```typescript
{ type: 'progress',   filename, loaded, total, percent, files_done, files_total, overall_percent }
{ type: 'complete',   filename, document }
{ type: 'error',      filename, error }
{ type: 'batch_done', succeeded, failed, documents }
```

### 6.4 Concurrency and directory traversal

```
files = [ a/b/1.pdf, a/b/2.pdf, a/b/3.pdf, a/b/4.pdf, a/b/5.pdf, a/b/6.pdf, a/c/7.pdf ]

Step 1: group by directory
  '' (root)  → []
  'a/b'      → [1.pdf, 2.pdf, 3.pdf, 4.pdf, 5.pdf, 6.pdf]
  'a/c'      → [7.pdf]

Step 2: process 'a/b' — batch 1 (files 1–5, 5 workers)
Step 3: process 'a/b' — batch 2 (file 6, 1 worker)
Step 4: process 'a/c' — batch 1 (file 7, 1 worker)
Step 5: emit batch_done
```

Subdirectory processing is sequential (one directory at a time) while file uploads within each directory run in parallel (up to MAX_CONCURRENT = 5).

---

## 7. External JavaScript API

### 7.1 ExternalDocumentsAPI class

**File:** `resources/js/external/documents/api.ts`

Exposed on `window.Portal.documents.Client`.

The external API provides the same upload/download/list contract as the admin API but uses **inline XHR** instead of Web Workers (because worker file bundling depends on the build tool chain which external integrators may not have).

### 7.2 Configuration

```javascript
var api = new window.Portal.documents.Client({
    baseUrl:   'https://yourdomain.com',
    memberRef: 'johndoe',
    // authToken: 'bearer-token',   // optional
});

api.configure({
    category:        'sponsorships',
    size_limit_mb:   50,
    allowed_types:   ['application/pdf', '.docx'],
    multiple:        true,
    allow_directory: false,
    save_static:     false,
});
```

### 7.3 Methods

| Method | Description |
|--------|-------------|
| `configure(opts)` | Merge config options. Chainable. |
| `setMemberRef(ref)` | Set member username. Chainable. |
| `setAuthToken(token)` | Set Bearer token. Chainable. |
| `analyseFiles(input)` | Client-side validation. Returns `ExternalFileAnalysis`. |
| `uploadFiles(input, onProgress?)` | Upload with XHR progress. Returns `ExternalDocumentRecord[]`. |
| `downloadDocument(ref)` | Trigger browser Save-As download. |
| `fetchBlob(ref)` | Fetch document as `Blob`. |
| `listDocuments(opts?)` | List documents (paginated). |
| `getPendingSessions()` | Retrieve interrupted upload sessions from localStorage. |
| `clearAllSessions()` | Remove all session data from localStorage. |

---

## 8. File Storage Paths

| Mode | Path |
|------|------|
| Standard (`save_static: false`) | `/mnt/node1/{member_reference_num}/modules/documents/{stored_name}` |
| Static (`save_static: true`) | `/mnt/node1/{member_reference_num}/documents/{original_name}` |

`member_reference_num` is the value of `window.subdomain_properties.Username` passed to the API.

The storage directories are created automatically with `0755` permissions if they do not exist.

---

## 9. File Naming (Standard Mode)

When `save_static` is `false`, the server replaces the original filename with a 32-character random string generated as follows:

1. **First character:** uniformly random lowercase ASCII letter (`a–z`).
2. **Remaining 31 characters:** uniformly random from `[a-z0-9]`.
3. **Extension:** original file extension in lowercase (e.g. `.pdf`).

Example: `aXk3mQpRz7bN9vLwYj6sTcFeDhRuOiP2.pdf`

This prevents:
- Path traversal attacks (`../../etc/passwd`)
- Filename collisions between uploads
- Leaking original filenames on disk

Both `original_name` and `stored_name` are saved in the `laravel_documents` table.

---

## 10. Categories

Categories are drawn from `resources/js/portal/ViewsCategories.ts`:

| Key constant | Value string | Use case |
|---|---|---|
| `DOCUMENTS` | `'documents'` | General documents |
| `DASHBOARD` | `'dashboard'` | Dashboard assets |
| `SETTINGS` | `'settings'` | Settings-related files |
| `SUPPORT` | `'support'` | Support attachments |
| `FINANCIALS` | `'financials'` | Financial documents |
| `ASSISTANT` | `'assistant'` | AI assistant files |
| `LISTINGS` | `'listings'` | Listing images / attachments |
| `COMMERCE` | `'commerce'` | E-commerce assets |
| `SUBSCRIBER` | `'subscriber'` | Subscriber-related |
| `SUBSCRIBERS_CATALOG` | `'subscribers_catalog'` | Subscriber catalog |
| `FORMS` | `'forms'` | Form attachments |
| `DIRECT_PAYMENTS` | `'direct_payments'` | Payment docs |
| `FEEDBACK` | `'feedback'` | Feedback attachments |
| `APPOINTMENTS` | `'appointments'` | Appointment documents |
| `EVENTS` | `'events'` | Event assets |
| `SPONSORSHIPS` | `'sponsorships'` | Sponsor documents and brand assets |

---

## 11. Client-Side Validation

Validation is performed entirely in the browser **before any network request**. The server does not re-validate using Laravel's `Validator`.

| Check | What is verified |
|-------|-----------------|
| **Type check** | `file.type` (MIME) and extension against `allowed_types`. An empty `allowed_types` array allows all files. |
| **Size check** | `file.size` (bytes) against `size_limit_bytes` (derived from `size_limit_mb × 1024 × 1024`). |

Invalid files are collected into `FileAnalysisResult.invalid_files` (or trigger `UploadErrorEvent` immediately) and are never submitted to the server.

The server still sanitises `member_reference_num` (stripping non-alphanumeric characters) for path safety.

---

## 12. Using the Admin API in a Module

Any admin controller file can import the singleton or create a fresh instance:

```typescript
import { documentsApi } from '@/portal/admin/documents';
import { ViewsCategories } from '@/portal/ViewsCategories';

// Configure once for this module
documentsApi.configure({
    category:      ViewsCategories.SPONSORSHIPS,
    size_limit_mb: 20,
    allowed_types: ['image/png', 'image/jpeg', '.pdf'],
});

// Wire to a file input
const fileInput = document.getElementById('sponsor-upload') as HTMLInputElement;
const bar       = document.getElementById('upload-bar') as HTMLProgressElement;

fileInput.addEventListener('change', async () => {
    // 1. Analyse first
    const analysis = await documentsApi.analyseFiles(fileInput.files!);
    if (!analysis.all_valid) {
        alert('Some files are invalid:\n' + analysis.invalid_files.map(f => `${f.name}: ${f.reason}`).join('\n'));
        return;
    }
    alert(`Ready to upload ${analysis.total_size_label}`);

    // 2. Upload
    const docs = await documentsApi.uploadFiles(fileInput.files!, (evt) => {
        if (evt.type === 'progress') bar.value = evt.overall_percent;
    });
    console.log('Uploaded documents:', docs);
});
```

---

## 13. Using the External API on a Public Page

```html
<!-- Load the portal external bundle -->
<script src="https://yourdomain.com/external/assets/main-abc123.js"></script>

<input type="file" id="my-files" multiple />
<progress id="pbar" value="0" max="100"></progress>
<button id="upload-btn">Upload</button>

<script>
(function () {
    var api = new window.Portal.documents.Client({
        memberRef: '{{ $memberUsername }}',  // server-rendered
    });

    api.configure({ category: 'sponsorships', size_limit_mb: 50 });

    document.getElementById('upload-btn').addEventListener('click', function () {
        var files = document.getElementById('my-files').files;

        api.uploadFiles(files, function (evt) {
            if (evt.type === 'progress') {
                document.getElementById('pbar').value = evt.overall_percent;
            }
            if (evt.type === 'batch_done') {
                alert('Done! ' + evt.succeeded + ' file(s) uploaded.');
            }
        });
    });
})();
</script>
```

---

## 14. Using the API in the Sponsors Dashboard

The sponsors dashboard (`resources/js/portal/sponsors/`) can use the admin `DocumentsAPI` directly since it is bundled as part of the admin portal:

```typescript
// In a sponsors dashboard view controller:
import { DocumentsAPI } from '@/portal/admin/documents';
import { ViewsCategories } from '@/portal/ViewsCategories';

const sponsorDocs = new DocumentsAPI();
sponsorDocs.configure({
    category:      ViewsCategories.SPONSORSHIPS,
    size_limit_mb: 100,
    allowed_types: ['image/png', 'image/jpeg', 'image/svg+xml', '.pdf'],
});

// The memberRef is picked up automatically from window.subdomain_properties.Username
// but can be overridden:
sponsorDocs.setMemberRef(app.window.subdomain_properties.Username);

// Upload brand assets
await sponsorDocs.uploadFiles(brandLogoInput.files, (evt) => {
    if (evt.type === 'progress')    progressBar.value = evt.overall_percent;
    if (evt.type === 'batch_done')  renderUploadedAssets(evt.documents);
});

// List existing brand assets
const { documents } = await sponsorDocs.listDocuments({ category: 'sponsorships' });
```

For external sponsor portals (pages outside the admin UI) use `window.Portal.documents.Client` from the external bundle as shown in section 13.

---

## 15. TypeScript Types Reference

### Admin types (`resources/js/portal/admin/documents/types.ts`)

```typescript
DocumentCategory          // union of ViewsCategories values
DocumentsUploadConfig     // configure() parameter
ResolvedUploadConfig      // fully-resolved config (with defaults)
FileAnalysisResult        // analyseFiles() return type
AnalysedFile              // entry in valid_files
InvalidFile               // entry in invalid_files
DocumentRecord            // mirrors laravel_documents row
UploadProgressEvent       // type: 'progress'
UploadCompleteEvent       // type: 'complete'
UploadErrorEvent          // type: 'error'
UploadBatchDoneEvent      // type: 'batch_done'
UploadEvent               // union of above four
CoordinatorStartMessage   // main thread → Coordinator
SerializedFile            // transferable file descriptor
ChildWorkerProgress       // UploadWorker → Coordinator (progress tick)
ChildWorkerResult         // UploadWorker → Coordinator (final result)
UploadSession             // localStorage session snapshot
ResumeFileEntry           // per-file status inside an UploadSession
```

### External types (`resources/js/external/documents/api.ts`)

```typescript
ExternalDocumentCategory
ExternalDocumentsConfig
ExternalDocumentsClientOptions
ExternalFileAnalysis
AnalysedFileInfo
InvalidFileInfo
ExternalDocumentRecord
ExternalUploadEvent
ExternalUploadProgressEvent
ExternalUploadCompleteEvent
ExternalUploadErrorEvent
ExternalUploadBatchDoneEvent
```

### Global type declarations (`resources/js/external/portal.d.ts`)

```typescript
PortalDocumentsNamespace         // window.Portal.documents
PortalDocumentsClientInstance    // instance of Portal.documents.Client
PortalDocumentsConfig
PortalDocumentRecord
PortalDocumentsFileAnalysis
PortalDocumentsUploadEvent
```

---

## 16. Security Considerations

| Concern | Mitigation |
|---------|-----------|
| **Path traversal** | `member_reference_num` is sanitised to `[a-zA-Z0-9_\-]` before being used in any filesystem path. |
| **Filename injection** | Original filenames are never used in filesystem paths in standard mode. Stored names are cryptographically random. |
| **Header injection** | `Content-Disposition` filename is URL-encoded via `rawurlencode()` and stripped of CR/LF/double-quote characters. |
| **Auth** | All API routes are protected by `ApiAuthMiddleware` (Bearer token or `portal_auth_token` cookie). |
| **File type** | Client validates type and size before upload. Server accepts whatever passes; add `allowed_types` on the client to enforce policy. |
| **Soft deletes** | Documents are soft-deleted; physical files are removed immediately on `destroy()` to avoid orphaned data. |
| **MIME type** | Stored in the DB for informational purposes; not used for access control. Do not rely on MIME type alone for security. |
