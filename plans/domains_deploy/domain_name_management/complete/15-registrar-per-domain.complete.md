# 15 — A domain is handled by the registrar it was bought through: completion report

**Plan file:** [`../15-registrar-per-domain.md`](../15-registrar-per-domain.md)
**Date:** 2026-10-24
**Status:** ✅ P0 built and tested with test doubles. Not run against a second real registrar (none exists). Nothing is committed or uploaded.

## 1. What was built (`w/www`)

| Plan section | Built as |
|---|---|
| §15.2 the lookup | New `app/Services/Brain/Domains/Registrar/RegistrarDrivers.php`: `for(?string $name)` returns the bound driver when nothing is recorded or the name matches it, a `NameComDriver` for `namecom`, and otherwise throws `DomainException` `DOM_E_REGISTRAR_UNKNOWN` (HTTP 500, naming the registrar). If the registrar in use cannot be resolved (`controller.domain_registrar` not set), a recorded `namecom` is still served. |
| §15.2 purchases | `DomainPurchaseService` takes `RegistrarDrivers`; a private `driverFor($purchaseRow)` is used for `getDomain` (twice), `register` and `setNameservers`. `search`, `quote` and the new purchase's recorded name still use the injected current driver. |
| §15.2 release | `DomainReleaseService` takes `RegistrarDrivers` instead of a driver; `autoRenewOff($claim)` finds the driver by `brain_dns_zones.registrar`, and a `DomainException` (no driver) returns false instead of failing the release. Used by `request()` and by `staffRelease()`. |
| Documentation | `complete/00_extras.md` E22. |

The container binding of `RegistrarDriver` (chosen by `controller.domain_registrar`) is unchanged.

## 2. Verified

`tests/Feature/Brain/Domains/Purchase/DomainPurchaseTest.php` gained three tests, all passing, on a local MariaDB:

* a completed purchase records `fake` on the purchase row and on the claim, with a registrar reference;
* `RegistrarDrivers::for` returns the bound driver for nothing recorded and for its own name, a `NameComDriver` for `namecom` while a different registrar is in use, and throws `DOM_E_REGISTRAR_UNKNOWN` naming `godaddy`;
* releasing a purchased name recorded as bought through `godaddy` still schedules the release, does not ask the other registrar (its auto-renew stays on) and the `release_scheduled` event says `registrar_auto_renew_off: false`.

`tests/Feature/Brain/Domains` as a whole: **282 tests pass**, 21 skipped (they need real VMs).

## 3. Not done, and why

1. **No second real driver exists**, so switching `controller.domain_registrar` to another provider has not been exercised; only the lookup path is proven, with test doubles.
2. **Retries of a purchase made through a registrar that is no longer in use** go through `RegistrarDrivers`, but only the Name.com case can actually reach a different real driver than the bound one.
3. **Moving a domain between registrars** (transfer) is not part of this step.
4. Not uploaded to the dev server.
