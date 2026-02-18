<?php

declare(strict_types=1);

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

require_once __DIR__ . '/lib/Autoloader.php';

\EasyDcimBandwidthGuard\Autoloader::register();

use EasyDcimBandwidthGuard\Bootstrap\Module;

function easydcim_bw_config(): array
{
    return Module::config();
}

function easydcim_bw_activate(): array
{
    return Module::activate();
}

function easydcim_bw_deactivate(): array
{
    return Module::deactivate();
}

function easydcim_bw_upgrade(array $vars): array
{
    return Module::upgrade($vars);
}

function easydcim_bw_output(array $vars): void
{
    Module::output($vars);
}

function easydcim_bw_clientarea(array $vars): array
{
    return Module::clientArea($vars);
}
