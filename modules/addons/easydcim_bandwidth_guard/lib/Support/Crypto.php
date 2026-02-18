<?php

declare(strict_types=1);

namespace EasyDcimBandwidthGuard\Support;

final class Crypto
{
    public static function safeDecrypt(string $value): string
    {
        if ($value === '') {
            return '';
        }

        if (!function_exists('decrypt')) {
            return $value;
        }

        try {
            $decrypted = decrypt($value);
            return is_string($decrypted) ? $decrypted : $value;
        } catch (\Throwable $e) {
            return $value;
        }
    }
}
