<?php

declare(strict_types=1);

namespace EasyDcimBw;

use RuntimeException;
use WHMCS\Database\Capsule;

class BandwidthManager
{
    public function __construct(
        private readonly Settings $settings,
        private readonly ServiceRepository $serviceRepository,
        private readonly StateRepository $stateRepository,
        private readonly EasyDcimApiClient $api,
        private readonly CycleCalculator $cycleCalculator,
        private readonly PurchaseManager $purchaseManager,
        private readonly Logger $logger
    ) {
    }

    public function processAll(): void
    {
        foreach ($this->serviceRepository->getEligibleServices() as $service) {
            try {
                $this->processService($service);
            } catch (\Throwable $e) {
                $this->logger->log('error', [
                    'event' => 'service_process_failed',
                    'serviceid' => $service['serviceid'] ?? null,
                    'message' => $e->getMessage(),
                ]);
            }
        }
    }

    /** @param array<string,mixed> $service */
    public function processService(array $service): void
    {
        $serviceId = (int) $service['serviceid'];
        $eServiceId = (int) ($this->serviceRepository->getCustomFieldValue($serviceId, 'easydcim_service_id') ?? 0);
        if ($eServiceId <= 0) {
            throw new RuntimeException('Missing easydcim_service_id custom field');
        }

        $eOrderId = (int) ($this->serviceRepository->getCustomFieldValue($serviceId, 'easydcim_order_id') ?? 0);
        $modeCustom = strtoupper((string) ($this->serviceRepository->getCustomFieldValue($serviceId, 'traffic_mode') ?? ''));
        $customBaseOverride = $this->serviceRepository->getCustomFieldValue($serviceId, 'base_quota_override_gb');

        $cycle = $this->cycleCalculator->calculate((string) $service['nextduedate'], (string) $service['billingcycle']);
        $cycleStart = $cycle['start']->format('Y-m-d H:i:s');
        $cycleEnd = $cycle['end']->format('Y-m-d H:i:s');

        $productDefault = $this->serviceRepository->getProductDefault((int) $service['pid']) ?? [];
        $override = $this->serviceRepository->getOverride($serviceId) ?? [];

        $baseQuota = (float) ($override['override_base_quota_gb'] ?? $customBaseOverride ?? $productDefault['default_quota_gb'] ?? 0);
        $mode = strtoupper((string) ($override['override_mode'] ?? $modeCustom ?: ($productDefault['default_mode'] ?? 'TOTAL')));
        $action = (string) ($override['override_action'] ?? $productDefault['default_action'] ?? 'disable_ports');

        $client = Capsule::table('tblclients')->where('id', $service['userid'])->first();
        $impersonate = $this->settings->getString('impersonate_mode') === 'userid'
            ? (string) $service['userid']
            : (string) ($client->email ?? '');

        $usageResponse = $this->api->getServiceBandwidth($eServiceId, $cycleStart, $cycleEnd, $impersonate);
        $usedGb = $this->extractUsageGb($usageResponse, $mode);

        $extrasGb = $this->serviceRepository->sumCyclePurchases($serviceId, $cycleStart, $cycleEnd);
        $allowedGb = $baseQuota + $extrasGb;
        $remainingGb = max($allowedGb - $usedGb, 0);

        $state = $this->stateRepository->get($serviceId) ?? [];
        $status = $remainingGb > 0 ? 'ok' : 'limited';

        if ($remainingGb <= 0) {
            $this->enforce($action, $eServiceId, $eOrderId, $impersonate);
        } else {
            $this->relaxIfNeeded($action, $eServiceId, $eOrderId, $state);
        }

        $this->tryAutoBuy($service, $cycleStart, $cycleEnd, $remainingGb, $status);

        $this->stateRepository->upsert($serviceId, [
            'userid' => $service['userid'],
            'easydcim_service_id' => $eServiceId,
            'easydcim_order_id' => $eOrderId ?: null,
            'cycle_start' => $cycleStart,
            'cycle_end' => $cycleEnd,
            'base_quota_gb' => $baseQuota,
            'mode' => $mode,
            'action' => $action,
            'last_used_gb' => $usedGb,
            'last_remaining_gb' => $remainingGb,
            'last_status' => $status,
            'last_check_at' => date('Y-m-d H:i:s'),
            'ports_limited' => in_array($action, ['disable_ports', 'both'], true) && $remainingGb <= 0 ? 1 : 0,
            'service_suspended' => in_array($action, ['suspend', 'both'], true) && $remainingGb <= 0 ? 1 : 0,
        ]);
    }

    /** @param array<string,mixed> $response */
    private function extractUsageGb(array $response, string $mode): float
    {
        $candidates = [
            'IN' => ['in', 'inbound', 'download', 'rx', 'traffic_in'],
            'OUT' => ['out', 'outbound', 'upload', 'tx', 'traffic_out'],
            'TOTAL' => ['total', 'sum', 'traffic_total'],
        ][$mode] ?? ['total'];

        foreach ($candidates as $key) {
            if (isset($response[$key])) {
                return $this->toGb((float) $response[$key]);
            }
            if (isset($response['data'][$key])) {
                return $this->toGb((float) $response['data'][$key]);
            }
        }

        return $this->toGb((float) ($response['data']['total'] ?? $response['total'] ?? 0));
    }

    private function toGb(float $bytes): float
    {
        return round($bytes / 1073741824, 3);
    }

    private function enforce(string $action, int $serviceId, int $orderId, ?string $impersonate): void
    {
        if (in_array($action, ['disable_ports', 'both'], true)) {
            foreach ($this->api->getServicePorts($serviceId, $impersonate) as $port) {
                $portId = (int) ($port['id'] ?? 0);
                if ($portId > 0) {
                    $this->api->disablePort($portId);
                }
            }
            $this->logger->log('warning', ['event' => 'ports_disabled', 'service_id' => $serviceId]);
        }

        if (in_array($action, ['suspend', 'both'], true) && $orderId > 0) {
            $this->api->suspendOrderService($orderId);
            $this->logger->log('warning', ['event' => 'service_suspended', 'order_id' => $orderId]);
        }
    }

    /** @param array<string,mixed> $state */
    private function relaxIfNeeded(string $action, int $serviceId, int $orderId, array $state): void
    {
        if (in_array($action, ['disable_ports', 'both'], true) && (int) ($state['ports_limited'] ?? 0) === 1) {
            foreach ($this->api->getServicePorts($serviceId) as $port) {
                $portId = (int) ($port['id'] ?? 0);
                if ($portId > 0) {
                    $this->api->enablePort($portId);
                }
            }
            $this->logger->log('info', ['event' => 'ports_enabled', 'service_id' => $serviceId]);
        }

        if (in_array($action, ['suspend', 'both'], true) && $orderId > 0 && (int) ($state['service_suspended'] ?? 0) === 1) {
            $this->api->unsuspendOrderService($orderId);
            $this->logger->log('info', ['event' => 'service_unsuspended', 'order_id' => $orderId]);
        }
    }

    /** @param array<string,mixed> $service */
    private function tryAutoBuy(array $service, string $cycleStart, string $cycleEnd, float $remainingGb, string &$status): void
    {
        if (!$this->settings->getBool('autobuy_enabled')) {
            return;
        }

        $threshold = $this->settings->getFloat('autobuy_threshold_gb', 10);
        if ($remainingGb > $threshold) {
            return;
        }

        $packageId = $this->settings->getInt('default_package_id');
        if ($packageId <= 0) {
            return;
        }

        $serviceId = (int) $service['serviceid'];
        $count = $this->purchaseManager->countAutoBuyInCycle($serviceId, $cycleStart, $cycleEnd);
        if ($count >= $this->settings->getInt('autobuy_max_per_cycle', 5)) {
            return;
        }

        $package = $this->purchaseManager->getPackage($packageId);
        if ($package === null) {
            return;
        }

        $credit = (float) Capsule::table('tblclients')->where('id', $service['userid'])->value('credit');
        if ($credit < (float) $package['price']) {
            $this->logger->log('warning', ['event' => 'autobuy_insufficient_credit', 'serviceid' => $serviceId]);
            return;
        }

        $invoiceId = $this->purchaseManager->createInvoiceAndRecord((int) $service['userid'], $serviceId, $package, $cycleStart, $cycleEnd, true);
        if ($invoiceId !== null) {
            $status = 'autobuy';
            $this->logger->log('info', ['event' => 'autobuy_success', 'invoice_id' => $invoiceId, 'serviceid' => $serviceId]);
        }
    }
}
