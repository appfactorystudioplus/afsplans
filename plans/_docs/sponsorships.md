# Sponsorship Module Documentation

The Sponsorship module is a full-stack feature allowing administrators to configure sponsorship programmes, collect payments via Direct Payment objects, approve sponsors, and provide a dedicated brand management dashboard for approved sponsors.

## Architecture Overview

The module follows the established Appfactory Studio architecture, consisting of four main components:
1. **Database Layer**: Four `laravel_`-prefixed tables storing configuration, applications, and sponsor accounts.
2. **Admin Portal**: Three UI views (`sponsorships`, `sponsorships:create`, `sponsorships:applications`) integrated into the `Financials` section.
3. **External API**: A standalone TypeScript client (`external/sponsorships/api.ts`) used on public websites to display tiers and submit applications.
4. **Sponsor Dashboard**: A bespoke Single Page Application (SPA) located at `/portal/sponsors` for sponsors to manage their brand assets.

---

## 1. Database Schema

- `laravel_sponsorships`: Top-level configuration. Links to a Direct Payment object.
- `laravel_sponsorship_levels`: The available tiers (e.g., Gold, Silver). Supports `specific`, `range`, or `any` amount types.
- `laravel_sponsorship_applications`: Inbound applications. Tracked via `pending`, `approved`, or `denied` statuses.
- `laravel_sponsor_users`: Authenticated sponsor accounts. Stores `brand_data` as a JSON blob.

---

## 2. Admin Workflow

1. **Create Programme**: Navigate to `Financials` > `Sponsorships`. Click **Create Sponsorship**.
2. **Configure Payment**: Select an existing **Direct Payment** object. This is what handles the actual Stripe/PayPal checkout on the frontend.
3. **Set Levels**: Add tiers. Define the amount type (Specific, Range, or Any) and list features.
4. **Configure Email**: Once saved, click **Configure Email**. This opens the `EmailConstructionBuilder` to define the template sent to sponsors upon approval.
5. **Approve Applications**: View the **Applications** tab. Approving an application automatically generates a `username` and `password`, creates a `SponsorUser` record, and emails the credentials to the applicant.

---

## 3. Public Website Integration (External API)

The module provides an external JS client to embed sponsorship options into any published Builder page or custom site.

```typescript
import { createSponsorshipAPI } from './external/sponsorships/api';

const api = createSponsorshipAPI();

// Renders the 'grid' template for a specific sponsorship into the #sponsor-section div
await api.renderSponsorTemplate('grid', '#sponsor-section', {
    sponsorshipRef: 'YOUR_SPONSORSHIP_REF',
    onLevelSelect: (level) => {
        console.log('User selected:', level.title);
        // Integrate with existing Direct Payment checkout flow here
    }
});
```

Available built-in templates:
- `minimal`: Clean list layout.
- `showcase`: Hero block with prominent tier cards.
- `grid`: Responsive grid of tiers.

### Payment Flow
1. User selects a tier via the external template.
2. Your frontend initiates a Direct Payment checkout.
3. On successful payment, your frontend calls:
   ```typescript
   await api.submitSponsorApplication({
       sponsorship_reference_num: '...',
       level_reference_num: level.reference_num,
       applicant_name: '...',
       applicant_email: '...',
       amount_paid: 500.00
   });
   ```

---

## 4. Sponsor Dashboard

Approved sponsors receive a link to `/portal/sponsors`. 
- **Authentication**: Custom JWT-style session token stored in `laravel_sponsor_users.session_token` (24h TTL).
- **Features**: 
  - Update display name and bio.
  - Set brand colors (Primary & Accent).
  - Upload Logo and Cover images.
  - Manage social media links.
  - Change password.

The dashboard uses a vanilla TS SPA approach, avoiding external framework dependencies to ensure fast loading and strict separation from the main Admin portal.
