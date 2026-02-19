<?php

declare(strict_types=1);

use EasyDcimBandwidthGuard\Application\CronRunner;
use EasyDcimBandwidthGuard\Autoloader;
use EasyDcimBandwidthGuard\Config\Settings;
use EasyDcimBandwidthGuard\Support\Logger;
use WHMCS\Database\Capsule;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

require_once __DIR__ . '/lib/Autoloader.php';
Autoloader::register();

add_hook('AfterCronJob', 1, static function (): void {
    if (!Capsule::schema()->hasTable('mod_easydcim_bw_guard_meta')) {
        return;
    }

    $configRows = Capsule::table('tbladdonmodules')
        ->where('module', 'easydcim_bw')
        ->get(['setting', 'value']);

    $addonConfig = [];
    foreach ($configRows as $row) {
        $addonConfig[$row->setting] = $row->value;
    }
    if (empty($addonConfig)) {
        return;
    }

    $settings = new Settings($addonConfig);
    $runner = new CronRunner($settings, new Logger());

    Capsule::table('mod_easydcim_bw_guard_meta')->updateOrInsert(
        ['meta_key' => 'last_whmcs_cron_at'],
        ['meta_value' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')]
    );

    $lastPoll = Capsule::table('mod_easydcim_bw_guard_meta')->where('meta_key', 'last_poll_at')->value('meta_value');
    $interval = max(5, $settings->getInt('poll_interval_minutes', 15));
    if (!$lastPoll || strtotime((string) $lastPoll) <= time() - ($interval * 60)) {
        $runner->runPoll();
        Capsule::table('mod_easydcim_bw_guard_meta')->updateOrInsert(
            ['meta_key' => 'last_poll_at'],
            ['meta_value' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')]
        );
    }

    $runner->runUpdateCheck(__DIR__);
});

add_hook('AdminServicesTabFields', 1, static function (array $vars): array {
    $serviceId = (int) ($vars['serviceid'] ?? 0);
    if ($serviceId <= 0) {
        return [];
    }

    $override = Capsule::table('mod_easydcim_bw_guard_service_overrides')->where('serviceid', $serviceId)->first();

    return [
        'EasyDcim-BW Override Quota (GB)' => $override ? (string) ($override->override_base_quota_gb ?? '') : '',
        'EasyDcim-BW Override Mode (IN/OUT/TOTAL)' => $override ? (string) ($override->override_mode ?? '') : '',
        'EasyDcim-BW Override Action (disable_ports/suspend/both)' => $override ? (string) ($override->override_action ?? '') : '',
    ];
});

add_hook('AdminServicesTabFieldsSave', 1, static function (array $vars): void {
    $serviceId = (int) ($vars['serviceid'] ?? 0);
    if ($serviceId <= 0) {
        return;
    }

    $quota = isset($_POST['EasyDcim-BW Override Quota (GB)']) ? trim((string) $_POST['EasyDcim-BW Override Quota (GB)']) : '';
    $mode = isset($_POST['EasyDcim-BW Override Mode (IN/OUT/TOTAL)']) ? strtoupper(trim((string) $_POST['EasyDcim-BW Override Mode (IN/OUT/TOTAL)'])) : '';
    $action = isset($_POST['EasyDcim-BW Override Action (disable_ports/suspend/both)']) ? trim((string) $_POST['EasyDcim-BW Override Action (disable_ports/suspend/both)']) : '';

    if ($mode !== '' && !in_array($mode, ['IN', 'OUT', 'TOTAL'], true)) {
        $mode = '';
    }
    if ($action !== '' && !in_array($action, ['disable_ports', 'suspend', 'both'], true)) {
        $action = '';
    }

    Capsule::table('mod_easydcim_bw_guard_service_overrides')->updateOrInsert(
        ['serviceid' => $serviceId],
        [
            'override_base_quota_gb' => $quota !== '' ? (float) $quota : null,
            'override_mode' => $mode !== '' ? $mode : null,
            'override_action' => $action !== '' ? $action : null,
            'updated_at' => date('Y-m-d H:i:s'),
            'created_at' => date('Y-m-d H:i:s'),
        ]
    );
});
