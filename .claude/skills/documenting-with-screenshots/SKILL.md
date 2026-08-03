---
name: documenting-with-screenshots
description: Use when writing or updating documentation that shows real UI screenshots or real command output — including when a screenshot may be stale after a UI change, when a documented flag turns out not to work, or when captures need regenerating. Covers capturing safely from a fixture tenant and making stale evidence fail the build.
---

# Documenting with screenshots

## Overview

Documentation that shows the product must be **captured from the product**, never drawn from
memory. Two rules carry most of the value:

1. **Capture from a fixture tenant that holds no real data.** A list screen publishes every row
   in it, and unlike text, an image cannot be redacted after it ships.
2. **Make missing or renamed evidence break the build.** Evidence that fails silently is worse
   than no evidence: the page keeps asserting something nobody re-checked.

## When to use

- Adding or refreshing screenshots in user-facing docs
- Documenting CLI or API output
- Auditing whether existing documentation still matches the product

Not for internal design notes with no user-facing screens.

## Where the skill file goes

`.claude/skills/<name>/SKILL.md` is read by **both Claude Code and OpenCode** (OpenCode also
searches `.opencode/skills/`, `.agents/skills/`, and the same three under `$HOME`). One directory
serves both runtimes. The name must be lowercase with single hyphens and match its directory.

## Workflow

1. **Seed a fixture tenant.** Its own team and user, created by the script rather than reused.
   Idempotent and additive, so re-running never mutates a working tenant. Give it enough content
   that each screen shows something: a screen documented against an empty table teaches nothing.
2. **Extend the capture script** (`capture-screenshots.mjs` in this directory). Each screen
   declares text that must be present before the shot is taken.
3. **Run it and read the failures.** A failing screen means either the route moved or the
   precondition is missing — both are findings, not noise.
4. **Write the prose, then verify every factual claim against the code** that implements it.
5. **Verify the whole thing** with the checklist below.

## Rules, and the failure each one prevents

| Rule | What happened without it |
| --- | --- |
| Abort if login lands in a tenant other than the fixture one | A capture run pulled a workspace whose rows were real customer names |
| Remove the dev-server error overlay before shooting, and report it | A Vite overlay covered the page and shipped local filesystem paths inside the image |
| Assert on body/heading text, not on a control's label | A wait on a button timed out because its label came from `aria-label`, which text matching does not see |
| Delete the old image when a capture fails | A stale screenshot stayed in place looking current after its screen was gone |
| Exit non-zero on any failed screen | The run "passed" with 13 of 15 screens captured |
| Never hand-write command output | Captured output stayed correct through three data changes; the hand-written parts silently rotted |
| Refuse to write an empty capture | An erroring command produced a blank code block that read as "this prints nothing" |
| Resolve images and captures at build time | A renamed capture would otherwise render as a broken image in production |

## Verify claims against code, not against the screen

The interface shows what is *rendered*, not what is *permitted* or *accepted*. Every one of these
was written from the UI and was wrong:

- "The audit log is role-filtered" — the controller aborts with 403 for everyone below admin.
- Template placeholders looked like `{name}` on screen; the renderer only matches `{{a.b}}`.
- A documented event name did not exist in the enum, so every example would have 422'd.
- Three CLI flags were accepted and silently ignored by the API.

**Read the controller, the enum, the validation rules.** When a documented command exists, run it
(`--dry-run` if it writes). If a flag does nothing, say so in the docs — do not describe intended
behavior as real.

## Red flags

- "I'll just describe what the screen looks like" → capture it
- "The screenshot is probably still fine" → re-run the capture; the diff answers it
- "It rendered, so the capture succeeded" → check the content assertion, not the exit of `goto`
- "This tenant is only demo data" → verify that, by name, before capturing
- "The API surely accepts this" → run it

## Verification checklist

- [ ] Docs build succeeds and every internal link resolves
- [ ] Every referenced screenshot and capture exists on disk; none unused
- [ ] No API key, token, password, or foreign tenant's data in any captured artifact
- [ ] Every documented command was executed or dry-run
- [ ] Capture scripts committed, so the next person regenerates instead of redrawing
