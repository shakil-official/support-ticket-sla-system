#!/usr/bin/env bash
# Creates .env from .env.example with a fresh APP_KEY and random DB passwords.
# The file is built in a temporary file and moved into place only when everything succeeded,
# so a failed run can never leave a half-filled .env behind.
set -euo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")/.."

if [ -f .env ]; then
  if grep -qE '^DB_(ROOT_)?PASSWORD=$|^APP_KEY=$' .env; then
    echo ".env exists but APP_KEY / DB passwords are EMPTY (an earlier run probably failed)." >&2
    echo "Delete it and run this script again:  rm .env && ./docker/setup.sh" >&2
    exit 1
  fi
  echo ".env already exists - leaving it untouched."
  exit 0
fi

# random string without depending on openssl: /dev/urandom + base64 (coreutils)
rand() { head -c "$1" /dev/urandom | base64 | tr -d '=+/\n' | cut -c1-"$2"; }

KEY="base64:$(head -c 32 /dev/urandom | base64)"
DB_PASS="$(rand 48 24)"
ROOT_PASS="$(rand 48 24)"

for v in "$KEY" "$DB_PASS" "$ROOT_PASS"; do
  [ -n "$v" ] || { echo "Could not generate random values." >&2; exit 1; }
done

TMP="$(mktemp .env.XXXXXX)"
trap 'rm -f "$TMP"' EXIT
cp .env.example "$TMP"

# portable in-place edit (works with GNU and BSD sed); '|' is safe, the values are [A-Za-z0-9]
set_env() {
  local k="$1" v="$2"
  if grep -q "^${k}=" "$TMP"; then
    sed -i.bak "s|^${k}=.*|${k}=${v}|" "$TMP" && rm -f "$TMP.bak"
  else
    echo "${k}=${v}" >> "$TMP"
  fi
}

set_env APP_KEY "$KEY"
set_env DB_PASSWORD "$DB_PASS"
set_env DB_ROOT_PASSWORD "$ROOT_PASS"

mv "$TMP" .env
trap - EXIT
echo ".env created. Now run: docker compose up -d --build"
