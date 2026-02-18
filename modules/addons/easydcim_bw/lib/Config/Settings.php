<?php

declare(strict_types=1);

namespace EasyDcimBandwidthGuard\Config;

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
}
