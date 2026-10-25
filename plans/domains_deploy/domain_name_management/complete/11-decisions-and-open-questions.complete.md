# 11 — Decisions and open questions: completion report (domain name management)

> **Split 2026-10-23.** This completion report covered a step that served both features. It is now two reports: this file is the **domain name management** half; the **website deployment** half is [../../new_way/complete/11-decisions-and-open-questions.complete.md](../../new_way/complete/11-decisions-and-open-questions.complete.md). Numbers (§, F, D, E) are unchanged.

> **Status: brought up to date with what steps 02–10 built and found (2026-10-09). No decision was made for the owner: where the code embodies a recommendation it says "built as recommended — confirm" and stays a decision.**
> Nothing is committed. The only file changed is [11-decisions-and-open-questions.md](../11-decisions-and-open-questions.md).

## What changed in the document

- **A status board** at the top: every decision with its state (owner answered · built as recommended — confirm · open — needs the owner), what the build did about it, and what is left.
- **Decisions added** (D19–D22, D24–D27, D29), each from something the work raised:
  - **D19** (settings only in the Brain) and **D20** (ssh by password through bash + `expect`) — the owner's instructions of 2026-10-09, with D20's open sub-questions: server passwords are stored in plain text in the database, production servers must allow password login, a non-root login needs `sudo -n`, the production Brain's OpenSSH/`expect` are unchecked.
  - **D21** — Name.com's legacy v4 API (which `NameComDriver` uses) is documented as being retired in 2026; CORE v1 and a sandbox run come before any real purchase.
  - **D22** — the cut-over is per member, decided by the Brain settings (confirm the 60 s cache and the fail-safe direction).
  - **D24** queue and scheduler on the Brain (the lab Brain has neither) · **D25** where alerts go · **D26** the production fleet list the guard needs · **D27** translations · **D29** the Direct Payments product for domain invoices.
- **Stale statements corrected:** the "guard in code is not built" line (it is), the `DOMAINS_USE_BRAIN_SURFACE` line (replaced by the per-member Brain setting), and "the ten P0 checks" (eleven, answered).
- **Lines added to "Settled"** (settings only in the Brain; ssh by password; the fleet guard).

## State of the decisions

| State | Decisions |
|---|---|
| Owner answered | D19, D20 (closed 2026-10-09), D21 (built, E10), D22 (confirm details) |
| Answered as built | D10, D14 |
| Built as recommended — confirm | D4, D5, D7, D8, D9, D12 |
| Open — needs the owner | D3, D6, D24, D25, D26, D27, D29 |

## Not verified

- The "built as recommended" claims were read from the steps' completion reports and the code I wrote; production's database engine for D8 and the old-code request bodies for D6 were **not** re-checked now.
- Nothing in this step was tested; it is a document.

## Update 2026-10-09 (later)

- **D20 closed** by the owner: plain-text passwords in the database are acceptable, production servers allow password login, and the production Brain's OpenSSH and `expect` are expected to work.
- **D21 answered and built:** `NameComDriver` now speaks CORE v1 (`complete/00_extras.md` E10).
