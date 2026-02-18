<?php

declare(strict_types=1);

namespace EasyDcimBandwidthGuard\Support;

final class Version
{
    private const FALLBACK_VERSION = '1.15';

    public static function current(string $moduleDir): array
    {
        $fileVersion = self::readVersionFile($moduleDir);
        if ($fileVersion !== null) {
            return [
                'module_version' => $fileVersion,
                'commit_sha' => self::runGit($moduleDir, 'rev-parse --short HEAD') ?? 'release',
                'commit_unix_ts' => (int) (self::runGit($moduleDir, 'show -s --format=%ct HEAD') ?? (string) time()),
            ];
        }

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

    private static function readVersionFile(string $moduleDir): ?string
    {
        $path = rtrim($moduleDir, '/') . '/VERSION';
        if (!is_file($path)) {
            return null;
        }

        $raw = trim((string) @file_get_contents($path));
        if (!preg_match('/^\d+\.\d{2}$/', $raw)) {
            return null;
        }

        return $raw;
    }

    private static function runGit(string $moduleDir, string $cmd): ?string
    {
        if (!function_exists('shell_exec')) {
            return null;
        }

        $roots = [
            realpath($moduleDir . '/../../../..') ?: null,
            realpath($moduleDir . '/../../..') ?: null,
            realpath($moduleDir) ?: null,
        ];

        $out = null;
        foreach ($roots as $root) {
            if (!is_string($root) || $root === '') {
                continue;
            }
            $escapedRoot = escapeshellarg($root);
            $out = shell_exec("git -C {$escapedRoot} {$cmd} 2>/dev/null");
            if (is_string($out) && trim($out) !== '') {
                break;
            }
        }

        if (!is_string($out)) {
            return null;
        }

        $value = trim($out);
        return $value !== '' ? $value : null;
    }
}
