<?php

declare(strict_types=1);

namespace EasyDcimBandwidthGuard\Bootstrap;

use EasyDcimBandwidthGuard\Application\AdminController;
use EasyDcimBandwidthGuard\Application\ClientController;
use EasyDcimBandwidthGuard\Config\Settings;
use EasyDcimBandwidthGuard\Infrastructure\Db\Migrator;
use EasyDcimBandwidthGuard\Infrastructure\EasyDcimClient;
use EasyDcimBandwidthGuard\Support\Crypto;
use EasyDcimBandwidthGuard\Support\Logger;
use EasyDcimBandwidthGuard\Support\Version;
use WHMCS\Database\Capsule;

final class Module
{
    public static function config(): array
    {
        $version = Version::current(__DIR__ . '/../../');

        return [
            'name' => 'EasyDcim-BW',
            'description' => 'Cycle-aware traffic control with EasyDCIM API, one-click git updates, and production-safe migrations.',
            'version' => $version['module_version'],
            'author' => 'Majid Isaloo',
            'fields' => [
                'easydcim_base_url' => ['FriendlyName' => 'EasyDCIM Base URL', 'Type' => 'text', 'Size' => '80', 'Default' => ''],
                'easydcim_api_token' => ['FriendlyName' => 'Admin API Token', 'Type' => 'password', 'Size' => '80', 'Default' => ''],
                'use_impersonation' => ['FriendlyName' => 'Use Impersonation', 'Type' => 'yesno', 'Description' => 'Enable X-Impersonate-User header'],
                'managed_pids' => ['FriendlyName' => 'Managed Product IDs', 'Type' => 'text', 'Size' => '80', 'Description' => 'Comma-separated PID list'],
                'managed_gids' => ['FriendlyName' => 'Managed Group IDs', 'Type' => 'text', 'Size' => '80', 'Description' => 'Comma-separated GID list'],
                'poll_interval_minutes' => ['FriendlyName' => 'Poll Interval (min)', 'Type' => 'text', 'Size' => '10', 'Default' => '15'],
                'graph_cache_minutes' => ['FriendlyName' => 'Graph Cache (min)', 'Type' => 'text', 'Size' => '10', 'Default' => '30'],
                'autobuy_enabled' => ['FriendlyName' => 'Auto-Buy Enabled', 'Type' => 'yesno'],
                'autobuy_threshold_gb' => ['FriendlyName' => 'Auto-Buy Threshold GB', 'Type' => 'text', 'Size' => '10', 'Default' => '10'],
                'autobuy_default_package_id' => ['FriendlyName' => 'Auto-Buy Default Package ID', 'Type' => 'text', 'Size' => '10', 'Default' => '0'],
                'autobuy_max_per_cycle' => ['FriendlyName' => 'Auto-Buy Max/Cycle', 'Type' => 'text', 'Size' => '10', 'Default' => '5'],
                'git_update_enabled' => ['FriendlyName' => 'Git Update Check Enabled', 'Type' => 'yesno', 'Description' => 'Check for new commits during cron'],
                'git_origin_url' => ['FriendlyName' => 'Git Origin URL', 'Type' => 'text', 'Size' => '120', 'Default' => ''],
                'git_branch' => ['FriendlyName' => 'Git Branch', 'Type' => 'text', 'Size' => '20', 'Default' => 'main'],
                'update_channel' => ['FriendlyName' => 'Update Channel', 'Type' => 'dropdown', 'Options' => 'stable,commit', 'Default' => 'commit'],
                'update_check_interval_minutes' => ['FriendlyName' => 'Update Check Interval (min)', 'Type' => 'text', 'Size' => '10', 'Default' => '30'],
                'update_mode' => ['FriendlyName' => 'Update Mode', 'Type' => 'dropdown', 'Options' => 'notify,check_oneclick,auto', 'Default' => 'check_oneclick'],
                'preflight_strict_mode' => ['FriendlyName' => 'Preflight Strict Mode', 'Type' => 'yesno', 'Description' => 'Block risky update actions'],
            ],
        ];
    }

    public static function activate(): array
    {
        $logger = new Logger();
        try {
            $migrator = new Migrator($logger);
            $migrator->migrate();

            Capsule::table('mod_easydcim_bw_guard_meta')->updateOrInsert(
                ['meta_key' => 'activated_at'],
                ['meta_value' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')]
            );

            $logger->log('INFO', 'module_activated', ['module' => 'easydcim_bw']);
            return ['status' => 'success', 'description' => 'Module activated with production-safe migrations.'];
        } catch (\Throwable $e) {
            return ['status' => 'error', 'description' => 'Activation failed: ' . $e->getMessage()];
        }
    }

    public static function deactivate(): array
    {
        return ['status' => 'success', 'description' => 'Module deactivated. Data kept intact.'];
    }

    public static function upgrade(array $vars): array
    {
        $logger = new Logger();
        try {
            (new Migrator($logger))->migrate();
            return ['status' => 'success'];
        } catch (\Throwable $e) {
            return ['status' => 'error', 'description' => 'Upgrade failed: ' . $e->getMessage()];
        }
    }

    public static function output(array $vars): void
    {
        $settings = new Settings($vars);
        $logger = new Logger();
        $controller = new AdminController($settings, $logger, __DIR__ . '/../../');
        $controller->handle($vars);
    }

    public static function clientArea(array $vars): array
    {
        $settings = new Settings($vars);
        $logger = new Logger();
        $controller = new ClientController($settings, $logger);

        $userId = (int) ($_SESSION['uid'] ?? 0);
        $data = $controller->buildTemplateVars($userId);

        return [
            'pagetitle' => 'Traffic Usage',
            'breadcrumb' => ['index.php?m=easydcim_bw' => 'Traffic Usage'],
            'templatefile' => 'clientarea',
            'requirelogin' => true,
            'forcessl' => false,
            'vars' => $data,
        ];
    }

    public static function preflight(array $addonConfig): array
    {
        $errors = [];
        if (version_compare(PHP_VERSION, '8.0.0', '<')) {
            $errors[] = 'PHP 8.0+ is required';
        }

        try {
            Capsule::connection()->select('SELECT 1');
        } catch (\Throwable $e) {
            $errors[] = 'Database connection failed: ' . $e->getMessage();
        }

        $settings = new Settings($addonConfig);
        if ($settings->getBool('preflight_strict_mode', true)) {
            $token = $settings->getString('easydcim_api_token');
            if ($token !== '' && $settings->getString('easydcim_base_url') !== '') {
                try {
                    $client = new EasyDcimClient(
                        $settings->getString('easydcim_base_url'),
                        Crypto::safeDecrypt($token),
                        $settings->getBool('use_impersonation', false),
                        new Logger()
                    );
                    if (!$client->ping()) {
                        $errors[] = 'EasyDCIM token/base URL ping check failed';
                    }
                } catch (\Throwable $e) {
                    $errors[] = 'EasyDCIM preflight failed: ' . $e->getMessage();
                }
            }
        }

        return $errors;
    }
}
