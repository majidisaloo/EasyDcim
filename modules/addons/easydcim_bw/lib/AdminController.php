<?php

declare(strict_types=1);

namespace EasyDcimBw;

use WHMCS\Database\Capsule;

class AdminController
{
    /** @param array<string,mixed> $vars */
    public function render(array $vars): void
    {
        $action = $_REQUEST['action'] ?? 'dashboard';

        if ($action === 'save-product-default') {
            $this->saveProductDefault();
            header('Location: addonmodules.php?module=easydcim_bw');
            exit;
        }

        if ($action === 'save-override') {
            $this->saveOverride();
            header('Location: addonmodules.php?module=easydcim_bw');
            exit;
        }

        if ($action === 'save-package') {
            $this->savePackage();
            header('Location: addonmodules.php?module=easydcim_bw');
            exit;
        }

        $defaults = Capsule::table('mod_easydcim_bw_product_defaults')->orderBy('pid')->get();
        $packages = Capsule::table('mod_easydcim_bw_packages')->orderBy('id', 'desc')->get();
        $overrides = Capsule::table('mod_easydcim_bw_service_overrides')->orderBy('serviceid')->limit(200)->get();
        $logs = Capsule::table('mod_easydcim_bw_logs')->orderBy('id', 'desc')->limit(50)->get();

        include __DIR__ . '/../templates/admin/dashboard.php';
    }

    private function saveProductDefault(): void
    {
        Capsule::table('mod_easydcim_bw_product_defaults')->updateOrInsert(
            ['pid' => (int) $_POST['pid']],
            [
                'default_quota_gb' => (float) $_POST['default_quota_gb'],
                'default_mode' => strtoupper((string) $_POST['default_mode']),
                'default_action' => (string) $_POST['default_action'],
                'enabled' => isset($_POST['enabled']) ? 1 : 0,
                'updated_at' => date('Y-m-d H:i:s'),
                'created_at' => date('Y-m-d H:i:s'),
            ]
        );
    }

    private function saveOverride(): void
    {
        Capsule::table('mod_easydcim_bw_service_overrides')->updateOrInsert(
            ['serviceid' => (int) $_POST['serviceid']],
            [
                'override_base_quota_gb' => $_POST['override_base_quota_gb'] !== '' ? (float) $_POST['override_base_quota_gb'] : null,
                'override_mode' => $_POST['override_mode'] !== '' ? strtoupper((string) $_POST['override_mode']) : null,
                'override_action' => $_POST['override_action'] !== '' ? (string) $_POST['override_action'] : null,
                'updated_at' => date('Y-m-d H:i:s'),
                'created_at' => date('Y-m-d H:i:s'),
            ]
        );
    }

    private function savePackage(): void
    {
        Capsule::table('mod_easydcim_bw_packages')->insert([
            'name' => trim((string) $_POST['name']),
            'size_gb' => (float) $_POST['size_gb'],
            'price' => (float) $_POST['price'],
            'taxed' => isset($_POST['taxed']) ? 1 : 0,
            'available_for_pids' => trim((string) $_POST['available_for_pids']),
            'available_for_gids' => trim((string) $_POST['available_for_gids']),
            'active' => 1,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }
}
