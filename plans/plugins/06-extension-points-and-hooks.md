# 06 — Extension points and hooks

## Completion document

When this plan step is completed, write `complete/06-extension-points-and-hooks.complete.md`.

---

This is the platform's entire exposed surface area for plugins — deliberately a catalog, not an
open-ended event bus a plugin can subscribe to anything on. If it isn't listed here, a plugin
cannot see it happen or act on it, by construction, the same closed-set discipline
[05 §4](05-security-and-sandboxing.md) applies to the capability SDK.

## 1. Backend extension points (hooks)

Named after the domain event they represent, deliberately coarse-grained rather than exposing
every internal model event:

| Extension point | Kind | Fires when | Data passed | Default on timeout/error (filter only) |
|---|---|---|---|---|
| `order.completed` | notify | An ecommerce order finishes checkout | Order reference, totals, line-item references (never raw PII beyond what `orders.read`-scoped code could already fetch) | — |
| `order.refunded` | notify | An order is refunded | Order reference, refund amount | — |
| `appointment.booked` | notify | A new appointment booking is created | Appointment reference, time, service | — |
| `form.submitted` | notify | A form submission is received | Form reference, submission reference (not the raw field values — a plugin with `forms.read` fetches those explicitly) | — |
| `portal_user.login` | notify | A portal user signs in | Portal user id, timestamp | — |
| `checkout.validate` | filter | Before an order is allowed to complete | Cart contents; plugin returns allow/deny + reason | **Allow.** A stalled or errored plugin never blocks a tenant's own sale ([17](17-example-plugin-order-guard.md) relies on this directly) |
| `admin.nav.build` | filter | The admin shell is assembling the sidebar | Nothing tenant-specific beyond the plugin's own declared nav entry (04 §2's `frontend.nav`) — this is largely handled by the manifest directly rather than a live hook (§3 note) | Omit the plugin's nav entry for this load |
| `settings.page.render` | filter | A plugin's settings screen is about to render | The plugin's own `plugin_settings` rows for this install | Render the panel empty with a "couldn't load settings" notice |
| `schedule.tick` | notify | The platform's scheduler reaches a plugin's declared cadence (04 §2, 05 §6) | Nothing beyond the tick itself; the plugin reads whatever it needs via its granted capabilities | — |

Per [02 §6](02-architecture-overview.md)'s rule that every filter states its own default-on-timeout
so a stalled plugin fails toward the platform's own behavior rather than hanging the request — the
column above is that statement, previously promised but not actually recorded here.

This catalog grows only when the platform team adds an extension point — never as a side effect
of a plugin submission. `plugin_capabilities_catalog` ([03 §1](03-database-schema.md)) and this
catalog evolve together: a hook that returns data implies the reading capability that data
requires exists and is listed there.

## 2. Frontend slots

A plugin's frontend bundle ([08](08-frontend-runtime-and-dynamic-loading.md)) can render into a
fixed set of locations, declared in the manifest and requested as capabilities, not discovered at
runtime:

| Slot | What a plugin renders there |
|---|---|
| `nav.item` | One sidebar entry, its own icon and label, linking to the plugin's own full-page view |
| `dashboard.widget` | A card on the main dashboard home screen |
| `settings.panel` | The plugin's own settings screen, reached from **Plugins → Installed → this plugin → Settings** ([09 §5](09-admin-dashboard-ux.md)) |
| `entity.tab` (future) | A tab on an existing entity's detail screen (e.g. an order detail page) — **not built in the first delivery**; flagged because it is the single most-requested WordPress-style extension point ("add a tab to the post editor") and the catalog is deliberately shaped so adding it later is additive, not a redesign |

Every slot renders through the same iframe/postMessage mechanism
([08 §3](08-frontend-runtime-and-dynamic-loading.md)) regardless of which slot it's in — there is
no separate, less-sandboxed path for a "small" widget versus a "full" page.

## 2.1 Public site slots — rendering into the published website, not the admin dashboard

**Added when the first real plugin needed it** ([16](16-example-plugin-cookie-consent.md), a
cookie-consent toast) — everything in §2 above renders inside the authenticated admin dashboard,
to a signed-in portal user. Nothing so far lets a plugin render anything on a tenant's actual
**published, public website** — the page the page builder (`resources/js/portal/builder`)
generates for an anonymous visitor. That is a genuinely different surface, so it is a genuinely
different slot category, not a variant of §2:

| Slot | What a plugin renders there | Audience |
|---|---|---|
| `site.global` | A small, site-wide element injected into every published page's shell (not tied to any one page's block layout) — a cookie-consent toast, for example | Anonymous, unauthenticated site visitors |

Consequences of the audience being the public, not an admin:

- **No admin session, no CSRF token, nothing sensitive in the surrounding page to protect the same
  way §2's iframe boundary protects the admin dashboard's own session** ([08 §3](08-frontend-runtime-and-dynamic-loading.md)) —
  but the blast radius if this goes wrong is arguably *larger*, not smaller: a compromised
  `site.global` bundle runs in front of every ordinary visitor to a real, live tenant website, not
  just the tenant's own admin staff. **The same iframe/CSP/integrity-hash machinery §2 uses is
  reused here, unchanged, applied to the published page instead of the admin shell** — this is not
  a place to relax sandboxing because "there's no session to steal"; there is a public audience to
  protect from a defaced or phishing-injected page instead.
- **The RPC bridge exposes a much smaller capability set than an admin-dashboard slot could.** A
  public visitor has no legitimate reason for a `site.global` plugin to reach anything requiring
  tenant business data (`orders.read` and similar). [05 §12](05-security-and-sandboxing.md) adds an
  explicit catalog-level flag for exactly this — a capability is only *reachable at all* from a
  public slot if it is marked eligible, closing off the possibility of a developer (or a review
  miss) accidentally exposing a sensitive capability to the open internet.
- **The write path a public slot uses runs unauthenticated**, since a site visitor has no portal
  session to authenticate with. [05 §12](05-security-and-sandboxing.md) covers how the platform
  still knows *which tenant* an anonymous request belongs to without trusting anything the
  anonymous client claims.
- **This plan has not audited how the builder actually renders a published page's shell** — where
  a site-wide element like this would physically be injected (a footer partial, a template hook,
  something else). That audit is explicitly the first phase of
  [16](16-example-plugin-cookie-consent.md), not assumed here.

`site.global` is deliberately the only public site slot in the first delivery. A per-page,
editor-placed public block (closer to how the builder's own first-party `BUILDER_MODULES`
registry works, [01 §5](01-current-state-audit.md)) is a larger extension — it implies a plugin
participating in the builder's own drag-and-drop palette — and is explicitly not attempted here;
`site.global` (an element on every page, not one an editor places) is the narrower slice that
happens to be exactly what a cookie-consent toast needs.

## 3. Why hooks are files, not arbitrary registration calls

A manifest's `hooks` array (04 §2) names a specific file within the package
(`backend/hooks/order_completed.php`) per subscribed extension point, rather than letting plugin
code call an SDK method like `$plugin->on('order.completed', $closure)` from anywhere. This is a
deliberate constraint, not an aesthetic choice:

- **The static analyzer** ([10 §3](10-review-and-publishing-pipeline.md)) can enumerate every hook
  a release subscribes to by listing `backend/hooks/`, cross-check it against the manifest's
  `hooks` array, and reject a mismatch — there is no way to hide a hook subscription from review.
- **The Plugin Runtime dispatcher** ([02 §5](02-architecture-overview.md)) loads exactly the one
  file an event names, rather than loading the plugin's entire codebase and letting it decide
  what to do with a dispatched event — the invocation surface per call is as small as it can be.

## 4. Catalog versioning

Both this hooks/slots catalog and the capability catalog ([05 §4](05-security-and-sandboxing.md))
are versioned together as **the extension-point catalog**, referenced by a manifest's
`platform_compat` field (04 §2):

- Adding a new hook, slot, or capability is a **minor** version bump — existing plugins are
  unaffected and cannot use the new surface until they declare compatibility with it.
- Changing the data shape an existing hook passes, or a capability method's signature or scoping
  guarantee, is a **major** version bump. A major bump does not retroactively break installed
  plugins built against the old major version — the Plugin Runtime dispatcher supports the
  previous major version's hook/capability shapes for a stated deprecation window, during which
  the marketplace flags affected plugins for the developer to update, before the old shape is
  finally retired (at which point any plugin still on it is treated as incompatible and disabled,
  per the `platform_compat` check in [04 §6](04-manifest-and-package-format.md)).
- Retiring a major version is a platform-team decision recorded here as a future step's job, not
  designed further in this plan's first delivery.

## 5. What is deliberately not an extension point

- **Nothing that would require handing a plugin a raw Eloquent model, a raw request object, or a
  raw response object.** Every hook's payload is a narrow, explicit DTO shape documented in this
  catalog, not "whatever the core model happens to look like today" — this is what lets the
  platform change its own internal models freely without breaking every plugin that touched one.
- **No hook fires with another tenant's context.** The Plugin Runtime dispatcher resolves
  installs and grants strictly within the tenant the triggering event belongs to
  ([02 §7](02-architecture-overview.md)); there is no "global" hook invocation across tenants.
- **No hook exposes another plugin's existence or data.** One plugin cannot discover what other
  plugins a tenant has installed through any extension point (00 §3 non-goal: no plugin-to-plugin
  dependency or interaction).
