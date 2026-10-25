# 15 — Allocation — Partial

## Status

- Client: `allocV2.canUse` / `consume` / `release` on `domain_forms_count` for create, duplicate, delete
- Server: `FormAllocationService` backstop via `FORMS_MAX_PER_SITE` config
- Server: monthly submission quota via `FormSubmissionQuotaService` + `FORMS_SUBMISSION_MONTHLY_LIMIT`

## Not yet built

- Wire tier limits from alloc_v2 registry dynamically (replace env defaults)
- Archive releases slot without delete (§15.3.1)
