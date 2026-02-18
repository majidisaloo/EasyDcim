<?php

namespace EasyDcimBandwidth;

use WHMCS\Database\Capsule;

class AdminController
{
    private array $vars;
    private Config $config;
    private Repository $repository;
    private BandwidthManager $manager;

    public function __construct(array $vars)
    {
        $this->vars = $vars;
        $this->config = new Config($vars);
        $this->repository = new Repository();
        $this->manager = new BandwidthManager($this->config);
    }

    public function render(): string
    {
        $action = $_REQUEST['action'] ?? '';

        if ($action === 'run_check') {
            $this->manager->processAllEligibleServices();
        }

        if ($action === 'save_product_default' && !empty($_POST['pid'])) {
            Capsule::table('mod_easydcim_bw_product_defaults')->updateOrInsert(
                ['pid' => (int) $_POST['pid']],
                [
                    'default_quota_gb' => (float) $_POST['default_quota_gb'],
                    'default_mode' => strtoupper((string) $_POST['default_mode']),
                    'default_action' => (string) $_POST['default_action'],
                    'enabled' => !empty($_POST['enabled']) ? 1 : 0,
                    'updated_at' => date('Y-m-d H:i:s'),
                    'created_at' => date('Y-m-d H:i:s'),
                ]
            );
        }

        if ($action === 'save_override' && !empty($_POST['serviceid'])) {
            Capsule::table('mod_easydcim_bw_service_overrides')->updateOrInsert(
                ['serviceid' => (int) $_POST['serviceid']],
                [
                    'override_base_quota_gb' => (float) $_POST['override_base_quota_gb'],
                    'override_mode' => strtoupper((string) $_POST['override_mode']),
                    'override_action' => !empty($_POST['override_action']) ? (string) $_POST['override_action'] : null,
                    'updated_at' => date('Y-m-d H:i:s'),
                    'created_at' => date('Y-m-d H:i:s'),
                ]
            );
        }

        $states = Capsule::table('mod_easydcim_bw_service_state')->orderBy('last_check_at', 'desc')->limit(100)->get();
        $logs = Capsule::table('mod_easydcim_bw_logs')->orderBy('id', 'desc')->limit(100)->get();

        ob_start();
        ?>
        <h2>EasyDCIM Bandwidth Manager</h2>
        <p>Use module configuration fields for API settings, product scope, cache, and auto-buy limits.</p>
        <p><a class="btn btn-primary" href="addonmodules.php?module=easydcim_bandwidth&action=run_check">Run Reconcile Now</a></p>

        <h3>Per-Product Defaults</h3>
        <form method="post" action="addonmodules.php?module=easydcim_bandwidth&action=save_product_default">
            <input type="number" name="pid" placeholder="PID" required>
            <input type="number" step="0.01" name="default_quota_gb" placeholder="Quota GB" required>
            <select name="default_mode">
                <option value="IN">IN</option>
                <option value="OUT">OUT</option>
                <option value="TOTAL" selected>TOTAL</option>
            </select>
            <select name="default_action">
                <option value="disable_ports">Disable Ports</option>
                <option value="suspend">Suspend</option>
                <option value="both">Both</option>
            </select>
            <label><input type="checkbox" name="enabled" value="1" checked> Enabled</label>
            <button type="submit" class="btn btn-default">Save</button>
        </form>

        <h3>Per-Service Permanent Override</h3>
        <form method="post" action="addonmodules.php?module=easydcim_bandwidth&action=save_override">
            <input type="number" name="serviceid" placeholder="WHMCS Service ID" required>
            <input type="number" step="0.01" name="override_base_quota_gb" placeholder="Override Quota GB" required>
            <select name="override_mode">
                <option value="IN">IN</option>
                <option value="OUT">OUT</option>
                <option value="TOTAL" selected>TOTAL</option>
            </select>
            <select name="override_action">
                <option value="">No Override Action</option>
                <option value="disable_ports">Disable Ports</option>
                <option value="suspend">Suspend</option>
                <option value="both">Both</option>
            </select>
            <button type="submit" class="btn btn-default">Save Override</button>
        </form>

        <h3>Service States</h3>
        <table class="table table-striped">
            <thead><tr><th>Service</th><th>Cycle</th><th>Used GB</th><th>Remaining GB</th><th>Status</th><th>Updated</th></tr></thead>
            <tbody>
            <?php foreach ($states as $row): ?>
                <tr>
                    <td><?php echo (int) $row->serviceid; ?></td>
                    <td><?php echo htmlspecialchars((string) $row->cycle_start . ' → ' . (string) $row->cycle_end); ?></td>
                    <td><?php echo (float) $row->last_used_gb; ?></td>
                    <td><?php echo (float) $row->last_remaining_gb; ?></td>
                    <td><?php echo htmlspecialchars((string) $row->last_status); ?></td>
                    <td><?php echo htmlspecialchars((string) $row->last_check_at); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <h3>Recent Logs</h3>
        <table class="table table-condensed">
            <thead><tr><th>ID</th><th>Level</th><th>Context</th><th>Time</th></tr></thead>
            <tbody>
            <?php foreach ($logs as $log): ?>
                <tr>
                    <td><?php echo (int) $log->id; ?></td>
                    <td><?php echo htmlspecialchars((string) $log->level); ?></td>
                    <td><pre style="max-width: 720px; white-space: pre-wrap;"><?php echo htmlspecialchars((string) $log->context_json); ?></pre></td>
                    <td><?php echo htmlspecialchars((string) $log->created_at); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php
        return ob_get_clean();
    }
}
