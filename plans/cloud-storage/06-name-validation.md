# 06 — Filename validation and the preflight scan

## Completion document

When this plan step is completed, write `complete/06-name-validation.complete.md` with
status, summary, files changed, verification steps, and follow-up work.

## Code documentation

Document all new and changed code thoroughly using block comments. Each rule in the
validator carries a comment naming the rule id, what it protects against, and the platform
that motivates it.

---

## 1. What this document decides

Every upload, on every platform, is scanned before bytes move. The scan answers one
question per path segment: **can this name be stored, retrieved, and synced back to every
client we support?**

The requirement says "conform to Linux file naming restrictions". Taken literally, Linux
forbids exactly two bytes in a filename: `/` and `NUL`. That is far too permissive to be
useful — a name legal on Linux can be impossible to create on Windows, impossible to
address safely, or indistinguishable from another name after normalisation. So the rule set
below is **Linux legality as the floor, portability as the policy**, with the strictness
difference made explicit and configurable rather than hidden.

## 2. Two modes

| Mode | Who | Behaviour |
|---|---|---|
| **portable** (default) | Everyone | Full rule set below. A name that cannot round-trip to Windows or macOS is rejected at upload. |
| **posix** | Opt-in, per account | Only the **Hard** rules. Permits `?`, `*`, `:`, trailing dots, case-only siblings. Warns loudly that such files may be unusable from the Windows desktop client. |

Portable is the default because the desktop app is Electron on Windows and the mobile app
writes into sandboxes with their own constraints. A member storing a file called
`report:final?.txt` from Linux and then finding the Windows client cannot write it locally
is a worse failure — silent, later, and confusing — than an upfront rejection.

`posix` mode exists so the product does not lie about being a Linux file store. It is an
account setting, not a per-upload flag, because it changes the collision-hash basis (doc 04
§2.3).

## 3. The rule set

### 3.1 Hard rules — violate Linux or path safety. Always rejected, both modes.

| Rule id | Rejects | Why |
|---|---|---|
| `NAME_EMPTY` | Zero-length segment | `a//b` — ambiguous, and `mkdir ""` fails |
| `NAME_NUL` | Byte `0x00` anywhere | Illegal in every filesystem; truncates C strings — a classic bypass vector |
| `NAME_SLASH` | `/` inside a segment | It *is* the separator; a segment containing one is a lie about the tree shape |
| `NAME_DOT` | Segment `.` or `..` | Traversal. Rejected as whole segments, not stripped |
| `PATH_ABSOLUTE` | Leading `/`, `\`, or a drive letter (`C:`) | relPath is relative by definition |
| `PATH_UNC` | Leading `\\` or `//` | Windows UNC — reaches another host |
| `NAME_TOO_LONG` | Segment > 255 **bytes** UTF-8 | Linux `NAME_MAX`. Measured in bytes, not characters — a 100-character CJK name is 300 bytes |
| `PATH_TOO_LONG` | Full relPath > 4096 bytes | Linux `PATH_MAX` |
| `PATH_TOO_DEEP` | > 64 segments | Bounds recursion in every walker; a depth bomb is otherwise a cheap DoS |
| `NAME_INVALID_UTF8` | Not valid UTF-8 | The wire format is JSON, which is UTF-8. Invalid sequences cannot round-trip |

### 3.2 Portable rules — legal on Linux, broken elsewhere. Rejected in portable mode.

| Rule id | Rejects | Why |
|---|---|---|
| `NAME_CONTROL_CHAR` | `0x01`–`0x1F`, `0x7F` | Legal on Linux. Breaks terminals, logs, ZIP entries, and `Content-Disposition`. A newline in a filename is a log-injection primitive |
| `NAME_WIN_RESERVED_CHAR` | `< > : " \| ? *` | Cannot be created on Windows at all |
| `NAME_WIN_RESERVED_BASE` | `CON PRN AUX NUL COM1-9 LPT1-9`, with or without extension, any case | Windows resolves these to devices. `CON.txt` is `CON` |
| `NAME_TRAILING_DOT_SPACE` | Trailing `.` or space | Windows silently strips them, so `a.` and `a` become the same file after a round trip |
| `NAME_LEADING_SPACE` | Leading space | Legal but a reliable source of "I can't find my file" |
| `NAME_BIDI_OVERRIDE` | `U+202A`–`U+202E`, `U+2066`–`U+2069` | Renders `evil‮txt.exe` as `evilexe.txt`. A spoofing vector, not a formatting choice |

### 3.3 Warnings — accepted, surfaced, never blocking

| Rule id | Flags | Why it is only a warning |
|---|---|---|
| `CASE_COLLISION` | `README.md` beside `readme.md` in one folder | Legal and distinct on Linux; collapses on Windows/macOS. Blocking would reject legitimate Linux trees |
| `UNICODE_NORMALISATION` | Two names equal under NFC but differing in bytes | macOS hands out NFD, Linux NFC. Same visible name, different bytes — see §4 |
| `NAME_LEADING_DOT` | `.hidden` | Legal and intentional. Worth telling the member their file will be hidden |
| `EXTENSION_MISMATCH` | Declared MIME disagrees with extension | Informational |
| `NAME_VERY_LONG` | Segment > 180 bytes | Under `NAME_MAX` but likely to break when nested deeply on Windows (260-char `MAX_PATH`) |

## 4. Unicode normalisation — the rule that prevents silent duplicates

macOS's HFS+/APFS hand filenames to applications in **NFD** (`é` = `e` + U+0301). Linux and
Windows use **NFC** (`é` = U+00E9). The same file uploaded from a Mac and from a PC
produces two different byte sequences for what the member sees as one name.

Without a policy, that is a **silent duplicate** — the exact failure G5 forbids.

**Policy:**

1. Every relPath is normalised to **NFC** on receipt, server-side, before validation.
2. The NFC form is what is stored on disk and in `storage_nodes.rel_path`.
3. `rel_path_hash` is computed over the NFC form (case-folded too, in portable mode).
4. A client uploading the NFD form of an existing NFC name gets a **conflict**, not a
   second file.
5. The client's own scan normalises before comparing against its local index, so a Mac
   client does not perpetually believe every file has changed.

The same reasoning drives portable-mode **case folding** in the hash: `README.md` and
`readme.md` in one folder are two files on Linux and one file on Windows. In portable mode
the second is a conflict; in posix mode both exist and `CASE_COLLISION` is a warning.

## 5. Where the scan runs

| Stage | Where | Authoritative? | Purpose |
|---|---|---|---|
| 1. Local scan | Client, during the folder walk | No | Instant feedback; never send bytes that will be rejected |
| 2. `POST /scan` | Server | Yes, advisory | Full check plus conflicts and quota, without committing to a session |
| 3. `POST /sessions` | Server | **Yes** | Re-validates the entire manifest. A session cannot open with an illegal path |
| 4. `POST …/commit` | Server, per file | **Yes** | Re-validates and re-asserts containment immediately before `rename(2)` |

Stages 3 and 4 both run even though stage 2 passed. This is not redundancy for its own
sake: between the scan and the commit, minutes or hours may pass, and a symlink can be
planted at a path that validated cleanly earlier. The commit-time check is the one that
runs against the filesystem as it exists at the moment of the write (doc 12 §3).

**The client scan is never trusted.** It exists to save bandwidth and to give a good
experience, and a hostile client simply skips it — which is why stage 3 re-does all of it.

## 6. What the member sees

A rejection is useless if it says "invalid filename". The scan report names the file, the
rule, and the fix:

```
Scan found 3 problems in "Project Archive" (1,204 files, 8.4 GB)

  ✗  invoices/Q1:2026.pdf
     ':' cannot be used in a filename on Windows.
     [ Rename to Q1-2026.pdf ]  [ Skip this file ]

  ✗  scripts/build .
     Names cannot end with a space or a dot.
     [ Rename to "build" ]  [ Skip this file ]

  ⚠  docs/README.md
     Differs only in capitalisation from docs/readme.md, which is already
     stored. On Windows and macOS these are the same file.
     [ Upload anyway ]  [ Skip ]

  [ Fix all automatically ]   [ Skip all problems ]   [ Cancel ]
```

**"Fix all automatically" is opt-in and reversible.** It applies the documented
substitutions (§6.1) and shows the resulting names *before* uploading. It is never the
default, because renaming a member's file without asking is what audit defect C1 already
does and is precisely what this plan exists to stop.

### 6.1 Automatic fix substitutions

| Problem | Substitution |
|---|---|
| Windows-reserved char | `< > : " \| ? *` → `-` |
| Control character | Removed |
| Trailing dot/space | Trimmed |
| Leading space | Trimmed |
| Reserved basename | `_` suffix: `CON.txt` → `CON_.txt` |
| Segment > 255 bytes | Truncated at a UTF-8 boundary, preserving the extension |
| BiDi override | Removed |
| Result is empty | `unnamed` |
| Result collides | ` (2)`, ` (3)`, … appended before the extension |

## 7. One rule set, three implementations, one corpus

Three languages implement this: **TypeScript** (web + desktop), **Dart** (mobile), **PHP**
(server). Three implementations of a security-relevant rule set will drift. Preventing that
is a build artefact, not a code-review habit.

### 7.1 The corpus

`apps/members/resources/storage/name-fixtures.json` is the single source of truth,
committed once and consumed by all three test suites:

```jsonc
{
  "version": 1,
  "cases": [
    { "input": "start/a.txt",        "mode": "portable", "expect": "accept" },
    { "input": "../escape.txt",      "mode": "portable", "expect": "reject", "rule": "NAME_DOT" },
    { "input": "a/../../b",          "mode": "portable", "expect": "reject", "rule": "NAME_DOT" },
    { "input": "C:\\evil.txt",       "mode": "portable", "expect": "reject", "rule": "PATH_ABSOLUTE" },
    { "input": "\\\\?\\C:\\x",       "mode": "portable", "expect": "reject", "rule": "PATH_UNC" },
    { "input": "a\u0000b",           "mode": "portable", "expect": "reject", "rule": "NAME_NUL" },
    { "input": "CON",                "mode": "portable", "expect": "reject", "rule": "NAME_WIN_RESERVED_BASE" },
    { "input": "con.txt",            "mode": "portable", "expect": "reject", "rule": "NAME_WIN_RESERVED_BASE" },
    { "input": "trailing ",          "mode": "portable", "expect": "reject", "rule": "NAME_TRAILING_DOT_SPACE" },
    { "input": "q1:2026.pdf",        "mode": "portable", "expect": "reject", "rule": "NAME_WIN_RESERVED_CHAR" },
    { "input": "q1:2026.pdf",        "mode": "posix",    "expect": "accept" },
    { "input": "a\nb.txt",           "mode": "portable", "expect": "reject", "rule": "NAME_CONTROL_CHAR" },
    { "input": "evil\u202Etxt.exe",  "mode": "portable", "expect": "reject", "rule": "NAME_BIDI_OVERRIDE" },
    { "input": "café.txt",           "mode": "portable", "expect": "accept", "normalised": "café.txt" },
    { "input": "cafe\u0301.txt",     "mode": "portable", "expect": "accept", "normalised": "café.txt" },
    { "input": "…256-byte segment…", "mode": "portable", "expect": "reject", "rule": "NAME_TOO_LONG" }
  ]
}
```

### 7.2 The parity requirement

Each platform ships a test that reads this file and asserts its validator agrees on every
case — same verdict, same rule id, same normalised output.

**Acceptance A3 is this test passing in all three languages.** A rule added to the corpus
fails two builds until both are updated, which is the point: the corpus is what makes
"three implementations" safe rather than a promise.

### 7.3 Reference implementation

`afsdesktop5/src/main/sync/paths.ts` already implements most of this rule set for LAN
transfer and is the reference for the TypeScript port.

One bug found there is worth repeating here because every implementation is vulnerable to
it: the validator originally called Node's OS-native `path.normalize()` before splitting on
`/`. On Windows that converts `/` to `\`, so `folder/../../escape.txt` collapsed into a
single segment containing embedded backslashes, the per-segment `..` check never fired, and
`path.join` later honoured those backslashes as real separators — reconstructing the escape
outside the root. It typechecked and passed casual review; only a fixture test caught it.

**The rule that prevents the whole class:** relPaths are POSIX on the wire, so split and
validate with POSIX semantics (`path.posix`, Dart's manual split, PHP string ops) on every
platform, and convert to native separators only at the final join — after validation.
