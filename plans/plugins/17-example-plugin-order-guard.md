# 17 — Example plugin: minimum order guard (hook-based)

## Completion document

When this plan step is completed, write
`complete/17-example-plugin-order-guard.complete.md`.

---

[16](16-example-plugin-cookie-consent.md) shipped zero backend PHP — every capability it used is
generic platform code. This is the other case: **a plugin that writes real PHP**, because it needs
to react to a platform event. It's also [14 §2](14-rollout-and-testing.md)'s second dogfood
plugin, made concrete — "a plugin exercising a `notify` hook and a `filter` hook."

## 0. What it does

A tenant sets a minimum order amount (e.g. $25). Below that, checkout is blocked with a message.
Every completed order is counted; every blocked attempt is counted. Two admin settings, two stats
numbers, two hook files. That's the whole plugin.

## 1. The code

**`plugin.json`'s `hooks` array** ([04 §2](04-manifest-and-package-format.md)):

```json
"hooks": [
  { "extension_point": "checkout.validate", "handler_file": "backend/hooks/checkout_validate.php", "kind": "filter" },
  { "extension_point": "order.completed",  "handler_file": "backend/hooks/order_completed.php",  "kind": "notify" }
]
```

**`backend/hooks/checkout_validate.php`** — the developer writes this. It's a filter: it runs
synchronously, before checkout completes, and can block it.

```php
<?php
/**
 * PLUGIN_SYSTEM_ADDITION — filter hook: checkout.validate (06 §1).
 * Fails open on timeout/error (06 §1's table) — a stalled check never blocks
 * a real sale, it just misses catching this one.
 */
return function (array $cart, PluginCapabilities $plugin): array {
    $settings  = $plugin->storage()->get('settings', 'minimum') ?? [];
    $minimum   = $settings['amount'] ?? 0;

    if ($cart['total'] < $minimum) {
        $plugin->storage()->increment('stats', 'totals', 'blocked_count', 1);

        return [
            'allow'  => false,
            'reason' => "Minimum order amount is \${$minimum}.",
        ];
    }

    return ['allow' => true];
};
```

**`backend/hooks/order_completed.php`** — a notify hook: fire-and-forget, queued, can't affect the
order that already went through.

```php
<?php
/** PLUGIN_SYSTEM_ADDITION — notify hook: order.completed (06 §1). */
return function (array $order, PluginCapabilities $plugin): void {
    $plugin->storage()->increment('stats', 'totals', 'completed_count', 1);
};
```

That's the entire backend. Same rule as every capability call in this plan: `$plugin` is the only
thing either file touches — no `DB::`, no Eloquent, no framework access (D7,
[05 §4](05-security-and-sandboxing.md)). `$cart` and `$order` are the narrow DTOs
[06 §1](06-extension-points-and-hooks.md)'s catalog defines, not a raw request or model.

## 2. How the platform actually calls these — not plugin code

```php
// PLUGIN_SYSTEM_ADDITION — core platform: the filter dispatcher (02 §6, 06 §3)
// No tenant parameter: this runs inside one tenant's own deployment, over
// that deployment's own locally installed plugins only (00 §0).
public function dispatchFilter(string $extensionPoint, array $payload): array
{
    foreach (PluginInstall::active() as $install) {
        $hook = $install->currentRelease()->manifest()->hookFor($extensionPoint, kind: 'filter');
        if (! $hook) {
            continue;
        }

        $verdict = $this->runtime->invoke($install, $hook->handlerFile, $payload, timeoutMs: 250)
            ?? ['allow' => true]; // timeout/error — 06 §1's stated default for this extension point

        if ($verdict['allow'] === false) {
            return $verdict; // first block wins; later plugins are not evaluated
        }
    }

    return ['allow' => true];
}

// PLUGIN_SYSTEM_ADDITION — core platform: the notify dispatcher
public function dispatchNotify(string $extensionPoint, array $payload): void
{
    foreach (PluginInstall::active() as $install) {
        $hook = $install->currentRelease()->manifest()->hookFor($extensionPoint, kind: 'notify');
        if ($hook) {
            Queue::push(new InvokePluginHook($install->id, $hook->handlerFile, $payload));
        }
    }
}
```

`dispatchFilter` loads exactly the one file the manifest named for this extension point, per
[06 §3](06-extension-points-and-hooks.md), invokes it inside the Plugin Runtime
([05 §3](05-security-and-sandboxing.md)) with the invocation token constructing `$plugin`
([05 §10](05-security-and-sandboxing.md)), and stops at the first `allow: false` — a second
plugin's filter is never consulted once one has already blocked, so two plugins can't produce a
contradictory verdict for the same checkout. `PluginInstall::active()` only ever iterates this one
deployment's own installs — there is no tenant to filter by, because there is only ever one.

## 3. Manifest, in full

```json
{
  "slug": "minimum-order-guard",
  "name": "Minimum Order Guard",
  "version": "1.0.0",
  "summary": "Block checkout below a minimum order amount you set.",
  "developer": "…",
  "platform_compat": "^1.0",
  "capabilities": ["plugin_data.read", "plugin_data.write", "plugin_data.increment"],
  "hooks": [
    { "extension_point": "checkout.validate", "handler_file": "backend/hooks/checkout_validate.php", "kind": "filter" },
    { "extension_point": "order.completed",  "handler_file": "backend/hooks/order_completed.php",  "kind": "notify" }
  ],
  "data_schema": [
    { "collection": "settings", "description": "The configured minimum amount" },
    { "collection": "stats", "description": "Blocked/completed counters",
      "increment": { "fields": ["blocked_count", "completed_count"], "max_amount": 1 } }
  ],
  "frontend": { "entry": "frontend/dist/main.js", "nav": { "label": "Order Guard", "icon": "shield" }, "settings_panel": true },
  "permissions_requested_summary": "Reads and writes its own settings, reacts to checkout and completed orders using only the totals it's given, and keeps two counters. Never reads order or customer details beyond what checkout.validate and order.completed already pass it."
}
```

No `http.egress`, no `orders.read` — the hook payloads ([06 §1](06-extension-points-and-hooks.md))
already carry everything this plugin needs (`cart.total`, nothing else about the order).

## 4. Admin UX and building it

Standard application of [09](09-admin-dashboard-ux.md) and [15](15-developer-scaffolding-and-local-development.md),
nothing new: a settings panel for the minimum amount, a stats view reading `stats`/`totals` via
`plugin_data.read`. Scaffolded with `plugin-cli new minimum-order-guard`; the two hook files are
generated as stubs in `backend/hooks/` and filled in per §1; tested locally against
[15 §3](15-developer-scaffolding-and-local-development.md)'s mock SDK by calling the exported
closures directly with fixture `$cart`/`$order` arrays — no real checkout needed to verify the
logic.

## Phases

| Phase | Deliverable | Done when |
|---|---|---|
| **P0** | Both hook files, manifest, settings/stats screens, against the local mock SDK | A fixture cart below the configured minimum is blocked locally with the right reason string |
| **P1** | Submit and install as an internal dogfood plugin ([10](10-review-and-publishing-pipeline.md)) | Clears review; installed on a real (or lab) tenant |
| **P2** | A real checkout below the minimum is blocked end to end; a real completed order increments the counter | Both hook kinds proven under a real invocation, fulfilling [14 §2](14-rollout-and-testing.md) item 2 |
