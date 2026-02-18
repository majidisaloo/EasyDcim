<?php

declare(strict_types=1);

namespace EasyDcimBandwidthGuard\Domain;

use WHMCS\Database\Capsule;

final class QuotaResolver
{
    public function resolve(int $serviceId, int $pid, string $cycleStart, string $cycleEnd, array $customFields = []): array
    {
        $serviceOverride = Capsule::table('mod_easydcim_bw_guard_service_overrides')->where('serviceid', $serviceId)->first();
        $productDefault = Capsule::table('mod_easydcim_bw_guard_product_defaults')->where('pid', $pid)->where('enabled', 1)->first();

        $fieldQuota = isset($customFields['base_quota_override_gb']) && $customFields['base_quota_override_gb'] !== ''
            ? (float) $customFields['base_quota_override_gb']
            : null;
        $fieldMode = isset($customFields['traffic_mode']) && $customFields['traffic_mode'] !== ''
            ? strtoupper((string) $customFields['traffic_mode'])
            : null;

        $baseQuota = (float) ($fieldQuota ?? $serviceOverride->override_base_quota_gb ?? $productDefault->default_quota_gb ?? 0);
        $mode = (string) ($fieldMode ?? $serviceOverride->override_mode ?? $productDefault->default_mode ?? 'TOTAL');
        $action = (string) ($serviceOverride->override_action ?? $productDefault->default_action ?? 'disable_ports');

        $extra = (float) Capsule::table('mod_easydcim_bw_guard_purchases')
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
