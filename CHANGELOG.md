# Changelog

All notable changes for EasyDcim-BW are documented here.

## [1.30] - 2026-02-19
### Fixed
- EasyDCIM runtime calls now use explicit `CONNECTTIMEOUT` and low-speed fail-safe options to prevent long blocking requests.
- Dashboard connection probe now uses a strict 5-second timeout.
- Manual release check action timeout reduced to 5 seconds to avoid long admin-page waits.
### Changed
- Connection runtime status is cached for 60 seconds (`conn_runtime_cache_*`) so repeated admin page loads do not trigger repeated network probes.
- Auto release refresh now stores check timestamp even on failure, preventing repeated back-to-back external calls when GitHub is unreachable.

## [1.29] - 2026-02-19
### Fixed
- Resolved admin-side update timeout path that could lead to `504 Gateway Timeout` on shared/proxy setups.
- Release HTTP calls now use stricter connect/low-speed timeouts to fail fast on bad outbound connectivity.
### Changed
- `Apply Latest Release` is now non-blocking: it queues update request immediately instead of downloading/extracting inside the admin web request.
- Actual release download/extract runs in `AfterCronJob` with lock protection and `update_in_progress` state updates.
- Added structured logs for queued-release apply results (`release_update_applied`, `release_update_apply_failed`).

## [1.28] - 2026-02-19
### Fixed
- Service list parsing now supports nested payload formats (`data.items`, `data.data`, `result.items`, `result.data`) to avoid empty-list false negatives.
- Added fallback servers loading via impersonated client service listing when direct admin-token client list returns empty.
### Added
- API call logs now include full request URL for easier endpoint-path validation.
- Servers tab now shows an explicit hint when API list is empty despite configured connection.
- Servers diagnostics now log list-summary events for direct/impersonated fetch attempts.

## [1.27] - 2026-02-19
### Fixed
- Servers tab now supports wider custom-field aliases (`Order ID`, `Server ID`, `Service ID`) so mapped data is detected more reliably.
- Per-service API test action added and logs detailed result/error into module logs for faster troubleshooting.
- API lookup failures in servers mapping are now explicitly logged (`servers_ports_lookup_failed`, `server_item_test`, `server_item_test_exception`).
### Changed
- Servers table now includes per-row `Test` action.
- Important warnings are consolidated per service (multiple issues in one line instead of repeated rows).
- Logs details column now keeps larger context payload (up to 1200 chars) for API debugging.

## [1.26] - 2026-02-19
### Fixed
- Removed stale commit-based update flag usage from dashboard update card (now release-state only).
- Update state now auto-resolves to up-to-date when installed version matches latest release tag.
### Changed
- Removed separate release-status duplication from dashboard cards (single update status card retained).
- EasyDCIM connection test now treats reachable-but-restricted client endpoint responses as a valid connectivity state.
- Dashboard connection state now distinguishes:
  - Connected
  - Configured (limited access)
  - Configured but disconnected

## [1.25] - 2026-02-19
### Fixed
- Dashboard update-state card now follows release-update status only (removed stale commit-update flag path that could show false yellow state).
- Servers view now filters to `Active` and `Suspended` services only (no pending/cancelled rows).
### Added
- Clickable links in servers table for service and client (direct WHMCS admin pages).
- `Important Warnings` section under preflight with actionable alerts.
### Changed
- Servers matching logic expanded to use `Order ID`, `Server ID`, `Service ID`, and IP fallback.
- Order ID is now shown in servers table.
- Client ID display removed from servers table (name-only display with link).
- Network-port analysis now excludes management ports (`iLO/iDRAC/BMC/IPMI/MGMT/KVM`) for warnings and status summaries.
- Added warnings for:
  - shared EasyDCIM server among multiple active services,
  - active service with all network ports down,
  - active service with no network traffic.

## [1.24] - 2026-02-19
### Added
- `Allow Self-Signed SSL` option in EasyDCIM connection tab (enabled by default) for IP-based/self-signed deployments.
- Global update-available banner across module tabs when a newer release exists.
### Changed
- Dashboard EasyDCIM connection card now shows runtime connectivity state: `Connected` or `Configured but disconnected` (warning).
- Update availability is refreshed on each module page load with a 10-second timeout fail-safe.
- Connection save button label simplified to `Save`/`ذخیره`.
- Update mode dropdown labels are now localized for Persian/English.
- Connection token field now shows masked placeholder when a token already exists.
- Removed visible hardcoded update-source hint text from settings UI.
### Fixed
- EasyDCIM client now supports self-signed SSL in admin/client/cron calls via cURL SSL verify controls.

## [1.23] - 2026-02-19
### Fixed
- In scope plan editor, checking `Unlimited` now immediately clears the paired quota input value and disables it.
- EasyDCIM proxy form no longer blocks save/test when proxy is disabled (proxy port/browser validation conflict removed).
### Changed
- Proxy fields now auto-toggle UI state: when proxy is off, related fields are disabled and grayed out; when on, they re-enable.

## [1.22] - 2026-02-19
### Fixed
- Scope plan saving flow hardened: auto-save now posts through standard `save_product_plan` action with AJAX flag, avoiding route/token mismatch issues in some WHMCS environments.
- Save feedback is now resilient when server returns non-JSON output.
### Changed
- Scope labels are localized (`IN/OUT/TOTAL` now shown as `دانلود/آپلود/مجموع` in Persian UI).
- Preflight table/details were further localized for Persian mode (`Available/Configured/Missing/Not configured` coverage).

## [1.21] - 2026-02-19
### Added
- New `Servers` tab with two sections: WHMCS-mapped services and unassigned EasyDCIM services.
- Server/service matching fallback by client/service IP when EasyDCIM IDs are not already stored.
- Port status summary in servers view (up count / total) with service/server fallback lookup.
### Changed
- Scope plan editor now uses robust row-based AJAX autosave with explicit save feedback and a working `Save All Plans` action.
- Unlimited toggles now reliably disable and gray out paired quota fields.
- Connection access naming changed to clearer mode labels: `Restricted Mode` / `Unrestricted Mode`.
- EasyDCIM connection test now works with current form values even before saving.
- Admin RTL behavior improved for Persian UI rendering and layout consistency.
- Dashboard, settings, scope, and preflight sections expanded with Persian translations and clearer labels.
### Fixed
- Removed remaining practical dependency messaging around git shell mode from health checks (release updater is shell-free).
- Corrected invalid scope table form markup that caused inconsistent save/disable behavior in some browsers.

## [1.20] - 2026-02-19
### Added
- Renamed tabs to `Easy DCIM` and `Services / Group` for clearer navigation.
- New module runtime status field in WHMCS admin service page showing traffic-limited vs normal active state.
- Auto-save behavior in Services/Group plan rows (saves on change) and unlimited checkboxes now disable their paired quota inputs.
### Changed
- EasyDCIM connection actions are now grouped together; connection test can run from the same section without a separate save flow.
- Module enabled control switched to dropdown (`Active` / `Disable`) for explicit temporary toggling.
- Services/Group plan table simplified by removing per-row mode selector (global mode setting is authoritative).
- Minor responsive/admin style cleanup for tighter table/form layout.

## [1.19] - 2026-02-19
### Added
- New dedicated `Connection` tab for EasyDCIM settings and connectivity tests.
- Proxy support for EasyDCIM API requests (`HTTP`, `HTTPS`, `SOCKS4`, `SOCKS5`) with optional authentication.
- Global module enable/disable switch for temporary pause without uninstalling.
- UI language selection (`Default`, `English`, `Farsi`) for admin/client rendering.
- Scope page now loads products from selected `GID/PID` and shows custom-field readiness checks (`service/order/server`).
### Changed
- `Suspended (other reasons)` now counts only services inside configured scope (`PID/GID`), not all WHMCS services.
- Scope quota UI upgraded to per-product plan editor (`IN/OUT/TOTAL`, unlimited toggles, mode/action defaults).
- EasyDCIM tests and runtime calls now use configured proxy settings when enabled.
### Fixed
- Eliminated practical dependency on `shell_exec` for update checks in shared hosting usage path.
- Improved settings-save isolation per tab (general vs connection) to avoid unintended resets.

## [1.18] - 2026-02-19
### Added
- New admin `Scope` tab for managed `PID/GID` and per-plan quota rules by mode (`IN`, `OUT`, `TOTAL`) with unlimited option.
- New client auto-buy preferences per service (enabled, threshold, package, max per cycle).
- WHMCS admin service-page override fields via hooks (`AdminServicesTabFields` / save hook).
- Global direction mapping option (`normal` / `swap`) for environments with reversed SNMP semantics.
### Changed
- Git shell mode is no longer required for update checks; release-based update flow is the primary path.
- Cron heartbeat status now relies on WHMCS cron ping and marks unhealthy if not seen in last 6 minutes.
- Logs tab now includes both retention cleanup and delete-all actions.
### Fixed
- Fixed `shell_exec`-related git updater runtime issue in shared-hosting environments.
- Fixed log cleanup behavior by adding explicit delete-all action and retention cleanup reliability.

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
