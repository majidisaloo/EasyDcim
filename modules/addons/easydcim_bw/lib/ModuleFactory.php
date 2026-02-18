<?php

declare(strict_types=1);

namespace EasyDcimBw;

use WHMCS\Database\Capsule;

class ModuleFactory
{
    public static function settingsFromDatabase(): Settings
    {
        $rows = Capsule::table('tbladdonmodules')->where('module', 'easydcim_bw')->get();
        $config = [];
        foreach ($rows as $row) {
            $config[$row->setting] = $row->value;
        }

        return new Settings($config);
    }

    public static function bandwidthManager(): BandwidthManager
    {
        $settings = self::settingsFromDatabase();
        $logger = new Logger();

        return new BandwidthManager(
            $settings,
            new ServiceRepository($settings),
            new StateRepository(),
            new EasyDcimApiClient($settings, $logger),
            new CycleCalculator(),
            new PurchaseManager($logger),
            $logger
        );
    }
}
