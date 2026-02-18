<?php

declare(strict_types=1);

namespace EasyDcimBandwidthGuard\Application;

use EasyDcimBandwidthGuard\Config\Settings;
use EasyDcimBandwidthGuard\Infrastructure\Git\GitUpdateManager;
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

        $version = Version::current($this->moduleDir);
        $updateAvailable = Capsule::table('mod_easydcim_bw_meta')->where('meta_key', 'update_available')->value('meta_value') === '1';
        $lastPollAt = (string) Capsule::table('mod_easydcim_bw_meta')->where('meta_key', 'last_poll_at')->value('meta_value');
        $apiFailCount = (int) Capsule::table('mod_easydcim_bw_meta')->where('meta_key', 'api_fail_count')->value('meta_value');
        $updateLock = Capsule::table('mod_easydcim_bw_meta')->where('meta_key', 'update_in_progress')->value('meta_value') === '1';

        echo '<link rel="stylesheet" href="../modules/addons/easydcim_bandwidth_guard/assets/admin.css">';
        echo '<div class="edbw-wrap">';
        echo '<h2>EasyDCIM Bandwidth Guard</h2>';
        echo '<div class="edbw-card"><strong>Running Version:</strong> ' . htmlspecialchars($version['module_version']) . '</div>';
        echo '<div class="edbw-card"><strong>Commit:</strong> ' . htmlspecialchars($version['commit_sha']) . '</div>';
        echo '<div class="edbw-card"><strong>Update Status:</strong> ' . ($updateAvailable ? 'New commit available' : 'Up to date') . '</div>';
        echo '<div class="edbw-card"><strong>Last Poll:</strong> ' . htmlspecialchars($lastPollAt ?: 'N/A') . '</div>';
        echo '<div class="edbw-card"><strong>API Fail Count:</strong> ' . $apiFailCount . '</div>';
        echo '<div class="edbw-card"><strong>Update Lock:</strong> ' . ($updateLock ? 'Locked' : 'Free') . '</div>';

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
        $packages = Capsule::table('mod_easydcim_bw_packages')->orderBy('id')->limit(100)->get();
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
        return Capsule::table('mod_easydcim_bw_purchases')
            ->orderByDesc('id')
            ->limit(200)
            ->get()
            ->map(static fn ($r): array => (array) $r)
            ->all();
    }

    private function getEnforcementLogs(): array
    {
        return Capsule::table('mod_easydcim_bw_logs')
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

        Capsule::table('mod_easydcim_bw_packages')->insert([
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
}
