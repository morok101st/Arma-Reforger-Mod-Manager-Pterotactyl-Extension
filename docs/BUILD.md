# Build the extension

## Prerequisites

Host tools: Docker, Git, `python3`, `zip`, shell; enough free disk space and internet access. Build runs in disposable `node:22-bookworm` and `php:8.4-cli` containers. **Do not build inside the production Panel container.**

## Building from a Git clone

```bash
git clone <YOUR-REPO-URL> /data/ptero2/armm-extension-build/armm-modsets-repo
cd /data/ptero2/armm-extension-build/armm-modsets-repo
chmod +x scripts/build.sh
./scripts/build.sh 2>&1 | tee armm-build.log
```

Output: `build/armm-modsets-v1.1.1.zip`. This zip is the installable archive (the Panel accepts `.zip` as well as `.pteroext`). Do not upload the repository ZIP as an installable extension.

The script clones Panel **tag `v2.0.0-beta.2`** into `.build/panel` and the official extension examples into `.build/official-extensions`, then uses their tooling to build the UI. The Panel checkout is versioned; the extension example repository follows its current default branch, so builds are **not bit-for-bit reproducible** until that second dependency is pinned to a known commit. The script prints both revisions for auditing.

To use a different Panel tag (not a compatibility guarantee):

```bash
PANEL_TAG=v2.0.0-beta.2 ./scripts/build.sh
```

To retry, simply run the script again. It isolates/replaces only `.build/armm-modsets`. It avoids the earlier `Permission denied` error by running npm with your host user ID instead of root. No Panel service is restarted.

## Validation before install

1. Unpack the **generated** zip in a temporary directory.
2. Copy the unpacked directory into the Panel container.
3. Run `php artisan p:extension:doctor <path>`.

The exact commands are in [INSTALL.md](INSTALL.md).
