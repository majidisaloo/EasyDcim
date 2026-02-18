<?php

namespace EasyDcimBandwidth;

class ClientAreaController
{
    private array $vars;
    private Config $config;
    private Repository $repository;
    private BandwidthManager $manager;

    public function __construct(array $vars)
    {
        $this->vars = $vars;
        $this->config = new Config($vars);
        $this->repository = new Repository();
        $this->manager = new BandwidthManager($this->config);
    }

    public function handle(): array
    {
        $serviceId = (int) ($_REQUEST['serviceid'] ?? 0);
        if ($serviceId <= 0) {
            return ['pagetitle' => 'Bandwidth Manager', 'templatefile' => 'clientarea', 'vars' => ['error' => 'Missing service ID']];
        }

        $service = $this->repository->getServiceById($serviceId);
        if (!$service) {
            return ['pagetitle' => 'Bandwidth Manager', 'templatefile' => 'clientarea', 'vars' => ['error' => 'Service not found']];
        }

        $cycle = (new CycleCalculator())->resolve((string) $service->billingcycle, (string) $service->nextduedate);
        $result = $this->manager->processService((array) $service);

        $graph = $this->manager->getGraphForService((array) $service, $cycle['start'], date('Y-m-d H:i:s'));
        $packages = $this->repository->listActivePackages();

        if (($_POST['bw_action'] ?? '') === 'buy_package' && !empty($_POST['package_id'])) {
            $this->handleBuyPackage((int) $service->id, (int) $service->userid, (int) $_POST['package_id'], $cycle);
            $result = $this->manager->processService((array) $service);
        }

        return [
            'pagetitle' => 'Bandwidth Manager',
            'breadcrumb' => ['clientarea.php?action=services' => 'My Services', '#' => 'Bandwidth'],
            'templatefile' => 'clientarea',
            'requirelogin' => true,
            'forcessl' => false,
            'vars' => [
                'serviceid' => $service->id,
                'result' => $result,
                'cycle' => $cycle,
                'graph' => $graph,
                'packages' => $packages,
            ],
        ];
    }

    private function handleBuyPackage(int $serviceId, int $userId, int $packageId, array $cycle): void
    {
        $package = $this->repository->getPackage($packageId);
        if (!$package) {
            return;
        }

        $invoiceResult = localAPI('CreateInvoice', [
            'userid' => $userId,
            'status' => 'Unpaid',
            'sendinvoice' => true,
            'itemdescription1' => sprintf('Extra Bandwidth %.2f GB for service #%d', $package->size_gb, $serviceId),
            'itemamount1' => $package->price,
            'itemtaxed1' => $package->taxed ? 1 : 0,
        ]);

        if (($invoiceResult['result'] ?? '') !== 'success') {
            Logger::log('purchase', ['serviceid' => $serviceId, 'status' => 'invoice_failed', 'api' => $invoiceResult]);
            return;
        }

        $invoiceId = (int) $invoiceResult['invoiceid'];
        $this->repository->recordPurchase([
            'whmcs_serviceid' => $serviceId,
            'userid' => $userId,
            'package_id' => (int) $package->id,
            'size_gb' => (float) $package->size_gb,
            'price' => (float) $package->price,
            'invoiceid' => $invoiceId,
            'cycle_start' => $cycle['start'],
            'cycle_end' => $cycle['end'],
        ]);

        Logger::log('purchase', ['serviceid' => $serviceId, 'status' => 'created', 'invoiceid' => $invoiceId]);
    }
}
