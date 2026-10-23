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

Output: `build/armm-modsets-v<version>.zip` plus a `.sha256` checksum file, where `<version>` is read from `extension.json`. This ZIP is the installable archive. Upload it through **Admin → Extensions** in Pterodactyl. Do not upload the repository ZIP or GitHub's automatically generated **Source code** ZIP as an extension.

The script clones Panel **tag `v2.0.0-beta.2`** into `.build/.panel` and the official extension examples into `.build/official-extensions`, then uses their tooling to build the UI. Both checkouts are pinned and verified against known commits (`PANEL_REV`, `TEMPLATE_REF`), npm dependencies are installed with `npm ci` from the template's `package-lock.json`, and all Docker images are pinned by digest. The script prints both revisions for auditing.

To use a different Panel tag (not a compatibility guarantee):

```bash
PANEL_TAG=<tag> PANEL_REV='' ./scripts/build.sh
```

`PANEL_REV=''` disables the commit check for the overridden tag. To update the pinned template, set `TEMPLATE_REF` in `scripts/build.sh` to the reviewed commit.

To retry, run the script again. It isolates/replaces only `.build/armm-modsets`. It avoids root-owned npm files by running npm with your host user ID. No Panel service is restarted.

## Validation and release publishing

Before distributing a self-built package, unpack it and confirm it contains `extension.json`, `src`, `routes`, `database`, `dist/client.js`, `README.md`, and `LICENSE`. Install and test the package in a non-production Panel through the Extensions UI before using it in production.

GitHub Actions builds the package on pushes, pull requests, and manual runs. A tag matching `v*` additionally runs a separate release job, the only job with write permissions, which creates a build provenance attestation and publishes the ZIP and its `.sha256` file as GitHub Release assets. All actions are pinned to commit SHAs; Dependabot proposes updates weekly.
