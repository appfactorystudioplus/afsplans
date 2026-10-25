# 11-domain-and-website-on-dev — completion record

**Plan file:** [`../11-domain-and-website-on-dev.md`](../11-domain-and-website-on-dev.md)
**Repos:** `w/www`, `w/members`
**Date:** 2026-10-15
**Status:** 🟡 Code complete for steps 2 to 4; no dev run yet (steps 5 and 6). Nothing is pushed and nothing was run on a server.

## What shipped

* Six signed Brain routes (`build/data`, `build/tenant`, `zones/list|save|delete`, `registrar/credentials`) so the members domain flow needs no bearer token and no level 99; the Brain config is returned only to the MEMBERS key and the registrar's keys only for one environment.
* Members calls them through `viaBrain()` and falls back to the original calls only when the Brain does not have the routes.
* `BuildServerSelector`: the Brain picks a server that is up, has room and is nearest to the member (MaxMind and Google, straight line), with nothing cached; none up refuses.
* `VITE_SUBDOMAIN` as the one environment switch (`AppEnvironment` in members); script folders come from `base_path()` (members) and `AppFolder` (the Brain) instead of the non-existent `dev/members15` and `dev/www15`.
* The old_way defects: browser no longer receives secrets (OF16), `CreatedBy` row filled, one generated database password per tenant, a failed build turns the website off.

## Deviations from the plan text

* `zones/save` and `zones/delete` enforce that the zone belongs to the given member; the old routes did not.
* The selector is resolved from the container (`app(BuildServerSelector::class)`) rather than a static `pick()`, so tests can replace the reachability check.
* The browser's refusal message is plain; the per-server reasons go to the log.

## How it was checked

* www: `BrainBuildSurfaceTest` (9), `BuildServerSelectorTest` (12), `AppFolderTest` (3); the Unit, Direct Payments and Brain suites pass on a throwaway local MySQL.
* members: `DomainBrainSurfaceTest` (15), `WebsiteBuildOutcomeTest` (3) and the existing domain and Brain-surface tests, run with www's phpunit.

## Not verified

* Everything that needs a server, a provider or a browser: the routes against a real Brain, MaxMind, Google, ssh reachability, `app_install.sh`, `background_runner.php`, certbot, Name.com, the progress bar.

## Residue

* Steps 5 and 6 (a real dev run and purchase) need your word. V3, V5 and the remaining old_way robustness items are not done. The fallbacks to the old calls remain until every Brain has the routes.
