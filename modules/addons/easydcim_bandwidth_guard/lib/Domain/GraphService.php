<?php

declare(strict_types=1);

namespace EasyDcimBandwidthGuard\Domain;

use EasyDcimBandwidthGuard\Infrastructure\EasyDcimClient;
use WHMCS\Database\Capsule;

final class GraphService
{
    private EasyDcimClient $client;

    public function __construct(EasyDcimClient $client)
    {
        $this->client = $client;
    }

    public function getCachedOrFetch(
        int $whmcsServiceId,
        string $targetServiceId,
        string $start,
        string $end,
        int $cacheMinutes,
        ?string $impersonateUser = null
    ): array {
        $payload = [
            'type' => 'AggregateTraffic',
            'target' => 'service',
            'start' => $start,
            'end' => $end,
            'raw' => false,
        ];
        $hash = hash('sha256', json_encode($payload));

        $cacheRow = Capsule::table('mod_easydcim_bw_graph_cache')
            ->where('whmcs_serviceid', $whmcsServiceId)
            ->where('payload_hash', $hash)
            ->orderByDesc('cached_at')
            ->first();

        if ($cacheRow && strtotime((string) $cacheRow->cached_at) > time() - ($cacheMinutes * 60)) {
            return json_decode((string) $cacheRow->json_data, true) ?: [];
        }

        $response = $this->client->graphExport($targetServiceId, $payload, $impersonateUser);
        $data = $response['data'] ?? [];

        Capsule::table('mod_easydcim_bw_graph_cache')->insert([
            'whmcs_serviceid' => $whmcsServiceId,
            'range_start' => $start,
            'range_end' => $end,
            'payload_hash' => $hash,
            'json_data' => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'cached_at' => date('Y-m-d H:i:s'),
        ]);

        return $data;
    }
}
