# The marketplace

One sentence: it's a **central catalog + review + signing service**, hosted on the Brain, that every independently-deployed tenant website talks to over a signed network channel to find, download, and get updates for plugins — it never runs a plugin itself, and it never touches a tenant's own data.

## The split that matters

```
        THE MARKETPLACE (central, on the Brain)          A TENANT'S OWN DEPLOYMENT
        ─────────────────────────────────────           ──────────────────────────
        developer accounts                                its own copy of this app
        the catalog (which plugins exist)                 its own database
        review pipeline                                   its own Plugin Runtime
        signing                                            "Plugins" nav item
                    │                                            │
                    └──────── signed pull / push ────────────────┘
                         (browse, install, revoke)
```

Nothing runs across that line except the network call itself. A plugin's actual PHP and frontend code execute *inside the tenant's own deployment*, on the tenant's own database — the marketplace's only job is being the one place every tenant can find and fetch reviewed, signed releases from.

## Developer side

1. **Register** a developer account, submit a plugin as a zip ([04](C:\projects\vagrant\afs-eco\packages\afsserver\plans\plugins\04-manifest-and-package-format.md)'s manifest + backend + frontend).
2. **Automated review** — static analysis rejects forbidden code patterns, undeclared capabilities, etc. ([10 §2–§3](C:\projects\vagrant\afs-eco\packages\afsserver\plans\plugins\10-review-and-publishing-pipeline.md)).
3. **Human review** — a reviewer checks the requested capabilities actually match what the plugin claims to do, and issues an approval assertion for that exact artifact.
4. **Signing** — the marketplace re-packages the reviewed code (never the developer's raw upload) and signs it. That signature is the only thing that ever makes a release installable ([05 §11](C:\projects\vagrant\afs-eco\packages\afsserver\plans\plugins\05-security-and-sandboxing.md)).
5. **Published** — now visible in the catalog.

## Tenant side

1. Tenant admin opens **Plugins → Browse** in their own admin dashboard — this does a signed pull against the marketplace's catalog API. Nothing local yet.
2. Admin picks a plugin, sees the capabilities it's requesting, clicks Install.
3. The tenant's own deployment **downloads the signed release** and **verifies the signature itself**, locally, against the marketplace's public key baked into the app — it doesn't ask the marketplace "is this valid," it checks.
4. It writes a row into **its own local database** (`laravel_plugin_installs` — no tenant column, since that database only ever has one tenant) and caches the manifest, the artifact, and the hashes locally.
5. From here on, **the plugin runs entirely locally** — the tenant's own Plugin Runtime, its own capability SDK, its own `laravel_plugin_data` table. The marketplace is never involved in running it.

## Updates and revocation

Since there's no shared table the marketplace could just edit, it can only *inform* a tenant, never reach into their deployment:

- **Pull is the source of truth.** Each deployment periodically checks the marketplace for a new version or a revoked release, on its own schedule and on demand.
- **Push is just an accelerator.** The marketplace can also proactively notify a deployment ("this release was just revoked") over the same signed channel, for speed — but if that notification is missed, the deployment's next ordinary pull catches it anyway. Nothing depends on the push arriving.

This whole two-way pattern isn't new — it's reused wholesale from [www_brain 10](C:\projects\vagrant\afs-eco\packages\afsserver\plans\www_brain\10-brain-owned-config-and-push.md), which already solved "one central service, many independently deployed apps, signed both ways" for Brain-owned settings. The plugin marketplace didn't need to invent a second trust mechanism.

## Why it's shaped this way

- **A tenant's plugins keep working even if the marketplace is completely unreachable** — everything needed to run an already-installed plugin was downloaded and cached at install time.
- **The marketplace never holds tenant data**, so compromising it doesn't expose any tenant's business data — only the catalog and signing infrastructure.
- **No new architecture was invented** for the one genuinely cross-deployment piece of this whole plan.

Full detail in [02 §8](C:\projects\vagrant\afs-eco\packages\afsserver\plans\plugins\02-architecture-overview.md) and the schema split in [03 §0–§1](C:\projects\vagrant\afs-eco\packages\afsserver\plans\plugins\03-database-schema.md).



