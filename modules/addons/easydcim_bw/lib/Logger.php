<?php

declare(strict_types=1);

namespace EasyDcimBw;

use WHMCS\Database\Capsule;

class Logger
{
    /** @param array<string,mixed> $context */
    public function log(string $level, array $context): void
    {
        Capsule::table('mod_easydcim_bw_logs')->insert([
            'level' => $level,
            'context_json' => json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }
}
