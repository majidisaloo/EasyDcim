# EasyDcim

WHMCS addon module for EasyDCIM bandwidth lifecycle management.

## Added module

This repository now includes an addon at:

- `modules/addons/easydcim_bandwidth`

### Key capabilities

- Detects eligible services by Product IDs / Group IDs and EasyDCIM custom fields.
- Calculates usage by real service cycle (`nextduedate` window).
- Enforces limits using EasyDCIM Admin APIs:
  - Disable / enable ports
  - Suspend / unsuspend service by order ID
- Supports one-time additional traffic packages that are valid only during the current cycle.
- Supports permanent per-service overrides (quota, mode, action).
- Supports graph export caching for client area usage charts.
- Supports auto-buy (from client credit) when remaining traffic is below threshold.
- Includes cron hooks for recurring reconciliation.

## Install (WHMCS)

1. Copy `modules/addons/easydcim_bandwidth` into your WHMCS installation.
2. Activate the addon from **System Settings → Addon Modules**.
3. Configure API/base settings in addon configuration.
4. Set product defaults and optional service overrides from addon admin page.

## Important custom fields (per service/product)

- `easydcim_order_id`
- `easydcim_service_id`
- `easydcim_server_id` (optional)
- `traffic_mode` (optional)
- `base_quota_override_gb` (optional)
