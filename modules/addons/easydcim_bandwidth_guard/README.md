# EasyDCIM Bandwidth Guard (WHMCS Addon)

Production-safe WHMCS addon for cycle-aware traffic enforcement, one-click Git updates, and complete traffic purchase audit logs.

## Key Features

- Cycle-aware traffic calculation from `nextduedate` and billing cycle.
- EasyDCIM API integration for bandwidth, ports, suspend/unsuspend, and graph export.
- Commit-based version string: `1.0.<commit_time>+<short_sha>`.
- Auto update check (`git ls-remote`) + one-click admin update (`git pull --ff-only`).
- Strict additive/idempotent migrations only on `mod_easydcim_bw_*` tables.
- Purchase logs include invoice, cycle window, reset date, actor, and timestamp.
- Admin package management and client one-time per-cycle purchase flow.
- Auto-buy from client credit with max-purchases-per-cycle guard.
- Client chart view with cache.

## Install

1. Copy `modules/addons/easydcim_bandwidth_guard` into WHMCS.
2. Activate from WHMCS Addon Modules.
3. Configure API settings and managed PID/GID scope.
4. Ensure `hooks.php` is loaded.

## Database Safety

- No changes to `tblhosting`, `tblinvoices`, or other WHMCS core tables.
- Migrations are idempotent and additive (`CREATE TABLE IF NOT EXISTS`, `ADD COLUMN IF NOT EXISTS` behavior).

## Cron Behavior

- `AfterCronJob` runs poll (interval-driven) and update-check (interval-driven).
- Service-level lock prevents race conditions.
- Poll handles up to 1000 managed services per run.

## Update Mode

Default mode is `check_oneclick`:

- Cron checks for new commit.
- Admin sees availability in dashboard.
- Admin applies update with one click.
