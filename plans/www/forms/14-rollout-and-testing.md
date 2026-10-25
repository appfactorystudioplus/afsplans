# 14 — Rollout and Testing

## 14.1 Phases

| Phase | Contents | Docs | Depends on |
|---|---|---|---|
| **P0** | **Security gate** — auth, site scoping, IDOR, the missing `deleteForm`, throttling, server-side effects, the file pipeline, escaping | [02](02-security-and-tenancy.md), [11](11-integrations-and-notifications.md) §11.2–§11.4 | nothing |
| **P1** | **Data foundation** — `TEXT`→`LONGTEXT`, versions, answers, files, events, the encoding migration, publish semantics | [04](04-database-schema.md), [09](09-publishing-and-versioning.md) | P0 |
| **P2** | **The canvas** — runtime-rendered design mode, session lifecycle, dirty/autosave, undo, naming, the choices editor | [05](05-form-builder.md) §5.2, §5.5, §5.6, §5.9, §5.10 | P1 |
| **P3** | **Configuration depth** — full type coverage, the properties panel, layout | [05](05-form-builder.md) §5.3, §5.4, [07](07-theming-and-layout.md) §7.6 | P2 |
| **P4** | **Logic and validation** — conditions, validators, expressions, triggers, server-side enforcement | [06](06-logic-and-validation.md) | P3 |
| **P5** | **Operator surface** — submissions list, detail, export, results, PDF | [10](10-submissions-and-data.md), [13](13-admin-dashboard.md) | P1 |
| **P6** | **Theming, i18n, publishing polish** — theme editor, translations, share, embeds | [07](07-theming-and-layout.md), [08](08-multi-language.md), [09](09-publishing-and-versioning.md) §9.6 | P2 |
| **P7** | **Integrations** — webhooks, afs-connect events, Site Users, paid forms, SDK v2 | [11](11-integrations-and-notifications.md), [12](12-externals-sdk.md) | P1, P5 |
| **P8** | **Allocation** — forms per site, the monthly submission meter, the server-side ceiling, level 99 | [15](15-allocation.md) | **P0**, P1 |

P0 ships alone, on its own branch, with no feature work attached — a security fix that arrives
inside a feature release is a security fix that waits for the feature.

**P5 depends only on P1**, so the operator surface can be built in parallel with the editor work
in P2–P4. Those are the two largest bodies of work and they touch almost disjoint files.

### Order that is not negotiable

1. **P0 before everything.** The API is open.
2. **`submission_data` TEXT→LONGTEXT before anything else in P1.** It is one line, it is
   backward compatible, and until it lands the module is silently corrupting signature and
   long-form answers ([04](04-database-schema.md) §4.12).
3. **Encoding migration before P4.** Conditional logic fills definitions with `<`, `>`, `&&` and
   quoted strings — exactly what `htmlentities` mangles (**F15**).
4. **Versions before P4.** Logic changes are the changes most likely to invalidate old
   submissions (**F17**).
5. **P0 before P8.** A client-side `canUse` in front of an unauthenticated `POST /forms` is
   decoration ([15](15-allocation.md) **F66**), and the server-side submission ceiling needs the
   site scoping P0 introduces to know whose quota it is spending.
6. **The canvas (P2) before new question types (P3).** Every type added to the hand-written
   renderer is another type to hand-draw and another way for the editor to disagree with the
   respondent's view (**F40**).

## 14.2 The security gate

P0 does not ship until every one of these is demonstrated, by test:

| # | Gate |
|---|---|
| 1 | No admin forms route responds to an unauthenticated request with anything but 401 |
| 2 | `GET /forms` as tenant A never returns tenant B's forms |
| 3 | Another tenant's form returns **404**, not 403 |
| 4 | No route accepts an integer submission id |
| 5 | `DELETE /forms/{ref}` works and does not 500 |
| 6 | The public projection contains no `id`, no `website_*`, no draft, no counts — asserted field by field |
| 7 | An unpublished form 404s publicly |
| 8 | Submit is throttled per IP and per form |
| 9 | A submission with markup in `email` renders as text in the admin |
| 10 | `renderer.ts` contains no `mailHandlerApi`, `notificationsApi` or `analyticsApi.forms.completed` call |
| 11 | A `curl` cannot cause mail to be sent to an address it names |
| 12 | An uploaded file is not retrievable without ownership or the submission reference |
| 13 | The browser cannot choose a stored filename |
| 14 | An unclaimed upload is swept after 24 h |
| 15 | `AFS_FORMS_MODIFIER_ADDITION` is registered in [`../../markers.md`](../../markers.md) |

## 14.3 Migrating what already exists

Every deployment has forms with NULL scoping columns (**F3**), entity-encoded definitions
(**F15**), no versions, and submissions with no version pointer.

| Step | Command | Notes |
|---|---|---|
| 1 | migrations 1–8 | [04](04-database-schema.md) §4.12. Additive, reversible |
| 2 | `forms:backfill-sites --dry-run` | Attribute forms to sites via `BuilderOutput` (`typename='forms'`) and afs-connect history. **Dry run first**, and inspect |
| 3 | `forms:backfill-sites` | Unattributable rows keep `website_reference_num = null` and appear in an "unassigned" admin list — never guessed, never deleted |
| 4 | `forms:migrate-encoding --dry-run` | Decode/re-encode, compare round-trips, report failures |
| 5 | `forms:migrate-encoding` | In batches, with the `&quot;` sniff still active |
| 6 | `forms:seed-initial-versions` | One version per form from its current draft, `note: 'migrated'`, so every existing submission can point at something |
| 7 | `forms:link-submissions-to-versions` | Every existing submission → that form's v1. It is not strictly true — the definition changed under them — and it is the best available, so **the version is marked `migrated` and the admin says so** rather than implying precision that does not exist |
| 8 | `forms:rebuild-answers` | Decompose existing documents ([04](04-database-schema.md) §4.5) |
| 9 | `forms:fix-preview-property` | Rewrite `showPreviewBeforeComplete` to the shape the bundled build reads ([07](07-theming-and-layout.md) §7.3) |

Every command: `--dry-run`, batched, resumable, idempotent, and logging a per-row outcome.

**Step 3 is the one with an outage risk.** Until it completes, scoped queries return nothing and
tenants see empty form lists. Run it in the same maintenance window as the migrations, verify
counts before and after, and keep the pre-migration count to compare against.

## 14.4 Verification after migration

| Check | Expectation |
|---|---|
| Form count before vs after | Identical |
| Submission count before vs after | Identical |
| Forms with `website_reference_num IS NULL` | Reported, and reviewed by a human |
| `json_data LIKE '%&quot;%'` | Zero after step 5 |
| Every form has ≥ 1 version | Yes |
| Every submission has `form_version_id` | Yes |
| `laravel_form_answers` count | Equals the sum of answered questions across documents |
| A sample of 20 submissions rendered before and after | Identical output |
| Definitions that failed to decode | Zero — and any failure blocks the step rather than skipping the row |

## 14.5 Standing checks

Enforced in CI, not remembered:

| Check | Guards |
|---|---|
| No admin query on `laravel_forms` without a `website_reference_num` predicate | **F2**, **F4** |
| No `innerHTML` assignment containing a `${` template literal in the forms controllers | **F35**, **F36** |
| No write to `laravel_form_versions` outside the publish action | [09](09-publishing-and-versioning.md) §9.4 |
| No `FunctionsController::Utils_encodeJson` call in forms code paths | **F15** |
| No import of `survey-core` from npm in forms code | **F49**, [12](12-externals-sdk.md) §12.8 |
| No `mailHandlerApi` / `notificationsApi` call in `external/forms/` | **F9**, **F10** |
| `portal.d.ts` matches the SDK's exported surface | [12](12-externals-sdk.md) §12.6 |
| Every changed file carries `AFS_FORMS_MODIFIER_ADDITION` | [README](README.md) |

## 14.6 The property-verification pass

The plan names roughly 120 SurveyJS properties. Every one must be a property the **bundled
v2.5.20 build** reads — not one the current online documentation describes
([00](00-overview.md) §0.4).

Before P3 starts:

1. Extract the property list from [05](05-form-builder.md) §5.4,
   [06](06-logic-and-validation.md) and [07](07-theming-and-layout.md).
2. For each, confirm against `public/assets/libs/surveyjs/survey.core.min.js` — by
   `Serializer.findProperty()` in a browser console against the loaded globals, which is
   authoritative, rather than by grepping a minified bundle, which is not.
3. Record the result in a checked-in table: property · level · confirmed · notes.
4. **Every property marked *verify* in [06](06-logic-and-validation.md) and
   [07](07-theming-and-layout.md) is resolved here**, including `showPreviewBeforeComplete`
   (**F47**), input masking, `resetValueIf`/`setValueIf`, `choicesFromQuestion`, and the timer
   property names.
5. Properties the build does not support are **removed from the panel**, not shipped as controls
   that do nothing.

That table is also the upgrade checklist: bumping the bundled runtime reruns it.

## 14.7 The test matrix

### Backend

| Area | Cases |
|---|---|
| Auth | Every route, authenticated and not, own tenant and other |
| Scoping | Two tenants, two sites, list/read/write/delete each way |
| Submit | Valid · required missing · invalid choice · unknown question · failing validator · hidden-and-required · oversized · throttled · duplicate idempotency key · to a closed form |
| Expressions | Every supported operator and function; unparseable → `unverified`, not rejected |
| Versions | Publish · republish unchanged · restore · immutability · delete-with-submissions refused |
| Files | Upload · claim · orphan sweep · unauthorised download refused · purge deletes from disk |
| Effects | Mail sent · retried · failed and logged · idempotent on retry · webhook signature verifies |
| Encoding | Legacy and new rows both readable through the migration window |
| Export | Wide · long · cross-version · renamed question · 20k rows queued |

### Front-end

| Area | Cases |
|---|---|
| Canvas | Renders through the runtime · theme applies · every type displays |
| Add | Click and drag; touch and keyboard |
| Naming | Uniqueness · rename refactors references · duplicate refused at save |
| Choices | Value preserved over ten cycles · comma in text · clear works · same editor for every choice type |
| Logic | Build → text → build round-trip · unrepresentable expression preserved · badges shown |
| Validation | Each validator configurable, with a message, enforced in preview |
| Session | Form A's theme does not leak into form B · unload guard · recovery · 409 on concurrent save |
| i18n | Add locale · translate · fallback per string · switch preserves answers · RTL |
| Escaping | Markup in a form name, a question title and a submission email all render as text |
| A11y | Full keyboard operation · focus management · ARIA roles |

### End-to-end

Six journeys, each exercising a whole vertical:

1. **Build → publish → embed → submit → email → export.** The core loop.
2. **Branching form** — conditions, triggers, a skip, and a submission proving the server
   enforced the same logic the client showed.
3. **Repeating group** — a dynamic panel with three entries, aggregated by `sumInArray`,
   decomposed into answers, exported both wide and long.
4. **Edit a live form** — publish v2, confirm v1 submissions still render with v1's labels.
5. **Multi-language** — author in two locales, submit in each, confirm choice values match and
   aggregate as one.
6. **Abuse** — `curl` the public endpoints attempting each of the fifteen gates in §14.2.

## 14.8 Performance targets

| Operation | Target |
|---|---|
| `GET /forms/{ref}` (published, cached) | < 100 ms |
| `POST /forms/submit` (validation + write; effects queued) | < 300 ms |
| Submissions list, 100k rows, filtered | < 500 ms |
| Per-question aggregate, 100k submissions | < 1 s |
| CSV export, 10k submissions | queued, < 60 s |
| Editor load, 100-question form | < 2 s |
| Canvas re-render on a property change | < 100 ms |

The published projection is the one worth caching hard: it is immutable per version
([04](04-database-schema.md) §4.3), so it can be cached until `current_version_id` changes,
with no invalidation subtlety.

## 14.9 Rollback

| Phase | If it goes wrong |
|---|---|
| P0 | Revert the routes. **Do not** revert the escaping or the removal of client-side mail |
| P1 | Migrations are reversible except `TEXT`→`LONGTEXT`, which is safe to keep. The encoding flag makes both encodings readable, so step 5 can stop mid-way |
| P2–P4 | Feature-flag the new editor per tenant; the old one stays importable for one release |
| P5–P7 | Additive views and jobs; disable the flag |

The one irreversible step is **purge** ([02](02-security-and-tenancy.md) §2.6), which is why it
is a separate, explicitly confirmed action rather than what Delete does.

## 14.10 Definition of done

Beyond the acceptance lists in each document:

- Every finding **F1**–**F61** is closed, or explicitly deferred with a reason recorded in the
  phase's completion document.
- The property-verification table (§14.6) is checked in and complete.
- No control in the editor writes a property the bundled runtime does not read.
- Every migration command has been run against a copy of production data, with §14.4's checks
  passing.
- `imp_forms_main.ts` is deleted.
- `portal.d.ts` is current, and the consuming copies are refreshed
  ([12](12-externals-sdk.md) §12.6).
- Each numbered document has a completion document in [`complete/`](complete/) — status,
  summary, files changed, verification steps, follow-ups.
