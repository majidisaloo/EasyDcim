<?php

declare(strict_types=1);

namespace EasyDcimBandwidthGuard\Application;

use EasyDcimBandwidthGuard\Config\Settings;
use EasyDcimBandwidthGuard\Domain\CycleCalculator;
use EasyDcimBandwidthGuard\Domain\EnforcementService;
use EasyDcimBandwidthGuard\Domain\PurchaseService;
use EasyDcimBandwidthGuard\Domain\QuotaResolver;
use EasyDcimBandwidthGuard\Infrastructure\EasyDcimClient;
use EasyDcimBandwidthGuard\Infrastructure\Lock\LockManager;
use EasyDcimBandwidthGuard\Support\Crypto;
use EasyDcimBandwidthGuard\Support\Logger;
use EasyDcimBandwidthGuard\Support\Version;
use WHMCS\Database\Capsule;

final class CronRunner
{
    private const RELEASE_REPO = 'majidisaloo/EasyDcim';
    private Settings $settings;
    private Logger $logger;

    public function __construct(Settings $settings, Logger $logger)
    {
        $this->settings = $settings;
        $this->logger = $logger;
    }

    public function runPoll(): void
    {
        if (!$this->settings->getBool('module_enabled', true)) {
            return;
        }

        $lock = new LockManager();
        if (!$lock->acquire('poll', 600)) {
            return;
        }

        try {
            $this->cleanupOldLogs();

            if ($this->apiCircuitOpen()) {
                $this->logger->log('WARNING', 'poll_skipped_circuit_open');
                return;
            }

            $services = $this->loadManagedServices();
            $client = $this->buildEasyDcimClient();
            $cycleCalc = new CycleCalculator();
            $quotaResolver = new QuotaResolver();
            $testMode = $this->settings->getBool('test_mode', false);
            $enforcement = new EnforcementService($client, $this->logger, $testMode);
            if ($testMode) {
                $this->logger->log('INFO', 'test_mode_enabled', ['scope' => 'cron_poll']);
            }
            $purchaseService = new PurchaseService($this->logger);

            foreach ($services as $service) {
                $this->processService($service, $cycleCalc, $quotaResolver, $client, $enforcement, $purchaseService);
            }
        } catch (\Throwable $e) {
            $this->registerApiFailure();
            $this->logger->log('ERROR', 'cron_poll_failed', ['error' => $e->getMessage()]);
        } finally {
            $lock->release('poll');
        }
    }

    public function runUpdateCheck(string $moduleDir): void
    {
        if (!$this->settings->getBool('module_enabled', true)) {
            return;
        }

        $lastCheck = Capsule::table('mod_easydcim_bw_guard_meta')->where('meta_key', 'last_update_check_at')->value('meta_value');
        $interval = max(5, $this->settings->getInt('update_check_interval_minutes', 30));
        if ($lastCheck && strtotime((string) $lastCheck) > time() - ($interval * 60)) {
            return;
        }

        try {
            $repo = self::RELEASE_REPO;
            $release = $this->fetchLatestRelease($repo);
            $latestTag = (string) ($release['tag_name'] ?? '');
            $latestVersion = ltrim($latestTag, 'vV');
            $currentVersion = Version::current($moduleDir)['module_version'];
            $available = $this->compareVersion($latestVersion, $currentVersion) > 0;

            Capsule::table('mod_easydcim_bw_guard_meta')->updateOrInsert(
                ['meta_key' => 'release_latest_tag'],
                ['meta_value' => $latestTag, 'updated_at' => date('Y-m-d H:i:s')]
            );
            Capsule::table('mod_easydcim_bw_guard_meta')->updateOrInsert(
                ['meta_key' => 'release_update_available'],
                ['meta_value' => $available ? '1' : '0', 'updated_at' => date('Y-m-d H:i:s')]
            );
            Capsule::table('mod_easydcim_bw_guard_meta')->updateOrInsert(
                ['meta_key' => 'update_available'],
                ['meta_value' => $available ? '1' : '0', 'updated_at' => date('Y-m-d H:i:s')]
            );

            $this->logger->log('INFO', 'release_update_check', [
                'repo' => $repo,
                'latest_tag' => $latestTag,
                'available' => $available ? 1 : 0,
                'current_version' => $currentVersion,
            ]);

            if ($available && $this->settings->getString('update_mode', 'check_oneclick') === 'auto') {
                $this->logger->log('WARNING', 'release_auto_update_skipped', ['reason' => 'manual_apply_required']);
            }
        } catch (\Throwable $e) {
            $this->logger->log('ERROR', 'update_check_failed', ['error' => $e->getMessage()]);
        }

        Capsule::table('mod_easydcim_bw_guard_meta')->updateOrInsert(
            ['meta_key' => 'last_update_check_at'],
            ['meta_value' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')]
        );

        $this->processQueuedReleaseUpdate();
    }

    private function fetchLatestRelease(string $repo): array
    {
        if (!function_exists('curl_init')) {
            throw new \RuntimeException('cURL extension is required for update checks.');
        }

        $repo = trim($repo);
        if (!preg_match('/^[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+$/', $repo)) {
            throw new \RuntimeException('Invalid github_repo format.');
        }

        $ch = curl_init('https://api.github.com/repos/' . $repo . '/releases/latest');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($ch, CURLOPT_TIMEOUT, 20);
        curl_setopt($ch, CURLOPT_LOW_SPEED_LIMIT, 1);
        curl_setopt($ch, CURLOPT_LOW_SPEED_TIME, 8);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['User-Agent: EasyDcim-BW', 'Accept: application/vnd.github+json']);
        $raw = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($code < 200 || $code >= 300) {
            throw new \RuntimeException('Release API failed: HTTP ' . $code . ' ' . $err);
        }

        $data = json_decode((string) $raw, true);
        if (!is_array($data) || !isset($data['tag_name'])) {
            throw new \RuntimeException('Invalid release API payload.');
        }
        return $data;
    }

    private function compareVersion(string $a, string $b): int
    {
        $normalize = static function (string $v): array {
            if (!preg_match('/^(\d+)\.(\d{1,2})$/', trim($v), $m)) {
                return [0, 0];
            }
            return [(int) $m[1], (int) $m[2]];
        };
        [$aMaj, $aMin] = $normalize($a);
        [$bMaj, $bMin] = $normalize($b);
        return ($aMaj <=> $bMaj) ?: ($aMin <=> $bMin);
    }

    private function processService(
        array $service,
        CycleCalculator $cycleCalc,
        QuotaResolver $quotaResolver,
        EasyDcimClient $client,
        EnforcementService $enforcement,
        PurchaseService $purchaseService
    ): void {
        $serviceLock = new LockManager();
        if (!$serviceLock->acquire('service:' . $service['id'], 120)) {
            return;
        }

        try {
            $cycle = $cycleCalc->calculate($service['nextduedate'], $service['billingcycle']);
            $this->syncPendingPurchases((int) $service['id'], $cycle['start'], $cycle['end']);
            $resolved = $quotaResolver->resolve(
                (int) $service['id'],
                (int) $service['packageid'],
                $cycle['start'],
                $cycle['end'],
                $service['custom_fields'],
                strtoupper($this->settings->getString('default_calculation_mode', 'TOTAL'))
            );

            $impersonate = $this->settings->getBool('use_impersonation', false)
                ? ($service['email'] ?? null)
                : null;

            $usage = $client->bandwidth($service['easydcim_service_id'], $cycle['start'], $cycle['end'], $impersonate);
            $usedGb = $this->extractUsedGb($usage['data'] ?? [], $resolved['mode']);
            $allowedGb = (float) $resolved['allowed_gb'];
            $remaining = max(0, $allowedGb - $usedGb);
            $addedByAutoBuy = $this->maybeAutoBuy($service, $cycle, $remaining, $purchaseService);
            if ($addedByAutoBuy > 0) {
                $allowedGb += $addedByAutoBuy;
                $remaining = max(0, $allowedGb - $usedGb);
            }

            $previous = Capsule::table('mod_easydcim_bw_guard_service_state')->where('serviceid', (int) $service['id'])->first();
            $wasLimited = ((string) ($previous->last_status ?? '')) === 'limited';

            if ($usedGb >= $allowedGb) {
                $enforcement->enforce(
                    $resolved['action'],
                    $service['easydcim_service_id'],
                    $service['easydcim_order_id'],
                    $impersonate,
                    $service['easydcim_server_id'] !== '' ? $service['easydcim_server_id'] : null
                );
                $status = 'limited';
            } else {
                if (($service['domainstatus'] ?? '') === 'Suspended' || $wasLimited) {
                    $enforcement->unlock(
                        $resolved['action'],
                        $service['easydcim_service_id'],
                        $service['easydcim_order_id'],
                        $impersonate,
                        $service['easydcim_server_id'] !== '' ? $service['easydcim_server_id'] : null
                    );
                }
                $status = 'ok';
            }

            Capsule::table('mod_easydcim_bw_guard_service_state')->updateOrInsert(
                ['serviceid' => (int) $service['id']],
                [
                    'userid' => (int) $service['userid'],
                    'easydcim_service_id' => $service['easydcim_service_id'],
                    'easydcim_order_id' => $service['easydcim_order_id'],
                    'cycle_start' => $cycle['start'],
                    'cycle_end' => $cycle['end'],
                    'base_quota_gb' => $resolved['base_quota_gb'],
                    'mode' => $resolved['mode'],
                    'action' => $resolved['action'],
                    'last_used_gb' => $usedGb,
                    'last_remaining_gb' => $remaining,
                    'last_status' => $status,
                    'last_check_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s'),
                    'created_at' => date('Y-m-d H:i:s'),
                ]
            );
            $this->registerApiSuccess();
        } catch (\Throwable $e) {
            $this->registerApiFailure();
            $this->logger->log('ERROR', 'service_poll_failed', ['serviceid' => $service['id'], 'error' => $e->getMessage()]);
        } finally {
            $serviceLock->release('service:' . $service['id']);
        }
    }

    private function extractUsedGb(array $payload, string $mode): float
    {
        $mode = strtoupper($mode);
        $in = (float) ($payload['in'] ?? $payload['inbound'] ?? $payload['download'] ?? 0);
        $out = (float) ($payload['out'] ?? $payload['outbound'] ?? $payload['upload'] ?? 0);
        if ($this->settings->getString('traffic_direction_map', 'normal') === 'swap') {
            $tmp = $in;
            $in = $out;
            $out = $tmp;
        }
        $total = (float) ($payload['total'] ?? ($in + $out));

        $bytes = match ($mode) {
            'IN' => $in,
            'OUT' => $out,
            default => $total,
        };

        return $bytes / 1073741824;
    }

    private function buildEasyDcimClient(): EasyDcimClient
    {
        return new EasyDcimClient(
            $this->settings->getString('easydcim_base_url'),
            Crypto::safeDecrypt($this->settings->getString('easydcim_api_token')),
            $this->settings->getBool('use_impersonation', false),
            $this->logger,
            $this->proxyConfig()
        );
    }

    private function loadManagedServices(): array
    {
        $pids = array_map('intval', $this->settings->getCsvList('managed_pids'));
        $gids = array_map('intval', $this->settings->getCsvList('managed_gids'));

        $query = Capsule::table('tblhosting as h')
            ->join('tblproducts as p', 'p.id', '=', 'h.packageid')
            ->leftJoin('tblclients as c', 'c.id', '=', 'h.userid')
            ->select([
                'h.id',
                'h.userid',
                'h.packageid',
                'h.billingcycle',
                'h.nextduedate',
                'h.domainstatus',
                'c.email',
            ])
            ->whereIn('h.domainstatus', ['Active', 'Suspended']);

        if (!empty($pids)) {
            $query->whereIn('h.packageid', $pids);
        } elseif (!empty($gids)) {
            $query->whereIn('p.gid', $gids);
        }

        $services = [];
        foreach ($query->limit(1000)->get() as $row) {
            $serviceId = (int) $row->id;
            $map = $this->resolveCustomFieldsForService($serviceId);
            if (($map['easydcim_service_id'] ?? '') === '') {
                continue;
            }

            $services[] = [
                'id' => $serviceId,
                'userid' => (int) $row->userid,
                'packageid' => (int) $row->packageid,
                'billingcycle' => (string) $row->billingcycle,
                'nextduedate' => (string) $row->nextduedate,
                'domainstatus' => (string) $row->domainstatus,
                'email' => (string) ($row->email ?? ''),
                'easydcim_service_id' => (string) $map['easydcim_service_id'],
                'easydcim_order_id' => (string) ($map['easydcim_order_id'] ?? ''),
                'easydcim_server_id' => (string) ($map['easydcim_server_id'] ?? ''),
                'custom_fields' => $map,
            ];
        }

        return $services;
    }

    private function resolveCustomFieldsForService(int $hostingId): array
    {
        $rows = Capsule::table('tblcustomfieldsvalues as v')
            ->join('tblcustomfields as f', 'f.id', '=', 'v.fieldid')
            ->where('f.type', 'product')
            ->where('v.relid', $hostingId)
            ->select(['f.fieldname', 'v.value'])
            ->get();

        $data = [];
        foreach ($rows as $row) {
            $name = strtolower(trim((string) $row->fieldname));
            $cleanName = explode('|', $name)[0];
            if (in_array($cleanName, ['easydcim_service_id', 'easydcim_order_id', 'easydcim_server_id', 'traffic_mode', 'base_quota_override_gb'], true)) {
                $data[$cleanName] = (string) $row->value;
            }
        }

        return $data;
    }

    private function maybeAutoBuy(array $service, array $cycle, float $remaining, PurchaseService $purchaseService): float
    {
        $prefs = Capsule::table('mod_easydcim_bw_guard_client_prefs')
            ->where('serviceid', (int) $service['id'])
            ->where('userid', (int) $service['userid'])
            ->first();

        $enabled = $prefs ? ((int) ($prefs->autobuy_enabled ?? 0) === 1) : $this->settings->getBool('autobuy_enabled', false);
        if (!$enabled) {
            return 0.0;
        }

        $threshold = $prefs && $prefs->autobuy_threshold_gb !== null
            ? (float) $prefs->autobuy_threshold_gb
            : (float) $this->settings->getInt('autobuy_threshold_gb', 10);
        if ($remaining > $threshold) {
            return 0.0;
        }

        $packageId = $prefs && $prefs->autobuy_package_id !== null
            ? (int) $prefs->autobuy_package_id
            : $this->settings->getInt('autobuy_default_package_id', 0);
        if ($packageId <= 0) {
            return 0.0;
        }

        $package = Capsule::table('mod_easydcim_bw_guard_packages')->where('id', $packageId)->where('is_active', 1)->first();
        if (!$package) {
            return 0.0;
        }

        $alreadyBought = (int) Capsule::table('mod_easydcim_bw_guard_purchases')
            ->where('whmcs_serviceid', (int) $service['id'])
            ->where('cycle_start', $cycle['start'])
            ->where('cycle_end', $cycle['end'])
            ->where('actor', 'autobuy_cron')
            ->count();
        $maxPerCycle = $prefs && $prefs->autobuy_max_per_cycle !== null
            ? (int) $prefs->autobuy_max_per_cycle
            : max(1, $this->settings->getInt('autobuy_max_per_cycle', 5));
        if ($alreadyBought >= max(1, $maxPerCycle)) {
            return 0.0;
        }

        $credit = (float) Capsule::table('tblclients')->where('id', (int) $service['userid'])->value('credit');
        if ($credit < (float) $package->price) {
            $this->logger->log('WARNING', 'autobuy_skipped_no_credit', ['serviceid' => $service['id'], 'credit' => $credit]);
            return 0.0;
        }

        $invoiceId = $this->createAndPayInvoice((int) $service['userid'], (int) $service['id'], (float) $package->price, (float) $package->size_gb);
        if ($invoiceId <= 0) {
            return 0.0;
        }

        $purchaseService->recordPurchase([
            'whmcs_serviceid' => (int) $service['id'],
            'userid' => (int) $service['userid'],
            'package_id' => (int) $package->id,
            'size_gb' => (float) $package->size_gb,
            'price' => (float) $package->price,
            'invoice_id' => $invoiceId,
            'cycle_start' => $cycle['start'],
            'cycle_end' => $cycle['end'],
            'reset_at' => $cycle['reset_at'],
            'actor' => 'autobuy_cron',
            'payment_status' => 'paid',
            'remaining_before_gb' => $remaining,
            'remaining_after_gb' => $remaining + (float) $package->size_gb,
            'context' => [
                'whmcs_service_id' => (int) $service['id'],
                'userid' => (int) $service['userid'],
                'invoice_id' => $invoiceId,
                'package_id' => (int) $package->id,
                'size_gb' => (float) $package->size_gb,
                'price' => (float) $package->price,
                'cycle_start' => $cycle['start'],
                'cycle_end' => $cycle['end'],
                'reset_at' => $cycle['reset_at'],
                'purchased_at' => date('Y-m-d H:i:s'),
                'actor' => 'autobuy_cron',
                'payment_status' => 'paid',
                'remaining_before_gb' => $remaining,
                'remaining_after_gb' => $remaining + (float) $package->size_gb,
            ],
        ]);

        return (float) $package->size_gb;
    }

    private function cleanupOldLogs(): void
    {
        $days = max(1, $this->settings->getInt('log_retention_days', 30));
        $cutoff = date('Y-m-d H:i:s', time() - ($days * 86400));
        try {
            $deleted = Capsule::table('mod_easydcim_bw_guard_logs')->where('created_at', '<', $cutoff)->delete();
            if ($deleted > 0) {
                $this->logger->log('INFO', 'logs_retention_cleanup', ['deleted' => $deleted, 'days' => $days]);
            }
        } catch (\Throwable $e) {
            $this->logger->log('ERROR', 'logs_retention_cleanup_failed', ['error' => $e->getMessage()]);
        }
    }

    private function createAndPayInvoice(int $userId, int $serviceId, float $price, float $sizeGb): int
    {
        if (!function_exists('localAPI')) {
            return 0;
        }

        $description = sprintf('Extra Bandwidth %.2fGB for service #%d', $sizeGb, $serviceId);
        $create = localAPI('CreateInvoice', [
            'userid' => $userId,
            'status' => 'Unpaid',
            'sendinvoice' => false,
            'itemdescription1' => $description,
            'itemamount1' => $price,
            'itemtaxed1' => 0,
        ]);

        $invoiceId = (int) ($create['invoiceid'] ?? 0);
        if (($create['result'] ?? '') !== 'success' || $invoiceId <= 0) {
            $this->logger->log('ERROR', 'autobuy_invoice_create_failed', ['userid' => $userId, 'response' => $create]);
            return 0;
        }

        $pay = localAPI('AddInvoicePayment', [
            'invoiceid' => $invoiceId,
            'transid' => 'AUTO-' . time() . '-' . $serviceId,
            'gateway' => 'credit',
            'noemail' => true,
        ]);

        if (($pay['result'] ?? '') !== 'success') {
            $apply = localAPI('ApplyCredit', ['invoiceid' => $invoiceId, 'amount' => $price]);
            if (($apply['result'] ?? '') !== 'success') {
                $this->logger->log('ERROR', 'autobuy_invoice_pay_failed', ['invoiceid' => $invoiceId, 'response' => $pay, 'apply_credit' => $apply]);
                return 0;
            }
        }

        return $invoiceId;
    }

    private function syncPendingPurchases(int $serviceId, string $cycleStart, string $cycleEnd): void
    {
        $pending = Capsule::table('mod_easydcim_bw_guard_purchases')
            ->where('whmcs_serviceid', $serviceId)
            ->where('cycle_start', $cycleStart)
            ->where('cycle_end', $cycleEnd)
            ->where('payment_status', 'pending')
            ->whereNotNull('invoiceid')
            ->get();

        foreach ($pending as $row) {
            $status = (string) Capsule::table('tblinvoices')->where('id', (int) $row->invoiceid)->value('status');
            if (strtolower($status) === 'paid') {
                Capsule::table('mod_easydcim_bw_guard_purchases')
                    ->where('id', (int) $row->id)
                    ->update(['payment_status' => 'paid']);
            }
        }
    }

    private function apiCircuitOpen(): bool
    {
        $failedCount = (int) Capsule::table('mod_easydcim_bw_guard_meta')->where('meta_key', 'api_fail_count')->value('meta_value');
        $lastFailAt = Capsule::table('mod_easydcim_bw_guard_meta')->where('meta_key', 'api_last_fail_at')->value('meta_value');
        if ($failedCount < 5) {
            return false;
        }

        return $lastFailAt && strtotime((string) $lastFailAt) > time() - 900;
    }

    private function registerApiFailure(): void
    {
        $count = (int) Capsule::table('mod_easydcim_bw_guard_meta')->where('meta_key', 'api_fail_count')->value('meta_value');
        Capsule::table('mod_easydcim_bw_guard_meta')->updateOrInsert(
            ['meta_key' => 'api_fail_count'],
            ['meta_value' => (string) ($count + 1), 'updated_at' => date('Y-m-d H:i:s')]
        );
        Capsule::table('mod_easydcim_bw_guard_meta')->updateOrInsert(
            ['meta_key' => 'api_last_fail_at'],
            ['meta_value' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')]
        );
    }

    private function registerApiSuccess(): void
    {
        Capsule::table('mod_easydcim_bw_guard_meta')->updateOrInsert(
            ['meta_key' => 'api_fail_count'],
            ['meta_value' => '0', 'updated_at' => date('Y-m-d H:i:s')]
        );
    }

    private function proxyConfig(): array
    {
        return [
            'enabled' => $this->settings->getBool('proxy_enabled', false),
            'type' => $this->settings->getString('proxy_type', 'http'),
            'host' => $this->settings->getString('proxy_host'),
            'port' => $this->settings->getInt('proxy_port', 0),
            'username' => $this->settings->getString('proxy_username'),
            'password' => Crypto::safeDecrypt($this->settings->getString('proxy_password')),
            'allow_self_signed' => $this->settings->getBool('allow_self_signed', true),
        ];
    }

    private function processQueuedReleaseUpdate(): void
    {
        $queued = (string) Capsule::table('mod_easydcim_bw_guard_meta')->where('meta_key', 'release_apply_requested')->value('meta_value');
        if ($queued !== '1') {
            return;
        }

        $lock = new LockManager();
        if (!$lock->acquire('update_apply', 900)) {
            return;
        }

        Capsule::table('mod_easydcim_bw_guard_meta')->updateOrInsert(
            ['meta_key' => 'update_in_progress'],
            ['meta_value' => '1', 'updated_at' => date('Y-m-d H:i:s')]
        );

        try {
            if (function_exists('set_time_limit')) {
                @set_time_limit(120);
            }
            if (!class_exists(\ZipArchive::class)) {
                throw new \RuntimeException('ZipArchive extension is required.');
            }

            $release = $this->fetchLatestRelease(self::RELEASE_REPO);
            $zipUrl = $this->extractZipUrl($release);
            if ($zipUrl === '') {
                throw new \RuntimeException('No ZIP asset found in latest release.');
            }

            $tmpZip = tempnam(sys_get_temp_dir(), 'edbw_rel_');
            if ($tmpZip === false) {
                throw new \RuntimeException('Could not allocate temp file.');
            }

            $this->downloadFile($zipUrl, $tmpZip, 60);
            $this->extractAddonFromZip($tmpZip);
            @unlink($tmpZip);

            Capsule::table('mod_easydcim_bw_guard_meta')->updateOrInsert(
                ['meta_key' => 'release_update_available'],
                ['meta_value' => '0', 'updated_at' => date('Y-m-d H:i:s')]
            );
            Capsule::table('mod_easydcim_bw_guard_meta')->updateOrInsert(
                ['meta_key' => 'release_apply_requested'],
                ['meta_value' => '0', 'updated_at' => date('Y-m-d H:i:s')]
            );
            $this->logger->log('INFO', 'release_update_applied', [
                'tag' => (string) ($release['tag_name'] ?? ''),
                'zip_url' => $zipUrl,
            ]);
        } catch (\Throwable $e) {
            $this->logger->log('ERROR', 'release_update_apply_failed', ['error' => $e->getMessage()]);
        } finally {
            Capsule::table('mod_easydcim_bw_guard_meta')->updateOrInsert(
                ['meta_key' => 'update_in_progress'],
                ['meta_value' => '0', 'updated_at' => date('Y-m-d H:i:s')]
            );
            $lock->release('update_apply');
        }
    }

    private function extractZipUrl(array $release): string
    {
        foreach (($release['assets'] ?? []) as $asset) {
            $name = strtolower((string) ($asset['name'] ?? ''));
            if (substr($name, -4) === '.zip' && !empty($asset['browser_download_url'])) {
                return (string) $asset['browser_download_url'];
            }
        }
        return '';
    }

    private function downloadFile(string $url, string $target, int $timeout = 60): void
    {
        $fh = fopen($target, 'wb');
        if ($fh === false) {
            throw new \RuntimeException('Cannot open temp file for writing.');
        }

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_FILE, $fh);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($ch, CURLOPT_TIMEOUT, max(10, $timeout));
        curl_setopt($ch, CURLOPT_LOW_SPEED_LIMIT, 1);
        curl_setopt($ch, CURLOPT_LOW_SPEED_TIME, 10);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['User-Agent: EasyDcim-BW']);
        curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        fclose($fh);

        if ($code < 200 || $code >= 300) {
            throw new \RuntimeException('Download failed: HTTP ' . $code . ' ' . $err);
        }
    }

    private function extractAddonFromZip(string $zipPath): void
    {
        $zip = new \ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new \RuntimeException('Cannot open downloaded ZIP.');
        }

        $whmcsRoot = realpath(__DIR__ . '/../../../..');
        if ($whmcsRoot === false) {
            $zip->close();
            throw new \RuntimeException('Cannot resolve WHMCS root path.');
        }

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (!is_string($name) || strpos($name, 'modules/addons/easydcim_bw/') === false) {
                continue;
            }

            $normalized = preg_replace('#^[^/]+/#', '', $name, 1);
            if (!is_string($normalized) || strpos($normalized, 'modules/addons/easydcim_bw/') !== 0) {
                continue;
            }

            $target = $whmcsRoot . '/' . $normalized;
            if (substr($normalized, -1) === '/') {
                if (!is_dir($target)) {
                    mkdir($target, 0755, true);
                }
                continue;
            }

            $dir = dirname($target);
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            $content = $zip->getFromIndex($i);
            if ($content === false) {
                continue;
            }
            file_put_contents($target, $content);
        }

        $zip->close();
    }
}
