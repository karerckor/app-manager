#!/usr/bin/env bash

# Core utilities: error handling, cleanup, temporary files.

APPMCTL_ROOT_PID="$$"
APPMCTL_TMPDIR=""

say_error() {
  printf '%s: %s\n' "$APPMCTL_NAME" "$*" >&2
}

die() {
  say_error "$*"
  exit 1
}

cleanup() {
  if [ "$$" = "$APPMCTL_ROOT_PID" ] && [ -n "$APPMCTL_TMPDIR" ] && [ -d "$APPMCTL_TMPDIR" ]; then
    case "$APPMCTL_TMPDIR" in
      */appmctl.*) rm -rf -- "$APPMCTL_TMPDIR" ;;
    esac
  fi
}

trap cleanup EXIT

ensure_tmpdir() {
  if [ -z "$APPMCTL_TMPDIR" ]; then
    APPMCTL_TMPDIR=$(mktemp -d "${TMPDIR:-/tmp}/appmctl.XXXXXX") || die "could not create a temporary directory"
    chmod 700 "$APPMCTL_TMPDIR" 2>/dev/null || true
  fi
}

new_temp_file() {
  [ -n "$APPMCTL_TMPDIR" ] || return 1
  mktemp "$APPMCTL_TMPDIR/${1:-file}.XXXXXX" || return 1
}

command_exists() {
  command -v "$1" >/dev/null 2>&1
}

# Generate an idempotency key. The API validates these as UUIDs, so the urandom
# fallback forces the version and variant nibbles rather than emitting 32 random
# hex characters, which would be the right shape but not a valid UUID.
new_request_uuid() {
  if command_exists uuidgen; then
    uuidgen | tr '[:upper:]' '[:lower:]'
    return 0
  fi

  if [ -r /proc/sys/kernel/random/uuid ]; then
    cat /proc/sys/kernel/random/uuid
    return 0
  fi

  local hex
  hex=$(od -An -tx1 -N16 /dev/urandom 2>/dev/null | tr -d ' \n') || return 1
  [ "${#hex}" -eq 32 ] || return 1

  local variant
  variant=$(printf '%x' $(( 0x${hex:16:2} & 0x3f | 0x80 )))
  printf '%s-%s-4%s-%s%s-%s\n' \
    "${hex:0:8}" "${hex:8:4}" "${hex:13:3}" "$variant" "${hex:18:2}" "${hex:20:12}"
}

require_value() {
  [ "$#" -ge 2 ] && [ -n "$2" ] || die "$1 requires a value"
}
