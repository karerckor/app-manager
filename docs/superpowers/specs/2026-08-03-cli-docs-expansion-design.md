# CLI documentation expansion — design

**Date:** 2026-08-03
**Branch:** `docs/cli-guide` (worktree `.claude/worktrees/docs-cli-guide`, based on `feat/credentials-byov`)

## Problem

The `appmctl` guide is a single page. It was rewritten to match the current command
surface, but the request is for a much more detailed reference split across nested
pages, with real command output rather than invented samples.

Two of the requested pages describe functionality the CLI does not have, and one
describes functionality the platform does not expose at all:

| Requested page | API | CLI |
| --- | --- | --- |
| `identifiers` | `/apps/{slug}/identifiers` CRUD exists | no command |
| `integrations/eas` | full Expo surface exists | no command |
| `integrations/notifications` | **no API routes at all** — web UI only | no command |

## Decisions

1. **Build the missing commands first**, then document them: `appmctl identifiers`
   and `appmctl eas` (full surface, 12 actions).
2. **Notifications gets a page that tells the truth**: configuration is web-UI only,
   there is no API or CLI surface yet, and webhooks are the automation path today.
   Building the notifications API is out of scope for this project.
3. **Output examples are captured from a live instance**, not written by hand.
4. **A `customer-app` demo app is seeded** so captured output reads well. Existing
   demo slugs are content hashes (`app-5aa64369d1870543ad42cf3e`); they cannot be
   prettified after capture because `format_table` aligns columns to value widths,
   so substituting a slug would break the alignment of every captured table.

## Part 1 — `appmctl identifiers`

New module `libs/cli/lib/commands/identifiers.sh`, mapping 1:1 to
`/apps/{mobileApp:slug}/identifiers`.

```
identifiers list   [--app APP] [--per-page N]
identifiers show   [--app APP] IDENTIFIER_ID
identifiers create [--app APP] --platform ios|android|web --identifier ID
                   [--name NAME] [--version V] [--version-code N]
identifiers update [--app APP] IDENTIFIER_ID [same flags]
identifiers delete [--app APP] IDENTIFIER_ID
```

- `list` renders a table of platform / identifier / name / version.
- `show`, `create`, `update` render a resource block; `delete` prints a confirmation.
- Client-side validation covers only the platform enum (`ios`, `android`, `web`).
  Uniqueness, the Android-only `version_code` restriction, and the web domain rule
  stay on the server so the CLI cannot drift from `ValidatesAppIdentifier`.
- Abilities: `identifiers:read` for reads, `identifiers:write` for writes.

## Part 2 — `appmctl eas`

New module `libs/cli/lib/commands/eas.sh`. The API splits across two scopes: the
Expo account (attached to an integration) and the project link (attached to an app).
Rather than inventing `sync` and `app-sync`, the scope is chosen by which flag is
passed — `--integration-id` targets the account, `--app` targets the project link.

```
eas connect --token TOKEN [--account NAME]
eas status             (--app APP | --integration-id ID)
eas sync               (--app APP | --integration-id ID)
eas disconnect         (--app APP | --integration-id ID)
eas projects  --integration-id ID
eas import    --integration-id ID --project-id UUID
eas link      --app APP --integration-id ID --mode create|existing [--project-id UUID]
eas auto-sync --app APP --enabled true|false
eas identifiers        --app APP
eas sync-identifiers   --app APP
eas provision          --app APP --identifier-id ID [--credential-ids A,B]
eas attach-credential  --app APP --identifier-id ID --credential-id ID
```

Actions that accept either scope must reject being given both, and must reject being
given neither.

The GraphQL and REST proxy endpoints (`/expo/graphql`, `/expo/proxy/{path}`) stay out
of the command. They are debugging tools whose payloads are pass-through, so the docs
show them through `appmctl request` instead.

Both modules get registered in `build.sh` (bundle order) and `parsing.sh` (resource
list, global option parsing, dispatch), a test file each, and fixtures in
`tests/fixtures/mock_curl.sh`.

## Part 3 — documentation structure

`DocsLayout` currently renders a flat list of nav sections. It gains support for a
`children` array so the CLI tree can nest, with the CLI section expanded when the
active path sits under `/guides/cli`.

| Page | Content |
| --- | --- |
| `/guides/cli` | What the CLI is, resource → action map, exit status, HTTP status table |
| `/guides/cli/installation` | Installer, custom dir, pinned commit + checksum, local checkout |
| `/guides/cli/configuration` | Env vars, global options, API key scopes, security model |
| `/guides/cli/applications` | `apps`, plus `assets` for icon and splash screen |
| `/guides/cli/identifiers` | `identifiers` |
| `/guides/cli/credentials` | `credentials`, `--data-file` over deprecated `--data` |
| `/guides/cli/files` | `files`, including metadata handling |
| `/guides/cli/builds` | `builds`, tags, `wait`, `when-running`, `logs`, `artifacts` |
| `/guides/cli/builds/manual-builds` | Manual reporting mode, `stream`, status management |
| `/guides/cli/builds/automated-builds` | Provider-reported builds, why manual writes 409 |
| `/guides/cli/builds/eas-builds` | `--reporting-mode eas`, `--expo-build-id` |
| `/guides/cli/integrations` | `integrations` list/get/repos/workflows/refs |
| `/guides/cli/integrations/eas` | `eas` |
| `/guides/cli/integrations/pipelines` | `pipelines` |
| `/guides/cli/integrations/webhooks` | `webhooks` |
| `/guides/cli/integrations/notifications` | Current state: web UI only, no API; use webhooks |
| `/guides/cli/examples` | GitHub Actions, GitLab CI, full lifecycle, gating on a build |

The build pages are split by reporting mode rather than by abstraction, because that
is the distinction the API actually enforces.

## Part 4 — output capture

`scripts/docs/capture-cli-output.sh`:

- reads `APP_MANAGER_API_KEY` from the environment, never from a file or argument
- runs a fixed list of read-only commands against the local instance
- writes each command's stdout to `apps/docs/src/data/outputs/<slug>.txt`
- is re-runnable, so a formatting change in the CLI shows up as a diff in review

Pages import the captured text with Vite's `?raw` suffix and render it through
`CodeBlock`. Captured files are checked for integration tokens before committing.

Write commands (`create`, `delete`, `upload`) are documented but not captured against
the live instance, to keep the harness read-only and repeatable. Their output shape is
shown from the corresponding read command where the response is the same resource.

## Verification

- every CLI test suite passes, including the two new files
- every documented example command survives `--dry-run` (flag names proven to exist)
- `pnpm build` succeeds and every internal link resolves to a built page
- captured outputs contain no API keys or integration tokens
- `libs/cli/README.md` lists the two new commands and their test files

## Out of scope

- The notifications REST API and its CLI command.
- Fixing `builds when-running --timeout`, which is parsed and ignored. It stays
  documented as ineffective.
- Expo GraphQL/REST proxy as first-class CLI actions.
