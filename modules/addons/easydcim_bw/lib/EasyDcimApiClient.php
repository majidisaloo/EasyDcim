<?php

declare(strict_types=1);

namespace EasyDcimBw;

use RuntimeException;

class EasyDcimApiClient
{
    public function __construct(
        private readonly Settings $settings,
        private readonly Logger $logger
    ) {
    }

    /** @param array<string,mixed> $body @return array<string,mixed> */
    private function request(string $method, string $path, array $body = [], ?string $impersonate = null): array
    {
        $url = rtrim($this->settings->getString('base_url'), '/') . $path;
        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('Unable to initialize curl');
        }

        $headers = [
            'Authorization: Bearer ' . $this->settings->getString('api_token'),
            'Accept: application/json',
            'Content-Type: application/json',
        ];

        if ($this->settings->getBool('use_impersonation') && $impersonate !== null && $impersonate !== '') {
            $headers[] = 'X-Impersonate-User: ' . $impersonate;
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_HTTPHEADER => $headers,
        ]);

        if ($body !== []) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        }

        $startedAt = microtime(true);
        $raw = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        $durationMs = (int) ((microtime(true) - $startedAt) * 1000);

        $this->logger->log('info', [
            'event' => 'api_call',
            'method' => $method,
            'path' => $path,
            'http_code' => $code,
            'duration_ms' => $durationMs,
            'error' => $error,
        ]);

        if ($raw === false || $error !== '') {
            throw new RuntimeException('EasyDCIM API error: ' . $error);
        }

        /** @var array<string,mixed> $decoded */
        $decoded = json_decode($raw, true) ?? [];
        if ($code >= 400) {
            throw new RuntimeException('EasyDCIM API returned ' . $code . ' for ' . $path);
        }

        return $decoded;
    }

    /** @return array<string,mixed> */
    public function getServiceBandwidth(int $serviceId, string $start, string $end, ?string $impersonate = null): array
    {
        return $this->request('POST', '/api/v3/client/services/' . $serviceId . '/bandwidth', ['start' => $start, 'end' => $end], $impersonate);
    }

    /** @return array<int,array<string,mixed>> */
    public function getServicePorts(int $serviceId, ?string $impersonate = null): array
    {
        $result = $this->request('GET', '/api/v3/client/services/' . $serviceId . '/ports?with_traffic=true', [], $impersonate);

        return (array) ($result['data'] ?? $result);
    }

    public function disablePort(int $portId): void
    {
        $this->request('POST', '/api/v3/admin/ports/' . $portId . '/disable');
    }

    public function enablePort(int $portId): void
    {
        $this->request('POST', '/api/v3/admin/ports/' . $portId . '/enable');
    }

    public function suspendOrderService(int $orderId): void
    {
        $this->request('POST', '/api/v3/admin/orders/' . $orderId . '/service/suspend');
    }

    public function unsuspendOrderService(int $orderId): void
    {
        $this->request('POST', '/api/v3/admin/orders/' . $orderId . '/service/unsuspend');
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    public function exportGraph(int $targetId, array $payload, ?string $impersonate = null): array
    {
        return $this->request('POST', '/api/v3/client/graphs/' . $targetId . '/export', $payload, $impersonate);
    }
}
