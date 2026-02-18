<?php

declare(strict_types=1);

namespace EasyDcimBandwidthGuard\Config;

use WHMCS\Database\Capsule;

final class Settings
{
    private array $config;

    public function __construct(array $addonConfig)
    {
        $this->config = $addonConfig;
    }

    public function getString(string $key, string $default = ''): string
    {
        $value = $this->config[$key] ?? $default;
        return is_string($value) ? trim($value) : $default;
    }

    public function getInt(string $key, int $default = 0): int
    {
        $value = $this->config[$key] ?? $default;
        return is_numeric($value) ? (int) $value : $default;
    }

    public function getBool(string $key, bool $default = false): bool
    {
        $value = $this->config[$key] ?? null;
        if ($value === null) {
            return $default;
        }
        if (is_bool($value)) {
            return $value;
        }

        return in_array(strtolower((string) $value), ['1', 'true', 'on', 'yes'], true);
    }

    public function getCsvList(string $key): array
    {
        $raw = $this->getString($key);
        if ($raw === '') {
            return [];
        }

        $items = array_filter(array_map('trim', explode(',', $raw)), static fn ($v): bool => $v !== '');
        return array_values(array_unique($items));
    }

    public static function defaults(): array
    {
        return [
            'easydcim_base_url' => '',
            'easydcim_api_token' => '',
            'use_impersonation' => '0',
            'managed_pids' => '',
            'managed_gids' => '',
            'poll_interval_minutes' => '15',
            'graph_cache_minutes' => '30',
            'autobuy_enabled' => '0',
            'autobuy_threshold_gb' => '10',
            'autobuy_default_package_id' => '0',
            'autobuy_max_per_cycle' => '5',
            'git_update_enabled' => '0',
            'git_origin_url' => '',
            'git_branch' => 'main',
            'github_repo' => 'majidisaloo/EasyDcim',
            'update_channel' => 'commit',
            'update_check_interval_minutes' => '30',
            'update_mode' => 'check_oneclick',
            'preflight_strict_mode' => '1',
        ];
    }

    public static function loadFromDatabase(string $module = 'easydcim_bw'): array
    {
        $values = self::defaults();
        $rows = Capsule::table('tbladdonmodules')->where('module', $module)->get(['setting', 'value']);
        foreach ($rows as $row) {
            $values[(string) $row->setting] = (string) $row->value;
        }

        return $values;
    }

    public static function saveToDatabase(array $settings, string $module = 'easydcim_bw'): void
    {
        foreach (self::defaults() as $key => $_default) {
            if (!array_key_exists($key, $settings)) {
                continue;
            }

            $value = (string) $settings[$key];
            Capsule::table('tbladdonmodules')->updateOrInsert(
                ['module' => $module, 'setting' => $key],
                ['value' => $value]
            );
        }
    }
}
