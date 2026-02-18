<?php

declare(strict_types=1);

namespace EasyDcimBw;

use WHMCS\Database\Capsule;

class ServiceRepository
{
    public function __construct(private readonly Settings $settings)
    {
    }

    /** @return array<int,array<string,mixed>> */
    public function getEligibleServices(): array
    {
        $pidList = $this->settings->getIdList('enabled_pids');
        $gidList = $this->settings->getIdList('enabled_gids');

        $query = Capsule::table('tblhosting')
            ->join('tblproducts', 'tblproducts.id', '=', 'tblhosting.packageid')
            ->where('tblhosting.domainstatus', 'Active');

        if ($pidList !== [] || $gidList !== []) {
            $query->where(static function ($nested) use ($pidList, $gidList): void {
                if ($pidList !== []) {
                    $nested->orWhereIn('tblhosting.packageid', $pidList);
                }
                if ($gidList !== []) {
                    $nested->orWhereIn('tblproducts.gid', $gidList);
                }
            });
        }

        return $query->select([
            'tblhosting.id as serviceid',
            'tblhosting.userid',
            'tblhosting.packageid as pid',
            'tblproducts.gid',
            'tblhosting.nextduedate',
            'tblhosting.billingcycle',
        ])->get()->map(static fn($row) => (array) $row)->all();
    }

    public function getCustomFieldValue(int $serviceId, string $fieldName): ?string
    {
        $value = Capsule::table('tblcustomfieldsvalues')
            ->join('tblcustomfields', 'tblcustomfields.id', '=', 'tblcustomfieldsvalues.fieldid')
            ->where('tblcustomfieldsvalues.relid', $serviceId)
            ->where('tblcustomfields.type', 'product')
            ->where('tblcustomfields.fieldname', 'like', $fieldName . '%')
            ->value('tblcustomfieldsvalues.value');

        return $value !== null ? trim((string) $value) : null;
    }

    /** @return array<string,mixed>|null */
    public function getOverride(int $serviceId): ?array
    {
        $row = Capsule::table('mod_easydcim_bw_service_overrides')->where('serviceid', $serviceId)->first();

        return $row ? (array) $row : null;
    }

    /** @return array<string,mixed>|null */
    public function getProductDefault(int $pid): ?array
    {
        $row = Capsule::table('mod_easydcim_bw_product_defaults')->where('pid', $pid)->where('enabled', 1)->first();

        return $row ? (array) $row : null;
    }

    public function sumCyclePurchases(int $serviceId, string $cycleStart, string $cycleEnd): float
    {
        return (float) Capsule::table('mod_easydcim_bw_purchases')
            ->where('whmcs_serviceid', $serviceId)
            ->where('cycle_start', $cycleStart)
            ->where('cycle_end', $cycleEnd)
            ->sum('size_gb');
    }
}
