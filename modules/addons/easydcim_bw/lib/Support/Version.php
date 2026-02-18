<?php

declare(strict_types=1);

namespace EasyDcimBandwidthGuard\Support;

final class Version
{
    private const FALLBACK_VERSION = '1.13';

    public static function current(string $moduleDir): array
    {
        $sha = self::runGit($moduleDir, 'rev-parse --short HEAD') ?? 'release';
        $countRaw = self::runGit($moduleDir, 'rev-list --count HEAD');
        if ($countRaw === null || !ctype_digit($countRaw)) {
            return [
                'module_version' => self::FALLBACK_VERSION,
                'commit_sha' => $sha,
                'commit_unix_ts' => time(),
            ];
        }
        $count = max(1, (int) $countRaw);

        $major = intdiv($count - 1, 100) + 1;
        $minor = $count % 100;

        return [
            'module_version' => sprintf('%d.%02d', $major, $minor),
            'commit_sha' => $sha,
            'commit_unix_ts' => time(),
        ];
    }

    private static function runGit(string $moduleDir, string $cmd): ?string
    {
        if (!function_exists('shell_exec')) {
            return null;
        }

        $root = escapeshellarg(realpath($moduleDir . '/../../..') ?: $moduleDir);
        $out = shell_exec("git -C {$root} {$cmd} 2>/dev/null");
        if (!is_string($out)) {
            return null;
        }

        $value = trim($out);
        return $value !== '' ? $value : null;
    }
}
