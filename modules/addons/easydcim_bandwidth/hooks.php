<?php

use EasyDcimBandwidth\BandwidthManager;
use EasyDcimBandwidth\Config;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

require_once __DIR__ . '/lib/Bootstrap.php';

add_hook('AfterCronJob', 1, function () {
    $vars = \WHMCS\Module\Addon\Setting::module('easydcim_bandwidth')->pluck('value', 'setting')->all();
    $config = new Config($vars);
    $manager = new BandwidthManager($config);
    $manager->processAllEligibleServices();
});

add_hook('DailyCronJob', 1, function () {
    $vars = \WHMCS\Module\Addon\Setting::module('easydcim_bandwidth')->pluck('value', 'setting')->all();
    $config = new Config($vars);
    $manager = new BandwidthManager($config);
    $manager->processAllEligibleServices();
});

add_hook('ClientAreaPrimarySidebar', 1, function ($primarySidebar) {
    $serviceId = (int) ($_GET['id'] ?? 0);
    if ($serviceId <= 0) {
        return;
    }

    if ($primarySidebar->hasChild('Service Details Actions')) {
        $primarySidebar->getChild('Service Details Actions')->addChild('Bandwidth Manager', [
            'uri' => 'index.php?m=easydcim_bandwidth&serviceid=' . $serviceId,
            'order' => 40,
            'icon' => 'fa-line-chart',
        ]);
    }
});
