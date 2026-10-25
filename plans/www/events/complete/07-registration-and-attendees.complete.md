# 07 — Registration and attendees (complete)

**Plan:** `07-registration-and-attendees.md`  
**Rollout phase:** P4  
**Repo:** `www`

## Shipped

- Order + registration split, idempotency, hold-before-register path
- Waitlist join + promote service
- Cancel registration with capacity release
- Attendee token + Site Users `myRegistrations`
- Promo code on register
- `form_submission_ref` validated on register (optional pin to registration row)

## Deferred

- Forms module inline submit on register (client submits form first, passes ref)
- Group modes (assign later, self-registration link)
- Approval workflows, transfers

## Verification

Playground: `public/playground/externals/events/register/`
