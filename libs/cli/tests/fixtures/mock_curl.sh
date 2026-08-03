#!/usr/bin/env bash

# Enhanced mock curl for testing. Routes responses based on URL patterns.

output=""
method="GET"
url=""
body=""

printf '%s\n' "$*" >>"$APPM_TEST_CURL_ARG_LOG"

while [ "$#" -gt 0 ]; do
  case "$1" in
    --output) output="$2"; shift 2 ;;
    --request) method="$2"; shift 2 ;;
    --write-out|--header) shift 2 ;;
    --form|--form-string)
      body="$body multipart:$2"
      case "$2" in
        *=\<*) body="$body multipart-value:$(cat "${2#*=<}")" ;;
      esac
      shift 2
      ;;
    --data-binary)
      case "$2" in
        @-)
          body=$(cat)
          ;;
        @*)
          body=$(cat "${2#@}")
          ;;
        *) body="$2" ;;
      esac
      shift 2
      ;;
    --silent|--show-error) shift ;;
    http://*|https://*) url="$1"; shift ;;
    *) shift ;;
  esac
done

body=${body//$'\n'/\\n}
printf '%s\t%s\t%s\n' "$method" "$url" "$body" >>"$APPM_TEST_CURL_LOG"

# Strip query string and trailing slash for matching.
url_path="${url%%\?*}"
while [ "${url_path%/}" != "$url_path" ]; do
  url_path="${url_path%/}"
done

# Extract the API-relative path (after /api/v1/{team}/).
rel_path="${url_path#*/api/v1/}"
rel_path="${rel_path#*/}"
# rel_path is now e.g. "apps/customer-app/builds" or "apps" or "webhooks/123/deliveries"

# Match from most specific to least specific.
case "$rel_path" in
  # --- builds (specific patterns first, since * matches slashes) ---
  apps/*/builds/*/cancel)
    printf '{"data":{"ok":true}}' >"$output"
    ;;
  apps/*/builds/*/logs)
    case "$method" in
      GET) printf 'Build log content here' >"$output" ;;
      POST) printf '{"data":{"ok":true}}' >"$output" ;;
    esac
    ;;
  apps/*/builds/*/artifacts/*/file)
    printf 'fake-file-content' >"$output"
    ;;
  apps/*/builds/*/artifacts/*)
    case "$method" in
      DELETE) printf '{"data":{"ok":true}}' >"$output" ;;
      *) printf '{"data":{"id":"art-001","name":"app-release.aab","created_at":"2025-01-15"}}' >"$output" ;;
    esac
    ;;
  apps/*/builds/*/artifacts)
    printf '{"data":[{"id":"art-001","name":"app-release.aab","created_at":"2025-01-15"},{"id":"art-002","name":"app-debug.apk","created_at":"2025-01-15"}]}' >"$output"
    ;;
  apps/*/builds/*)
    case "$method" in
      GET) printf '{"data":{"id":"bld-001","app_id":"app-001","profile_type":"production","status":"completed","reporting_mode":"manual","tags":[["android","#3ddc84"],["1.4.0","#f59e0b"]],"request_uuid":"abc-123","created_at":"2025-01-15","started_at":"2025-01-15T10:00:00Z","finished_at":"2025-01-15T10:30:00Z"}}' >"$output" ;;
      PATCH) printf '{"data":{"id":"bld-001","status":"completed"}}' >"$output" ;;
    esac
    ;;
  apps/*/builds)
    case "$method" in
      GET) printf '{"data":[{"id":"bld-001","profile_type":"production","status":"completed","created_at":"2025-01-15"},{"id":"bld-002","profile_type":"staging","status":"running","created_at":"2025-01-16"}]}' >"$output" ;;
      POST) printf '{"data":{"id":"bld-003","status":"pending","profile_type":"production","request_uuid":"abc-123","created_at":"2025-07-31"}}' >"$output" ;;
    esac
    ;;
  # --- credentials ---
  apps/*/credentials/*/file)
    case "$method" in
      GET) printf 'fake-credential-content' >"$output" ;;
      POST) printf '{"data":{"ok":true}}' >"$output" ;;
    esac
    ;;
  apps/*/credentials/*)
    case "$method" in
      GET) printf '{"data":{"id":"cred-001","type":"android_keystore","name":"Production Keystore","tags":[],"expires_at":null,"profiles":[{"id":"prof-001","type":"production","name":"Production","app_identifier":{"id":"ident-002","platform":"android","identifier":"com.example.customer"}}],"file":{"name":"production.keystore","mime_type":"application/x-pkcs12","size":2543},"created_at":"2025-01-15","updated_at":"2025-06-01"}}' >"$output" ;;
      PATCH) printf '{"data":{"id":"cred-001","type":"android_keystore","name":"Production Keystore"}}' >"$output" ;;
      DELETE) printf '{"data":{"ok":true}}' >"$output" ;;
    esac
    ;;
  apps/*/credentials)
    case "$method" in
      POST) printf '{"data":{"id":"cred-001","type":"android_keystore","name":"Production Keystore","expires_at":null,"created_at":"2025-01-15"}}' >"$output" ;;
      *) printf '{"data":[{"id":"cred-001","type":"android_keystore","name":"Production Keystore","expires_at":null,"created_at":"2025-01-15"},{"id":"cred-002","type":"ios_certificate","name":"App Store Certificate","expires_at":"2027-03-01","created_at":"2025-01-16"}]}' >"$output" ;;
    esac
    ;;
  # --- assets (icon/splashscreen) ---
  apps/*/assets/*/files/*)
    printf 'fake-asset-content' >"$output"
    ;;
  apps/*/assets/*)
    case "$method" in
      POST) printf '{"data":{"slug":"customer-app","name":"Customer App","created_at":"2025-01-15"}}' >"$output" ;;
      DELETE) printf '{"data":{"ok":true}}' >"$output" ;;
      *) printf 'fake-asset-preview' >"$output" ;;
    esac
    ;;
  apps/*/assets)
    printf '{"data":{"icon.original":"https://manager.example/api/v1/mobile-team/apps/customer-app/assets/icon/files/original","icon.ios-icon-40":"https://manager.example/api/v1/mobile-team/apps/customer-app/assets/icon/files/ios-icon-40","splashscreen.original":"https://manager.example/api/v1/mobile-team/apps/customer-app/assets/splashscreen/files/original"}}' >"$output"
    ;;
  # --- files (app media library) ---
  apps/*/files/*/content)
    printf 'fake-file-content' >"$output"
    ;;
  apps/*/files/*)
    case "$method" in
      DELETE) printf '{"data":{"ok":true}}' >"$output" ;;
      *) printf '{"data":{"id":"file-001","name":"release-notes.pdf","extension":"pdf","mime_type":"application/pdf","size":2048,"metadata":{"channel":"beta"},"created_at":"2026-07-31","updated_at":"2026-08-01"}}' >"$output" ;;
    esac
    ;;
  apps/*/files)
    case "$method" in
      POST) printf '{"data":{"id":"file-001","name":"release-notes.pdf","mime_type":"application/pdf","size":2048,"created_at":"2026-07-31"}}' >"$output" ;;
      *) printf '{"data":[{"id":"file-001","name":"release-notes.pdf","mime_type":"application/pdf","size":2048,"created_at":"2026-07-31"},{"id":"file-002","name":"mapping.txt","mime_type":"text/plain","size":512,"created_at":"2026-07-30"}]}' >"$output" ;;
    esac
    ;;
  # --- app expo links ---
  apps/*/expo/identifiers/*/provision)
    printf '{"data":{"identifier":"com.example.customer","platform":"ios","provisioned":["ios_distribution_certificate","ios_provisioning_profile"],"skipped":[]}}' >"$output"
    ;;
  apps/*/expo/identifiers/*/credentials/*)
    printf '{"data":{"identifier":"com.example.customer","platform":"ios","credential_id":"cred-001","pushed":true}}' >"$output"
    ;;
  apps/*/expo/identifiers/sync)
    printf '{"data":{"created":1,"updated":2,"unchanged":0}}' >"$output"
    ;;
  apps/*/expo/identifiers)
    printf '{"data":[{"local_id":"ident-001","platform":"ios","identifier":"com.example.customer","name":"Customer App","sync_status":"synced"},{"local_id":"ident-002","platform":"android","identifier":"com.example.customer","name":"Customer App","sync_status":"app_manager_only"}]}' >"$output"
    ;;
  apps/*/expo/automatic-sync)
    printf '{"data":{"automatic_sync":{"enabled":true,"status":"idle","error":null,"last_pushed_at":"2026-08-02T18:20:00+00:00"}}}' >"$output"
    ;;
  apps/*/expo/sync)
    printf '{"data":{"project":{"slug":"customer-app","full_name":"@acme/customer-app","exists_on_expo":true,"last_synced_at":"2026-08-03T10:20:00+00:00"}}}' >"$output"
    ;;
  apps/*/expo)
    case "$method" in
      DELETE) printf '{"data":{"ok":true}}' >"$output" ;;
      POST) printf '{"data":{"id":"expo-proj-001","external_id":"11111111-2222-3333-4444-555555555555","slug":"customer-app","full_name":"@acme/customer-app","exists_on_expo":true}}' >"$output" ;;
      *) printf '{"data":{"project":{"id":"expo-proj-001","external_id":"11111111-2222-3333-4444-555555555555","slug":"customer-app","name":"Customer App","full_name":"@acme/customer-app","account":{"id":"acct-001","name":"acme"},"exists_on_expo":true,"last_synced_at":"2026-08-02T18:20:00+00:00","automatic_sync":{"enabled":true,"status":"idle","error":null,"last_pushed_at":"2026-08-02T18:20:00+00:00"}},"remote":{"builds":[],"submissions":[]}}}' >"$output" ;;
    esac
    ;;
  # --- identifiers ---
  apps/*/identifiers/*)
    case "$method" in
      DELETE) printf '{"data":{"ok":true}}' >"$output" ;;
      *) printf '{"data":{"id":"ident-001","platform":"ios","identifier":"com.example.customer","name":"Customer App","version":"1.4.0","version_code":null,"created_at":"2026-07-20T09:00:00+00:00","updated_at":"2026-08-01T11:30:00+00:00"}}' >"$output" ;;
    esac
    ;;
  apps/*/identifiers)
    case "$method" in
      POST) printf '{"data":{"id":"ident-003","platform":"android","identifier":"com.example.customer","name":"Customer App","version":"1.4.0","version_code":42,"created_at":"2026-08-03T10:00:00+00:00","updated_at":"2026-08-03T10:00:00+00:00"}}' >"$output" ;;
      *) printf '{"data":[{"id":"ident-001","platform":"ios","identifier":"com.example.customer","name":"Customer App","version":"1.4.0","version_code":null,"created_at":"2026-07-20T09:00:00+00:00"},{"id":"ident-002","platform":"android","identifier":"com.example.customer","name":"Customer App","version":"1.4.0","version_code":42,"created_at":"2026-07-20T09:05:00+00:00"}]}' >"$output" ;;
    esac
    ;;
  # --- single app ---
  apps/*)
    case "$method" in
      GET) printf '{"data":{"slug":"customer-app","name":"Customer App","description":"Mobile client","created_at":"2025-01-15","updated_at":"2025-06-01"}}' >"$output" ;;
      PATCH) printf '{"data":{"slug":"customer-app","name":"Updated App"}}' >"$output" ;;
      DELETE) printf '{"data":{"ok":true}}' >"$output" ;;
    esac
    ;;
  # --- apps list ---
  apps)
    if [ "$method" = "GET" ]; then
      printf '{"data":[{"slug":"customer-app","name":"Customer App","created_at":"2025-01-15"},{"slug":"internal-app","name":"Internal App","created_at":"2025-01-10"}]}' >"$output"
    else
      printf '{"data":{"slug":"new-app","name":"New App","created_at":"2025-07-31"}}' >"$output"
    fi
    ;;
  # --- webhooks ---
  webhooks/*/deliveries/*/redeliver)
    printf '{"data":{"ok":true}}' >"$output"
    ;;
  webhooks/*/deliveries)
    printf '{"data":[{"id":"del-001","event_type":"build.completed","status":"delivered","attempt_count":1,"response_status":200,"duration_ms":142,"delivered_at":"2025-01-15T10:00:00Z"},{"id":"del-002","event_type":"build.failed","status":"failed","attempt_count":3,"response_status":500,"duration_ms":98,"delivered_at":null}]}' >"$output"
    ;;
  webhooks/*/test|webhooks/*/rotate-secret)
    printf '{"data":{"ok":true}}' >"$output"
    ;;
  webhooks/*)
    case "$method" in
      GET) printf '{"data":{"id":"wh-001","name":"Release notifier","url":"https://example.com/hook","secret_hint":"whse\u2026000","subscribed_events":["build.completed","build.failed"],"filters":{},"enabled":true,"consecutive_failures":0,"last_delivered_at":"2025-06-01T09:00:00Z","created_at":"2025-01-15","updated_at":"2025-06-01"}}' >"$output" ;;
      PATCH) printf '{"data":{"id":"wh-001","url":"https://new-url.com/hook"}}' >"$output" ;;
      DELETE) printf '{"data":{"ok":true}}' >"$output" ;;
    esac
    ;;
  webhooks)
    case "$method" in
      GET) printf '{"data":[{"id":"wh-001","name":"Release notifier","url":"https://example.com/hook","subscribed_events":["build.completed","build.failed"],"enabled":true,"created_at":"2025-01-15"}]}' >"$output" ;;
      POST) printf '{"data":{"id":"wh-002","name":"Release notifier","url":"https://example.com/hook","subscribed_events":["build.completed"],"enabled":true,"created_at":"2025-07-31"}}' >"$output" ;;
    esac
    ;;
  # --- expo on integrations (must precede the generic integrations/* patterns) ---
  integrations/*/expo/projects/*/import)
    printf '{"data":{"id":"expo-proj-001","external_id":"11111111-2222-3333-4444-555555555555","slug":"customer-app","name":"Customer App","full_name":"@acme/customer-app","account":{"id":"acct-001","name":"acme"},"exists_on_expo":true,"mobile_app":{"id":"app-001","slug":"customer-app","name":"Customer App"}}}' >"$output"
    ;;
  integrations/*/expo/projects)
    printf '{"data":[{"id":"expo-proj-001","external_id":"11111111-2222-3333-4444-555555555555","slug":"customer-app","name":"Customer App","full_name":"@acme/customer-app","account":{"id":"acct-001","name":"acme"},"exists_on_expo":true,"last_synced_at":"2026-08-02T18:20:00+00:00"},{"id":"expo-proj-002","external_id":"66666666-7777-8888-9999-000000000000","slug":"internal-tools","name":"Internal Tools","full_name":"@acme/internal-tools","account":{"id":"acct-001","name":"acme"},"exists_on_expo":false,"last_synced_at":null}]}' >"$output"
    ;;
  integrations/*/expo/graphql|integrations/*/expo/proxy/*)
    printf '{"data":{"ok":true}}' >"$output"
    ;;
  integrations/*/expo/sync)
    printf '{"data":{"id":"int-002","provider":"expo","name":"acme","status":"active","projects_synced":2}}' >"$output"
    ;;
  integrations/*/expo)
    printf '{"data":{"ok":true}}' >"$output"
    ;;
  integrations/expo)
    printf '{"data":{"id":"int-002","provider":"expo","name":"acme","status":"active","created_at":"2026-08-03T10:15:00+00:00"}}' >"$output"
    ;;
  # --- integrations ---
  integrations/*/pipelines/*/runs)
    printf '{"data":{"id":"bld-004","status":"pending","reporting_mode":"automatic","dispatch_status":"dispatched","external_run_url":"https://github.com/acme/customer-app/actions/runs/1234567","request_uuid":"33333333-4444-4555-8666-777777777777","created_at":"2026-08-03"}}' >"$output"
    ;;
  integrations/*/pipelines/*/inputs)
    printf '{"data":[{"name":"branch","type":"string","required":true}]}' >"$output"
    ;;
  integrations/*/pipelines/*)
    case "$method" in
      GET) printf '{"data":{"id":"pipe-001","name":"Build Pipeline","enabled":true,"profile_type":"production","reporting_mode":"automatic","repository_full_name":"acme/customer-app","workflow_id":"98765432","workflow_name":"Release","workflow_path":".github/workflows/release.yml","default_ref":"main","input_defaults":{},"created_at":"2025-01-15","updated_at":"2025-06-01"}}' >"$output" ;;
      PATCH) printf '{"data":{"id":"pipe-001","name":"Updated Pipeline"}}' >"$output" ;;
      DELETE) printf '{"data":{"ok":true}}' >"$output" ;;
    esac
    ;;
  integrations/*/pipelines)
    case "$method" in
      GET) printf '{"data":[{"id":"pipe-001","name":"Build Pipeline","enabled":true,"repository_full_name":"acme/customer-app","workflow_name":"Release","created_at":"2025-01-15"}]}' >"$output" ;;
      POST) printf '{"data":{"id":"pipe-002","name":"New Pipeline","enabled":true,"repository_full_name":"acme/customer-app","workflow_name":"Release","created_at":"2025-07-31"}}' >"$output" ;;
    esac
    ;;
  integrations/*/repositories)
    printf '{"data":[{"id":"repo-001","name":"org/app-repo","default_branch":"main"}]}' >"$output"
    ;;
  integrations/*/workflows)
    printf '{"data":[{"id":"wf-001","name":"Build","path":".github/workflows/build.yml"}]}' >"$output"
    ;;
  integrations/*/refs)
    printf '{"data":[{"ref":"refs/heads/main","sha":"abc123"}]}' >"$output"
    ;;
  integrations/*)
    printf '{"data":{"id":"int-001","provider":"github","name":"GitHub","created_at":"2025-01-15","updated_at":"2025-06-01"}}' >"$output"
    ;;
  integrations)
    printf '{"data":[{"id":"int-001","provider":"github","name":"GitHub","created_at":"2025-01-15"}]}' >"$output"
    ;;
  # --- credential types ---
  credential-types)
    printf '{"data":[{"slug":"android_keystore","name":"Android Keystore","platform":"android"},{"slug":"ios_certificate","name":"iOS Certificate","platform":"ios"}]}' >"$output"
    ;;
  # --- api keys ---
  api-keys/*)
    printf '{"data":{"ok":true}}' >"$output"
    ;;
  api-keys)
    printf '{"data":[]}' >"$output"
    ;;
  # --- fallback ---
  *)
    printf '{"data":{"ok":true}}' >"$output"
    ;;
esac

printf '200'
