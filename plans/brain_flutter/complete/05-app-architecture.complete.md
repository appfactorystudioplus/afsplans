# 05 — App architecture — Completion

**Verified:** 2026-10-05 — `tool/gates.sh` fully green (G1–G9, `flutter analyze` clean with `public_member_api_docs` on, `flutter test` 198 passed). The app has also been built and run (`flutter run`, debug) on a moto g play against lab2: the handshake (`/auth/me`, `whoami`) and the member list and detail loaded with no errors in the log.

**Status:** **Complete for what exists today.** File 05 is the architecture the whole app follows, so some of its layout (the `subscription`, `payments`, `allocations`, `billing`, `closure` features) can only be built by files 07, 08 and 06b. Every rule in 05 that applies to the code that exists is met or listed below as a divergence.

## What 05 asks for, and where it stands

| § | Requirement | State | Evidence |
|---|-------------|-------|----------|
| 5.1 | Project `com.appfactorystudio.afsbrain`, Android only, label "AFS Brain" | ✅ | `flutter create --org com.appfactorystudio --project-name afsbrain --platforms android`; manifest label |
| 5.2 | Dependencies mirrored from the sibling | ✅ with notes | `flutter_bloc`, `equatable`, `http`, `flutter_dotenv`, `intl`, `shared_preferences`, `url_launcher` are all used. **`local_auth`** is not added (app lock is P8). **`bloc_test` and `mocktail` were removed**: they were declared but never used (tests use `flutter_test` with hand-written fakes). SDK constraint is `^3.13.4` (what `flutter create` generated), not the sibling's `^3.9.2`. `.env` and the lab CA pem are assets |
| 5.3 | Layout | ✅ for what exists | `main.dart`, `app.dart`, `config.dart`; `core/api` (`brain_api_client`, `api_exception`, `api_paths`, plus `error_parser`, `redact`); `core/format.dart`, `core/json.dart`, `core/lab_ca.dart`, `core/widgets` (`env_badge`, `error_view`, `confirm_dialogs`); `session/` (cubit, state, capabilities, `ui/session_failure_page`); `features/members` (data, cubit, ui, `ui/widgets/chips`), `features/settings`. Rule "a feature owns its data, state and UI; features do not import each other's `ui/`" is **enforced by gate G9** |
| 5.4 | HTTP client | ✅ | `BrainApiClient`: one base URL from config; the registry fetch is a separate method that never sends the token; `Authorization`, `Accept`, `Content-Type`, `User-Agent: afsbrain/<version>` (the real version, from `core/app_info.dart`); timeout ×3 for provider-calling calls (`slow`; member create uses it); a 3xx is an error and is never followed; a GET is retried once on a connection error or timeout, a write never is; debug logging is `METHOD path -> status (ms)` only, through the single `redact()` (unit-tested against Bearer, JSON secrets, env line, query secrets); a non-JSON body is `unexpectedBody` |
| 5.5 | Error model | ✅ | Fourteen `ApiErrorKind`s; `parseErrorResponse` handles every wire shape in the table **including the staff routes' `{code, message, fields}`** (field errors read from `fields` or Laravel's `errors`); `BS_E_NOT_FOUND`, `BS_E_NO_SUBSCRIPTION` → `notFound`; 404 from a level-99 route → `notAuthorised`; any 401 re-runs the handshake; every exception has `userMessage` and a body-free `diagnostic` shown under "Details" |
| 5.6 | Write flow and the ambiguous timeout | ✅ | `MemberDetailCubit._write`: saving → success re-reads / stale / validation / failed; a timeout or unreachable re-reads the member and compares `updated_at` (saved if it moved, failed with the original error if not, never re-sent). Member create resolves a lost response by looking the member up by username |
| 5.7 | Models | ✅ for the models that exist | No `late` fields (gate G5), no throwing casts (`core/json.dart` readers), enums with an `unknown` member, dates in UTC with date-only values kept as calendar days, plan labels from the server (see below). `SubscriptionDetail`, `Charge`, `AllocationRow` belong to files 07 and 08 |
| 5.8 | State management | ✅ with divergences | Cubit per concern; `SessionCubit`, `MemberListCubit` (350 ms debounce, stale-response guard), `MemberDetailCubit` (tells the list to update the row in place after a write), `MemberFormCubit`. Repositories are interfaces with an HTTP implementation and a fake in tests. **`BrainApiClient`, `MemberRepository` and `MemberListCache` are provided once at the root** (`app.dart`) |
| 5.9 | Caching | ✅ | First default-query page cached in `shared_preferences`, keyed by a hash of the API base URL, shown as "Cached · N min ago", cleared from Settings; nothing else cached; the token is never stored by the app |
| 5.10 | Documentation and tests | ✅ | Every `lib` file opens with a `///` library comment (gate G8); every public member is documented (`public_member_api_docs` is on in `analysis_options.yaml`, so it cannot regress); `http.Client`, repositories and the clock are injectable (settings page and list cubit take a clock). English only, no `l10n` |

## Fixed while auditing against this file

- **Layering:** `chips.dart` lived in `core/` but depended on a feature's models; moved into the members feature. The session failure page lived in `core/` but depended on session state; moved to `session/ui/`. Gate G9 now fails if `core/` imports a feature or the session, or one feature imports another's UI.
- **Hidden global config:** the failure page read the environment label from `config.dart` through `EnvBadge`, so it could not render without dotenv loaded. It now takes `envLabel` and `isProduction` like the shell does, and the config-reading `EnvBadge` was deleted.
- **Error shapes:** the staff routes (file 04 BS6) answer `{code, message, fields}`; the parser only read Laravel's `errors`, so field errors from `BS_E_VALIDATION` would have been dropped. Fixed and tested.
- **User-Agent** said `afsbrain/0.1.0` regardless of the build; it now uses the real version.
- **Plan labels** were read only from a built-in list on the list and detail screens; the shell now fetches `create-options.plans` once per session and `planLabel()` prefers them, with the built-in list as the offline fallback (plan 05 §5.7).
- **Repository provision:** the detail route used to be given its own `MemberRepository` provider; it is now read from the root provider.

## Divergences (deliberate, kept)

- **`MemberListState` is one `Equatable` class with a status enum**, not a sealed `initial | loading | loaded | failed` hierarchy: the list must keep its rows on screen while a refresh or a "load more" fails, which a sealed hierarchy makes awkward. Same for `MemberDetailState`.
- **Navigation is `Navigator.push(MaterialPageRoute)`**, not `onGenerateRoute` with a single route table. There are two pushed destinations today (detail, add); a table can be introduced with the next one.
- **Form drafts live in the page's `State`**, not in the Cubit. A rotation keeps the `State`; a process death does not (the plan also says deep state does not survive an app restart).
- **No `member_api.dart`**: the data source is merged into `HttpMemberRepository` (one class, ~110 lines).
- **`core/format.dart` is one file**, not a folder; **`loading` and `empty_state` widgets are inline** (a `CircularProgressIndicator` and a `Text`), not separate widgets, until a second screen needs them.
- **Test fixtures are hand-written from the server code, not captured** (05 and 12 §12.4 want real lab2 responses). `test/fixtures/README.md` has the capture commands; capturing needs the device token, so it is left to the user.

## Not verified

- On-device behaviour of P1–P3 beyond "the list and a detail loaded": the edit, plan, status, add and remove flows have not been exercised against the lab (the server routes were deployed but those writes were not run).
- The server tests that back the client's contract (file 04) are written but not executed.
