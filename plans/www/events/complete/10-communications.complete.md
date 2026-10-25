# 10 — Communications (complete)

**Plan:** `10-communications.md`  
**Rollout phase:** P7  
**Repo:** `www`

## Shipped

- `EventCommunicationService` — confirmation log + ICS builder
- ICS download route on storefront
- Confirmation triggered on free/confirmed register

## Deferred

- Scheduled reminders, waitlist offer emails, webhooks (MAIL_MAILER=log locally)

## Verification

GET `/portal/api/events/storefront/registrations/{ref}/calendar.ics?token=...`
