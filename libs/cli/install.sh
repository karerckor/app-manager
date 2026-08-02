#!/usr/bin/env bash

# Install appmctl as a single executable. The repository is deliberately not
# cloned: only libs/cli/bin/appmctl is downloaded from the selected Git ref.

set -euo pipefail

INSTALLER_NAME="appmctl installer"
REPOSITORY="${APPMCTL_REPOSITORY:-karerckor/app-manager}"
REF="${APPMCTL_REF:-main}"
EXPECTED_SHA256="${APPMCTL_SHA256:-}"
ALLOW_UNVERIFIED="${APPMCTL_ALLOW_UNVERIFIED:-0}"
INSTALL_DIR="${APPMCTL_INSTALL_DIR:-${HOME:-}/.local/bin}"
DOWNLOAD_URL="${APPMCTL_DOWNLOAD_URL:-https://raw.githubusercontent.com/${REPOSITORY}/${REF}/libs/cli/bin/appmctl}"
STAGING_FILE=""

say_error() {
  printf '%s: %s\n' "$INSTALLER_NAME" "$*" >&2
}

die() {
  say_error "$*"
  exit 1
}

cleanup() {
  if [ -n "$STAGING_FILE" ] && [ -f "$STAGING_FILE" ]; then
    rm -f -- "$STAGING_FILE"
  fi
}

trap cleanup EXIT
trap 'exit 129' HUP
trap 'exit 130' INT
trap 'exit 143' TERM

command_exists() {
  command -v "$1" >/dev/null 2>&1
}

sha256_file() {
  local file="$1"

  if command_exists sha256sum; then
    sha256sum "$file" | awk '{print $1}'
  elif command_exists shasum; then
    shasum -a 256 "$file" | awk '{print $1}'
  else
    die "APPMCTL_SHA256 was provided, but sha256sum or shasum is not available"
  fi
}

[ -n "$INSTALL_DIR" ] || die "set APPMCTL_INSTALL_DIR or HOME"
command_exists curl || die "curl is required"
command_exists bash || die "bash is required"
command_exists mktemp || die "mktemp is required"

if [ -z "$EXPECTED_SHA256" ] && [ "$ALLOW_UNVERIFIED" != "1" ]; then
  die "APPMCTL_SHA256 is required (set APPMCTL_ALLOW_UNVERIFIED=1 only for an explicitly trusted development source)"
fi

if ! mkdir -p -- "$INSTALL_DIR"; then
  die "could not create install directory: $INSTALL_DIR"
fi

STAGING_FILE=$(mktemp "$INSTALL_DIR/.appmctl.XXXXXX") || die "could not create a temporary file in $INSTALL_DIR"
chmod 600 "$STAGING_FILE"

if ! curl --fail --silent --show-error --location \
  --retry 3 \
  --connect-timeout 15 \
  --output "$STAGING_FILE" \
  "$DOWNLOAD_URL"; then
  die "could not download appmctl (repository=$REPOSITORY, ref=$REF)"
fi

IFS= read -r first_line <"$STAGING_FILE" || true
[ "$first_line" = '#!/usr/bin/env bash' ] || die "downloaded file is not the appmctl Bash executable"
bash -n "$STAGING_FILE" || die "downloaded appmctl failed the Bash syntax check"

if [ -n "$EXPECTED_SHA256" ]; then
  case "$EXPECTED_SHA256" in
    *[!0-9A-Fa-f]*) die "APPMCTL_SHA256 must be a 64-character hexadecimal SHA-256 value" ;;
  esac
  [ "${#EXPECTED_SHA256}" -eq 64 ] || die "APPMCTL_SHA256 must be a 64-character hexadecimal SHA-256 value"

  actual_sha256=$(sha256_file "$STAGING_FILE")
  expected_sha256=$(printf '%s' "$EXPECTED_SHA256" | tr '[:upper:]' '[:lower:]')
  actual_sha256=$(printf '%s' "$actual_sha256" | tr '[:upper:]' '[:lower:]')
  [ "$actual_sha256" = "$expected_sha256" ] || die "SHA-256 mismatch for downloaded appmctl"
fi

chmod 755 "$STAGING_FILE"
TARGET="$INSTALL_DIR/appmctl"
mv -f -- "$STAGING_FILE" "$TARGET"
STAGING_FILE=""

installed_version=$("$TARGET" --version)
printf 'Installed %s to %s\n' "$installed_version" "$TARGET"

case ":${PATH:-}:" in
  *":$INSTALL_DIR:"*) ;;
  *) printf 'Add it to PATH with: export PATH="%s:$PATH"\n' "$INSTALL_DIR" ;;
esac
