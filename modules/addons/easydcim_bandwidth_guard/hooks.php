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
        ->where('module', 'easydcim_bandwidth_guard')
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
