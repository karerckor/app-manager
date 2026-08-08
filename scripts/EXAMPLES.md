# Build Scripts — Presentation Demo

Drives real builds through the platform via the v1 API: lifecycle transitions,
streamed logs, and colored tags. Steps 1–5 are fully local; steps 6–7 show EAS
reconciliation and read from expo.dev (without starting a build there).

## Prerequisites

- The app is running (`http://localhost:8000`) with demo data seeded
  (`php artisan db:seed --class=DemoSeeder`)
- `jq` and `curl` on PATH
- The demo app has `ios`, `android`, and `web` identifiers (the seeder creates them)
- A queue worker for the EAS steps (`php artisan queue:work`) — reconciliation
  runs on the `integrations` queue
- For steps 6–7: an app linked to a live Expo project (see the last section)

## Setup (run once)

```bash
export APP_MANAGER_API_KEY=demo-api-key
export APP_MANAGER_TEAM=demo-users-team
export APP_MANAGER_APP=example-app
export APP_MANAGER_INSTANCE_URL=http://localhost:8000

# App linked to Expo — used by the EAS steps only
EAS_APP=app-5aa64369d1870543ad42cf3e
```

Keep the builds page open while presenting — each command shows up there:

- steps 1–5: `http://localhost:8000/accounts/demo-users-team/apps/example-app/builds`
- steps 6–7: `http://localhost:8000/accounts/demo-users-team/apps/app-5aa64369d1870543ad42cf3e/builds`

---

## Demo Flow (copy-paste one by one)

Steps 1–5 take ~12s each: the build goes `pending → running → <final>`, posting
platform-specific logs at every stage. Add `--delay 2` to speed it up.

### 1. Android — successful build (green tag)

```bash
scripts/build-manual.sh --platform android --status completed --version 1.0.0
```

### 2. iOS — successful build (blue tag)

```bash
scripts/build-manual.sh --platform ios --status completed --version 1.0.0
```

### 3. Web — successful build (purple tag)

```bash
scripts/build-manual.sh --platform web --status completed --version 1.0.0
```

### 4. Android — failed build

```bash
scripts/build-manual.sh --platform android --status failed --version 1.1.0
```

Logs end on a compilation error — open **Details** to show the captured output.

### 5. iOS — cancelled build

```bash
scripts/build-manual.sh --platform ios --status cancelled --version 1.1.0
```

### 6. EAS build — status reconciled from Expo (orange tag)

No build is triggered on expo.dev: the script points the platform at a build that
already ran there, and the platform pulls its real status and timestamps.

```bash
scripts/build-eas.sh --app $EAS_APP --platform android --profile production \
  --expo-build-id 935bbadf-046f-4aaa-90d2-ce40a502d04c --version 2.49.5
```

The build appears as `pending` with `expo` / `android` / version tags, then flips
to `completed` on its own — that's the reconciliation job, not the script.

### 7. EAS build that was cancelled on Expo (with `--wait`)

```bash
scripts/build-eas.sh --app $EAS_APP --platform ios --profile production \
  --expo-build-id ec9b3f72-3ce0-4386-ac82-a5fa7fdf9287 --version 2.49.5 --wait
```

`--wait` polls until the status settles — here `cancelled`, mirroring Expo.

Build IDs go stale as new builds push them out of the recent list. Get current
ones (the script prints this command too if an ID is rejected):

```bash
curl -s -H "Authorization: Bearer $APP_MANAGER_API_KEY" -H "Accept: application/json" \
  "$APP_MANAGER_INSTANCE_URL/api/v1/$APP_MANAGER_TEAM/apps/$EAS_APP/expo" \
  | jq -r '.data.remote.builds[] | "\(.id)  \(.platform)  \(.status)  \(.appVersion)"'
```

`FINISHED` → `completed`, `CANCELED` → `cancelled`, `ERRORED` → `failed`.

### What to show in the UI afterwards

- Colored tags on each build row; the **Tags** filter now lists every value used
- **Details** for build logs, and the status/duration/finished columns
- Filtering by status (`failed`) or by tag
- EAS rows carry an `EAS` badge and show Expo's own durations and dates — proof the
  status came from Expo, not from the script

Use **Reporting → EAS** to isolate builds whose status is synchronized from Expo.

---

## Tags reference

Tags are set at creation time via `appmctl builds create --tag NAME:#HEX`
(repeatable), so they are visible the moment the build appears.

| Tag     | Color  | Hex       | Applied when              |
|---------|--------|-----------|---------------------------|
| android | green  | `#22c55e` | `--platform android`      |
| ios     | blue   | `#3b82f6` | `--platform ios`          |
| web     | purple | `#a855f7` | `--platform web`          |
| manual  | slate  | `#64748b` | no `--platform` given     |
| expo    | orange | `#f97316` | `build-eas.sh` (always)   |
| version | amber  | `#f59e0b` | always (`--version` value)|

---

## What each script does

### `build-manual.sh`

```
1. Resolve identifier   → queries /identifiers API by --platform
2. Create tagged build  → POST /builds (pending, with colored tags)
3. Post logs            → POST /builds/{id}/logs ("Build queued...")
4. Start build          → PATCH /builds/{id} (running)
5. Post logs            → POST /builds/{id}/logs ("Compiling...")
6. Wait                 → sleep 10s (configurable via --delay)
7. Finish build         → PATCH /builds/{id} (completed/failed/cancelled)
8. Post logs            → POST /builds/{id}/logs ("Build done.")
```

`--status cancelled` short-circuits after step 3 and cancels the build instead.

### `build-eas.sh`

```
1. Preflight            → GET /apps/{app}/expo (app must be linked to Expo)
2. Resolve identifier   → queries /identifiers API by --platform
3. Create tagged build  → POST /builds (reporting_mode=eas, expo_build_id, tags)
4. (optional) Wait      → polls until terminal status (--wait)
```

It never starts a build on Expo. The API validates the ID against the linked
project's recent builds, then a background job copies the real status, timestamps,
and artifacts across. Status transitions are owned by that job — the API rejects
manual `--status` writes on EAS builds with 409.

---

## Flags

### `build-manual.sh`

| Flag | Default | Description |
|------|---------|-------------|
| `--status` | `completed` | Final status: `completed`, `failed`, `cancelled` |
| `--platform` | — | `ios`, `android`, or `web` (auto-selects identifier) |
| `--profile` | auto | `appstore` (ios), `production` (android/web) |
| `--version` | `1.0.0` | Version tag value |
| `--app` | `example-app` | App slug |
| `--delay` | `10` | Seconds between running and final status |
| `--context` | — | Extra JSON merged into build context |

### `build-eas.sh`

| Flag | Default | Description |
|------|---------|-------------|
| `--expo-build-id` | — | **Required.** Expo/EAS build ID |
| `--platform` | — | `ios` or `android` (auto-selects identifier) |
| `--profile` | auto | `appstore` (ios), `production` (android) |
| `--version` | `1.0.0` | Version tag value |
| `--app` | `example-app` | App slug |
| `--wait` | — | Poll until terminal status |
| `--context` | — | Extra JSON merged into build context |

---

## Using `build-eas.sh` with a different app

The EAS steps need an app linked to a **live** Expo project — the API validates
the build ID against that project's recent builds on expo.dev. `example-app` has
no usable link, which is why the steps above pass `--app $EAS_APP`.

To point them at another app:

1. Connect an Expo account — **Integrations → Expo**
2. Link a project to the app — app page → **Expo EAS** tab
3. Set `EAS_APP` to that app's slug and list its build IDs with the `curl` above

The script preflights the link and, if a build ID is rejected, prints the exact
command for listing valid ones.

---

## Troubleshooting

The scripts report the real cause and exit non-zero; they never continue on a
failed step.

| Message | Cause |
|---------|-------|
| `No <platform> identifier found` | Seed demo data, or pick a platform the app has |
| `Failed to list identifiers … (HTTP 401)` | `APP_MANAGER_API_KEY` unset or wrong |
| `Failed to list identifiers … (HTTP 404)` | `APP_MANAGER_TEAM` or `--app` slug doesn't match |
| `Could not reach <url>` | Instance not running at `APP_MANAGER_INSTANCE_URL` |
| `Could not determine the instance URL` | `APP_MANAGER_INSTANCE_URL` unset |
| `not linked to an Expo project` | `--app` has no Expo link — see the section above |
| `EAS build ID was not found in recent builds` | ID went stale; re-list current IDs |
