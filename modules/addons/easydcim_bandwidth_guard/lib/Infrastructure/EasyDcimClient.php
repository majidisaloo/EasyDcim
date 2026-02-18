<?php

declare(strict_types=1);

namespace EasyDcimBandwidthGuard\Infrastructure;

use EasyDcimBandwidthGuard\Support\Logger;

final class EasyDcimClient
{
    private string $baseUrl;
    private string $token;
    private bool $impersonation;
    private Logger $logger;

    public function __construct(string $baseUrl, string $token, bool $impersonation, Logger $logger)
    {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->token = $token;
        $this->impersonation = $impersonation;
        $this->logger = $logger;
    }

    public function bandwidth(string $serviceId, string $start, string $end, ?string $impersonateUser = null): array
    {
        return $this->request('POST', '/api/v3/client/services/' . rawurlencode($serviceId) . '/bandwidth', [
            'start' => $start,
            'end' => $end,
        ], $impersonateUser);
    }

    public function ports(string $serviceId, bool $withTraffic = false, ?string $impersonateUser = null): array
    {
        $query = $withTraffic ? '?with_traffic=true' : '';
        return $this->request('GET', '/api/v3/client/services/' . rawurlencode($serviceId) . '/ports' . $query, null, $impersonateUser);
    }

    public function portsByServer(string $serverId, bool $withTraffic = false, ?string $impersonateUser = null): array
    {
        $query = $withTraffic ? '?with_traffic=true' : '';
        $candidates = [
            '/api/v3/client/items/' . rawurlencode($serverId) . '/ports' . $query,
            '/api/v3/client/servers/' . rawurlencode($serverId) . '/ports' . $query,
        ];

        foreach ($candidates as $path) {
            $response = $this->request('GET', $path, null, $impersonateUser, false);
            $code = (int) ($response['http_code'] ?? 0);
            if ($code >= 200 && $code < 300) {
                return $response;
            }
        }

        return ['http_code' => 404, 'data' => [], 'raw' => null, 'error' => 'No server port endpoint matched'];
    }

    public function disablePort(string $portId): array
    {
        return $this->request('POST', '/api/v3/admin/ports/' . rawurlencode($portId) . '/disable');
    }

    public function enablePort(string $portId): array
    {
        return $this->request('POST', '/api/v3/admin/ports/' . rawurlencode($portId) . '/enable');
    }

    public function suspendOrder(string $orderId): array
    {
        return $this->request('POST', '/api/v3/admin/orders/' . rawurlencode($orderId) . '/service/suspend');
    }

    public function unsuspendOrder(string $orderId): array
    {
        return $this->request('POST', '/api/v3/admin/orders/' . rawurlencode($orderId) . '/service/unsuspend');
    }

    public function graphExport(string $targetId, array $body, ?string $impersonateUser = null): array
    {
        return $this->request('POST', '/api/v3/client/graphs/' . rawurlencode($targetId) . '/export', $body, $impersonateUser);
    }

    public function ping(): bool
    {
        if ($this->baseUrl === '' || $this->token === '') {
            return false;
        }

        $result = $this->request('GET', '/api/v3/client/services', null, null, false);
        return ($result['http_code'] ?? 0) >= 200 && ($result['http_code'] ?? 0) < 500;
    }

    private function request(string $method, string $path, ?array $body = null, ?string $impersonateUser = null, bool $throwOnError = true): array
    {
        $url = $this->baseUrl . $path;
        $ch = curl_init($url);
        $headers = [
            'Authorization: Bearer ' . $this->token,
            'Accept: application/json',
            'Content-Type: application/json',
        ];

        if ($this->impersonation && $impersonateUser) {
            $headers[] = 'X-Impersonate-User: ' . $impersonateUser;
        }

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, strtoupper($method));
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_SLASHES));
        }

        $started = microtime(true);
        $raw = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        $durationMs = (int) ((microtime(true) - $started) * 1000);
        $decoded = is_string($raw) ? json_decode($raw, true) : null;

        $this->logger->log('INFO', 'easydcim_api_call', [
            'method' => $method,
            'path' => $path,
            'http_code' => $httpCode,
            'duration_ms' => $durationMs,
            'curl_error' => $error,
        ]);

        $result = [
            'http_code' => $httpCode,
            'data' => is_array($decoded) ? $decoded : [],
            'raw' => $raw,
            'error' => $error,
        ];

        if ($throwOnError && ($httpCode < 200 || $httpCode >= 300)) {
            throw new \RuntimeException('EasyDCIM request failed: ' . $path . ' code=' . $httpCode . ' error=' . $error);
        }

        return $result;
    }
}
