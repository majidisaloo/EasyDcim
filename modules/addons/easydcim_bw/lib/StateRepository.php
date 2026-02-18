<?php

declare(strict_types=1);

namespace EasyDcimBw;

use WHMCS\Database\Capsule;

class StateRepository
{
    /** @return array<string,mixed>|null */
    public function get(int $serviceId): ?array
    {
        $row = Capsule::table('mod_easydcim_bw_service_state')->where('serviceid', $serviceId)->first();

        return $row ? (array) $row : null;
    }

    /** @param array<string,mixed> $payload */
    public function upsert(int $serviceId, array $payload): void
    {
        $existing = $this->get($serviceId);
        if ($existing === null) {
            $payload['serviceid'] = $serviceId;
            $payload['created_at'] = date('Y-m-d H:i:s');
            $payload['updated_at'] = date('Y-m-d H:i:s');
            Capsule::table('mod_easydcim_bw_service_state')->insert($payload);
            return;
        }

        $payload['updated_at'] = date('Y-m-d H:i:s');
        Capsule::table('mod_easydcim_bw_service_state')->where('serviceid', $serviceId)->update($payload);
    }
}
