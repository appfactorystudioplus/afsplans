# 03 — Advanced features

Website-appropriate subset, cross-referencing
[`../desktop/05-advanced-features.md`](../desktop/05-advanced-features.md)
rather than restating shared reasoning.

## 1. Reactions and raised hand

Same LiveKit data-channel mechanism as desktop/mobile — directly portable, low effort.

## 2. Meeting whiteboard

Same value proposition as desktop's Excalidraw-backed whiteboard
(`afsdesktop5` `05` §2), and `@excalidraw/excalidraw` is a **web-native** React component in the
first place — arguably the most natural platform for this feature to live on, since no
Electron/native wrapping is involved at all. If built once, the same component could plausibly
be shared between `w/members`' web client and `afsdesktop5`'s Electron renderer (both are
Chromium-hosted web content) — worth evaluating as shared code rather than building it twice
when this feature is actually scheduled.

## 3. Live captions

Same value as desktop (`afsdesktop5` `05` §1). `w/members` has no Whisper native addon to reuse
(that's Electron-native code specific to `afsdesktop5`) — for the web client this should follow
the same **server-side** recommendation given to mobile
(`../mobile/04-advanced-features.md` §1): captioning runs in
`w/communications` (or a service it calls), streamed to every client over the signaling
channel, so no client-specific implementation is needed on web, mobile, or desktop beyond
rendering the caption text that arrives.

## 4. Embeddable/public meeting pages

A website-specific opportunity with no direct desktop/mobile analog: a member's room join page
could be given a memorable public-facing URL (e.g. under their own domain, tying into the
existing `member_domains_count` / website-builder infrastructure) rather than only a generic
`members.appfactory.studio/j/:code` link — "meet.yourdomain.com" style. Flagged as a
plausible v2 differentiator, not scoped further here since it depends on the website-builder
plan's own extension points, not this one.
