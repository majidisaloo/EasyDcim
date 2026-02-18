<?php

declare(strict_types=1);

require_once dirname(__DIR__, 4) . '/init.php';
require_once __DIR__ . '/../lib/bootstrap.php';

use EasyDcimBw\ModuleFactory;

$manager = ModuleFactory::bandwidthManager();
$manager->processAll();

echo "EasyDCIM BW daily reconcile finished\n";
