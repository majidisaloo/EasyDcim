<?php

declare(strict_types=1);

namespace EasyDcimBandwidthGuard\Support;

use WHMCS\Database\Capsule;

final class Logger
{
    public function log(string $level, string $message, array $context = []): void
    {
        try {
            Capsule::table('mod_easydcim_bw_guard_logs')->insert([
                'level' => strtoupper($level),
                'message' => $message,
                'context_json' => json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'correlation_id' => $context['correlation_id'] ?? bin2hex(random_bytes(8)),
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            logModuleCall('easydcim_bw', 'log_fallback', $context, $e->getMessage(), [], ['token', 'Authorization']);
        }
    }
}
