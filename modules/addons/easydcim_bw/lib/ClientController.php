<?php

declare(strict_types=1);

namespace EasyDcimBw;

use WHMCS\Database\Capsule;

class ClientController
{
    /** @param array<string,mixed> $vars @return array<string,mixed> */
    public function render(array $vars): array
    {
        $settings = new Settings($vars);
        $logger = new Logger();
        $serviceRepo = new ServiceRepository($settings);
        $purchaseManager = new PurchaseManager($logger);
        $graphCache = new GraphCache($settings);
        $api = new EasyDcimApiClient($settings, $logger);
        $cycleCalculator = new CycleCalculator();

        $serviceId = (int) ($_GET['serviceid'] ?? 0);
        $service = Capsule::table('tblhosting')->where('id', $serviceId)->where('userid', $vars['clientsdetails']['id'] ?? 0)->first();
        if (!$service) {
            return [
                'pagetitle' => 'Bandwidth Manager',
                'templatefile' => 'client/error',
                'vars' => ['error' => 'Service not found'],
            ];
        }

        $cycle = $cycleCalculator->calculate((string) $service->nextduedate, (string) $service->billingcycle);
        $cycleStart = $cycle['start']->format('Y-m-d H:i:s');
        $cycleEnd = $cycle['end']->format('Y-m-d H:i:s');

        if (($_POST['action'] ?? '') === 'buy-package') {
            check_token('WHMCS.default');
            $package = $purchaseManager->getPackage((int) $_POST['package_id']);
            if ($package) {
                $purchaseManager->createInvoiceAndRecord((int) $service->userid, $serviceId, $package, $cycleStart, $cycleEnd, false);
            }
            header('Location: index.php?m=easydcim_bw&serviceid=' . $serviceId);
            exit;
        }

        $eServiceId = (int) ($serviceRepo->getCustomFieldValue($serviceId, 'easydcim_service_id') ?? 0);
        $mode = strtoupper((string) ($_GET['mode'] ?? 'TOTAL'));
        $graphPayload = [
            'type' => 'AggregateTraffic',
            'target' => 'service',
            'start' => $cycleStart,
            'end' => date('Y-m-d H:i:s'),
            'raw' => false,
        ];

        $graphData = $graphCache->get($serviceId, $graphPayload['start'], $graphPayload['end'], $graphPayload);
        if ($graphData === null && $eServiceId > 0) {
            $graphData = $api->exportGraph($eServiceId, $graphPayload, (string) $vars['clientsdetails']['email']);
            $graphCache->put($serviceId, $graphPayload['start'], $graphPayload['end'], $graphPayload, $graphData);
        }

        $state = Capsule::table('mod_easydcim_bw_service_state')->where('serviceid', $serviceId)->first();
        $packages = Capsule::table('mod_easydcim_bw_packages')->where('active', 1)->orderBy('size_gb')->get();

        return [
            'pagetitle' => 'Bandwidth Manager',
            'breadcrumb' => ['index.php?m=easydcim_bw&serviceid=' . $serviceId => 'Bandwidth Manager'],
            'templatefile' => 'client/dashboard',
            'requirelogin' => true,
            'forcessl' => false,
            'vars' => [
                'serviceid' => $serviceId,
                'cycle_start' => $cycleStart,
                'cycle_end' => $cycleEnd,
                'state' => $state,
                'packages' => $packages,
                'mode' => $mode,
                'graph_data' => $graphData,
            ],
        ];
    }
}
