#!/usr/bin/env bash

# HTTP transport: curl wrapper, request building, response handling.

REQUEST_HEADERS=()
REQUEST_FORMS=()
REQUEST_FILES=()
REQUEST_BODY_KIND="none"
REQUEST_BODY_VALUE=""
REQUEST_HAS_CONTENT_TYPE=0
LAST_RESPONSE_FILE=""

reset_request() {
  REQUEST_HEADERS=()
  REQUEST_FORMS=()
  REQUEST_FILES=()
  REQUEST_BODY_KIND="none"
  REQUEST_BODY_VALUE=""
  REQUEST_HAS_CONTENT_TYPE=0
}

validate_field_assignment() {
  local option="$1"
  local assignment="$2"
  local field

  case "$assignment" in
    *=*) ;;
    *) die "$option expects FIELD=VALUE" ;;
  esac
  field=${assignment%%=*}
  case "$field" in
    ''|*[!A-Za-z0-9_.-]*) die "$option expects FIELD=VALUE with a simple field name" ;;
  esac
}

validate_file_assignment() {
  local assignment="$1"
  local path
  validate_field_assignment "--file" "$assignment"
  path=${assignment#*=}
  [ -n "$path" ] || die "--file requires a non-empty file path"
  [ -f "$path" ] || die "upload file does not exist: $path"
}

header_is_sensitive() {
  local name=${1%%:*}
  name=$(printf '%s' "$name" | tr '[:upper:]' '[:lower:]')
  case "$name" in
    authorization|cookie|proxy-authorization|x-api-key) return 0 ;;
    *) return 1 ;;
  esac
}

print_request_plan() {
  local method="$1"
  local url="$2"
  local item
  local path

  printf 'dry-run: no request sent\n'
  printf 'method: %s\n' "$method"
  printf 'url: %s\n' "$url"
  printf 'headers:\n'
  printf '  Authorization: Bearer [REDACTED]\n'
  printf '  Accept: application/json\n'
  for item in "${REQUEST_HEADERS[@]}"; do
    if header_is_sensitive "$item"; then
      printf '  %s: [REDACTED]\n' "${item%%:*}"
    else
      printf '  %s\n' "$item"
    fi
  done

  case "$REQUEST_BODY_KIND" in
    inline) printf 'body: inline data (%s characters)\n' "${#REQUEST_BODY_VALUE}" ;;
    file) printf 'body-file: %s\n' "$REQUEST_BODY_VALUE" ;;
    stdin) printf 'body-file: stdin\n' ;;
    multipart)
      printf 'multipart:\n'
      for item in "${REQUEST_FORMS[@]}"; do
        printf '  field: %s=<inline value>\n' "${item%%=*}"
      done
      for item in "${REQUEST_FILES[@]}"; do
        path=${item#*=}
        printf '  file: %s=%s\n' "${item%%=*}" "$path"
      done
      ;;
    none) printf 'body: none\n' ;;
  esac

  if [ -n "$OUTPUT_FILE" ]; then
    printf 'output: %s\n' "$OUTPUT_FILE"
  else
    printf 'output: stdout\n'
  fi
}

print_http_error_body() {
  local response_file="$1"
  if [ -s "$response_file" ]; then
    printf '%s: response body:\n' "$APPMCTL_NAME" >&2
    cat "$response_file" >&2
    printf '\n' >&2
  fi
}

perform_request() {
  local method="$1"
  local url="$2"
  local response_file
  local auth_headers_file
  local inline_file=""
  local status
  local curl_status
  local item
  local field
  local path
  local curl_args

  response_file=$(new_temp_file response) || die "could not create a response file"
  auth_headers_file=$(new_temp_file headers) || die "could not create a header file"
  chmod 600 "$auth_headers_file" 2>/dev/null || true
  printf 'Authorization: Bearer %s\nAccept: application/json\n' "$API_KEY" >"$auth_headers_file"
  curl_args=(
    --silent
    --show-error
    --request "$method"
    --output "$response_file"
    --write-out '%{http_code}'
    --header "@$auth_headers_file"
  )

  for item in "${REQUEST_HEADERS[@]}"; do
    curl_args+=(--header "$item")
  done

  case "$REQUEST_BODY_KIND" in
    inline)
      inline_file=$(new_temp_file request) || die "could not create a request body file"
      chmod 600 "$inline_file" 2>/dev/null || true
      printf '%s' "$REQUEST_BODY_VALUE" >"$inline_file"
      if [ "$REQUEST_HAS_CONTENT_TYPE" -eq 0 ]; then
        curl_args+=(--header 'Content-Type: application/json')
      fi
      curl_args+=(--data-binary "@$inline_file")
      ;;
    file)
      if [ "$REQUEST_HAS_CONTENT_TYPE" -eq 0 ]; then
        curl_args+=(--header 'Content-Type: application/json')
      fi
      curl_args+=(--data-binary "@$REQUEST_BODY_VALUE")
      ;;
    stdin)
      if [ "$REQUEST_HAS_CONTENT_TYPE" -eq 0 ]; then
        curl_args+=(--header 'Content-Type: application/json')
      fi
      curl_args+=(--data-binary '@-')
      ;;
    multipart)
      for item in "${REQUEST_FORMS[@]}"; do
        curl_args+=(--form-string "$item")
      done
      for item in "${REQUEST_FILES[@]}"; do
        field=${item%%=*}
        path=${item#*=}
        curl_args+=(--form "$field=@$path")
      done
      ;;
  esac

  curl_args+=("$url")
  status=$("$CURL_BIN" "${curl_args[@]}")
  curl_status=$?

  if [ "$curl_status" -ne 0 ]; then
    print_http_error_body "$response_file"
    say_error "request failed before receiving a valid response (curl exit $curl_status)"
    return 1
  fi
  case "$status" in
    [0-9][0-9][0-9]) ;;
    *)
      print_http_error_body "$response_file"
      say_error "curl returned an invalid HTTP status: $status"
      return 1
      ;;
  esac
  if [ "$status" -lt 200 ] || [ "$status" -ge 300 ]; then
    print_http_error_body "$response_file"
    say_error "API request failed with HTTP $status"
    return 1
  fi

  LAST_RESPONSE_FILE="$response_file"
  return 0
}

check_output_destination() {
  if [ -n "$OUTPUT_FILE" ] && [ -e "$OUTPUT_FILE" ] && [ "$FORCE_OUTPUT" -ne 1 ]; then
    die "refusing to overwrite $OUTPUT_FILE (use --force)"
  fi
  if [ -n "$OUTPUT_FILE" ] && [ ! -d "$(dirname "$OUTPUT_FILE")" ]; then
    die "output directory does not exist: $(dirname "$OUTPUT_FILE")"
  fi
}

emit_response() {
  local source="$LAST_RESPONSE_FILE"
  local formatted

  if [ "$PRETTY_JSON" -eq 1 ]; then
    require_jq
    formatted=$(new_temp_file json) || die "could not create a JSON output file"
    if ! "$JQ_BIN" . "$source" >"$formatted"; then
      die "the API response is not valid JSON"
    fi
    source="$formatted"
  fi

  if [ -n "$OUTPUT_FILE" ]; then
    mv -f -- "$source" "$OUTPUT_FILE" || die "could not write response to $OUTPUT_FILE"
  else
    cat "$source"
  fi
}

configure_json_file_body() {
  reset_request
  REQUEST_BODY_KIND="file"
  REQUEST_BODY_VALUE="$1"
}

discard_last_response() {
  :
}

send_json_file() {
  local method="$1"
  local url="$2"
  local body_file="$3"

  configure_json_file_body "$body_file"
  perform_request "$method" "$url" || return 1
  discard_last_response
}

create_json_string_body() {
  local content_file="$1"
  local body_file="$2"
  "$JQ_BIN" -Rs '{content: .}' "$content_file" >"$body_file"
}

post_log_file() {
  local url="$1"
  local content_file="$2"
  local body_file

  body_file=$(new_temp_file log-body) || return 1
  if ! create_json_string_body "$content_file" "$body_file"; then
    say_error "could not encode build log as JSON"
    return 1
  fi
  send_json_file POST "$url" "$body_file"
}

post_log_text() {
  local url="$1"
  local content="$2"
  local content_file

  content_file=$(new_temp_file log-text) || return 1
  printf '%s' "$content" >"$content_file"
  post_log_file "$url" "$content_file"
}

api_get() {
  local path="$1"
  local url
  require_config
  url=$(build_url "$path") || return 1
  require_curl
  ensure_tmpdir
  perform_request GET "$url" || return 1
}

api_post() {
  local path="$1"
  local body_file="$2"
  local url
  require_config
  url=$(build_url "$path") || return 1
  require_curl
  ensure_tmpdir
  reset_request
  if [ -n "$body_file" ]; then
    send_json_file POST "$url" "$body_file"
  else
    perform_request POST "$url"
  fi
}

api_patch() {
  local path="$1"
  local body_file="$2"
  local url
  require_config
  url=$(build_url "$path") || return 1
  require_curl
  ensure_tmpdir
  reset_request
  if [ -n "$body_file" ]; then
    send_json_file PATCH "$url" "$body_file"
  else
    perform_request PATCH "$url"
  fi
}

api_delete() {
  local path="$1"
  local url
  require_config
  url=$(build_url "$path") || return 1
  require_curl
  ensure_tmpdir
  reset_request
  perform_request DELETE "$url" || return 1
}
