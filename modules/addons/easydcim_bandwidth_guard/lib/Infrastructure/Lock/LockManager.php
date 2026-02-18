<?php

declare(strict_types=1);

namespace EasyDcimBandwidthGuard\Infrastructure\Lock;

use WHMCS\Database\Capsule;

final class LockManager
{
    public function acquire(string $key, int $ttlSeconds = 300): bool
    {
        $now = time();
        $expiresAt = $now + $ttlSeconds;
        $existing = Capsule::table('mod_easydcim_bw_meta')->where('meta_key', 'lock:' . $key)->first();

        if ($existing) {
            $value = json_decode((string) $existing->meta_value, true) ?: [];
            if (($value['expires_at'] ?? 0) > $now) {
                return false;
            }
        }

        $payload = json_encode(['expires_at' => $expiresAt], JSON_UNESCAPED_SLASHES);
        Capsule::table('mod_easydcim_bw_meta')->updateOrInsert(
            ['meta_key' => 'lock:' . $key],
            ['meta_value' => $payload, 'updated_at' => date('Y-m-d H:i:s')]
        );

        return true;
    }

    public function release(string $key): void
    {
        Capsule::table('mod_easydcim_bw_meta')->where('meta_key', 'lock:' . $key)->delete();
    }
}
