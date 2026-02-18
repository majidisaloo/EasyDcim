<?php

namespace EasyDcimBandwidth;

class BandwidthManager
{
    private Config $config;
    private Repository $repository;
    private EasyDcimClient $client;
    private CycleCalculator $cycles;

    public function __construct(Config $config)
    {
        $this->config = $config;
        $this->repository = new Repository();
        $this->client = new EasyDcimClient($config);
        $this->cycles = new CycleCalculator();
    }

    public function processAllEligibleServices(): void
    {
        $services = $this->repository->getEligibleServices(
            $this->config->getCsvInts('enabled_pids'),
            $this->config->getCsvInts('enabled_gids')
        );

        foreach ($services as $service) {
            $this->processService((array) $service);
        }
    }

    public function processService(array $service): array
    {
        $custom = $this->repository->getCustomFieldValues((int) $service['id']);
        $easydcimServiceId = (int) ($custom['easydcim_service_id'] ?? 0);
        $orderId = (int) ($custom['easydcim_order_id'] ?? 0);

        if ($easydcimServiceId <= 0) {
            Logger::log('warning', ['serviceid' => $service['id'], 'reason' => 'missing easydcim_service_id']);
            return ['status' => 'skipped'];
        }

        $cycle = $this->cycles->resolve((string) $service['billingcycle'], (string) $service['nextduedate']);
        $resolved = $this->resolveLimits((int) $service['id'], (int) $service['packageid'], $custom);
        $extra = $this->repository->getCyclePurchasesTotal((int) $service['id'], $cycle['start'], $cycle['end']);
        $allowed = (float) $resolved['base_quota_gb'] + $extra;

        $impersonate = $this->resolveImpersonation((int) $service['userid']);
        $bandwidth = $this->client->getBandwidth($easydcimServiceId, $cycle['start'], $cycle['end'], $impersonate);
        $usedGb = $this->extractUsageGb($bandwidth['data'], $resolved['mode']);
        $remaining = max(0, $allowed - $usedGb);

        $status = 'ok';
        if ($usedGb >= $allowed) {
            $status = 'limited';
            $this->enforce((int) $service['id'], $easydcimServiceId, $orderId, $resolved['action'], $impersonate);
        } else {
            $this->unlock((int) $service['id'], $easydcimServiceId, $orderId, $resolved['action'], $impersonate);
            if ($this->config->isEnabled('autobuy_enabled') && $remaining <= (float) $this->config->get('autobuy_threshold_gb', 10)) {
                $this->handleAutobuy((int) $service['id'], (int) $service['userid'], $cycle, $remaining);
            }
        }

        $this->repository->saveServiceState([
            'serviceid' => (int) $service['id'],
            'userid' => (int) $service['userid'],
            'easydcim_service_id' => $easydcimServiceId,
            'easydcim_order_id' => $orderId,
            'cycle_start' => $cycle['start'],
            'cycle_end' => $cycle['end'],
            'base_quota_gb' => $resolved['base_quota_gb'],
            'mode' => $resolved['mode'],
            'action' => $resolved['action'],
            'last_used_gb' => $usedGb,
            'last_remaining_gb' => $remaining,
            'last_status' => $status,
            'last_check_at' => date('Y-m-d H:i:s'),
        ]);

        return ['status' => $status, 'used_gb' => $usedGb, 'allowed_gb' => $allowed, 'remaining_gb' => $remaining];
    }

    public function getGraphForService(array $service, string $start, string $end, bool $raw = false): array
    {
        $custom = $this->repository->getCustomFieldValues((int) $service['id']);
        $easydcimServiceId = (int) ($custom['easydcim_service_id'] ?? 0);
        $impersonate = $this->resolveImpersonation((int) $service['userid']);
        $payloadHash = sha1($easydcimServiceId . '|AggregateTraffic|service|' . $start . '|' . $end . '|' . (int) $raw);
        $cacheTtl = (int) $this->config->get('graph_cache_minutes', 30);

        $cached = $this->repository->findGraphCache((int) $service['id'], $payloadHash, $cacheTtl);
        if ($cached !== null) {
            return $cached;
        }

        $graph = $this->client->exportGraph($easydcimServiceId, 'service', $start, $end, $raw, $impersonate);
        $data = $graph['data'] ?? [];

        $this->repository->storeGraphCache((int) $service['id'], $start, $end, $payloadHash, $data);
        return $data;
    }

    private function resolveLimits(int $serviceId, int $pid, array $custom): array
    {
        $defaults = $this->repository->getProductDefault($pid);
        $override = $this->repository->getServiceOverride($serviceId);

        $quota = (float) ($defaults->default_quota_gb ?? 0);
        $mode = strtoupper((string) ($defaults->default_mode ?? 'TOTAL'));
        $action = (string) ($defaults->default_action ?? $this->config->get('default_action', 'disable_ports'));

        if ($override) {
            $quota = (float) $override->override_base_quota_gb;
            $mode = strtoupper((string) $override->override_mode);
            if (!empty($override->override_action)) {
                $action = (string) $override->override_action;
            }
        }

        if (!empty($custom['base_quota_override_gb'])) {
            $quota = (float) $custom['base_quota_override_gb'];
        }

        if (!empty($custom['traffic_mode'])) {
            $mode = strtoupper((string) $custom['traffic_mode']);
        }

        return ['base_quota_gb' => $quota, 'mode' => $mode, 'action' => $action];
    }

    private function resolveImpersonation(int $userId): ?string
    {
        if (!$this->config->isEnabled('use_impersonation')) {
            return null;
        }

        $client = $this->repository->getClientById($userId);
        if (!$client) {
            return null;
        }

        $source = $this->config->get('impersonate_source', 'email');
        return $source === 'userid' ? (string) $client->id : (string) $client->email;
    }

    private function extractUsageGb(array $payload, string $mode): float
    {
        $mode = strtoupper($mode);

        foreach (['data', 'result', 'stats'] as $root) {
            if (isset($payload[$root]) && is_array($payload[$root])) {
                $payload = $payload[$root];
                break;
            }
        }

        $inBytes = (float) ($payload['in'] ?? $payload['inbound'] ?? 0);
        $outBytes = (float) ($payload['out'] ?? $payload['outbound'] ?? 0);
        $totalBytes = (float) ($payload['total'] ?? ($inBytes + $outBytes));

        $bytes = $totalBytes;
        if ($mode === 'IN') {
            $bytes = $inBytes;
        } elseif ($mode === 'OUT') {
            $bytes = $outBytes;
        }

        return $bytes / (1024 * 1024 * 1024);
    }

    private function enforce(int $serviceId, int $easydcimServiceId, int $orderId, string $action, ?string $impersonate): void
    {
        if (in_array($action, ['disable_ports', 'both'], true)) {
            $portsResponse = $this->client->getPorts($easydcimServiceId, $impersonate);
            $ports = $portsResponse['data']['data'] ?? $portsResponse['data'] ?? [];
            foreach ($ports as $port) {
                if (!isset($port['id'])) {
                    continue;
                }
                $this->client->disablePort((int) $port['id']);
            }
        }

        if (in_array($action, ['suspend', 'both'], true) && $orderId > 0) {
            $this->client->suspendOrder($orderId);
        }

        Logger::log('state_change', ['serviceid' => $serviceId, 'action' => 'enforce', 'mode' => $action]);
    }

    private function unlock(int $serviceId, int $easydcimServiceId, int $orderId, string $action, ?string $impersonate): void
    {
        if (in_array($action, ['disable_ports', 'both'], true)) {
            $portsResponse = $this->client->getPorts($easydcimServiceId, $impersonate);
            $ports = $portsResponse['data']['data'] ?? $portsResponse['data'] ?? [];
            foreach ($ports as $port) {
                if (!isset($port['id'])) {
                    continue;
                }
                $this->client->enablePort((int) $port['id']);
            }
        }

        if (in_array($action, ['suspend', 'both'], true) && $orderId > 0) {
            $this->client->unsuspendOrder($orderId);
        }

        Logger::log('state_change', ['serviceid' => $serviceId, 'action' => 'unlock', 'mode' => $action]);
    }

    private function handleAutobuy(int $serviceId, int $userId, array $cycle, float $remaining): void
    {
        $packageId = (int) $this->config->get('autobuy_default_package_id', 0);
        if ($packageId <= 0) {
            return;
        }

        $count = $this->repository->getAutobuyCount($serviceId, $cycle['start'], $cycle['end']);
        $max = (int) $this->config->get('autobuy_max_per_cycle', 5);
        if ($count >= $max) {
            Logger::log('autobuy', ['serviceid' => $serviceId, 'status' => 'max_reached', 'remaining' => $remaining]);
            return;
        }

        $package = $this->repository->getPackage($packageId);
        if (!$package) {
            return;
        }

        $invoiceResult = localAPI('CreateInvoice', [
            'userid' => $userId,
            'status' => 'Unpaid',
            'sendinvoice' => false,
            'itemdescription1' => sprintf('Auto-buy Extra Bandwidth %.2f GB for service #%d', $package->size_gb, $serviceId),
            'itemamount1' => $package->price,
            'itemtaxed1' => $package->taxed ? 1 : 0,
        ]);

        if (($invoiceResult['result'] ?? '') !== 'success') {
            Logger::log('autobuy', ['serviceid' => $serviceId, 'status' => 'invoice_failed', 'api' => $invoiceResult]);
            return;
        }

        $invoiceId = (int) $invoiceResult['invoiceid'];
        $applyCredit = localAPI('ApplyCredit', ['invoiceid' => $invoiceId, 'amount' => $package->price]);
        if (($applyCredit['result'] ?? '') !== 'success') {
            Logger::log('autobuy', ['serviceid' => $serviceId, 'status' => 'insufficient_credit']);
            return;
        }

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

        Logger::log('autobuy', ['serviceid' => $serviceId, 'status' => 'success', 'invoiceid' => $invoiceId]);
    }
}
