<?php

declare(strict_types=1);

namespace EasyDcimBw;

class Settings
{
    /** @var array<string,mixed> */
    private array $config;

    /** @param array<string,mixed> $config */
    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public function getString(string $key, string $default = ''): string
    {
        $value = $this->config[$key] ?? $default;

        return trim((string) $value);
    }

    public function getInt(string $key, int $default = 0): int
    {
        return (int) ($this->config[$key] ?? $default);
    }

    public function getFloat(string $key, float $default = 0.0): float
    {
        return (float) ($this->config[$key] ?? $default);
    }

    public function getBool(string $key): bool
    {
        $value = $this->config[$key] ?? '';

        return $value === 'on' || $value === '1' || $value === 1 || $value === true;
    }

    /** @return int[] */
    public function getIdList(string $key): array
    {
        $raw = $this->getString($key);
        if ($raw === '') {
            return [];
        }

        return array_values(array_filter(array_map('intval', explode(',', $raw))));
    }
}
