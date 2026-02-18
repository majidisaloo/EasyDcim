<?php

namespace EasyDcimBandwidth;

use WHMCS\Database\Capsule;

class Logger
{
    public static function log(string $level, array $context): void
    {
        Capsule::table('mod_easydcim_bw_logs')->insert([
            'level' => $level,
            'context_json' => json_encode($context, JSON_UNESCAPED_UNICODE),
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }
}
