# Memberships Module Documentation

## Overview

The Memberships Module manages subscriber accounts for the AFS platform. It spans a full-stack registration flow: plan selection on the pricing page, payment processing, account creation, and admin management via the Brain panel.

Membership levels map to Direct Payment subscription tiers:

| Index | Name         | Monthly Price |
|-------|--------------|---------------|
| 0     | Free         | $0.00         |
| 1     | Pro          | $9.99         |
| 2     | Business     | $19.99        |
| 3     | Organization | $49.99        |
| 4     | Enterprise   | $99.99        |

---

## Architecture

```
[Pricing page – main.ts]
    │  setSelectedTierPlan(tierRef)
    ▼
[Checkout page – create.ts]
    │  availability checks (blur + submit)
    │  discount code validation
    │  tax estimation
    │  confirmPayment() via Portal SDK
    │  POST /portal/api/v1/memberships/register
    ▼
[MembershipController::register()]
    │  validates + deduplicates
    │  registerCheckpointUser() → Delight\Auth (ext_member_* tables)
    │  Membership::create()
    ▼
[laravel_brain_memberships table]

[Brain Admin Panel]
    brain_content.html → #brain_membership_btn
    imp_brain.ts → navigate to brain:memberships:init
    brainView.ts → brain:memberships:init subview
    brain_memberships_content.html (form + table)
    brain_membership.ts (controller)
    MembershipController (API)
```

---

## Database Schema

**Table:** `laravel_brain_memberships`

| Column              | Type                       | Notes                                          |
|---------------------|----------------------------|------------------------------------------------|
| `id`                | bigint unsigned, PK        | Auto-increment                                 |
| `reference_num`     | varchar(64), unique        | Random 12-char alphanumeric token              |
| `username`          | varchar(128), unique       | Used for Delight Auth login                    |
| `email`             | varchar(255), unique       | User email                                     |
| `firstname`         | varchar(128), nullable     |                                                |
| `lastname`          | varchar(128), nullable     |                                                |
| `membership`        | tinyint                    | 0=Free, 1=Pro, 2=Business, 3=Org, 4=Enterprise|
| `level`             | tinyint, default 0         | Reserved for future permission tiers           |
| `user_id`           | bigint unsigned, nullable  | ID from Delight Auth (`ext_member_users`)      |
| `street`            | varchar(255), nullable     | Billing address                                |
| `city`              | varchar(128), nullable     |                                                |
| `state`             | varchar(128), nullable     |                                                |
| `zip_code`          | varchar(32), nullable      |                                                |
| `country_code`      | varchar(8), nullable       | ISO 3166-1 alpha-2 (e.g. `US`)                |
| `country_name`      | varchar(128), nullable     |                                                |
| `zone`              | tinyint, default 0         | Reserved for multi-region routing              |
| `processor`         | varchar(64), nullable      | Provider name: `stripe`, `square`, `paypal`    |
| `processor_group`   | varchar(128), nullable     | 7-char public processor ID                     |
| `json_data`         | text, nullable             | JSON: `discount_code`, `tax_rate`, `tax_cents` |
| `customer_id`       | varchar(128), nullable     | PSP customer ID (e.g. `cus_xxx` for Stripe)    |
| `account_status`    | tinyint, default 1         | 1=Active, 2=Suspended, 3=Marked for deletion   |
| `account_close_date`| date, nullable             | Set when account is scheduled for deletion      |
| `created_at`        | timestamp                  |                                                |
| `updated_at`        | timestamp                  |                                                |

Migration: `database/migrations/2026_04_27_000001_create_laravel_brain_memberships_table.php`

Model: `app/Models/Membership.php`

---

## API Endpoints

All endpoints are under `/portal/` (prefix defined in `routes/portal.php`).

### List memberships
```
GET /portal/api/v1/memberships
```
Returns all membership records ordered by creation date (newest first).

**Response:**
```json
{
  "success": true,
  "memberships": [ { ...membership fields... } ]
}
```

---

### Admin-create a membership
```
POST /portal/api/v1/memberships
Content-Type: application/json
```

**Body:**
```json
{
  "username": "jdoe",
  "email": "jdoe@example.com",
  "password": "SecurePass123",
  "firstname": "John",
  "lastname": "Doe",
  "membership": 1,
  "street": "123 Main St",
  "city": "Austin",
  "state": "TX",
  "zip_code": "78701",
  "country_code": "US",
  "country_name": "United States",
  "processor": "stripe",
  "processor_id": "ABC1234",
  "discount_code": "SAVE20",
  "account_status": 1
}
```

Creates a membership record and registers the user in the Delight Auth checkpoint system (`ext_member_users` table). If checkpoint registration fails, the membership record is still created (with `user_id = null`) and the failure is logged.

---

### Update a membership
```
PUT /portal/api/v1/memberships/{reference_num}
Content-Type: application/json
```

**Allowed fields:** `account_status`, `account_close_date`, `membership`, `level`, `customer_id`, `firstname`, `lastname`

---

### Validate username availability
```
GET /portal/api/v1/memberships/validate-username?username=jdoe
```

**Response:**
```json
{ "success": true, "available": true, "message": "Username is available." }
```

---

### Validate email availability
```
GET /portal/api/v1/memberships/validate-email?email=user@example.com
```

**Response:**
```json
{ "success": true, "available": false, "message": "Email is already registered." }
```

---

### Resolve discount code
```
GET /portal/api/v1/memberships/discount?processor_id=ABC1234&discount_code=SAVE20
```

Looks up a `ProcessorDiscount` record matching the given processor and code. The discount must already exist in `laravel_processor_discounts` (created via the Discounts admin panel or Processors API).

**Response:**
```json
{
  "success": true,
  "discount": {
    "discount_code": "SAVE20",
    "type": "percent_off",
    "percent_off": 20.0,
    "amount_cents": null,
    "currency": "usd"
  }
}
```

---

### Estimate tax
```
POST /portal/api/v1/memberships/tax
Content-Type: application/json
```

**Body:**
```json
{
  "processor_id": "ABC1234",
  "country_code": "US",
  "state": "CA",
  "membership": 1,
  "subtotal_cents": 999
}
```

**Response:**
```json
{
  "success": true,
  "tax_rate": 7.25,
  "tax_cents": 72,
  "description": "Stripe Tax estimate: 7.25% for US/CA"
}
```

Tax is estimated using:
- **Stripe**: Stripe Tax Calculations API (`POST /v1/tax/calculations`) — requires Stripe Tax to be enabled on the account.
- **Square**: Falls back to flat-rate lookup table (Square has no standalone tax estimation API).
- **PayPal / fallback**: Flat-rate US state lookup table; returns 0% for non-US addresses.

---

### Public registration (post-payment)
```
POST /portal/api/v1/memberships/register
Content-Type: application/json
```

Called by `create.ts` after a successful payment (or immediately for free-plan signups).

**Body:**
```json
{
  "username": "jdoe",
  "email": "jdoe@example.com",
  "password": "SecurePass123",
  "firstname": "John",
  "lastname": "Doe",
  "membership": 1,
  "street": "123 Main St",
  "city": "Austin",
  "state": "TX",
  "zip_code": "78701",
  "country_code": "US",
  "country_name": "United States",
  "processor_id": "ABC1234",
  "customer_id": "cus_ABC123",
  "discount_code": "SAVE20",
  "tax_rate": 7.25,
  "tax_cents": 72
}
```

**Response (201):**
```json
{
  "success": true,
  "membership": {
    "reference_num": "aB3cD4eF5gH6",
    "username": "jdoe",
    "membership": 1
  }
}
```

On success, a `// TODO: Send welcome / receipt email` stub is present in the controller for future email implementation.

---

## Registration Flow (create.ts)

1. **Plan selection** (`resources/js/afs/main.ts`): User selects a plan → `setSelectedTierPlan(tierRef)` stores the tier ref in sessionStorage → redirect to `/create`.

2. **Checkout page** (`resources/js/afs/create.ts`):
   - `getSelectedTier()` reads the stored plan and populates the plan badge and order summary.
   - **Username blur** → `GET /portal/api/v1/memberships/validate-username` → shows "available" / "taken" inline.
   - **Email blur** → `GET /portal/api/v1/memberships/validate-email` → shows "available" / "registered" inline.
   - **Discount code blur** → `GET /portal/api/v1/memberships/discount` → shows discount detail or error.
   - **Country/state blur** → `POST /portal/api/v1/memberships/tax` → shows estimated tax in the order summary.
   - **On submit**: re-checks username/email availability, then:
     - **Paid plans**: calls `confirmPayment()` via Portal SDK → on success, calls `POST /register`.
     - **Free plan**: calls `POST /register` directly.
   - On success, clears sessionStorage and shows the confirmation message.

---

## Checkpoint Authentication Integration

The `MembershipController` uses **Delight\Auth** (`delight-im/auth`) to register users in the `ext_member_*` tables alongside the membership record.

```php
// MembershipController::registerCheckpointUser()
$pdo  = DB::connection()->getPdo();
$db   = \Delight\Db\PdoDatabase::fromPdo($pdo);
$auth = new \Delight\Auth\Auth($db, null, 'ext_member_', false);
$userId = $auth->register($email, $password, $username);
```

The returned `$userId` is stored in `laravel_brain_memberships.user_id`. If checkpoint registration fails (e.g. duplicate email in `ext_member_users`), the failure is logged and the membership record is still created with `user_id = null`.

---

## Discount Codes

Discounts are stored in `laravel_processor_discounts` and managed via the Processors admin panel. The membership module only **looks up** existing discounts — it does not create them.

### Provider behaviour
- **Stripe**: Discount is a Stripe Coupon (`provider_discount_id` = Stripe coupon ID). Applied at Checkout Session or PaymentIntent creation.
- **Square**: Square Catalog Discount. Applied to Order line items at checkout.
- **PayPal**: No global discount API. Discounts are applied as item-level adjustments on the PayPal Order request. The `discount_code` field is stored in `json_data` for reference.

The `discount_code` and any resolved discount detail are stored in the membership's `json_data` column for audit purposes.

---

## Tax Implementation

### Stripe Tax
When the active processor is Stripe with Stripe Tax enabled, the controller calls the **Stripe Tax Calculations API** (`POST /v1/tax/calculations`) to obtain an accurate tax estimate for the user's country and state. The `line_items[0][amount]` is the plan's subtotal in cents.

**Requirements:**
- Stripe Tax must be enabled in the Stripe Dashboard → Tax.
- The Stripe secret key must be stored (encrypted) on the `Processor` record.

### Square Tax
Square does not expose a standalone tax estimation API. Tax is estimated using the flat-rate lookup table (see below), and the actual tax is applied at checkout time via Square Order line items with tax IDs from the Square Catalog.

### PayPal Tax
PayPal tax is applied as `item.tax_amount` in the PayPal Order request. The webhook controller does not calculate tax — it is applied at order creation time.

### Flat-rate Fallback
A lookup table of approximate US state sales tax rates is used when the Stripe Tax API is unavailable or the processor is Square/PayPal. Non-US addresses receive a 0% estimate.

The `tax_rate` and `tax_cents` values are stored in the membership's `json_data` column.

---

## Admin UI — Brain Memberships Panel

### Navigation
Brain panel → **Memberships** button (`#brain_membership_btn`) → navigates to `brain:memberships:init` subview.

### Files
- `resources/js/portal/admin/html/views/html/modules/brain/brain_memberships_content.html` — HTML form and list
- `resources/js/portal/admin/controllers/brain/brain_membership.ts` — frontend controller
- `resources/js/portal/admin/views/brainView.ts` — subview registration
- `resources/js/portal/admin/utils/templates.ts` — template import (`brain_memberships_content`)

### Features
- **Create form**: all membership fields including username/email availability checks on blur, discount code validation, tax estimation.
- **Member list**: table showing reference, username, email, membership level, status, processor, discount, tax, customer ID, and creation date.
- **Refresh button**: reloads the member list.
- **Back button**: navigates back to the Brain dashboard.

---

## Webhook Email Stubs

The `ProcessorWebhookController` contains `// TODO: Send ... email` stubs at each payment-success event:

- `recordStripeCharge()` — `charge.succeeded` / `payment_intent.succeeded` → `// TODO: Send payment-success / receipt email to $email`
- `recordStripeInvoiceCharge()` — `invoice.payment_succeeded` → `// TODO: Send subscription-renewal / receipt email to $email`
- `recordSquareCharge()` — `payment.completed` → `// TODO: Send payment-success / receipt email to $email`
- `recordPayPalCharge()` — `PAYMENT.CAPTURE.COMPLETED` / `CHECKOUT.ORDER.COMPLETED` → `// TODO: Send payment-success / receipt email to $email`

Implement these stubs using Laravel's `Mail` facade or the platform's `EmailProviderController` once email templates are available.

---

## File Map

| File | Purpose |
|------|---------|
| `database/migrations/2026_04_27_000001_create_laravel_brain_memberships_table.php` | DB schema |
| `app/Models/Membership.php` | Eloquent model |
| `app/Http/Controllers/Portal/Admin/MembershipController.php` | API controller |
| `routes/portal.php` | Route definitions (`api/v1/memberships/*`) |
| `resources/js/afs/create.ts` | Checkout page: availability checks, discount, tax, registration |
| `resources/js/afs/main.ts` | Pricing page: plan selection |
| `resources/js/portal/admin/controllers/brain/brain_membership.ts` | Brain admin TS controller |
| `resources/js/portal/admin/html/views/html/modules/brain/brain_memberships_content.html` | Brain admin HTML |
| `resources/js/portal/admin/utils/templates.ts` | Template registry |
| `resources/js/portal/admin/views/brainView.ts` | Subview registration |
| `resources/js/portal/admin/afscore/core/imp/brain/imp_brain.ts` | Button click handler |
| `app/Http/Controllers/Externals/Processor/ProcessorWebhookController.php` | Webhook email TODO stubs |
