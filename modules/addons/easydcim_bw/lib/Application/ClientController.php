<?php

declare(strict_types=1);

namespace EasyDcimBandwidthGuard\Application;

use EasyDcimBandwidthGuard\Config\Settings;
use EasyDcimBandwidthGuard\Domain\CycleCalculator;
use EasyDcimBandwidthGuard\Domain\GraphService;
use EasyDcimBandwidthGuard\Domain\PurchaseService;
use EasyDcimBandwidthGuard\Infrastructure\EasyDcimClient;
use EasyDcimBandwidthGuard\Support\Crypto;
use EasyDcimBandwidthGuard\Support\Logger;
use WHMCS\Database\Capsule;

final class ClientController
{
    private Settings $settings;
    private Logger $logger;
    private bool $isFa;

    public function __construct(Settings $settings, Logger $logger)
    {
        $this->settings = $settings;
        $this->logger = $logger;
        $lang = strtolower((string) ($_SESSION['Language'] ?? $_SESSION['adminlang'] ?? 'english'));
        $this->isFa = str_starts_with($lang, 'farsi') || str_starts_with($lang, 'persian') || str_starts_with($lang, 'fa');
    }

    public function buildTemplateVars(int $userId): array
    {
        $service = Capsule::table('mod_easydcim_bw_guard_service_state')
            ->where('userid', $userId)
            ->orderByDesc('id')
            ->first();

        if (!$service) {
            return [
                'has_service' => false,
                'message' => $this->t('no_service'),
                'chart_json' => json_encode(['labels' => [], 'datasets' => []]),
                'purchases' => [],
                'is_fa' => $this->isFa,
            ];
        }

        $client = new EasyDcimClient(
            $this->settings->getString('easydcim_base_url'),
            Crypto::safeDecrypt($this->settings->getString('easydcim_api_token')),
            $this->settings->getBool('use_impersonation', false),
            $this->logger
        );

        $cycle = new CycleCalculator();
        $hosting = Capsule::table('tblhosting')->where('id', $service->serviceid)->first();
        $window = $cycle->calculate((string) $hosting->nextduedate, (string) $hosting->billingcycle);

        $graphService = new GraphService($client);
        $chart = $graphService->getCachedOrFetch(
            (int) $service->serviceid,
            (string) $service->easydcim_service_id,
            $window['start'],
            date('Y-m-d H:i:s'),
            max(5, $this->settings->getInt('graph_cache_minutes', 30)),
            $this->settings->getBool('use_impersonation', false) ? (string) Capsule::table('tblclients')->where('id', $userId)->value('email') : null
        );

        $flash = '';
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($_POST['buy_package_id'])) {
            $flash = $this->handleBuyPackage($userId, (int) $service->serviceid, $window, (int) $_POST['buy_package_id']);
        }
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($_POST['save_autobuy'])) {
            $flash = $this->saveClientAutoBuyPrefs($userId, (int) $service->serviceid);
        }

        $packages = Capsule::table('mod_easydcim_bw_guard_packages')
            ->where('is_active', 1)
            ->orderBy('size_gb')
            ->get()
            ->map(static fn ($r): array => (array) $r)
            ->all();

        $purchases = Capsule::table('mod_easydcim_bw_guard_purchases')
            ->where('whmcs_serviceid', $service->serviceid)
            ->where('cycle_start', $window['start'])
            ->where('cycle_end', $window['end'])
            ->orderByDesc('id')
            ->limit(50)
            ->get()
            ->map(static fn ($r): array => (array) $r)
            ->all();

        $autobuyPref = Capsule::table('mod_easydcim_bw_guard_client_prefs')
            ->where('serviceid', (int) $service->serviceid)
            ->where('userid', $userId)
            ->first();

        $daysToReset = max(0, (int) floor((strtotime($window['reset_at']) - time()) / 86400));

        return [
            'has_service' => true,
            'service_id' => (int) $service->serviceid,
            'used_gb' => (float) $service->last_used_gb,
            'remaining_gb' => (float) $service->last_remaining_gb,
            'status' => (string) $service->last_status,
            'mode' => (string) $service->mode,
            'cycle_start' => $window['start'],
            'cycle_end' => $window['end'],
            'reset_at' => $window['reset_at'],
            'days_to_reset' => $daysToReset,
            'flash' => $flash,
            'chart_json' => json_encode($chart, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'packages' => $packages,
            'purchases' => $purchases,
            'autobuy_pref' => $autobuyPref ? (array) $autobuyPref : [],
            'is_fa' => $this->isFa,
            'i18n' => [
                'traffic_overview' => $this->t('traffic_overview'),
                'current_cycle' => $this->t('current_cycle'),
                'buy_additional' => $this->t('buy_additional'),
                'purchases_cycle' => $this->t('purchases_cycle'),
                'save' => $this->t('save'),
                'autobuy_title' => $this->t('autobuy_title'),
            ],
        ];
    }

    private function handleBuyPackage(int $userId, int $serviceId, array $window, int $packageId): string
    {
        $package = Capsule::table('mod_easydcim_bw_guard_packages')->where('id', $packageId)->where('is_active', 1)->first();
        if (!$package) {
            return 'Selected package is not available.';
        }

        if (!function_exists('localAPI')) {
            return $this->t('localapi_unavailable');
        }

        $create = localAPI('CreateInvoice', [
            'userid' => $userId,
            'status' => 'Unpaid',
            'sendinvoice' => true,
            'itemdescription1' => sprintf('Extra Bandwidth %.2fGB for service #%d', (float) $package->size_gb, $serviceId),
            'itemamount1' => (float) $package->price,
            'itemtaxed1' => (int) $package->taxed,
        ]);
        if (($create['result'] ?? '') !== 'success') {
            return $this->t('invoice_fail');
        }

        $invoiceId = (int) ($create['invoiceid'] ?? 0);
        if ($invoiceId <= 0) {
            return $this->t('invoice_invalid');
        }

        $status = (string) Capsule::table('tblinvoices')->where('id', $invoiceId)->value('status');
        $paymentStatus = strtolower($status) === 'paid' ? 'paid' : 'pending';

        (new PurchaseService($this->logger))->recordPurchase([
            'whmcs_serviceid' => $serviceId,
            'userid' => $userId,
            'package_id' => (int) $package->id,
            'size_gb' => (float) $package->size_gb,
            'price' => (float) $package->price,
            'invoice_id' => $invoiceId,
            'cycle_start' => $window['start'],
            'cycle_end' => $window['end'],
            'reset_at' => $window['reset_at'],
            'actor' => 'client_manual',
            'payment_status' => $paymentStatus,
            'created_at' => date('Y-m-d H:i:s'),
            'context' => [
                'whmcs_service_id' => $serviceId,
                'userid' => $userId,
                'invoice_id' => $invoiceId,
                'package_id' => (int) $package->id,
                'size_gb' => (float) $package->size_gb,
                'price' => (float) $package->price,
                'cycle_start' => $window['start'],
                'cycle_end' => $window['end'],
                'reset_at' => $window['reset_at'],
                'purchased_at' => date('Y-m-d H:i:s'),
                'actor' => 'client_manual',
                'payment_status' => $paymentStatus,
            ],
        ]);

        return ($this->isFa ? 'فاکتور #' : 'Invoice #') . $invoiceId . ($this->isFa ? ' برای این سیکل ساخته شد.' : ' created successfully for this cycle.');
    }

    private function saveClientAutoBuyPrefs(int $userId, int $serviceId): string
    {
        $enabled = isset($_POST['autobuy_enabled']) ? 1 : 0;
        $threshold = isset($_POST['autobuy_threshold_gb']) && $_POST['autobuy_threshold_gb'] !== '' ? (float) $_POST['autobuy_threshold_gb'] : null;
        $packageId = isset($_POST['autobuy_package_id']) && $_POST['autobuy_package_id'] !== '' ? (int) $_POST['autobuy_package_id'] : null;
        $maxPerCycle = isset($_POST['autobuy_max_per_cycle']) && $_POST['autobuy_max_per_cycle'] !== '' ? (int) $_POST['autobuy_max_per_cycle'] : null;

        Capsule::table('mod_easydcim_bw_guard_client_prefs')->updateOrInsert(
            ['serviceid' => $serviceId, 'userid' => $userId],
            [
                'autobuy_enabled' => $enabled,
                'autobuy_threshold_gb' => $threshold,
                'autobuy_package_id' => $packageId,
                'autobuy_max_per_cycle' => $maxPerCycle,
                'updated_at' => date('Y-m-d H:i:s'),
                'created_at' => date('Y-m-d H:i:s'),
            ]
        );

        return $this->isFa ? 'تنظیمات Auto-Buy ذخیره شد.' : 'Auto-Buy settings saved.';
    }

    private function t(string $key): string
    {
        $fa = [
            'no_service' => 'هیچ سرویس مدیریت‌شده‌ای برای حساب شما پیدا نشد.',
            'traffic_overview' => 'وضعیت ترافیک',
            'current_cycle' => 'سیکل جاری',
            'buy_additional' => 'خرید ترافیک اضافه',
            'purchases_cycle' => 'خریدهای این سیکل',
            'save' => 'ذخیره',
            'autobuy_title' => 'تنظیمات خرید خودکار',
            'localapi_unavailable' => 'localAPI در دسترس نیست.',
            'invoice_fail' => 'ساخت فاکتور انجام نشد.',
            'invoice_invalid' => 'شناسه فاکتور معتبر نیست.',
        ];
        $en = [
            'no_service' => 'No managed service found for your account.',
            'traffic_overview' => 'Traffic Overview',
            'current_cycle' => 'Current Cycle',
            'buy_additional' => 'Buy Additional Traffic',
            'purchases_cycle' => 'Purchases in Current Cycle',
            'save' => 'Save',
            'autobuy_title' => 'Auto-Buy Preferences',
            'localapi_unavailable' => 'localAPI is unavailable.',
            'invoice_fail' => 'Could not create invoice.',
            'invoice_invalid' => 'Invoice creation returned invalid invoice id.',
        ];
        $map = $this->isFa ? $fa : $en;
        return $map[$key] ?? $key;
    }
}
