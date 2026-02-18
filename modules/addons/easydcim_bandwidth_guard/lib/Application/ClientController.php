<?php

declare(strict_types=1);

namespace EasyDcimBandwidthGuard\Application;

use EasyDcimBandwidthGuard\Config\Settings;
use EasyDcimBandwidthGuard\Domain\CycleCalculator;
use EasyDcimBandwidthGuard\Domain\GraphService;
use EasyDcimBandwidthGuard\Domain\PurchaseService;
use EasyDcimBandwidthGuard\Infrastructure\EasyDcimClient;
use EasyDcimBandwidthGuard\Support\Crypto;
use EasyDcimBandwidthGuard\Support\Logger;
use WHMCS\Database\Capsule;

final class ClientController
{
    private Settings $settings;
    private Logger $logger;

    public function __construct(Settings $settings, Logger $logger)
    {
        $this->settings = $settings;
        $this->logger = $logger;
    }

    public function buildTemplateVars(int $userId): array
    {
        $service = Capsule::table('mod_easydcim_bw_service_state')
            ->where('userid', $userId)
            ->orderByDesc('id')
            ->first();

        if (!$service) {
            return [
                'has_service' => false,
                'message' => 'No managed service found for your account.',
                'chart_json' => json_encode(['labels' => [], 'datasets' => []]),
                'purchases' => [],
            ];
        }

        $client = new EasyDcimClient(
            $this->settings->getString('easydcim_base_url'),
            Crypto::safeDecrypt($this->settings->getString('easydcim_api_token')),
            $this->settings->getBool('use_impersonation', false),
            $this->logger
        );

        $cycle = new CycleCalculator();
        $hosting = Capsule::table('tblhosting')->where('id', $service->serviceid)->first();
        $window = $cycle->calculate((string) $hosting->nextduedate, (string) $hosting->billingcycle);

        $graphService = new GraphService($client);
        $chart = $graphService->getCachedOrFetch(
            (int) $service->serviceid,
            (string) $service->easydcim_service_id,
            $window['start'],
            date('Y-m-d H:i:s'),
            max(5, $this->settings->getInt('graph_cache_minutes', 30)),
            $this->settings->getBool('use_impersonation', false) ? (string) Capsule::table('tblclients')->where('id', $userId)->value('email') : null
        );

        $flash = '';
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($_POST['buy_package_id'])) {
            $flash = $this->handleBuyPackage($userId, (int) $service->serviceid, $window, (int) $_POST['buy_package_id']);
        }

        $packages = Capsule::table('mod_easydcim_bw_packages')
            ->where('is_active', 1)
            ->orderBy('size_gb')
            ->get()
            ->map(static fn ($r): array => (array) $r)
            ->all();

        $purchases = Capsule::table('mod_easydcim_bw_purchases')
            ->where('whmcs_serviceid', $service->serviceid)
            ->where('cycle_start', $window['start'])
            ->where('cycle_end', $window['end'])
            ->orderByDesc('id')
            ->limit(50)
            ->get()
            ->map(static fn ($r): array => (array) $r)
            ->all();

        return [
            'has_service' => true,
            'service_id' => (int) $service->serviceid,
            'used_gb' => (float) $service->last_used_gb,
            'remaining_gb' => (float) $service->last_remaining_gb,
            'status' => (string) $service->last_status,
            'mode' => (string) $service->mode,
            'cycle_start' => $window['start'],
            'cycle_end' => $window['end'],
            'reset_at' => $window['reset_at'],
            'flash' => $flash,
            'chart_json' => json_encode($chart, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'packages' => $packages,
            'purchases' => $purchases,
        ];
    }

    private function handleBuyPackage(int $userId, int $serviceId, array $window, int $packageId): string
    {
        $package = Capsule::table('mod_easydcim_bw_packages')->where('id', $packageId)->where('is_active', 1)->first();
        if (!$package) {
            return 'Selected package is not available.';
        }

        if (!function_exists('localAPI')) {
            return 'localAPI is unavailable.';
        }

        $create = localAPI('CreateInvoice', [
            'userid' => $userId,
            'status' => 'Unpaid',
            'sendinvoice' => true,
            'itemdescription1' => sprintf('Extra Bandwidth %.2fGB for service #%d', (float) $package->size_gb, $serviceId),
            'itemamount1' => (float) $package->price,
            'itemtaxed1' => (int) $package->taxed,
        ]);
        if (($create['result'] ?? '') !== 'success') {
            return 'Could not create invoice.';
        }

        $invoiceId = (int) ($create['invoiceid'] ?? 0);
        if ($invoiceId <= 0) {
            return 'Invoice creation returned invalid invoice id.';
        }

        $status = (string) Capsule::table('tblinvoices')->where('id', $invoiceId)->value('status');
        $paymentStatus = strtolower($status) === 'paid' ? 'paid' : 'pending';

        (new PurchaseService($this->logger))->recordPurchase([
            'whmcs_serviceid' => $serviceId,
            'userid' => $userId,
            'package_id' => (int) $package->id,
            'size_gb' => (float) $package->size_gb,
            'price' => (float) $package->price,
            'invoice_id' => $invoiceId,
            'cycle_start' => $window['start'],
            'cycle_end' => $window['end'],
            'reset_at' => $window['reset_at'],
            'actor' => 'client_manual',
            'payment_status' => $paymentStatus,
            'created_at' => date('Y-m-d H:i:s'),
            'context' => [
                'whmcs_service_id' => $serviceId,
                'userid' => $userId,
                'invoice_id' => $invoiceId,
                'package_id' => (int) $package->id,
                'size_gb' => (float) $package->size_gb,
                'price' => (float) $package->price,
                'cycle_start' => $window['start'],
                'cycle_end' => $window['end'],
                'reset_at' => $window['reset_at'],
                'purchased_at' => date('Y-m-d H:i:s'),
                'actor' => 'client_manual',
                'payment_status' => $paymentStatus,
            ],
        ]);

        return 'Invoice #' . $invoiceId . ' created successfully for this cycle.';
    }
}
