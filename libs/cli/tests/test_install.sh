#!/usr/bin/env bash

set -eu
set -o pipefail

TEST_DIR=$(mktemp -d "${TMPDIR:-/tmp}/appmctl-install-test.XXXXXX")
CLI_DIR=$(cd "$(dirname "$0")/.." && pwd)
INSTALLER="$CLI_DIR/install.sh"
SOURCE="$CLI_DIR/bin/appmctl"
INSTALL_DIR="$TEST_DIR/bin with spaces"

cleanup() {
  case "$TEST_DIR" in
    */appmctl-install-test.*) rm -rf -- "$TEST_DIR" ;;
  esac
}
trap cleanup EXIT

fail() {
  printf 'not ok - %s\n' "$1" >&2
  exit 1
}

if command -v sha256sum >/dev/null 2>&1; then
  EXPECTED_SHA=$(sha256sum "$SOURCE" | awk '{print $1}')
else
  EXPECTED_SHA=$(shasum -a 256 "$SOURCE" | awk '{print $1}')
fi

install_output=$(
  curl -fsSL "file://$INSTALLER" |
    APPMCTL_INSTALL_DIR="$INSTALL_DIR" \
    APPMCTL_DOWNLOAD_URL="file://$SOURCE" \
    APPMCTL_SHA256="$EXPECTED_SHA" \
    bash
)

[ -x "$INSTALL_DIR/appmctl" ] || fail 'installer did not create an executable'
[ "$("$INSTALL_DIR/appmctl" --version)" = 'appmctl 0.2.0' ] || fail 'installed CLI does not run'
case "$install_output" in
  *"Installed appmctl 0.2.0"*) ;;
  *) fail 'installer did not report the installed version' ;;
esac

if APPMCTL_INSTALL_DIR="$INSTALL_DIR" \
  APPMCTL_DOWNLOAD_URL="file://$SOURCE" \
  APPMCTL_SHA256='0000000000000000000000000000000000000000000000000000000000000000' \
    bash "$INSTALLER" >/dev/null 2>&1; then
  fail 'installer accepted an invalid checksum'
fi

printf 'ok - installer downloads and verifies appmctl\n'
printf 'ok - installer rejects a checksum mismatch\n'
printf '1..2\n'
