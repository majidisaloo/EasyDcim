<?php

declare(strict_types=1);

use EasyDcimBw\Addon;
use EasyDcimBw\AdminController;
use EasyDcimBw\ClientController;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

require_once __DIR__ . '/lib/bootstrap.php';

function easydcim_bw_config(): array
{
    return Addon::config();
}

function easydcim_bw_activate(): array
{
    return Addon::activate();
}

function easydcim_bw_deactivate(): array
{
    return Addon::deactivate();
}

function easydcim_bw_upgrade(array $vars): array
{
    return Addon::upgrade($vars);
}

function easydcim_bw_output(array $vars): void
{
    (new AdminController())->render($vars);
}

function easydcim_bw_clientarea(array $vars): array
{
    return (new ClientController())->render($vars);
}
