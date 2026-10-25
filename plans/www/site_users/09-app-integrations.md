# 09 — App Integrations

How ecommerce, events, appointments, forms and discussions optionally adopt Site Users —
through configuration, not nine bespoke integrations.

> ### Extended by [`../connections/`](../connections/README.md) (2026-09-12)
>
> This document's contract survives; two of its storage and UI decisions do not.
>
> - **§9.2's per-instance `site_users_mode` column becomes derived.** The tri-state is now
>   read from a connection row in `laravel_site_user_connections` — not connected → `off`,
>   connected with guest checkout → `guest`, connected without → `required`
>   ([connections/03](../connections/03-database-schema.md) §3.5). `modeFor()` keeps its
>   signature and its callers; only what it reads changes.
> - **§9.2's "configured in the module's own admin section, not a central Site Users
>   screen" is reversed**, by request: the Connections tab on the Site Users module screen
>   is the one write surface, and each module's form carries a shortcut to it
>   ([connections/05](../connections/05-admin-connections.md) §5.1, §5.6).
> - **Adoption gains what §9.4 only gestured at** — a Dashboard group per connected module,
>   contributed by that module ([connections/08](../connections/08-dashboard-sections.md)),
>   and the self-service actions inside it
>   ([connections/09](../connections/09-module-self-service.md)).
>
> §9.3's "one nullable column, never from the request body", §9.5's adoption order and
> §9.7's acceptance list are unchanged and still govern.

## 9.1 The point of the module

Site Users only earns its place if the modules a tenant actually deploys can use it. Today
none do ([01](01-current-state-audit.md) §1.6): no module references `systemsusers_id`, no
table has a user foreign key, and no page knows whether anyone is signed in — largely because
the token authenticates nothing (**M2**).

## 9.2 One contract, one toggle (**N9**)

Nine modules times a bespoke integration each is how a system ends up half-adopted, with five
notions of "signed in" and three password policies. So adoption is **declared**, not coded.

A module declares support once, in a registry entry:

```php
// config/site_users_consumers.php
return [
    'ecommerce'    => ['label' => 'Ecommerce',    'user_column' => 'site_user_id',
                       'supports' => ['guest', 'required']],
    'events'       => ['label' => 'Events',       'user_column' => 'site_user_id',
                       'supports' => ['guest', 'required']],
    'appointments' => ['label' => 'Appointments', 'user_column' => 'site_user_id',
                       'supports' => ['guest', 'required']],
    'forms'        => ['label' => 'Forms',        'user_column' => 'site_user_id',
                       'supports' => ['guest']],
    'discussions'  => ['label' => 'Discussions',  'user_column' => 'site_user_id',
                       'supports' => ['required']],
];
```

And a tenant switches it on per module, per mode:

| Mode | Meaning |
|---|---|
| `off` | The module ignores Site Users entirely. **Default** |
| `guest` | Signed-in users are recognised and their activity attributed; anonymous use still works |
| `required` | The module refuses anonymous use |

**Authentication is optional and off until a tenant turns it on.** A deployment that adopts
none of this keeps working exactly as it does today — guest checkout, anonymous bookings,
public forms. `off` is not a degraded state; it is what most instances will run forever.

### Where a tenant turns it on

**In the module's own admin section, on the thing being configured** — not in a central Site
Users screen. A tenant setting up their shop is in `Ecommerce → Store`; sending them to a
separate module to enable accounts is how a feature stays undiscovered, and it inverts the
mental model. They are not configuring Site Users; they are deciding how their shop works.

The panel is the same everywhere: the three modes as radio buttons with plain-language
labels, and a module select that appears only once a mode other than `off` is chosen.
[`../ecommerce/01-site-users-guard.md`](../ecommerce/01-site-users-guard.md) §1.5.3 is the
worked example, and the shape other modules copy.

### The mode belongs to the instance, not the module

Ecommerce has stores; events have events; appointments have calendars. A tenant may
reasonably want a public retail store and a members-only wholesale one on the same site, so
the mode is stored **per instance**:

| Level | Holds | Set by |
|---|---|---|
| **Registry** (code, below) | What the module *supports* | A developer, once |
| **Instance row** (data) | What *this* store/event/calendar does | The tenant, per instance |

A per-module toggle cannot express the wholesale case, and modules that genuinely have one
instance lose nothing by storing it on that instance.

This is deliberately the same shape as afs-connect's source registry
([../../afs-connect/12-source-registry.md](../../afs-connect/12-source-registry.md)) — one
array from which the settings UI, the validator and the consuming code all derive, rather
than the eleven-location registration that document exists to fix. **If adding a consumer
takes more than one entry plus a column, that is the signal the seam is wrong.**

`supports` is per-module honesty: `discussions` has no meaningful anonymous mode, `forms` has
no meaningful required one (a public contact form that demands an account is not a contact
form). The settings UI offers only what a module declares.

## 9.3 What a consuming module does

Exactly one thing: ask who is here.

```php
$user = app(SiteUsersGateway::class)->currentUser($request);   // ?SiteUser
```

`null` means guest. It never throws ([03](03-architecture.md) §3.5), which is what makes
`guest` mode cost a consuming module nothing — no try/catch, no branch on whether the module
is even installed.

Then, if it wants attribution, one nullable column:

```php
$table->unsignedBigInteger('site_user_id')->nullable()->index();
```

**Nullable, always.** Rows created before adoption have no user, and `guest` mode keeps
creating them. A non-nullable column would make adoption a data migration.

**A consuming module never creates, authenticates or mutates a user.** That is the gateway's
deliberate omission ([03](03-architecture.md) §3.5) — five registration paths would mean five
password policies.

## 9.4 Per-module notes

| Module | `guest` | `required` | Notes |
|---|---|---|---|
| **Ecommerce** | Orders attributed; saved cards via [08](08-direct-payments-link.md) | Account required to check out | The module with the most to gain, and the first adopter — [`../ecommerce/01-site-users-guard.md`](../ecommerce/01-site-users-guard.md) is the worked plan |
| **Events** | Registrations attributed | Account required to register | "My tickets" needs this |
| **Appointments** | Bookings attributed; prefill from profile | Account required to book | Reduces no-shows; also lets a member cancel their own booking, which today needs a link in an email |
| **Forms** | Submissions attributed when signed in | *(not offered)* | §9.2 |
| **Discussions** | *(not offered)* | Always | Anonymous discussion is a different product |

The **appointments** row has a consequence worth naming: today a booking is identified by an
emailed link. Adding accounts means two ways to reach the same booking, and the module must
accept both — an existing emailed link cannot stop working because accounts arrived.

## 9.5 Adopting without breaking anything

Order matters, and the middle step is the one that gets skipped:

1. **Add the nullable column.** No behaviour change. Ships alone.
2. **Populate on new activity** in `guest` mode. Old rows stay null; new ones attribute.
3. **Only then** offer `required`, and only for modules that declare it.

Turning on `required` for a module with existing anonymous data does **not** retroactively
orphan it — those rows keep their null `site_user_id` and remain visible to the admin.
What `required` changes is the *next* action, not the history.

Backfilling old rows by matching email is possible and is **not** in this plan: it is
[08](08-direct-payments-link.md) §8.5's problem with the same three rules, and it should be
solved once for both rather than per module.

## 9.6 The builder

Builder components for login, registration, account and password reset, all rendering the
[07](07-externals-api.md) §7.3 flows. A tenant drags a Login block onto a page and it works.

**The components are thin.** They mount an SDK flow and pass through styling. No component
implements a credential path of its own — otherwise the builder becomes a second
implementation that drifts from the SDK, and the standalone requirement
([07](07-externals-api.md) §7.1) stops being tested by the primary consumer.

## 9.7 Acceptance

- Adding a consumer is **one registry entry plus one nullable column** (§9.2).
- A module with Site Users `off` behaves exactly as today — no queries, no gateway calls.
- In `guest` mode, an anonymous checkout still completes and an authenticated one is
  attributed.
- Turning on `required` leaves existing anonymous rows intact and visible (§9.5).
- `currentUser()` on a request with no token returns `null` — no exception, no redirect.
- An existing emailed appointment link still works after appointments adopts accounts.
- The settings UI offers a module only the modes it declares in `supports`.
- No consuming module calls anything that creates or authenticates a user.
