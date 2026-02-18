# Changelog

All notable changes for EasyDcim-BW are documented here.

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
