<?php

declare(strict_types=1);

namespace EasyDcimBandwidthGuard\Infrastructure\Db;

use EasyDcimBandwidthGuard\Support\Logger;
use WHMCS\Database\Capsule;
use Illuminate\Database\Schema\Blueprint;

final class Migrator
{
    private Logger $logger;

    public function __construct(Logger $logger)
    {
        $this->logger = $logger;
    }

    public function migrate(): void
    {
        $this->createProductDefaults();
        $this->createServiceState();
        $this->createPurchases();
        $this->createGraphCache();
        $this->createLogs();
        $this->createMeta();
        $this->createUpdateLog();
        $this->createPackages();
        $this->createServiceOverrides();
        $this->createClientPrefs();
        $this->addMissingColumns();
        $this->addIndexes();
    }

    public function purgeModuleData(): void
    {
        $tables = [
            'mod_easydcim_bw_guard_graph_cache',
            'mod_easydcim_bw_guard_update_log',
            'mod_easydcim_bw_guard_purchases',
            'mod_easydcim_bw_guard_service_state',
            'mod_easydcim_bw_guard_service_overrides',
            'mod_easydcim_bw_guard_product_defaults',
            'mod_easydcim_bw_guard_packages',
            'mod_easydcim_bw_guard_client_prefs',
            'mod_easydcim_bw_guard_logs',
            'mod_easydcim_bw_guard_meta',
        ];

        foreach ($tables as $table) {
            try {
                Capsule::schema()->dropIfExists($table);
            } catch (\Throwable $e) {
                $this->logger->log('ERROR', 'purge_table_failed', ['table' => $table, 'error' => $e->getMessage()]);
            }
        }
    }

    private function createProductDefaults(): void
    {
        if (Capsule::schema()->hasTable('mod_easydcim_bw_guard_product_defaults')) {
            return;
        }

        Capsule::schema()->create('mod_easydcim_bw_guard_product_defaults', static function (Blueprint $table): void {
            $table->increments('id');
            $table->integer('pid')->default(0);
            $table->decimal('default_quota_gb', 12, 2)->default(0);
            $table->string('default_mode', 10)->default('TOTAL');
            $table->string('default_action', 20)->default('disable_ports');
            $table->boolean('enabled')->default(true);
            $table->timestamps();
        });
    }

    private function createServiceState(): void
    {
        if (Capsule::schema()->hasTable('mod_easydcim_bw_guard_service_state')) {
            return;
        }

        Capsule::schema()->create('mod_easydcim_bw_guard_service_state', static function (Blueprint $table): void {
            $table->increments('id');
            $table->integer('serviceid')->unsigned();
            $table->integer('userid')->unsigned();
            $table->string('easydcim_service_id', 64);
            $table->string('easydcim_order_id', 64)->nullable();
            $table->string('easydcim_server_id', 64)->nullable();
            $table->dateTime('cycle_start')->nullable();
            $table->dateTime('cycle_end')->nullable();
            $table->decimal('base_quota_gb', 12, 2)->default(0);
            $table->string('mode', 10)->default('TOTAL');
            $table->string('action', 20)->default('disable_ports');
            $table->decimal('last_used_gb', 14, 4)->default(0);
            $table->decimal('last_remaining_gb', 14, 4)->default(0);
            $table->string('last_status', 20)->default('ok');
            $table->dateTime('last_check_at')->nullable();
            $table->dateTime('updated_at')->nullable();
            $table->dateTime('created_at')->nullable();
        });
    }

    private function createPurchases(): void
    {
        if (Capsule::schema()->hasTable('mod_easydcim_bw_guard_purchases')) {
            return;
        }

        Capsule::schema()->create('mod_easydcim_bw_guard_purchases', static function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->integer('whmcs_serviceid')->unsigned();
            $table->integer('userid')->unsigned();
            $table->integer('package_id')->unsigned();
            $table->decimal('size_gb', 12, 2);
            $table->decimal('price', 12, 2);
            $table->integer('invoiceid')->unsigned()->nullable();
            $table->dateTime('cycle_start');
            $table->dateTime('cycle_end');
            $table->dateTime('reset_at');
            $table->string('actor', 20)->default('client_manual');
            $table->string('payment_status', 20)->default('pending');
            $table->decimal('remaining_before_gb', 14, 4)->default(0);
            $table->decimal('remaining_after_gb', 14, 4)->default(0);
            $table->text('purchase_context_json')->nullable();
            $table->dateTime('created_at');
        });
    }

    private function createGraphCache(): void
    {
        if (Capsule::schema()->hasTable('mod_easydcim_bw_guard_graph_cache')) {
            return;
        }

        Capsule::schema()->create('mod_easydcim_bw_guard_graph_cache', static function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->integer('whmcs_serviceid')->unsigned();
            $table->dateTime('range_start');
            $table->dateTime('range_end');
            $table->string('payload_hash', 64);
            $table->longText('json_data');
            $table->dateTime('cached_at');
        });
    }

    private function createLogs(): void
    {
        if (Capsule::schema()->hasTable('mod_easydcim_bw_guard_logs')) {
            return;
        }

        Capsule::schema()->create('mod_easydcim_bw_guard_logs', static function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('level', 16);
            $table->string('message', 255);
            $table->longText('context_json')->nullable();
            $table->string('correlation_id', 64)->nullable();
            $table->dateTime('created_at');
        });
    }

    private function createMeta(): void
    {
        if (Capsule::schema()->hasTable('mod_easydcim_bw_guard_meta')) {
            return;
        }

        Capsule::schema()->create('mod_easydcim_bw_guard_meta', static function (Blueprint $table): void {
            $table->string('meta_key', 64)->primary();
            $table->text('meta_value')->nullable();
            $table->dateTime('updated_at');
        });
    }

    private function createUpdateLog(): void
    {
        if (Capsule::schema()->hasTable('mod_easydcim_bw_guard_update_log')) {
            return;
        }

        Capsule::schema()->create('mod_easydcim_bw_guard_update_log', static function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('current_sha', 64)->nullable();
            $table->string('remote_sha', 64)->nullable();
            $table->string('status', 32);
            $table->longText('details_json')->nullable();
            $table->dateTime('checked_at')->nullable();
            $table->dateTime('applied_at')->nullable();
            $table->dateTime('created_at');
        });
    }

    private function createPackages(): void
    {
        if (Capsule::schema()->hasTable('mod_easydcim_bw_guard_packages')) {
            return;
        }

        Capsule::schema()->create('mod_easydcim_bw_guard_packages', static function (Blueprint $table): void {
            $table->increments('id');
            $table->string('name', 120);
            $table->decimal('size_gb', 12, 2);
            $table->decimal('price', 12, 2);
            $table->boolean('taxed')->default(false);
            $table->string('available_for_pids', 255)->nullable();
            $table->string('available_for_gids', 255)->nullable();
            $table->boolean('is_active')->default(true);
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
        });
    }

    private function createServiceOverrides(): void
    {
        if (Capsule::schema()->hasTable('mod_easydcim_bw_guard_service_overrides')) {
            return;
        }

        Capsule::schema()->create('mod_easydcim_bw_guard_service_overrides', static function (Blueprint $table): void {
            $table->increments('id');
            $table->integer('serviceid')->unsigned()->unique();
            $table->decimal('override_base_quota_gb', 12, 2)->nullable();
            $table->string('override_mode', 10)->nullable();
            $table->string('override_action', 20)->nullable();
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
        });
    }

    private function createClientPrefs(): void
    {
        if (Capsule::schema()->hasTable('mod_easydcim_bw_guard_client_prefs')) {
            return;
        }

        Capsule::schema()->create('mod_easydcim_bw_guard_client_prefs', static function (Blueprint $table): void {
            $table->increments('id');
            $table->integer('serviceid')->unsigned();
            $table->integer('userid')->unsigned();
            $table->boolean('autobuy_enabled')->default(false);
            $table->decimal('autobuy_threshold_gb', 12, 2)->nullable();
            $table->integer('autobuy_package_id')->unsigned()->nullable();
            $table->integer('autobuy_max_per_cycle')->unsigned()->nullable();
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
        });
    }

    private function addMissingColumns(): void
    {
        if (Capsule::schema()->hasTable('mod_easydcim_bw_guard_service_state')) {
            $this->addColumnIfMissing('mod_easydcim_bw_guard_service_state', 'easydcim_server_id', static function (Blueprint $table): void {
                $table->string('easydcim_server_id', 64)->nullable();
            });
            $this->addColumnIfMissing('mod_easydcim_bw_guard_service_state', 'lock_version', static function (Blueprint $table): void {
                $table->unsignedInteger('lock_version')->default(0);
            });
        }

        if (Capsule::schema()->hasTable('mod_easydcim_bw_guard_product_defaults')) {
            $this->addColumnIfMissing('mod_easydcim_bw_guard_product_defaults', 'default_quota_in_gb', static function (Blueprint $table): void {
                $table->decimal('default_quota_in_gb', 12, 2)->nullable();
            });
            $this->addColumnIfMissing('mod_easydcim_bw_guard_product_defaults', 'default_quota_out_gb', static function (Blueprint $table): void {
                $table->decimal('default_quota_out_gb', 12, 2)->nullable();
            });
            $this->addColumnIfMissing('mod_easydcim_bw_guard_product_defaults', 'default_quota_total_gb', static function (Blueprint $table): void {
                $table->decimal('default_quota_total_gb', 12, 2)->nullable();
            });
            $this->addColumnIfMissing('mod_easydcim_bw_guard_product_defaults', 'unlimited_in', static function (Blueprint $table): void {
                $table->boolean('unlimited_in')->default(false);
            });
            $this->addColumnIfMissing('mod_easydcim_bw_guard_product_defaults', 'unlimited_out', static function (Blueprint $table): void {
                $table->boolean('unlimited_out')->default(false);
            });
            $this->addColumnIfMissing('mod_easydcim_bw_guard_product_defaults', 'unlimited_total', static function (Blueprint $table): void {
                $table->boolean('unlimited_total')->default(false);
            });
        }

        if (Capsule::schema()->hasTable('mod_easydcim_bw_guard_logs')) {
            $this->addColumnIfMissing('mod_easydcim_bw_guard_logs', 'source', static function (Blueprint $table): void {
                $table->string('source', 40)->nullable();
            });
        }
    }

    private function addColumnIfMissing(string $table, string $column, callable $callback): void
    {
        if (Capsule::schema()->hasColumn($table, $column)) {
            return;
        }

        Capsule::schema()->table($table, $callback);
        $this->logger->log('INFO', 'column_added', ['table' => $table, 'column' => $column]);
    }

    private function addIndexes(): void
    {
        $this->ensureIndex('mod_easydcim_bw_guard_service_state', 'idx_mod_edbw_service_cycle', ['serviceid', 'cycle_start', 'cycle_end']);
        $this->ensureIndex('mod_easydcim_bw_guard_purchases', 'idx_mod_edbw_purchases_cycle', ['whmcs_serviceid', 'cycle_start', 'cycle_end', 'created_at']);
        $this->ensureIndex('mod_easydcim_bw_guard_graph_cache', 'idx_mod_edbw_graph_lookup', ['whmcs_serviceid', 'range_start', 'range_end', 'payload_hash']);
        $this->ensureIndex('mod_easydcim_bw_guard_logs', 'idx_mod_edbw_logs_level_created', ['level', 'created_at']);
        $this->ensureIndex('mod_easydcim_bw_guard_service_overrides', 'idx_mod_edbw_override_service', ['serviceid']);
        $this->ensureIndex('mod_easydcim_bw_guard_client_prefs', 'idx_mod_edbw_client_prefs_service', ['serviceid', 'userid']);
    }

    private function ensureIndex(string $table, string $indexName, array $columns): void
    {
        if (!Capsule::schema()->hasTable($table)) {
            return;
        }

        try {
            Capsule::schema()->table($table, static function (Blueprint $blueprint) use ($columns, $indexName): void {
                $blueprint->index($columns, $indexName);
            });
        } catch (\Throwable $e) {
            // Duplicate index errors are acceptable for idempotent migration behavior.
        }
    }
}
