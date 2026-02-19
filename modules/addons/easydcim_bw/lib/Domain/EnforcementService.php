<?php

declare(strict_types=1);

namespace EasyDcimBandwidthGuard\Domain;

use EasyDcimBandwidthGuard\Infrastructure\EasyDcimClient;
use EasyDcimBandwidthGuard\Support\Logger;

final class EnforcementService
{
    private EasyDcimClient $client;
    private Logger $logger;
    private bool $testMode;

    public function __construct(EasyDcimClient $client, Logger $logger, bool $testMode = false)
    {
        $this->client = $client;
        $this->logger = $logger;
        $this->testMode = $testMode;
    }

    public function enforce(string $action, string $serviceId, ?string $orderId, ?string $impersonateUser = null, ?string $serverId = null): void
    {
        if (in_array($action, ['disable_ports', 'both'], true)) {
            if ($this->testMode) {
                $this->logger->log('INFO', 'test_mode_action', [
                    'action' => 'disable_ports',
                    'service_id' => $serviceId,
                    'order_id' => $orderId,
                    'command' => 'POST /api/v3/admin/ports/{portId}/disable',
                    'note' => 'Port discovery skipped in test mode to avoid high API load.',
                ]);
            } else {
            foreach ($this->resolvePorts($serviceId, $impersonateUser, $serverId) as $port) {
                if (!empty($port['id'])) {
                    $portId = (string) $port['id'];
                    $this->client->disablePort($portId);
                }
            }
            }
        }

        if (in_array($action, ['suspend', 'both'], true) && $orderId) {
            if ($this->testMode) {
                $this->logger->log('INFO', 'test_mode_action', [
                    'action' => 'suspend_order',
                    'service_id' => $serviceId,
                    'order_id' => $orderId,
                    'command' => 'POST /api/v3/admin/orders/' . $orderId . '/service/suspend',
                ]);
            } else {
                $this->client->suspendOrder($orderId);
            }
        }

        $this->logger->log($this->testMode ? 'INFO' : 'WARNING', 'traffic_enforced', [
            'service_id' => $serviceId,
            'order_id' => $orderId,
            'action' => $action,
            'test_mode' => $this->testMode ? 1 : 0,
        ]);
    }

    public function unlock(string $action, string $serviceId, ?string $orderId, ?string $impersonateUser = null, ?string $serverId = null): void
    {
        if (in_array($action, ['disable_ports', 'both'], true)) {
            if ($this->testMode) {
                $this->logger->log('INFO', 'test_mode_action', [
                    'action' => 'enable_ports',
                    'service_id' => $serviceId,
                    'order_id' => $orderId,
                    'command' => 'POST /api/v3/admin/ports/{portId}/enable',
                    'note' => 'Port discovery skipped in test mode to avoid high API load.',
                ]);
            } else {
            foreach ($this->resolvePorts($serviceId, $impersonateUser, $serverId) as $port) {
                if (!empty($port['id'])) {
                    $portId = (string) $port['id'];
                    $this->client->enablePort($portId);
                }
            }
            }
        }

        if (in_array($action, ['suspend', 'both'], true) && $orderId) {
            if ($this->testMode) {
                $this->logger->log('INFO', 'test_mode_action', [
                    'action' => 'unsuspend_order',
                    'service_id' => $serviceId,
                    'order_id' => $orderId,
                    'command' => 'POST /api/v3/admin/orders/' . $orderId . '/service/unsuspend',
                ]);
            } else {
                $this->client->unsuspendOrder($orderId);
            }
        }

        $this->logger->log('INFO', 'traffic_unlocked', [
            'service_id' => $serviceId,
            'order_id' => $orderId,
            'action' => $action,
            'test_mode' => $this->testMode ? 1 : 0,
        ]);
    }

    private function resolvePorts(string $serviceId, ?string $impersonateUser = null, ?string $serverId = null): array
    {
        $ports = $this->client->ports($serviceId, false, $impersonateUser);
        $list = $ports['data']['data'] ?? $ports['data'] ?? [];
        return is_array($list) ? $list : [];
    }
}
