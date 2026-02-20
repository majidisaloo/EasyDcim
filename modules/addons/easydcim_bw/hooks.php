<?php

declare(strict_types=1);

use EasyDcimBandwidthGuard\Application\AdminController;
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
    if (!$settings->getBool('module_enabled', true)) {
        return;
    }
    $runner = new CronRunner($settings, new Logger());

    Capsule::table('mod_easydcim_bw_guard_meta')->updateOrInsert(
        ['meta_key' => 'last_whmcs_cron_at'],
        ['meta_value' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')]
    );

    $lastPoll = Capsule::table('mod_easydcim_bw_guard_meta')->where('meta_key', 'last_poll_at')->value('meta_value');
    $interval = max(1, $settings->getInt('poll_interval_minutes', 15));
    if (!$lastPoll || strtotime((string) $lastPoll) <= time() - ($interval * 60)) {
        $runner->runPoll();
        Capsule::table('mod_easydcim_bw_guard_meta')->updateOrInsert(
            ['meta_key' => 'last_poll_at'],
            ['meta_value' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')]
        );
    }

    $runner->runUpdateCheck(__DIR__);

    try {
        (new AdminController($settings, new Logger(), __DIR__))->runBackgroundMaintenanceFromCron(5);
    } catch (\Throwable $e) {
        (new Logger())->log('ERROR', 'servers_test_all_background_hook_failed', ['error' => $e->getMessage()]);
    }
});

add_hook('AdminServicesTabFields', 1, static function (array $vars): array {
    $serviceId = (int) ($vars['serviceid'] ?? 0);
    if ($serviceId <= 0) {
        return [];
    }

    $override = Capsule::table('mod_easydcim_bw_guard_service_overrides')->where('serviceid', $serviceId)->first();
    $state = Capsule::table('mod_easydcim_bw_guard_service_state')->where('serviceid', $serviceId)->first();
    $runtime = 'No data';
    if ($state) {
        $status = (string) ($state->last_status ?? 'ok');
        if ($status === 'limited') {
            $runtime = 'Active - Traffic limited (ports disabled/suspended by policy)';
        } else {
            $runtime = 'Active - Normal';
        }
    }

    $cycleStart = (string) ($state->cycle_start ?? '');
    $cycleEnd = (string) ($state->cycle_end ?? '');
    $resetAt = ($cycleEnd !== '' ? date('Y-m-d H:i:s', strtotime($cycleEnd) + 1) : '');
    $extraBought = 0.0;
    if ($cycleStart !== '' && $cycleEnd !== '') {
        $extraBought = (float) Capsule::table('mod_easydcim_bw_guard_purchases')
            ->where('whmcs_serviceid', $serviceId)
            ->where('cycle_start', $cycleStart)
            ->where('cycle_end', $cycleEnd)
            ->where('payment_status', 'paid')
            ->sum('size_gb');
    }
    $used = (float) ($state->last_used_gb ?? 0.0);
    $remaining = (float) ($state->last_remaining_gb ?? 0.0);
    $allowed = max(0.0, $used + $remaining);
    $basePlan = max(0.0, $allowed - $extraBought);

    return [
        'EasyDcim-BW Runtime Status' => $runtime,
        'EasyDcim-BW Cycle Window' => ($cycleStart !== '' && $cycleEnd !== '') ? ($cycleStart . ' -> ' . $cycleEnd) : 'No data',
        'EasyDcim-BW Reset At' => $resetAt !== '' ? $resetAt : 'No data',
        'EasyDcim-BW Base Plan (GB)' => number_format($basePlan, 2, '.', ''),
        'EasyDcim-BW Extra Bought (GB)' => number_format($extraBought, 2, '.', ''),
        'EasyDcim-BW Effective Allowed (GB)' => number_format($allowed, 2, '.', ''),
        'EasyDcim-BW Remaining (GB)' => number_format($remaining, 2, '.', ''),
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
