<?php

use WHMCS\Database\Capsule;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

require_once __DIR__ . '/lib/Bootstrap.php';

function easydcim_bandwidth_config()
{
    return [
        'name' => 'EasyDCIM Bandwidth Manager',
        'description' => 'Traffic quota enforcement, per-cycle add-ons, graph caching, and auto-buy for EasyDCIM services.',
        'version' => '1.0.0',
        'author' => 'Codex',
        'fields' => [
            'base_url' => [
                'FriendlyName' => 'EasyDCIM Base URL',
                'Type' => 'text',
                'Size' => '60',
                'Description' => 'Example: https://your-easydcim.com',
            ],
            'api_token' => [
                'FriendlyName' => 'Admin API Token',
                'Type' => 'password',
                'Size' => '60',
                'Description' => 'Bearer token used for EasyDCIM API calls',
            ],
            'use_impersonation' => [
                'FriendlyName' => 'Use Impersonation',
                'Type' => 'yesno',
                'Description' => 'Send X-Impersonate-User header for client endpoints',
            ],
            'impersonate_source' => [
                'FriendlyName' => 'Impersonation Source',
                'Type' => 'dropdown',
                'Options' => 'email,userid',
                'Description' => 'Use WHMCS client email or user id in X-Impersonate-User',
            ],
            'enabled_gids' => [
                'FriendlyName' => 'Enabled Product Group IDs',
                'Type' => 'text',
                'Size' => '60',
                'Description' => 'Comma-separated GIDs',
            ],
            'enabled_pids' => [
                'FriendlyName' => 'Enabled Product IDs',
                'Type' => 'text',
                'Size' => '60',
                'Description' => 'Comma-separated PIDs',
            ],
            'default_action' => [
                'FriendlyName' => 'Default Action',
                'Type' => 'dropdown',
                'Options' => 'disable_ports,suspend,both',
            ],
            'poll_interval_minutes' => [
                'FriendlyName' => 'Poll Interval (Minutes)',
                'Type' => 'text',
                'Default' => '15',
                'Size' => '10',
            ],
            'graph_cache_minutes' => [
                'FriendlyName' => 'Graph Cache (Minutes)',
                'Type' => 'text',
                'Default' => '30',
                'Size' => '10',
            ],
            'autobuy_enabled' => [
                'FriendlyName' => 'Enable Auto-Buy',
                'Type' => 'yesno',
            ],
            'autobuy_threshold_gb' => [
                'FriendlyName' => 'Auto-Buy Threshold (GB)',
                'Type' => 'text',
                'Default' => '10',
                'Size' => '10',
            ],
            'autobuy_default_package_id' => [
                'FriendlyName' => 'Default Auto-Buy Package ID',
                'Type' => 'text',
                'Size' => '10',
            ],
            'autobuy_max_per_cycle' => [
                'FriendlyName' => 'Auto-Buy Max per Cycle',
                'Type' => 'text',
                'Default' => '5',
                'Size' => '10',
            ],
        ],
    ];
}

function easydcim_bandwidth_activate()
{
    try {
        Capsule::schema()->create('mod_easydcim_bw_product_defaults', function ($table) {
            $table->increments('id');
            $table->integer('pid')->unique();
            $table->decimal('default_quota_gb', 12, 2)->default(0);
            $table->string('default_mode', 16)->default('TOTAL');
            $table->string('default_action', 32)->default('disable_ports');
            $table->boolean('enabled')->default(true);
            $table->timestamps();
        });

        Capsule::schema()->create('mod_easydcim_bw_service_state', function ($table) {
            $table->increments('id');
            $table->integer('serviceid')->unique();
            $table->integer('userid');
            $table->integer('easydcim_service_id');
            $table->integer('easydcim_order_id')->nullable();
            $table->dateTime('cycle_start')->nullable();
            $table->dateTime('cycle_end')->nullable();
            $table->decimal('base_quota_gb', 12, 2)->default(0);
            $table->string('mode', 16)->default('TOTAL');
            $table->string('action', 32)->default('disable_ports');
            $table->decimal('last_used_gb', 14, 4)->default(0);
            $table->decimal('last_remaining_gb', 14, 4)->default(0);
            $table->string('last_status', 32)->default('ok');
            $table->dateTime('last_check_at')->nullable();
            $table->timestamps();
            $table->index(['userid', 'last_status']);
        });

        Capsule::schema()->create('mod_easydcim_bw_purchases', function ($table) {
            $table->increments('id');
            $table->integer('whmcs_serviceid');
            $table->integer('userid');
            $table->integer('package_id');
            $table->decimal('size_gb', 12, 2);
            $table->decimal('price', 12, 2);
            $table->integer('invoiceid')->nullable();
            $table->dateTime('cycle_start');
            $table->dateTime('cycle_end');
            $table->dateTime('created_at');
            $table->index(['whmcs_serviceid', 'cycle_start', 'cycle_end']);
        });

        Capsule::schema()->create('mod_easydcim_bw_graph_cache', function ($table) {
            $table->increments('id');
            $table->integer('whmcs_serviceid');
            $table->dateTime('range_start');
            $table->dateTime('range_end');
            $table->string('payload_hash', 64);
            $table->longText('json_data');
            $table->dateTime('cached_at');
            $table->index(['whmcs_serviceid', 'payload_hash']);
        });

        Capsule::schema()->create('mod_easydcim_bw_logs', function ($table) {
            $table->increments('id');
            $table->string('level', 20);
            $table->longText('context_json');
            $table->dateTime('created_at');
            $table->index(['level', 'created_at']);
        });

        Capsule::schema()->create('mod_easydcim_bw_packages', function ($table) {
            $table->increments('id');
            $table->string('name', 120);
            $table->decimal('size_gb', 12, 2);
            $table->decimal('price', 12, 2);
            $table->boolean('taxed')->default(false);
            $table->text('available_for_pids')->nullable();
            $table->text('available_for_gids')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Capsule::schema()->create('mod_easydcim_bw_service_overrides', function ($table) {
            $table->increments('id');
            $table->integer('serviceid')->unique();
            $table->decimal('override_base_quota_gb', 12, 2);
            $table->string('override_mode', 16)->default('TOTAL');
            $table->string('override_action', 32)->nullable();
            $table->timestamps();
        });
    } catch (\Exception $e) {
        return ['status' => 'error', 'description' => 'Error creating tables: ' . $e->getMessage()];
    }

    return ['status' => 'success', 'description' => 'EasyDCIM Bandwidth Manager activated successfully.'];
}

function easydcim_bandwidth_deactivate()
{
    return ['status' => 'success', 'description' => 'Module deactivated. Data retained.'];
}

function easydcim_bandwidth_output($vars)
{
    $controller = new EasyDcimBandwidth\AdminController($vars);
    echo $controller->render();
}

function easydcim_bandwidth_clientarea($vars)
{
    $controller = new EasyDcimBandwidth\ClientAreaController($vars);
    return $controller->handle();
}
