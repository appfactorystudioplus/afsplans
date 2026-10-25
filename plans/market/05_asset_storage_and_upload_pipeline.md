# Step 05 — Asset storage, filename analysis, and the upload pipeline

**On completion:** write `complete/05_asset_storage_and_upload_pipeline_complete.md`.
**Depends on:** [03](03_market_database_schema.md), [04](04_internal_cross_api.md).
**Blocks:** 06, 12, 17, 18.

---

## 05.1 Layout on disk

> **Tagging (step 01 §01.4).** Every file this step creates or modifies carries
> `MARKET_MODIFIER_ADDITION` — created files in the top doc block, modified files at each changed
> line or region. `grep -rn MARKET_MODIFIER_ADDITION` is the change index for this whole effort.


```
/mnt/node2/market/members/
└── {member_reference_num}/                 e.g. m1xTHpRmdtH2Y
    ├── assets/
    │   └── {asset_reference_num}/          as_XXXXXXXX
    │       ├── versions/
    │       │   └── {version}/              1.0.0
    │       │       ├── package.zip         the canonical archive
    │       │       └── package.sha256
    │       └── previews/
    │           ├── cover.png
    │           └── gallery-01.png
    ├── services/
    │   └── {service_reference_num}/
    │       ├── gallery/
    │       └── deliverables/{service_order_reference_num}/
    └── tmp/
        └── {upload_session_id}/            chunk staging, GC'd after 24h
```

Rules:

- The root comes from `config('market.storage.root')`. **The root is the only configurable part** —
  the member/asset/version segments are computed by the application. A configurable per-member path
  template is a path-traversal surface with no upside.
- `archive_path` in the database is stored **relative** to the root. Moving the mount must not
  require a data migration.
- Directories `0750`, files `0640`, owned by the PHP-FPM user, group-shared with nginx so the
  internal-redirect download can read them.
- Nothing under this root is web-served. Nginx exposes it only through an `internal;` location.

**Preconditions before any code runs:** `/mnt/node2` is mounted, writable by the web user, and has
headroom. The `market_assets` disk uses `'throw' => true` so a failed write raises rather than
returning `false` — a silently failed asset upload that reports success is the worst outcome
available here.

---

## 05.2 Client-side filename analysis (members)

> *"on the client side before upload analyze each file to make sure that they are appropriate file
> names for the linux file system if not then alert the member of the malformed file name and for
> them to change it before the upload can happen."*

New module: `members/resources/js/market/FilenameAnalyzer.ts`. Pure, dependency-free, unit-testable,
no DOM. Takes a `File[]` (from a folder drop or `<input webkitdirectory>`), returns a report.

### Rule table

Each rule states what it checks, why, and whether it blocks.

| # | Rule | Why | Severity |
|---|---|---|---|
| 1 | Path segment is empty | Unrepresentable | **block** |
| 2 | Segment is `.` or `..` | Traversal | **block** |
| 3 | Contains a NUL byte (`U+0000`) | Illegal in every filesystem; truncates C strings | **block** |
| 4 | Contains a control char `U+0001`–`U+001F`, or `U+007F` | Legal on ext4, breaks every shell/log/tar pipeline that touches it | **block** |
| 5 | Contains `/` inside a segment | Separator | **block** |
| 6 | Contains a backslash | Windows separator that becomes a literal filename char on Linux | **block** |
| 7 | Contains any of `: * ? " < > \|` | Illegal on Windows/macOS — the buyer's machine, not the server's | **block** |
| 8 | Segment > 255 **bytes** UTF-8 | ext4/XFS `NAME_MAX` — count bytes, not JS `.length`; `"日本語"` is 3 chars and 9 bytes | **block** |
| 9 | Full relative path > 4096 bytes | `PATH_MAX` | **block** |
| 10 | Leading `-` | Reads as a flag to every CLI tool: `rm -myfile` | **block** |
| 11 | Trailing `.` or space | Silently stripped by Windows; `"a. "` and `"a"` collide on extract | **block** |
| 12 | Leading/trailing whitespace anywhere | Invisible; produces "file not found" with a correct-looking name | **block** |
| 13 | Windows reserved base name (`CON`, `PRN`, `AUX`, `NUL`, `COM1`–`COM9`, `LPT1`–`LPT9`), with or without extension, case-insensitive | Cannot be created on Windows at any path | **block** |
| 14 | Two paths differing only by case | Linux keeps both; macOS/Windows collide and one silently overwrites the other on extract | **block** |
| 15 | Duplicate identical path | Ambiguous archive | **block** |
| 16 | Not valid UTF-8 / contains unpaired surrogates | Mojibake on extraction | **block** |
| 17 | Contains a bidi override (`U+202A`–`U+202E`, `U+2066`–`U+2069`) | Filename-spoofing: a right-to-left override makes an executable display as an image | **block** |
| 18 | Zero-width chars (`U+200B`–`U+200D`, `U+FEFF`) | Invisible; two "identical" names | **block** |
| 19 | Non-ASCII (accents, CJK, emoji) | Works fine on Linux, but tooling varies | **warn** |
| 20 | Unicode not in NFC | macOS produces NFD; the same accented name differs byte-wise across platforms | **warn**, offer normalize |
| 21 | `.DS_Store`, `Thumbs.db`, `desktop.ini`, `__MACOSX/`, `.git/`, `node_modules/` | Junk that bloats the package | **warn**, offer exclude |
| 22 | Symlink or alias | Cannot be represented safely; may point outside | **block** |
| 23 | 0-byte file | Usually an accident | **warn** |
| 24 | Single file > `VITE_MARKET_MAX_UPLOAD_MB`, or total over it | Rejected server-side anyway | **block** |

### Report shape

```ts
/** MARKET_MODIFIER_ADDITION */
export interface FilenameIssue {
  path: string;          // relative path as the browser reported it
  rule: number;          // the table row above
  severity: 'block' | 'warn';
  reason: string;        // i18n key, resolved for display
  suggestion?: string;   // a safe rename, when one is computable
}
export interface AnalysisReport {
  files: number;
  totalBytes: number;
  issues: FilenameIssue[];
  blocked: boolean;      // any severity === 'block'
}
```

### The dialog

When `blocked`, a modal — not an `alert()`. The existing controllers use `alert()` throughout; that
cannot present twelve issues.

- Grouped by rule, each group with a one-line plain-language explanation.
- Per row: the offending path with the bad characters visibly highlighted — control and zero-width
  characters rendered as visible placeholder glyphs. Otherwise the member is being shown two
  identical-looking strings and told they differ.
- A copyable list of `old path → suggested path`, so the fix can be applied in a file manager.
- **Upload stays disabled.** No override. Per the brief: *"before the upload can happen."*
- Warnings render in a separate, dismissible section with a "Fix automatically" action for
  normalization and junk exclusion.
- Every string is an i18n key in `members/resources/js/i18n/en.json` under `market.upload.*`.

`suggestion` generation: strip control/zero-width/bidi, replace illegal chars with `-`, collapse
runs, trim leading `-` and trailing dots/spaces, NFC-normalize, truncate to 255 bytes preserving the
extension, de-duplicate with `-2`, `-3`. When a suggestion cannot be computed (case collision), say
what to do instead: "rename one of these two files".

---

## 05.3 Zipping

The brief says assets are zipped, with analysis client-side before upload. Concretely:

1. Analyze the raw `File[]` client-side. Blocked → stop.
2. Build the archive **in the browser** (`CompressionStream` where available, otherwise a bundled
   zip library), so one artifact is uploaded instead of N requests and the archive's entry names are
   exactly the names that were analyzed.
3. Compute SHA-256 of the archive client-side; send it with the finalize call.
4. **The server re-runs the entire rule table over the archive's entries.** Not "again for safety" —
   because the client's report is a *convenience for the member*, and the archive is attacker-
   controlled input. A hand-crafted zip that never touched the analyzer must be rejected identically.

Server re-validation additionally enforces what the client cannot see:

- **Zip-slip**: any entry whose normalized path escapes the extraction root → reject the upload.
- Absolute entry paths, entries with a drive letter → reject.
- Entry-type check: only regular files and directories. No symlinks, no devices.
- **Zip-bomb**: total uncompressed size, compression ratio (reject above 200:1), and entry count caps.
- MIME sniffing per entry, recorded in `asset_version_files.mime`.

A rejected archive sets `asset_versions.status = 'failed'` and returns a `422` whose `errors` map
carries the same `FilenameIssue[]` shape, so the members UI renders server rejections through the
identical dialog. One renderer, two sources.

---

## 05.4 Upload transport

Chunked and resumable — packages are hundreds of MB and a failed 400 MB upload that restarts from
zero is not a feature, it is a reason not to sell here.

| Method | Path | Purpose |
|---|---|---|
| `POST` | `/assets/{ref}/versions` | Begin. Body: version, changelog, archive sha256, total bytes, file manifest. Returns `upload_session_id`, `chunk_size`, and `received_chunks` (non-empty on resume). |
| `PUT` | `/uploads/{sessionId}/chunks/{n}` | One chunk. Body raw bytes + per-chunk sha256. Idempotent — re-sending a stored chunk is a `200`, not an error. |
| `POST` | `/assets/{ref}/versions/{v}/finalize` | Assemble from `tmp/`, verify whole-archive sha256, run §05.3 validation, move into place atomically, write `asset_version_files`, mark `ready`. |
| `DELETE` | `/uploads/{sessionId}` | Abandon; delete staging. |

- Chunk default 8 MB, from config.
- Staging under `{member}/tmp/{sessionId}/`; a scheduled command GCs sessions older than 24h.
- Assembly writes to `versions/{v}/package.zip.part`, then renames — atomic within the same
  filesystem, so a crashed finalize can never leave a half-written `package.zip` that looks complete.
- Whole-archive sha256 mismatch → delete, `422`, no partial state.
- Members side proxies chunks through `MarketClient` with the 900s upload timeout. Progress is
  reported to the browser per chunk.

---

## 05.5 Downloads

Two consumers — the `/portal` dashboard (step 12) and the engine (step 18) — one mechanism.

1. Consumer requests a download for an entitlement it holds.
2. Market verifies: entitlement exists, `revoked_at` is null, version is `ready`, and — for the
   engine — `distribution_tag = joystick_engine`.
3. Market issues a **short-lived signed URL** (TTL from `MARKET_ASSET_DOWNLOAD_TTL_SECONDS`,
   default 300s) bound to the entitlement, the version, and the requesting member.
4. Redemption serves the file via nginx `X-Accel-Redirect` into the `internal;` location.
5. An `asset_downloads` row is written; the `assets.downloads` counter is recomputed from it.

**Never stream a multi-hundred-MB file through PHP.** It pins an FPM worker for the whole transfer,
and a dozen concurrent downloads exhaust the pool and take the storefront down with them.

Nginx (documented in the completion report so ops can apply it):

```nginx
# MARKET_MODIFIER_ADDITION
location /__market_assets/ {
    internal;
    alias /mnt/node2/market/members/;
    sendfile on;
    aio threads;
}
```

Signed URLs are single-consumer but **not** single-use — a resumed download re-issues `Range`
requests against the same URL, and one-shot tokens break resume. Expiry plus the audit row is the
correct trade.

---

## 05.6 Previews

Cover images and gallery media are public and served normally from `public/` (or a public disk), not
from `/mnt/node2`. They go through the same filename analysis but are additionally re-encoded
server-side (strip EXIF, cap dimensions, convert to WebP with a JPEG fallback). Re-encoding is not
cosmetic: it is what guarantees a "PNG" is actually a PNG and not a polyglot file.

---

## 05.7 Retention

| Event | Files |
|---|---|
| Version withdrawn | Kept. Someone may own it. |
| Asset unpublished | Kept. Entitlements survive delisting. |
| Asset soft-deleted with entitlements | Blocked at the API (step 04). |
| Asset deleted with no entitlements | Files deleted after a 30-day grace window. |
| Member account closed | Files retained; entitlement downloads keep working. |
| Upload abandoned | `tmp/` GC after 24h. |

The through-line: **a buyer's ability to re-download what they paid for does not depend on the
seller's continued goodwill.** Every row above follows from that.

---

## 05.8 Verification

- [ ] A folder containing `bad name.png`, `-flag.txt`, `CON.cs`, `Assets/A.png` + `Assets/a.png`, a 300-byte name, and a `.DS_Store` produces exactly six findings: five blocks and one warning, each on the right rule.
- [ ] Upload button stays disabled while any block is present, and enables the moment the last one is resolved.
- [ ] A zip hand-crafted with a `../../etc/passwd` entry posted directly to finalize → `422`, nothing written outside the root.
- [ ] A 10 KB zip that expands to 4 GB → rejected on ratio.
- [ ] Kill the browser mid-upload; resume returns the correct `received_chunks` and completes without re-sending them.
- [ ] Corrupt one chunk in transit → finalize fails on sha256, no `package.zip` exists.
- [ ] `ls -l` the version directory: `0640`, correct owner.
- [ ] Signed download URL works; the same URL 301 seconds later returns `403`; another member's ref against the same URL returns `403`.
- [ ] Download served with an nginx-sized `Content-Length` and no PHP memory spike.
- [ ] Every file created or modified by this step carries `MARKET_MODIFIER_ADDITION` (§01.4). Verify by `grep -rn`, and reconcile the result against this step's Files section — a file in the list without a tag, or a tagged file not in the list, are both defects.
- [ ] `complete/05_asset_storage_and_upload_pipeline_complete.md` written, including the nginx block.
