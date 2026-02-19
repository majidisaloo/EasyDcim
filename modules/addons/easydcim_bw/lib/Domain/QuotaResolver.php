<?php

declare(strict_types=1);

namespace EasyDcimBandwidthGuard\Domain;

use WHMCS\Database\Capsule;

final class QuotaResolver
{
    public function resolve(int $serviceId, int $pid, string $cycleStart, string $cycleEnd, array $customFields = [], string $globalDefaultMode = 'TOTAL'): array
    {
        $serviceOverride = Capsule::table('mod_easydcim_bw_guard_service_overrides')->where('serviceid', $serviceId)->first();
        $productDefault = Capsule::table('mod_easydcim_bw_guard_product_defaults')->where('pid', $pid)->where('enabled', 1)->first();

        $fieldQuota = isset($customFields['base_quota_override_gb']) && $customFields['base_quota_override_gb'] !== ''
            ? (float) $customFields['base_quota_override_gb']
            : null;
        $fieldMode = isset($customFields['traffic_mode']) && $customFields['traffic_mode'] !== ''
            ? strtoupper((string) $customFields['traffic_mode'])
            : null;

        $mode = (string) ($fieldMode ?? $serviceOverride->override_mode ?? $productDefault->default_mode ?? $globalDefaultMode);
        $action = (string) ($serviceOverride->override_action ?? $productDefault->default_action ?? 'disable_ports');
        $mode = strtoupper($mode);

        $baseQuota = (float) ($fieldQuota ?? $serviceOverride->override_base_quota_gb ?? $this->resolveProductQuotaByMode($productDefault, $mode));
        $isUnlimited = $this->isUnlimitedForMode($productDefault, $mode);
        if ($fieldQuota !== null || ($serviceOverride->override_base_quota_gb ?? null) !== null) {
            $isUnlimited = false;
        }

        $extra = (float) Capsule::table('mod_easydcim_bw_guard_purchases')
            ->where('whmcs_serviceid', $serviceId)
            ->where('cycle_start', $cycleStart)
            ->where('cycle_end', $cycleEnd)
            ->where('payment_status', 'paid')
            ->sum('size_gb');

        return [
            'base_quota_gb' => $baseQuota,
            'mode' => $mode,
            'action' => $action,
            'is_unlimited' => $isUnlimited,
            'extra_quota_gb' => $extra,
            'allowed_gb' => $isUnlimited ? 999999999.0 : ($baseQuota + $extra),
        ];
    }

    private function resolveProductQuotaByMode(?object $productDefault, string $mode): float
    {
        if (!$productDefault) {
            return 0.0;
        }

        if ($mode === 'IN') {
            $value = $productDefault->default_quota_in_gb ?? null;
            return $value !== null ? (float) $value : (float) ($productDefault->default_quota_gb ?? 0);
        }
        if ($mode === 'OUT') {
            $value = $productDefault->default_quota_out_gb ?? null;
            return $value !== null ? (float) $value : (float) ($productDefault->default_quota_gb ?? 0);
        }

        $value = $productDefault->default_quota_total_gb ?? null;
        return $value !== null ? (float) $value : (float) ($productDefault->default_quota_gb ?? 0);
    }

    private function isUnlimitedForMode(?object $productDefault, string $mode): bool
    {
        if (!$productDefault) {
            return false;
        }

        return match ($mode) {
            'IN' => (int) ($productDefault->unlimited_in ?? 0) === 1,
            'OUT' => (int) ($productDefault->unlimited_out ?? 0) === 1,
            default => (int) ($productDefault->unlimited_total ?? 0) === 1,
        };
    }
}
