<?php

namespace EasyDcimBandwidth;

class CycleCalculator
{
    public function resolve(string $billingCycle, string $nextDueDate): array
    {
        $end = new \DateTimeImmutable($nextDueDate . ' 00:00:00');
        $end = $end->modify('-1 second');

        $intervalMap = [
            'Monthly' => 'P1M',
            'Quarterly' => 'P3M',
            'Semi-Annually' => 'P6M',
            'Annually' => 'P1Y',
            'Biennially' => 'P2Y',
            'Triennially' => 'P3Y',
        ];

        $intervalSpec = $intervalMap[$billingCycle] ?? 'P1M';
        $start = $end->add(new \DateInterval('PT1S'))->sub(new \DateInterval($intervalSpec));

        return [
            'start' => $start->format('Y-m-d H:i:s'),
            'end' => $end->format('Y-m-d H:i:s'),
        ];
    }
}
