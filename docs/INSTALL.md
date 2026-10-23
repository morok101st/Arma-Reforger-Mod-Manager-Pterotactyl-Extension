# Install / Update through the Pterodactyl UI

Download the release package `armm-modsets-v<release-version>.zip` from the [GitHub Releases page](https://github.com/morok101st/Arma-Reforger-Mod-Manager-Pterotactyl-Extension/releases). Do **not** use GitHub's automatically generated **Source code** ZIP; it is not an installable extension package.

These instructions require an administrator account in a compatible Pterodactyl Panel. The exact wording of upload or install controls can vary between Panel beta versions.

## Fresh installation

1. In the Panel, open **Admin → Extensions**.
2. Use the Extensions UI to upload `armm-modsets-v<release-version>.zip`.
3. Install the uploaded package and enable **ARMM Modset Manager** (`armm-modsets`).
4. Confirm that the extension is listed as installed and enabled, and that its displayed version matches the downloaded release.

## Update an existing installation

1. Create a **Panel database backup** before updating.
2. Download the desired newer release package from GitHub Releases.
3. In **Admin → Extensions**, use the UI update/replace flow for the existing `armm-modsets` extension and upload the new package.
4. Confirm that the extension is enabled after the update and that the displayed version is correct.
5. Do not remove the extension as part of a normal update. Its settings and per-server assignments should be preserved.

## Configure and test

1. Open **Admin → Extensions → ARMM Modset Manager** and configure the ARMM URL, username, password, TLS validation, and version preservation.
2. Open an Arma Reforger server and select **ARMM Modsets**.
3. Wait for the modset list to load, select a modset, and save the assignment. Click **Refresh Modsets** only when you need to force a refresh from ARMM.
4. Stop the game server and make an additional backup of its `config.json` if required by your operating procedures.
5. Click **Synchronize config.json**.
6. Confirm that only `game.mods` changed and that a `config.json.armm-<YYYYMMDD-HHMMSS>.bak` backup exists. Start the server and validate the selected mods.

The sync is manual; starting a server does **not** fetch or apply a new modset.

## Troubleshooting and rollback

- If installation, configuration, or sync fails, reproduce the issue and inspect the Panel's Laravel logs using your Panel deployment's normal log-access method.
- If a release malfunctions, disable the extension in **Admin → Extensions**.
- To roll back, install or update to a previously working release package through the same UI, then enable it and verify the version.
- Restore the Panel database backup if the update changed data unexpectedly.
- Restore a game configuration from the matching `config.json.armm-<YYYYMMDD-HHMMSS>.bak` backup only when appropriate.
- Avoid removing the extension during rollback: removal can delete extension settings or data.

## Building your own package

Normal installations should use a published release package. If you need to build from source, follow [BUILD.md](BUILD.md), then upload the generated `build/armm-modsets-v<release-version>.zip` through the Extensions UI.
