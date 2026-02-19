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
    private array $proxy;
    private bool $allowSelfSigned;

    public function __construct(string $baseUrl, string $token, bool $impersonation, Logger $logger, array $proxy = [])
    {
        $baseUrl = trim($baseUrl);
        $baseUrl = preg_replace('#/backend/?$#i', '', $baseUrl) ?? $baseUrl;
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->token = $token;
        $this->impersonation = $impersonation;
        $this->logger = $logger;
        $this->proxy = $proxy;
        $this->allowSelfSigned = (bool) ($proxy['allow_self_signed'] ?? false);
    }

    public function bandwidth(string $serviceId, string $start, string $end, ?string $impersonateUser = null): array
    {
        return $this->request('POST', '/api/v3/client/services/' . rawurlencode($serviceId) . '/bandwidth', [
            'startDate' => $start,
            'endDate' => $end,
        ], $impersonateUser);
    }

    public function ports(string $serviceId, bool $withTraffic = false, ?string $impersonateUser = null, bool $throwOnError = true): array
    {
        $query = $withTraffic ? '?with_traffic=true' : '';
        return $this->request('GET', '/api/v3/client/services/' . rawurlencode($serviceId) . '/ports' . $query, null, $impersonateUser, $throwOnError);
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

        $result = $this->pingInfo();
        return ($result['http_code'] ?? 0) >= 200 && ($result['http_code'] ?? 0) < 300;
    }

    public function listServices(?string $impersonateUser = null, array $query = []): array
    {
        return $this->request('GET', '/api/v3/client/services', null, $impersonateUser, false, 5, $query);
    }

    public function orderDetails(string $orderId): array
    {
        return $this->request('GET', '/api/v3/admin/orders/' . rawurlencode($orderId), null, null, false, 5);
    }

    public function listAdminOrders(array $query = []): array
    {
        return $this->request('GET', '/api/v3/admin/orders', null, null, false, 5, $query);
    }

    public function pingInfo(): array
    {
        if ($this->baseUrl === '' || $this->token === '') {
            return ['ok' => false, 'http_code' => 0, 'error' => 'missing_base_or_token'];
        }
        $result = $this->request('GET', '/api/v3/client/services', null, null, false, 5);
        $code = (int) ($result['http_code'] ?? 0);
        return [
            'ok' => $code >= 200 && $code < 300,
            'reachable' => ($code >= 200 && $code < 500) || trim((string) ($result['error'] ?? '')) === '',
            'http_code' => $code,
            'error' => (string) ($result['error'] ?? ''),
        ];
    }

    private function request(
        string $method,
        string $path,
        ?array $body = null,
        ?string $impersonateUser = null,
        bool $throwOnError = true,
        int $timeout = 5,
        array $query = []
    ): array
    {
        $url = $this->baseUrl . $path;
        if (!empty($query)) {
            $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($query);
        }
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
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, min(5, max(1, $timeout)));
        curl_setopt($ch, CURLOPT_TIMEOUT, max(1, $timeout));
        curl_setopt($ch, CURLOPT_LOW_SPEED_LIMIT, 1);
        curl_setopt($ch, CURLOPT_LOW_SPEED_TIME, max(3, min(8, $timeout)));
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        $this->applyProxyOptions($ch);
        if (str_starts_with(strtolower($url), 'https://') && $this->allowSelfSigned) {
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        }

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
        $decodedError = '';
        if (is_array($decoded)) {
            foreach (['error', 'message', 'detail', 'details', 'reason'] as $key) {
                if (!isset($decoded[$key])) {
                    continue;
                }
                if (is_string($decoded[$key]) && trim($decoded[$key]) !== '') {
                    $decodedError = trim($decoded[$key]);
                    break;
                }
                if (is_array($decoded[$key])) {
                    $decodedError = trim((string) json_encode($decoded[$key], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                    if ($decodedError !== '') {
                        break;
                    }
                }
            }
        }
        $errorOut = trim($error) !== '' ? $error : $decodedError;

        $this->logger->log('INFO', 'easydcim_api_call', [
            'method' => $method,
            'url' => $url,
            'path' => $path,
            'http_code' => $httpCode,
            'duration_ms' => $durationMs,
            'curl_error' => $errorOut,
        ]);

        $result = [
            'http_code' => $httpCode,
            'data' => is_array($decoded) ? $decoded : [],
            'raw' => $raw,
            'error' => $errorOut,
        ];

        if ($throwOnError && ($httpCode < 200 || $httpCode >= 300)) {
            throw new \RuntimeException('EasyDCIM request failed: ' . $path . ' code=' . $httpCode . ' error=' . $error);
        }

        return $result;
    }

    private function applyProxyOptions($ch): void
    {
        if (!(($this->proxy['enabled'] ?? false) === true)) {
            return;
        }

        $host = trim((string) ($this->proxy['host'] ?? ''));
        $port = (int) ($this->proxy['port'] ?? 0);
        if ($host === '' || $port <= 0) {
            return;
        }

        curl_setopt($ch, CURLOPT_PROXY, $host);
        curl_setopt($ch, CURLOPT_PROXYPORT, $port);

        $type = strtolower((string) ($this->proxy['type'] ?? 'http'));
        if ($type === 'socks5') {
            curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_SOCKS5);
        } elseif ($type === 'socks4') {
            curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_SOCKS4);
        } else {
            curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_HTTP);
            if ($type === 'https') {
                curl_setopt($ch, CURLOPT_HTTPPROXYTUNNEL, true);
            }
        }

        $username = (string) ($this->proxy['username'] ?? '');
        $password = (string) ($this->proxy['password'] ?? '');
        if ($username !== '') {
            curl_setopt($ch, CURLOPT_PROXYUSERPWD, $username . ':' . $password);
        }
    }
}
