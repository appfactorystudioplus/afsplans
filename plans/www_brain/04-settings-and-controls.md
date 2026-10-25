# 04 — Settings and controls

The general mechanism. Email is one kind of control ([05](05-mail-and-notifications.md)); this is
how *any other* behaviour becomes Brain-controllable without Brain code changes (B3).

## 4.1 The model

Each app's manifest lists settings ([02 §2.2](02-app-registry-and-manifest.md)). The Brain stores a
value per `(app, key)`; the **effective value** is:

```
effective(app, key) = brain_value   if a value exists AND it validates against the current schema
                    = default       otherwise (never an error)
```

An invalid, orphaned or missing value **degrades to the default** — never to failure (B2, B8).

## 4.2 What a setting can be

| Type | Example |
|------|---------|
| boolean | `maintenance.enabled` |
| integer / number | `share.max_collaborators` |
| string / enum | `editor.default_theme` ∈ `light\|dark` |
| list | `import.allowed_extensions` |
| object | `export.pdf_options` (bounded depth) |

Not settings: secrets ([03 §3.3](03-data-model.md)), per-member entitlements (allocations), and
anything the app must decide per request from data it owns.

## 4.3 Which controls belong here, and which do not

The risk of a control plane is that it becomes a second, drifting home for numbers that already
have one. Rule of thumb:

| Belongs here | Belongs elsewhere |
|--------------|-------------------|
| Operational switches (maintenance, kill switches, feature flags) | **Limits and tier ceilings** → alloc_v2 / [`members_brain`](../members_brain/README.md) |
| App-wide defaults and copy | **Billing policy** → `laravel_direct_payment_advanced` ([`billing`](../billing/README.md)) |
| Behaviour a support person should change without a deploy | **Secrets and credentials** → each app's `.env` |
| Mail responses ([05](05-mail-and-notifications.md)) | **Identity/session policy** → Checkpoint (`auth`) |

A manifest that declares a setting duplicating one of the right-hand column is a review failure.

## 4.4 Read path

`GET /internal/brain/apps/{app}/settings` returns every effective setting for the app in one call:

```json
{
  "app": "NOTES_EDITOR",
  "manifest_version": "2026.09.24-1",
  "etag": "W/\"c41f…\"",
  "settings": {
    "maintenance.enabled": { "value": false, "source": "default", "revision": 0 },
    "share.max_collaborators": { "value": 25, "source": "brain", "revision": 3 }
  }
}
```

- `source` ∈ `brain` \| `default` \| `default_invalid` (a stored value that no longer validates) so
  an operator can tell *why* an app is using a value.
- The response carries an **`ETag`**; the client sends `If-None-Match` and gets `304` with no body
  when nothing changed, so the steady-state cost is one tiny request per TTL.
- One document per app, not one call per key.

## 4.5 Caching and freshness

The client ([06](06-app-integration.md)) caches the document:

| Layer | Lifetime | Used when |
|-------|----------|-----------|
| Fresh cache | TTL — default **60 s** for `hot` settings, **10 min** otherwise | Normal operation |
| Last-known-good | **Unbounded** (until replaced) | Brain unreachable, `5xx`, bad signature, timeout |
| Compiled defaults | Deploy-time | Nothing cached yet **and** the Brain is unreachable |

Consequences to state plainly:

- A change made in the Brain takes effect within **one TTL**, not instantly (OQ-3 covers push).
- A Brain outage changes nothing for a running app.
- A *cold* app during a Brain outage runs on manifest defaults — which is why defaults must be safe
  (`maintenance.enabled` default `false`; a kill switch default is the *safe* state).
- Last-known-good is **never expired by age alone**; a stale value is better than a wrong default.

## 4.6 Write path (staff only)

`PUT /api/v1/brain/apps/{app}/settings/{key}` (staff group, [07](07-brain-admin-ui.md)):

1. Authorize: authenticated **and** level 99 (server-side; the sidebar hide is not authorization).
2. Load the current manifest; reject an undeclared key (`404`).
3. Validate `value` against the key's schema (`422` with the reason).
4. Compare-and-set on `revision` (the client sends the revision it edited; a mismatch is `409`
   so two staff cannot silently overwrite each other — the failure `members_brain` documents for
   allocations).
5. In one transaction: update `brain_app_settings`, append `brain_app_setting_history`.
6. Return the new effective value and revision.

**Rollback** = `PUT` the `old_value` from a history row with `source = 'rollback'`. History is never
edited.

**Reset to default** = delete the row (recorded in history as `new_value = null`).

## 4.7 Extension: per-member scope (not in v1)

`scope` and `scope_ref` exist in the table so a setting can later be overridden per member
(`scope = 'member'`, `scope_ref = member_reference_num`). Not built first because:

- it multiplies the read payload (the client would need the member context),
- it overlaps allocations and entitlements,
- and no requirement names it yet.

If added, resolution becomes `member override → app value → default`, and the read call takes a
`?member=` parameter. Nothing in v1 prevents it.

## 4.8 First real controls (proposal for the pilot)

Kept deliberately tiny to prove the mechanism (OQ-6):

| Key | Type | Default | Meaning |
|-----|------|---------|---------|
| `maintenance.enabled` | boolean, `hot` | `false` | Show a maintenance page to everyone but staff |
| `maintenance.message` | string ≤ 500 | `""` | Text on that page |

Everything else waits for a named need.

## 4.9 Acceptance

- Setting a value in the Brain changes the app's effective value within one TTL.
- An out-of-range value is refused with `422`; nothing is stored.
- Two staff editing the same key: the second save gets `409`.
- Stopping the Brain leaves a running app unchanged; a cold app boots on defaults.
- A schema change that invalidates a stored value makes the app use the default and shows the key
  as *invalid* in the UI.
- Every write, rollback and reset appears in history with the actor.
