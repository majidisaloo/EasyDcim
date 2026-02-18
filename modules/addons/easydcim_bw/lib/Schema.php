<?php

declare(strict_types=1);

namespace EasyDcimBw;

use WHMCS\Database\Capsule;

class Schema
{
    public static function create(): void
    {
        if (!Capsule::schema()->hasTable('mod_easydcim_bw_product_defaults')) {
            Capsule::schema()->create('mod_easydcim_bw_product_defaults', static function ($table): void {
                $table->increments('id');
                $table->integer('pid')->unique();
                $table->decimal('default_quota_gb', 10, 2)->default(0);
                $table->string('default_mode', 10)->default('TOTAL');
                $table->string('default_action', 20)->default('disable_ports');
                $table->boolean('enabled')->default(true);
                $table->timestamps();
            });
        }

        if (!Capsule::schema()->hasTable('mod_easydcim_bw_service_state')) {
            Capsule::schema()->create('mod_easydcim_bw_service_state', static function ($table): void {
                $table->increments('id');
                $table->integer('serviceid')->unique();
                $table->integer('userid');
                $table->integer('easydcim_service_id');
                $table->integer('easydcim_order_id')->nullable();
                $table->dateTime('cycle_start');
                $table->dateTime('cycle_end');
                $table->decimal('base_quota_gb', 10, 2)->default(0);
                $table->string('mode', 10)->default('TOTAL');
                $table->string('action', 20)->default('disable_ports');
                $table->decimal('last_used_gb', 12, 3)->default(0);
                $table->decimal('last_remaining_gb', 12, 3)->default(0);
                $table->string('last_status', 20)->default('ok');
                $table->dateTime('last_check_at')->nullable();
                $table->boolean('ports_limited')->default(false);
                $table->boolean('service_suspended')->default(false);
                $table->timestamps();
            });
        }

        if (!Capsule::schema()->hasTable('mod_easydcim_bw_purchases')) {
            Capsule::schema()->create('mod_easydcim_bw_purchases', static function ($table): void {
                $table->increments('id');
                $table->integer('whmcs_serviceid');
                $table->integer('userid');
                $table->integer('package_id');
                $table->decimal('size_gb', 10, 2);
                $table->decimal('price', 10, 2);
                $table->integer('invoiceid')->nullable();
                $table->dateTime('cycle_start');
                $table->dateTime('cycle_end');
                $table->dateTime('created_at');
                $table->index(['whmcs_serviceid', 'cycle_start', 'cycle_end'], 'idx_service_cycle');
            });
        }

        if (!Capsule::schema()->hasTable('mod_easydcim_bw_graph_cache')) {
            Capsule::schema()->create('mod_easydcim_bw_graph_cache', static function ($table): void {
                $table->increments('id');
                $table->integer('whmcs_serviceid');
                $table->dateTime('range_start');
                $table->dateTime('range_end');
                $table->string('payload_hash', 64);
                $table->mediumText('json_data');
                $table->dateTime('cached_at');
                $table->index(['whmcs_serviceid', 'payload_hash'], 'idx_service_hash');
            });
        }

        if (!Capsule::schema()->hasTable('mod_easydcim_bw_logs')) {
            Capsule::schema()->create('mod_easydcim_bw_logs', static function ($table): void {
                $table->increments('id');
                $table->string('level', 20);
                $table->mediumText('context_json');
                $table->dateTime('created_at');
            });
        }

        if (!Capsule::schema()->hasTable('mod_easydcim_bw_packages')) {
            Capsule::schema()->create('mod_easydcim_bw_packages', static function ($table): void {
                $table->increments('id');
                $table->string('name', 120);
                $table->decimal('size_gb', 10, 2);
                $table->decimal('price', 10, 2);
                $table->boolean('taxed')->default(true);
                $table->text('available_for_pids')->nullable();
                $table->text('available_for_gids')->nullable();
                $table->boolean('active')->default(true);
                $table->timestamps();
            });
        }

        if (!Capsule::schema()->hasTable('mod_easydcim_bw_service_overrides')) {
            Capsule::schema()->create('mod_easydcim_bw_service_overrides', static function ($table): void {
                $table->increments('id');
                $table->integer('serviceid')->unique();
                $table->decimal('override_base_quota_gb', 10, 2)->nullable();
                $table->string('override_mode', 10)->nullable();
                $table->string('override_action', 20)->nullable();
                $table->timestamps();
            });
        }
    }
}
