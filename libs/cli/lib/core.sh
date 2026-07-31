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

require_value() {
  [ "$#" -ge 2 ] && [ -n "$2" ] || die "$1 requires a value"
}
