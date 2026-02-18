<?php

namespace EasyDcimBandwidth;

class EasyDcimClient
{
    private Config $config;

    public function __construct(Config $config)
    {
        $this->config = $config;
    }

    public function request(string $method, string $path, array $body = [], ?string $impersonate = null): array
    {
        $url = rtrim($this->config->get('base_url', ''), '/') . $path;
        $token = $this->config->get('api_token', '');

        $headers = [
            'Authorization: Bearer ' . $token,
            'Accept: application/json',
            'Content-Type: application/json',
        ];

        if ($this->config->isEnabled('use_impersonation') && $impersonate) {
            $headers[] = 'X-Impersonate-User: ' . $impersonate;
        }

        $start = microtime(true);
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, strtoupper($method));
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);
        if (!empty($body)) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        }

        $responseBody = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        $elapsedMs = (int) round((microtime(true) - $start) * 1000);

        Logger::log('api', [
            'method' => $method,
            'path' => $path,
            'status' => $status,
            'elapsed_ms' => $elapsedMs,
            'error' => $error,
            'request_body' => $body,
            'response_body' => $responseBody,
        ]);

        return [
            'status' => $status,
            'error' => $error,
            'data' => json_decode($responseBody ?: '[]', true),
        ];
    }

    public function getBandwidth(int $serviceId, string $start, string $end, ?string $impersonate): array
    {
        return $this->request('POST', '/api/v3/client/services/' . $serviceId . '/bandwidth', [
            'start' => $start,
            'end' => $end,
        ], $impersonate);
    }

    public function getPorts(int $serviceId, ?string $impersonate): array
    {
        return $this->request('GET', '/api/v3/client/services/' . $serviceId . '/ports?with_traffic=true', [], $impersonate);
    }

    public function disablePort(int $portId): array
    {
        return $this->request('POST', '/api/v3/admin/ports/' . $portId . '/disable');
    }

    public function enablePort(int $portId): array
    {
        return $this->request('POST', '/api/v3/admin/ports/' . $portId . '/enable');
    }

    public function suspendOrder(int $orderId): array
    {
        return $this->request('POST', '/api/v3/admin/orders/' . $orderId . '/service/suspend');
    }

    public function unsuspendOrder(int $orderId): array
    {
        return $this->request('POST', '/api/v3/admin/orders/' . $orderId . '/service/unsuspend');
    }

    public function exportGraph(int $targetId, string $target, string $start, string $end, bool $raw, ?string $impersonate): array
    {
        return $this->request('POST', '/api/v3/client/graphs/' . $targetId . '/export', [
            'type' => 'AggregateTraffic',
            'target' => $target,
            'start' => $start,
            'end' => $end,
            'raw' => $raw,
        ], $impersonate);
    }
}
