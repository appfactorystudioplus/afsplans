# 06 — Editor Modes

Two views, one state. The JSON editor stays exactly as capable as it is today.

## 6.1 Why the raw editor stays

Every generated form has a shape it cannot express, and the operator meets that shape on the day
something is already wrong. The registry object can hold an unknown resource id, a hand-added
field, a half-written entry from an interrupted save, a `periodStart` in the wrong encoding, an
`ac_*` counter for a domain that no longer exists — none of which the form has a control for,
all of which someone may need to fix at 2am.

The raw editor is the difference between that being a support call and being a fix. It is not a
legacy mode to be deprecated; it is the escape hatch, and it is listed first in this plan's rules
for that reason.

## 6.2 One state, two projections

```ts
/**
 * AFS_BRAIN_ALLOC_MODIFIER_ADDITION — plans/members_brain/06-editor-modes.md §6.2
 *
 * ONE draft. The form reads and writes paths into it; the JSON editor reads and
 * writes the whole of it. Switching mode re-renders from the draft and never
 * copies between two buffers.
 *
 * Two independently-held buffers would make "which one wins on Save" depend on
 * which tab was last touched — a question the operator cannot see the answer to
 * and would only ask after losing an edit.
 */
interface AllocationEditorState {
    pristine:  Record<string, any>;   // registryObj.toJSON() as loaded — never mutated
    draft:     Record<string, any>;   // the single editable document
    touched:   Set<string>;           // JSON-pointer paths the operator changed — 07 §7.4
    mode:      'form' | 'json';
    loadedAt:  Date;
    updatedAt: string;                // the Parse value at load — 07 §7.6
}
```

- **`pristine` is never mutated.** It is what the diff compares against
  ([07](07-save-semantics.md) §7.3) and what **Discard** restores.
- **`touched` is the point.** It is what makes a save a merge rather than an overwrite, and it is
  recorded by both modes — the form on every control change, the JSON editor by diffing its
  buffer against `pristine` on every change event.
- Switching modes is a **re-render**, never a transfer.

## 6.3 The representability check

Before rendering the form, the draft is checked against what the form can express. Anything that
fails is **unrepresentable content** — not invalid, just outside the generated controls.

| Case | Example |
|---|---|
| Unknown resource id | an `entry.key` with no match in the registry's 34 |
| Unknown top-level field | anything that is not `member_resources`, `domains`, `pm_projects`, an `ac_*` counter, or a Parse internal |
| Malformed entry | `used` that is not a number; an entry that is not an object |
| Orphan counter | an `ac_d_<token>__…` whose domain is not in `domains[]` |
| Wrong-shaped container | `domains` as an object rather than an array |

The check produces a list of JSON pointers, and it runs on **load** as well as on **switch** —
an operator who opens a member with an orphan counter should be told, not have it quietly not
render.

## 6.4 Switching with unrepresentable content

**The form never silently drops what it cannot render.** Two rules:

**Rule 1 — unrepresentable content is preserved verbatim through a form edit.** The form writes
paths into the draft; it does not rebuild the draft from its own model. A field the form has
never heard of is still there after a form save, untouched.

**Rule 2 — switching to Form with unrepresentable content warns, and offers to show it.**

```
┌─ 3 things this form can't show ───────────────────────────┐
│  • /member_resources/legacy_thing   unknown resource id    │
│  • /ac_d_oldsite_com__domain_forms_count   orphan counter  │
│  • /domains/1/resources/x/used   not a number              │
│                                                            │
│  They will be left exactly as they are. Edit them in JSON. │
│                     [ Stay in JSON ]  [ Show form anyway ] │
└────────────────────────────────────────────────────────────┘
```

The form then renders with a persistent banner — *"3 fields not shown"* — that opens the same
list. A warning that appears once and can be dismissed is a warning that will be dismissed.

**The orphan-counter case deserves its own note.** An `ac_*` field whose domain has been removed
is the authority for a bucket with no container ([03](03-the-data-model.md) §3.5), so it will win
over anything if that domain name ever returns. The list offers **[remove]** for exactly this
case — it is the one piece of unrepresentable content with an obvious correct action.

## 6.5 Switching the other way

Form → JSON is always safe: the draft is already the document. The JSON editor opens on the draft
including pending form edits, and the diff highlights them.

**Pending changes survive both switches.** The mode toggle is not a commit and not a discard, and
the pending-changes count in the footer does not move when it is pressed.

## 6.6 The JSON editor, unchanged but better placed

`vanilla-jsoneditor` stays, with the same configuration. Three things around it change:

- **Parse internals are not in the buffer.** `objectId`, `createdAt`, `updatedAt` and `ACL` are
  stripped on load rather than on save, so what the operator edits is what will be written
  (**BR22**). `updatedAt` moves to the header as a fact.
- **The existing `href="#"` click guard stays.** `brain_membership.ts:1229` already prevents the
  editor's anchors from jumping the page to the top. That is a real fix for a real annoyance and
  it survives the rewrite.
- **Save goes through the same validation, diff and merge** as the form
  ([07](07-save-semantics.md)). The raw editor is an escape hatch from the *controls*, not from
  the *safety*. An operator who genuinely needs to write something validation rejects has
  `--force` in the confirmation dialog and an audit row that records they used it.

## 6.7 Which mode saved

`laravel_brain_alloc_audit.mode` records `form` or `json`
([02](02-security-and-access.md) §2.5). A targeted control change and a hand-edited document fail
differently, and knowing which one preceded a problem is worth one enum column.

If the operator edited in both modes before saving, the value is `json` — the wider surface is
the one that could have done anything.

## 6.8 Acceptance

- Editing a value in Form, switching to JSON, and switching back shows the edit in both, once
  (§6.2).
- The **pending changes** count does not change when the mode toggle is pressed.
- A draft containing an unknown resource id renders the warning, lists the pointer, and — after
  a form-mode save of an unrelated row — **still contains it, byte-identical** (§6.4 rule 1).
- The unrepresentable-content banner is **persistent**, not dismissible.
- An orphan `ac_*` counter is listed with a **[remove]** action, and removing it is a normal
  diffed change (§6.4).
- **Discard** restores `pristine` in whichever mode is open, and clears `touched`.
- `objectId`, `createdAt`, `updatedAt` and `ACL` are absent from the JSON buffer (§6.6).
- A save made in JSON mode records `mode = json`; one made in Form mode records `mode = form`; a
  session that used both records `json` (§6.7).
- The editor's `href="#"` anchors still do not scroll the page (§6.6).
