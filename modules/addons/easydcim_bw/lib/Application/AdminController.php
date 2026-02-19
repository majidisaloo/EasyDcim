<?php

declare(strict_types=1);

namespace EasyDcimBandwidthGuard\Application;

use EasyDcimBandwidthGuard\Config\Settings;
use EasyDcimBandwidthGuard\Infrastructure\EasyDcimClient;
use EasyDcimBandwidthGuard\Infrastructure\Git\GitUpdateManager;
use EasyDcimBandwidthGuard\Support\Crypto;
use EasyDcimBandwidthGuard\Support\Logger;
use EasyDcimBandwidthGuard\Support\Version;
use WHMCS\Database\Capsule;

final class AdminController
{
    private const RELEASE_REPO = 'majidisaloo/EasyDcim';
    private Settings $settings;
    private Logger $logger;
    private string $moduleDir;
    private bool $isFa;

    public function __construct(Settings $settings, Logger $logger, string $moduleDir)
    {
        $this->settings = $settings;
        $this->logger = $logger;
        $this->moduleDir = $moduleDir;
        $configured = strtolower($this->settings->getString('ui_language', 'auto'));
        if (in_array($configured, ['fa', 'farsi', 'persian'], true)) {
            $lang = 'farsi';
        } elseif (in_array($configured, ['en', 'english'], true)) {
            $lang = 'english';
        } else {
            $lang = strtolower((string) ($_SESSION['adminlang'] ?? $_SESSION['Language'] ?? 'english'));
        }
        $this->isFa = str_starts_with($lang, 'farsi') || str_starts_with($lang, 'persian') || str_starts_with($lang, 'fa');
    }

    public function handle(array $vars): void
    {
        $action = $_REQUEST['action'] ?? '';
        $api = isset($_GET['api']) ? (string) $_GET['api'] : '';
        $tab = (string) ($_REQUEST['tab'] ?? 'dashboard');
        $flash = [];

        if ($api === 'purchase_logs') {
            $this->json($this->getPurchaseLogs(300));
            return;
        }

        if ($api === 'enforcement_logs') {
            $this->json($this->getEnforcementLogs(300));
            return;
        }

        if ($action === 'apply_update') {
            $flash[] = ['type' => 'warning', 'text' => 'Git shell update is disabled. Use Release update actions.'];
        }
        if ($action === 'add_package') {
            $this->addPackage();
            $tab = 'packages';
        }
        if ($action === 'save_override') {
            $this->saveOverride();
            $tab = 'packages';
        }
        if ($action === 'save_settings') {
            $flash[] = $this->saveSettings();
            $tab = 'settings';
        }
        if ($action === 'save_connection') {
            $flash[] = $this->saveSettings();
            $tab = 'connection';
        }
        if ($action === 'test_easydcim') {
            $flash[] = $this->testEasyDcimConnection();
            $tab = 'connection';
        }
        if ($action === 'run_preflight') {
            $flash[] = ['type' => 'info', 'text' => 'Preflight retest completed.'];
            $tab = 'dashboard';
        }
        if ($action === 'check_release_update') {
            $flash[] = $this->checkReleaseUpdate();
            $tab = 'dashboard';
        }
        if ($action === 'apply_release_update') {
            $flash[] = $this->applyReleaseUpdate();
            $tab = 'dashboard';
        }
        if ($action === 'cleanup_logs') {
            $flash[] = $this->cleanupLogsNow();
            $tab = 'logs';
        }
        if ($action === 'cleanup_logs_all') {
            $flash[] = $this->cleanupLogsAllNow();
            $tab = 'logs';
        }
        if ($action === 'save_scope') {
            $flash[] = $this->saveScopeSettings();
            $tab = 'scope';
        }
        if ($action === 'save_product_default') {
            $flash[] = $this->saveProductDefault();
            $tab = 'scope';
        }
        if ($action === 'save_product_plan') {
            $flash[] = $this->saveProductPlan();
            $tab = 'scope';
        }

        $this->settings = new Settings(Settings::loadFromDatabase());
        $version = Version::current($this->moduleDir);

        echo '<link rel="stylesheet" href="../modules/addons/easydcim_bw/assets/admin.css">';
        echo '<div class="edbw-wrap">';
        echo '<div class="edbw-header">';
        echo '<h2>EasyDcim-BW</h2>';
        echo '<p>' . htmlspecialchars($this->t('subtitle')) . '</p>';
        echo '</div>';

        foreach ($flash as $msg) {
            echo '<div class="alert alert-' . htmlspecialchars($msg['type']) . '">' . htmlspecialchars($msg['text']) . '</div>';
        }

        $moduleLink = (string) ($vars['modulelink'] ?? '');
        $this->renderTabs($moduleLink, $tab);

        if ($tab === 'settings') {
            $this->renderSettingsTab();
        } elseif ($tab === 'connection') {
            $this->renderConnectionTab();
        } elseif ($tab === 'scope') {
            $this->renderScopeTab();
        } elseif ($tab === 'packages') {
            $this->renderPackagesTab();
        } elseif ($tab === 'logs') {
            $this->renderLogsTab();
        } else {
            $this->renderDashboardTab($version, $moduleLink);
        }

        echo '</div>';
    }

    private function renderTabs(string $moduleLink, string $activeTab): void
    {
        $tabs = [
            'dashboard' => $this->t('tab_dashboard'),
            'connection' => $this->t('tab_connection'),
            'settings' => $this->t('tab_settings'),
            'scope' => $this->t('tab_scope'),
            'packages' => $this->t('tab_packages'),
            'logs' => $this->t('tab_logs'),
        ];

        echo '<div class="edbw-tabs">';
        foreach ($tabs as $key => $label) {
            $class = $key === $activeTab ? 'edbw-tab active' : 'edbw-tab';
            $url = $moduleLink !== '' ? $moduleLink . '&tab=' . rawurlencode($key) : '?tab=' . rawurlencode($key);
            echo '<a class="' . $class . '" href="' . htmlspecialchars($url) . '">' . htmlspecialchars($label) . '</a>';
        }
        echo '</div>';
    }

    private function renderDashboardTab(array $version, string $moduleLink): void
    {
        $updateAvailable = Capsule::table('mod_easydcim_bw_guard_meta')->where('meta_key', 'update_available')->value('meta_value') === '1';
        $lastPollAt = (string) Capsule::table('mod_easydcim_bw_guard_meta')->where('meta_key', 'last_poll_at')->value('meta_value');
        $apiFailCount = (int) Capsule::table('mod_easydcim_bw_guard_meta')->where('meta_key', 'api_fail_count')->value('meta_value');
        $updateLock = Capsule::table('mod_easydcim_bw_guard_meta')->where('meta_key', 'update_in_progress')->value('meta_value') === '1';
        $releaseTag = (string) Capsule::table('mod_easydcim_bw_guard_meta')->where('meta_key', 'release_latest_tag')->value('meta_value');
        $releaseAvailable = Capsule::table('mod_easydcim_bw_guard_meta')->where('meta_key', 'release_update_available')->value('meta_value') === '1';
        $checks = $this->buildHealthChecks();
        $checkMap = [];
        foreach ($checks as $row) {
            $checkMap[$row['name']] = $row['ok'];
        }

        echo '<div class="edbw-metrics">';
        $this->renderMetricCard('Version', (string) $version['module_version'], 'ok', '<svg viewBox="0 0 24 24"><path d="M12 3l8 4v10l-8 4-8-4V7l8-4z"></path></svg>');
        $this->renderMetricCard('Commit', (string) $version['commit_sha'], 'neutral', '<svg viewBox="0 0 24 24"><path d="M12 2a5 5 0 015 5v2h1a4 4 0 014 4v5h-2v-5a2 2 0 00-2-2h-1v2a5 5 0 11-10 0v-2H6a2 2 0 00-2 2v5H2v-5a4 4 0 014-4h1V7a5 5 0 015-5z"></path></svg>');
        $this->renderMetricCard('Update Status', $updateAvailable ? 'New commit available' : 'Up to date', $updateAvailable ? 'warn' : 'ok', '<svg viewBox="0 0 24 24"><path d="M12 4v8m0 0l3-3m-3 3L9 9M5 14a7 7 0 1014 0"></path></svg>');
        $this->renderMetricCard('Release Status', $releaseAvailable ? 'Update available' : 'Up to date', $releaseAvailable ? 'warn' : 'ok', '<svg viewBox="0 0 24 24"><path d="M6 4h12v4H6zM5 10h14v10H5zM10 14h4"></path></svg>');
        $this->renderMetricCard('Cron Poll', $lastPollAt !== '' ? $lastPollAt : 'No data', $lastPollAt !== '' ? 'ok' : 'error', '<svg viewBox="0 0 24 24"><path d="M12 6v6l4 2"></path><circle cx="12" cy="12" r="9"></circle></svg>');
        $this->renderMetricCard('API Fail Count', (string) $apiFailCount, $apiFailCount > 0 ? 'error' : 'ok', '<svg viewBox="0 0 24 24"><path d="M12 3l9 18H3zM12 9v4m0 4h.01"></path></svg>');
        $this->renderMetricCard('Update Lock', $updateLock ? 'Locked' : 'Free', $updateLock ? 'warn' : 'ok', '<svg viewBox="0 0 24 24"><path d="M7 11V8a5 5 0 1110 0v3"></path><rect x="5" y="11" width="14" height="10" rx="2"></rect></svg>');
        $this->renderMetricCard('EasyDCIM Connection', (($checkMap['EasyDCIM Base URL'] ?? false) && ($checkMap['EasyDCIM API Token'] ?? false)) ? 'Configured' : 'Not configured', (($checkMap['EasyDCIM Base URL'] ?? false) && ($checkMap['EasyDCIM API Token'] ?? false)) ? 'ok' : 'error', '<svg viewBox="0 0 24 24"><path d="M4 12a8 8 0 0116 0M8 12a4 4 0 018 0"></path><circle cx="12" cy="16" r="1"></circle></svg>');

        foreach ($this->buildRuntimeStatus() as $card) {
            $this->renderMetricCard($card['label'], $card['value'], $card['state'], $card['icon']);
        }

        $this->renderMetricCard('Latest Release', $releaseTag !== '' ? $releaseTag : 'Unknown', $releaseTag !== '' ? 'ok' : 'neutral', '<svg viewBox="0 0 24 24"><path d="M5 4h14v16H5zM9 8h6M9 12h6M9 16h4"></path></svg>');
        echo '</div>';

        echo '<div class="edbw-panel">';
        echo '<h3>Update Actions</h3>';
        echo '<div class="edbw-actions">';
        echo '<form method="post" class="edbw-form-inline"><input type="hidden" name="tab" value="dashboard"><input type="hidden" name="action" value="check_release_update"><button class="btn btn-default" type="submit">Check Update Now</button></form>';
        echo '<form method="post" class="edbw-form-inline"><input type="hidden" name="tab" value="dashboard"><input type="hidden" name="action" value="apply_release_update"><button class="btn btn-primary" type="submit">Apply Latest Release</button></form>';
        echo '</div>';
        echo '</div>';

        $this->renderPreflightPanel();
    }

    private function renderMetricCard(string $title, string $value, string $state, string $iconSvg): void
    {
        echo '<div class="edbw-card edbw-state-' . htmlspecialchars($state) . '">';
        echo '<div class="edbw-card-icon">' . $iconSvg . '</div>';
        echo '<div class="edbw-card-body">';
        echo '<div class="edbw-card-title">' . htmlspecialchars($title) . '</div>';
        echo '<div class="edbw-card-value">' . htmlspecialchars($value) . '</div>';
        echo '</div>';
        echo '</div>';
    }

    private function renderSettingsTab(): void
    {
        $s = $this->settings;
        echo '<div class="edbw-panel">';
        echo '<h3>Module Settings</h3>';
        echo '<form method="post" class="edbw-settings-grid">';
        echo '<input type="hidden" name="tab" value="settings">';
        echo '<input type="hidden" name="action" value="save_settings">';
        echo '<div class="edbw-form-inline"><label>Module Status</label><select name="module_enabled"><option value="1"' . ((string) $s->getString('module_enabled', '1') === '1' ? ' selected' : '') . '>Active</option><option value="0"' . ((string) $s->getString('module_enabled', '1') === '0' ? ' selected' : '') . '>Disable</option></select><span class="edbw-help">Disable temporarily without losing data.</span></div>';
        echo '<div class="edbw-form-inline"><label>UI Language</label><select name="ui_language"><option value="auto"' . ($s->getString('ui_language', 'auto') === 'auto' ? ' selected' : '') . '>Default</option><option value="english"' . ($s->getString('ui_language', 'auto') === 'english' ? ' selected' : '') . '>English</option><option value="farsi"' . ($s->getString('ui_language', 'auto') === 'farsi' ? ' selected' : '') . '>فارسی</option></select></div>';
        echo '<div class="edbw-form-inline"><label>Poll Interval (min)</label><input type="number" min="5" name="poll_interval_minutes" value="' . (int) $s->getInt('poll_interval_minutes', 15) . '"></div>';
        echo '<div class="edbw-form-inline"><label>Graph Cache (min)</label><input type="number" min="5" name="graph_cache_minutes" value="' . (int) $s->getInt('graph_cache_minutes', 30) . '"></div>';

        echo '<div class="edbw-form-inline"><label>Auto-Buy Enabled</label><input type="checkbox" name="autobuy_enabled" value="1" ' . ($s->getBool('autobuy_enabled') ? 'checked' : '') . '></div>';
        echo '<div class="edbw-form-inline"><label>Auto-Buy Threshold GB</label><input type="number" min="1" name="autobuy_threshold_gb" value="' . (int) $s->getInt('autobuy_threshold_gb', 10) . '"></div>';
        echo '<div class="edbw-form-inline"><label>Auto-Buy Default Package ID</label><input type="number" min="0" name="autobuy_default_package_id" value="' . (int) $s->getInt('autobuy_default_package_id', 0) . '"></div>';
        echo '<div class="edbw-form-inline"><label>Auto-Buy Max/Cycle</label><input type="number" min="1" name="autobuy_max_per_cycle" value="' . (int) $s->getInt('autobuy_max_per_cycle', 5) . '"></div>';

        echo '<div class="edbw-form-inline"><label>Update Source</label><span class="edbw-help">Hardcoded to GitHub release: majidisaloo/EasyDcim</span></div>';
        echo '<div class="edbw-form-inline"><label>Update Mode</label><select name="update_mode">';
        foreach (['notify', 'check_oneclick', 'auto'] as $mode) {
            echo '<option value="' . $mode . '"' . ($s->getString('update_mode', 'check_oneclick') === $mode ? ' selected' : '') . '>' . $mode . '</option>';
        }
        echo '</select></div>';
        echo '<div class="edbw-form-inline"><label>Direction Mapping</label><select name="traffic_direction_map"><option value="normal"' . ($s->getString('traffic_direction_map', 'normal') === 'normal' ? ' selected' : '') . '>Normal</option><option value="swap"' . ($s->getString('traffic_direction_map', 'normal') === 'swap' ? ' selected' : '') . '>Swap IN/OUT</option></select><span class="edbw-help">Use swap if EasyDCIM IN/OUT is reversed on your network devices.</span></div>';
        echo '<div class="edbw-form-inline"><label>Default Calculation Mode</label><select name="default_calculation_mode"><option value="TOTAL"' . ($s->getString('default_calculation_mode', 'TOTAL') === 'TOTAL' ? ' selected' : '') . '>TOTAL (IN+OUT)</option><option value="IN"' . ($s->getString('default_calculation_mode', 'TOTAL') === 'IN' ? ' selected' : '') . '>IN only</option><option value="OUT"' . ($s->getString('default_calculation_mode', 'TOTAL') === 'OUT' ? ' selected' : '') . '>OUT only</option></select></div>';

        echo '<div class="edbw-form-inline"><label>Test Mode (Dry Run)</label><input type="checkbox" name="test_mode" value="1" ' . ($s->getBool('test_mode', false) ? 'checked' : '') . '><span class="edbw-help">No real suspend/disable/enable/unsuspend calls; logs show what would be sent.</span></div>';
        echo '<div class="edbw-form-inline"><label>Log Retention (days)</label><input type="number" min="1" name="log_retention_days" value="' . (int) $s->getInt('log_retention_days', 30) . '"></div>';
        echo '<div class="edbw-form-inline"><label>Preflight Strict Mode</label><input type="checkbox" name="preflight_strict_mode" value="1" ' . ($s->getBool('preflight_strict_mode', true) ? 'checked' : '') . '></div>';
        echo '<div class="edbw-form-inline"><label>Purge Data On Deactivate</label><input type="checkbox" name="purge_on_deactivate" value="1" ' . ($s->getBool('purge_on_deactivate', false) ? 'checked' : '') . '><span class="edbw-help">If enabled, all `mod_easydcim_bw_guard_*` tables and module settings are deleted on deactivate.</span></div>';

        echo '<button class="btn btn-primary" type="submit">Save Settings</button>';
        echo '</form>';
        echo '</div>';
    }

    private function renderConnectionTab(): void
    {
        $s = $this->settings;
        echo '<div class="edbw-panel">';
        echo '<h3>EasyDCIM Connection</h3>';
        echo '<form method="post" class="edbw-settings-grid">';
        echo '<input type="hidden" name="tab" value="connection">';
        echo '<div class="edbw-form-inline"><label>EasyDCIM Base URL</label><input type="text" name="easydcim_base_url" value="' . htmlspecialchars($s->getString('easydcim_base_url')) . '" size="70"></div>';
        echo '<div class="edbw-form-inline"><label>Admin API Token</label><input type="password" name="easydcim_api_token" value="" placeholder="Leave empty to keep current token" size="70"></div>';
        echo '<div class="edbw-form-inline"><label>Use Impersonation</label><input type="checkbox" name="use_impersonation" value="1" ' . ($s->getBool('use_impersonation', false) ? 'checked' : '') . '></div>';
        echo '<h4>Proxy</h4>';
        echo '<div class="edbw-form-inline"><label>Enable Proxy</label><input type="checkbox" name="proxy_enabled" value="1" ' . ($s->getBool('proxy_enabled', false) ? 'checked' : '') . '></div>';
        echo '<div class="edbw-form-inline"><label>Proxy Type</label><select name="proxy_type"><option value="http"' . ($s->getString('proxy_type', 'http') === 'http' ? ' selected' : '') . '>HTTP</option><option value="https"' . ($s->getString('proxy_type', 'http') === 'https' ? ' selected' : '') . '>HTTPS</option><option value="socks5"' . ($s->getString('proxy_type', 'http') === 'socks5' ? ' selected' : '') . '>SOCKS5</option><option value="socks4"' . ($s->getString('proxy_type', 'http') === 'socks4' ? ' selected' : '') . '>SOCKS4</option></select></div>';
        echo '<div class="edbw-form-inline"><label>Proxy Host</label><input type="text" name="proxy_host" value="' . htmlspecialchars($s->getString('proxy_host')) . '"></div>';
        echo '<div class="edbw-form-inline"><label>Proxy Port</label><input type="number" min="1" name="proxy_port" value="' . (int) $s->getInt('proxy_port', 0) . '"></div>';
        echo '<div class="edbw-form-inline"><label>Proxy Username</label><input type="text" name="proxy_username" value="' . htmlspecialchars($s->getString('proxy_username')) . '"></div>';
        echo '<div class="edbw-form-inline"><label>Proxy Password</label><input type="password" name="proxy_password" value="" placeholder="Leave empty to keep current password"></div>';
        echo '<div class="edbw-actions">';
        echo '<button class="btn btn-primary" type="submit" name="action" value="save_connection">Save Connection</button>';
        echo '<button class="btn btn-default" type="submit" name="action" value="test_easydcim">Test EasyDCIM Connection</button>';
        echo '</div>';
        echo '</form>';
        echo '</div>';
    }

    private function renderPackagesTab(): void
    {
        echo '<div class="edbw-panel">';
        echo '<h3>Traffic Packages</h3>';
        echo '<form method="post" class="edbw-form-inline">';
        echo '<input type="hidden" name="tab" value="packages">';
        echo '<input type="hidden" name="action" value="add_package">';
        echo '<input type="text" name="pkg_name" placeholder="Package name" required>';
        echo '<input type="number" step="0.01" min="0.01" name="pkg_size_gb" placeholder="Size GB" required>';
        echo '<input type="number" step="0.01" min="0" name="pkg_price" placeholder="Price" required>';
        echo '<button class="btn btn-default" type="submit">Add Package</button>';
        echo '</form>';
        $packages = Capsule::table('mod_easydcim_bw_guard_packages')->orderBy('id')->limit(100)->get();
        echo '<div class="edbw-table-wrap">';
        echo '<table class="table table-striped"><thead><tr><th>ID</th><th>Name</th><th>Size GB</th><th>Price</th><th>Active</th></tr></thead><tbody>';
        foreach ($packages as $pkg) {
            echo '<tr>';
            echo '<td>' . (int) $pkg->id . '</td>';
            echo '<td>' . htmlspecialchars((string) $pkg->name) . '</td>';
            echo '<td>' . htmlspecialchars((string) $pkg->size_gb) . '</td>';
            echo '<td>' . htmlspecialchars((string) $pkg->price) . '</td>';
            echo '<td>' . ((int) $pkg->is_active === 1 ? 'Yes' : 'No') . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
        echo '</div>';
        echo '</div>';

        echo '<div class="edbw-panel">';
        echo '<h3>Service Overrides (Permanent)</h3>';
        echo '<form method="post" class="edbw-form-inline">';
        echo '<input type="hidden" name="tab" value="packages">';
        echo '<input type="hidden" name="action" value="save_override">';
        echo '<input type="number" min="1" name="ov_serviceid" placeholder="WHMCS Service ID" required>';
        echo '<input type="number" step="0.01" min="0" name="ov_quota_gb" placeholder="Base Quota GB">';
        echo '<select name="ov_mode"><option value="">Mode</option><option value="IN">IN</option><option value="OUT">OUT</option><option value="TOTAL">TOTAL</option></select>';
        echo '<select name="ov_action"><option value="">Action</option><option value="disable_ports">Disable Ports</option><option value="suspend">Suspend</option><option value="both">Both</option></select>';
        echo '<button class="btn btn-default" type="submit">Save Override</button>';
        echo '</form>';

        $overrides = Capsule::table('mod_easydcim_bw_guard_service_overrides')->orderByDesc('id')->limit(200)->get();
        echo '<div class="edbw-table-wrap">';
        echo '<table class="table table-striped"><thead><tr><th>Service</th><th>Quota GB</th><th>Mode</th><th>Action</th><th>Updated</th></tr></thead><tbody>';
        foreach ($overrides as $ov) {
            echo '<tr>';
            echo '<td>' . (int) $ov->serviceid . '</td>';
            echo '<td>' . htmlspecialchars((string) $ov->override_base_quota_gb) . '</td>';
            echo '<td>' . htmlspecialchars((string) $ov->override_mode) . '</td>';
            echo '<td>' . htmlspecialchars((string) $ov->override_action) . '</td>';
            echo '<td>' . htmlspecialchars((string) $ov->updated_at) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
        echo '</div>';
        echo '</div>';
    }

    private function renderScopeTab(): void
    {
        $s = $this->settings;
        $scopedProducts = $this->getScopedProducts();
        echo '<div class="edbw-panel">';
        echo '<h3>Managed Scope</h3>';
        echo '<form method="post" class="edbw-settings-grid">';
        echo '<input type="hidden" name="tab" value="scope">';
        echo '<input type="hidden" name="action" value="save_scope">';
        echo '<div class="edbw-form-inline"><label>Managed PIDs</label><input type="text" name="managed_pids" value="' . htmlspecialchars($s->getString('managed_pids')) . '" size="70"><span class="edbw-help">Comma separated product IDs</span></div>';
        echo '<div class="edbw-form-inline"><label>Managed GIDs</label><input type="text" name="managed_gids" value="' . htmlspecialchars($s->getString('managed_gids')) . '" size="70"><span class="edbw-help">Comma separated group IDs</span></div>';
        echo '<button class="btn btn-primary" type="submit">Save Scope</button>';
        echo '</form>';
        echo '<p class="edbw-help">Loaded products by current scope: ' . count($scopedProducts) . '</p>';
        echo '</div>';

        echo '<div class="edbw-panel">';
        echo '<h3>Plan Quotas (IN / OUT / TOTAL)</h3>';
        echo '<p class="edbw-help">Rows auto-save on change. You can still use Save Scope above for PID/GID updates.</p>';
        echo '<div class="edbw-table-wrap">';
        echo '<table class="table table-striped"><thead><tr><th>PID</th><th>Product</th><th>GID</th><th>CF Check</th><th>IN GB</th><th>OUT GB</th><th>TOTAL GB</th><th>Unlimited IN/OUT/TOTAL</th><th>Action</th></tr></thead><tbody>';
        foreach ($scopedProducts as $row) {
            echo '<tr><form method="post" class="edbw-auto-plan">';
            echo '<input type="hidden" name="tab" value="scope">';
            echo '<input type="hidden" name="action" value="save_product_plan">';
            echo '<input type="hidden" name="pd_pid" value="' . (int) $row['pid'] . '">';
            echo '<td>' . (int) $row['pid'] . '</td>';
            echo '<td>' . htmlspecialchars((string) $row['name']) . '</td>';
            echo '<td>' . (int) $row['gid'] . '</td>';
            $cfStatus = (($row['cf_service'] ? 'S' : '-') . '/' . ($row['cf_order'] ? 'O' : '-') . '/' . ($row['cf_server'] ? 'V' : '-'));
            echo '<td>' . htmlspecialchars($cfStatus) . '</td>';
            echo '<td><input type="number" step="0.01" min="0" name="pd_quota_in_gb" value="' . htmlspecialchars((string) $row['quota_in']) . '"' . ($row['unlimited_in'] ? ' disabled' : '') . '></td>';
            echo '<td><input type="number" step="0.01" min="0" name="pd_quota_out_gb" value="' . htmlspecialchars((string) $row['quota_out']) . '"' . ($row['unlimited_out'] ? ' disabled' : '') . '></td>';
            echo '<td><input type="number" step="0.01" min="0" name="pd_quota_total_gb" value="' . htmlspecialchars((string) $row['quota_total']) . '"' . ($row['unlimited_total'] ? ' disabled' : '') . '></td>';
            echo '<td>';
            echo '<label>IN <input type="checkbox" class="edbw-limit-toggle" data-target="pd_quota_in_gb" name="pd_unlimited_in" value="1" ' . ($row['unlimited_in'] ? 'checked' : '') . '></label> ';
            echo '<label>OUT <input type="checkbox" class="edbw-limit-toggle" data-target="pd_quota_out_gb" name="pd_unlimited_out" value="1" ' . ($row['unlimited_out'] ? 'checked' : '') . '></label> ';
            echo '<label>TOTAL <input type="checkbox" class="edbw-limit-toggle" data-target="pd_quota_total_gb" name="pd_unlimited_total" value="1" ' . ($row['unlimited_total'] ? 'checked' : '') . '></label>';
            echo '</td>';
            echo '<td><select name="pd_action"><option value="disable_ports"' . ($row['action'] === 'disable_ports' ? ' selected' : '') . '>Disable Ports</option><option value="suspend"' . ($row['action'] === 'suspend' ? ' selected' : '') . '>Suspend</option><option value="both"' . ($row['action'] === 'both' ? ' selected' : '') . '>Both</option></select></td>';
            echo '</form></tr>';
        }
        echo '</tbody></table>';
        echo '</div>';
        echo '<script>(function(){var forms=document.querySelectorAll(".edbw-auto-plan");forms.forEach(function(f){var t;f.querySelectorAll("input,select").forEach(function(el){el.addEventListener("change",function(){if(el.classList.contains("edbw-limit-toggle")){var target=f.querySelector("[name=\\"" + el.getAttribute("data-target") + "\\"]");if(target){target.disabled=el.checked;}}clearTimeout(t);t=setTimeout(function(){f.submit();},350);});});});})();</script>';
        echo '</div>';
    }

    private function renderLogsTab(): void
    {
        $retention = max(1, $this->settings->getInt('log_retention_days', 30));
        echo '<div class="edbw-panel">';
        echo '<h3>System Logs</h3>';
        echo '<p class="edbw-help">Retention is set to ' . $retention . ' day(s). Logs older than this are auto-cleaned in cron.</p>';
        echo '<form method="post" class="edbw-form-inline">';
        echo '<input type="hidden" name="tab" value="logs">';
        echo '<input type="hidden" name="action" value="cleanup_logs">';
        echo '<button class="btn btn-default" type="submit">Cleanup Logs Now</button>';
        echo '</form>';
        echo '<form method="post" class="edbw-form-inline">';
        echo '<input type="hidden" name="tab" value="logs">';
        echo '<input type="hidden" name="action" value="cleanup_logs_all">';
        echo '<button class="btn btn-default" type="submit">Delete All Logs</button>';
        echo '</form>';

        $logs = $this->getSystemLogs(500);
        echo '<div class="edbw-table-wrap">';
        echo '<table class="table table-striped"><thead><tr><th>Level</th><th>Message</th><th>Source</th><th>Details</th><th>Time</th></tr></thead><tbody>';
        foreach ($logs as $log) {
            $ctx = (string) ($log['context_json'] ?? '');
            if (strlen($ctx) > 260) {
                $ctx = substr($ctx, 0, 260) . '...';
            }
            echo '<tr>';
            echo '<td>' . htmlspecialchars((string) $log['level']) . '</td>';
            echo '<td>' . htmlspecialchars((string) $log['message']) . '</td>';
            echo '<td>' . htmlspecialchars((string) ($log['source'] ?? 'system')) . '</td>';
            echo '<td><code>' . htmlspecialchars($ctx) . '</code></td>';
            echo '<td>' . htmlspecialchars((string) $log['created_at']) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
        echo '</div>';
        echo '</div>';

        echo '<div class="edbw-panel">';
        echo '<h3>Traffic Purchase Logs</h3>';
        $purchases = $this->getPurchaseLogs(300);
        echo '<div class="edbw-table-wrap">';
        echo '<table class="table table-striped"><thead><tr><th>ID</th><th>Service</th><th>Invoice</th><th>Cycle</th><th>Reset</th><th>Actor</th><th>Created</th></tr></thead><tbody>';
        foreach ($purchases as $row) {
            echo '<tr>';
            echo '<td>' . (int) $row['id'] . '</td>';
            echo '<td>' . (int) $row['whmcs_serviceid'] . '</td>';
            echo '<td>' . (int) $row['invoiceid'] . '</td>';
            echo '<td>' . htmlspecialchars((string) $row['cycle_start'] . ' -> ' . (string) $row['cycle_end']) . '</td>';
            echo '<td>' . htmlspecialchars((string) $row['reset_at']) . '</td>';
            echo '<td>' . htmlspecialchars((string) $row['actor']) . '</td>';
            echo '<td>' . htmlspecialchars((string) $row['created_at']) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
        echo '</div>';
        echo '</div>';
    }

    private function renderPreflightPanel(): void
    {
        $checks = $this->buildHealthChecks();
        $failed = array_filter($checks, static fn (array $c): bool => !$c['ok']);

        echo '<div class="edbw-panel">';
        echo '<h3>Preflight Checks</h3>';
        echo '<form method="post" class="edbw-form-inline">';
        echo '<input type="hidden" name="tab" value="dashboard">';
        echo '<input type="hidden" name="action" value="run_preflight">';
        echo '<button class="btn btn-default" type="submit">Retest</button>';
        echo '</form>';
        echo '<div class="edbw-table-wrap">';
        echo '<table class="table table-striped"><thead><tr><th>Check</th><th>Status</th><th>Details</th></tr></thead><tbody>';
        foreach ($checks as $check) {
            echo '<tr>';
            echo '<td>' . htmlspecialchars($check['name']) . '</td>';
            echo '<td>' . ($check['ok'] ? '<span class="edbw-badge ok">OK</span>' : '<span class="edbw-badge fail">Missing/Fail</span>') . '</td>';
            echo '<td>' . htmlspecialchars($check['detail']) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
        echo '</div>';

        if (!empty($failed)) {
            echo '<div class="alert alert-warning">Module can run, but missing items should be fixed before production traffic enforcement.</div>';
        } else {
            echo '<div class="alert alert-success">All preflight checks passed.</div>';
        }
        echo '</div>';
    }

    private function buildHealthChecks(): array
    {
        $checks = [];
        $phpOk = version_compare(PHP_VERSION, '8.0.0', '>=');
        $checks[] = ['name' => 'PHP version', 'ok' => $phpOk, 'detail' => 'Current: ' . PHP_VERSION . ', required: >= 8.0'];

        $checks[] = ['name' => 'cURL extension', 'ok' => function_exists('curl_init'), 'detail' => function_exists('curl_init') ? 'Available' : 'Missing'];
        $checks[] = ['name' => 'Git mode capability (shell_exec)', 'ok' => true, 'detail' => 'Not required (release-based updater is active)'];
        $checks[] = ['name' => 'ZIP extension', 'ok' => class_exists(\ZipArchive::class), 'detail' => class_exists(\ZipArchive::class) ? 'Available' : 'Missing'];
        $checks[] = ['name' => 'Module status', 'ok' => $this->settings->getBool('module_enabled', true), 'detail' => $this->settings->getBool('module_enabled', true) ? 'Enabled' : 'Disabled'];

        $baseUrl = $this->settings->getString('easydcim_base_url');
        $token = $this->settings->getString('easydcim_api_token');
        $checks[] = ['name' => 'EasyDCIM Base URL', 'ok' => $baseUrl !== '', 'detail' => $baseUrl !== '' ? 'Configured' : 'Not configured'];
        $checks[] = ['name' => 'EasyDCIM API Token', 'ok' => $token !== '', 'detail' => $token !== '' ? 'Configured' : 'Not configured'];

        $scopeSet = !empty($this->settings->getCsvList('managed_pids')) || !empty($this->settings->getCsvList('managed_gids'));
        $checks[] = ['name' => 'Managed scope (PID/GID)', 'ok' => $scopeSet, 'detail' => $scopeSet ? 'Configured' : 'No PID/GID set'];

        $requiredCustomFields = ['easydcim_service_id', 'easydcim_order_id', 'easydcim_server_id'];
        $existing = Capsule::table('tblcustomfields')
            ->where('type', 'product')
            ->pluck('fieldname')
            ->map(static fn ($n): string => strtolower(trim(explode('|', (string) $n)[0])))
            ->all();
        foreach ($requiredCustomFields as $field) {
            $checks[] = [
                'name' => 'Custom field: ' . $field,
                'ok' => in_array($field, $existing, true),
                'detail' => in_array($field, $existing, true) ? 'Found' : 'Missing',
            ];
        }

        return $checks;
    }

    private function buildRuntimeStatus(): array
    {
        $limitedCount = (int) Capsule::table('mod_easydcim_bw_guard_service_state')->where('last_status', 'limited')->count();
        $syncedInLastHour = (int) Capsule::table('mod_easydcim_bw_guard_service_state')->where('last_check_at', '>=', date('Y-m-d H:i:s', time() - 3600))->count();
        $suspendedQuery = Capsule::table('tblhosting as h')
            ->join('tblproducts as p', 'p.id', '=', 'h.packageid')
            ->leftJoin('mod_easydcim_bw_guard_service_state as s', 's.serviceid', '=', 'h.id')
            ->where('h.domainstatus', 'Suspended')
            ->where(static function ($q): void {
                $q->whereNull('s.last_status')->orWhere('s.last_status', '!=', 'limited');
            });
        $this->applyScopeFilter($suspendedQuery);
        $suspendedOther = (int) $suspendedQuery->count();

        $lastWhmcsCron = (string) Capsule::table('mod_easydcim_bw_guard_meta')->where('meta_key', 'last_whmcs_cron_at')->value('meta_value');
        $cronOk = $lastWhmcsCron !== '' && strtotime($lastWhmcsCron) > time() - 360;

        return [
            ['label' => 'Module status', 'value' => $this->settings->getBool('module_enabled', true) ? 'Enabled' : 'Disabled', 'state' => $this->settings->getBool('module_enabled', true) ? 'ok' : 'neutral', 'icon' => '<svg viewBox="0 0 24 24"><path d="M12 2v10"></path><path d="M6 6a8 8 0 1012 0"></path></svg>'],
            ['label' => 'Cron status', 'value' => $cronOk ? ('Active (last ping: ' . $lastWhmcsCron . ')') : 'Not running in last 6 minutes', 'state' => $cronOk ? 'ok' : 'error', 'icon' => '<svg viewBox="0 0 24 24"><path d="M12 6v6l4 2"></path><circle cx="12" cy="12" r="9"></circle></svg>'],
            ['label' => 'Traffic-limited services', 'value' => (string) $limitedCount, 'state' => $limitedCount > 0 ? 'warn' : 'ok', 'icon' => '<svg viewBox="0 0 24 24"><path d="M4 20h16M7 16h10M10 12h4M12 4v4"></path></svg>'],
            ['label' => 'Synced (last 1h)', 'value' => (string) $syncedInLastHour, 'state' => $syncedInLastHour > 0 ? 'ok' : 'neutral', 'icon' => '<svg viewBox="0 0 24 24"><path d="M3 12h6l3-8 4 16 3-8h2"></path></svg>'],
            ['label' => 'Suspended (other reasons)', 'value' => (string) $suspendedOther, 'state' => $suspendedOther > 0 ? 'warn' : 'neutral', 'icon' => '<svg viewBox="0 0 24 24"><path d="M7 11V8a5 5 0 1110 0v3"></path><rect x="5" y="11" width="14" height="10" rx="2"></rect></svg>'],
            ['label' => 'Test Mode', 'value' => $this->settings->getBool('test_mode', false) ? 'Enabled (Dry Run)' : 'Disabled', 'state' => $this->settings->getBool('test_mode', false) ? 'warn' : 'neutral', 'icon' => '<svg viewBox="0 0 24 24"><path d="M6 2h12M9 2v4l-5 8a4 4 0 003.4 6h9.2A4 4 0 0020 14l-5-8V2"></path></svg>'],
        ];
    }

    private function saveSettings(): array
    {
        $current = Settings::loadFromDatabase();
        $payload = $current;
        $action = (string) ($_POST['action'] ?? '');
        $allowedGeneral = [
            'module_enabled', 'ui_language', 'poll_interval_minutes', 'graph_cache_minutes',
            'autobuy_enabled', 'autobuy_threshold_gb', 'autobuy_default_package_id', 'autobuy_max_per_cycle',
            'update_mode', 'traffic_direction_map', 'default_calculation_mode', 'test_mode',
            'log_retention_days', 'preflight_strict_mode', 'purge_on_deactivate',
        ];
        $allowedConnection = [
            'easydcim_base_url', 'use_impersonation',
            'proxy_enabled', 'proxy_type', 'proxy_host', 'proxy_port', 'proxy_username',
        ];
        $allowed = $action === 'save_connection' ? $allowedConnection : $allowedGeneral;
        $boolKeys = ['use_impersonation', 'autobuy_enabled', 'preflight_strict_mode', 'purge_on_deactivate', 'test_mode', 'proxy_enabled'];

        foreach ($allowed as $key) {
            if (in_array($key, $boolKeys, true)) {
                $payload[$key] = isset($_POST[$key]) ? '1' : '0';
                continue;
            }
            if (!isset($_POST[$key])) {
                continue;
            }
            $payload[$key] = trim((string) $_POST[$key]);
        }

        if ($action === 'save_connection') {
            $newToken = trim((string) ($_POST['easydcim_api_token'] ?? ''));
            if ($newToken !== '') {
                $payload['easydcim_api_token'] = function_exists('encrypt') ? encrypt($newToken) : $newToken;
            } else {
                $payload['easydcim_api_token'] = $current['easydcim_api_token'] ?? '';
            }

            $newProxyPassword = trim((string) ($_POST['proxy_password'] ?? ''));
            if ($newProxyPassword !== '') {
                $payload['proxy_password'] = function_exists('encrypt') ? encrypt($newProxyPassword) : $newProxyPassword;
            } else {
                $payload['proxy_password'] = $current['proxy_password'] ?? '';
            }
        }

        if ((int) ($payload['log_retention_days'] ?? 30) < 1) {
            $payload['log_retention_days'] = '30';
        }

        Settings::saveToDatabase($payload);
        return ['type' => 'success', 'text' => 'Settings saved successfully.'];
    }

    private function testEasyDcimConnection(): array
    {
        try {
            $baseUrl = $this->settings->getString('easydcim_base_url');
            $token = Crypto::safeDecrypt($this->settings->getString('easydcim_api_token'));
            if ($baseUrl === '' || $token === '') {
                return ['type' => 'warning', 'text' => 'Base URL or API token is missing.'];
            }

            $client = new EasyDcimClient($baseUrl, $token, $this->settings->getBool('use_impersonation', false), $this->logger, $this->proxyConfig());
            if ($client->ping()) {
                return ['type' => 'success', 'text' => 'EasyDCIM connection is OK.'];
            }

            return ['type' => 'warning', 'text' => 'EasyDCIM is reachable but response is not healthy.'];
        } catch (\Throwable $e) {
            return ['type' => 'danger', 'text' => 'EasyDCIM test failed: ' . $e->getMessage()];
        }
    }

    private function cleanupLogsNow(): array
    {
        try {
            $days = max(1, $this->settings->getInt('log_retention_days', 30));
            $cutoff = date('Y-m-d H:i:s', time() - ($days * 86400));
            $deleted = Capsule::table('mod_easydcim_bw_guard_logs')->where('created_at', '<', $cutoff)->delete();
            return ['type' => 'success', 'text' => 'Log cleanup complete. Removed ' . (int) $deleted . ' rows.'];
        } catch (\Throwable $e) {
            return ['type' => 'danger', 'text' => 'Log cleanup failed: ' . $e->getMessage()];
        }
    }

    private function cleanupLogsAllNow(): array
    {
        try {
            $deleted = Capsule::table('mod_easydcim_bw_guard_logs')->delete();
            return ['type' => 'success', 'text' => 'All logs removed: ' . (int) $deleted];
        } catch (\Throwable $e) {
            return ['type' => 'danger', 'text' => 'Delete all logs failed: ' . $e->getMessage()];
        }
    }

    private function saveScopeSettings(): array
    {
        $current = Settings::loadFromDatabase();
        $current['managed_pids'] = trim((string) ($_POST['managed_pids'] ?? ''));
        $current['managed_gids'] = trim((string) ($_POST['managed_gids'] ?? ''));
        Settings::saveToDatabase($current);
        return ['type' => 'success', 'text' => 'Scope saved.'];
    }

    private function saveProductDefault(): array
    {
        try {
            $pid = (int) ($_POST['pd_pid'] ?? 0);
            $mode = strtoupper(trim((string) ($_POST['pd_mode'] ?? 'TOTAL')));
            $quota = ($_POST['pd_quota_gb'] ?? '') !== '' ? (float) $_POST['pd_quota_gb'] : null;
            $unlimited = isset($_POST['pd_unlimited']) ? 1 : 0;
            $action = trim((string) ($_POST['pd_action'] ?? 'disable_ports'));
            if ($pid <= 0) {
                return ['type' => 'danger', 'text' => 'Invalid PID.'];
            }
            if (!in_array($mode, ['IN', 'OUT', 'TOTAL'], true)) {
                $mode = 'TOTAL';
            }
            if (!in_array($action, ['disable_ports', 'suspend', 'both'], true)) {
                $action = 'disable_ports';
            }

            $row = Capsule::table('mod_easydcim_bw_guard_product_defaults')->where('pid', $pid)->first();
            $data = [
                'pid' => $pid,
                'default_mode' => $mode,
                'default_action' => $action,
                'enabled' => 1,
                'updated_at' => date('Y-m-d H:i:s'),
                'created_at' => date('Y-m-d H:i:s'),
            ];

            if ($mode === 'IN') {
                $data['default_quota_in_gb'] = $quota;
                $data['unlimited_in'] = $unlimited;
            } elseif ($mode === 'OUT') {
                $data['default_quota_out_gb'] = $quota;
                $data['unlimited_out'] = $unlimited;
            } else {
                $data['default_quota_total_gb'] = $quota;
                $data['default_quota_gb'] = $quota ?? 0;
                $data['unlimited_total'] = $unlimited;
            }

            if ($row) {
                Capsule::table('mod_easydcim_bw_guard_product_defaults')->where('id', (int) $row->id)->update($data);
            } else {
                Capsule::table('mod_easydcim_bw_guard_product_defaults')->insert($data);
            }

            return ['type' => 'success', 'text' => 'Plan quota rule saved.'];
        } catch (\Throwable $e) {
            return ['type' => 'danger', 'text' => 'Failed to save plan rule: ' . $e->getMessage()];
        }
    }

    private function saveProductPlan(): array
    {
        try {
            $pid = (int) ($_POST['pd_pid'] ?? 0);
            if ($pid <= 0) {
                return ['type' => 'danger', 'text' => 'Invalid PID.'];
            }

            $action = trim((string) ($_POST['pd_action'] ?? 'disable_ports'));
            if (!in_array($action, ['disable_ports', 'suspend', 'both'], true)) {
                $action = 'disable_ports';
            }
            $row = Capsule::table('mod_easydcim_bw_guard_product_defaults')->where('pid', $pid)->first();
            $mode = $row ? (string) ($row->default_mode ?? 'TOTAL') : strtoupper($this->settings->getString('default_calculation_mode', 'TOTAL'));
            if (!in_array($mode, ['IN', 'OUT', 'TOTAL'], true)) {
                $mode = 'TOTAL';
            }

            $data = [
                'pid' => $pid,
                'default_mode' => $mode,
                'default_action' => $action,
                'default_quota_in_gb' => ($_POST['pd_quota_in_gb'] ?? '') !== '' ? (float) $_POST['pd_quota_in_gb'] : null,
                'default_quota_out_gb' => ($_POST['pd_quota_out_gb'] ?? '') !== '' ? (float) $_POST['pd_quota_out_gb'] : null,
                'default_quota_total_gb' => ($_POST['pd_quota_total_gb'] ?? '') !== '' ? (float) $_POST['pd_quota_total_gb'] : null,
                'default_quota_gb' => ($_POST['pd_quota_total_gb'] ?? '') !== '' ? (float) $_POST['pd_quota_total_gb'] : 0,
                'unlimited_in' => isset($_POST['pd_unlimited_in']) ? 1 : 0,
                'unlimited_out' => isset($_POST['pd_unlimited_out']) ? 1 : 0,
                'unlimited_total' => isset($_POST['pd_unlimited_total']) ? 1 : 0,
                'enabled' => 1,
                'updated_at' => date('Y-m-d H:i:s'),
                'created_at' => date('Y-m-d H:i:s'),
            ];

            if ($row) {
                Capsule::table('mod_easydcim_bw_guard_product_defaults')->where('id', (int) $row->id)->update($data);
            } else {
                Capsule::table('mod_easydcim_bw_guard_product_defaults')->insert($data);
            }

            return ['type' => 'success', 'text' => 'Product plan saved for PID ' . $pid];
        } catch (\Throwable $e) {
            return ['type' => 'danger', 'text' => 'Failed to save product plan: ' . $e->getMessage()];
        }
    }

    private function checkReleaseUpdate(): array
    {
        try {
            $repo = self::RELEASE_REPO;
            $release = $this->fetchLatestRelease($repo);
            $latestTag = (string) ($release['tag_name'] ?? '');
            $latestVersion = ltrim($latestTag, 'vV');
            $currentVersion = Version::current($this->moduleDir)['module_version'];
            $available = $this->compareVersion($latestVersion, $currentVersion) > 0;

            Capsule::table('mod_easydcim_bw_guard_meta')->updateOrInsert(
                ['meta_key' => 'release_latest_tag'],
                ['meta_value' => $latestTag, 'updated_at' => date('Y-m-d H:i:s')]
            );
            Capsule::table('mod_easydcim_bw_guard_meta')->updateOrInsert(
                ['meta_key' => 'release_latest_zip'],
                ['meta_value' => (string) $this->extractZipUrl($release), 'updated_at' => date('Y-m-d H:i:s')]
            );
            Capsule::table('mod_easydcim_bw_guard_meta')->updateOrInsert(
                ['meta_key' => 'release_update_available'],
                ['meta_value' => $available ? '1' : '0', 'updated_at' => date('Y-m-d H:i:s')]
            );

            return ['type' => 'success', 'text' => $available ? ('New release found: ' . $latestTag) : 'No newer release found.'];
        } catch (\Throwable $e) {
            return ['type' => 'danger', 'text' => 'Release check failed: ' . $e->getMessage()];
        }
    }

    private function applyReleaseUpdate(): array
    {
        try {
            if (!class_exists(\ZipArchive::class)) {
                throw new \RuntimeException('ZipArchive extension is required.');
            }

            $repo = self::RELEASE_REPO;
            $release = $this->fetchLatestRelease($repo);
            $zipUrl = $this->extractZipUrl($release);
            if ($zipUrl === '') {
                throw new \RuntimeException('No ZIP asset found in latest release.');
            }

            $tmpZip = tempnam(sys_get_temp_dir(), 'edbw_rel_');
            if ($tmpZip === false) {
                throw new \RuntimeException('Could not allocate temp file.');
            }
            $this->downloadFile($zipUrl, $tmpZip);
            $this->extractAddonFromZip($tmpZip);
            @unlink($tmpZip);

            Capsule::table('mod_easydcim_bw_guard_meta')->updateOrInsert(
                ['meta_key' => 'release_update_available'],
                ['meta_value' => '0', 'updated_at' => date('Y-m-d H:i:s')]
            );

            return ['type' => 'success', 'text' => 'Release update applied successfully.'];
        } catch (\Throwable $e) {
            return ['type' => 'danger', 'text' => 'Release update failed: ' . $e->getMessage()];
        }
    }

    private function fetchLatestRelease(string $repo): array
    {
        $repo = trim($repo);
        if (!preg_match('/^[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+$/', $repo)) {
            throw new \RuntimeException('GitHub repo format must be owner/repo.');
        }
        $url = 'https://api.github.com/repos/' . $repo . '/releases/latest';
        $response = $this->httpGetJson($url);
        if (!isset($response['tag_name'])) {
            throw new \RuntimeException('GitHub latest release payload is invalid.');
        }
        return $response;
    }

    private function extractZipUrl(array $release): string
    {
        foreach (($release['assets'] ?? []) as $asset) {
            $name = strtolower((string) ($asset['name'] ?? ''));
            if (substr($name, -4) === '.zip' && !empty($asset['browser_download_url'])) {
                return (string) $asset['browser_download_url'];
            }
        }
        return '';
    }

    private function httpGetJson(string $url): array
    {
        if (!function_exists('curl_init')) {
            throw new \RuntimeException('cURL extension is required.');
        }

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['User-Agent: EasyDcim-BW', 'Accept: application/vnd.github+json']);
        $raw = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($code < 200 || $code >= 300) {
            throw new \RuntimeException('HTTP ' . $code . ' ' . $err);
        }

        $data = json_decode((string) $raw, true);
        if (!is_array($data)) {
            throw new \RuntimeException('Invalid JSON response.');
        }
        return $data;
    }

    private function downloadFile(string $url, string $target): void
    {
        $fh = fopen($target, 'wb');
        if ($fh === false) {
            throw new \RuntimeException('Cannot open temp file for writing.');
        }

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_FILE, $fh);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 120);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['User-Agent: EasyDcim-BW']);
        curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        fclose($fh);

        if ($code < 200 || $code >= 300) {
            throw new \RuntimeException('Download failed: HTTP ' . $code . ' ' . $err);
        }
    }

    private function extractAddonFromZip(string $zipPath): void
    {
        $zip = new \ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new \RuntimeException('Cannot open downloaded ZIP.');
        }

        $whmcsRoot = realpath($this->moduleDir . '/../../../');
        if ($whmcsRoot === false) {
            $zip->close();
            throw new \RuntimeException('Cannot resolve WHMCS root path.');
        }

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (!is_string($name) || strpos($name, 'modules/addons/easydcim_bw/') !== 0) {
                continue;
            }
            $target = $whmcsRoot . '/' . $name;
            if (substr($name, -1) === '/') {
                if (!is_dir($target)) {
                    mkdir($target, 0755, true);
                }
                continue;
            }

            $dir = dirname($target);
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            $content = $zip->getFromIndex($i);
            if ($content === false) {
                continue;
            }
            file_put_contents($target, $content);
        }
        $zip->close();
    }

    private function compareVersion(string $a, string $b): int
    {
        $normalize = static function (string $v): array {
            if (!preg_match('/^(\\d+)\\.(\\d{1,2})$/', trim($v), $m)) {
                return [0, 0];
            }
            return [(int) $m[1], (int) $m[2]];
        };
        [$aMaj, $aMin] = $normalize($a);
        [$bMaj, $bMin] = $normalize($b);
        return ($aMaj <=> $bMaj) ?: ($aMin <=> $bMin);
    }

    private function applyOneClickUpdate(): void
    {
        $manager = new GitUpdateManager($this->moduleDir, $this->logger);
        try {
            $origin = $this->settings->getString('git_origin_url');
            $branch = $this->settings->getString('git_branch', 'main');
            $manager->applyOneClickUpdate($origin, $branch);
            echo '<div class="alert alert-success">Update applied successfully.</div>';
        } catch (\Throwable $e) {
            $this->logger->log('ERROR', 'update_apply_failed', ['error' => $e->getMessage()]);
            echo '<div class="alert alert-danger">Update failed: ' . htmlspecialchars($e->getMessage()) . '</div>';
        }
    }

    private function getPurchaseLogs(int $limit = 200): array
    {
        return Capsule::table('mod_easydcim_bw_guard_purchases')
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(static fn ($r): array => (array) $r)
            ->all();
    }

    private function getEnforcementLogs(int $limit = 200): array
    {
        return Capsule::table('mod_easydcim_bw_guard_logs')
            ->whereIn('message', ['traffic_enforced', 'traffic_unlocked', 'service_poll_failed', 'test_mode_action'])
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(static fn ($r): array => (array) $r)
            ->all();
    }

    private function getSystemLogs(int $limit = 400): array
    {
        return Capsule::table('mod_easydcim_bw_guard_logs')
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(static fn ($r): array => (array) $r)
            ->all();
    }

    private function json(array $data): void
    {
        header('Content-Type: application/json');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function addPackage(): void
    {
        $name = trim((string) ($_POST['pkg_name'] ?? ''));
        $size = (float) ($_POST['pkg_size_gb'] ?? 0);
        $price = (float) ($_POST['pkg_price'] ?? 0);
        if ($name === '' || $size <= 0 || $price < 0) {
            echo '<div class="alert alert-danger">Invalid package values.</div>';
            return;
        }

        Capsule::table('mod_easydcim_bw_guard_packages')->insert([
            'name' => $name,
            'size_gb' => $size,
            'price' => $price,
            'taxed' => 0,
            'available_for_pids' => null,
            'available_for_gids' => null,
            'is_active' => 1,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        echo '<div class="alert alert-success">Package added.</div>';
    }

    private function saveOverride(): void
    {
        $serviceId = (int) ($_POST['ov_serviceid'] ?? 0);
        $quota = ($_POST['ov_quota_gb'] ?? '') !== '' ? (float) $_POST['ov_quota_gb'] : null;
        $mode = strtoupper(trim((string) ($_POST['ov_mode'] ?? '')));
        $action = trim((string) ($_POST['ov_action'] ?? ''));

        if ($serviceId <= 0) {
            echo '<div class="alert alert-danger">Invalid service id.</div>';
            return;
        }
        if ($mode !== '' && !in_array($mode, ['IN', 'OUT', 'TOTAL'], true)) {
            echo '<div class="alert alert-danger">Invalid mode.</div>';
            return;
        }
        if ($action !== '' && !in_array($action, ['disable_ports', 'suspend', 'both'], true)) {
            echo '<div class="alert alert-danger">Invalid action.</div>';
            return;
        }

        Capsule::table('mod_easydcim_bw_guard_service_overrides')->updateOrInsert(
            ['serviceid' => $serviceId],
            [
                'override_base_quota_gb' => $quota,
                'override_mode' => $mode !== '' ? $mode : null,
                'override_action' => $action !== '' ? $action : null,
                'updated_at' => date('Y-m-d H:i:s'),
                'created_at' => date('Y-m-d H:i:s'),
            ]
        );
        echo '<div class="alert alert-success">Override saved.</div>';
    }

    private function applyScopeFilter($query): void
    {
        $pids = array_map('intval', $this->settings->getCsvList('managed_pids'));
        $gids = array_map('intval', $this->settings->getCsvList('managed_gids'));
        if (!empty($pids)) {
            $query->whereIn('h.packageid', $pids);
            return;
        }
        if (!empty($gids)) {
            $query->whereIn('p.gid', $gids);
        }
    }

    private function getScopedProducts(): array
    {
        $pids = array_map('intval', $this->settings->getCsvList('managed_pids'));
        $gids = array_map('intval', $this->settings->getCsvList('managed_gids'));
        if (empty($pids) && empty($gids)) {
            return [];
        }

        $q = Capsule::table('tblproducts')->select(['id', 'name', 'gid']);
        if (!empty($pids)) {
            $q->whereIn('id', $pids);
        } elseif (!empty($gids)) {
            $q->whereIn('gid', $gids);
        }
        $products = $q->orderBy('gid')->orderBy('id')->get();
        if ($products->isEmpty()) {
            return [];
        }

        $pidList = $products->pluck('id')->map(static fn ($v): int => (int) $v)->all();
        $defaults = Capsule::table('mod_easydcim_bw_guard_product_defaults')->whereIn('pid', $pidList)->get()->keyBy('pid');
        $cfRows = Capsule::table('tblcustomfields')
            ->where('type', 'product')
            ->whereIn('relid', $pidList)
            ->get(['relid', 'fieldname']);
        $cfMap = [];
        foreach ($cfRows as $r) {
            $pid = (int) $r->relid;
            $name = strtolower(trim(explode('|', (string) $r->fieldname)[0]));
            $cfMap[$pid][$name] = true;
        }

        $out = [];
        foreach ($products as $p) {
            $pid = (int) $p->id;
            $d = $defaults[$pid] ?? null;
            $out[] = [
                'pid' => $pid,
                'name' => (string) $p->name,
                'gid' => (int) $p->gid,
                'quota_in' => $d ? (string) ($d->default_quota_in_gb ?? '') : '',
                'quota_out' => $d ? (string) ($d->default_quota_out_gb ?? '') : '',
                'quota_total' => $d ? (string) ($d->default_quota_total_gb ?? $d->default_quota_gb ?? '') : '',
                'unlimited_in' => $d ? ((int) ($d->unlimited_in ?? 0) === 1) : false,
                'unlimited_out' => $d ? ((int) ($d->unlimited_out ?? 0) === 1) : false,
                'unlimited_total' => $d ? ((int) ($d->unlimited_total ?? 0) === 1) : false,
                'mode' => $d ? (string) ($d->default_mode ?? 'TOTAL') : 'TOTAL',
                'action' => $d ? (string) ($d->default_action ?? 'disable_ports') : 'disable_ports',
                'cf_service' => !empty($cfMap[$pid]['easydcim_service_id']),
                'cf_order' => !empty($cfMap[$pid]['easydcim_order_id']),
                'cf_server' => !empty($cfMap[$pid]['easydcim_server_id']),
            ];
        }
        return $out;
    }

    private function proxyConfig(): array
    {
        return [
            'enabled' => $this->settings->getBool('proxy_enabled', false),
            'type' => $this->settings->getString('proxy_type', 'http'),
            'host' => $this->settings->getString('proxy_host'),
            'port' => $this->settings->getInt('proxy_port', 0),
            'username' => $this->settings->getString('proxy_username'),
            'password' => Crypto::safeDecrypt($this->settings->getString('proxy_password')),
        ];
    }

    private function t(string $key): string
    {
        $fa = [
            'subtitle' => 'مرکز کنترل ترافیک سرویس‌های EasyDCIM',
            'tab_dashboard' => 'داشبورد',
            'tab_connection' => 'Easy DCIM',
            'tab_settings' => 'تنظیمات',
            'tab_scope' => 'سرویس/گروه',
            'tab_packages' => 'پکیج‌ها',
            'tab_logs' => 'لاگ‌ها',
        ];
        $en = [
            'subtitle' => 'Bandwidth control center for EasyDCIM services',
            'tab_dashboard' => 'Dashboard',
            'tab_connection' => 'Easy DCIM',
            'tab_settings' => 'Settings',
            'tab_scope' => 'Services / Group',
            'tab_packages' => 'Packages',
            'tab_logs' => 'Logs',
        ];
        $map = $this->isFa ? $fa : $en;
        return $map[$key] ?? $key;
    }
}
