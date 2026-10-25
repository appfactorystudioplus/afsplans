# 05 — App architecture

## Completion document

Built 2026-10-05: [complete/05-app-architecture.complete.md](complete/05-app-architecture.complete.md).

## 5.1 Creating the project (P0 — **not run by this plan**)

The project does not exist yet. The standing rule against installing tooling to test planned work, and the instruction
to plan only, mean nothing below has been run. Flutter itself is already installed (`C:\flutter`); P0 creates the
project with:

```bash
cd C:\projects\vagrant\afs-eco
flutter create --org com.appfactorystudio --project-name afsbrain --platforms android afsbrain_flutter
```

That yields applicationId `com.appfactorystudio.afsbrain` (BF1), Android only, no iOS/web/desktop folders. It is its
own folder with its own `.gitignore`; `afs-eco` itself is not a git repository (each client app is), so whether
`afsbrain_flutter` gets a `git init` is your call and nothing here initialises or commits ([README](README.md)
conventions). The launcher label (`android:label`) is changed to **AFS Brain** in `AndroidManifest.xml`.

## 5.2 Dependencies

Mirrored from `afsmobile_flutter/pubspec.yaml` so code and versions are interchangeable ([BF12](00-overview.md)):

| Package | Version (sibling) | Use |
|---------|-------------------|-----|
| `flutter_bloc` | `^9.1.1` | Cubits for every feature |
| `equatable` | `^2.0.6` | Value equality for states and models |
| `http` | `^1.2.2` | The one HTTP client |
| `flutter_dotenv` | `^6.0.1` | Load the bundled `.env` ([03](03-environments-and-config.md)) |
| `intl` | `^0.20.2` | Dates, money, plural forms |
| `shared_preferences` | `^2.1.0` | Last-list cache, UI preferences (never the token) |
| `url_launcher` | `^6.3.1` | Open `mailto:` and the provider dashboard link for a charge |
| `local_auth` | current stable at P0 | App lock ([10](10-ui-and-navigation.md) §10.6), production only |
| dev: `flutter_test`, `flutter_lints ^6.0.0` | | |
| dev: `bloc_test`, `mocktail` | current stable at P0 | Cubit and repository tests |

SDK constraint `^3.9.2` like the sibling. **Not used:** `dio` (one HTTP stack is enough), `sqflite` (no local database),
`get_it`/`riverpod` (constructor injection and `RepositoryProvider` are enough at this size), Firebase (no push, [OQ7](00-overview.md)),
any Stripe/Square SDK (BF2).

`pubspec.yaml` assets: `.env` and `assets/lab/mkcert-rootCA.pem` (copied from `afsmobile_flutter/assets/lab/`).

## 5.3 Layout

```
afsbrain_flutter/
  lib/
    main.dart                      bootstrapConfig → trustLabCaInDebug → session handshake → runApp
    app.dart                       MaterialApp, theme, RepositoryProviders, route table
    config.dart                    typed .env getters (03 §3.4)
    core/
      api/
        brain_api_client.dart      the one HTTP client (§5.4)
        api_exception.dart         normalised error type + parser (§5.5)
        api_paths.dart             route SUFFIXES only: '/v1/memberships', …
      format/                      money, date, tier-label helpers (intl)
      widgets/                     env_badge, error_view, confirm_dialog, typed_confirm_dialog, loading, empty_state
      lab_ca.dart                  debug-only CA trust (03 §3.6)
    session/
      session_cubit.dart           handshake state machine (02 §2.5) + capabilities
      session_state.dart
      capabilities.dart            the `capabilities` map as a typed class; unknown ⇒ false
    features/
      members/        data/ (member_repository.dart, member_api.dart, models/)
                      cubit/ (member_list_cubit, member_detail_cubit, member_form_cubit)
                      ui/    (member_list_page, member_detail_page, member_form_page, widgets/)
      subscription/   data/ cubit/ ui/                                    (07)
      payments/       data/ cubit/ ui/                                    (07 §7.6)
      allocations/    data/ cubit/ ui/                                    (08)
      billing/        data/ cubit/ ui/                                    (Billing tab, 10 §10.5)
      closure/        data/ cubit/ ui/                                    (06 §6.6, capability-gated)
      settings/       ui/                                                  (env, token expiry, about)
  test/                            mirrors lib/; fixtures/ holds REAL captured lab2 responses (12 §12.4)
  assets/lab/mkcert-rootCA.pem
  _env/<mode>/.env  .env  .env.example
  android/                         debug-only network_security_config.xml (03 §3.6)
```

Rule for the tree: **a feature owns its data, state and UI; features do not import each other's `ui/`.** Shared widgets
live in `core/widgets`. A feature that needs another's data goes through that feature's repository interface.

## 5.4 The HTTP client (`BrainApiClient`)

One class, constructed once from `config.dart` and injected.

- **Base URL** = `portalApiUrl`; every call is `Uri.parse('$portalApiUrl$suffix')`. No other host is ever contacted
  except `REGISTRY_URL` (a separate, unauthenticated `get` method that **does not send the token**).
- **Headers**: `Authorization: Bearer <deviceToken>`, `Accept: application/json`, `Content-Type: application/json` on
  bodies, `User-Agent: afsbrain/<version>`.
- **Timeouts**: `REQUEST_TIMEOUT_SECONDS` for reads and ordinary writes; **×3** for the provider-calling subscription
  routes (`cancel`, `change-tier`, `sync`) and refunds, which wait on Stripe/Square/PayPal.
- **Redirects are never followed to another host.** The client sets `followRedirects` handling itself: a `3xx` is an error
  (`UnexpectedRedirect`), because the token must only ever reach the configured host ([02](02-authentication-device-token.md) §2.7).
- **Retries**: a `GET` is retried **once** on a connection error or timeout. **Writes are never retried** by the client — a
  timed-out `POST …/cancel` or refund may have succeeded; the UI resolves that ambiguity by **re-reading** the resource
  ([§5.6](#56-write-flow-and-the-ambiguous-timeout)).
- **Logging**: in debug, `METHOD path → status (ms)`; **never** headers, never bodies containing `password`, `token`,
  `checkpoint_session`; in release, nothing but errors without payloads. A single `redact()` function is used by every
  logging path and has a unit test that feeds it each secret-bearing shape.
- **Decoding**: `jsonDecode` of the body inside `try`; a non-JSON body (an HTML 500/502 from nginx) becomes
  `ApiException.unexpectedBody`, not a thrown `FormatException`.

## 5.5 The error model

The Brain returns at least six error shapes. The client normalises **all** of them to one type; no Cubit ever reads a raw
body.

```dart
/// What went wrong, in terms the UI can act on.
enum ApiErrorKind {
  unreachable,      // DNS/connection refused/no route — "can't reach <host>"
  tls,              // certificate rejected — the lab CA is not trusted (03 §3.6)
  timeout,
  unauthenticated,  // 401: token missing/invalid/expired/revoked → Token-rejected screen
  notAuthorised,    // 404 from a level-99 route: token valid but not the Brain member
  notFound,         // 404 from any other route
  stale,            // 409 STALE_RECORD / BS_E_STALE_RECORD
  conflict,         // 409 with failed_changes (allocation) — carries the conflicts
  validation,       // 422 — carries field errors
  provider,         // 502 / BS_E_PROVIDER / DP_E_PROVIDER_ERROR
  notAllowed,       // BS_E_NOT_ALLOWED / BS_E_SELF_ACTION
  server,           // other 5xx
  unexpectedBody,
  unexpectedRedirect,
}
class ApiException implements Exception {
  final ApiErrorKind kind; final int? status; final String? code;
  final String message; final Map<String, List<String>> fields; final Object? detail;
}
```

| Wire shape (all seen in the Brain) | Example | Maps to |
|------------------------------------|---------|---------|
| `{success:false, message}` | `PortalAdminAuthMiddleware` 401, most controllers | by status |
| `{success:false, error:{message, code}}` | `ApiAuthMiddleware` 401 (`AUTH_INVALID_TOKEN`, `AUTH_TOKEN_EXPIRED`, `AUTH_MISSING`), `STALE_RECORD` 409 | `unauthenticated` / `stale` |
| `{success:false, code:'DP_E_…', message?}` | Direct Payments | `provider` for `DP_E_PROVIDER_ERROR`, `notFound` for `DP_E_NO_SUBSCRIPTION`, … |
| `{success:false, code:'BS_E_…', message, fields?}` | new staff routes ([04](04-server-additions.md) §4.2) | per code |
| Laravel validation `{message, errors:{field:[…]}}` (422) | `$request->validate()` | `validation`, `fields` from `errors` |
| `{success:false, status:'conflict', failed_changes:[…]}` (409) | allocation `store` | `conflict` |

Special rules:

- **`404` from a `level99` route is `notAuthorised`**, because `PortalLevelNinetyNineMiddleware` deliberately answers
  404 ([BA3](01-current-state-audit.md)). The client knows which paths are level-99 from a single table in
  `api_paths.dart`; for everything else 404 is `notFound`. The allocation self-edit 404 ([BA14](01-current-state-audit.md))
  is pre-empted in the UI (BF13), so it should never be seen.
- **`401` anywhere** triggers `SessionCubit.revalidate()`; the user lands on **Token rejected**, not on a retry loop.
- A `502` carries the provider's message to the UI **as a message**, never as a stack.
- Every `ApiException` has a one-line `userMessage` (for snackbars) and a `diagnostic` (shown under "Details" on the
  error view, containing the request path, status and code — never the body).

## 5.6 Write flow and the ambiguous timeout

Every mutating Cubit method follows one shape:

```
emit(saving)
  → validate locally (reason lengths, required fields)         [no request if invalid]
  → client.send(...)
       success     → emit(saved(newState from the response))   [responses carry the refreshed resource]
       stale       → emit(staleConflict) → UI: "Changed elsewhere. Reload."  [no auto-retry]
       validation  → emit(fieldErrors)
       timeout / unreachable AFTER the request was sent
                   → emit(unknownOutcome) → re-GET the resource; show "Checking whether it went through…";
                     then saved(...) or failed(...) from what the server actually holds
       other       → emit(failed(ApiException))
```

`unknownOutcome` matters most for **cancel, change-tier, refund and member create**: each can succeed at the provider or
in Checkpoint and still lose the response. The re-read is by `reference_num` (create: by `username`), so it is safe.

## 5.7 Models

Plain immutable classes with `Equatable`, `fromJson(Map<String,dynamic>)` and — only where the app sends data back —
`toJson()`. Rules, learned from `afsmobile_flutter/lib/models/model_member.dart` (which declares `late` fields and
crashes on the first `null`):

- **No `late` fields; no casts that can throw on `null`.** Every nullable column is nullable in Dart.
- **Money is `int` cents** end to end; formatted with `intl` only in widgets. Never `double`.
- **Dates**: parse ISO-8601 to UTC `DateTime`, render in the device zone. `account_close_date` is a date-only value
  and is kept as `DateTime` at local midnight with a `dateOnly` flag so it does not slide a day across zones.
- **Enums have an `unknown` member**: `AccountStatus {active, suspended, markedForDeletion, unknown}`,
  `BillingState {current, pastDue, downgraded, unknown}`, `SubscriptionStatus {…, unknown}`. A new server value shows as
  "Unknown (<raw>)" rather than crashing.
- **Plan** is `int level 0..4`; its label comes from `create-options.plans` ([04](04-server-additions.md) BS7), cached
  for the session, with a hard-coded fallback only for display when offline.
- `Member` (list row), `MemberDetail`, `SubscriptionDetail`, `Charge`, `AllocationRow`, `Override`, `MemberEvent`,
  `Capabilities` — fields exactly as in the contracts of [04](04-server-additions.md).

## 5.8 State management

`flutter_bloc` **Cubit** per screen concern; states are `Equatable` sealed hierarchies (`initial | loading | loaded | failed`
plus feature-specific ones). Repositories are abstract classes (`MemberRepository`, `SubscriptionRepository`, …) with an
HTTP implementation and an in-memory fake used by tests; they are provided with `RepositoryProvider` at `app.dart`.

- `SessionCubit` — owns the handshake and `Capabilities`; everything else reads capabilities from it.
- `MemberListCubit` — query (`search`, filters, sort), page cursor, `loadMore`, `refresh`; debounced search (350 ms); a
  stale-response guard (a response for an old query is dropped).
- `MemberDetailCubit` — loads BS4 once; exposes the pieces; `refresh()`; after any write, replaces its state with the
  server's response and tells `MemberListCubit` to update that row in place.
- Forms (`MemberFormCubit`, reason dialogs) keep draft values in the Cubit so a rotation or a back-gesture does not lose them.

Navigation: plain `Navigator` with `onGenerateRoute` and a single route table (no new package). The bottom navigation bar
and its four tabs are in [10](10-ui-and-navigation.md).

## 5.9 Caching and persistence

- **Last member-list page** is saved to `shared_preferences` (JSON) keyed by `hash(PORTAL_API_URL)` so a cold start paints
  instantly, labelled "Cached · 3 min ago" until the network answers. Switching environment changes the key, so a lab list
  is never shown against production.
- **Nothing else is cached**: not subscriptions, not payments, not allocation rows (money and entitlement must be live).
- The **token is never stored** by the app — it is read from the bundled `.env` ([03](03-environments-and-config.md)).
- Clearing the cache is a Settings button.

## 5.10 Documentation and tests

- Every Dart file starts with a `///` library comment stating purpose and the plan reference
  (`brain_flutter/NN §x`); every class and public member has a doc comment — the same standard as
  `afsmobile_flutter/lib/config.dart`.
- Test seams: `BrainApiClient` takes an `http.Client` (so tests use `package:http/testing.dart`'s `MockClient`), repositories
  are interfaces, `Clock` is injected for "expires in N days". Details in [12](12-rollout-and-testing.md) §12.4.
- Strings are English and live next to their widgets; no `l10n` (a one-user app; `afsmobile_flutter`'s `en`/`es` setup is
  not copied).
