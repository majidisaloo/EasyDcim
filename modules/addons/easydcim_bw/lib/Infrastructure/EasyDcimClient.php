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
            '/api/v3/admin/items/' . rawurlencode($serverId) . '/ports' . $query,
            '/api/v3/admin/servers/' . rawurlencode($serverId) . '/ports' . $query,
        ];

        $best = ['http_code' => 404, 'data' => [], 'raw' => null, 'error' => 'No server port endpoint matched'];
        foreach ($candidates as $path) {
            $response = $this->request('GET', $path, null, $impersonateUser, false);
            $code = (int) ($response['http_code'] ?? 0);
            if ($code >= 200 && $code < 300) {
                return $response;
            }
            if ($code > (int) ($best['http_code'] ?? 0)) {
                $best = $response;
            }
        }

        // Fallback: some EasyDCIM installations expose ports only through admin list endpoint.
        $adminListResponse = $this->adminPortsByItemId($serverId);
        $adminListCode = (int) ($adminListResponse['http_code'] ?? 0);
        if ($adminListCode >= 200 && $adminListCode < 300) {
            return $adminListResponse;
        }
        if ($adminListCode > (int) ($best['http_code'] ?? 0)) {
            $best = $adminListResponse;
        }

        // Final fallback: some installations expose ports only inside server/item details payload.
        $detailsResponse = $this->serverDetailsById($serverId);
        $detailsCode = (int) ($detailsResponse['http_code'] ?? 0);
        if ($detailsCode >= 200 && $detailsCode < 300) {
            return $detailsResponse;
        }
        if ($detailsCode > (int) ($best['http_code'] ?? 0)) {
            $best = $detailsResponse;
        }

        return $best;
    }

    public function portDetails(string $portId): array
    {
        $portId = trim($portId);
        if ($portId === '') {
            return ['http_code' => 0, 'data' => [], 'raw' => null, 'error' => 'missing_port_id'];
        }
        return $this->request('GET', '/api/v3/admin/ports/' . rawurlencode($portId), null, null, false, 5);
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

    public function orderPorts(string $orderId, bool $withTraffic = false): array
    {
        $query = $withTraffic ? '?with_traffic=true' : '';
        $candidates = [
            '/api/v3/admin/orders/' . rawurlencode($orderId) . '/ports' . $query,
            '/api/v3/admin/orders/' . rawurlencode($orderId) . '/service/ports' . $query,
        ];

        $last = ['http_code' => 404, 'data' => [], 'raw' => null, 'error' => 'No order ports endpoint matched'];
        foreach ($candidates as $path) {
            $response = $this->request('GET', $path, null, null, false, 5);
            $code = (int) ($response['http_code'] ?? 0);
            if ($code >= 200 && $code < 300) {
                return $response;
            }
            $last = $response;
        }

        return $last;
    }

    public function adminPortsByItemId(string $itemId): array
    {
        $itemId = trim($itemId);
        if ($itemId === '') {
            return ['http_code' => 0, 'data' => [], 'raw' => null, 'error' => 'missing_item_id'];
        }

        $queries = [
            ['page' => 1, 'per_page' => 100, 'search_term' => $itemId, 'search_op' => 'eq', 'search_fields' => 'item_id'],
            ['page' => 1, 'per_page' => 100, 'search_term' => $itemId, 'search_op' => 'eq', 'search_fields' => ['item_id']],
            ['page' => 1, 'per_page' => 100, 'search_term' => $itemId, 'search_op' => 'eq'],
        ];

        $best = ['http_code' => 404, 'data' => [], 'raw' => null, 'error' => 'No admin ports list matched'];
        foreach ($queries as $query) {
            $response = $this->request('GET', '/api/v3/admin/ports', null, null, false, 5, $query);
            $code = (int) ($response['http_code'] ?? 0);
            if ($code >= 200 && $code < 300 && $this->responseContainsRows((array) ($response['data'] ?? []))) {
                return $response;
            }
            if ($code > (int) ($best['http_code'] ?? 0)) {
                $best = $response;
            }
        }

        return $best;
    }

    public function serverDetailsById(string $serverId): array
    {
        $serverId = trim($serverId);
        if ($serverId === '') {
            return ['http_code' => 0, 'data' => [], 'raw' => null, 'error' => 'missing_server_id'];
        }

        $candidates = [
            '/api/v3/admin/servers/' . rawurlencode($serverId),
            '/api/v3/admin/items/' . rawurlencode($serverId),
            '/api/v3/client/servers/' . rawurlencode($serverId),
            '/api/v3/client/items/' . rawurlencode($serverId),
        ];

        $best = ['http_code' => 404, 'data' => [], 'raw' => null, 'error' => 'No server details endpoint matched'];
        foreach ($candidates as $path) {
            $response = $this->request('GET', $path, null, null, false, 5);
            $code = (int) ($response['http_code'] ?? 0);
            if ($code >= 200 && $code < 300) {
                return $response;
            }
            if ($code > (int) ($best['http_code'] ?? 0)) {
                $best = $response;
            }
        }

        return $best;
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

    private function responseContainsRows(array $payload): bool
    {
        if (array_keys($payload) === range(0, count($payload) - 1)) {
            return count($payload) > 0;
        }
        foreach (['items', 'data', 'records', 'rows', 'collection'] as $key) {
            if (!isset($payload[$key]) || !is_array($payload[$key])) {
                continue;
            }
            $value = $payload[$key];
            if (array_keys($value) === range(0, count($value) - 1)) {
                return count($value) > 0;
            }
            foreach (['items', 'data', 'records', 'rows'] as $nested) {
                if (isset($value[$nested]) && is_array($value[$nested]) && count($value[$nested]) > 0) {
                    return true;
                }
            }
        }
        return false;
    }
}
