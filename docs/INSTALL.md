# Installation / Update (Docker Compose)

These commands assume the Docker Compose stack is located at **`/data/ptero2`** and the Panel service is called `panel`.

## 1. Build

Follow [BUILD.md](BUILD.md), creating:

`/data/ptero2/armm-extension-build/armm-modsets-repo/build/armm-modsets-v1.1.1.zip`

## 2. Upload to Panel container and run doctor

```bash
cd /data/ptero2
PACKAGE=armm-extension-build/armm-modsets-repo/build/armm-modsets-v1.1.1.zip
mkdir -p armm-extension-build/armm-modsets-doctor
unzip -oq "$PACKAGE" -d armm-extension-build/armm-modsets-doctor
docker compose cp armm-extension-build/armm-modsets-doctor/. panel:/tmp/armm-modsets-doctor/
docker compose exec panel php artisan p:extension:doctor /tmp/armm-modsets-doctor
```

Do not continue if doctor reports an error.

## 3a. Fresh installation

```bash
docker compose cp "$PACKAGE" panel:/tmp/armm-modsets-v1.1.1.zip
docker compose exec panel php artisan p:extension:install /tmp/armm-modsets-v1.1.1.zip
docker compose exec panel php artisan p:extension:enable armm-modsets
docker compose exec panel php artisan p:extension:list
```

## 3b. Upgrade existing extension

Take a **Panel database backup** first. Preserve Extension settings and assignments. Do not drop its assignment table.

```bash
docker compose cp "$PACKAGE" panel:/tmp/armm-modsets-v1.1.1.zip
docker compose exec panel php artisan p:extension:disable armm-modsets
docker compose exec panel php artisan p:extension:install /tmp/armm-modsets-v1.1.1.zip --replace
docker compose exec panel php artisan p:extension:enable armm-modsets
docker compose exec panel php artisan p:extension:list
```

Expected: `armm-modsets | ARMM Modset Manager | 1.1.1 | yes | enabled`.

## 4. Configure and test

1. Admin → Extensions → ARMM Modset Manager: configure URL, login, password, TLS validation, version preservation.
2. Open a Reforger server → **ARMM Modsets**.
3. Click **Sync Modsets**, choose a modset, **Zuordnung speichern**.
4. Stop the game server, back up its `config.json`, then click **config.json synchronisieren**.
5. Confirm only `game.mods` changed and `config.json.armm.bak` exists; start the server and validate the mods.

The sync is manual; simply starting a server will **not** fetch a new modset.

## Troubleshooting and rollback

For a 500 error, inspect Laravel logs after reproducing:

```bash
cd /data/ptero2
docker compose exec panel sh -c 'tail -n 120 "$(ls -t /app/storage/logs/laravel*.log | head -1)"'
```

The previously fixed error `DaemonFileRepository does not exist` occurred in v1.1.0; v1.1.1 uses `ReadsFileContents` / `WritesFileContents`.

If the new version malfunctions, disable it:

```bash
docker compose exec panel php artisan p:extension:disable armm-modsets
```

Restore a prior package with `p:extension:install <archive> --replace` and `p:extension:enable armm-modsets`; restore Panel DB backups if a migration has modified data. For the game configuration, restore `config.json` from `config.json.armm.bak` only if appropriate.

**Avoid `p:extension:remove` during rollback:** it may remove extension data/settings.
