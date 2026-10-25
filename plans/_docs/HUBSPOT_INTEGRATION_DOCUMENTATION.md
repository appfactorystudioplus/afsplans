# HubSpot Integration Implementation

## Summary
Added a HubSpot OAuth integration to the portal settings General tab using a Laravel backend implementation. The integration opens HubSpot authorization in a separate browser tab, exchanges the returned authorization code for tokens, and stores the integration state in new `laravel_` prefixed database tables.

## Added Files
- `app/Models/Integrations/Integration.php`
- `app/Models/Integrations/IntegrationLog.php`
- `app/Models/Integrations/Hubspot/HubspotIntegration.php`
- `app/Models/Integrations/Hubspot/HubspotIntegrationLog.php`
- `app/Http/Controllers/Integrations/Hubspot/HubspotIntegrationController.php`
- `database/migrations/2026_04_14_000001_create_laravel_integrations_table.php`
- `database/migrations/2026_04_14_000002_create_laravel_integration_logs_table.php`
- `resources/views/portal/admin/integrations/hubspot/hubspot_callback.blade.php`
- `HUBSPOT_INTEGRATION_DOCUMENTATION.md`

## Updated Files
- `config/services.php`
- `routes/portal.php`
- `resources/js/portal/admin/html/views/settings.html`
- `resources/js/portal/admin/views/settings.ts`

## Behavior
1. A new HubSpot card is displayed in the Settings > General tab.
2. Clicking the `Connect HubSpot` button opens `/portal/api/integrations/hubspot/authorize` in a new tab.
3. The backend redirects the user to HubSpot’s OAuth authorization screen.
4. HubSpot revokes to `/portal/api/integrations/hubspot/callback` after approval.
5. The callback route exchanges the authorization code for tokens and stores them in the database.
6. The portal settings UI can query `/portal/api/integrations/hubspot/status` to display connection status.

## Database Tables
Built using Laravel migrations with `laravel_` prefixes:
- `laravel_integrations`
- `laravel_integration_logs`

## Required Environment Variables
Add these to your `.env` file:
- `HUBSPOT_CLIENT_ID`
- `HUBSPOT_CLIENT_SECRET`
- `HUBSPOT_SCOPES` (optional; defaults to `crm.objects.contacts.read`)

## HubSpot App Setup
In your HubSpot app settings, set the redirect URI to:
- `https://<your-domain>/portal/api/integrations/hubspot/callback`

## How to Run
1. Run the migrations:
   ```bash
   php artisan migrate
   ```
2. Make sure the HubSpot app credentials are configured in `.env`.
3. Open the portal admin and navigate to Settings > General.
4. Click `Connect HubSpot` and complete the OAuth flow in the new tab.

## Notes
- This implementation does not modify `public/_portal_`.
- The new callback page is rendered inside `resources/views/portal/admin/integrations/hubspot/hubspot_callback.blade.php`.



Project Path
public/_portal_/admin/core/services/_integrations/hubspot

✔ Enter a unique name to reference this account in the CLI: www-appfactorystudio

✔ SUCCESS Created config file "/Users/mitchellfamily/.hscli/config.yml"
✔ SUCCESS Connected account www-appfactorystudio [standard] (244106628) and set it as the default account
--------------------------------------------------
What's next?

  - `hs get-started` - Get started with HubSpot development
  - `hs help` - See a list of available commands
  - `hs accounts list` - See a list of configured HubSpot accounts


