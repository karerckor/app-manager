# App Manager CLI

`appmctl` is a dependency-light command-line client for the App Manager API. It is distributed as one Bash 3.2-compatible executable, making it suitable for CI runners, minimal build images, and local automation without a language-specific SDK.

It provides domain-aware subcommands for all major API resources, plus generic access:

- **`builds`** — create, list, get, cancel, and patch builds
- **`logs`** — fetch and append build logs
- **`artifacts`** — list, download, upload, and delete build artifacts
- **`files`** — list, inspect, download, upload, update, and delete files attached to an app
- **`assets`** — list, preview, download, upload, and delete app icons and splash screens
- **`apps`** — list, get, create, update, and delete applications
- **`identifiers`** — manage per-platform bundle IDs, package names, and web domains
- **`credentials`** — manage app credentials and credential types
- **`integrations`** — list integrations, repositories, workflows, and refs
- **`eas`** — connect an Expo account, link projects, and sync identifiers and credentials
- **`pipelines`** — manage and trigger CI/CD pipelines
- **`webhooks`** — manage webhook endpoints, deliveries, and secrets
- **`sentry`** — manage routed Sentry-compatible DSNs
- **`telemetry`** — inspect grouped issues and traces for an application
- **`request`** — generic access to any team API endpoint
- **`stream`** — run a command locally while uploading stdout/stderr to a manual build

The CLI is currently pre-1.0. Pin the source commit in reproducible pipelines and expect the command surface to evolve with API v1.

## Requirements

| Tool | Required for |
| --- | --- |
| Bash 3.2 or later | All commands |
| `curl` | API requests and installation |
| `jq` | `--json` and `stream` |
| `mktemp`, `tee`, `dd`, and standard POSIX utilities | `stream` and secure temporary files |

The script is tested without Bash 4-only features so the macOS system Bash can run it. The wrapped command and any mobile build dependencies remain the caller's responsibility.

## Install

### curl + bash

The CI-friendly installer downloads only the `appmctl` executable; it does not clone or partially clone the repository:

```bash
APPMCTL_SHA256='PUBLISHED_SHA256_FOR_THIS_RELEASE'
curl -fsSL \
  https://raw.githubusercontent.com/karerckor/app-manager/main/libs/cli/install.sh |
  APPMCTL_SHA256="$APPMCTL_SHA256" bash

export PATH="${HOME}/.local/bin:${PATH}"
appmctl --version
```

The default destination is `$HOME/.local/bin/appmctl`. Select another directory by passing the variable to `bash` on the right-hand side of the pipe:

```bash
curl -fsSL \
  https://raw.githubusercontent.com/karerckor/app-manager/main/libs/cli/install.sh |
  APPMCTL_SHA256="$APPMCTL_SHA256" \
  APPMCTL_INSTALL_DIR="$PWD/.appmctl-bin" bash

export PATH="$PWD/.appmctl-bin:$PATH"
```

`APPMCTL_SHA256` is required. The installer aborts without it, because piping an
unverified remote script into `bash` is how a compromised upstream reaches a runner. Set
`APPMCTL_ALLOW_UNVERIFIED=1` only against a source you already control.


Do not put `APPMCTL_INSTALL_DIR=...` immediately before `curl`: that variable would be available to `curl`, but not to the `bash` process running the installer.

For a reproducible pipeline, pin both the installer and downloaded CLI to a full commit SHA instead of `main`:

```bash
APPMCTL_REF='FULL_GIT_COMMIT_SHA'

curl -fsSL \
  "https://raw.githubusercontent.com/karerckor/app-manager/${APPMCTL_REF}/libs/cli/install.sh" |
  APPMCTL_REF="$APPMCTL_REF" \
  APPMCTL_SHA256='PUBLISHED_SHA256_FOR_THIS_COMMIT' \
  APPMCTL_INSTALL_DIR="$PWD/.appmctl-bin" \
  bash

export PATH="$PWD/.appmctl-bin:$PATH"
```

The installer supports these variables:

| Variable | Default | Purpose |
| --- | --- | --- |
| `APPMCTL_INSTALL_DIR` | `$HOME/.local/bin` | Destination directory |
| `APPMCTL_REF` | `main` | Branch, tag, or preferably full commit SHA |
| `APPMCTL_REPOSITORY` | `karerckor/app-manager` | GitHub `owner/repository` |
| `APPMCTL_SHA256` | unset | Required expected SHA-256 of `bin/appmctl` |
| `APPMCTL_ALLOW_UNVERIFIED` | `0` | Emergency development-only opt-out from checksum verification |
| `APPMCTL_DOWNLOAD_URL` | raw GitHub URL | Override for a trusted mirror |

Before replacing an existing installation, the installer requires an expected SHA-256, checks the Bash shebang, runs `bash -n`, and verifies the downloaded executable. Unverified installation requires the explicit `APPMCTL_ALLOW_UNVERIFIED=1` development override.

### Existing checkout

Run directly from the repository:

```bash
libs/cli/bin/appmctl --help
```

Or install the script somewhere on `PATH`:

```bash
install -m 0755 libs/cli/bin/appmctl /usr/local/bin/appmctl
```

### CI/CD examples

Generic CI shell step:

```bash
APPMCTL_BIN_DIR="${CI_PROJECT_DIR:-$PWD}/.appmctl-bin"

curl -fsSL \
  https://raw.githubusercontent.com/karerckor/app-manager/main/libs/cli/install.sh |
  APPMCTL_SHA256="$APPMCTL_SHA256" \
  APPMCTL_INSTALL_DIR="$APPMCTL_BIN_DIR" bash

export PATH="$APPMCTL_BIN_DIR:$PATH"

APP_MANAGER_API_KEY="$APP_MANAGER_API_KEY" \
APP_MANAGER_TEAM="$APP_MANAGER_TEAM" \
APP_MANAGER_INSTANCE_URL="${APP_MANAGER_INSTANCE_URL:-http://localhost:8000}" \
appmctl apps list --json
```

GitHub Actions persists the installed directory for later steps through `$GITHUB_PATH`:

```yaml
- name: Install App Manager CLI
  shell: bash
  run: |
    curl -fsSL \
      https://raw.githubusercontent.com/karerckor/app-manager/main/libs/cli/install.sh |
      APPMCTL_SHA256="$APPMCTL_SHA256" \
      APPMCTL_INSTALL_DIR="$RUNNER_TEMP/appmctl-bin" bash
    echo "$RUNNER_TEMP/appmctl-bin" >> "$GITHUB_PATH"

- name: Build and stream logs
  env:
    APP_MANAGER_API_KEY: ${{ secrets.APP_MANAGER_API_KEY }}
    APP_MANAGER_TEAM: mobile-team
    APP_MANAGER_APP: customer-app
    APP_MANAGER_INSTANCE_URL: ${{ vars.APP_MANAGER_INSTANCE_URL }}
    APP_MANAGER_BUILD_ID: ${{ inputs.app_manager_build_id }}
  run: |
    appmctl stream \
      --build-id "$APP_MANAGER_BUILD_ID" \
      --step Build \
      -- pnpm build
```

GitLab CI can install it in `before_script`:

```yaml
before_script:
  - curl -fsSL https://raw.githubusercontent.com/karerckor/app-manager/main/libs/cli/install.sh | APPMCTL_SHA256="$APPMCTL_SHA256" APPMCTL_INSTALL_DIR="$CI_PROJECT_DIR/.appmctl-bin" bash
  - export PATH="$CI_PROJECT_DIR/.appmctl-bin:$PATH"

build:
  script:
    - appmctl stream --build-id "$APP_MANAGER_BUILD_ID" --step Build -- pnpm build
```

The runner image must provide Bash and curl. Install jq as well when using `--json` or `stream`.

## Quick start

After installing the CLI, configure one team and optional default app:

```bash
export APP_MANAGER_API_KEY='1|plain-text-api-key'
export APP_MANAGER_TEAM='mobile-team'
export APP_MANAGER_APP='customer-app'
export APP_MANAGER_INSTANCE_URL='https://manager.example.com'

# List team apps.
appmctl apps list

# Show build history for an app.
appmctl builds list --status running

# Stream a command to an existing manual build.
appmctl stream \
  --build-id "$APP_MANAGER_BUILD_ID" \
  --step Build \
  -- pnpm build
```

## Commands

All subcommands support `--help` for detailed usage and `--dry-run` to preview the request without sending it. Use `--json` to pretty-print JSON responses.

### `appmctl builds`

```bash
appmctl builds list [--app APP] [--status STATUS] [--page N]
appmctl builds get [--app APP] <build-id>
appmctl builds create [--app APP] --profile TYPE [--request-uuid UUID] [--context JSON] [--tag NAME[:#HEX]]
appmctl builds cancel [--app APP] <build-id>
appmctl builds patch [--app APP] <build-id> [--status STATUS] [--tag NAME[:#HEX]]
```

`--tag` is repeatable and takes either a bare name or `NAME:#RRGGBB` for a colored
badge. On `patch` it replaces the build's whole tag set, and at least one of
`--status` or `--tag` must be given. Tags may be set on any build; `--status`
transitions remain limited to manually reported builds.

```bash
appmctl builds create --profile appstore --tag ios:#3b82f6 --tag 1.0.0:#f59e0b
appmctl builds patch bld-abc123 --tag ios:#3b82f6
```

### `appmctl logs`

```bash
appmctl logs get [--app APP] --build-id ID
appmctl logs post [--app APP] --build-id ID --content TEXT
```

### `appmctl artifacts`

```bash
appmctl artifacts list [--app APP] --build-id ID
appmctl artifacts get [--app APP] --build-id ID <artifact-id> [-o FILE]
appmctl artifacts upload [--app APP] --build-id ID --name NAME [--metadata JSON] --file FILE
appmctl artifacts delete [--app APP] --build-id ID <artifact-id>
```

### `appmctl files`

```bash
appmctl files list [--app APP] [--per-page N]
appmctl files show [--app APP] --name NAME
appmctl files get [--app APP] --name NAME [-o FILE]
appmctl files upload [--app APP] --file FILE [--metadata JSON]
appmctl files update [--app APP] --name NAME --metadata JSON
appmctl files delete [--app APP] --name NAME
```

Files are addressed by name, not by id. `show` returns metadata, `get` downloads
the content. Names are percent-encoded for you, but must not contain slashes —
use `appmctl request` for names outside the ASCII range. `--metadata` takes a
JSON object, or the literal `null` to clear it.

```bash
appmctl files upload --file ./mapping.txt --metadata '{"channel":"beta"}'
appmctl files show --name mapping.txt
```

### `appmctl assets`

```bash
appmctl assets list [--app APP]
appmctl assets show [--app APP] --type icon|splashscreen [-o FILE]
appmctl assets download [--app APP] --type icon|splashscreen [--variant VARIANT] [-o FILE]
appmctl assets upload [--app APP] --type icon|splashscreen --file FILE
appmctl assets delete [--app APP] --type icon|splashscreen
```

Each app has at most one icon and one splash screen, so `upload` replaces the
existing asset. Besides the original file, the platform generates per-platform
variants (`ios-icon-40`, `android-icon-mdpi`, and so on); `list` prints the
download URL for the original plus every variant that has actually been
generated, and `download --variant` fetches one of them. `show` streams the
original inline for previewing.

```bash
appmctl assets list
appmctl assets download --type icon --variant ios-icon-40 -o icon-40.png
appmctl assets upload --type splashscreen --file ./splash.png
```

### `appmctl apps`

```bash
appmctl apps list
appmctl apps get <slug>
appmctl apps create --name NAME [--slug SLUG] [--description DESC] [--metadata JSON]
appmctl apps update <slug> [--name NAME] [--description DESC] [--metadata JSON]
appmctl apps delete <slug>
```

### `appmctl identifiers`

```bash
appmctl identifiers list   [--app APP] [--per-page N]
appmctl identifiers show   [--app APP] <identifier-id>
appmctl identifiers create [--app APP] --platform ios|android|web --identifier ID \
                           [--name NAME] [--version VERSION] [--version-code N]
appmctl identifiers update [--app APP] <identifier-id> [same flags]
appmctl identifiers delete [--app APP] <identifier-id>
```

Identifiers are the per-platform bundle IDs, package names, and web domains an app
ships under. `--version-code` applies to Android only, and the API rejects it for
other platforms. Uniqueness is enforced per app and platform.

```bash
appmctl identifiers create --platform android --identifier com.acme.customer \
  --name 'Customer App' --version 1.4.0 --version-code 42
appmctl identifiers list
```

### `appmctl credentials`

```bash
appmctl credentials list [--app APP]
appmctl credentials get [--app APP] <credential-id>
appmctl credentials create [--app APP] --type TYPE --profile PROFILE [--data-file FILE|-] [--file FILE]
appmctl credentials update [--app APP] <credential-id> --data-file FILE|-
appmctl credentials delete [--app APP] <credential-id>
appmctl credentials download [--app APP] <credential-id> [-o FILE]
appmctl credentials upload [--app APP] <credential-id> --file FILE
appmctl credentials types
```

### `appmctl integrations`

```bash
appmctl integrations list
appmctl integrations get <integration-id>
appmctl integrations repos --integration-id ID
appmctl integrations workflows --integration-id ID
appmctl integrations refs --integration-id ID
```

### `appmctl eas`

```bash
# Expo account, attached to an integration
appmctl eas connect --token TOKEN [--account NAME]
appmctl eas projects --integration-id ID
appmctl eas import   --integration-id ID --project-id ID

# Project link, attached to an app
appmctl eas status            [--app APP]
appmctl eas link              [--app APP] --integration-id ID --mode create|existing [--project-id ID]
appmctl eas auto-sync         [--app APP] --enabled true|false
appmctl eas identifiers       [--app APP]
appmctl eas sync-identifiers  [--app APP]
appmctl eas provision         [--app APP] --identifier-id ID [--credential-ids ID,ID]
appmctl eas attach-credential [--app APP] --identifier-id ID --credential-id ID

# Either scope, chosen by the flag you pass
appmctl eas sync       (--app APP | --integration-id ID)
appmctl eas disconnect (--app APP | --integration-id ID)
```

The Expo API splits across two scopes: the account hangs off an integration, and the
project link hangs off an app. `sync` and `disconnect` exist in both, so they take the
scope from the flag — `--integration-id` for the account, `--app` for the project link
— and refuse to run when given both.

The Expo GraphQL and REST proxies are not wrapped. Their payloads pass straight
through, so use `appmctl request` for them:

```bash
appmctl request POST integrations/int-002/expo/graphql \
  --data '{"query":"{ me { username } }"}' --json
```

### `appmctl pipelines`

```bash
appmctl pipelines list --integration-id ID
appmctl pipelines get --integration-id ID <pipeline-id>
appmctl pipelines create --integration-id ID --name NAME [--config JSON]
appmctl pipelines update --integration-id ID <pipeline-id> [--name NAME] [--config JSON]
appmctl pipelines delete --integration-id ID <pipeline-id>
appmctl pipelines run --integration-id ID <pipeline-id> [--inputs JSON] [--ref REF] [--request-uuid UUID]
appmctl pipelines inputs --integration-id ID <pipeline-id>
```

`run` dispatches the workflow and prints the build record the platform created, so the
build id for `builds wait` comes straight out of the trigger. The run is idempotent on
`--request-uuid`, which is generated when omitted — pass your own so a retried CI step
attaches to the same build instead of dispatching a second workflow.

### `appmctl webhooks`

```bash
appmctl webhooks list
appmctl webhooks get <webhook-id>
appmctl webhooks create --url URL [--events JSON] [--app SLUG]
appmctl webhooks update <webhook-id> [--url URL] [--events JSON]
appmctl webhooks delete <webhook-id>
appmctl webhooks test <webhook-id>
appmctl webhooks rotate <webhook-id>
appmctl webhooks deliveries <webhook-id>
appmctl webhooks redeliver --webhook-id ID <delivery-id>
```

### `appmctl sentry`

```bash
appmctl sentry list
appmctl sentry get <dsn-id>
appmctl sentry create --config sentry.json
appmctl sentry update <dsn-id> --config sentry.json
appmctl sentry delete <dsn-id>
```

The JSON config uses the same fields and validation as the Web UI: `name`,
`routing_method`, optional `event_source`, `app_key`, `identifier_key`, `routes`,
`forward_enabled`, optional `upstream_dsn`, and `enabled`.

### `appmctl telemetry`

```bash
appmctl --app customer-app telemetry issues [--environment ENV] [--identifier UUID] [--search TEXT]
appmctl --app customer-app telemetry issue <issue-id> [--event UUID]
appmctl --app customer-app telemetry traces [--environment ENV] [--identifier UUID] [--search TEXT]
appmctl --app customer-app telemetry trace <trace-id> [--event UUID]
```

Issue and trace detail responses contain a timeline plus the complete selected Sentry SDK event.

### `appmctl request` (legacy)

Generic access to any API endpoint. See [API requests](#api-requests) below.

### `appmctl stream` (legacy)

Stream command output to a manual build log. See [Stream command logs](#stream-command-logs) below.

## Configure

The API key and team slug are required:

```bash
export APP_MANAGER_API_KEY='1|plain-text-api-key'
export APP_MANAGER_TEAM='mobile-team'
```

For app-scoped commands, setting a default app is convenient but not globally required:

```bash
export APP_MANAGER_APP='customer-app'
```

`APP_MANAGER_INSTANCE_URL` is optional and accepts either the instance root or its `/api/v1` URL. The current development default is `http://localhost:8000`; a self-hosted deployment should override it:

```bash
export APP_MANAGER_INSTANCE_URL='https://manager.example.com'
```

The CLI never prints the API key in dry-run output and passes its authorization headers to `curl` through a mode-`0600` temporary file rather than a process argument. Prefer the environment variable over placing secrets in custom request headers or payloads.

### Configuration reference

| Environment variable | CLI override | Required | Purpose |
| --- | --- | --- | --- |
| `APP_MANAGER_API_KEY` | None | Yes | Bearer API key; deliberately not accepted as a command-line argument |
| `APP_MANAGER_TEAM` | `--team` | Yes | Team slug inserted into every API URL |
| `APP_MANAGER_INSTANCE_URL` | `--instance-url` | No | Instance root or `/api/v1` URL; defaults to `http://localhost:8000` |
| `APP_MANAGER_APP` | `--app` | For app-scoped commands | Default application slug |
| `APP_MANAGER_CURL_BIN` | None | No | Alternate `curl` executable, primarily for tests |
| `APP_MANAGER_JQ_BIN` | None | No | Alternate `jq` executable, primarily for tests |

Command-line values override environment values. Connection overrides (`--team` and `--instance-url`) must appear before `request` or `stream`. Request output options such as `--json` and `--output` may appear globally or after the request path; command-specific options otherwise follow the command arguments.

The team, app, and build ID are validated as simple path segments. The CLI rejects absolute request URLs and `.`/`..` path traversal. Query strings are allowed on relative paths.

### API key scopes

`request` is generic, so its required scopes depend on the endpoint. `stream` requires:

- `logs:write` in its default mode;
- `logs:write` and `builds:write` with `--manage-status`.

The App Manager API also checks the team permissions of the user who created the key. Grant only the resources used by the job.

## API requests

Paths are relative to `/api/v1/{APP_MANAGER_TEAM}/`:

```bash
# Print and validate pretty JSON.
appmctl --json request GET apps

# Inline JSON.
appmctl request POST apps \
  --data '{"name":"Customer App","description":"Mobile client"}' \
  --json

# Read a JSON body from a file and save the response.
appmctl request POST apps \
  --data-file create-app.json \
  --output created-app.json

# stdin is supported too.
generate-payload | appmctl request PATCH apps/customer-app --data-file - --json

# Upload a multipart file. --form and --file can be repeated.
appmctl request POST apps/customer-app/assets/icon \
  --file file=icon.png \
  --json

# Upload a build artifact with metadata.
appmctl request POST apps/customer-app/builds/BUILD_ID/artifacts \
  --form 'name=customer-app.aab' \
  --form 'metadata={"commit":"abc123"}' \
  --file file=build/customer-app.aab \
  --json

# Download a binary response. Existing files are protected unless --force is used.
appmctl request GET apps/customer-app/assets/icon --output icon.png

# Inspect a request without sending it. Authorization is redacted.
appmctl --dry-run request POST apps --data-file create-app.json
```

`--json` is explicit because downloads may be binary. Without `--json`, the response bytes are passed through unchanged. API errors are printed to stderr and produce a non-zero exit status.

### Request option reference

```text
appmctl [global options] request METHOD PATH [request options]
```

Supported methods are `GET`, `POST`, `PUT`, `PATCH`, `DELETE`, and `HEAD`.

| Option | Behavior |
| --- | --- |
| `--data JSON` | Sends an inline request body; defaults its content type to JSON |
| `--data-file FILE` | Sends a file as the request body; use `-` to read stdin |
| `--form FIELD=VALUE` | Adds a multipart text field; repeatable and preserves `=` in values |
| `--file FIELD=FILE` | Adds a multipart file field; repeatable |
| `-H, --header HEADER` | Adds a header; repeatable, but cannot replace `Authorization` |
| `--content-type TYPE` | Overrides the default content type for data bodies |
| `--json` | Validates and pretty-prints the response with `jq` |
| `-o, --output FILE` | Writes response bytes to a file instead of stdout |
| `--force` | Allows an existing output file to be replaced |
| `--dry-run` | Prints the sanitized request plan without sending it |

Only one body kind can be selected, but multipart text and file fields can be mixed and repeated. The parent directory of `--output` must already exist. The CLI protects existing output files unless `--force` is explicit.

### Manual build lifecycle with subcommands

A runner can create an idempotent manual build, stream its main command, and upload an artifact using the dedicated subcommands:

```bash
APP_MANAGER_BUILD_ID=$(
  appmctl --json builds create \
    --app "$APP_MANAGER_APP" \
    --profile production \
    --context '{"runner":"ci"}' |
    jq -er '.data.id'
)
export APP_MANAGER_BUILD_ID

appmctl stream \
  --build-id "$APP_MANAGER_BUILD_ID" \
  --manage-status \
  --step 'Android bundle' \
  -- ./gradlew bundleRelease

appmctl artifacts upload \
  --build-id "$APP_MANAGER_BUILD_ID" \
  --name app-release.aab \
  --metadata '{"channel":"internal"}' \
  --file app/build/outputs/bundle/release/app-release.aab
```

The same workflow works with the generic `request` command for endpoints not yet covered by subcommands. Use a stable `--request-uuid` when a CI retry must resolve to the same build.

## Stream command logs

`stream` targets an existing build in `manual` reporting mode:

```bash
appmctl stream \
  --app customer-app \
  --build-id "$APP_MANAGER_BUILD_ID" \
  --step 'JavaScript build' \
  -- pnpm build
```

The equivalent `--command` form is useful in compact CI configuration:

```bash
appmctl stream \
  --app customer-app \
  --build-id "$APP_MANAGER_BUILD_ID" \
  --step 'Android bundle' \
  --command ./gradlew bundleRelease
```

The command is invoked directly, without `eval`. To use shell syntax, invoke a shell explicitly:

```bash
appmctl stream --build-id "$APP_MANAGER_BUILD_ID" \
  --step 'Tests' \
  -- sh -c 'pnpm test | tee test-output.txt'
```

During execution, combined stdout/stderr remains visible in the local terminal and is uploaded in 64 KiB chunks. `--chunk-bytes` may select any size up to the API limit of 256 KiB. The API currently limits the complete build log to 5 MB.

`--step` writes separators before and after that command, which gives one build log readable stages without changing the current plain-text API schema. Multiple invocations can target the same build:

```bash
appmctl stream --build-id "$APP_MANAGER_BUILD_ID" --step Install -- pnpm install
appmctl stream --build-id "$APP_MANAGER_BUILD_ID" --step Test -- pnpm test
appmctl stream --build-id "$APP_MANAGER_BUILD_ID" --step Build -- pnpm build
```

By default, `stream` only requires `logs:write` and does not alter build state. Add `--manage-status` when one invocation owns the whole build lifecycle; it sets the build to `running`, then to `completed` or `failed`. That mode also requires `builds:write`.

The CLI returns the wrapped command's exit status. When the command succeeds but an upload or status update fails, it returns `1`. `--dry-run` neither executes the command nor sends a request:

```bash
appmctl --dry-run stream --build-id BUILD_ID --step Build -- pnpm build
```

### Stream option reference

```text
appmctl [global options] stream --build-id ID [options] -- COMMAND [ARG ...]
```

| Option | Behavior |
| --- | --- |
| `--app APP` | App slug; defaults to `APP_MANAGER_APP` |
| `--build-id ID` | Existing manual build UUID; required |
| `--step NAME` | Uploads readable start and finish markers |
| `--chunk-bytes BYTES` | Upload size from 1 to 262,144 bytes; defaults to 65,536 |
| `--manage-status` | Sets `running`, then `completed` or `failed` |
| `--command` or `--` | Ends CLI option parsing and starts the wrapped command |
| `--dry-run` | Prints the command and request target without executing either |

The wrapped command's stdout and stderr are intentionally combined so their ordering remains useful in one build log. The uploader checks for new output once per second. A step finish marker is sent only when log upload has remained healthy.

## Exit status and errors

- Successful `request` and `stream` operations exit `0`.
- `request` exits non-zero for local validation, `curl` failures, non-2xx HTTP responses, invalid JSON requested through `--json`, or output errors.
- `stream` returns the wrapped command's non-zero exit status whenever the command fails.
- If the command succeeds but log upload, capture, or a managed status update fails, `stream` exits `1`.
- `--dry-run` validates configuration and arguments but neither sends a request nor executes a wrapped command.

For unsuccessful HTTP responses, the response body is printed to stderr when available, followed by the status. Typical meanings are:

| Status | Common cause |
| --- | --- |
| `401` | Invalid or revoked API key |
| `403` | Missing API scope or team permission |
| `404` | Wrong team/app/build path or cross-team resource |
| `409` | Manual writes attempted on an automatic build, or a conflicting terminal state |
| `422` | API validation failure |

The API client does not retry normal requests. Use an outer CI retry only when the operation is safe or explicitly idempotent; build creation is idempotent when the same `request_uuid` is reused.

## Security model

- The API key is accepted only through `APP_MANAGER_API_KEY`, not a CLI argument.
- Authorization headers and inline request bodies are written to mode-`0600` files inside a mode-`0700` temporary directory before being passed to `curl`.
- Dry-run output redacts authorization, cookie, proxy authorization, and API-key headers and never prints multipart text values.
- Temporary state is removed on normal exit. Build runners should still be treated as sensitive because wrapped commands and downloaded response files may contain secrets.
- Use HTTPS outside local development and use a narrowly scoped API key per pipeline or trust boundary.
- Pin installer and executable revisions. For stronger supply-chain verification, also provide `APPMCTL_SHA256` to the installer.

Avoid secrets in URL query strings, output filenames, arbitrary headers not recognized as sensitive, or command arguments that the operating system may expose. When downloading credentials, choose a protected workspace and delete them after the build if the runner is persistent.

## Current boundaries

- `stream` works only with builds in `manual` reporting mode. Automatic provider builds reject manual status and log writes.
- Output is combined stdout/stderr; separate streams and terminal control sequences are not preserved.
- The API accepts at most 256 KiB per log chunk and 5 MB per complete build log. The CLI does not truncate output automatically when the server limit is reached.
- A response is treated as bytes unless `--json` is selected; content-type does not change output behavior.
- There is no built-in config file or credential store. Configuration is deliberately explicit through environment variables and flags.
- The generic `request` command remains available for API endpoints not yet covered by dedicated subcommands.

## Troubleshooting

- **`APP_MANAGER_INSTANCE_URL must start with http:// or https://`** — pass the instance root such as `https://manager.example.com` or its exact `/api/v1` URL. Query strings and fragments are rejected.
- **`jq is required for this operation`** — install `jq`, or omit `--json`. Log streaming always needs `jq` to encode arbitrary command output safely.
- **`refusing to overwrite ...`** — select a new output path or add `--force`. Parent directories are not created automatically.
- **The local command runs but no log reaches App Manager** — check that the build is manual, the app/build IDs match the selected team, and the key has `logs:write`. The command may have already produced local output before an upload error is reported.
- **The command uses a pipe or redirect literally** — `stream` executes arguments directly and never calls `eval`. Wrap shell syntax explicitly, for example `-- sh -c 'pnpm test | tee results.txt'`.

## Development

The source is organized as Bash modules in `libs/cli/lib/` with a build step that produces a single distributable file:

```bash
# Build the single-file distributable from modules.
bash libs/cli/build.sh > libs/cli/bin/appmctl

# Run the full test suite.
for t in libs/cli/tests/test_*.sh; do bash "$t"; done
```

Individual test files:

```bash
bash libs/cli/tests/test_cli.sh           # Core request/stream tests
bash libs/cli/tests/test_install.sh       # Installer verification
bash libs/cli/tests/test_builds.sh        # Builds subcommands
bash libs/cli/tests/test_apps.sh          # Apps subcommands
bash libs/cli/tests/test_credentials.sh   # Credentials subcommands
bash libs/cli/tests/test_pipelines.sh     # Integrations + pipelines subcommands
bash libs/cli/tests/test_webhooks.sh      # Webhooks subcommands
bash libs/cli/tests/test_logs_artifacts.sh # Logs + artifacts subcommands
bash libs/cli/tests/test_files.sh         # Files subcommands
bash libs/cli/tests/test_assets.sh        # Assets (icon + splash screen) subcommands
bash libs/cli/tests/test_identifiers.sh   # Identifiers subcommands
bash libs/cli/tests/test_eas.sh           # Expo/EAS subcommands
```

The test suite uses a mock `curl` executable and covers URL construction, secret handling, all subcommand actions, dry-run output, formatted output, and backward compatibility with `request`/`stream`. Keep the script compatible with Bash 3.2 and avoid adding runtime dependencies without updating the requirements and CI examples.

The version is defined by `APPMCTL_VERSION` in `bin/appmctl`. There is no separate release channel yet; the installer downloads from `main` unless `APPMCTL_REF` is set, which is why production CI should always pin a full commit SHA.
