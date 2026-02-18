# EasyDcim-BW

Single WHMCS addon module for EasyDCIM bandwidth control.

## Repository Layout

This repository is intentionally laid out for direct extraction into WHMCS `public_html`:

- `modules/addons/easydcim_bw`

If you extract a release package in WHMCS root (`public_html`), files will land in the correct place automatically.

## Quick Install

1. Download a release package (`EasyDcim-BW-<version>.zip`).
2. Extract it directly in WHMCS root (`public_html`).
3. Activate addon module `EasyDcim-BW` from WHMCS admin.
4. Open addon page and run **Preflight Retest**.
5. Fix missing items one-by-one (API token, custom fields, PID/GID scope, ...).

## Versioning Rule

Version format is short and commit-based:

- `major.minor` where `minor` is two digits (`01..99`)
- every 100 commits increments `major`

Examples:

- commit 2 -> `1.02`
- commit 99 -> `1.99`
- commit 101 -> `2.01`

## Changelog

See [CHANGELOG.md](CHANGELOG.md).

## Release Packaging

Use:

```bash
bash scripts/build_release_zip.sh
```

Output package is generated in `dist/` and is ready to extract in WHMCS root.
