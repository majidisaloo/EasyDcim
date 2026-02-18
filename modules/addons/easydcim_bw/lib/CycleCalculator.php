<?php

declare(strict_types=1);

namespace EasyDcimBw;

use DateInterval;
use DateTimeImmutable;

class CycleCalculator
{
    /** @return array{start:DateTimeImmutable,end:DateTimeImmutable} */
    public function calculate(string $nextDueDate, string $billingCycle): array
    {
        $due = new DateTimeImmutable($nextDueDate . ' 00:00:00');
        $end = $due->modify('-1 second');

        $months = match (strtolower($billingCycle)) {
            'monthly' => 1,
            'quarterly' => 3,
            'semi-annually', 'semiannually' => 6,
            'annually', 'yearly' => 12,
            'biennially' => 24,
            'triennially' => 36,
            default => 1,
        };

        $start = $due->sub(new DateInterval('P' . $months . 'M'));

        return ['start' => $start, 'end' => $end];
    }
}
