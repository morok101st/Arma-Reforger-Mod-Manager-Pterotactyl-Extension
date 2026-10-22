#!/usr/bin/env bash
set -Eeuo pipefail
# Runs the extension integration tests inside a prepared Pterodactyl Panel checkout.
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PANEL_DIR="${PANEL_DIR:-$ROOT/.build/.panel}"
FIXTURE="$PANEL_DIR/tests/Fixtures/extensions/armm-modsets"
TEST_FILE="$PANEL_DIR/tests/Integration/ArmmModsetsExtensionTest.php"
msg() { printf '\n[ARMM TEST] %s\n' "$*"; }
die() { printf '\n[ARMM TEST] ERROR: %s\n' "$*" >&2; exit 1; }
[[ -x "$PANEL_DIR/vendor/bin/pest" ]] || die "Panel test dependencies are missing in $PANEL_DIR. Run composer install in the Panel checkout first."
[[ -f "$ROOT/tests/panel/ArmmModsetsExtensionTest.php" ]] || die 'Extension integration test file is missing'

cleanup() {
  rm -rf "$FIXTURE"
  rm -f "$TEST_FILE"
}
trap cleanup EXIT

msg 'Preparing extension fixture'
cleanup
mkdir -p "$FIXTURE"
cp "$ROOT/extension.json" "$FIXTURE/"
cp -R "$ROOT/src" "$ROOT/routes" "$ROOT/database" "$FIXTURE/"
cp "$ROOT/tests/panel/ArmmModsetsExtensionTest.php" "$TEST_FILE"

msg 'Running Pterodactyl integration tests'
(
  cd "$PANEL_DIR"
  php vendor/bin/pest "$TEST_FILE"
)
