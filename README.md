# ARMM Modset Manager for Pterodactyl 2.x

Native **ARMM Modsets** tab for Arma Reforger servers in Pterodactyl 2.x.

**Version:** 1.1.1 · **Verified by operator:** Pterodactyl `v2.0.0-beta.2` (UI, modset listing and config sync). This repository contains the version that was reported working; the Git-ready packaging/build flow is newly prepared and has not been executed against the operator's machine.

## Features

- Configure ARMM server URL and credentials centrally in **Admin → Extensions**.
- Fetch accessible modsets from `GET /api/modsets` and select one per server.
- Store per-server assignment in `ext_armm_modsets_assignments`.
- **config.json synchronisieren** reads the selected modset's `/api/modsets/{id}/export`, backs up `config.json` as `config.json.armm.bak`, and replaces only `game.mods` through Wings.
- Preserve mod version fields returned by ARMM when the corresponding setting is enabled.
- Does **not** require the old `armm-sync.sh` or the ARMM Egg startup variables.

**Not supported:** automatic synchronization on every server start. This extension synchronizes only when the button is clicked. It is intended for administrators, and sync should be performed with the game server stopped. Permissions use an admin-only check in backend routes.

## Quick start

```bash
git clone <YOUR-REPOSITORY-URL> armm-modsets
cd armm-modsets
./scripts/build.sh
```

Find the resulting distributable in `build/armm-modsets-v1.1.1.zip`. See [Build](docs/BUILD.md) and [Install / Update](docs/INSTALL.md).

## Requirements

- Pterodactyl `v2.0.0-beta.2` (target version), PHP 8.4 in the Panel
- Linux host with Docker, Git, Python 3 and zip; internet access for Docker images/npm/GitHub
- Working Panel ↔ Wings connection and reachable ARMM HTTPS endpoint
- Access to the Pterodactyl Docker Compose host for installation

## Repository structure

```text
extension.json        Extension manifest
src/                  PHP backend and React tab
routes/               Extension API routes
database/migrations/   Assignment table
scripts/build.sh      Isolated Docker-based build
docs/BUILD.md          Build requirements and checks
docs/INSTALL.md        Install, upgrade, verify, rollback
docs/CONFIGURATION.md  ARMM settings and usage
.gitignore            Excludes credentials and generated artifacts
```

## Security notes

Never commit `.env`, login cookies, API passwords, Laravel `APP_KEY`, Wings tokens, real server `config.json`, or database dumps. The build does not require any of these files. Extension configuration resides within the Panel, not in this repository. The server receives only the mod list via the file API. `tls_verify=1` is recommended.

## Support scope

This is a dedicated integration for the currently tested Pterodactyl beta. New Panel betas may change SDK or file contracts and require a rebuild and code adaptation. PHP syntax checks and TypeScript compilation are not substitutes for integration tests.
