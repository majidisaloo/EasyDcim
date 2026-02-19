# EasyDcim-BW (WHMCS Addon)

Production-safe WHMCS addon for cycle-aware traffic enforcement, one-click Git updates, and complete traffic purchase audit logs.

Current version: `1.25`

## Key Features

- Cycle-aware traffic calculation from `nextduedate` and billing cycle.
- EasyDCIM API integration for bandwidth, ports, suspend/unsuspend, and graph export.
- Port enforcement resolves port IDs by service, with fallback by `easydcim_server_id`.
- Short version from `modules/addons/easydcim_bw/VERSION` (e.g. `1.16`) with git metadata when available.
- Auto update check from GitHub releases + one-click admin update without `shell_exec`.
- Strict additive/idempotent migrations only on `mod_easydcim_bw_guard_*` tables.
- Deactivate keeps all module data/settings by default; optional `Purge Data On Deactivate` removes only module tables/settings.
- Purchase logs include invoice, cycle window, reset date, actor, and timestamp.
- Admin UI is tab-based (`Dashboard`, `Easy DCIM`, `Settings`, `Services / Group`, `Servers`, `Packages`, `Logs`) with color-coded status cards.
- Test mode (dry run) logs intended enforcement commands without sending real suspend/port actions.
- Configurable log retention with automatic cleanup in cron and manual cleanup in Logs tab.
- Added `Scope` tab for managed `PID/GID` and per-plan quota rules (`IN/OUT/TOTAL` + unlimited toggles).
- Client area now supports per-service auto-buy preferences and shows cycle reset remaining days.
- Service override fields are now available directly in WHMCS admin service page.
- Admin package management and client one-time per-cycle purchase flow.
- Auto-buy from client credit with max-purchases-per-cycle guard.
- Client chart view with cache.

## Install

1. Copy `modules/addons/easydcim_bw` into WHMCS.
2. Activate from WHMCS Addon Modules.
3. Configure API settings and managed PID/GID scope.
4. Ensure `hooks.php` is loaded.

## Database Safety

- No schema changes to `tblhosting`, `tblinvoices`, or other WHMCS core tables.
- Migrations are idempotent and additive (`CREATE TABLE IF NOT EXISTS`, `ADD COLUMN IF NOT EXISTS` behavior).
- Dedicated table prefix (`mod_easydcim_bw_guard_*`) avoids conflicts with older `easydcim_bw` addons.

## Cron Behavior

- `AfterCronJob` runs poll (interval-driven) and update-check (interval-driven).
- Service-level lock prevents race conditions.
- Poll handles up to 1000 managed services per run.

## Update Mode

Default mode is `check_oneclick`:

- Cron/check action checks latest GitHub release.
- Admin sees availability in dashboard.
- Admin applies latest release zip with one click (no shell access required).
