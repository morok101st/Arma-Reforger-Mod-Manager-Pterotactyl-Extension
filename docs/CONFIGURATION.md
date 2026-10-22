# Configuration & behavior

Install and enable the extension through **Admin → Extensions** before configuring it. The following settings are available under **Admin → Extensions → ARMM Modset Manager**.

## Admin → Extensions → ARMM Modset Manager

| Setting | Purpose | Recommended |
|---|---|---|
| `url` | ARMM HTTPS base URL (without `/api`) | Valid HTTPS URL |
| `username` | ARMM API account | Dedicated `webinterface` account with modset access |
| `password` | ARMM password | Secret; never commit |
| `tls_verify` | Validate certificate | `1` |
| `keep_versions` | Keep `version` from ARMM export | `1` (ARMM controls versions) |

ARMM endpoints: `POST /api/auth/login`, `GET /api/modsets`, and `GET /api/modsets/{id}/export`. The ARMM account must have access to each modset. Otherwise the export may return HTTP 404 despite a successful login.

## Manual synchronization sequence

1. Open the server's **ARMM Modsets** tab. The available modsets load automatically from the server-side cache.
2. Click **Sync Modsets** to force a refresh from ARMM when you need the latest choices.
3. Select the desired entry and save the assignment. The selected modset ID and last known name are stored for the server.
4. Stop Reforger and click **Synchronize config.json**.
5. Backend authenticates with ARMM, downloads the mod list, validates mod IDs, reads `config.json` via Wings, writes `config.json.armm.bak`, updates only `game.mods`, and records sync time/count.

Your other settings (e.g., ACE settings, server name, scenario) are left structurally unchanged. The JSON file may be reformatted during serialization. Only the latest `config.json.armm.bak` is retained, so keep separate historical backups if needed.

The standard Reforger startup may remain:

```bash
sed -i 's/"true"/true/g; s/"false"/false/g' config.json && exec ./ArmaReforgerServer -gproj ./addons/data/ArmaReforger.gproj -config ./config.json -profile ./profile -backendlog -nothrow -listScenarios -logStats $(({{LOG_INTERVAL}}*1000)) -maxFPS {{MAX_FPS}} -addonTempDir ./tmp {{START_PARAM}}
```

## Current limitations

- Manual button only, no pre-start hook.
- Server owners and subusers with the `file.update` permission can manage modsets. Extension-wide ARMM settings remain available only in the Admin area.
- The ARMM modset list is cached server-side for 10 minutes. **Sync Modsets** bypasses that cache and refreshes it.
- The API list parser supports a plain list or `data` / `modsets` / `items` wrapper.
- Sync is designed for offline game servers; no automatic restart or scheduling.
