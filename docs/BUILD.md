# Build the extension

Building is only required when you need to create a package from this source repository. Normal Panel operators should download the ready-made release package from the [GitHub Releases page](https://github.com/morok101st/Arma-Reforger-Mod-Manager-Pterotactyl-Extension/releases) and install it through the Pterodactyl Extensions UI.

## Prerequisites

Host tools: Docker, Git, `python3`, `zip`, and a shell; enough free disk space and internet access. The build runs in disposable `node:22-bookworm` and `php:8.4-cli` containers. **Do not build inside the production Panel container.**

## Build from a Git clone

```bash
git clone <YOUR-REPOSITORY-URL> /path/to/armm-modsets
cd /path/to/armm-modsets
chmod +x scripts/build.sh
./scripts/build.sh 2>&1 | tee armm-build.log
```

Output: `build/armm-modsets-v1.1.2.zip`. This ZIP is the installable archive. Upload it through **Admin → Extensions** in Pterodactyl. Do not upload the repository ZIP or GitHub's automatically generated **Source code** ZIP as an extension.

The script clones Panel **tag `v2.0.0-beta.2`** into `.build/.panel` and the official extension examples into `.build/official-extensions`, then uses their tooling to build the UI. The Panel checkout is versioned; the extension example repository follows its current default branch, so builds are **not bit-for-bit reproducible** until that second dependency is pinned to a known commit. The script prints both revisions for auditing.

To use a different Panel tag (not a compatibility guarantee):

```bash
PANEL_TAG=v2.0.0-beta.2 ./scripts/build.sh
```

To retry, run the script again. It isolates/replaces only `.build/armm-modsets`. It avoids root-owned npm files by running npm with your host user ID. No Panel service is restarted.

## Validation and release publishing

Before distributing a self-built package, unpack it and confirm it contains `extension.json`, `src`, `routes`, `database`, and `dist/client.js`. Install and test the package in a non-production Panel through the Extensions UI before using it in production.

GitHub Actions builds the package on pushes, pull requests, and manual runs. A tag matching `v*` also publishes `build/*.zip` as a GitHub Release asset.
