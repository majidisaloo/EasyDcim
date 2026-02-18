<?php

declare(strict_types=1);

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

require_once __DIR__ . '/lib/Autoloader.php';

\EasyDcimBandwidthGuard\Autoloader::register();

use EasyDcimBandwidthGuard\Bootstrap\Module;

function easydcim_bandwidth_guard_config(): array
{
    return Module::config();
}

function easydcim_bandwidth_guard_activate(): array
{
    return Module::activate();
}

function easydcim_bandwidth_guard_deactivate(): array
{
    return Module::deactivate();
}

function easydcim_bandwidth_guard_upgrade(array $vars): array
{
    return Module::upgrade($vars);
}

function easydcim_bandwidth_guard_output(array $vars): void
{
    Module::output($vars);
}

function easydcim_bandwidth_guard_clientarea(array $vars): array
{
    return Module::clientArea($vars);
}
