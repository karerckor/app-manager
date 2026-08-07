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
  # --- blueprints and conditional hooks ---
  blueprints/*/hooks/*)
    case "$method" in
      GET) printf '{"data":{"id":"hook-001","blueprint_id":"blueprint-001","name":"Flag incomplete apps","condition":"blueprint_mismatch","condition_config":null,"actions":[{"type":"remove_tag","tags":[["ready"]]},{"type":"add_tag","tags":[["blueprint-incomplete","#f43f5e","heartbeat"]]}],"enabled":true,"created_at":"2026-08-06T10:00:00Z","updated_at":"2026-08-06T10:00:00Z"}}' >"$output" ;;
      PATCH) printf '{"data":{"id":"hook-001","blueprint_id":"blueprint-001","name":"Updated hook","condition":"blueprint_mismatch","condition_config":null,"actions":[{"type":"add_tag","tags":[["blueprint-incomplete"]]}],"enabled":false}}' >"$output" ;;
      DELETE) printf '{"data":{"ok":true}}' >"$output" ;;
    esac
    ;;
  blueprints/*/hooks)
    case "$method" in
      GET) printf '{"data":[{"id":"hook-001","blueprint_id":"blueprint-001","name":"Flag incomplete apps","condition":"blueprint_mismatch","actions":[{"type":"add_tag","tags":[["blueprint-incomplete"]]}],"enabled":true}]}' >"$output" ;;
      POST) printf '{"data":{"id":"hook-002","blueprint_id":"blueprint-001","name":"Mark ready","condition":"blueprint_matches","condition_config":null,"actions":[{"type":"remove_tag","tags":[["blueprint-incomplete"]]},{"type":"add_tag","tags":[["ready","#10b981","pulse"]]}],"enabled":true}}' >"$output" ;;
    esac
    ;;
  blueprints/*/evaluate)
    printf '{"data":{"evaluated_applications":3}}' >"$output"
    ;;
  blueprints/*)
    case "$method" in
      GET) printf '{"data":{"id":"blueprint-001","name":"Production app","description":"Shared contract","json_schema":{"type":"object"},"required_files":["associated-domains.json"],"platforms":["ios","android"],"applications_count":3,"hooks_count":2,"hooks":[],"created_at":"2026-08-06T09:00:00Z","updated_at":"2026-08-06T10:00:00Z"}}' >"$output" ;;
      PATCH) printf '{"data":{"id":"blueprint-001","name":"Updated blueprint","description":"Shared contract","json_schema":{"type":"object"},"required_files":[],"platforms":["ios"],"applications_count":3,"hooks_count":2}}' >"$output" ;;
      DELETE) printf '{"data":{"ok":true}}' >"$output" ;;
    esac
    ;;
  blueprints)
    case "$method" in
      GET) printf '{"data":[{"id":"blueprint-001","name":"Production app","required_files":["associated-domains.json"],"platforms":["ios","android"],"applications_count":3,"hooks_count":2}]}' >"$output" ;;
      POST) printf '{"data":{"id":"blueprint-002","name":"Web app","description":null,"json_schema":{"type":"object"},"required_files":[],"platforms":["web"],"applications_count":0,"hooks_count":0}}' >"$output" ;;
    esac
    ;;
  # --- Sentry telemetry ---
  apps/*/telemetry/issues/*)
    case "$method" in
      PATCH) printf '{"data":{"id":"issue-001","title":"Payment failed","events_count":2,"status":"resolved","tags":[["urgent","#f43f5e","pulse"]],"resolved_at":"2026-08-06T12:00:00Z"}}' >"$output" ;;
      *) printf '{"data":{"id":"issue-001","title":"Payment failed","events_count":2,"timeline":[{"id":"evt-001"}],"selected_event":{"id":"evt-001","user":{"id":"buyer-42"}}}}' >"$output" ;;
    esac
    ;;
  apps/*/telemetry/issues)
    printf '{"data":[{"id":"issue-001","title":"Payment failed","events_count":2,"status":"unresolved","last_seen_at":"2026-08-05T12:00:00Z"}]}' >"$output"
    ;;
  apps/*/telemetry/traces/*)
    printf '{"data":{"trace_id":"aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa","title":"POST /checkout","events_count":2,"selected_event":{"id":"evt-002"}}}' >"$output"
    ;;
  apps/*/telemetry/traces)
    printf '{"data":[{"trace_id":"aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa","title":"POST /checkout","environment":"production","release":"1.4.0","occurred_at":"2026-08-05T12:00:00Z"}]}' >"$output"
    ;;
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
  apps/*/credentials/*/verification)
    printf '{"data":{"provider":"aws_secrets_manager","resource":"arn:aws:secretsmanager:eu-central-1:123456789012:secret:ios-dist","metadata":{"region":"eu-central-1"},"metadata_version":2,"metadata_source":"pipeline","verification":{"status":"verified","verified_at":"2026-08-03T12:30:00+00:00","error":null}}}' >"$output"
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
      GET) printf '{"data":{"id":"019fc789-478f-71a0-9177-0ed28c77cff2","slug":"customer-app","name":"Customer App","description":"Mobile client","created_at":"2025-01-15","updated_at":"2025-06-01"}}' >"$output" ;;
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
  # --- Sentry integration (must precede the generic integrations/* patterns) ---
  integrations/sentry/*)
    case "$method" in
      DELETE) printf '{"data":{"ok":true}}' >"$output" ;;
      *) printf '{"data":{"id":"dsn-001","name":"Customer relay","routing_method":"query","enabled":true,"forward_enabled":false,"routes":[]}}' >"$output" ;;
    esac
    ;;
  integrations/sentry)
    case "$method" in
      GET) printf '{"data":[{"id":"dsn-001","name":"Customer relay","routing_method":"query","enabled":true,"forward_enabled":false,"last_event_at":null}]}' >"$output" ;;
      *) printf '{"data":{"id":"dsn-001","name":"Customer relay","routing_method":"query","enabled":true,"forward_enabled":false,"routes":[]}}' >"$output" ;;
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
