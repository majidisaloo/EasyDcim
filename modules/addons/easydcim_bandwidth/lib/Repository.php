<?php

namespace EasyDcimBandwidth;

use WHMCS\Database\Capsule;

class Repository
{
    public function getEligibleServices(array $pids, array $gids)
    {
        $query = Capsule::table('tblhosting as h')
            ->join('tblproducts as p', 'p.id', '=', 'h.packageid')
            ->whereIn('h.domainstatus', ['Active', 'Suspended'])
            ->select('h.id', 'h.userid', 'h.packageid', 'h.billingcycle', 'h.nextduedate', 'h.domainstatus', 'p.gid');

        if (!empty($pids) || !empty($gids)) {
            $query->where(function ($q) use ($pids, $gids) {
                if (!empty($pids)) {
                    $q->orWhereIn('h.packageid', $pids);
                }
                if (!empty($gids)) {
                    $q->orWhereIn('p.gid', $gids);
                }
            });
        }

        return $query->get();
    }

    public function getCustomFieldValues(int $serviceId): array
    {
        $rows = Capsule::table('tblcustomfieldsvalues as v')
            ->join('tblcustomfields as f', 'f.id', '=', 'v.fieldid')
            ->where('v.relid', $serviceId)
            ->where('f.type', 'product')
            ->select('f.fieldname', 'v.value')
            ->get();

        $result = [];
        foreach ($rows as $row) {
            $key = trim(strtolower((string) $row->fieldname));
            $result[$key] = trim((string) $row->value);
        }

        return $result;
    }

    public function getProductDefault(int $pid): ?object
    {
        return Capsule::table('mod_easydcim_bw_product_defaults')->where('pid', $pid)->first();
    }

    public function getServiceOverride(int $serviceId): ?object
    {
        return Capsule::table('mod_easydcim_bw_service_overrides')->where('serviceid', $serviceId)->first();
    }

    public function getCyclePurchasesTotal(int $serviceId, string $cycleStart, string $cycleEnd): float
    {
        return (float) Capsule::table('mod_easydcim_bw_purchases')
            ->where('whmcs_serviceid', $serviceId)
            ->where('cycle_start', $cycleStart)
            ->where('cycle_end', $cycleEnd)
            ->sum('size_gb');
    }

    public function getAutobuyCount(int $serviceId, string $cycleStart, string $cycleEnd): int
    {
        return (int) Capsule::table('mod_easydcim_bw_purchases')
            ->where('whmcs_serviceid', $serviceId)
            ->where('cycle_start', $cycleStart)
            ->where('cycle_end', $cycleEnd)
            ->where('package_id', '>', 0)
            ->count();
    }

    public function saveServiceState(array $data): void
    {
        $existing = Capsule::table('mod_easydcim_bw_service_state')->where('serviceid', $data['serviceid'])->first();
        $payload = array_merge($data, ['updated_at' => date('Y-m-d H:i:s')]);

        if ($existing) {
            Capsule::table('mod_easydcim_bw_service_state')->where('serviceid', $data['serviceid'])->update($payload);
            return;
        }

        $payload['created_at'] = date('Y-m-d H:i:s');
        Capsule::table('mod_easydcim_bw_service_state')->insert($payload);
    }

    public function findGraphCache(int $serviceId, string $payloadHash, int $ttlMinutes): ?array
    {
        $minTime = date('Y-m-d H:i:s', time() - ($ttlMinutes * 60));
        $row = Capsule::table('mod_easydcim_bw_graph_cache')
            ->where('whmcs_serviceid', $serviceId)
            ->where('payload_hash', $payloadHash)
            ->where('cached_at', '>=', $minTime)
            ->first();

        if (!$row) {
            return null;
        }

        return json_decode($row->json_data, true);
    }

    public function storeGraphCache(int $serviceId, string $start, string $end, string $payloadHash, array $data): void
    {
        Capsule::table('mod_easydcim_bw_graph_cache')->insert([
            'whmcs_serviceid' => $serviceId,
            'range_start' => $start,
            'range_end' => $end,
            'payload_hash' => $payloadHash,
            'json_data' => json_encode($data),
            'cached_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public function getPackage(int $packageId): ?object
    {
        return Capsule::table('mod_easydcim_bw_packages')->where('id', $packageId)->where('is_active', 1)->first();
    }

    public function listActivePackages(): array
    {
        return Capsule::table('mod_easydcim_bw_packages')->where('is_active', 1)->orderBy('size_gb')->get()->all();
    }

    public function recordPurchase(array $data): void
    {
        Capsule::table('mod_easydcim_bw_purchases')->insert($data + ['created_at' => date('Y-m-d H:i:s')]);
    }

    public function getClientById(int $userId): ?object
    {
        return Capsule::table('tblclients')->where('id', $userId)->first();
    }

    public function getServiceById(int $serviceId): ?object
    {
        return Capsule::table('tblhosting')->where('id', $serviceId)->first();
    }
}
