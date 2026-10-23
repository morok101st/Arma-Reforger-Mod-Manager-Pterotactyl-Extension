#!/usr/bin/env bash
set -Eeuo pipefail
# Build is isolated: no changes to the running Panel, Wings, or database.
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PANEL_TAG="${PANEL_TAG:-v2.0.0-beta.2}"
# Expected commit of PANEL_TAG; set PANEL_REV='' when overriding PANEL_TAG.
PANEL_REV="${PANEL_REV-ea742d332f5861a81e502857446b8df9cb9f0254}"
# Pinned upstream revisions and image digests keep release builds reproducible.
TEMPLATE_REF="${TEMPLATE_REF:-2a1232df2f0a374e5f9efe492822d3e2b0999e12}"
ALPINE_IMAGE='alpine:3.20@sha256:d9e853e87e55526f6b2917df91a2115c36dd7c696a35be12163d44e6e2a4b6bc'
NODE_IMAGE='node:22-bookworm@sha256:63538dfe497cc29dfafdda25ada09b46db68a3f4cc205abf1f5d6bf0913f3384'
PHP_IMAGE='php:8.4-cli@sha256:40638d9d5ec50bbf20205a06c733fff5839fc13b0a7c8e10e455b49da2a13068'
STAGING="${STAGING:-$ROOT/.build}"
TARGET="${TARGET:-$ROOT/build}"
VERSION="$(python3 - "$ROOT/extension.json" <<'PYTHON'
import json,sys
with open(sys.argv[1], encoding='utf-8') as f: manifest=json.load(f)
version=manifest.get('version')
if not isinstance(version, str) or not version:
    raise SystemExit('extension.json must contain a non-empty version string')
print(version)
PYTHON
)" || { printf '\n[ARMM BUILD] ERROR: Invalid extension version\n' >&2; exit 1; }
msg() { printf '\n[ARMM BUILD] %s\n' "$*"; }
die() { printf '\n[ARMM BUILD] ERROR: %s\n' "$*" >&2; exit 1; }
for cmd in docker git python3 zip; do command -v "$cmd" >/dev/null || die "$cmd is required"; done
if [[ "${GITHUB_REF_TYPE:-}" == 'tag' && "${GITHUB_REF_NAME:-}" != "v$VERSION" ]]; then
  die "Git tag $GITHUB_REF_NAME does not match extension version v$VERSION"
fi
mkdir -p "$STAGING" "$TARGET"
if [[ ! -d "$STAGING/.panel/.git" ]]; then
  git clone --depth 1 --branch "$PANEL_TAG" https://github.com/pterodactyl/panel.git "$STAGING/.panel"
fi
[[ -z "$PANEL_REV" || "$(git -C "$STAGING/.panel" rev-parse HEAD)" == "$PANEL_REV" ]] || die "Panel checkout does not match $PANEL_REV"
[[ -f "$STAGING/.panel/packages/sdk/package.json" ]] || die 'Panel SDK not found'
if [[ ! -d "$STAGING/official-extensions/.git" ]]; then
  git init -q "$STAGING/official-extensions"
  git -C "$STAGING/official-extensions" remote add origin https://github.com/pterodactyl/extensions.git
fi
git -C "$STAGING/official-extensions" fetch -q --depth 1 origin "$TEMPLATE_REF"
git -C "$STAGING/official-extensions" checkout -q --detach FETCH_HEAD
[[ "$(git -C "$STAGING/official-extensions" rev-parse HEAD)" == "$TEMPLATE_REF" ]] || die 'Template revision mismatch'
TEMPLATE="$STAGING/official-extensions/custom-buttons"
# The official template resolves @pterodactyl/sdk via file:../.panel/packages/sdk.
# Keep the SDK checkout at .build/.panel accordingly.
[[ -f "$TEMPLATE/package.json" ]] || die 'Extension template not found'
msg "Panel revision: $(git -C "$STAGING/.panel" rev-parse HEAD)"
msg "Template revision: $(git -C "$STAGING/official-extensions" rev-parse HEAD)"
# Remove previous container-created root-owned files through a root container,
# and only within this repo's known, dedicated staging subtree.
if [[ -e "$STAGING/armm-modsets" ]]; then
  docker run --rm -v "$STAGING:/workspace" "$ALPINE_IMAGE" sh -ec 'rm -rf /workspace/armm-modsets'
fi
mkdir -p "$STAGING/armm-modsets"
for f in package.json package-lock.json vite.config.mjs tsconfig.json postcss.config.mjs openapi-ts.config.ts; do
  [[ ! -f "$TEMPLATE/$f" ]] || cp "$TEMPLATE/$f" "$STAGING/armm-modsets/$f"
done
cp "$ROOT/extension.json" "$STAGING/armm-modsets/"
cp -R "$ROOT/src" "$ROOT/routes" "$ROOT/database" "$STAGING/armm-modsets/"
cp "$ROOT/README.md" "$STAGING/armm-modsets/"
python3 - "$STAGING/armm-modsets/package.json" "$VERSION" <<'PYTHON'
import json,sys
p,version=sys.argv[1:]
with open(p,encoding='utf-8') as f: d=json.load(f)
d['name']='armm-modsets'
d['version']=version
with open(p,'w',encoding='utf-8') as f: json.dump(d,f,indent=2);f.write('\n')
PYTHON
msg 'Node 22: TypeScript + Vite Build'
# Run npm as caller's UID/GID, avoiding root-owned node_modules on host.
docker run --rm --user "$(id -u):$(id -g)" -e HOME=/tmp \
  -e npm_config_cache=/tmp/npm-cache -v "$STAGING:/work" \
  -w /work/armm-modsets "$NODE_IMAGE" sh -ec \
  'npm ci --no-audit --no-fund && npm run typecheck && npm run build'
[[ -s "$STAGING/armm-modsets/dist/client.js" ]] || die 'Frontend build is missing'
msg 'PHP syntax check'
docker run --rm -v "$STAGING/armm-modsets:/ext:ro" "$PHP_IMAGE" sh -ec \
  'find /ext/src /ext/routes /ext/database -type f -name "*.php" -exec php -l {} \;'
msg 'Creating installation archive'
(cd "$STAGING/armm-modsets" && zip -qr "$TARGET/armm-modsets-v$VERSION.zip" \
  extension.json src routes database dist README.md)
(cd "$TARGET" && sha256sum "armm-modsets-v$VERSION.zip" > "armm-modsets-v$VERSION.zip.sha256")
msg "Complete: $TARGET/armm-modsets-v$VERSION.zip"
msg 'Install the package through the Pterodactyl Extensions UI.'
