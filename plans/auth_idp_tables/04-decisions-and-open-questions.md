# 04 — Decisions and open questions

| # | Question | Options | Recommendation | Needed before |
|---|---|---|---|---|
| **OD1** | On the lab (and later elsewhere), which IDP accounts exist after the change? | (a) carry the legacy `admin` (`super_admin`, created 2026-02-28) with `admin:import-from-shared`; (b) start clean: only the seeder's `admin` account (hard-coded `admin123`, forced to change at first login); (c) both | **(b) for the lab**, (a) wherever a real account must survive (production, if its accounts are in the shared copy). The legacy row is from before the split; its password is unknown to this audit | 02, 03 |
| **OD2** | How is the forced password change decided and enforced? (Owner decision 2026-10-10: keep the hard-coded `admin` / `admin123` account and prompt for a new password like `w/www`, decided by the password itself.) | (a) as planned in step 01 §1.5: the server derives "must change" from the stored hash (no column), blocks the other admin routes until the change, and the screen shows the `w/www`-style modal; (b) identical to `w/www`: the browser compares the typed password with the default and prompts; nothing on the server stops API use meanwhile | **(a)** — same prompt, rules and wording, decided by the password value, but the API is held too | 01 |
| **OD3** | Auth's tests run SQLite in memory by default (`phpunit.xml:26–27`). The migration and seeder tests must run on a real database (standing rule) | a MariaDB test database `testdb_phpunit_auth` on `10.0.0.243`, given by the run's `DB_*`; or keep SQLite for the file-parsing guard only and skip the DB tests | **the MariaDB test database**, `phpunit.xml` untouched | 01 tests |
| **OD4** | The unused `admin_users` / `admin_sessions` in the shared databases | leave for good; drop later after verification and a backup | **leave in P0/P1; decide the drop separately (P2)** with an explicit go-ahead — a `DROP` is destructive | 02 P2 |
| **OD5** | The dead copy of `AdminController` in `w/www` (`app/Http/Controllers/Admin/AdminController.php`, unrouted, no service behind it) | remove; leave | **remove**, as its own small change in `w/www` (the owner's call; not needed for this fix) | — |

## Questions only the owner (or the server agent) can answer

1. Production: the facts in [03](03-rollout-and-verification.md) §3.2 — above all, where the IDP's accounts are today and whether `db:seed` has run there.
2. Does anything outside the repositories read the shared copies of `admin_users` (reports, scripts, another app)? The audit found none in `w/`, and `account_close` already treats staff accounts as untouched.
