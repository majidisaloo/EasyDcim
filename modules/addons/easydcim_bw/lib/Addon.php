<?php

declare(strict_types=1);

namespace EasyDcimBw;

use WHMCS\Database\Capsule;

class Addon
{
    public static function config(): array
    {
        return [
            'name' => 'EasyDCIM Bandwidth Manager',
            'description' => 'Cycle-aware bandwidth management, overage enforcement, and traffic pack purchases for EasyDCIM services.',
            'version' => '1.0.0',
            'author' => 'Codex',
            'fields' => [
                'base_url' => [
                    'FriendlyName' => 'EasyDCIM Base URL',
                    'Type' => 'text',
                    'Size' => '60',
                    'Default' => '',
                ],
                'api_token' => [
                    'FriendlyName' => 'Admin API Token',
                    'Type' => 'password',
                    'Size' => '60',
                    'Default' => '',
                ],
                'use_impersonation' => [
                    'FriendlyName' => 'Use Impersonation',
                    'Type' => 'yesno',
                    'Description' => 'Enable X-Impersonate-User for client endpoints',
                ],
                'impersonate_mode' => [
                    'FriendlyName' => 'Impersonation Value',
                    'Type' => 'dropdown',
                    'Options' => 'email,userid',
                    'Default' => 'email',
                ],
                'enabled_pids' => [
                    'FriendlyName' => 'Enabled Product IDs',
                    'Type' => 'text',
                    'Size' => '60',
                    'Description' => 'Comma-separated PID list',
                ],
                'enabled_gids' => [
                    'FriendlyName' => 'Enabled Product Group IDs',
                    'Type' => 'text',
                    'Size' => '60',
                    'Description' => 'Comma-separated GID list',
                ],
                'poll_interval_minutes' => [
                    'FriendlyName' => 'Poll Interval (minutes)',
                    'Type' => 'text',
                    'Default' => '15',
                    'Size' => '5',
                ],
                'graph_cache_minutes' => [
                    'FriendlyName' => 'Graph Cache (minutes)',
                    'Type' => 'text',
                    'Default' => '30',
                    'Size' => '5',
                ],
                'autobuy_enabled' => [
                    'FriendlyName' => 'Auto Buy Enabled',
                    'Type' => 'yesno',
                ],
                'autobuy_threshold_gb' => [
                    'FriendlyName' => 'Auto Buy Threshold (GB)',
                    'Type' => 'text',
                    'Default' => '10',
                    'Size' => '6',
                ],
                'autobuy_max_per_cycle' => [
                    'FriendlyName' => 'Auto Buy Max/Cycle',
                    'Type' => 'text',
                    'Default' => '5',
                    'Size' => '3',
                ],
                'default_package_id' => [
                    'FriendlyName' => 'Default Auto Buy Package ID',
                    'Type' => 'text',
                    'Default' => '',
                    'Size' => '6',
                ],
            ],
        ];
    }

    public static function activate(): array
    {
        Schema::create();

        return ['status' => 'success', 'description' => 'EasyDCIM BW module activated'];
    }

    public static function deactivate(): array
    {
        return ['status' => 'success', 'description' => 'Module deactivated (tables preserved)'];
    }

    public static function upgrade(array $vars): array
    {
        Schema::create();

        return ['status' => 'success'];
    }
}
