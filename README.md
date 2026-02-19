# EasyDcim-BW

Single WHMCS addon module for EasyDCIM bandwidth control.

Current module version: `1.76`

## Repository Layout

This repository is intentionally laid out for direct extraction into WHMCS `public_html`:

- `modules/addons/easydcim_bw`

If you extract a release package in WHMCS root (`public_html`), files will land in the correct place automatically.

## Quick Install

1. Download a release package from GitHub Releases.
2. Extract it directly in WHMCS root (`public_html`).
3. Activate addon module `EasyDcim-BW` from WHMCS admin.
4. Open addon page and run **Preflight Retest**.
5. Fix missing items one-by-one (API token, custom fields, PID/GID scope, ...).

## Changelog

See [CHANGELOG.md](CHANGELOG.md).
