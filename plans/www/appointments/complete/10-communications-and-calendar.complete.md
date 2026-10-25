# 10 — Communications and calendar (complete)

**Plan:** `10-communications-and-calendar.md`  
**Repo:** `www`  
**Date:** 2026-08-31 (updated)  
**Status:** Two-way calendar sync via ICS import + outbound feed; OAuth deferred.

## Shipped

| Item | Location |
|---|---|
| Confirmation + ICS attachment | `AppointmentCommunicationService` |
| Customer ICS download | storefront `…/calendar.ics` |
| **Inbound sync** | Staff ICS feed URL → `laravel_appointment_busy_blocks` |
| **Outbound feed** | Admin `GET …/staff/{ref}/calendar.ics` (confirmed bookings) |
| ICS parser | `Appointments\Support\IcsParser` |
| Connections table | `laravel_appointment_calendar_connections` |
| Sync command | `appointments:sync-calendars` |
| Loop prevention | Skip UIDs ending `@appfactory.studio` / `bkg_` prefix |
| Titles off by default | `import_titles` flag |
| Busy blocks in availability | `AppointmentAvailabilityService` |

## Admin

- Connect ICS feed per staff (calendar view UI)
- Manual sync + scheduled `appointments:sync-calendars`

## Deferred

- Google/Outlook OAuth token store (§10.6 connection management)
- Reminder jobs (§10.5)
- Push to external API (outbound is subscribe-via-ICS URL)
- Webhooks, daily schedule email

## Verification

```bash
php artisan appointments:sync-calendars
php artisan appointments:sync-calendars --staff=stf_xxx
```

Connect a staff member's secret Google Calendar ICS URL in **Calendar → Calendar sync**, then confirm busy times disappear from availability.
