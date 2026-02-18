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
    private Settings $settings;
    private Logger $logger;
    private string $moduleDir;

    public function __construct(Settings $settings, Logger $logger, string $moduleDir)
    {
        $this->settings = $settings;
        $this->logger = $logger;
        $this->moduleDir = $moduleDir;
    }

    public function handle(array $vars): void
    {
        $action = $_REQUEST['action'] ?? 'dashboard';
        $api = isset($_GET['api']) ? (string) $_GET['api'] : '';
        $flash = [];

        if ($api === 'purchase_logs') {
            $this->json($this->getPurchaseLogs());
            return;
        }

        if ($api === 'enforcement_logs') {
            $this->json($this->getEnforcementLogs());
            return;
        }

        if ($action === 'apply_update') {
            $this->applyOneClickUpdate();
        }
        if ($action === 'add_package') {
            $this->addPackage();
        }
        if ($action === 'save_override') {
            $this->saveOverride();
        }
        if ($action === 'save_settings') {
            $flash[] = $this->saveSettings();
        }
        if ($action === 'test_easydcim') {
            $flash[] = $this->testEasyDcimConnection();
        }
        if ($action === 'run_preflight') {
            $flash[] = ['type' => 'info', 'text' => 'Preflight retest completed.'];
        }

        $this->settings = new Settings(Settings::loadFromDatabase());
        $version = Version::current($this->moduleDir);
        $updateAvailable = Capsule::table('mod_easydcim_bw_guard_meta')->where('meta_key', 'update_available')->value('meta_value') === '1';
        $lastPollAt = (string) Capsule::table('mod_easydcim_bw_guard_meta')->where('meta_key', 'last_poll_at')->value('meta_value');
        $apiFailCount = (int) Capsule::table('mod_easydcim_bw_guard_meta')->where('meta_key', 'api_fail_count')->value('meta_value');
        $updateLock = Capsule::table('mod_easydcim_bw_guard_meta')->where('meta_key', 'update_in_progress')->value('meta_value') === '1';

        echo '<link rel="stylesheet" href="../modules/addons/easydcim_bw/assets/admin.css">';
        echo '<div class="edbw-wrap">';
        echo '<h2>EasyDcim-BW</h2>';
        foreach ($flash as $msg) {
            echo '<div class="alert alert-' . htmlspecialchars($msg['type']) . '">' . htmlspecialchars($msg['text']) . '</div>';
        }
        echo '<div class="edbw-card"><strong>Running Version:</strong> ' . htmlspecialchars($version['module_version']) . '</div>';
        echo '<div class="edbw-card"><strong>Commit:</strong> ' . htmlspecialchars($version['commit_sha']) . '</div>';
        echo '<div class="edbw-card"><strong>Update Status:</strong> ' . ($updateAvailable ? 'New commit available' : 'Up to date') . '</div>';
        echo '<div class="edbw-card"><strong>Last Poll:</strong> ' . htmlspecialchars($lastPollAt ?: 'N/A') . '</div>';
        echo '<div class="edbw-card"><strong>API Fail Count:</strong> ' . $apiFailCount . '</div>';
        echo '<div class="edbw-card"><strong>Update Lock:</strong> ' . ($updateLock ? 'Locked' : 'Free') . '</div>';
        foreach ($this->buildRuntimeStatus() as $card) {
            echo '<div class="edbw-card"><strong>' . htmlspecialchars($card['label']) . ':</strong> ' . htmlspecialchars($card['value']) . '</div>';
        }
        $this->renderConnectionSettings();
        $this->renderPreflightPanel();

        if ($updateAvailable) {
            $moduleLink = $vars['modulelink'] ?? '';
            echo '<form method="post" action="' . htmlspecialchars($moduleLink) . '">';
            echo '<input type="hidden" name="action" value="apply_update">';
            echo '<button class="btn btn-primary" type="submit">Apply One-Click Update</button>';
            echo '</form>';
        }

        $this->renderTables();
        echo '</div>';
    }

    private function renderPreflightPanel(): void
    {
        $checks = $this->buildHealthChecks();
        $failed = array_filter($checks, static fn (array $c): bool => !$c['ok']);

        echo '<h3>Preflight Checks</h3>';
        echo '<form method="post" class="edbw-form-inline">';
        echo '<input type="hidden" name="action" value="run_preflight">';
        echo '<button class="btn btn-default" type="submit">Retest</button>';
        echo '</form>';
        echo '<table class="table table-striped"><thead><tr><th>Check</th><th>Status</th><th>Details</th></tr></thead><tbody>';
        foreach ($checks as $check) {
            echo '<tr>';
            echo '<td>' . htmlspecialchars($check['name']) . '</td>';
            echo '<td>' . ($check['ok'] ? 'OK' : 'Missing/Fail') . '</td>';
            echo '<td>' . htmlspecialchars($check['detail']) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';

        if (!empty($failed)) {
            echo '<div class="alert alert-warning">Module can run, but missing items should be fixed before production traffic enforcement.</div>';
        } else {
            echo '<div class="alert alert-success">All preflight checks passed.</div>';
        }
    }

    private function buildHealthChecks(): array
    {
        $checks = [];
        $phpOk = version_compare(PHP_VERSION, '8.0.0', '>=');
        $checks[] = ['name' => 'PHP version', 'ok' => $phpOk, 'detail' => 'Current: ' . PHP_VERSION . ', required: >= 8.0'];

        $checks[] = ['name' => 'cURL extension', 'ok' => function_exists('curl_init'), 'detail' => function_exists('curl_init') ? 'Available' : 'Missing'];
        $checks[] = ['name' => 'shell_exec for git update', 'ok' => function_exists('shell_exec'), 'detail' => function_exists('shell_exec') ? 'Available' : 'Disabled'];

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
        $suspendedOther = (int) Capsule::table('tblhosting as h')
            ->leftJoin('mod_easydcim_bw_guard_service_state as s', 's.serviceid', '=', 'h.id')
            ->where('h.domainstatus', 'Suspended')
            ->where(static function ($q): void {
                $q->whereNull('s.last_status')->orWhere('s.last_status', '!=', 'limited');
            })
            ->count();

        $lastPoll = (string) Capsule::table('mod_easydcim_bw_guard_meta')->where('meta_key', 'last_poll_at')->value('meta_value');
        $cronOk = $lastPoll !== '' && strtotime($lastPoll) > time() - 3600;

        return [
            ['label' => 'Cron status', 'value' => $cronOk ? 'Connected' : 'Not running recently'],
            ['label' => 'Traffic-limited services', 'value' => (string) $limitedCount],
            ['label' => 'Synced (last 1h)', 'value' => (string) $syncedInLastHour],
            ['label' => 'Suspended (other reasons)', 'value' => (string) $suspendedOther],
        ];
    }

    private function renderConnectionSettings(): void
    {
        $s = $this->settings;
        echo '<h3>Settings</h3>';
        echo '<form method="post">';
        echo '<input type="hidden" name="action" value="save_settings">';
        echo '<div class="edbw-form-inline"><label>EasyDCIM Base URL</label><input type="text" name="easydcim_base_url" value="' . htmlspecialchars($s->getString('easydcim_base_url')) . '" size="60"></div>';
        echo '<div class="edbw-form-inline"><label>Admin API Token</label><input type="password" name="easydcim_api_token" value="" placeholder="Leave empty to keep current token" size="60"></div>';
        echo '<div class="edbw-form-inline"><label>Managed PIDs</label><input type="text" name="managed_pids" value="' . htmlspecialchars($s->getString('managed_pids')) . '" size="40"></div>';
        echo '<div class="edbw-form-inline"><label>Managed GIDs</label><input type="text" name="managed_gids" value="' . htmlspecialchars($s->getString('managed_gids')) . '" size="40"></div>';
        echo '<div class="edbw-form-inline"><label>Use Impersonation</label><input type="checkbox" name="use_impersonation" value="1" ' . ($s->getBool('use_impersonation') ? 'checked' : '') . '></div>';
        echo '<div class="edbw-form-inline"><label>Poll Interval (min)</label><input type="number" min="5" name="poll_interval_minutes" value="' . (int) $s->getInt('poll_interval_minutes', 15) . '"></div>';
        echo '<div class="edbw-form-inline"><label>Graph Cache (min)</label><input type="number" min="5" name="graph_cache_minutes" value="' . (int) $s->getInt('graph_cache_minutes', 30) . '"></div>';
        echo '<div class="edbw-form-inline"><label>Auto-Buy Enabled</label><input type="checkbox" name="autobuy_enabled" value="1" ' . ($s->getBool('autobuy_enabled') ? 'checked' : '') . '></div>';
        echo '<div class="edbw-form-inline"><label>Auto-Buy Threshold GB</label><input type="number" min="1" name="autobuy_threshold_gb" value="' . (int) $s->getInt('autobuy_threshold_gb', 10) . '"></div>';
        echo '<div class="edbw-form-inline"><label>Auto-Buy Default Package ID</label><input type="number" min="0" name="autobuy_default_package_id" value="' . (int) $s->getInt('autobuy_default_package_id', 0) . '"></div>';
        echo '<div class="edbw-form-inline"><label>Auto-Buy Max/Cycle</label><input type="number" min="1" name="autobuy_max_per_cycle" value="' . (int) $s->getInt('autobuy_max_per_cycle', 5) . '"></div>';
        echo '<div class="edbw-form-inline"><label>Git Update Enabled</label><input type="checkbox" name="git_update_enabled" value="1" ' . ($s->getBool('git_update_enabled') ? 'checked' : '') . '></div>';
        echo '<div class="edbw-form-inline"><label>Git Origin URL</label><input type="text" name="git_origin_url" value="' . htmlspecialchars($s->getString('git_origin_url')) . '" size="60"></div>';
        echo '<div class="edbw-form-inline"><label>Git Branch</label><input type="text" name="git_branch" value="' . htmlspecialchars($s->getString('git_branch', 'main')) . '" size="20"></div>';
        echo '<div class="edbw-form-inline"><label>Update Mode</label><select name="update_mode">';
        foreach (['notify', 'check_oneclick', 'auto'] as $mode) {
            echo '<option value="' . $mode . '"' . ($s->getString('update_mode', 'check_oneclick') === $mode ? ' selected' : '') . '>' . $mode . '</option>';
        }
        echo '</select></div>';
        echo '<button class="btn btn-primary" type="submit">Save Settings</button>';
        echo '</form>';

        echo '<form method="post" class="edbw-form-inline">';
        echo '<input type="hidden" name="action" value="test_easydcim">';
        echo '<button class="btn btn-default" type="submit">Test EasyDCIM Connection</button>';
        echo '</form>';
    }

    private function saveSettings(): array
    {
        $current = Settings::loadFromDatabase();
        $payload = $current;
        $keys = array_keys(Settings::defaults());
        $boolKeys = ['git_update_enabled', 'use_impersonation', 'autobuy_enabled', 'preflight_strict_mode'];
        foreach ($keys as $key) {
            if (in_array($key, $boolKeys, true)) {
                $payload[$key] = isset($_POST[$key]) ? '1' : '0';
                continue;
            }
            if (!isset($_POST[$key])) {
                continue;
            }
            $payload[$key] = trim((string) $_POST[$key]);
        }

        $newToken = trim((string) ($_POST['easydcim_api_token'] ?? ''));
        if ($newToken !== '') {
            $payload['easydcim_api_token'] = function_exists('encrypt') ? encrypt($newToken) : $newToken;
        } else {
            $payload['easydcim_api_token'] = $current['easydcim_api_token'] ?? '';
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

            $client = new EasyDcimClient($baseUrl, $token, $this->settings->getBool('use_impersonation', false), $this->logger);
            if ($client->ping()) {
                return ['type' => 'success', 'text' => 'EasyDCIM connection is OK.'];
            }

            return ['type' => 'warning', 'text' => 'EasyDCIM is reachable but response is not healthy.'];
        } catch (\Throwable $e) {
            return ['type' => 'danger', 'text' => 'EasyDCIM test failed: ' . $e->getMessage()];
        }
    }

    private function renderTables(): void
    {
        echo '<h3>Traffic Packages</h3>';
        echo '<form method="post" class="edbw-form-inline">';
        echo '<input type="hidden" name="action" value="add_package">';
        echo '<input type="text" name="pkg_name" placeholder="Package name" required>';
        echo '<input type="number" step="0.01" min="0.01" name="pkg_size_gb" placeholder="Size GB" required>';
        echo '<input type="number" step="0.01" min="0" name="pkg_price" placeholder="Price" required>';
        echo '<button class="btn btn-default" type="submit">Add Package</button>';
        echo '</form>';
        $packages = Capsule::table('mod_easydcim_bw_guard_packages')->orderBy('id')->limit(100)->get();
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

        echo '<h3>Service Overrides (Permanent)</h3>';
        echo '<form method="post" class="edbw-form-inline">';
        echo '<input type="hidden" name="action" value="save_override">';
        echo '<input type="number" min="1" name="ov_serviceid" placeholder="WHMCS Service ID" required>';
        echo '<input type="number" step="0.01" min="0" name="ov_quota_gb" placeholder="Base Quota GB">';
        echo '<select name="ov_mode"><option value=\"\">Mode</option><option value=\"IN\">IN</option><option value=\"OUT\">OUT</option><option value=\"TOTAL\">TOTAL</option></select>';
        echo '<select name="ov_action"><option value=\"\">Action</option><option value=\"disable_ports\">Disable Ports</option><option value=\"suspend\">Suspend</option><option value=\"both\">Both</option></select>';
        echo '<button class="btn btn-default" type="submit">Save Override</button>';
        echo '</form>';

        $overrides = Capsule::table('mod_easydcim_bw_guard_service_overrides')->orderByDesc('id')->limit(200)->get();
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

        $purchases = $this->getPurchaseLogs();
        echo '<h3>Traffic Purchase Logs</h3>';
        echo '<table class="table table-striped"><thead><tr><th>ID</th><th>Service</th><th>Invoice</th><th>Cycle</th><th>Reset</th><th>Actor</th><th>Created</th></tr></thead><tbody>';
        foreach ($purchases as $row) {
            echo '<tr>';
            echo '<td>' . (int) $row['id'] . '</td>';
            echo '<td>' . (int) $row['whmcs_serviceid'] . '</td>';
            echo '<td>' . (int) $row['invoiceid'] . '</td>';
            echo '<td>' . htmlspecialchars($row['cycle_start'] . ' -> ' . $row['cycle_end']) . '</td>';
            echo '<td>' . htmlspecialchars($row['reset_at']) . '</td>';
            echo '<td>' . htmlspecialchars($row['actor']) . '</td>';
            echo '<td>' . htmlspecialchars($row['created_at']) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';

        $logs = $this->getEnforcementLogs();
        echo '<h3>Enforcement Logs</h3>';
        echo '<table class="table table-striped"><thead><tr><th>Level</th><th>Message</th><th>Time</th></tr></thead><tbody>';
        foreach ($logs as $log) {
            echo '<tr>';
            echo '<td>' . htmlspecialchars($log['level']) . '</td>';
            echo '<td>' . htmlspecialchars($log['message']) . '</td>';
            echo '<td>' . htmlspecialchars($log['created_at']) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
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

    private function getPurchaseLogs(): array
    {
        return Capsule::table('mod_easydcim_bw_guard_purchases')
            ->orderByDesc('id')
            ->limit(200)
            ->get()
            ->map(static fn ($r): array => (array) $r)
            ->all();
    }

    private function getEnforcementLogs(): array
    {
        return Capsule::table('mod_easydcim_bw_guard_logs')
            ->whereIn('message', ['traffic_enforced', 'traffic_unlocked', 'service_poll_failed'])
            ->orderByDesc('id')
            ->limit(200)
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
}
