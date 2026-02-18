<?php

declare(strict_types=1);

namespace EasyDcimBandwidthGuard\Domain;

use EasyDcimBandwidthGuard\Infrastructure\EasyDcimClient;
use EasyDcimBandwidthGuard\Support\Logger;

final class EnforcementService
{
    private EasyDcimClient $client;
    private Logger $logger;

    public function __construct(EasyDcimClient $client, Logger $logger)
    {
        $this->client = $client;
        $this->logger = $logger;
    }

    public function enforce(string $action, string $serviceId, ?string $orderId, ?string $impersonateUser = null): void
    {
        if (in_array($action, ['disable_ports', 'both'], true)) {
            $ports = $this->client->ports($serviceId, false, $impersonateUser);
            foreach (($ports['data']['data'] ?? $ports['data'] ?? []) as $port) {
                if (!empty($port['id'])) {
                    $this->client->disablePort((string) $port['id']);
                }
            }
        }

        if (in_array($action, ['suspend', 'both'], true) && $orderId) {
            $this->client->suspendOrder($orderId);
        }

        $this->logger->log('WARNING', 'traffic_enforced', [
            'service_id' => $serviceId,
            'order_id' => $orderId,
            'action' => $action,
        ]);
    }

    public function unlock(string $action, string $serviceId, ?string $orderId, ?string $impersonateUser = null): void
    {
        if (in_array($action, ['disable_ports', 'both'], true)) {
            $ports = $this->client->ports($serviceId, false, $impersonateUser);
            foreach (($ports['data']['data'] ?? $ports['data'] ?? []) as $port) {
                if (!empty($port['id'])) {
                    $this->client->enablePort((string) $port['id']);
                }
            }
        }

        if (in_array($action, ['suspend', 'both'], true) && $orderId) {
            $this->client->unsuspendOrder($orderId);
        }

        $this->logger->log('INFO', 'traffic_unlocked', [
            'service_id' => $serviceId,
            'order_id' => $orderId,
            'action' => $action,
        ]);
    }
}
