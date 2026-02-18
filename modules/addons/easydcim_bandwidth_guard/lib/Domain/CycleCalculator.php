<?php

declare(strict_types=1);

namespace EasyDcimBandwidthGuard\Domain;

final class CycleCalculator
{
    public function calculate(string $nextDueDate, string $billingCycle): array
    {
        $next = new \DateTimeImmutable($nextDueDate . ' 00:00:00');
        $cycleEnd = $next->modify('-1 second');
        $months = $this->monthsForCycle($billingCycle);
        $cycleStart = $next->modify('-' . $months . ' months');

        return [
            'start' => $cycleStart->format('Y-m-d H:i:s'),
            'end' => $cycleEnd->format('Y-m-d H:i:s'),
            'reset_at' => $cycleEnd->modify('+1 second')->format('Y-m-d H:i:s'),
        ];
    }

    private function monthsForCycle(string $billingCycle): int
    {
        return match (strtolower(trim($billingCycle))) {
            'monthly' => 1,
            'quarterly' => 3,
            'semi-annually', 'semiannually' => 6,
            'annually', 'yearly' => 12,
            'biennially' => 24,
            'triennially' => 36,
            default => 1,
        };
    }
}
