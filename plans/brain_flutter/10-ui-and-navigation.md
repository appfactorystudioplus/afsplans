# 10 — UI and navigation

## Completion document

Built 2026-10-05 (the app icon belongs to file 11; device verification pending): [complete/10-ui-and-navigation.complete.md](complete/10-ui-and-navigation.complete.md).

## 10.1 Principles

1. **One thumb, one hand.** Primary actions in the lower half; the list and the detail are scroll-first.
2. **The server decides what exists** (BF10): tabs, buttons and menu items appear only when `capabilities` says so.
3. **Never ambiguous about where you are acting** — the environment badge is always visible (§10.2).
4. **Destructive means deliberate:** reason fields, typed confirmations on production, no single-tap irreversible action.
5. **Honest states:** every list has loading, empty and error states; every error shows the server's message and a Retry.
6. Material 3, system light/dark, large-text safe, minimum 48 dp touch targets. English only (no `l10n`).

## 10.2 The environment badge

A slim, always-on strip under the status bar (and a coloured launcher-icon-independent app-bar tint):

| `ENV_LABEL` | Colour | Meaning |
|-------------|--------|---------|
| `LAB2`, `VAGRANT`, `VAGRANT-LAN` | blue | A lab; safe to experiment |
| `DEV` (development) | amber | Development servers |
| `PRODUCTION` | **red** | Real members, real money |

It also shows the host (`www.10-0-0-243.sslip.io`) in small type, and the SANDBOX/LIVE chip appears on every money screen
([07](07-subscriptions-direct-payments.md) §7.3). The badge's text comes from `ENV_LABEL`, its colour from `IS_PRODUCTION` +
the label — never from inspecting the URL.

On `production` the following gain a **typed confirmation** (type the member's username, or the amount for a refund): cancel
subscription, refund, mark for deletion, soft/hard delete, remove override, global afs-connect flag.

## 10.3 Navigation map

Bottom `NavigationBar`, four destinations (a destination absent when its capability is false):

```
 ┌──────────────────────────────────────────────┐
 │ ▌LAB2 · www.10-0-0-243.sslip.io              │  ← environment badge
 ├──────────────────────────────────────────────┤
 │                                              │
 │            (current destination)             │
 │                                              │
 ├──────────────────────────────────────────────┤
 │  Members     Billing     More     Settings   │
 └──────────────────────────────────────────────┘
```

| Destination | Contents | Capability |
|-------------|----------|-----------|
| **Members** | List (search, filters) → Detail (tabs) → Add | `members` |
| **Billing** | Overview (enforcement, counts, needs-attention) → Ledger (all charges) | `billing_overview`, `money_ledger` |
| **More** | Checkpoint errors · afs-connect · Apps (if P9 is built) | `afs_connect`, `apps` |
| **Settings** | Environment & host, signed-in member, token expiry, capabilities (debug list), clear cache, about/version | always |

Detail pages push on top of the bar; the system back gesture pops. Deep state (selected tab, scroll, search text) survives
rotation and process death within a session via `RestorationMixin`/Cubit state; it does not survive an app restart (the list cache
does, §10.7).

## 10.4 Screens

| Screen | Key elements | Spec |
|--------|--------------|------|
| **Splash / handshake** | Logo, "Connecting to LAB2…", then the three failure screens | [02](02-authentication-device-token.md) §2.5 |
| **Not paired / Token rejected / Not the Brain member / Wrong identity** | Cause, env, host, the exact `php artisan brain:device-token …` command with Copy, Retry. **No text field** | [02](02-authentication-device-token.md) |
| **Unreachable** | "Can't reach `<host>`. On the lab, join the home Wi-Fi and check the VM is up." + Retry; separate wording for TLS ("The lab certificate isn't trusted — install `mkcert-rootCA.crt`", [03](03-environments-and-config.md) §3.6) | [05](05-app-architecture.md) §5.5 |
| **Member list** | Search field, filter chips, sort menu, cards, infinite scroll, FAB **Add** | [06](06-members-feature.md) §6.1 |
| **Member detail** | Header + overflow, tabs Details · Subscription · Payments · Allocations · Activity | §6.2 |
| **Edit profile / Change plan / Change status** | Form page / bottom sheets | §6.3 |
| **Add member** | Sectioned scroll form, sticky Save | §6.4 |
| **Remove sheet / Danger zone** | R1 now; R2 capability-gated | §6.6 |
| **Subscription tab** | Card, dunning block, action buttons | [07](07-subscriptions-direct-payments.md) §7.3 |
| **Cancel / Change plan sheets** | Reason, policy line, typed confirm | §7.4–§7.5 |
| **Payments tab / Charge sheet / Refund** | List, detail, amount + reason | §7.6 |
| **Billing overview / Ledger** | Counts, attention list; filterable charge list | §7.7 |
| **Usage / Overrides / Add override** | Grouped bars; list; sheet | [08](08-allocations-and-overrides.md) |
| **Checkpoint errors / afs-connect** | List + resolve; global flag + toggles | [09](09-other-brain-sections.md) |
| **Settings** | Read-only facts + cache clear | §10.3 |

## 10.5 The Billing destination

The reason this app exists beyond "members on a phone": a glance answers "is anyone in trouble?"

```
 Billing
 ┌───────────────────────────────────────────────┐
 │ Enforcement: OFF  (grace 14 d, max 50/run)     │
 ├───────────────────────────────────────────────┤
 │  current 0     past due 0     downgraded 0     │
 ├───────────────────────────────────────────────┤
 │ Needs attention                                │
 │  (nothing — all members are current)           │
 └───────────────────────────────────────────────┘
 [ View all charges → ]
```

Rows under "Needs attention" show username, plan, state, "downgrade in 6 d" and open that member's Subscription tab. Pull-to-refresh.
When enforcement is off the screen says so in the first line, so an empty list is not read as "all clear" when the ladder simply isn't running.

## 10.6 App lock (production)

`APP_LOCK=true` ⇒ on cold start and after 5 minutes in the background, require device biometric/PIN via `local_auth` before showing
any screen; failure → a locked screen with a single Retry. It uses the phone's own screen-lock credential, stores nothing, and is
not a login to the Brain (the token is unaffected). If the device has no secure lock screen, the lock is skipped and Settings
shows "App lock unavailable — no device lock set". Off in lab modes ([OQ6](00-overview.md)).

## 10.7 Loading, offline and refresh behaviour

- The first Members page is cached ([05](05-app-architecture.md) §5.9) and painted immediately with a "Cached · <age>" caption.
- No offline writes. With no connectivity, write buttons stay enabled but fail fast into the **Unreachable** state with the draft kept.
- Pull-to-refresh on every list/detail. The app does not poll, except the closure progress in [06](06-members-feature.md) §6.6.2.
- Shimmer/skeletons for lists; a determinate spinner only where the server reports progress.

## 10.8 Accessibility and polish

TalkBack labels on chips, bars ("2 of 3 used, 67 percent"), and icon buttons; status never conveyed by colour alone (chip text +
icon); dynamic type up to 200%; haptic tick on a completed destructive action; copy confirmations as snackbars; app icon: a distinct
glyph and colour from `afsmobile_flutter`'s so the two are distinguishable in the launcher (added in
[11](11-android-build-and-deploy.md)).

## 10.9 Acceptance

- Every screen in §10.4 reachable from the navigation map; none reachable that §9 excludes.
- Switching `.env` to production and rebuilding turns the badge red and enables typed confirmations with no code edit.
- With `capabilities.billing_overview = false` (simulated), the Billing destination is absent and no code path throws.
- TalkBack reads each list row and each money value with its units.
