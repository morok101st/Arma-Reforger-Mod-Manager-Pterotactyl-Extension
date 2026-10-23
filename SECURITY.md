# Security Policy

## Supported versions

Only the latest release published on the [Releases page](https://github.com/morok101st/Arma-Reforger-Mod-Manager-Pterotactyl-Extension/releases) receives security fixes.

## Reporting a vulnerability

Please do **not** open a public issue. Report vulnerabilities privately through
**Security → Report a vulnerability** in this repository (GitHub private vulnerability reporting).
Include the affected version, reproduction steps, and the expected impact.

## Verifying release packages

Each release ZIP ships with a `.sha256` checksum and a GitHub build provenance attestation:

```bash
sha256sum -c armm-modsets-v<version>.zip.sha256
gh attestation verify armm-modsets-v<version>.zip --repo morok101st/Arma-Reforger-Mod-Manager-Pterotactyl-Extension
```
