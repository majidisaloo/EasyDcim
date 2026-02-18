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
            foreach ($this->resolvePorts($serviceId, $impersonateUser, $serverId) as $port) {
                if (!empty($port['id'])) {
                    $portId = (string) $port['id'];
                    if ($this->testMode) {
                        $this->logger->log('INFO', 'test_mode_action', [
                            'action' => 'disable_port',
                            'service_id' => $serviceId,
                            'port_id' => $portId,
                            'command' => 'POST /api/v3/admin/ports/' . $portId . '/disable',
                        ]);
                    } else {
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
            foreach ($this->resolvePorts($serviceId, $impersonateUser, $serverId) as $port) {
                if (!empty($port['id'])) {
                    $portId = (string) $port['id'];
                    if ($this->testMode) {
                        $this->logger->log('INFO', 'test_mode_action', [
                            'action' => 'enable_port',
                            'service_id' => $serviceId,
                            'port_id' => $portId,
                            'command' => 'POST /api/v3/admin/ports/' . $portId . '/enable',
                        ]);
                    } else {
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
        if (!empty($list)) {
            return is_array($list) ? $list : [];
        }

        if (!$serverId) {
            return [];
        }

        $fallback = $this->client->portsByServer($serverId, false, $impersonateUser);
        $fallbackList = $fallback['data']['data'] ?? $fallback['data'] ?? [];
        return is_array($fallbackList) ? $fallbackList : [];
    }
}
