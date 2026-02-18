<?php

declare(strict_types=1);

namespace EasyDcimBandwidthGuard\Domain;

use WHMCS\Database\Capsule;

final class QuotaResolver
{
    public function resolve(int $serviceId, int $pid, string $cycleStart, string $cycleEnd): array
    {
        $serviceOverride = Capsule::table('mod_easydcim_bw_service_state')->where('serviceid', $serviceId)->first();
        $productDefault = Capsule::table('mod_easydcim_bw_product_defaults')->where('pid', $pid)->where('enabled', 1)->first();

        $baseQuota = (float) ($serviceOverride->base_quota_gb ?? $productDefault->default_quota_gb ?? 0);
        $mode = (string) ($serviceOverride->mode ?? $productDefault->default_mode ?? 'TOTAL');
        $action = (string) ($serviceOverride->action ?? $productDefault->default_action ?? 'disable_ports');

        $extra = (float) Capsule::table('mod_easydcim_bw_purchases')
            ->where('whmcs_serviceid', $serviceId)
            ->where('cycle_start', $cycleStart)
            ->where('cycle_end', $cycleEnd)
            ->where('payment_status', 'paid')
            ->sum('size_gb');

        return [
            'base_quota_gb' => $baseQuota,
            'mode' => strtoupper($mode),
            'action' => $action,
            'extra_quota_gb' => $extra,
            'allowed_gb' => $baseQuota + $extra,
        ];
    }
}
