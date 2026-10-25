# 15 — A domain is handled by the registrar it was bought through

> **STATUS 2026-10-24: BUILT (P0).** What exists and what was checked: [complete/15-registrar-per-domain.complete.md](complete/15-registrar-per-domain.complete.md). Written because the platform may use a different registrar one day and registrars are third-party systems. It changes the purchase flow of [05](05-add-and-purchase-flows.md) §5.2 and §5.3; the registrar in use is chosen as described in `complete/00_extras.md` E14.

## 15.1 The problem

The registrar a name was bought through was already recorded at purchase: `brain_domain_purchases.registrar`, `registrar_order_ref` and `registrar_price_cents`, and on the claim `brain_dns_zones.registrar`, `registrar_ref`, `expires_at` and `auto_renew`. But nothing read it afterwards. One driver was bound for the whole application, picked from `controller.domain_registrar`, and every later call used it: a retry of a purchase, and switching auto-renew off when a member gives a name up. If `controller.domain_registrar` were changed, those calls for old domains would have gone to the new registrar, which does not hold them.

## 15.2 P0 — what is built

* `RegistrarDrivers::for(?string $name)` returns the driver for a recorded registrar name: the one in use if the name matches it; `namecom` for Name.com; otherwise it stops with `DOM_E_REGISTRAR_UNKNOWN` naming the registrar. Nothing recorded means the one in use.
* `DomainPurchaseService` uses it for an existing purchase's `getDomain`, `register` and `setNameservers`, by the registrar recorded on that purchase row. Search, quote and a new purchase keep using the registrar in use now, and record its name on the new row.
* `DomainReleaseService` uses it to switch auto-renew off for a purchased claim, by the registrar recorded on the claim. A name whose registrar the platform has no driver for still gets its release scheduled; the event records `registrar_auto_renew_off: false`, so staff can switch it off by hand.
* A domain a member added themselves ("just add it") has no registrar recorded: it was registered somewhere we do not know, and no registrar is ever asked about it.

## 15.3 How to switch or add a registrar

1. Write the driver class (it implements `RegistrarDriver`, as `NameComDriver` does).
2. Add its name to the `match` in `RegistrarDrivers::for()` and in the `AppServiceProvider` binding.
3. Add its entry to `controller.keys`, with a `development` and a `production` slot.
4. Change `controller.domain_registrar`.
5. **Keep the old registrar's `controller.keys` entry and its driver** for as long as it still holds domains.

## 15.4 Decision

| # | Question | Answer |
|---|---|---|
| D37 | Is the recorded registrar used after a switch? | **Yes** (owner, 2026-10-24; E22). |

## 15.5 Not part of this step

* Moving a domain from one registrar to another (transfer).
* A second real driver. Only Name.com exists, so the lookup path to a second registrar is proven with test doubles, not with a real one.
