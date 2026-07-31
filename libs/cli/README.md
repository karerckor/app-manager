# App Manager CLI

`appmctl` is a dependency-light command-line client for the App Manager API. It is distributed as one Bash 3.2-compatible executable, making it suitable for CI runners, minimal build images, and local automation without a language-specific SDK.

It provides two commands:

- `request` for any team API endpoint, including JSON bodies, request bodies read from files/stdin, multipart file uploads, and responses written to stdout or a file;
- `stream` for running a command locally while its combined stdout/stderr is uploaded to an existing manual build.

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
curl -fsSL \
  https://raw.githubusercontent.com/karerckor/app-manager/main/libs/cli/install.sh |
  bash

export PATH="${HOME}/.local/bin:${PATH}"
appmctl --version
```

The default destination is `$HOME/.local/bin/appmctl`. Select another directory by passing the variable to `bash` on the right-hand side of the pipe:

```bash
curl -fsSL \
  https://raw.githubusercontent.com/karerckor/app-manager/main/libs/cli/install.sh |
  APPMCTL_INSTALL_DIR="$PWD/.appmctl-bin" bash

export PATH="$PWD/.appmctl-bin:$PATH"
```

Do not put `APPMCTL_INSTALL_DIR=...` immediately before `curl`: that variable would be available to `curl`, but not to the `bash` process running the installer.

For a reproducible pipeline, pin both the installer and downloaded CLI to a full commit SHA instead of `main`:

```bash
APPMCTL_REF='FULL_GIT_COMMIT_SHA'

curl -fsSL \
  "https://raw.githubusercontent.com/karerckor/app-manager/${APPMCTL_REF}/libs/cli/install.sh" |
  APPMCTL_REF="$APPMCTL_REF" \
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
| `APPMCTL_SHA256` | unset | Optional expected SHA-256 of `bin/appmctl` |
| `APPMCTL_DOWNLOAD_URL` | raw GitHub URL | Override for a trusted mirror |

Before replacing an existing installation, the installer requires the Bash shebang, runs `bash -n`, and verifies `APPMCTL_SHA256` when supplied.

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
  APPMCTL_INSTALL_DIR="$APPMCTL_BIN_DIR" bash

export PATH="$APPMCTL_BIN_DIR:$PATH"

APP_MANAGER_API_KEY="$APP_MANAGER_API_KEY" \
APP_MANAGER_TEAM="$APP_MANAGER_TEAM" \
APP_MANAGER_INSTANCE_URL="${APP_MANAGER_INSTANCE_URL:-http://localhost:8000}" \
appmctl --json request GET apps
```

GitHub Actions persists the installed directory for later steps through `$GITHUB_PATH`:

```yaml
- name: Install App Manager CLI
  shell: bash
  run: |
    curl -fsSL \
      https://raw.githubusercontent.com/karerckor/app-manager/main/libs/cli/install.sh |
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
  - curl -fsSL https://raw.githubusercontent.com/karerckor/app-manager/main/libs/cli/install.sh | APPMCTL_INSTALL_DIR="$CI_PROJECT_DIR/.appmctl-bin" bash
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

# Inspect the configured app.
appmctl --json request GET "apps/$APP_MANAGER_APP"

# Stream a command to an existing manual build.
appmctl stream \
  --build-id "$APP_MANAGER_BUILD_ID" \
  --step Build \
  -- pnpm build
```

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
| `APP_MANAGER_APP` | `stream --app` | For `stream` | Default application slug |
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

### Manual build lifecycle with generic requests

There is no dedicated `create-build` subcommand because `request` already covers the complete API. A runner can create an idempotent manual build, stream its main command, and upload an artifact:

```bash
REQUEST_UUID='598a349f-b526-40ba-8e54-247772bce160'

APP_MANAGER_BUILD_ID=$(
  appmctl request POST "apps/$APP_MANAGER_APP/builds" \
    --data "{\"request_uuid\":\"$REQUEST_UUID\",\"profile_type\":\"production\",\"context\":{\"runner\":\"ci\"}}" |
    jq -er '.data.id'
)
export APP_MANAGER_BUILD_ID

appmctl stream \
  --build-id "$APP_MANAGER_BUILD_ID" \
  --manage-status \
  --step 'Android bundle' \
  -- ./gradlew bundleRelease

appmctl request POST \
  "apps/$APP_MANAGER_APP/builds/$APP_MANAGER_BUILD_ID/artifacts" \
  --form 'name=app-release.aab' \
  --form 'metadata={"channel":"internal"}' \
  --file file=app/build/outputs/bundle/release/app-release.aab \
  --json
```

Use a stable request UUID when a CI retry must resolve to the same build. `--manage-status` is appropriate only when that one `stream` invocation owns the full lifecycle; otherwise update state with explicit requests and use several stream steps without it.

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

- `appmctl` is an API transport plus a log streamer, not a high-level CRUD SDK. Resource-specific behavior remains documented in the [API v1 guide](../../apps/platform/docs/api-v1.md).
- `stream` works only with builds in `manual` reporting mode. Automatic provider builds reject manual status and log writes.
- Output is combined stdout/stderr; separate streams and terminal control sequences are not preserved.
- The API accepts at most 256 KiB per log chunk and 5 MB per complete build log. The CLI does not truncate output automatically when the server limit is reached.
- A response is treated as bytes unless `--json` is selected; content-type does not change output behavior.
- There is no built-in config file or credential store. Configuration is deliberately explicit through environment variables and flags.

## Troubleshooting

- **`APP_MANAGER_INSTANCE_URL must start with http:// or https://`** — pass the instance root such as `https://manager.example.com` or its exact `/api/v1` URL. Query strings and fragments are rejected.
- **`jq is required for this operation`** — install `jq`, or omit `--json`. Log streaming always needs `jq` to encode arbitrary command output safely.
- **`refusing to overwrite ...`** — select a new output path or add `--force`. Parent directories are not created automatically.
- **The local command runs but no log reaches App Manager** — check that the build is manual, the app/build IDs match the selected team, and the key has `logs:write`. The command may have already produced local output before an upload error is reported.
- **The command uses a pipe or redirect literally** — `stream` executes arguments directly and never calls `eval`. Wrap shell syntax explicitly, for example `-- sh -c 'pnpm test | tee results.txt'`.

## Development

Run the dependency-free smoke suite and syntax check:

```bash
bash -n libs/cli/bin/appmctl
bash libs/cli/tests/test_cli.sh
bash -n libs/cli/install.sh
bash libs/cli/tests/test_install.sh
```

The smoke suite uses a mock `curl` executable and covers URL construction, secret handling, JSON file/stdin bodies, multipart fields, output files, command exit propagation, status management, chunk sizing, installer syntax validation, and checksum rejection. Keep the script compatible with Bash 3.2 and avoid adding runtime dependencies without updating the requirements and CI examples.

The version is defined by `APPMCTL_VERSION` in `bin/appmctl`. There is no separate release channel yet; the installer downloads from `main` unless `APPMCTL_REF` is set, which is why production CI should always pin a full commit SHA.
