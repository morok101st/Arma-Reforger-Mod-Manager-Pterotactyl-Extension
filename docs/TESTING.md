# Integration tests

The extension is tested through Pterodactyl's own Pest integration-test environment. This is required because the extension depends on Panel models, extension routing, settings, permissions, and Laravel services.

GitHub Actions runs these tests automatically for every push, pull request, tag, and manual workflow run. The workflow provisions a temporary MySQL test database and a Pterodactyl `v2.0.0-beta.2` checkout before building the release package. A failed test prevents the build and release job from running.

## Covered behavior

`tests/panel/ArmmModsetsExtensionTest.php` covers:

- server-owner and `file.update` subuser access;
- rejection of users without `file.update`;
- ARMM list normalization and the 10-minute cache;
- forced list refresh;
- persisting the selected modset ID and display name;
- rejection of unavailable modset IDs; and
- `config.json` synchronization, including the backup and `game.mods` replacement.

## Run the tests locally (optional)

Local execution is optional because GitHub Actions runs the suite automatically. Use a Pterodactyl `v2.0.0-beta.2` checkout with its Composer development dependencies installed and a configured **testing** database. The Panel test bootstrap refuses to run against a database whose name does not contain `test`.

```bash
# In a prepared Panel checkout
composer install

# From this extension repository
PANEL_DIR=/path/to/pterodactyl-panel ./scripts/test.sh
```

The script temporarily copies the extension and its Pest test file into the Panel test fixture directory, runs only the ARMM test file, and removes the temporary files afterward. It does not modify a production Panel installation.
