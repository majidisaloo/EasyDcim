<?php

declare(strict_types=1);

namespace EasyDcimBandwidthGuard\Support;

final class Version
{
    public const BASE_VERSION = '1.0';

    public static function current(string $moduleDir): array
    {
        $sha = self::runGit($moduleDir, 'rev-parse --short HEAD') ?? 'nogit';
        $ts = self::runGit($moduleDir, 'show -s --format=%ct HEAD') ?? (string) time();
        $build = ctype_digit($ts) ? date('YmdHis', (int) $ts) : date('YmdHis');

        return [
            'module_version' => self::BASE_VERSION . '.' . $build . '+' . $sha,
            'commit_sha' => $sha,
            'commit_unix_ts' => ctype_digit($ts) ? (int) $ts : time(),
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
