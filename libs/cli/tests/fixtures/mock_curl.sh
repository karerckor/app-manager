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

# Route based on URL pattern and method.
case "$url" in
  */apps?*|*/apps "")
    if [ "$method" = "GET" ]; then
      printf '{"data":[{"slug":"customer-app","name":"Customer App","created_at":"2025-01-15"},{"slug":"internal-app","name":"Internal App","created_at":"2025-01-10"}]}' >"$output"
    else
      printf '{"data":{"slug":"new-app","name":"New App","created_at":"2025-07-31"}}' >"$output"
    fi
    ;;
  */apps/[^/]* "")
    case "$method" in
      GET) printf '{"data":{"slug":"customer-app","name":"Customer App","description":"Mobile client","created_at":"2025-01-15","updated_at":"2025-06-01"}}' >"$output" ;;
      PATCH) printf '{"data":{"slug":"customer-app","name":"Updated App"}}' >"$output" ;;
      DELETE) printf '{"data":{"ok":true}}' >"$output" ;;
    esac
    ;;
  */builds?*|*/builds "")
    printf '{"data":[{"id":"bld-001","profile_type":"production","status":"completed","created_at":"2025-01-15"},{"id":"bld-002","profile_type":"staging","status":"running","created_at":"2025-01-16"}]}' >"$output"
    ;;
  */builds/[^/]*/cancel)
    printf '{"data":{"ok":true}}' >"$output"
    ;;
  */builds/[^/]* "")
    case "$method" in
      GET) printf '{"data":{"id":"bld-001","app":{"slug":"customer-app"},"profile_type":"production","status":"completed","request_uuid":"abc-123","created_at":"2025-01-15","started_at":"2025-01-15T10:00:00Z","finished_at":"2025-01-15T10:30:00Z"}}' >"$output" ;;
      POST) printf '{"data":{"id":"bld-003","status":"pending","profile_type":"production","request_uuid":"abc-123","created_at":"2025-07-31"}}' >"$output" ;;
      PATCH) printf '{"data":{"id":"bld-001","status":"completed"}}' >"$output" ;;
    esac
    ;;
  */builds "")
    printf '{"data":[{"id":"bld-001","profile_type":"production","status":"completed","created_at":"2025-01-15"}]}' >"$output"
    ;;
  */artifacts "")
    printf '{"data":[{"id":"art-001","name":"app-release.aab","created_at":"2025-01-15"},{"id":"art-002","name":"app-debug.apk","created_at":"2025-01-15"}]}' >"$output"
    ;;
  */artifacts/[^/]*/file)
    printf 'fake-file-content' >"$output"
    ;;
  */artifacts/[^/]* "")
    case "$method" in
      DELETE) printf '{"data":{"ok":true}}' >"$output" ;;
      *) printf '{"data":{"id":"art-001","name":"app-release.aab","created_at":"2025-01-15"}}' >"$output" ;;
    esac
    ;;
  */credentials?*|*/credentials "")
    printf '{"data":[{"id":"cred-001","credential_type":{"slug":"android_keystore"},"build_profile":"production","created_at":"2025-01-15"}]}' >"$output"
    ;;
  */credentials/[^/]*/file)
    case "$method" in
      GET) printf 'fake-credential-content' >"$output" ;;
      POST) printf '{"data":{"ok":true}}' >"$output" ;;
    esac
    ;;
  */credentials/[^/]* "")
    case "$method" in
      GET) printf '{"data":{"id":"cred-001","credential_type":{"slug":"android_keystore"},"build_profile":"production","created_at":"2025-01-15","updated_at":"2025-06-01"}}' >"$output" ;;
      PATCH) printf '{"data":{"id":"cred-001","credential_type":{"slug":"android_keystore"}}}' >"$output" ;;
      DELETE) printf '{"data":{"ok":true}}' >"$output" ;;
    esac
    ;;
  */credential-types)
    printf '{"data":[{"slug":"android_keystore","name":"Android Keystore","platform":"android"},{"slug":"ios_certificate","name":"iOS Certificate","platform":"ios"}]}' >"$output"
    ;;
  */integrations "")
    printf '{"data":[{"id":"int-001","provider":"github","name":"GitHub","created_at":"2025-01-15"}]}' >"$output"
    ;;
  */integrations/[^/]*/repositories)
    printf '{"data":[{"id":"repo-001","name":"org/app-repo","default_branch":"main"}]}' >"$output"
    ;;
  */integrations/[^/]*/workflows)
    printf '{"data":[{"id":"wf-001","name":"Build","path":".github/workflows/build.yml"}]}' >"$output"
    ;;
  */integrations/[^/]*/refs)
    printf '{"data":[{"ref":"refs/heads/main","sha":"abc123"}]}' >"$output"
    ;;
  */integrations/[^/]*/pipelines "")
    case "$method" in
      GET) printf '{"data":[{"id":"pipe-001","name":"Build Pipeline","created_at":"2025-01-15"}]}' >"$output" ;;
      POST) printf '{"data":{"id":"pipe-002","name":"New Pipeline","created_at":"2025-07-31"}}' >"$output" ;;
    esac
    ;;
  */pipelines/[^/]*/runs)
    printf '{"data":{"ok":true}}' >"$output"
    ;;
  */pipelines/[^/]*/inputs)
    printf '{"data":[{"name":"branch","type":"string","required":true}]}' >"$output"
    ;;
  */pipelines/[^/]* "")
    case "$method" in
      GET) printf '{"data":{"id":"pipe-001","name":"Build Pipeline","created_at":"2025-01-15","updated_at":"2025-06-01"}}' >"$output" ;;
      PATCH) printf '{"data":{"id":"pipe-001","name":"Updated Pipeline"}}' >"$output" ;;
      DELETE) printf '{"data":{"ok":true}}' >"$output" ;;
    esac
    ;;
  */webhooks "")
    case "$method" in
      GET) printf '{"data":[{"id":"wh-001","url":"https://example.com/hook","events":["build.completed"],"created_at":"2025-01-15"}]}' >"$output" ;;
      POST) printf '{"data":{"id":"wh-002","url":"https://example.com/hook","active":true,"created_at":"2025-07-31"}}' >"$output" ;;
    esac
    ;;
  */webhooks/[^/]*/deliveries/[^/]*/redeliver)
    printf '{"data":{"ok":true}}' >"$output"
    ;;
  */webhooks/[^/]*/deliveries "")
    printf '{"data":[{"id":"del-001","event":"build.completed","status_code":200,"delivered_at":"2025-01-15T10:00:00Z"}]}' >"$output"
    ;;
  */webhooks/[^/]*/test|*/webhooks/[^/]*/rotate-secret)
    printf '{"data":{"ok":true}}' >"$output"
    ;;
  */webhooks/[^/]* "")
    case "$method" in
      GET) printf '{"data":{"id":"wh-001","url":"https://example.com/hook","events":["build.completed"],"active":true,"created_at":"2025-01-15","updated_at":"2025-06-01"}}' >"$output" ;;
      PATCH) printf '{"data":{"id":"wh-001","url":"https://new-url.com/hook"}}' >"$output" ;;
      DELETE) printf '{"data":{"ok":true}}' >"$output" ;;
    esac
    ;;
  */logs "")
    case "$method" in
      GET) printf 'Build log content here' >"$output" ;;
      POST) printf '{"data":{"ok":true}}' >"$output" ;;
    esac
    ;;
  *)
    printf '{"data":{"ok":true}}' >"$output"
    ;;
esac

printf '200'
