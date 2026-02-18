<?php

declare(strict_types=1);

namespace EasyDcimBw;

use WHMCS\Database\Capsule;

class GraphCache
{
    public function __construct(private readonly Settings $settings)
    {
    }

    /** @param array<string,mixed> $payload @return array<string,mixed>|null */
    public function get(int $serviceId, string $rangeStart, string $rangeEnd, array $payload): ?array
    {
        $hash = hash('sha256', json_encode($payload));
        $ttlMinutes = $this->settings->getInt('graph_cache_minutes', 30);
        $minDate = date('Y-m-d H:i:s', time() - $ttlMinutes * 60);

        $row = Capsule::table('mod_easydcim_bw_graph_cache')
            ->where('whmcs_serviceid', $serviceId)
            ->where('range_start', $rangeStart)
            ->where('range_end', $rangeEnd)
            ->where('payload_hash', $hash)
            ->where('cached_at', '>=', $minDate)
            ->first();

        if (!$row) {
            return null;
        }

        return json_decode((string) $row->json_data, true) ?? null;
    }

    /** @param array<string,mixed> $payload @param array<string,mixed> $result */
    public function put(int $serviceId, string $rangeStart, string $rangeEnd, array $payload, array $result): void
    {
        $hash = hash('sha256', json_encode($payload));

        Capsule::table('mod_easydcim_bw_graph_cache')->insert([
            'whmcs_serviceid' => $serviceId,
            'range_start' => $rangeStart,
            'range_end' => $rangeEnd,
            'payload_hash' => $hash,
            'json_data' => json_encode($result),
            'cached_at' => date('Y-m-d H:i:s'),
        ]);
    }
}
