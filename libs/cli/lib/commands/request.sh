#!/usr/bin/env bash

# Legacy "request" command. Preserved for backward compatibility.

request_usage() {
  cat <<'EOF'
Usage:
  appmctl [global options] request METHOD PATH [options]

PATH is relative to /api/v1/{APP_MANAGER_TEAM}/. It may include a query
string, for example "apps?per_page=25".

Options:
  --data JSON               Send inline JSON
  --data-file FILE          Read the request body from FILE; use - for stdin
  --form FIELD=VALUE        Add a multipart text field; may be repeated
  --file FIELD=FILE         Add a multipart file; may be repeated
  -H, --header HEADER       Add a request header; may be repeated
  --content-type TYPE       Set Content-Type for --data/--data-file
  --dry-run                 Print the request plan without calling the API
  --json                    Pretty-print and validate a JSON response with jq
  -o, --output FILE         Save the response to FILE
  --force                   Replace an existing output file

Examples:
  appmctl --json request GET apps
  appmctl request POST apps --data '{"name":"Customer App"}' --json
  appmctl request POST apps --data-file create-app.json --output response.json
  appmctl request POST apps/my-app/assets/icon --file file=icon.png --json
  appmctl request GET apps/my-app/assets/icon --output icon.png
EOF
}

parse_request_options() {
  local item

  while [ "$#" -gt 0 ]; do
    case "$1" in
      --data)
        require_value "$1" "$2"
        [ "$REQUEST_BODY_KIND" = "none" ] || die "only one of --data, --data-file, --form and --file may select the body type"
        REQUEST_BODY_KIND="inline"
        REQUEST_BODY_VALUE="$2"
        shift 2
        ;;
      --data=*)
        [ "$REQUEST_BODY_KIND" = "none" ] || die "only one body type may be selected"
        REQUEST_BODY_KIND="inline"
        REQUEST_BODY_VALUE=${1#*=}
        shift
        ;;
      --data-file)
        require_value "$1" "$2"
        [ "$REQUEST_BODY_KIND" = "none" ] || die "only one body type may be selected"
        if [ "$2" = "-" ]; then
          REQUEST_BODY_KIND="stdin"
        else
          [ -f "$2" ] || die "request body file does not exist: $2"
          REQUEST_BODY_KIND="file"
          REQUEST_BODY_VALUE="$2"
        fi
        shift 2
        ;;
      --data-file=*)
        [ "$REQUEST_BODY_KIND" = "none" ] || die "only one body type may be selected"
        REQUEST_BODY_VALUE=${1#*=}
        if [ "$REQUEST_BODY_VALUE" = "-" ]; then
          REQUEST_BODY_KIND="stdin"
        else
          [ -f "$REQUEST_BODY_VALUE" ] || die "request body file does not exist: $REQUEST_BODY_VALUE"
          REQUEST_BODY_KIND="file"
        fi
        shift
        ;;
      --form)
        require_value "$1" "$2"
        [ "$REQUEST_BODY_KIND" = "none" ] || [ "$REQUEST_BODY_KIND" = "multipart" ] || die "multipart fields cannot be combined with --data or --data-file"
        validate_field_assignment "--form" "$2"
        REQUEST_BODY_KIND="multipart"
        REQUEST_FORMS+=("$2")
        shift 2
        ;;
      --form=*)
        item=${1#*=}
        [ "$REQUEST_BODY_KIND" = "none" ] || [ "$REQUEST_BODY_KIND" = "multipart" ] || die "multipart fields cannot be combined with --data or --data-file"
        validate_field_assignment "--form" "$item"
        REQUEST_BODY_KIND="multipart"
        REQUEST_FORMS+=("$item")
        shift
        ;;
      --file)
        require_value "$1" "$2"
        [ "$REQUEST_BODY_KIND" = "none" ] || [ "$REQUEST_BODY_KIND" = "multipart" ] || die "multipart files cannot be combined with --data or --data-file"
        validate_file_assignment "$2"
        REQUEST_BODY_KIND="multipart"
        REQUEST_FILES+=("$2")
        shift 2
        ;;
      --file=*)
        item=${1#*=}
        [ "$REQUEST_BODY_KIND" = "none" ] || [ "$REQUEST_BODY_KIND" = "multipart" ] || die "multipart files cannot be combined with --data or --data-file"
        validate_file_assignment "$item"
        REQUEST_BODY_KIND="multipart"
        REQUEST_FILES+=("$item")
        shift
        ;;
      -H|--header)
        require_value "$1" "$2"
        case "$2" in
          *$'\n'*|*$'\r'*) die "request headers must not contain line breaks" ;;
        esac
        case "$2" in
          [Aa][Uu][Tt][Hh][Oo][Rr][Ii][Zz][Aa][Tt][Ii][Oo][Nn]:*) die "Authorization is managed by APP_MANAGER_API_KEY" ;;
          [Cc][Oo][Nn][Tt][Ee][Nn][Tt]-[Tt][Yy][Pp][Ee]:*) REQUEST_HAS_CONTENT_TYPE=1 ;;
        esac
        REQUEST_HEADERS+=("$2")
        shift 2
        ;;
      --content-type)
        require_value "$1" "$2"
        REQUEST_HEADERS+=("Content-Type: $2")
        REQUEST_HAS_CONTENT_TYPE=1
        shift 2
        ;;
      --dry-run) DRY_RUN=1; shift ;;
      --json) PRETTY_JSON=1; shift ;;
      -o|--output)
        require_value "$1" "$2"
        OUTPUT_FILE="$2"
        shift 2
        ;;
      --force) FORCE_OUTPUT=1; shift ;;
      -h|--help) request_usage; exit 0 ;;
      *) die "unknown request option: $1" ;;
    esac
  done
}

command_request() {
  local method
  local path
  local url

  if [ "${1:-}" = "-h" ] || [ "${1:-}" = "--help" ]; then
    request_usage
    return 0
  fi
  [ "$#" -ge 2 ] || die "request requires METHOD and PATH"
  method=$(printf '%s' "$1" | tr '[:lower:]' '[:upper:]')
  path="$2"
  shift 2
  case "$method" in
    GET|POST|PUT|PATCH|DELETE|HEAD) ;;
    *) die "unsupported HTTP method: $method" ;;
  esac

  reset_request
  parse_request_options "$@"
  require_config
  url=$(build_url "$path") || return 1
  check_output_destination

  if [ "$DRY_RUN" -eq 1 ]; then
    print_request_plan "$method" "$url"
    return 0
  fi

  require_curl
  ensure_tmpdir
  perform_request "$method" "$url" || return 1
  emit_response
}
