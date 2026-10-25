# 06 — Logic and Validation — Partial

## Status

**Server-side enforcement (§6.9):** `FormSubmissionValidator` validates submissions against the
published version's `question_index` — unknown keys, required fields, choice membership, email
and basic SurveyJS validators. Simple `visibleIf` / `requiredIf` expressions evaluate when
parseable; unparseable expressions fail open with `validation_state = unverified`.

**External SDK:** `renderer.ts` sends `idempotency_key` and `duration_seconds`; `api.ts` raises
`ValidationError` on 422 with per-question errors.

## Not yet built

- Full condition builder UI (§6.2)
- Properties panel for logic/validators (§6.3–§6.4)
- Complete reduced expression evaluator (§6.9.2)
- Publish-time unsupported-expression warnings (§6.9.3)
- Matrix / dynamic panel scoped validation (§6.10)

## Verification

```bash
cd www
php artisan test --filter=FormSubmissionValidator
```
