<?php

namespace EasyDcimBandwidth;

class Config
{
    private array $config;

    public function __construct(array $vars)
    {
        $this->config = $vars;
    }

    public function get(string $key, $default = null)
    {
        if (isset($this->config[$key]) && $this->config[$key] !== '') {
            return $this->config[$key];
        }

        return $default;
    }

    public function getCsvInts(string $key): array
    {
        $value = (string) $this->get($key, '');
        if ($value === '') {
            return [];
        }

        return array_values(array_filter(array_map('intval', array_map('trim', explode(',', $value)))));
    }

    public function isEnabled(string $key): bool
    {
        return (string) $this->get($key, '') === 'on';
    }
}
