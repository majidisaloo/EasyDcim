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
            'description' => 'Traffic control for EasyDCIM with safe migrations and Git updates.',
            'version' => $version['module_version'],
            'author' => 'Majid Isaloo',
            'fields' => [],
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
            if (stripos($e->getMessage(), 'no active transaction') !== false) {
                return ['status' => 'success', 'description' => 'Module activated. Migration completed with a non-fatal transaction warning.'];
            }
            return ['status' => 'error', 'description' => 'Activation failed: ' . $e->getMessage()];
        }
    }

    public static function deactivate(): array
    {
        $logger = new Logger();
        try {
            $settings = new Settings(Settings::loadFromDatabase());
            if ($settings->getBool('purge_on_deactivate', false)) {
                (new Migrator($logger))->purgeModuleData();
                Capsule::table('tbladdonmodules')->where('module', 'easydcim_bw')->delete();
                return ['status' => 'success', 'description' => 'Module deactivated and all module data was purged (as configured).'];
            }

            return ['status' => 'success', 'description' => 'Module deactivated. Data/settings were preserved.'];
        } catch (\Throwable $e) {
            return ['status' => 'error', 'description' => 'Deactivate failed: ' . $e->getMessage()];
        }
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
        $settings = new Settings(Settings::loadFromDatabase());
        $logger = new Logger();
        $controller = new AdminController($settings, $logger, __DIR__ . '/../../');
        $controller->handle($vars);
    }

    public static function clientArea(array $vars): array
    {
        $settings = new Settings(Settings::loadFromDatabase());
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
                        new Logger(),
                        [
                            'enabled' => $settings->getBool('proxy_enabled', false),
                            'type' => $settings->getString('proxy_type', 'http'),
                            'host' => $settings->getString('proxy_host'),
                            'port' => $settings->getInt('proxy_port', 0),
                            'username' => $settings->getString('proxy_username'),
                            'password' => Crypto::safeDecrypt($settings->getString('proxy_password')),
                        ]
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
