# Changelog

All notable changes for EasyDcim-BW are documented here.

## [1.17] - 2026-02-19
### Added
- New tabbed admin navigation: `Dashboard`, `Settings`, `Packages`, and `Logs`.
- Color-coded status cards with SVG icons for health/runtime states (green/amber/red/gray).
- New `test_mode` (dry run) setting to log exact enforcement API commands without applying them.
- New `log_retention_days` setting and logs cleanup action in admin logs tab.
### Changed
- Update actions moved to dashboard quick actions while update configuration remains in settings.
- Logs tab now shows system logs and traffic purchase logs with retention visibility.
- Cron now performs automatic logs retention cleanup based on configured days.

## [1.16] - 2026-02-19
### Added
- New safe deactivate option: `Purge Data On Deactivate` in addon dashboard settings.
- Explicit `VERSION` file in addon path to keep version stable on non-git/manual deployments.
### Changed
- Deactivate now preserves module data/settings by default for safe manual file replacement.
- Admin dashboard UI refreshed for cleaner cards/forms/tables and better readability.
- Addon README aligned with release-based update flow and deactivate behavior.
### Fixed
- Activation now tolerates non-fatal `There is no active transaction` warning path on some WHMCS environments.

## [1.15] - 2026-02-19
### Added
- Manual GitHub release update path without `shell_exec` (download latest release zip and apply addon files).
### Changed
- Update check/source shifted to GitHub releases for compatibility with shared hosting limits.

## [1.14] - 2026-02-19
### Fixed
- Hardened GitHub release workflow reliability and deployment version fallback behavior.

## [1.13] - 2026-02-19
### Changed
- Moved addon settings UI from WHMCS Addon Modules config into module dashboard.
- Added health/status dashboard (cron, sync, EasyDCIM connectivity, limited services).
- Added in-dashboard settings save and EasyDCIM connection test (safe error reporting).
- Removed local `scripts/` packaging flow and moved release asset build to GitHub Actions.
### Fixed
- Version fallback on non-git deployment no longer shows `1.01` unexpectedly.

## [1.12] - 2026-02-19
### Added
- Root README for direct repository landing page documentation.
### Changed
- Release and install instructions aligned with extract-in-`public_html` workflow.

## [1.11] - 2026-02-19
### Fixed
- Short semantic versioning (`major.minor`) from commit count.
- Activation failure on some fresh WHMCS installs (`There is no active transaction`).
- Added preflight panel and retest flow in addon admin UI.

## [1.10] - 2026-02-19
### Changed
- Kept a single English README under addon path.

## [1.09] - 2026-02-19
### Changed
- Unified repository to a single module: `modules/addons/easydcim_bw`.
- Removed duplicated addon implementations.

## [1.08] - 2026-02-19
### Fixed
- Production hardening updates for compatibility and safety.

## [1.07] - 2026-02-19
### Added
- Initial full EasyDcim-BW addon implementation with cycle logic, enforcement, purchase flow, graph cache, git update checks.
