# Expo EAS integration

The Expo integration is optional at both the operator and team level. Enabling it does not create, import, or modify applications by itself.

## Configuration

```dotenv
INTEGRATIONS_ENABLED=true
EXPO_INTEGRATION_ENABLED=true
EXPO_API_URL=https://api.expo.dev
EXPO_GRAPHQL_URL=https://api.expo.dev/graphql
EXPO_WEB_URL=https://expo.dev
EXPO_REQUEST_TIMEOUT=30
```

Connect an Expo account from **Integrations → Expo EAS** with an Expo access token. For production automation, prefer an Expo robot user with the narrowest account role that supports the required actions. Personal access tokens are suitable for development and one-off use.

Tokens are stored in `integration_oauth_credentials` using Laravel's encrypted cast. They are never stored in the auditable `integrations.settings` column or returned to the browser.

## Public API

API v1 exposes the same Expo lifecycle to API-key clients:

- `integrations:read` lists synced projects;
- `integrations:write` connects, syncs and disconnects accounts and is required by custom
  operations that change Expo;
- `integrations:proxy` authorizes arbitrary REST and GraphQL forwarding and must be treated like
  direct access to the Expo account, including remote credentials and mutations;
- app import additionally requires `apps:write` and `credentials:write`;
- identifier provisioning and credential push require their corresponding resource write scopes.

The REST proxy is pinned to `EXPO_API_URL`, never accepts a caller-supplied origin, and replaces
the incoming authorization header with the encrypted Expo access token. All proxy calls require
`integrations:proxy`, regardless of the HTTP method or GraphQL operation text. See
[`api-v1.md`](api-v1.md#expodev) for the complete route and payload contract.

## Application lifecycle

The local `MobileApp` and remote Expo `App` are connected through `expo_projects`:

- one local application can be linked to at most one Expo project;
- one Expo project can be linked to at most one local application in a team;
- disconnecting Expo or unlinking an application does not delete the remote project;
- deleting a local application leaves the Expo project record available for a later import.

After connecting an account, App Manager synchronizes the account's project inventory. An existing local application then shows **Set up on Expo** with two explicit choices:

1. create a new Expo project using the local application slug and display name, then provision its
   local platform identifiers and supported EAS credentials;
2. link one of the unlinked projects discovered in the connected Expo account.

Unlinked Expo projects can also be imported from the integration page. App Manager generates a collision-free local slug during import.

Pulling metadata is opt-in per project link and runs only during an explicit **Pull from Expo** action. Expo's display name and description update the local application; the local slug is not changed.

### Automatic App Manager → Expo sync

Automatic sync is disabled by default and can be enabled independently for each linked application. Enabling it queues an initial push of the application's current state. Later local changes queue another push on the `integrations` queue for:

- the application display name;
- the application icon;
- Android package names and iOS bundle identifiers;
- supported EAS credential files and their attachment metadata.

The direction is always **App Manager → Expo**. Remote Expo changes are never pulled in the background; **Pull from Expo** is the only full Expo → App Manager operation. Explicit identifier sync remains a user-initiated button action.

Pushes use a per-project snapshot, so an unchanged retry does not create duplicate requests. The UI and API expose `queued`, `syncing`, `synced`, and `failed` states together with the latest error and successful push time. Turning the switch off stops future local changes from being queued and does not modify either side.

The application identifiers page compares local bundle IDs/package names with the app credential
identifiers currently returned by Expo. Each row is marked as `DB + Expo`, `DB only`, or
`Expo only`. Its **Sync** action is non-destructive and bidirectional: Expo-only identifiers are
created locally, and DB-only identifiers are provisioned in the linked Expo project.

## Credentials

The EAS application page reads credential metadata only. Private material is requested only by an explicit **Import as application** or **Pull from Expo** action and is never included in Inertia page props.

During a pull, App Manager downloads the credential files Expo exposes:

- Android keystores and their passwords;
- Firebase and Google Play service-account JSON;
- APNs and App Store Connect P8 keys;
- iOS distribution certificates and their passwords;
- Apple provisioning profiles.

Passwords and key identifiers use the encrypted credential payload. Files use the configured private Media Library disk. Repeating a pull is idempotent and replaces a local file only when its contents changed.

Local credentials can be pushed or re-synced explicitly from an app identifier. Supported mappings are:

| App Manager credential | Expo resource |
| --- | --- |
| Android keystore | Android keystore and Android app build credentials |
| Firebase HTTP v1 service account | Google service-account key for FCM V1 |
| Google Play service account | Google service-account key for submissions |
| APNs authentication key | Apple push key |
| App Store Connect API key | App Store Connect API key for submissions |
| iOS signing certificate | Apple distribution certificate |
| Provisioning profile | Apple provisioning profile |

When a compatible iOS certificate and provisioning profile have both been pushed for the same identifier and build target, App Manager creates the corresponding EAS iOS build-credentials association.

Creating a new Expo project performs the same push automatically for every supported local
credential that has a file. Unsupported client configuration files such as `google-services.json`
and `GoogleService-Info.plist` remain local. If Expo rejects an item, the project stays linked,
the UI reports the item-specific error, and the failed item is not marked as successfully synced.

Re-syncing a linked local credential creates and attaches a new Expo credential because EAS credential resources are immutable. The previous remote resource remains in the Expo account. Deleting a local credential does not revoke the corresponding Apple, Google, or Expo credential.

## Builds and submissions

The application EAS page shows recent builds and submissions directly from Expo GraphQL. Supported actions are:

- cancel an active build or submission;
- retry a retryable build or submission;
- submit a finished Android build to a selected Google Play track;
- submit a finished iOS build to App Store Connect.

### EAS Workflows as a CI/CD provider

A linked Expo project can also expose EAS Workflows through the standard App Manager CI/CD
pipeline UI. The binding is intentionally application-scoped: an EAS Workflow can only be
attached to the local application linked to that exact Expo project.

App Manager distinguishes the setup layers instead of treating every linked project as runnable:

1. **Expo linked, GitHub missing** — credentials, builds, and submissions remain available, but
   workflows cannot be dispatched from a Git ref. Connect a repository in the Expo project.
2. **GitHub linked, workflows missing** — add a YAML file under `.eas/workflows`.
3. **Workflow discovered, pipeline missing** — configure the workflow on **Integrations → CI/CD**.
4. **Ready** — the workflow appears in the application's **Run build** menu.

Dispatch uses Expo's `POST /v2/workflows/dispatch` endpoint. App Manager creates its normal
`BuildRecord` and `CiCdRun`, then polls `GET /v2/workflows/runs/{id}` and maps the EAS run to the
same lifecycle, cancellation, audit, webhook, and build-history behavior used by GitHub
and GitLab. The synchronized log is a safe workflow/job summary because the public Workflows API
does not expose the full VM log stream.

The workflow source remains in the connected GitHub repository. This avoids uploading arbitrary
source archives or duplicating EAS CLI's project-resolution logic. Builds started outside App
Manager still appear in the EAS activity list, but only runs dispatched through an App Manager
pipeline create local build records.

## Schema contract

The implementation targets [`eas-schema.json`](../eas-schema.json). When replacing that schema, verify these GraphQL surfaces before deploying:

- `meActor.accounts`, `account.byId.apps`, `app.createApp`;
- app credential metadata fields and credential mutations;
- `App.builds`, `BuildMutation` cancellation/retry;
- `App.submissions`, `SubmissionMutation` creation/cancellation/retry.
- `App.githubRepository`, `App.workflows`, workflow revisions and branch history;
- `WorkflowRunMutation.cancelWorkflowRun`.

Expo recommends access tokens for scripts and CI, and EAS CLI remains the supported source-preparation entry point:

- <https://docs.expo.dev/accounts/programmatic-access/>
- <https://docs.expo.dev/build/>
- <https://docs.expo.dev/app-signing/app-credentials/>
- <https://docs.expo.dev/eas/workflows/rest-api/>
