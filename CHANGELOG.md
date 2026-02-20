# Changelog

All notable changes for EasyDcim-BW are documented here.

## [1.93] - 2026-02-20
### Fixed
- Servers batch test now auto-continues reliably without requiring manual `Continue` clicks:
  - added server-side auto-refresh fallback while queue is running
  - kept existing form-based continue flow for CSP/theme compatibility.
- Improved port-state detection by considering numeric status values from nested `selected/connected` refs, reducing false/unstable status indicators.
- Improved `Unassigned Services` filtering so non-final statuses (such as pending/rejected) remain visible for reconciliation workflows; only final terminal states are excluded.
### Added
- Added per-port traffic badge in Servers port status rows for quick visibility of traffic volume on detected ports.
- Added richer row details in Servers table:
  - connected switch/item summary
  - connected port labels
  - detected link speed summary
- Added domain/label hint under client name in assigned-services rows for easier visual identification.

## [1.92] - 2026-02-20
### Changed
- Servers tab is now bandwidth-first:
  - `Used/Remaining/Effective Allowed` columns are shown directly in the main list.
  - technical fields (`Product ID`, `IP`, `Order ID`, `EasyDCIM Service/Server`) moved into per-row **Details**.
- Added clearer in-tab hint for traffic visibility and usage review flow.
### Fixed
- Port speed normalization now handles unrealistic raw values (like `1000000G`) and maps them to plausible interface rates (such as `1G` / `10G`).
- Improved interface/port label extraction from nested EasyDCIM payloads so switch-side names/descriptions are preferred over raw numeric-only IDs.
- Improved port state inference when API returns numeric status fields, reducing `(Unknown)` states.
- Added new Servers-table UI styles for traffic values and details panel readability.

## [1.91] - 2026-02-19
### Fixed
- Servers batch test flow is now fully form-based (no fragile AJAX fallback chain), so `Stop`/`Reset` actions reliably clear queue state.
- Batch processing size was increased to 5 services per click to reduce repeated manual steps while keeping request load controlled.
- Port status rendering now enriches rows with `GET /api/v3/admin/ports/{id}` details when available:
  - better port display labels
  - speed badge extraction (`1G`/`10G` when provided)
  - improved state normalization (`Up/Idle/Down`).
### Added
- New **Traffic** admin tab to view per-service usage/remaining/effective limit, cycle window, and last check time in one table.

## [1.90] - 2026-02-19
### Fixed
- Port discovery now prefers the actual `data` payload from EasyDCIM admin responses (instead of parsing wrapper metadata), reducing false `No ports found` results.
- Added stronger port parsing for real-world order/server payload structures:
  - supports `selected_port`, `connected_port`, and `connected_item` blocks/strings
  - extracts IDs from labels like `#1070 ...` / `#1014 ...`
  - improves `connected_port_label` and status derivation (`Up/Idle/Down`).
- Added new server-details fallback (`/api/v3/admin/servers/{id}` and related item endpoints) when direct ports endpoints are unavailable.
- Cached unsupported order-ports endpoints to avoid repeated 404 probes on every test and reduce bulk-test latency.
- Added richer debug context (`port_paths`) in `resolved_ports_from_order_empty` logs for faster troubleshooting on edge payloads.

## [1.89] - 2026-02-19
### Fixed
- Added new server-port fallback through `/api/v3/admin/ports` with `item_id` search when direct server/order port endpoints are unavailable.
- Improved port payload parsing for paginated/nested API responses so admin list responses can be converted to port rows correctly.
- Strengthened fallback extraction path by recursively parsing nested structures when direct rows are empty.

## [1.88] - 2026-02-19
### Fixed
- Bulk test flow no longer short-circuits permanently on `order_id_only:bulk`; it now continues with service/server fallback probes in the same run.
- Added no-impersonation fallback for server-based port lookup when server endpoints return `401/403/422`.
- AJAX batch call now prioritizes dedicated `api=servers_batch` endpoint first for more stable JSON responses.

## [1.87] - 2026-02-19
### Fixed
- Added hard fallback submit path for `Test All` when AJAX response is invalid/blocked, so bulk test never stays idle.
- Improved Servers-tab batch UX resilience: keeps message/state and auto-recovers by switching to standard action when needed.
- Extended server-port lookup candidates with admin endpoints:
  - `/api/v3/admin/items/{id}/ports`
  - `/api/v3/admin/servers/{id}/ports`

## [1.86] - 2026-02-19
### Fixed
- `Test All` in Servers tab now runs fully via AJAX without forced page-refresh fallback loops during normal operation.
- Stop/Reset/Refresh actions in Servers tab are now handled asynchronously and keep the queue controls visible/usable.
- Added extra order-based port lookup fallback via admin endpoints (`/api/v3/admin/orders/{id}/ports` and `/api/v3/admin/orders/{id}/service/ports`) when order details payload has no direct port rows.
- Expanded port-row detection heuristics for nested payloads (`interfaces`, `networkinterfaces`, `switchports`, `links`, and additional connection keys).

## [1.85] - 2026-02-19
### Fixed
- Reduced repeated `admin/orders/{id}` calls inside service test flow by adding runtime cache for order-details, order-service mapping, and order-ports extraction.
- Optimized single/batch test path to avoid unnecessary `order_api` resolution when service candidates are already present from cache/custom fields.
- Added faster `order_only` short-circuit path for single-service tests to prevent repetitive `403/422` client-ports retries when endpoint mode is already known.
- Improved batch JS runner behavior on inconsistent AJAX state: it now pauses with clear status (instead of forced reload/auto-submit loops), keeping controls usable.
### Changed
- Synchronized root and addon README version markers with module version for release workflow compatibility.

## [1.84] - 2026-02-19
### Fixed
- Reduced repeated `admin/orders/{id}` calls inside service test flow by adding runtime cache for order-details, order-service mapping, and order-ports extraction.
- Optimized single/batch test path to avoid unnecessary `order_api` resolution when service candidates are already present from cache/custom fields.
- Added faster `order_only` short-circuit path for single-service tests to prevent repetitive `403/422` client-ports retries when endpoint mode is already known.
- Improved batch JS runner behavior on inconsistent AJAX state: it now pauses with clear status (instead of forced reload/auto-submit loops), keeping controls usable.

## [1.83] - 2026-02-19
### Changed
- Servers batch tester now learns blocked client-ports access mode and prioritizes `order`-based path in bulk runs, reducing redundant API calls and improving progress speed.
### Fixed
- `Stop` and `Reset` controls in Servers batch UI are now always visible and no longer disappear during state transitions.

## [1.82] - 2026-02-19
### Fixed
- Hardened Servers batch JS runner against zero/invalid AJAX state while queue still has work.
- If AJAX payload is inconsistent (or danger) but pending progress is expected, runner now auto-submits `Continue` form instead of stopping.
- Prevents UI regression where `Stop/Reset` disappears and queue remains stuck at low progress.

## [1.81] - 2026-02-19
### Fixed
- Added robust batch-test auto-recovery in Servers tab:
  - detects stalled progress (`done` not moving)
  - automatically falls back to normal form submit (`Continue`) when AJAX polling stalls
  - keeps queue moving even under admin JS/plugin/theme conflicts.

## [1.80] - 2026-02-19
### Fixed
- Servers batch runner now auto-resumes reliably on page load when progress state exists (`done < total`) even if stale `running` flag is false.
- Restores queue controls and continues polling, preventing freeze at early progress states like `1/163`.

## [1.79] - 2026-02-19
### Fixed
- Added final fail-safe for service test: if HTTP `422` occurs but `order_id` exists, test is downgraded to `order_id_only` instead of hard failure.
- Fixed Servers tab live controls visibility to keep queue controls active while `done < total` even if `running` flag desyncs.
- Prevents batch queue freeze at early progress states (e.g. `1/163`).

## [1.78] - 2026-02-19
### Fixed
- Enforced deterministic downgrade to `order_details_only` when ports endpoint returns `401/403/422` but `admin/orders/{id}` is reachable.
- `portsFromOrderDetails()` now returns explicit `reachable` state to prevent false `mode=none` failures on valid order mappings.

## [1.77] - 2026-02-19
### Fixed
- Manual/Batch service test now downgrades to `order_details_only` when:
  - `client/services/{id}/ports` returns `401/403/422`
  - and `admin/orders/{order_id}` is reachable (`HTTP 200`).
- This prevents hard-fail on environments where client ports endpoint is restricted for that account while admin order mapping is valid.

## [1.76] - 2026-02-19
### Fixed
- Improved order-details ports fallback parser to detect more EasyDCIM payload variants (`number`, `port_number`, `user_label`, connection keys, and `ports` parent context).
- Added diagnostic log `resolved_ports_from_order_empty` with top-level keys when order details return no parsable ports.
- Helps resolve manual test cases that stayed on HTTP 422 despite valid `order_id/server_id` mapping.

## [1.75] - 2026-02-19
### Fixed
- Batch service test now retries `client/services/{id}/ports` without impersonation on HTTP `401/403/422` (same as manual test path).
- Fixes warnings like `Impersonated user account is inactive` during `Test All` when impersonated EasyDCIM user is disabled.

## [1.74] - 2026-02-19
### Fixed
- Fixed stuck batch state in Servers tab where progress could remain (e.g. `1/163`) while `Stop/Reset` controls disappeared.
- Running-state detection now uses both `remaining` and `done < total`.
- Added queue self-recovery when legacy/corrupt state has progress counters but empty queue.

## [1.73] - 2026-02-19
### Fixed
- Manual single-service test now retries `client/services/{id}/ports` without impersonation on HTTP `422` (in addition to `401/403`) before final failure.
- This reduces false-negative test failures when a resolved service ID is valid in admin context but rejected under current impersonation constraints.

## [1.72] - 2026-02-19
### Fixed
- Strengthened Servers batch AJAX transport for strict WHMCS admin environments:
  - primary endpoint now uses real form action URL
  - request payload now includes CSRF token automatically (from hidden input or URL when present)
  - module/tab parameters are always injected in POST payload
- This resolves cases where `Test All` still returned HTML (`<!DOCTYPE html>`) instead of JSON.

## [1.71] - 2026-02-19
### Fixed
- Fixed batch `Test All` transport to avoid HTML responses in AJAX loop:
  - switched background batch calls to `POST` with `FormData` on current admin URL
  - fallback batch endpoint also uses `POST` JSON-accept requests
- This resolves repeated `All services test failed - invalid server response [<!DOCTYPE html ...]` caused by admin query/session routing edge cases.

## [1.70] - 2026-02-19
### Fixed
- Fixed `All services test failed - invalid server response` in Servers batch test:
  - batch AJAX URLs now preserve full current admin query parameters (including WHMCS admin token/session params)
  - this prevents HTML admin/login pages from being returned to JS where JSON was expected.

## [1.69] - 2026-02-19
### Fixed
- Hardened Servers batch AJAX against JS/response conflicts:
  - primary call via standard action endpoint
  - automatic fallback to `api=servers_batch` when primary response is non-JSON
- Error status now shows a short raw response snippet for faster diagnosis when a proxy/theme/plugin injects HTML into AJAX responses.

## [1.68] - 2026-02-19
### Fixed
- Added direct AJAX handlers for `action=test_all_services|stop|reset|refresh` with guaranteed JSON responses.
- Batch runner JS now calls the standard addon action endpoints (with `ajax=1`) instead of relying only on `api=servers_batch`, improving compatibility on stricter WHMCS/admin setups.

## [1.67] - 2026-02-19
### Fixed
- Batch background runner in Servers tab now calls the batch API via lightweight `GET` polling to avoid non-JSON POST responses that triggered generic `All services test failed`.
- Improved stability of auto-run path after starting `Test All` with one-page reload.

## [1.66] - 2026-02-19
### Fixed
- `Unassigned Services` mapping now uses only explicit WHMCS mappings (custom field/service-state), not inferred runtime matches; this prevents false “everything mapped” cases.
- `Important Warnings` now flags:
  - services that resolve to an EasyDCIM service but have no explicit mapping saved
  - explicit-vs-detected service ID mismatches
### Added
- New warning when EasyDCIM cache is empty, with clear instruction to run `Refresh Cache`.

## [1.65] - 2026-02-19
### Fixed
- Servers batch controls now follow mixed behavior for stability:
  - `Test All`, `Stop`, `Reset`, `Refresh Cache` submit normally and reload once.
  - ongoing batch execution continues via background AJAX without full-page refresh.
- Fixed UX bug where batch could start but UI controls/progress would not immediately reflect state until manual reload.

## [1.64] - 2026-02-19
### Fixed
- Reworked `Servers` batch-test AJAX to use a dedicated lightweight endpoint (`api=servers_batch`) so `Test All` no longer fails with generic `All services test failed`.
- Added robust JSON parsing fallback in browser-side batch calls to avoid hard failure on unexpected response bodies.
### Changed
- Batch operations (`test`, `continue`, `stop`, `reset`, `refresh cache`) now run through one consistent AJAX API path.

## [1.63] - 2026-02-19
### Added
- Ports status now shows per-port lines with visual state dots:
  - green: up with traffic
  - yellow: up but idle
  - red: down/inactive
- Connected port label/name is now shown in status details when available from EasyDCIM payload.
### Changed
- Unassigned/non-connected port rows are filtered from visual list when connected rows exist, so only actionable connected ports stay visible.
- Port-row detection/parsing was hardened to avoid non-port objects being miscounted as ports in order-details fallback payloads.

## [1.62] - 2026-02-19
### Changed
- Servers `Test All` flow is now AJAX-driven (no full page refresh on each batch step).
- Bulk progress now updates live inside the same page while queue runs.
- Added live button-state behavior:
  - running: show `Continue`, `Stop`, `Reset`
  - stopped/finished: hide those controls and keep `Test All`.
- `Refresh Cache` action in Servers tab is now AJAX-backed for smoother UX.

## [1.61] - 2026-02-19
### Added
- `Unassigned Services` table now shows `iLO IP` and `Label` (when available from EasyDCIM payloads).
### Changed
- Important Warnings now compares unassigned EasyDCIM items against mapped WHMCS services using `iLO IP`, service IP, and label/domain similarity.
- Added new warning type for likely unmapped-but-matching services to help fast manual correction.

## [1.60] - 2026-02-19
### Fixed
- Removed duplicate bulk-progress notice in Servers tab by suppressing top flash when queue is in-progress.
- Added explicit `Stop` action for bulk test queue; stopping now clears queue state cleanly.
### Changed
- Queue controls now behave as:
  - Active queue: `Continue`, `Stop`, `Reset`
  - Stopped/completed queue: only `Test All`
- Auto-continue timer in bulk test flow set to 1 second for smoother sequential execution.
- Updated action labels to reflect one-by-one continuation (`Continue (1 by 1)` / `ادامه تست (تکی)`).

## [1.59] - 2026-02-19
### Performance
- `Test All` now runs strictly one service per request (`chunkSize=1`) for smoother server load.
- Added robust auto-continue path with `autobatch=1` + timed refresh, so bulk testing no longer gets stuck at first batch.

## [1.58] - 2026-02-19
### Fixed
- Fixed order-details port parsing path where fallback payload was wrapped as `{ports: [...]}` but parser only read `data/result`; this caused false `No ports found` despite resolved order ports.

## [1.57] - 2026-02-19
### Fixed
- `Test All` bulk flow optimized to avoid heavy fallback calls in batch mode (no slow server-scan/order-scan paths during bulk checks).
- Added auto-continue for bulk test queue (batch-by-batch) to keep each request short and avoid admin-page hangs.
- Unassigned Services list now filters out non-actionable rows (pending/rejected/fraud/no locator) to prevent noisy/unstable output.

## [1.56] - 2026-02-19
### Fixed
- Dashboard `Update Actions` buttons now render side-by-side again (horizontal layout), instead of stacked vertically.

## [1.55] - 2026-02-19
### Changed
- Logs tab action buttons (`Cleanup Logs Now`, `Delete All Logs`) are now grouped in one horizontal card-style row for cleaner UI.

## [1.54] - 2026-02-19
### Changed
- `Server ID` is now prioritized in service test flow:
  - resolve `order_id` from `server_id` when order is missing
  - prefer `admin/orders/{id}` port extraction when server/order mapping exists
- Added cached resolver for `server_id -> order_id` with diagnostic logs.
### Fixed
- Improved extraction/logging of real port connection identifiers for troubleshooting:
  - `port_ids` (server-side port id)
  - `connected_port_ids` (connected switch port id)
  - `connected_item_ids` (connected switch/item id)

## [1.53] - 2026-02-19
### Fixed
- `Test All` no longer runs all services in one long request; it now executes in queue mode with small batches (5 services per run) to prevent admin hang/timeouts.
- Added queue controls for bulk testing:
  - Continue batch test
  - Reset test queue
  - Live progress counters (done/total, ok/warn/fail)

## [1.52] - 2026-02-19
### Fixed
- Clarified Servers table `PID` meaning by renaming it to `Product ID` (WHMCS product identifier, not port ID).
- Improved port parsing from EasyDCIM payloads (`client/services/.../ports` and order-details fallback) to capture:
  - `port_id`
  - `connected_item_id` (switch/item)
  - `connected_port_id`
  - broader up-state detection (`accepted` and related flags)
### Changed
- `server_item_test` logs now include extracted `port_ids`, `connected_port_ids`, and `connected_item_ids` for precise troubleshooting.

## [1.51] - 2026-02-19
### Fixed
- Resolved `servers cache refresh` SQL failure on older installs where `mod_easydcim_bw_guard_service_state.easydcim_server_id` did not exist.
- `persistResolvedMappings()` is now schema-aware and safely skips `easydcim_server_id` writes when column is missing.
- Added additive migration to create missing `easydcim_server_id` column in `service_state`.
### Changed
- Servers tab action buttons are now grouped side-by-side in clean action cards.

## [1.50] - 2026-02-19
### Fixed
- Unassigned Services table now filters out malformed/empty API rows to prevent random blank rows and unstable display.
- Rows that only contain `service_id` with transient statuses (`accepted/pending/rejected`) and no other identifiers are excluded from unassigned list.
### Changed
- Unassigned Services table now includes `Order ID` column for better traceability.

## [1.49] - 2026-02-19
### Added
- Added cross-system client identity comparison (WHMCS vs EasyDCIM) in `Important Warnings`.
- Sync/cache parsing now extracts EasyDCIM client `name/email` from both `client/services` and `admin/orders` payloads (including nested client/user/customer objects).
### Changed
- Scoped mapped service rows now carry resolved EasyDCIM client identity for mismatch detection.

## [1.48] - 2026-02-19
### Added
- Added `Test All` action in Servers tab to run row test for all scoped services in one click and summarize `OK/WARN/FAIL`.
### Changed
- Renamed Servers refresh action label to clarify it refreshes cache and completes missing mappings.
- Servers cache refresh now also persists resolved `service/order/server` mappings into module service-state where possible.
### Fixed
- Added missing i18n keys for new Servers actions and status messages in both Persian and English.

## [1.47] - 2026-02-19
### Fixed
- Servers cache builder now ignores non-2xx `admin/orders` pages and filters out non-order payloads to prevent fake `items=1` cases and incomplete cache results.
- Scoped list now fills `EasyDCIM Service` from local `order_service_map_*` cache when direct mapping is missing.
- Servers table alignment tightened with fixed layout + centered link rendering.

## [1.46] - 2026-02-19
### Fixed
- Added fallback port extraction from `admin/orders/{id}` payload when `client/services/{id}/ports` is blocked (`403/422`) or unavailable.
- `server_item_test` now keeps working without hardcoded service exceptions by trying service/order/server/IP candidate mapping paths.
- Improved API error propagation: decoded API error message is now captured and logged even when cURL error is empty.
### Changed
- `admin/orders` cache sync now reads up to 5 pages to improve mapping coverage on larger installations.

## [1.45] - 2026-02-19
### Fixed
- Test flow now builds `service_id` candidates from all available sources (`service CF`, `order cache`, `server cache`, `IP cache`, `order API`) and tries them in sequence.
- Added cross-check correction for mismatched `order_id` and `service_id` during scoped service mapping; mismatch is auto-corrected to trusted cache map and logged.
- For unresolved service cases, test now attempts `server_id` lookup as a fallback without hardcoded exceptions.
- Added clearer message for environments where Server-ID ports endpoint is not supported (`HTTP 404`).
### Changed
- EasyDCIM cache merge logic now always contributes `admin/orders` records to improve order/service/server matching quality.

## [1.44] - 2026-02-19
### Fixed
- Added persistent `order_id -> service_id` cache (including negative cache) to avoid repeated heavy resolution calls for unresolved orders like `342`.
- `Servers -> Test` now falls back to `server_id` port lookup when service-based lookup is denied/invalid (`401/403/422`) and server mapping exists.
- Prevented false port counts from API error payloads (error objects are no longer interpreted as one fake port row).
### Changed
- EasyDCIM list cache now always merges admin-order sourced items, not only when direct client list is empty.

## [1.43] - 2026-02-19
### Fixed
- `Servers -> Test` no longer throws hard exception on `403/401` for `client/services/{id}/ports`; response is handled gracefully with explicit permission message.
- Added automatic fallback port test without impersonation when first attempt is denied.
- Health Check now treats `easydcim_service_id` as optional (warning only), while keeping `order_id/server_id` as primary required mapping identifiers.

## [1.42] - 2026-02-19
### Fixed
- `Servers -> Test` now stores and shows per-service port test result directly in the table, so `Ports Status` is no longer stuck on `No data` after a successful test.
- Test output is now explicit for three cases: network ports found, only non-network ports found, or no ports returned.
### Performance
- Test action now reads scoped services from local cache-first mapping path and avoids triggering expensive per-row API resolution.

## [1.41] - 2026-02-19
### Fixed
- Health Check custom-field coverage now accepts both product custom fields and product configurable options (`Service ID`, `Order ID`, `Server ID`) so scoped products are detected correctly.
- Preflight retest now clears the health-check cache before recomputing.
### Performance
- Servers tab is now cache-first and no longer performs live EasyDCIM list calls during page render.
- Added manual `Sync servers now` action for controlled refresh.
- Removed per-row API service-id resolution from list rendering paths.
- Added short health-check result cache to reduce repeated heavy calculations.

## [1.40] - 2026-02-19
### Changed
- Added a dedicated `Health Check` tab and moved heavy preflight/custom-field coverage checks there.
- Moved Important Warnings panel from Dashboard to `Health Check` to keep Dashboard fast on large WHMCS datasets.
- Health tab now includes runtime/cron status cards, preflight retest, and warning table in one place.
### Performance
- Dashboard no longer runs scope-wide custom-field counting on each load.

## [1.39] - 2026-02-19
### Fixed
- Preflight custom-field checks are now scope-aware with counts per field (`configured/total`) and warning state for partial coverage.
- Servers table alignment improved: headers and row values are centered consistently.
- Service extraction now reads nested order IDs (`order.id` / `order.order_id`) to improve mapping.
- Updater now validates write access earlier and reports directory/file permission issues more explicitly.
### Changed
- Scoped hosting mapping now attempts `Order ID -> Service ID` resolution even when port lookup is disabled, with per-order cache to avoid repeated API calls.

## [1.38] - 2026-02-19
### Fixed
- `Servers -> Test` no longer returns ambiguous `HTTP 0` when service ID is missing; now reports explicit unresolved-service reason.
- Added order-to-service fallback scan through `client/services` pages when `admin/orders/{id}` does not expose `service_id`.
### Added
- New diagnostics logs for unresolved cases:
  - `resolve_service_id_from_order_scan_failed`
  - `resolve_service_id_from_order_empty`
  - `resolved_service_id_from_order` now includes `via` source.

## [1.37] - 2026-02-19
### Fixed
- Immediate updater now fails explicitly if no files are actually written (prevents false-success message).
- Added strict write checks for every extracted file and directory creation in release apply flow.
- Added post-apply version verification against release tag (`installed` vs `expected`) to detect path/permission mismatch immediately.

## [1.36] - 2026-02-19
### Fixed
- Removed server/item port fallback from enforcement flow to stop mass invalid API calls (`/client/servers/*/ports`, `/client/items/*/ports`).
- Test mode now avoids per-port discovery calls entirely and logs intended action as a single dry-run entry.
### Changed
- Port enable/disable in real mode is now strictly service-based (`/client/services/{id}/ports` discovery only).

## [1.35] - 2026-02-19
### Fixed
- Stopped repeated invalid port lookups by server/item endpoints in servers workflow when `service_id` is missing.
- Servers mapping now resolves missing `EasyDCIM Service ID` from `Order ID` before any port request.
### Changed
- Added admin orders list fallback (`/api/v3/admin/orders`) when `/client/services` returns empty.
- Added new diagnostics logs: `servers_list_orders_summary` and `servers_list_orders_failed`.
- Port status lookup now prioritizes service-based endpoint only, with order-based service resolution fallback.

## [1.34] - 2026-02-19
### Fixed
- Servers `Test` action no longer uses unsupported server-port fallback endpoints as primary path; it now resolves `service_id` from `order_id` and tests `/client/services/{id}/ports`.
- Scoped service lookup for `Test` now uses fast mode (no bulk port lookups), preventing test-action hangs.
- Improved service-list parsing for additional v3 payload layouts (`records`, `rows`, `services`, `collection`).
### Changed
- Servers fetch now retries with impersonated client emails if direct list is empty, without `filter=active` hard constraint.
- Added admin order-details helper for resolving missing `EasyDCIM Service ID` from known `Order ID`.
- Added logs for order->service resolution path (`resolved_service_id_from_order`, `resolve_service_id_from_order_failed`).

## [1.33] - 2026-02-19
### Changed
- `Apply Latest Release` is immediate again (click-to-apply) and no longer depends on cron queue execution.
- Update action now sets `update_in_progress` state during apply and clears it in `finally` for safer recovery on failure.
### Fixed
- Cleared stale queued-update flag after successful immediate apply.
- Added explicit success/failure logs for click-based release apply flow.

## [1.32] - 2026-02-19
### Fixed
- Aligned EasyDCIM bandwidth request body with v3 docs: `startDate` / `endDate` (instead of non-standard keys).
- Normalized EasyDCIM base URL input by removing trailing `/backend` automatically for API calls.
- Servers tab no longer runs expensive per-service port lookups during list render (prevents hangs/timeouts on large scopes).
### Changed
- Servers list fetch now uses API-filtered paging (`filter=active`, `per_page`, `page`) with strict 5-second request budget.
- Added short cache for servers list (`servers_list_cache_*`) to avoid repeated API calls on rapid tab refresh.
- Service-item extraction now supports v3-style payloads with `related_id` and `related.ip_addresses`.

## [1.31] - 2026-02-19
### Fixed
- Removed automatic outbound release-check call from admin page load to prevent blocking/timeouts while opening module UI.
- Dashboard `Important Warnings` no longer triggers EasyDCIM API calls during render.
- Dashboard connection card now reads cached runtime state only (no live network probe on page load).
### Changed
- Added explicit cached connection-state writer during `Test EasyDCIM Connection` action.
- Scoped hosting loader now supports disabling per-service port lookup for fast DB-only rendering paths.

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
