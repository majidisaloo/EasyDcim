<?php

declare(strict_types=1);

namespace EasyDcimBandwidthGuard\Application;

use EasyDcimBandwidthGuard\Config\Settings;
use EasyDcimBandwidthGuard\Infrastructure\EasyDcimClient;
use EasyDcimBandwidthGuard\Infrastructure\Git\GitUpdateManager;
use EasyDcimBandwidthGuard\Support\Crypto;
use EasyDcimBandwidthGuard\Support\Logger;
use EasyDcimBandwidthGuard\Support\Version;
use WHMCS\Database\Capsule;

final class AdminController
{
    private const RELEASE_REPO = 'majidisaloo/EasyDcim';
    private Settings $settings;
    private Logger $logger;
    private string $moduleDir;
    private bool $isFa;
    private array $orderDetailsRuntimeCache = [];
    private array $orderPortsRuntimeCache = [];
    private array $orderServiceRuntimeCache = [];
    private array $portDetailsRuntimeCache = [];
    private array $itemLabelRuntimeCache = [];
    private ?bool $orderPortsEndpointSupported = null;

    public function __construct(Settings $settings, Logger $logger, string $moduleDir)
    {
        $this->settings = $settings;
        $this->logger = $logger;
        $this->moduleDir = $moduleDir;
        $configured = strtolower($this->settings->getString('ui_language', 'auto'));
        if (in_array($configured, ['fa', 'farsi', 'persian'], true)) {
            $lang = 'farsi';
        } elseif (in_array($configured, ['en', 'english'], true)) {
            $lang = 'english';
        } else {
            $lang = strtolower((string) ($_SESSION['adminlang'] ?? $_SESSION['Language'] ?? 'english'));
        }
        $this->isFa = str_starts_with($lang, 'farsi') || str_starts_with($lang, 'persian') || str_starts_with($lang, 'fa');
    }

    public function handle(array $vars): void
    {
        $action = $_REQUEST['action'] ?? '';
        $api = isset($_GET['api']) ? (string) $_GET['api'] : '';
        $tab = (string) ($_REQUEST['tab'] ?? 'dashboard');
        $ajax = $this->isAjaxRequest();
        $flash = [];

        if ($api === 'servers_batch') {
            $op = (string) ($_REQUEST['op'] ?? 'status');
            try {
                if ($op === 'test') {
                    $result = $this->testAllServices();
                    $this->json($this->buildBatchAjaxPayload($result));
                    return;
                }
                if ($op === 'reset') {
                    $result = $this->resetTestAllServices();
                    $this->json($this->buildBatchAjaxPayload($result));
                    return;
                }
                if ($op === 'stop') {
                    $result = $this->stopTestAllServices();
                    $this->json($this->buildBatchAjaxPayload($result));
                    return;
                }
                if ($op === 'refresh') {
                    $result = $this->refreshServersCacheNow();
                    $this->json($this->buildBatchAjaxPayload($result));
                    return;
                }
                $this->json($this->buildBatchAjaxPayload([
                    'type' => 'info',
                    'text' => '',
                    'silent' => true,
                ]));
                return;
            } catch (\Throwable $e) {
                $this->logger->log('ERROR', 'servers_batch_api_failed', ['op' => $op, 'error' => $e->getMessage()]);
                $this->json([
                    'type' => 'danger',
                    'message' => $this->t('servers_test_all_failed') . ': ' . $e->getMessage(),
                    'silent' => false,
                    'state' => $this->buildBatchStatePayload(),
                ]);
                return;
            }
        }

        if ($ajax && in_array((string) $action, ['test_all_services', 'stop_test_all_services', 'reset_test_all_services', 'refresh_servers_cache'], true)) {
            try {
                if ($action === 'test_all_services') {
                    $this->json($this->buildBatchAjaxPayload($this->testAllServices()));
                    return;
                }
                if ($action === 'stop_test_all_services') {
                    $this->json($this->buildBatchAjaxPayload($this->stopTestAllServices()));
                    return;
                }
                if ($action === 'reset_test_all_services') {
                    $this->json($this->buildBatchAjaxPayload($this->resetTestAllServices()));
                    return;
                }
                if ($action === 'refresh_servers_cache') {
                    $this->json($this->buildBatchAjaxPayload($this->refreshServersCacheNow()));
                    return;
                }
            } catch (\Throwable $e) {
                $this->logger->log('ERROR', 'servers_batch_ajax_action_failed', ['action' => $action, 'error' => $e->getMessage()]);
                $this->json([
                    'type' => 'danger',
                    'message' => $this->t('servers_test_all_failed') . ': ' . $e->getMessage(),
                    'silent' => false,
                    'state' => $this->buildBatchStatePayload(),
                ]);
                return;
            }
        }

        if ($api === 'purchase_logs') {
            $this->json($this->getPurchaseLogs(300));
            return;
        }

        if ($api === 'enforcement_logs') {
            $this->json($this->getEnforcementLogs(300));
            return;
        }
        if ($api === 'save_plan') {
            $this->json($this->saveProductPlan());
            return;
        }
        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST'
            && (string) ($_POST['action'] ?? '') === 'save_product_plan'
            && isset($_POST['ajax'])
        ) {
            $this->json($this->saveProductPlan());
            return;
        }

        if ($action === 'apply_update') {
            $flash[] = ['type' => 'warning', 'text' => 'Git shell update is disabled. Use Release update actions.'];
        }
        if ($action === 'add_package') {
            $flash[] = $this->addPackage();
            $tab = 'packages';
        }
        if ($action === 'update_package') {
            $flash[] = $this->updatePackage();
            $tab = 'packages';
        }
        if ($action === 'delete_package') {
            $flash[] = $this->deletePackage();
            $tab = 'packages';
        }
        if ($action === 'save_override') {
            $this->saveOverride();
            $tab = 'packages';
        }
        if ($action === 'save_settings') {
            $flash[] = $this->saveSettings();
            $tab = 'settings';
        }
        if ($action === 'save_connection') {
            $flash[] = $this->saveSettings();
            $tab = 'connection';
        }
        if ($action === 'test_easydcim') {
            $flash[] = $this->testEasyDcimConnection();
            $tab = 'connection';
        }
        if ($action === 'run_preflight') {
            $this->invalidateHealthCheckCache();
            $flash[] = ['type' => 'info', 'text' => $this->t('preflight_retested')];
            $tab = (string) ($_POST['tab'] ?? 'health');
        }
        if ($action === 'check_release_update') {
            $flash[] = $this->checkReleaseUpdate();
            $tab = 'dashboard';
        }
        if ($action === 'apply_release_update') {
            $flash[] = $this->applyReleaseUpdate();
            $tab = 'dashboard';
        }
        if ($action === 'cleanup_logs') {
            $flash[] = $this->cleanupLogsNow();
            $tab = 'logs';
        }
        if ($action === 'cleanup_logs_all') {
            $flash[] = $this->cleanupLogsAllNow();
            $tab = 'logs';
        }
        if ($action === 'save_scope') {
            $flash[] = $this->saveScopeSettings();
            $tab = 'scope';
        }
        if ($action === 'save_product_default') {
            $flash[] = $this->saveProductDefault();
            $tab = 'scope';
        }
        if ($action === 'save_product_plan') {
            $flash[] = $this->saveProductPlan();
            $tab = 'scope';
        }
        if ($action === 'test_service_item') {
            $flash[] = $this->testServiceItem();
            $tab = 'servers';
        }
        if ($action === 'refresh_servers_cache') {
            $result = $this->refreshServersCacheNow();
            if ($ajax) {
                $this->json($this->buildBatchAjaxPayload($result));
                return;
            }
            $flash[] = $result;
            $tab = 'servers';
        }
        if ($action === 'test_all_services') {
            $result = $this->testAllServices();
            if ($ajax) {
                $this->json($this->buildBatchAjaxPayload($result));
                return;
            }
            if (!($result['silent'] ?? false)) {
                $flash[] = $result;
            }
            $tab = 'servers';
        }
        if ($action === 'reset_test_all_services') {
            $result = $this->resetTestAllServices();
            if ($ajax) {
                $this->json($this->buildBatchAjaxPayload($result));
                return;
            }
            $flash[] = $result;
            $tab = 'servers';
        }
        if ($action === 'stop_test_all_services') {
            $result = $this->stopTestAllServices();
            if ($ajax) {
                $this->json($this->buildBatchAjaxPayload($result));
                return;
            }
            $flash[] = $result;
            $tab = 'servers';
        }

        // Keep admin load non-blocking: avoid automatic outbound checks on every page view.
        $this->settings = new Settings(Settings::loadFromDatabase());
        $version = Version::current($this->moduleDir);

        echo '<link rel="stylesheet" href="../modules/addons/easydcim_bw/assets/admin.css">';
        echo '<div class="edbw-wrap' . ($this->isFa ? ' edbw-fa' : '') . '">';
        echo '<div class="edbw-header">';
        echo '<h2>EasyDcim-BW</h2>';
        echo '<p>' . htmlspecialchars($this->t('subtitle')) . '</p>';
        echo '</div>';

        foreach ($flash as $msg) {
            echo '<div class="alert alert-' . htmlspecialchars($msg['type']) . '">' . htmlspecialchars($msg['text']) . '</div>';
        }
        if (Capsule::table('mod_easydcim_bw_guard_meta')->where('meta_key', 'release_update_available')->value('meta_value') === '1') {
            echo '<div class="alert alert-warning">' . htmlspecialchars($this->t('update_banner')) . '</div>';
        }

        $moduleLink = (string) ($vars['modulelink'] ?? '');
        $this->renderTabs($moduleLink, $tab);

        if ($tab === 'settings') {
            $this->renderSettingsTab();
        } elseif ($tab === 'connection') {
            $this->renderConnectionTab();
        } elseif ($tab === 'scope') {
            $this->renderScopeTab();
        } elseif ($tab === 'health') {
            $this->renderHealthTab();
        } elseif ($tab === 'servers') {
            $this->renderServersTab();
        } elseif ($tab === 'traffic') {
            $this->renderTrafficTab();
        } elseif ($tab === 'packages') {
            $this->renderPackagesTab();
        } elseif ($tab === 'logs') {
            $this->renderLogsTab();
        } else {
            $this->renderDashboardTab($version, $moduleLink);
        }

        echo '</div>';
    }

    private function renderTabs(string $moduleLink, string $activeTab): void
    {
        $tabs = [
            'dashboard' => $this->t('tab_dashboard'),
            'connection' => $this->t('tab_connection'),
            'settings' => $this->t('tab_settings'),
            'scope' => $this->t('tab_scope'),
            'servers' => $this->t('tab_servers'),
            'traffic' => $this->t('tab_traffic'),
            'packages' => $this->t('tab_packages'),
            'health' => $this->t('tab_health'),
            'logs' => $this->t('tab_logs'),
        ];

        echo '<div class="edbw-tabs">';
        foreach ($tabs as $key => $label) {
            $class = $key === $activeTab ? 'edbw-tab active' : 'edbw-tab';
            $url = $moduleLink !== '' ? $moduleLink . '&tab=' . rawurlencode($key) : '?tab=' . rawurlencode($key);
            echo '<a class="' . $class . '" href="' . htmlspecialchars($url) . '">' . htmlspecialchars($label) . '</a>';
        }
        echo '</div>';
    }

    private function renderDashboardTab(array $version, string $moduleLink): void
    {
        $apiFailCount = (int) Capsule::table('mod_easydcim_bw_guard_meta')->where('meta_key', 'api_fail_count')->value('meta_value');
        $updateLock = Capsule::table('mod_easydcim_bw_guard_meta')->where('meta_key', 'update_in_progress')->value('meta_value') === '1';
        $releaseTag = (string) Capsule::table('mod_easydcim_bw_guard_meta')->where('meta_key', 'release_latest_tag')->value('meta_value');
        $releaseAvailable = Capsule::table('mod_easydcim_bw_guard_meta')->where('meta_key', 'release_update_available')->value('meta_value') === '1';
        $releaseSourceStateRaw = (string) Capsule::table('mod_easydcim_bw_guard_meta')->where('meta_key', 'release_source_status')->value('meta_value');
        $releaseSourceState = $releaseSourceStateRaw === 'ok' ? 'ok' : ($releaseSourceStateRaw === 'error' ? 'error' : 'neutral');
        $releaseVersion = ltrim($releaseTag, 'vV');
        $isBehindLatest = $releaseTag !== '' && $this->compareVersion($releaseVersion, (string) $version['module_version']) > 0;
        if ($releaseTag !== '' && $this->compareVersion($releaseVersion, (string) $version['module_version']) <= 0) {
            $releaseAvailable = false;
        }
        $connectionState = $this->getConnectionRuntimeState();

        echo '<div class="edbw-metrics">';
        $this->renderMetricCard($this->t('m_version'), (string) $version['module_version'], 'ok', '<svg viewBox="0 0 24 24"><path d="M12 3l8 4v10l-8 4-8-4V7l8-4z"></path></svg>');
        $this->renderMetricCard(
            $this->t('m_update_source'),
            $this->t('m_update_source_github'),
            $releaseSourceState,
            '<svg viewBox="0 0 24 24"><path d="M12 2a5 5 0 015 5v2h1a4 4 0 014 4v5h-2v-5a2 2 0 00-2-2h-1v2a5 5 0 11-10 0v-2H6a2 2 0 00-2 2v5H2v-5a4 4 0 014-4h1V7a5 5 0 015-5z"></path></svg>'
        );
        $this->renderMetricCard($this->t('m_update_status'), $releaseAvailable ? $this->t('m_update_available') : $this->t('m_uptodate'), $releaseAvailable ? 'error' : 'ok', '<svg viewBox="0 0 24 24"><path d="M12 4v8m0 0l3-3m-3 3L9 9M5 14a7 7 0 1014 0"></path></svg>');
        $apiFailUrl = $this->buildTabUrl('logs', ['logs_only_errors' => '1', 'logs_level' => 'ERROR']);
        $this->renderMetricCardLink($this->t('m_api_fail_count'), (string) $apiFailCount, $apiFailCount > 0 ? 'error' : 'ok', '<svg viewBox="0 0 24 24"><path d="M12 3l9 18H3zM12 9v4m0 4h.01"></path></svg>', $apiFailUrl);
        $this->renderMetricCard($this->t('m_update_lock'), $updateLock ? $this->t('m_locked') : $this->t('m_free'), $updateLock ? 'warn' : 'ok', '<svg viewBox="0 0 24 24"><path d="M7 11V8a5 5 0 1110 0v3"></path><rect x="5" y="11" width="14" height="10" rx="2"></rect></svg>');
        $this->renderMetricCard($this->t('m_connection'), $connectionState['text'], $connectionState['state'], '<svg viewBox="0 0 24 24"><path d="M4 12a8 8 0 0116 0M8 12a4 4 0 018 0"></path><circle cx="12" cy="16" r="1"></circle></svg>');

        $latestReleaseState = $releaseTag === '' ? 'neutral' : ($isBehindLatest ? 'warn' : 'ok');
        $this->renderMetricCard($this->t('m_latest_release'), $releaseTag !== '' ? $releaseTag : $this->t('m_unknown'), $latestReleaseState, '<svg viewBox="0 0 24 24"><path d="M5 4h14v16H5zM9 8h6M9 12h6M9 16h4"></path></svg>');
        echo '</div>';

        echo '<div class="edbw-panel">';
        echo '<h3>' . htmlspecialchars($this->t('update_actions')) . '</h3>';
        echo '<div class="edbw-actions">';
        echo '<form method="post" class="edbw-form-inline"><input type="hidden" name="tab" value="dashboard"><input type="hidden" name="action" value="check_release_update"><button class="btn btn-default" type="submit">' . htmlspecialchars($this->t('check_update_again')) . '</button></form>';
        echo '<form method="post" class="edbw-form-inline"><input type="hidden" name="tab" value="dashboard"><input type="hidden" name="action" value="apply_release_update"><button class="btn btn-primary" type="submit">' . htmlspecialchars($this->t('apply_latest_release')) . '</button></form>';
        echo '</div>';
        echo '</div>';

    }

    private function renderHealthTab(): void
    {
        $lastPollAt = (string) Capsule::table('mod_easydcim_bw_guard_meta')->where('meta_key', 'last_poll_at')->value('meta_value');
        echo '<div class="edbw-panel">';
        echo '<h3>' . htmlspecialchars($this->t('health_cron_title')) . '</h3>';
        echo '<div class="edbw-metrics">';
        $this->renderMetricCard($this->t('m_cron_poll'), $lastPollAt !== '' ? $lastPollAt : $this->t('m_no_data'), $lastPollAt !== '' ? 'ok' : 'error', '<svg viewBox="0 0 24 24"><path d="M12 6v6l4 2"></path><circle cx="12" cy="12" r="9"></circle></svg>');
        echo '</div>';
        echo '</div>';

        $runtimeCards = $this->buildRuntimeStatus();
        echo '<div class="edbw-panel">';
        echo '<h3>' . htmlspecialchars($this->t('health_runtime_title')) . '</h3>';
        echo '<div class="edbw-metrics">';
        foreach ($runtimeCards as $card) {
            $this->renderMetricCard($card['label'], $card['value'], $card['state'], $card['icon']);
        }
        echo '</div>';
        echo '</div>';

        $this->renderPreflightPanel();
        $this->renderImportantWarningsPanel();
    }

    private function renderMetricCard(string $title, string $value, string $state, string $iconSvg): void
    {
        echo '<div class="edbw-card edbw-state-' . htmlspecialchars($state) . '">';
        echo '<div class="edbw-card-icon">' . $iconSvg . '</div>';
        echo '<div class="edbw-card-body">';
        echo '<div class="edbw-card-title">' . htmlspecialchars($title) . '</div>';
        echo '<div class="edbw-card-value">' . htmlspecialchars($value) . '</div>';
        echo '</div>';
        echo '</div>';
    }

    private function renderMetricCardLink(string $title, string $value, string $state, string $iconSvg, string $url): void
    {
        echo '<a class="edbw-card-link" href="' . htmlspecialchars($url) . '">';
        $this->renderMetricCard($title, $value, $state, $iconSvg);
        echo '</a>';
    }

    private function renderSettingsTab(): void
    {
        $s = $this->settings;
        echo '<div class="edbw-panel">';
        echo '<h3>' . htmlspecialchars($this->t('module_settings')) . '</h3>';
        echo '<form method="post" class="edbw-settings-grid">';
        echo '<input type="hidden" name="tab" value="settings">';
        echo '<input type="hidden" name="action" value="save_settings">';
        echo '<div class="edbw-form-inline"><label>' . htmlspecialchars($this->t('module_status')) . '</label><select name="module_enabled"><option value="1"' . ((string) $s->getString('module_enabled', '1') === '1' ? ' selected' : '') . '>' . htmlspecialchars($this->t('active')) . '</option><option value="0"' . ((string) $s->getString('module_enabled', '1') === '0' ? ' selected' : '') . '>' . htmlspecialchars($this->t('disabled')) . '</option></select><span class="edbw-help">' . htmlspecialchars($this->t('module_status_help')) . '</span></div>';
        echo '<div class="edbw-form-inline"><label>' . htmlspecialchars($this->t('ui_language')) . '</label><select name="ui_language"><option value="auto"' . ($s->getString('ui_language', 'auto') === 'auto' ? ' selected' : '') . '>' . htmlspecialchars($this->t('lang_default')) . '</option><option value="english"' . ($s->getString('ui_language', 'auto') === 'english' ? ' selected' : '') . '>English</option><option value="farsi"' . ($s->getString('ui_language', 'auto') === 'farsi' ? ' selected' : '') . '>فارسی</option></select></div>';
        echo '<div class="edbw-form-inline"><label>' . htmlspecialchars($this->t('poll_interval')) . '</label><input type="number" min="1" name="poll_interval_minutes" value="' . (int) $s->getInt('poll_interval_minutes', 15) . '"></div>';
        echo '<div class="edbw-form-inline"><label>' . htmlspecialchars($this->t('servers_test_chunk_size')) . '</label><input type="number" min="1" max="50" name="servers_test_chunk_size" value="' . (int) $s->getInt('servers_test_chunk_size', 1) . '"><span class="edbw-help">' . htmlspecialchars($this->t('servers_test_chunk_size_help')) . '</span></div>';
        echo '<div class="edbw-form-inline"><label>' . htmlspecialchars($this->t('graph_cache')) . '</label><input type="number" min="5" name="graph_cache_minutes" value="' . (int) $s->getInt('graph_cache_minutes', 30) . '"></div>';

        echo '<div class="edbw-form-inline"><label>' . htmlspecialchars($this->t('autobuy_enabled')) . '</label><input type="checkbox" name="autobuy_enabled" value="1" ' . ($s->getBool('autobuy_enabled') ? 'checked' : '') . '></div>';
        echo '<div class="edbw-form-inline"><label>' . htmlspecialchars($this->t('autobuy_threshold')) . '</label><input type="number" min="1" name="autobuy_threshold_gb" value="' . (int) $s->getInt('autobuy_threshold_gb', 10) . '"></div>';
        echo '<div class="edbw-form-inline"><label>' . htmlspecialchars($this->t('autobuy_package')) . '</label><input type="number" min="0" name="autobuy_default_package_id" value="' . (int) $s->getInt('autobuy_default_package_id', 0) . '"></div>';
        echo '<div class="edbw-form-inline"><label>' . htmlspecialchars($this->t('autobuy_max')) . '</label><input type="number" min="1" name="autobuy_max_per_cycle" value="' . (int) $s->getInt('autobuy_max_per_cycle', 5) . '"></div>';

        echo '<div class="edbw-form-inline"><label>' . htmlspecialchars($this->t('update_mode')) . '</label><select name="update_mode">';
        echo '<option value="notify"' . ($s->getString('update_mode', 'check_oneclick') === 'notify' ? ' selected' : '') . '>' . htmlspecialchars($this->t('update_mode_notify')) . '</option>';
        echo '<option value="check_oneclick"' . ($s->getString('update_mode', 'check_oneclick') === 'check_oneclick' ? ' selected' : '') . '>' . htmlspecialchars($this->t('update_mode_check_oneclick')) . '</option>';
        echo '<option value="auto"' . ($s->getString('update_mode', 'check_oneclick') === 'auto' ? ' selected' : '') . '>' . htmlspecialchars($this->t('update_mode_auto')) . '</option>';
        echo '</select></div>';
        echo '<div class="edbw-form-inline"><label>' . htmlspecialchars($this->t('direction_mapping')) . '</label><select name="traffic_direction_map"><option value="normal"' . ($s->getString('traffic_direction_map', 'normal') === 'normal' ? ' selected' : '') . '>' . htmlspecialchars($this->t('normal')) . '</option><option value="swap"' . ($s->getString('traffic_direction_map', 'normal') === 'swap' ? ' selected' : '') . '>' . htmlspecialchars($this->t('swap_in_out')) . '</option></select><span class="edbw-help">' . htmlspecialchars($this->t('direction_mapping_help')) . '</span></div>';
        echo '<div class="edbw-form-inline"><label>' . htmlspecialchars($this->t('default_calc_mode')) . '</label><select name="default_calculation_mode"><option value="TOTAL"' . ($s->getString('default_calculation_mode', 'TOTAL') === 'TOTAL' ? ' selected' : '') . '>TOTAL (IN+OUT)</option><option value="IN"' . ($s->getString('default_calculation_mode', 'TOTAL') === 'IN' ? ' selected' : '') . '>IN</option><option value="OUT"' . ($s->getString('default_calculation_mode', 'TOTAL') === 'OUT' ? ' selected' : '') . '>OUT</option></select></div>';

        echo '<div class="edbw-form-inline"><label>' . htmlspecialchars($this->t('test_mode')) . '</label><input type="checkbox" name="test_mode" value="1" ' . ($s->getBool('test_mode', false) ? 'checked' : '') . '><span class="edbw-help">' . htmlspecialchars($this->t('test_mode_help')) . '</span></div>';
        echo '<div class="edbw-form-inline"><label>' . htmlspecialchars($this->t('log_retention')) . '</label><input type="number" min="1" name="log_retention_days" value="' . (int) $s->getInt('log_retention_days', 30) . '"></div>';
        echo '<div class="edbw-form-inline"><label>' . htmlspecialchars($this->t('preflight_strict')) . '</label><input type="checkbox" name="preflight_strict_mode" value="1" ' . ($s->getBool('preflight_strict_mode', true) ? 'checked' : '') . '></div>';
        echo '<div class="edbw-form-inline"><label>' . htmlspecialchars($this->t('purge_on_deactivate')) . '</label><input type="checkbox" name="purge_on_deactivate" value="1" ' . ($s->getBool('purge_on_deactivate', false) ? 'checked' : '') . '><span class="edbw-help">' . htmlspecialchars($this->t('purge_on_deactivate_help')) . '</span></div>';

        echo '<button class="btn btn-primary" type="submit">' . htmlspecialchars($this->t('save_settings')) . '</button>';
        echo '</form>';
        echo '</div>';
    }

    private function renderConnectionTab(): void
    {
        $s = $this->settings;
        $tokenMasked = $s->getString('easydcim_api_token') !== '' ? '****************' : $this->t('keep_secret');
        echo '<div class="edbw-panel">';
        echo '<h3>' . htmlspecialchars($this->t('easy_connection')) . '</h3>';
        echo '<form method="post" class="edbw-settings-grid">';
        echo '<input type="hidden" name="tab" value="connection">';
        echo '<div class="edbw-form-inline"><label>' . htmlspecialchars($this->t('base_url')) . '</label><input type="text" name="easydcim_base_url" value="' . htmlspecialchars($s->getString('easydcim_base_url')) . '" size="70"></div>';
        echo '<div class="edbw-form-inline"><label>' . htmlspecialchars($this->t('api_token')) . '</label><input type="password" name="easydcim_api_token" value="" placeholder="' . htmlspecialchars($tokenMasked) . '" size="70"></div>';
        echo '<div class="edbw-form-inline"><label>' . htmlspecialchars($this->t('access_mode')) . '</label><select name="use_impersonation"><option value="0"' . ($s->getBool('use_impersonation', false) ? '' : ' selected') . '>' . htmlspecialchars($this->t('restricted_mode')) . '</option><option value="1"' . ($s->getBool('use_impersonation', false) ? ' selected' : '') . '>' . htmlspecialchars($this->t('unrestricted_mode')) . '</option></select></div>';
        echo '<div class="edbw-form-inline"><label>' . htmlspecialchars($this->t('allow_self_signed')) . '</label><input type="checkbox" name="allow_self_signed" value="1" ' . ($s->getBool('allow_self_signed', true) ? 'checked' : '') . '><span class="edbw-help">' . htmlspecialchars($this->t('allow_self_signed_help')) . '</span></div>';
        echo '<h4>' . htmlspecialchars($this->t('proxy_title')) . '</h4>';
        echo '<div class="edbw-form-inline"><label>' . htmlspecialchars($this->t('proxy_enable')) . '</label><input id="edbw-proxy-enabled" type="checkbox" name="proxy_enabled" value="1" ' . ($s->getBool('proxy_enabled', false) ? 'checked' : '') . '></div>';
        echo '<div class="edbw-form-inline"><label>' . htmlspecialchars($this->t('proxy_type')) . '</label><select class="edbw-proxy-field" name="proxy_type"><option value="http"' . ($s->getString('proxy_type', 'http') === 'http' ? ' selected' : '') . '>HTTP</option><option value="https"' . ($s->getString('proxy_type', 'http') === 'https' ? ' selected' : '') . '>HTTPS</option><option value="socks5"' . ($s->getString('proxy_type', 'http') === 'socks5' ? ' selected' : '') . '>SOCKS5</option><option value="socks4"' . ($s->getString('proxy_type', 'http') === 'socks4' ? ' selected' : '') . '>SOCKS4</option></select></div>';
        echo '<div class="edbw-form-inline"><label>' . htmlspecialchars($this->t('proxy_host')) . '</label><input class="edbw-proxy-field" type="text" name="proxy_host" value="' . htmlspecialchars($s->getString('proxy_host')) . '"></div>';
        echo '<div class="edbw-form-inline"><label>' . htmlspecialchars($this->t('proxy_port')) . '</label><input id="edbw-proxy-port" class="edbw-proxy-field" type="number" name="proxy_port" value="' . (int) $s->getInt('proxy_port', 0) . '"></div>';
        echo '<div class="edbw-form-inline"><label>' . htmlspecialchars($this->t('proxy_user')) . '</label><input class="edbw-proxy-field" type="text" name="proxy_username" value="' . htmlspecialchars($s->getString('proxy_username')) . '"></div>';
        echo '<div class="edbw-form-inline"><label>' . htmlspecialchars($this->t('proxy_pass')) . '</label><input class="edbw-proxy-field" type="password" name="proxy_password" value="" placeholder="' . htmlspecialchars($this->t('keep_secret')) . '"></div>';
        echo '<div class="edbw-actions">';
        echo '<button class="btn btn-primary" type="submit" name="action" value="save_connection">' . htmlspecialchars($this->t('save_connection')) . '</button>';
        echo '<button class="btn btn-default" type="submit" name="action" value="test_easydcim">' . htmlspecialchars($this->t('test_connection')) . '</button>';
        echo '</div>';
        echo '<script>(function(){var en=document.getElementById("edbw-proxy-enabled");var fields=document.querySelectorAll(".edbw-proxy-field");var port=document.getElementById("edbw-proxy-port");function sync(){var on=en&&en.checked;fields.forEach(function(f){f.disabled=!on;if(!on){f.classList.add("edbw-disabled-input");}else{f.classList.remove("edbw-disabled-input");}});if(port){if(on){port.setAttribute("min","1");if(port.value==="0"){port.value="";}}else{port.removeAttribute("min");port.value="";}}}if(en){en.addEventListener("change",sync);}sync();})();</script>';
        echo '</form>';
        echo '</div>';
    }

    private function renderPackagesTab(): void
    {
        $editPackageId = (int) ($_GET['edit_pkg'] ?? 0);
        $editPackage = null;
        if ($editPackageId > 0) {
            $editPackage = Capsule::table('mod_easydcim_bw_guard_packages')->where('id', $editPackageId)->first();
        }
        echo '<div class="edbw-panel">';
        echo '<h3>Traffic Packages</h3>';
        echo '<form method="post" class="edbw-form-inline edbw-packages-form">';
        echo '<input type="hidden" name="tab" value="packages">';
        echo '<input type="hidden" name="action" value="' . ($editPackage ? 'update_package' : 'add_package') . '">';
        if ($editPackage) {
            echo '<input type="hidden" name="pkg_id" value="' . (int) $editPackage->id . '">';
        }
        echo '<div class="edbw-packages-field"><label>Package name</label><input type="text" name="pkg_name" placeholder="Package name" value="' . htmlspecialchars((string) ($editPackage->name ?? '')) . '" required></div>';
        echo '<div class="edbw-packages-field"><label>Size GB</label><input type="number" step="0.01" min="0.01" name="pkg_size_gb" placeholder="Size GB" value="' . htmlspecialchars((string) ($editPackage->size_gb ?? '')) . '" required></div>';
        echo '<div class="edbw-packages-field"><label>Price</label><input type="number" step="0.01" min="0" name="pkg_price" placeholder="Price" value="' . htmlspecialchars((string) ($editPackage->price ?? '')) . '" required></div>';
        echo '<div class="edbw-packages-field"><label>Active</label><select name="pkg_active"><option value="1"' . (((int) ($editPackage->is_active ?? 1)) === 1 ? ' selected' : '') . '>Yes</option><option value="0"' . (((int) ($editPackage->is_active ?? 1)) === 0 ? ' selected' : '') . '>No</option></select></div>';
        echo '<div class="edbw-packages-action"><button class="btn btn-default" type="submit">' . ($editPackage ? 'Save Package' : 'Add Package') . '</button></div>';
        if ($editPackage) {
            echo '<div class="edbw-packages-action"><a class="btn btn-default" href="' . htmlspecialchars($this->buildTabUrl('packages')) . '">Cancel Edit</a></div>';
        }
        echo '</form>';
        $packages = Capsule::table('mod_easydcim_bw_guard_packages')->orderBy('id')->limit(100)->get();
        echo '<div class="edbw-table-wrap">';
        echo '<table class="table table-striped edbw-table-center edbw-packages-table"><thead><tr><th>ID</th><th>Name</th><th>Size GB</th><th>Price</th><th>Active</th><th>Action</th></tr></thead><tbody>';
        foreach ($packages as $pkg) {
            echo '<tr>';
            echo '<td>' . (int) $pkg->id . '</td>';
            echo '<td>' . htmlspecialchars((string) $pkg->name) . '</td>';
            echo '<td>' . htmlspecialchars((string) $pkg->size_gb) . '</td>';
            echo '<td>' . htmlspecialchars((string) $pkg->price) . '</td>';
            echo '<td>' . ((int) $pkg->is_active === 1 ? 'Yes' : 'No') . '</td>';
            echo '<td><div class="edbw-packages-actions">';
            echo '<a class="btn btn-default btn-xs" href="' . htmlspecialchars($this->buildTabUrl('packages', ['edit_pkg' => (string) ((int) $pkg->id)])) . '">Edit</a>';
            echo '<form method="post" class="edbw-test-form" onsubmit="return confirm(\'Delete this package?\')">';
            echo '<input type="hidden" name="tab" value="packages">';
            echo '<input type="hidden" name="action" value="delete_package">';
            echo '<input type="hidden" name="delete_pkg_id" value="' . (int) $pkg->id . '">';
            echo '<button type="submit" class="btn btn-danger btn-xs">Delete</button>';
            echo '</form>';
            echo '</div></td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
        echo '</div>';
        echo '</div>';

        echo '<div class="edbw-panel">';
        echo '<h3>Service Overrides (Permanent)</h3>';
        echo '<form method="post" class="edbw-form-inline">';
        echo '<input type="hidden" name="tab" value="packages">';
        echo '<input type="hidden" name="action" value="save_override">';
        echo '<input type="number" min="1" name="ov_serviceid" placeholder="WHMCS Service ID" required>';
        echo '<input type="number" step="0.01" min="0" name="ov_quota_gb" placeholder="Base Quota GB">';
        echo '<select name="ov_mode"><option value="">Mode</option><option value="IN">IN</option><option value="OUT">OUT</option><option value="TOTAL">TOTAL</option></select>';
        echo '<select name="ov_action"><option value="">Action</option><option value="disable_ports">Disable Ports</option><option value="suspend">Suspend</option><option value="both">Both</option></select>';
        echo '<button class="btn btn-default" type="submit">Save Override</button>';
        echo '</form>';

        $overrides = Capsule::table('mod_easydcim_bw_guard_service_overrides')->orderByDesc('id')->limit(200)->get();
        echo '<div class="edbw-table-wrap">';
        echo '<table class="table table-striped"><thead><tr><th>Service</th><th>Quota GB</th><th>Mode</th><th>Action</th><th>Updated</th></tr></thead><tbody>';
        foreach ($overrides as $ov) {
            echo '<tr>';
            echo '<td>' . (int) $ov->serviceid . '</td>';
            echo '<td>' . htmlspecialchars((string) $ov->override_base_quota_gb) . '</td>';
            echo '<td>' . htmlspecialchars((string) $ov->override_mode) . '</td>';
            echo '<td>' . htmlspecialchars((string) $ov->override_action) . '</td>';
            echo '<td>' . htmlspecialchars((string) $ov->updated_at) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
        echo '</div>';
        echo '</div>';
    }

    private function renderScopeTab(): void
    {
        $s = $this->settings;
        $scopedProducts = $this->getScopedProducts();
        echo '<div class="edbw-panel">';
        echo '<h3>' . htmlspecialchars($this->t('managed_scope')) . '</h3>';
        echo '<form method="post" class="edbw-settings-grid">';
        echo '<input type="hidden" name="tab" value="scope">';
        echo '<input type="hidden" name="action" value="save_scope">';
        echo '<div class="edbw-form-inline"><label>' . htmlspecialchars($this->t('managed_pids')) . '</label><input type="text" name="managed_pids" value="' . htmlspecialchars($s->getString('managed_pids')) . '" size="70"><span class="edbw-help">' . htmlspecialchars($this->t('comma_pids')) . '</span></div>';
        echo '<div class="edbw-form-inline"><label>' . htmlspecialchars($this->t('managed_gids')) . '</label><input type="text" name="managed_gids" value="' . htmlspecialchars($s->getString('managed_gids')) . '" size="70"><span class="edbw-help">' . htmlspecialchars($this->t('comma_gids')) . '</span></div>';
        echo '<button class="btn btn-primary" type="submit">' . htmlspecialchars($this->t('save_scope')) . '</button>';
        echo '</form>';
        echo '<p class="edbw-help">' . htmlspecialchars($this->t('loaded_products')) . ': ' . count($scopedProducts) . '</p>';
        echo '</div>';

        echo '<div class="edbw-panel">';
        echo '<h3>' . htmlspecialchars($this->t('plan_quotas')) . '</h3>';
        echo '<p class="edbw-help">' . htmlspecialchars($this->t('plan_quotas_help')) . '</p>';
        echo '<div class="edbw-table-wrap">';
        echo '<table class="table table-striped"><thead><tr><th>PID</th><th>' . htmlspecialchars($this->t('product')) . '</th><th>GID</th><th>' . htmlspecialchars($this->t('cf_check')) . '</th><th>' . htmlspecialchars($this->t('in_label')) . ' GB</th><th>' . htmlspecialchars($this->t('out_label')) . ' GB</th><th>' . htmlspecialchars($this->t('total_label')) . ' GB</th><th>' . htmlspecialchars($this->t('unlimited_label')) . '</th><th>' . htmlspecialchars($this->t('action')) . '</th></tr></thead><tbody>';
        foreach ($scopedProducts as $row) {
            echo '<tr class="edbw-auto-plan" data-pid="' . (int) $row['pid'] . '">';
            echo '<td>' . (int) $row['pid'] . '</td>';
            echo '<td>' . htmlspecialchars((string) $row['name']) . '</td>';
            echo '<td>' . (int) $row['gid'] . '</td>';
            $cfStatus = (($row['cf_service'] ? 'S' : '-') . '/' . ($row['cf_order'] ? 'O' : '-') . '/' . ($row['cf_server'] ? 'V' : '-'));
            echo '<td>' . htmlspecialchars($cfStatus) . '</td>';
            echo '<td><input type="number" step="0.01" min="0" name="pd_quota_in_gb" value="' . htmlspecialchars((string) $row['quota_in']) . '"' . ($row['unlimited_in'] ? ' disabled class="edbw-disabled-input"' : '') . '></td>';
            echo '<td><input type="number" step="0.01" min="0" name="pd_quota_out_gb" value="' . htmlspecialchars((string) $row['quota_out']) . '"' . ($row['unlimited_out'] ? ' disabled class="edbw-disabled-input"' : '') . '></td>';
            echo '<td><input type="number" step="0.01" min="0" name="pd_quota_total_gb" value="' . htmlspecialchars((string) $row['quota_total']) . '"' . ($row['unlimited_total'] ? ' disabled class="edbw-disabled-input"' : '') . '></td>';
            echo '<td>';
            echo '<label>' . htmlspecialchars($this->t('in_label')) . ' <input type="checkbox" class="edbw-limit-toggle" data-target="pd_quota_in_gb" name="pd_unlimited_in" value="1" ' . ($row['unlimited_in'] ? 'checked' : '') . '></label> ';
            echo '<label>' . htmlspecialchars($this->t('out_label')) . ' <input type="checkbox" class="edbw-limit-toggle" data-target="pd_quota_out_gb" name="pd_unlimited_out" value="1" ' . ($row['unlimited_out'] ? 'checked' : '') . '></label> ';
            echo '<label>' . htmlspecialchars($this->t('total_label')) . ' <input type="checkbox" class="edbw-limit-toggle" data-target="pd_quota_total_gb" name="pd_unlimited_total" value="1" ' . ($row['unlimited_total'] ? 'checked' : '') . '></label>';
            echo '</td>';
            echo '<td><select name="pd_action"><option value="disable_ports"' . ($row['action'] === 'disable_ports' ? ' selected' : '') . '>' . htmlspecialchars($this->t('disable_ports')) . '</option><option value="suspend"' . ($row['action'] === 'suspend' ? ' selected' : '') . '>' . htmlspecialchars($this->t('suspend')) . '</option><option value="both"' . ($row['action'] === 'both' ? ' selected' : '') . '>' . htmlspecialchars($this->t('both')) . '</option></select></td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
        echo '</div>';
        echo '<div class="edbw-actions"><button type="button" id="edbw-save-all" class="btn btn-primary">' . htmlspecialchars($this->t('save_all_plans')) . '</button><span id="edbw-save-note" class="edbw-help"></span></div>';
        echo '<script>(function(){'
            . 'var rows=document.querySelectorAll(".edbw-auto-plan");'
            . 'var note=document.getElementById("edbw-save-note");'
            . 'var apiUrl=window.location.pathname+(window.location.search||"");'
            . 'function applyToggles(r){r.querySelectorAll(".edbw-limit-toggle").forEach(function(c){var target=r.querySelector("[name=\\"" + c.getAttribute("data-target") + "\\"]");if(target){target.disabled=c.checked;if(c.checked){target.value="";target.classList.add("edbw-disabled-input");}else{target.classList.remove("edbw-disabled-input");}}});}'
            . 'function rowData(r){var fd=new FormData();fd.append("tab","scope");fd.append("action","save_product_plan");fd.append("pd_pid",r.getAttribute("data-pid")||"0");r.querySelectorAll("input,select").forEach(function(el){if(!el.name){return;}if(el.type==="checkbox"){if(el.checked){fd.append(el.name,"1");}return;}fd.append(el.name,el.value||"");});return fd;}'
            . 'function postRow(r){var fd=rowData(r);fd.append("ajax","1");'
            . 'fetch(apiUrl,{method:"POST",body:fd,credentials:"same-origin",headers:{"X-Requested-With":"XMLHttpRequest"}})'
            . '.then(function(r){return r.text();}).then(function(txt){var j=null;try{j=JSON.parse(txt);}catch(_){j=null;}if(j){note.textContent=(j.text||"' . addslashes($this->t('saved')) . '");note.style.color=(j.type==="success"?"#047857":"#b91c1c");return;}note.textContent="' . addslashes($this->t('saved')) . '";note.style.color="#047857";})'
            . '.catch(function(){note.textContent="' . addslashes($this->t('save_failed')) . '";note.style.color="#b91c1c";});}'
            . 'rows.forEach(function(r){applyToggles(r);var t;var onEdit=function(){applyToggles(r);clearTimeout(t);t=setTimeout(function(){postRow(r);},350);};r.querySelectorAll("input,select").forEach(function(el){el.addEventListener("change",onEdit);if(el.tagName==="INPUT" && el.type!=="checkbox"){el.addEventListener("input",onEdit);}});});'
            . 'var saveAll=document.getElementById("edbw-save-all");if(saveAll){saveAll.addEventListener("click",function(){var i=0;function next(){if(i>=rows.length){note.textContent="' . addslashes($this->t('all_rows_saved')) . '";note.style.color="#047857";return;}postRow(rows[i]);i++;setTimeout(next,120);}next();});}'
            . '})();</script>';
        echo '</div>';
    }

    private function renderServersTab(): void
    {
        $baseUrl = $this->settings->getString('easydcim_base_url');
        $token = Crypto::safeDecrypt($this->settings->getString('easydcim_api_token'));
        $apiAvailable = $baseUrl !== '' && $token !== '';
        $easyServices = $this->getEasyServicesCacheOnly();
        $services = $this->getScopedHostingServices($easyServices, false, false);
        $search = trim((string) ($_REQUEST['servers_q'] ?? ''));
        $serversPerPage = $this->getPerPage('servers_per_page', 10);
        $assignedPage = $this->getPage('servers_page_assigned');
        $unassignedPage = $this->getPage('servers_page_unassigned');

        $explicitMappedServiceIds = $this->getExplicitMappedEasyServiceIdsForScope();
        $unassigned = $this->buildUnassignedEasyServices($easyServices, $explicitMappedServiceIds);
        $easyByService = [];
        $easyByOrder = [];
        $easyByServer = [];
        $easyByIp = [];
        foreach ($easyServices as $item) {
            if (!is_array($item)) {
                continue;
            }
            $sid = trim((string) ($item['service_id'] ?? ''));
            $ord = trim((string) ($item['order_id'] ?? ''));
            $srv = trim((string) ($item['server_id'] ?? ''));
            $ip = trim((string) ($item['ip'] ?? ''));
            if ($sid !== '' && !isset($easyByService[$sid])) {
                $easyByService[$sid] = $item;
            }
            if ($ord !== '' && !isset($easyByOrder[$ord])) {
                $easyByOrder[$ord] = $item;
            }
            if ($srv !== '' && !isset($easyByServer[$srv])) {
                $easyByServer[$srv] = $item;
            }
            if ($ip !== '' && !isset($easyByIp[$ip])) {
                $easyByIp[$ip] = $item;
            }
        }
        if ($search !== '') {
            $services = array_values(array_filter($services, function (array $svc) use ($search, $easyByService, $easyByOrder, $easyByServer, $easyByIp): bool {
                $easyItem = [];
                $sid = trim((string) ($svc['easydcim_service_id'] ?? ''));
                $ord = trim((string) ($svc['easydcim_order_id'] ?? ''));
                $srv = trim((string) ($svc['easydcim_server_id'] ?? ''));
                $ip = trim((string) ($svc['ip'] ?? ''));
                if ($sid !== '' && isset($easyByService[$sid])) {
                    $easyItem = $easyByService[$sid];
                } elseif ($ord !== '' && isset($easyByOrder[$ord])) {
                    $easyItem = $easyByOrder[$ord];
                } elseif ($srv !== '' && isset($easyByServer[$srv])) {
                    $easyItem = $easyByServer[$srv];
                } elseif ($ip !== '' && isset($easyByIp[$ip])) {
                    $easyItem = $easyByIp[$ip];
                }
                return $this->matchesSearchTerm([
                    (string) ($svc['serviceid'] ?? ''),
                    (string) ($svc['userid'] ?? ''),
                    (string) ($svc['pid'] ?? ''),
                    (string) ($svc['domainstatus'] ?? ''),
                    (string) ($svc['domain'] ?? ''),
                    (string) ($svc['client_name'] ?? ''),
                    (string) ($svc['firstname'] ?? ''),
                    (string) ($svc['lastname'] ?? ''),
                    (string) ($svc['email'] ?? ''),
                    (string) ($svc['ip'] ?? ''),
                    (string) ($svc['easydcim_service_id'] ?? ''),
                    (string) ($svc['easydcim_server_id'] ?? ''),
                    (string) ($svc['easydcim_order_id'] ?? ''),
                    (string) ($easyItem['label'] ?? ''),
                    (string) ($easyItem['ilo_ip'] ?? ''),
                    (string) ($easyItem['client_name'] ?? ''),
                    (string) ($easyItem['client_email'] ?? ''),
                ], $search);
            }));
            $unassigned = array_values(array_filter($unassigned, function (array $item) use ($search): bool {
                return $this->matchesSearchTerm([
                    (string) ($item['service_id'] ?? ''),
                    (string) ($item['server_id'] ?? ''),
                    (string) ($item['order_id'] ?? ''),
                    (string) ($item['ip'] ?? ''),
                    (string) ($item['ilo_ip'] ?? ''),
                    (string) ($item['label'] ?? ''),
                    (string) ($item['status'] ?? ''),
                    (string) ($item['client_name'] ?? ''),
                    (string) ($item['client_email'] ?? ''),
                ], $search);
            }));
        }

        echo '<div class="edbw-panel">';
        echo '<h3>' . htmlspecialchars($this->t('servers_tab_title')) . '</h3>';
        if (!$apiAvailable) {
            echo '<div class="alert alert-warning">' . htmlspecialchars($this->t('servers_api_missing')) . '</div>';
        } else {
            echo '<p class="edbw-help">' . htmlspecialchars($this->t('servers_api_loaded')) . ': ' . count($easyServices) . '</p>';
            $cacheAt = (string) Capsule::table('mod_easydcim_bw_guard_meta')->where('meta_key', 'servers_list_cache_at')->value('meta_value');
            echo '<p class="edbw-help">' . htmlspecialchars($this->t('servers_cache_at')) . ': ' . htmlspecialchars($cacheAt !== '' ? $cacheAt : $this->t('m_no_data')) . '</p>';
            echo '<div class="edbw-server-actions">';
            echo '<form method="post" class="edbw-form-inline edbw-action-card">';
            echo '<input type="hidden" name="tab" value="servers">';
            echo '<input type="hidden" name="action" value="refresh_servers_cache">';
            echo '<button class="btn btn-default" type="submit">' . htmlspecialchars($this->t('servers_refresh_cache')) . '</button>';
            echo '</form>';
            $testAllState = $this->getTestAllState();
            $runningNow = ((int) ($testAllState['remaining'] ?? 0) > 0)
                || ((int) ($testAllState['total'] ?? 0) > (int) ($testAllState['done'] ?? 0));
            if ($runningNow) {
                echo '<form method="post" class="edbw-form-inline edbw-action-card">';
                echo '<input type="hidden" name="tab" value="servers">';
                echo '<input type="hidden" name="action" value="test_all_services">';
                echo '<button class="btn btn-primary" type="submit">' . htmlspecialchars($this->t('servers_test_all_continue')) . '</button>';
                echo '</form>';
                echo '<form method="post" class="edbw-form-inline edbw-action-card">';
                echo '<input type="hidden" name="tab" value="servers">';
                echo '<input type="hidden" name="action" value="stop_test_all_services">';
                echo '<button class="btn btn-default" type="submit">' . htmlspecialchars($this->t('servers_test_all_stop')) . '</button>';
                echo '</form>';
                echo '<form method="post" class="edbw-form-inline edbw-action-card">';
                echo '<input type="hidden" name="tab" value="servers">';
                echo '<input type="hidden" name="action" value="reset_test_all_services">';
                echo '<button class="btn btn-default" type="submit">' . htmlspecialchars($this->t('servers_test_all_reset')) . '</button>';
                echo '</form>';
            } else {
                echo '<form method="post" class="edbw-form-inline edbw-action-card">';
                echo '<input type="hidden" name="tab" value="servers">';
                echo '<input type="hidden" name="action" value="test_all_services">';
                echo '<button class="btn btn-default" type="submit">' . htmlspecialchars($this->t('servers_test_all')) . '</button>';
                echo '</form>';
            }
            echo '</div>';
            if ($runningNow) {
                $elapsedSeconds = $this->resolveBatchElapsedSeconds($testAllState);
                echo '<div class="alert alert-info">';
                echo htmlspecialchars($this->t('servers_test_all_progress')) . ': '
                    . (int) ($testAllState['done'] ?? 0) . '/' . (int) ($testAllState['total'] ?? 0)
                    . ' (OK: ' . (int) ($testAllState['ok'] ?? 0)
                    . ', WARN: ' . (int) ($testAllState['warn'] ?? 0)
                    . ', FAIL: ' . (int) ($testAllState['fail'] ?? 0) . ')'
                    . ' | ' . htmlspecialchars($this->t('servers_test_all_elapsed')) . ': ' . htmlspecialchars($this->formatDuration($elapsedSeconds));
                echo '</div>';
            }
            $lastDuration = (int) Capsule::table('mod_easydcim_bw_guard_meta')->where('meta_key', 'servers_test_all_last_duration_seconds')->value('meta_value');
            $lastFinishedAt = (string) Capsule::table('mod_easydcim_bw_guard_meta')->where('meta_key', 'servers_test_all_last_finished_at')->value('meta_value');
            if ($lastDuration > 0 || $lastFinishedAt !== '') {
                echo '<div class="alert alert-success">';
                echo htmlspecialchars($this->t('servers_test_all_last_run')) . ': ';
                if ($lastFinishedAt !== '') {
                    echo htmlspecialchars($lastFinishedAt) . ' | ';
                }
                echo htmlspecialchars($this->t('servers_test_all_total_time')) . ': ' . htmlspecialchars($this->formatDuration($lastDuration));
                echo '</div>';
            }
            if (count($easyServices) === 0 && $cacheAt === '') {
                echo '<div class="alert alert-warning">' . htmlspecialchars($this->t('servers_cache_empty_hint')) . '</div>';
            } elseif (count($easyServices) === 0) {
                echo '<div class="alert alert-warning">' . htmlspecialchars($this->t('servers_api_empty_hint')) . '</div>';
            }
        }
        echo '<form method="get" class="edbw-form-inline edbw-search-form" action="' . htmlspecialchars($this->buildTabUrl('servers', ['servers_q' => '', 'traffic_q' => '', 'focus_serviceid' => ''])) . '">';
        echo '<label>' . htmlspecialchars($this->t('search')) . '</label>';
        echo '<input type="text" name="servers_q" value="' . htmlspecialchars($search) . '" placeholder="' . htmlspecialchars($this->t('servers_search_placeholder')) . '">';
        echo '<label>' . htmlspecialchars($this->t('rows_per_page')) . '</label>';
        echo '<select name="servers_per_page">';
        foreach ([10, 25, 50, 100] as $size) {
            echo '<option value="' . $size . '"' . ($serversPerPage === $size ? ' selected' : '') . '>' . $size . '</option>';
        }
        echo '</select>';
        echo '<button class="btn btn-default" type="submit">' . htmlspecialchars($this->t('search')) . '</button>';
        if ($search !== '') {
            echo '<a class="btn btn-default" href="' . htmlspecialchars($this->buildTabUrl('servers', ['servers_q' => '', 'traffic_q' => '', 'focus_serviceid' => ''])) . '">' . htmlspecialchars($this->t('clear_search')) . '</a>';
        }
        echo '</form>';
        echo '</div>';

        $servicesTotal = count($services);
        $servicesPages = max(1, (int) ceil($servicesTotal / $serversPerPage));
        $assignedPage = min(max(1, $assignedPage), $servicesPages);
        $servicesForPage = array_slice($services, ($assignedPage - 1) * $serversPerPage, $serversPerPage);
        $resolverClient = null;
        if ($apiAvailable) {
            $resolverClient = new EasyDcimClient(
                $baseUrl,
                $token,
                $this->settings->getBool('use_impersonation', false),
                $this->logger,
                $this->proxyConfig()
            );
        }
        $defaultsByPid = [];
        $pidList = array_values(array_unique(array_filter(array_map(static fn (array $row): int => (int) ($row['pid'] ?? 0), $servicesForPage))));
        if (!empty($pidList)) {
            $defaultsByPid = Capsule::table('mod_easydcim_bw_guard_product_defaults')
                ->whereIn('pid', $pidList)
                ->get()
                ->keyBy('pid')
                ->all();
        }
        $unassignedTotal = count($unassigned);
        $unassignedPages = max(1, (int) ceil($unassignedTotal / $serversPerPage));
        $unassignedPage = min(max(1, $unassignedPage), $unassignedPages);
        $unassignedForPage = array_slice($unassigned, ($unassignedPage - 1) * $serversPerPage, $serversPerPage);

        echo '<div class="edbw-panel">';
        echo '<h3>' . htmlspecialchars($this->t('servers_assigned')) . '</h3>';
        echo '<p class="edbw-help">' . htmlspecialchars($this->t('servers_traffic_hint')) . '</p>';
        echo '<div class="edbw-table-wrap"><table class="table table-striped edbw-table-center"><thead><tr><th>' . htmlspecialchars($this->t('service')) . '</th><th>' . htmlspecialchars($this->t('client')) . '</th><th>' . htmlspecialchars($this->t('connected_switches')) . '</th><th>' . htmlspecialchars($this->t('ports_status')) . '</th><th>' . htmlspecialchars($this->t('status')) . '</th><th>' . htmlspecialchars($this->t('details')) . '</th><th>' . htmlspecialchars($this->t('test')) . '</th></tr></thead><tbody>';
        foreach ($servicesForPage as $svc) {
            $testCache = $this->getServiceTestCache((int) $svc['serviceid']);
            $portsHtml = $this->renderPortStatusHtml($svc, $testCache);
            $lastCheck = trim((string) ($svc['last_check_at'] ?? ''));
            $cycleStart = trim((string) ($svc['cycle_start'] ?? ''));
            $cycleEnd = trim((string) ($svc['cycle_end'] ?? ''));
            $cycleText = ($cycleStart !== '' && $cycleEnd !== '')
                ? ($cycleStart . ' -> ' . $cycleEnd)
                : $this->t('m_no_data');
            $portLabels = [];
            $switchLabels = [];
            $switchIds = [];
            $speedLabels = [];
            if (is_array($testCache) && isset($testCache['port_rows']) && is_array($testCache['port_rows'])) {
                foreach ($testCache['port_rows'] as $row) {
                    if (!is_array($row)) {
                        continue;
                    }
                    $cp = trim((string) ($row['connected_port_label'] ?? ''));
                    $ci = trim((string) ($row['connected_item_label'] ?? ''));
                    $cpid = trim((string) ($row['connected_port_id'] ?? ''));
                    $ciid = trim((string) ($row['connected_item_id'] ?? ''));
                    $sp = trim((string) ($row['speed'] ?? ''));
                    if (($cp === '' || $this->isSimpleNumericLabel($cp)) && $cpid !== '' && $resolverClient instanceof EasyDcimClient) {
                        $detail = $this->getPortDetailCached($resolverClient, $cpid);
                        $fromDetail = trim((string) ($detail['label'] ?? ''));
                        if ($fromDetail !== '') {
                            $cp = $fromDetail;
                        }
                    }
                    $cp = $this->normalizePortPresentationLabel($cp);
                    $normalizedSwitch = $this->normalizeSwitchDisplayLabel($ci);
                    if ($normalizedSwitch === '' && $ciid !== '' && $resolverClient instanceof EasyDcimClient) {
                        $resolvedSwitch = $this->resolveItemLabelCached($resolverClient, $ciid);
                        if ($resolvedSwitch !== '') {
                            $normalizedSwitch = $resolvedSwitch;
                        }
                    }
                    if ($normalizedSwitch === '' && $ciid !== '') {
                        $normalizedSwitch = 'SW #' . $ciid;
                    }
                    if ($cp !== '' && !in_array($cp, $portLabels, true)) {
                        $portLabels[] = $cp;
                    }
                    if ($normalizedSwitch !== '' && !in_array($normalizedSwitch, $switchLabels, true)) {
                        $switchLabels[] = $normalizedSwitch;
                    }
                    if ($ciid !== '' && !in_array($ciid, $switchIds, true)) {
                        $switchIds[] = $ciid;
                    }
                    if ($sp !== '' && !in_array($sp, $speedLabels, true)) {
                        $speedLabels[] = $sp;
                    }
                }
            }
            $portLabelText = !empty($portLabels) ? implode(' | ', array_slice($portLabels, 0, 3)) : $this->t('m_no_data');
            if (!empty($switchLabels)) {
                $switchLabelText = implode(' | ', array_slice($switchLabels, 0, 3));
            } elseif (!empty($switchIds)) {
                $switchLabelText = implode(' | ', array_map(static fn (string $id): string => 'SW #' . $id, array_slice($switchIds, 0, 3)));
            } else {
                $switchLabelText = $this->t('m_no_data');
            }
            $speedLabelText = !empty($speedLabels) ? implode(' | ', array_slice($speedLabels, 0, 3)) : $this->t('m_no_data');
            $clientHtml = '<a href="' . htmlspecialchars((string) $svc['client_url']) . '">' . htmlspecialchars((string) $svc['client_name']) . '</a>';
            $domain = trim((string) ($svc['domain'] ?? ''));
            $easyItem = [];
            $svcEasyId = trim((string) ($svc['easydcim_service_id'] ?? ''));
            $svcOrderId = trim((string) ($svc['easydcim_order_id'] ?? ''));
            $svcServerId = trim((string) ($svc['easydcim_server_id'] ?? ''));
            $svcIp = trim((string) ($svc['ip'] ?? ''));
            if ($svcEasyId !== '' && isset($easyByService[$svcEasyId])) {
                $easyItem = $easyByService[$svcEasyId];
            } elseif ($svcOrderId !== '' && isset($easyByOrder[$svcOrderId])) {
                $easyItem = $easyByOrder[$svcOrderId];
            } elseif ($svcServerId !== '' && isset($easyByServer[$svcServerId])) {
                $easyItem = $easyByServer[$svcServerId];
            } elseif ($svcIp !== '' && isset($easyByIp[$svcIp])) {
                $easyItem = $easyByIp[$svcIp];
            }
            $publicIp = trim((string) ($svc['ip'] ?? ''));
            $easyIp = trim((string) ($easyItem['ip'] ?? ''));
            $iloIp = trim((string) ($easyItem['ilo_ip'] ?? ''));
            $labelText = trim((string) ($easyItem['label'] ?? ''));
            $easyHost = trim((string) ($easyItem['hostname'] ?? ''));
            $productId = (int) ($svc['pid'] ?? 0);
            $productName = trim((string) ($svc['product_name'] ?? ''));
            if ($productName === '') {
                $productName = 'PID ' . $productId;
            }
            $mode = strtoupper(trim((string) ($svc['mode'] ?? $this->settings->getString('default_calculation_mode', 'TOTAL'))));
            $defaultPlanHtml = $this->formatDefaultPlanBwBadge($defaultsByPid[$productId] ?? null, $mode);
            $defaultPlanText = trim(strip_tags($defaultPlanHtml));
            if ($switchLabelText === $this->t('m_no_data')) {
                $fallbackSwitch = $this->normalizeSwitchDisplayLabel($labelText);
                if ($fallbackSwitch !== '') {
                    $switchLabelText = $fallbackSwitch;
                }
            }
            $serviceMainLabel = $switchLabelText !== $this->t('m_no_data') ? $switchLabelText : $labelText;
            if ($serviceMainLabel === '') {
                $serviceMainLabel = $domain;
            }
            if ($serviceMainLabel === '') {
                $serviceMainLabel = '#' . (int) ($svc['serviceid'] ?? 0);
            }
            $detailRows = [
                [$this->t('product'), $productName],
                [$this->t('default_plan_bw'), $defaultPlanText !== '' ? $defaultPlanText : '-'],
                ['WHMCS IP', $publicIp !== '' ? $publicIp : '-'],
                ['EasyDCIM IP', $easyIp !== '' ? $easyIp : '-'],
                ['iLO IP', $iloIp !== '' ? $iloIp : '-'],
                ['WHMCS Hostname', $domain !== '' ? $domain : '-'],
                ['EasyDCIM Hostname', $easyHost !== '' ? $easyHost : '-'],
                [$this->t('connected_switches'), $switchLabelText],
                [$this->t('connected_ports'), $portLabelText],
                [$this->t('port_speeds'), $speedLabelText],
                [$this->t('traffic_cycle'), $cycleText],
                [$this->t('traffic_last_check'), $lastCheck !== '' ? $lastCheck : $this->t('m_no_data')],
            ];
            if ($domain !== '' && strcasecmp($domain, $publicIp) !== 0) {
                $clientHtml .= '<div class="edbw-help">' . htmlspecialchars($domain) . '</div>';
            }
            if ($publicIp !== '') {
                $clientHtml .= '<div class="edbw-client-meta"><span class="edbw-client-meta-key">IP</span><span>' . htmlspecialchars($publicIp) . '</span></div>';
            }
            if ($iloIp !== '' && strcasecmp($iloIp, $publicIp) !== 0) {
                $clientHtml .= '<div class="edbw-client-meta"><span class="edbw-client-meta-key">iLO</span><span>' . htmlspecialchars($iloIp) . '</span></div>';
            }
            if ($labelText !== '') {
                $clientHtml .= '<div class="edbw-client-meta"><span class="edbw-client-meta-key">Label</span><span>' . htmlspecialchars($labelText) . '</span></div>';
            }
            $detailPayload = ['title' => $serviceMainLabel, 'rows' => $detailRows];
            $detailPayloadAttr = htmlspecialchars((string) json_encode($detailPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ENT_QUOTES, 'UTF-8');
            $detailHtml = '<button type="button" class="btn btn-default edbw-detail-open" data-edbw-detail="' . $detailPayloadAttr . '">' . htmlspecialchars($this->t('details')) . '</button>';
            $trafficUrl = $this->buildTabUrl('traffic', [
                'traffic_q' => (string) ((int) ($svc['serviceid'] ?? 0)),
                'focus_serviceid' => (string) ((int) ($svc['serviceid'] ?? 0)),
            ]);
            $serviceUrl = (string) ($svc['service_url'] ?? '#');
            $serviceIdText = '#' . (int) ($svc['serviceid'] ?? 0);
            $serviceCellHtml = '<a class="edbw-service-main-link" href="' . htmlspecialchars($serviceUrl) . '">' . htmlspecialchars($serviceIdText) . '</a>'
                . '<div class="edbw-help"><a href="' . htmlspecialchars($trafficUrl) . '">' . htmlspecialchars($this->t('tab_traffic')) . '</a></div>';
            echo '<tr>';
            echo '<td>' . $serviceCellHtml . '</td>';
            echo '<td>' . $clientHtml . '</td>';
            echo '<td>' . htmlspecialchars($switchLabelText) . '</td>';
            echo '<td>' . $portsHtml . '</td>';
            echo '<td>' . htmlspecialchars($this->domainStatusLabel((string) ($svc['domainstatus'] ?? ''))) . '</td>';
            echo '<td>' . $detailHtml . '</td>';
            echo '<td><form method="post" class="edbw-test-form"><input type="hidden" name="tab" value="servers"><input type="hidden" name="action" value="test_service_item"><input type="hidden" name="test_serviceid" value="' . (int) $svc['serviceid'] . '"><button type="submit" class="btn edbw-test-btn">' . htmlspecialchars($this->t('test')) . '</button></form></td>';
            echo '</tr>';
        }
        if (empty($servicesForPage)) {
            echo '<tr><td colspan="7">' . htmlspecialchars($this->t('no_rows')) . '</td></tr>';
        }
        echo '</tbody></table></div>';
        echo '<div id="edbw-detail-drawer" class="edbw-detail-drawer" aria-hidden="true">'
            . '<div class="edbw-detail-backdrop" data-edbw-close="1"></div>'
            . '<div class="edbw-detail-panel">'
            . '<button type="button" class="edbw-detail-close" data-edbw-close="1">&times;</button>'
            . '<h4 class="edbw-detail-title"></h4>'
            . '<dl class="edbw-detail-list edbw-detail-list-drawer"></dl>'
            . '</div></div>';
        echo '<script>(function(){'
            . 'var drawer=document.getElementById("edbw-detail-drawer");'
            . 'if(!drawer){return;}'
            . 'var titleEl=drawer.querySelector(".edbw-detail-title");'
            . 'var listEl=drawer.querySelector(".edbw-detail-list-drawer");'
            . 'function closeDrawer(){drawer.classList.remove("open");drawer.setAttribute("aria-hidden","true");}'
            . 'function openDrawer(payload){if(!payload){return;}'
            . 'titleEl.textContent=payload.title||"Details";'
            . 'listEl.innerHTML="";'
            . 'var rows=Array.isArray(payload.rows)?payload.rows:[];'
            . 'rows.forEach(function(row){if(!Array.isArray(row)||row.length<2){return;}'
            . 'var dt=document.createElement("dt");dt.textContent=String(row[0]||"");'
            . 'var dd=document.createElement("dd");dd.textContent=String(row[1]||"-");'
            . 'listEl.appendChild(dt);listEl.appendChild(dd);});'
            . 'drawer.classList.add("open");drawer.setAttribute("aria-hidden","false");}'
            . 'document.querySelectorAll(".edbw-detail-open").forEach(function(btn){btn.addEventListener("click",function(){'
            . 'var raw=btn.getAttribute("data-edbw-detail")||"";if(!raw){return;}'
            . 'try{openDrawer(JSON.parse(raw));}catch(e){}});});'
            . 'drawer.querySelectorAll("[data-edbw-close=\'1\']").forEach(function(el){el.addEventListener("click",closeDrawer);});'
            . 'document.addEventListener("keydown",function(e){if(e.key==="Escape"){closeDrawer();}});'
            . '})();</script>';
        $this->renderPager('servers', 'servers_page_assigned', $assignedPage, $servicesPages, [
            'servers_q' => $search,
            'servers_per_page' => (string) $serversPerPage,
            'servers_page_unassigned' => (string) $unassignedPage,
        ]);
        echo '</div>';

        echo '<div class="edbw-panel">';
        echo '<h3>' . htmlspecialchars($this->t('servers_unassigned')) . '</h3>';
        echo '<div class="edbw-table-wrap"><table class="table table-striped edbw-table-center"><thead><tr><th>EasyDCIM Service ID</th><th>Server/Device ID</th><th>IP</th><th>iLO IP</th><th>Label</th><th>' . htmlspecialchars($this->t('order_id')) . '</th><th>' . htmlspecialchars($this->t('status')) . '</th></tr></thead><tbody>';
        foreach ($unassignedForPage as $item) {
            echo '<tr>';
            echo '<td>' . htmlspecialchars((string) ($item['service_id'] ?? '-')) . '</td>';
            echo '<td>' . htmlspecialchars((string) ($item['server_id'] ?? '-')) . '</td>';
            echo '<td>' . htmlspecialchars((string) ($item['ip'] ?? '-')) . '</td>';
            echo '<td>' . htmlspecialchars((string) ($item['ilo_ip'] ?? '-')) . '</td>';
            echo '<td>' . htmlspecialchars((string) ($item['label'] ?? '-')) . '</td>';
            echo '<td>' . htmlspecialchars((string) ($item['order_id'] ?? '-')) . '</td>';
            echo '<td>' . htmlspecialchars((string) ($item['status'] ?? '-')) . '</td>';
            echo '</tr>';
        }
        if (empty($unassignedForPage)) {
            echo '<tr><td colspan="7">' . htmlspecialchars($this->t('no_rows')) . '</td></tr>';
        }
        echo '</tbody></table></div>';
        $this->renderPager('servers', 'servers_page_unassigned', $unassignedPage, $unassignedPages, [
            'servers_q' => $search,
            'servers_per_page' => (string) $serversPerPage,
            'servers_page_assigned' => (string) $assignedPage,
        ]);
        echo '</div>';
    }

    private function getEasyServicesCacheOnly(): array
    {
        $cacheJson = (string) Capsule::table('mod_easydcim_bw_guard_meta')->where('meta_key', 'servers_list_cache_json')->value('meta_value');
        if ($cacheJson === '') {
            return [];
        }
        $decoded = json_decode($cacheJson, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function buildUnassignedEasyServices(array $easyServices, array $explicitMappedServiceIds): array
    {
        return array_values(array_filter($easyServices, static function (array $item) use ($explicitMappedServiceIds): bool {
            $serviceId = trim((string) ($item['service_id'] ?? ''));
            $serverId = trim((string) ($item['server_id'] ?? ''));
            $ip = trim((string) ($item['ip'] ?? ''));
            $iloIp = trim((string) ($item['ilo_ip'] ?? ''));
            $label = trim((string) ($item['label'] ?? ''));
            $orderId = trim((string) ($item['order_id'] ?? ''));
            $status = strtolower(trim((string) ($item['status'] ?? '')));
            if ($serviceId === '' && $serverId === '' && $ip === '' && $orderId === '' && $iloIp === '' && $label === '') {
                return false;
            }
            if ($serviceId !== '' && isset($explicitMappedServiceIds[$serviceId])) {
                return false;
            }
            if (in_array($status, ['cancelled', 'canceled', 'fraud', 'terminated'], true)) {
                return false;
            }
            return true;
        }));
    }

    private function buildTabUrl(string $tab, array $extra = []): string
    {
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '');
        $parts = $uri !== '' ? (parse_url($uri) ?: []) : [];
        $path = (string) ($parts['path'] ?? '');
        if ($path === '' || stripos($path, 'addonmodules.php') === false) {
            $baseDir = trim((string) dirname($path), '/\\');
            $path = ($baseDir !== '' && $baseDir !== '.') ? ('/' . $baseDir . '/addonmodules.php') : 'addonmodules.php';
        }

        $query = [];
        if (!empty($parts['query'])) {
            parse_str((string) $parts['query'], $query);
        } elseif (!empty($_GET) && is_array($_GET)) {
            $query = $_GET;
        }

        unset(
            $query['action'],
            $query['api'],
            $query['op'],
            $query['ajax'],
            $query['auto'],
            $query['tab'],
            $query['search'],
            $query['q'],
            $query['rp']
        );
        $query['module'] = 'easydcim_bw';
        $query['tab'] = $tab;
        foreach ($extra as $k => $v) {
            if ($v === '' || $v === null) {
                unset($query[$k]);
                continue;
            }
            $query[(string) $k] = (string) $v;
        }
        return $path . '?' . http_build_query($query);
    }

    private function matchesSearchTerm(array $values, string $term): bool
    {
        $needle = $this->normalizeSearchText($term);
        if ($needle === '') {
            return true;
        }
        foreach ($values as $value) {
            $haystack = $this->normalizeSearchText((string) $value);
            if ($haystack !== '' && strpos($haystack, $needle) !== false) {
                return true;
            }
        }
        return false;
    }

    private function normalizeSearchText(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        if (function_exists('mb_strtolower')) {
            return mb_strtolower($value, 'UTF-8');
        }
        return strtolower($value);
    }

    private function getExplicitMappedEasyServiceIdsForScope(): array
    {
        $q = Capsule::table('tblhosting as h')
            ->join('tblproducts as p', 'p.id', '=', 'h.packageid')
            ->leftJoin('mod_easydcim_bw_guard_service_state as s', 's.serviceid', '=', 'h.id')
            ->whereIn('h.domainstatus', ['Active', 'Suspended'])
            ->select(['h.id as serviceid', 's.easydcim_service_id as state_service_id']);
        $this->applyScopeFilter($q);
        $rows = $q->get();
        if ($rows->isEmpty()) {
            return [];
        }
        $serviceIds = $rows->pluck('serviceid')->map(static fn ($v): int => (int) $v)->all();
        $cfVals = $this->getServiceCustomFieldValues($serviceIds);
        $mapped = [];
        foreach ($rows as $r) {
            $sid = (int) ($r->serviceid ?? 0);
            if ($sid <= 0) {
                continue;
            }
            $cfService = trim((string) ($cfVals[$sid]['easydcim_service_id'] ?? ''));
            $stateService = trim((string) ($r->state_service_id ?? ''));
            $explicit = $cfService !== '' ? $cfService : $stateService;
            if ($explicit !== '') {
                $mapped[$explicit] = true;
            }
        }
        return $mapped;
    }

    private function fetchEasyServices(): array
    {
        $baseUrl = $this->settings->getString('easydcim_base_url');
        $token = Crypto::safeDecrypt($this->settings->getString('easydcim_api_token'));
        if ($baseUrl === '' || $token === '') {
            return [];
        }

        $client = new EasyDcimClient($baseUrl, $token, $this->settings->getBool('use_impersonation', false), $this->logger, $this->proxyConfig());
        $merged = [];
        $seen = [];
        for ($page = 1; $page <= 3; $page++) {
            $resp = $client->listServices(null, ['page' => $page, 'per_page' => 100]);
            $items = $this->extractServiceItems((array) ($resp['data'] ?? []));
            $this->logger->log('INFO', 'servers_list_services_summary', [
                'mode' => 'direct',
                'page' => $page,
                'http_code' => (int) ($resp['http_code'] ?? 0),
                'items' => count($items),
            ]);
            foreach ($items as $item) {
                $k = trim((string) ($item['service_id'] ?? ''));
                if ($k === '') {
                    $k = md5(json_encode($item, JSON_UNESCAPED_SLASHES));
                }
                if (isset($seen[$k])) {
                    continue;
                }
                $seen[$k] = true;
                $merged[] = $item;
            }
            if (count($items) < 100) {
                break;
            }
        }

        if (empty($merged) && $this->settings->getBool('use_impersonation', false)) {
            foreach ($this->getScopedClientEmails(30) as $email) {
                try {
                    $resp = $client->listServices($email, ['page' => 1, 'per_page' => 100]);
                    $items = $this->extractServiceItems((array) ($resp['data'] ?? []));
                    $this->logger->log('INFO', 'servers_list_services_summary', [
                        'mode' => 'impersonated',
                        'impersonate' => $email,
                        'http_code' => (int) ($resp['http_code'] ?? 0),
                        'items' => count($items),
                    ]);
                    foreach ($items as $item) {
                        $k = trim((string) ($item['service_id'] ?? ''));
                        if ($k === '') {
                            $k = md5(json_encode($item, JSON_UNESCAPED_SLASHES));
                        }
                        if (isset($seen[$k])) {
                            continue;
                        }
                        $seen[$k] = true;
                        $merged[] = $item;
                    }
                    if (!empty($merged)) {
                        break;
                    }
                } catch (\Throwable $e) {
                    $this->logger->log('WARNING', 'servers_list_services_impersonate_failed', [
                        'impersonate' => $email,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        $orders = $this->fetchEasyServicesFromAdminOrders($client);
        foreach ($orders as $item) {
            $k = trim((string) ($item['service_id'] ?? ''));
            if ($k === '') {
                $k = 'o:' . trim((string) ($item['order_id'] ?? ''));
            }
            if ($k === '' || isset($seen[$k])) {
                continue;
            }
            $seen[$k] = true;
            $merged[] = $item;
        }

        return $merged;
    }

    private function fetchEasyServicesFromAdminOrders(EasyDcimClient $client): array
    {
        $out = [];
        for ($page = 1; $page <= 5; $page++) {
            try {
                $resp = $client->listAdminOrders(['page' => $page, 'per_page' => 100]);
                $httpCode = (int) ($resp['http_code'] ?? 0);
                if ($httpCode < 200 || $httpCode >= 300) {
                    $this->logger->log('WARNING', 'servers_list_orders_http_failed', [
                        'page' => $page,
                        'http_code' => $httpCode,
                    ]);
                    break;
                }
                $orders = $this->extractListFromObject((array) ($resp['data'] ?? []));
                $orders = array_values(array_filter($orders, static function ($row): bool {
                    return is_array($row)
                        && (
                            isset($row['id'])
                            || isset($row['order_id'])
                            || isset($row['orderId'])
                            || isset($row['service_id'])
                            || isset($row['service'])
                        );
                }));
                $this->logger->log('INFO', 'servers_list_orders_summary', [
                    'page' => $page,
                    'http_code' => $httpCode,
                    'items' => count($orders),
                ]);
                if (empty($orders)) {
                    break;
                }
                foreach ($orders as $row) {
                    if (!is_array($row)) {
                        continue;
                    }
                    $identity = $this->extractEasyClientIdentity($row);
                    $label = $this->extractPreferredLabel($row);
                    $iloIp = $this->extractManagementIp($row);
                    $serviceId = trim((string) ($row['service_id'] ?? $row['serviceId'] ?? ''));
                    if ($serviceId === '' && isset($row['service']) && is_array($row['service'])) {
                        $serviceId = trim((string) ($row['service']['id'] ?? $row['service']['service_id'] ?? ''));
                    }
                    $serverId = trim((string) ($row['related_id'] ?? $row['server_id'] ?? $row['item_id'] ?? ''));
                    $ip = trim((string) ($row['ip'] ?? $row['dedicated_ip'] ?? $row['ipv4'] ?? ''));
                    if ($ip === '' && isset($row['related']) && is_array($row['related'])) {
                        $ip = trim((string) ($row['related']['ip'] ?? $row['related']['dedicated_ip'] ?? $row['related']['ipv4'] ?? ''));
                    }
                    if ($ip === '' && isset($row['service']) && is_array($row['service'])) {
                        $ip = trim((string) ($row['service']['ip'] ?? $row['service']['dedicated_ip'] ?? $row['service']['ipv4'] ?? ''));
                    }
                    $out[] = [
                        'service_id' => $serviceId,
                        'server_id' => $serverId,
                        'order_id' => trim((string) ($row['id'] ?? $row['order_id'] ?? $row['orderId'] ?? '')),
                        'ip' => $ip,
                        'ilo_ip' => $iloIp,
                        'label' => $label,
                        'status' => (string) ($row['status'] ?? ''),
                        'client_name' => $identity['name'],
                        'client_email' => $identity['email'],
                        'is_up' => false,
                    ];
                }
                if (count($orders) < 100) {
                    break;
                }
            } catch (\Throwable $e) {
                $this->logger->log('WARNING', 'servers_list_orders_failed', ['page' => $page, 'error' => $e->getMessage()]);
                break;
            }
        }
        return $out;
    }

    private function renderLogsTab(): void
    {
        $retention = max(1, $this->settings->getInt('log_retention_days', 30));
        $logsQ = trim((string) ($_REQUEST['logs_q'] ?? ''));
        $logsSource = trim((string) ($_REQUEST['logs_source'] ?? ''));
        $logsLevel = strtoupper(trim((string) ($_REQUEST['logs_level'] ?? '')));
        $logsOnlyErrors = ((string) ($_REQUEST['logs_only_errors'] ?? '') === '1');
        $logsPerPage = $this->getPerPage('logs_per_page', 10);
        $logsPage = $this->getPage('logs_page');
        echo '<div class="edbw-panel">';
        echo '<h3>System Logs</h3>';
        echo '<p class="edbw-help">Retention is set to ' . $retention . ' day(s). Logs older than this are auto-cleaned in cron.</p>';
        echo '<form method="get" class="edbw-form-inline edbw-search-form" action="' . htmlspecialchars($this->buildTabUrl('logs', ['logs_q' => '', 'logs_source' => '', 'logs_level' => '', 'logs_only_errors' => '', 'logs_page' => '', 'logs_per_page' => ''])) . '">';
        echo '<label>' . htmlspecialchars($this->t('search')) . '</label>';
        echo '<input type="text" name="logs_q" value="' . htmlspecialchars($logsQ) . '" placeholder="' . htmlspecialchars($this->t('logs_search_placeholder')) . '">';
        echo '<label>Level</label>';
        echo '<select name="logs_level">';
        echo '<option value=""' . ($logsLevel === '' ? ' selected' : '') . '>All</option>';
        foreach (['INFO', 'WARNING', 'ERROR'] as $lv) {
            echo '<option value="' . $lv . '"' . ($logsLevel === $lv ? ' selected' : '') . '>' . $lv . '</option>';
        }
        echo '</select>';
        echo '<label>Source</label>';
        echo '<input type="text" name="logs_source" value="' . htmlspecialchars($logsSource) . '" placeholder="system / easydcim_api_call ...">';
        echo '<label>' . htmlspecialchars($this->t('rows_per_page')) . '</label>';
        echo '<select name="logs_per_page">';
        foreach ([10, 25, 50, 100] as $size) {
            echo '<option value="' . $size . '"' . ($logsPerPage === $size ? ' selected' : '') . '>' . $size . '</option>';
        }
        echo '</select>';
        echo '<label>' . htmlspecialchars($this->t('logs_only_errors')) . '</label>';
        echo '<input type="checkbox" name="logs_only_errors" value="1"' . ($logsOnlyErrors ? ' checked' : '') . '>';
        echo '<button class="btn btn-default" type="submit">' . htmlspecialchars($this->t('search')) . '</button>';
        if ($logsQ !== '' || $logsSource !== '' || $logsLevel !== '' || $logsOnlyErrors) {
            echo '<a class="btn btn-default" href="' . htmlspecialchars($this->buildTabUrl('logs', ['logs_q' => '', 'logs_source' => '', 'logs_level' => '', 'logs_only_errors' => '', 'logs_page' => '', 'logs_per_page' => ''])) . '">' . htmlspecialchars($this->t('clear_search')) . '</a>';
        }
        echo '</form>';
        echo '<div class="edbw-server-actions">';
        echo '<form method="post" class="edbw-form-inline edbw-action-card">';
        echo '<input type="hidden" name="tab" value="logs">';
        echo '<input type="hidden" name="action" value="cleanup_logs">';
        echo '<button class="btn btn-default" type="submit">Cleanup Logs Now</button>';
        echo '</form>';
        echo '<form method="post" class="edbw-form-inline edbw-action-card">';
        echo '<input type="hidden" name="tab" value="logs">';
        echo '<input type="hidden" name="action" value="cleanup_logs_all">';
        echo '<button class="btn btn-default" type="submit">Delete All Logs</button>';
        echo '</form>';
        echo '</div>';

        $logsPaged = $this->getSystemLogsPaged($logsPage, $logsPerPage, [
            'q' => $logsQ,
            'source' => $logsSource,
            'level' => $logsLevel,
            'only_errors' => $logsOnlyErrors,
        ]);
        $logs = $logsPaged['rows'];
        echo '<div class="edbw-table-wrap">';
        echo '<table class="table table-striped"><thead><tr><th>Level</th><th>Message</th><th>Source</th><th>Details</th><th>Time</th></tr></thead><tbody>';
        foreach ($logs as $log) {
            $ctx = (string) ($log['context_json'] ?? '');
            if (strlen($ctx) > 1200) {
                $ctx = substr($ctx, 0, 1200) . '...';
            }
            echo '<tr>';
            echo '<td>' . htmlspecialchars((string) $log['level']) . '</td>';
            echo '<td>' . htmlspecialchars((string) $log['message']) . '</td>';
            echo '<td>' . htmlspecialchars((string) ($log['source'] ?? 'system')) . '</td>';
            echo '<td><code>' . htmlspecialchars($ctx) . '</code></td>';
            echo '<td>' . htmlspecialchars((string) $log['created_at']) . '</td>';
            echo '</tr>';
        }
        if (empty($logs)) {
            echo '<tr><td colspan="5">' . htmlspecialchars($this->t('no_rows')) . '</td></tr>';
        }
        echo '</tbody></table>';
        echo '</div>';
        $this->renderPager('logs', 'logs_page', (int) $logsPaged['page'], (int) $logsPaged['pages'], [
            'logs_q' => $logsQ,
            'logs_source' => $logsSource,
            'logs_level' => $logsLevel,
            'logs_only_errors' => $logsOnlyErrors ? '1' : '',
            'logs_per_page' => (string) $logsPerPage,
        ]);
        echo '</div>';

        echo '<div class="edbw-panel">';
        echo '<h3>Traffic Purchase Logs</h3>';
        $purchases = $this->getPurchaseLogs(300);
        echo '<div class="edbw-table-wrap">';
        echo '<table class="table table-striped"><thead><tr><th>ID</th><th>Service</th><th>Invoice</th><th>Cycle</th><th>Reset</th><th>Actor</th><th>Created</th></tr></thead><tbody>';
        foreach ($purchases as $row) {
            echo '<tr>';
            echo '<td>' . (int) $row['id'] . '</td>';
            echo '<td>' . (int) $row['whmcs_serviceid'] . '</td>';
            echo '<td>' . (int) $row['invoiceid'] . '</td>';
            echo '<td>' . htmlspecialchars((string) $row['cycle_start'] . ' -> ' . (string) $row['cycle_end']) . '</td>';
            echo '<td>' . htmlspecialchars((string) $row['reset_at']) . '</td>';
            echo '<td>' . htmlspecialchars((string) $row['actor']) . '</td>';
            echo '<td>' . htmlspecialchars((string) $row['created_at']) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
        echo '</div>';
        echo '</div>';
    }

    private function renderTrafficTab(): void
    {
        $search = trim((string) ($_REQUEST['traffic_q'] ?? ''));
        $focusServiceId = (int) ($_REQUEST['focus_serviceid'] ?? 0);
        $rows = $this->loadTrafficSnapshotRows();
        if (empty($rows)) {
            $rows = $this->refreshTrafficSnapshot(true);
        }

        $defaultsByPid = [];
        $pidList = [];
        foreach ($rows as $r) {
            $pid = (int) ($r['pid'] ?? 0);
            if ($pid > 0) {
                $pidList[$pid] = $pid;
            }
        }
        if (!empty($pidList)) {
            $defaultRows = Capsule::table('mod_easydcim_bw_guard_product_defaults')
                ->whereIn('pid', array_values($pidList))
                ->where('enabled', 1)
                ->get();
            foreach ($defaultRows as $dr) {
                $defaultsByPid[(int) $dr->pid] = $dr;
            }
        }

        $easyServices = $this->getEasyServicesCacheOnly();
        $easyByService = [];
        $easyByOrder = [];
        $easyByServer = [];
        foreach ($easyServices as $item) {
            if (!is_array($item)) {
                continue;
            }
            $sid = trim((string) ($item['service_id'] ?? ''));
            $ord = trim((string) ($item['order_id'] ?? ''));
            $srv = trim((string) ($item['server_id'] ?? ''));
            if ($sid !== '' && !isset($easyByService[$sid])) {
                $easyByService[$sid] = $item;
            }
            if ($ord !== '' && !isset($easyByOrder[$ord])) {
                $easyByOrder[$ord] = $item;
            }
            if ($srv !== '' && !isset($easyByServer[$srv])) {
                $easyByServer[$srv] = $item;
            }
        }

        if ($search !== '') {
            $rows = array_values(array_filter($rows, function (array $row) use ($search, $easyByService, $easyByOrder, $easyByServer): bool {
                $serviceId = trim((string) ($row['easydcim_service_id'] ?? ''));
                $orderId = trim((string) ($row['easydcim_order_id'] ?? ''));
                $serverId = trim((string) ($row['easydcim_server_id'] ?? ''));
                $easyItem = $serviceId !== '' && isset($easyByService[$serviceId]) ? $easyByService[$serviceId] : [];
                if (empty($easyItem) && $orderId !== '' && isset($easyByOrder[$orderId])) {
                    $easyItem = $easyByOrder[$orderId];
                }
                if (empty($easyItem) && $serverId !== '' && isset($easyByServer[$serverId])) {
                    $easyItem = $easyByServer[$serverId];
                }
                return $this->matchesSearchTerm([
                    (string) ($row['serviceid'] ?? ''),
                    (string) ($row['userid'] ?? ''),
                    (string) ($row['pid'] ?? ''),
                    (string) ($row['dedicatedip'] ?? ''),
                    (string) ($row['domain'] ?? ''),
                    (string) ($row['firstname'] ?? ''),
                    (string) ($row['lastname'] ?? ''),
                    (string) ($row['email'] ?? ''),
                    (string) ($row['easydcim_service_id'] ?? ''),
                    (string) ($row['easydcim_order_id'] ?? ''),
                    (string) ($row['easydcim_server_id'] ?? ''),
                    (string) ($easyItem['label'] ?? ''),
                    (string) ($easyItem['ilo_ip'] ?? ''),
                    (string) ($easyItem['ip'] ?? ''),
                    (string) ($easyItem['client_name'] ?? ''),
                    (string) ($easyItem['client_email'] ?? ''),
                ], $search);
            }));
        }

        echo '<div class="edbw-panel">';
        echo '<h3>' . htmlspecialchars($this->t('traffic_report_title')) . '</h3>';
        echo '<form method="get" class="edbw-form-inline edbw-search-form" action="' . htmlspecialchars($this->buildTabUrl('traffic', ['traffic_q' => '', 'focus_serviceid' => '', 'servers_q' => ''])) . '">';
        echo '<label>' . htmlspecialchars($this->t('search')) . '</label>';
        echo '<input type="text" name="traffic_q" value="' . htmlspecialchars($search) . '" placeholder="' . htmlspecialchars($this->t('traffic_search_placeholder')) . '">';
        echo '<button class="btn btn-default" type="submit">' . htmlspecialchars($this->t('search')) . '</button>';
        if ($search !== '' || $focusServiceId > 0) {
            echo '<a class="btn btn-default" href="' . htmlspecialchars($this->buildTabUrl('traffic', ['traffic_q' => '', 'focus_serviceid' => '', 'servers_q' => ''])) . '">' . htmlspecialchars($this->t('clear_search')) . '</a>';
        }
        echo '</form>';
        echo '<div class="edbw-table-wrap"><table class="table table-striped edbw-table-center"><thead><tr><th>'
            . htmlspecialchars($this->t('service')) . '</th><th>'
            . htmlspecialchars($this->t('client')) . '</th><th>'
            . htmlspecialchars($this->t('default_plan_bw')) . '</th><th>'
            . htmlspecialchars($this->t('traffic_used_gb')) . '</th><th>'
            . htmlspecialchars($this->t('traffic_remaining_gb')) . '</th><th>'
            . htmlspecialchars($this->t('traffic_allowed_gb')) . '</th><th>'
            . htmlspecialchars($this->t('traffic_cycle')) . '</th><th>'
            . htmlspecialchars($this->t('status')) . '</th><th>'
            . htmlspecialchars($this->t('traffic_last_check')) . '</th><th>'
            . htmlspecialchars($this->t('details')) . '</th></tr></thead><tbody>';

        foreach ($rows as $r) {
            $serviceId = (int) ($r['serviceid'] ?? 0);
            $userId = (int) ($r['userid'] ?? 0);
            $pid = (int) ($r['pid'] ?? 0);
            $used = (float) ($r['last_used_gb'] ?? 0.0);
            $remaining = (float) ($r['last_remaining_gb'] ?? 0.0);
            $allowed = max(0.0, $used + $remaining);
            $clientName = trim((string) ($r['firstname'] ?? '') . ' ' . (string) ($r['lastname'] ?? ''));
            if ($clientName === '') {
                $clientName = '#' . $userId;
            }
            $mode = strtoupper(trim((string) ($r['mode'] ?? 'TOTAL')));
            $cycleStart = trim((string) ($r['cycle_start'] ?? ''));
            $cycleEnd = trim((string) ($r['cycle_end'] ?? ''));
            $extra = (float) ($r['extra_gb'] ?? 0.0);
            $cycle = $cycleStart !== '' && $cycleEnd !== ''
                ? ($cycleStart . ' -> ' . $cycleEnd)
                : $this->t('m_no_data');
            $status = $this->domainStatusLabel((string) ($r['last_status'] ?? ''));
            $checkedAt = trim((string) ($r['last_check_at'] ?? ''));
            if ($checkedAt === '') {
                $checkedAt = $this->t('m_no_data');
            }
            $defaultPlanHtml = $this->formatDefaultPlanBwBadge($defaultsByPid[$pid] ?? null, $mode);
            $resetAt = $cycleEnd !== '' ? (date('Y-m-d H:i:s', strtotime($cycleEnd) + 1)) : $this->t('m_no_data');

            $trClass = $focusServiceId > 0 && $focusServiceId === $serviceId ? ' class="edbw-row-focus"' : '';
            echo '<tr' . $trClass . '>';
            echo '<td><a href="clientsservices.php?userid=' . $userId . '&id=' . $serviceId . '">#' . $serviceId . '</a></td>';
            echo '<td><a href="clientssummary.php?userid=' . $userId . '">' . htmlspecialchars($clientName) . '</a></td>';
            echo '<td>' . $defaultPlanHtml . '</td>';
            echo '<td>' . number_format($used, 2, '.', '') . '</td>';
            echo '<td>' . number_format($remaining, 2, '.', '') . '</td>';
            echo '<td>' . number_format($allowed, 2, '.', '') . '</td>';
            echo '<td>' . htmlspecialchars($cycle) . '</td>';
            echo '<td>' . htmlspecialchars($status) . '</td>';
            echo '<td>' . htmlspecialchars($checkedAt) . '</td>';
            echo '<td><details class="edbw-traffic-detail"><summary>' . htmlspecialchars($this->t('details')) . '</summary>'
                . '<div class="edbw-traffic-detail-body">'
                . '<div><strong>' . htmlspecialchars($this->t('product_id')) . ':</strong> ' . $pid . '</div>'
                . '<div><strong>IP:</strong> ' . htmlspecialchars((string) ($r['dedicatedip'] ?? '-')) . '</div>'
                . '<div><strong>' . htmlspecialchars($this->t('mode')) . ':</strong> ' . htmlspecialchars($mode) . '</div>'
                . '<div><strong>' . htmlspecialchars($this->t('base_quota')) . ':</strong> ' . number_format(max(0.0, $allowed - $extra), 2, '.', '') . ' GB</div>'
                . '<div><strong>' . htmlspecialchars($this->t('extra_quota')) . ':</strong> ' . number_format($extra, 2, '.', '') . ' GB</div>'
                . '<div><strong>' . htmlspecialchars($this->t('traffic_allowed_gb')) . ':</strong> ' . number_format($allowed, 2, '.', '') . ' GB</div>'
                . '<div><strong>' . htmlspecialchars($this->t('traffic_cycle')) . ':</strong> ' . htmlspecialchars($cycle) . '</div>'
                . '<div><strong>' . htmlspecialchars($this->t('reset_at')) . ':</strong> ' . htmlspecialchars($resetAt) . '</div>'
                . '</div></details></td>';
            echo '</tr>';
        }

        if (empty($rows)) {
            echo '<tr><td colspan="10">' . htmlspecialchars($this->t('no_rows')) . '</td></tr>';
        }

        echo '</tbody></table></div>';
        echo '</div>';
    }

    public function refreshTrafficSnapshotFromCron(int $intervalSeconds = 60): void
    {
        $intervalSeconds = max(15, $intervalSeconds);
        $last = (string) Capsule::table('mod_easydcim_bw_guard_meta')->where('meta_key', 'traffic_snapshot_last_refresh_at')->value('meta_value');
        if ($last !== '' && strtotime($last) > (time() - $intervalSeconds)) {
            return;
        }
        $this->refreshTrafficSnapshot(true);
    }

    private function refreshTrafficSnapshot(bool $updateMeta): array
    {
        $rows = $this->buildTrafficRowsFromDatabase(500);
        $this->saveTrafficSnapshotRows($rows);
        if ($updateMeta) {
            Capsule::table('mod_easydcim_bw_guard_meta')->updateOrInsert(
                ['meta_key' => 'traffic_snapshot_last_refresh_at'],
                ['meta_value' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')]
            );
        }
        return $rows;
    }

    private function buildTrafficRowsFromDatabase(int $limit = 500): array
    {
        $q = Capsule::table('mod_easydcim_bw_guard_service_state as s')
            ->join('tblhosting as h', 'h.id', '=', 's.serviceid')
            ->join('tblproducts as p', 'p.id', '=', 'h.packageid')
            ->leftJoin('tblclients as c', 'c.id', '=', 'h.userid')
            ->whereIn('h.domainstatus', ['Active', 'Suspended'])
            ->select([
                's.serviceid',
                's.userid',
                'h.packageid as pid',
                'h.dedicatedip',
                'h.domain',
                'c.email',
                's.easydcim_service_id',
                's.easydcim_order_id',
                's.easydcim_server_id',
                's.last_used_gb',
                's.last_remaining_gb',
                's.last_status',
                's.mode',
                's.last_check_at',
                's.cycle_start',
                's.cycle_end',
                'c.firstname',
                'c.lastname',
            ]);
        $this->applyScopeFilter($q);
        $rows = $q->orderByDesc('s.last_check_at')->orderByDesc('s.serviceid')->limit(max(1, $limit))->get();
        if ($rows->isEmpty()) {
            return [];
        }

        $serviceIds = [];
        $cycleStarts = [];
        $cycleEnds = [];
        foreach ($rows as $r) {
            $sid = (int) ($r->serviceid ?? 0);
            if ($sid > 0) {
                $serviceIds[$sid] = $sid;
            }
            $cs = trim((string) ($r->cycle_start ?? ''));
            $ce = trim((string) ($r->cycle_end ?? ''));
            if ($cs !== '') {
                $cycleStarts[$cs] = $cs;
            }
            if ($ce !== '') {
                $cycleEnds[$ce] = $ce;
            }
        }

        $purchaseSums = [];
        if (!empty($serviceIds) && !empty($cycleStarts) && !empty($cycleEnds)) {
            $pRows = Capsule::table('mod_easydcim_bw_guard_purchases')
                ->whereIn('whmcs_serviceid', array_values($serviceIds))
                ->whereIn('cycle_start', array_values($cycleStarts))
                ->whereIn('cycle_end', array_values($cycleEnds))
                ->where('payment_status', 'paid')
                ->select(['whmcs_serviceid', 'cycle_start', 'cycle_end', Capsule::raw('SUM(size_gb) as total_gb')])
                ->groupBy('whmcs_serviceid', 'cycle_start', 'cycle_end')
                ->get();
            foreach ($pRows as $p) {
                $key = (int) $p->whmcs_serviceid . '|' . (string) $p->cycle_start . '|' . (string) $p->cycle_end;
                $purchaseSums[$key] = (float) ($p->total_gb ?? 0.0);
            }
        }

        $out = [];
        foreach ($rows as $r) {
            $sid = (int) ($r->serviceid ?? 0);
            $cs = trim((string) ($r->cycle_start ?? ''));
            $ce = trim((string) ($r->cycle_end ?? ''));
            $key = $sid . '|' . $cs . '|' . $ce;
            $out[] = [
                'serviceid' => $sid,
                'userid' => (int) ($r->userid ?? 0),
                'pid' => (int) ($r->pid ?? 0),
                'dedicatedip' => (string) ($r->dedicatedip ?? ''),
                'domain' => (string) ($r->domain ?? ''),
                'email' => (string) ($r->email ?? ''),
                'easydcim_service_id' => (string) ($r->easydcim_service_id ?? ''),
                'easydcim_order_id' => (string) ($r->easydcim_order_id ?? ''),
                'easydcim_server_id' => (string) ($r->easydcim_server_id ?? ''),
                'last_used_gb' => (float) ($r->last_used_gb ?? 0.0),
                'last_remaining_gb' => (float) ($r->last_remaining_gb ?? 0.0),
                'last_status' => (string) ($r->last_status ?? 'ok'),
                'mode' => (string) ($r->mode ?? 'TOTAL'),
                'last_check_at' => (string) ($r->last_check_at ?? ''),
                'cycle_start' => $cs,
                'cycle_end' => $ce,
                'firstname' => (string) ($r->firstname ?? ''),
                'lastname' => (string) ($r->lastname ?? ''),
                'extra_gb' => (float) ($purchaseSums[$key] ?? 0.0),
            ];
        }
        return $out;
    }

    private function getTrafficSnapshotPath(): string
    {
        return rtrim($this->moduleDir, '/\\') . '/cache/traffic_snapshot.json';
    }

    private function loadTrafficSnapshotRows(): array
    {
        $path = $this->getTrafficSnapshotPath();
        if (!is_file($path)) {
            return [];
        }
        $raw = @file_get_contents($path);
        if (!is_string($raw) || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }
        $rows = $decoded['rows'] ?? [];
        return is_array($rows) ? $rows : [];
    }

    private function saveTrafficSnapshotRows(array $rows): void
    {
        $path = $this->getTrafficSnapshotPath();
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $payload = [
            'generated_at' => date('Y-m-d H:i:s'),
            'count' => count($rows),
            'rows' => $rows,
        ];
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return;
        }
        $tmp = $path . '.tmp';
        @file_put_contents($tmp, $json, LOCK_EX);
        @rename($tmp, $path);
    }

    private function formatDefaultPlanBwBadge(?object $default, string $mode): string
    {
        $mode = strtoupper($mode);
        $in = $this->defaultPlanPart($default, 'in');
        $out = $this->defaultPlanPart($default, 'out');
        $total = $this->defaultPlanPart($default, 'total');
        $inClass = $mode === 'IN' || $mode === 'TOTAL' ? 'is-active' : '';
        $outClass = $mode === 'OUT' || $mode === 'TOTAL' ? 'is-active' : '';
        $totalClass = $mode === 'TOTAL' ? 'is-active' : '';

        return '<span class="edbw-plan-bw"><span class="' . $inClass . '">' . htmlspecialchars($in) . '-D</span>'
            . ' | <span class="' . $outClass . '">' . htmlspecialchars($out) . '-U</span>'
            . ' | <span class="' . $totalClass . '">' . htmlspecialchars($total) . '-T</span></span>';
    }

    private function defaultPlanPart(?object $default, string $part): string
    {
        if (!$default) {
            return '0G';
        }
        if ($part === 'in') {
            if ((int) ($default->unlimited_in ?? 0) === 1) {
                return '∞';
            }
            return number_format((float) ($default->default_quota_in_gb ?? 0.0), 0, '.', '') . 'G';
        }
        if ($part === 'out') {
            if ((int) ($default->unlimited_out ?? 0) === 1) {
                return '∞';
            }
            return number_format((float) ($default->default_quota_out_gb ?? 0.0), 0, '.', '') . 'G';
        }
        if ((int) ($default->unlimited_total ?? 0) === 1) {
            return '∞';
        }
        return number_format((float) ($default->default_quota_total_gb ?? $default->default_quota_gb ?? 0.0), 0, '.', '') . 'G';
    }

    private function renderPreflightPanel(): void
    {
        $checks = $this->buildHealthChecks();
        $failed = array_filter($checks, static fn (array $c): bool => ((string) ($c['level'] ?? ($c['ok'] ? 'ok' : 'fail')) === 'fail'));

        echo '<div class="edbw-panel">';
        echo '<h3>' . htmlspecialchars($this->t('preflight_checks')) . '</h3>';
        echo '<form method="post" class="edbw-form-inline">';
        echo '<input type="hidden" name="tab" value="health">';
        echo '<input type="hidden" name="action" value="run_preflight">';
        echo '<button class="btn btn-default" type="submit">' . htmlspecialchars($this->t('retest')) . '</button>';
        echo '</form>';
        echo '<div class="edbw-table-wrap">';
        echo '<table class="table table-striped"><thead><tr><th>' . htmlspecialchars($this->t('check')) . '</th><th>' . htmlspecialchars($this->t('status')) . '</th><th>' . htmlspecialchars($this->t('details')) . '</th></tr></thead><tbody>';
        foreach ($checks as $check) {
            $level = (string) ($check['level'] ?? ($check['ok'] ? 'ok' : 'fail'));
            $badge = '<span class="edbw-badge fail">' . htmlspecialchars($this->t('missing_fail')) . '</span>';
            if ($level === 'ok') {
                $badge = '<span class="edbw-badge ok">OK</span>';
            } elseif ($level === 'warn') {
                $badge = '<span class="edbw-badge warn">' . htmlspecialchars($this->isFa ? 'هشدار' : 'Warning') . '</span>';
            }
            echo '<tr>';
            echo '<td>' . htmlspecialchars($check['name']) . '</td>';
            echo '<td>' . $badge . '</td>';
            echo '<td>' . htmlspecialchars($check['detail']) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
        echo '</div>';

        if (!empty($failed)) {
            echo '<div class="alert alert-warning">' . htmlspecialchars($this->t('preflight_warn')) . '</div>';
        } else {
            echo '<div class="alert alert-success">' . htmlspecialchars($this->t('preflight_ok')) . '</div>';
        }
        echo '</div>';
    }

    private function renderImportantWarningsPanel(): void
    {
        $warnings = $this->buildImportantWarnings();
        echo '<div class="edbw-panel">';
        echo '<h3>' . htmlspecialchars($this->t('important_warnings')) . '</h3>';
        if (empty($warnings)) {
            echo '<div class="alert alert-success">' . htmlspecialchars($this->t('no_important_warnings')) . '</div>';
            echo '</div>';
            return;
        }
        echo '<div class="edbw-table-wrap">';
        echo '<table class="table table-striped"><thead><tr><th>' . htmlspecialchars($this->t('warning_type')) . '</th><th>' . htmlspecialchars($this->t('details')) . '</th></tr></thead><tbody>';
        foreach ($warnings as $w) {
            echo '<tr><td>' . htmlspecialchars($w['type']) . '</td><td>' . $w['text'] . '</td></tr>';
        }
        echo '</tbody></table></div>';
        echo '</div>';
    }

    private function buildImportantWarnings(): array
    {
        $easyServices = $this->getEasyServicesCacheOnly();
        $rows = $this->getScopedHostingServices($easyServices, false);
        if (empty($rows)) {
            return [];
        }
        $warnings = [];
        if (empty($easyServices)) {
            $warnings[] = [
                'type' => $this->t('warn_easy_cache_empty'),
                'text' => htmlspecialchars($this->t('warn_easy_cache_empty_text')),
            ];
        }

        $activeRows = array_values(array_filter($rows, static fn (array $r): bool => strtolower((string) ($r['domainstatus'] ?? '')) === 'active'));
        $shared = [];
        foreach ($activeRows as $r) {
            $server = trim((string) ($r['easydcim_server_id'] ?? ''));
            if ($server === '') {
                continue;
            }
            $shared[$server][] = $r;
        }
        foreach ($shared as $serverId => $items) {
            if (count($items) < 2) {
                continue;
            }
            $links = [];
            foreach ($items as $r) {
                $links[] = '<a href="' . htmlspecialchars((string) $r['service_url']) . '">#' . (int) $r['serviceid'] . '</a>';
            }
            $warnings[] = [
                'type' => $this->t('warn_shared_server'),
                'text' => htmlspecialchars($this->t('server_id')) . ': ' . htmlspecialchars($serverId) . ' | ' . implode(' , ', $links),
            ];
        }

        $byService = [];
        foreach ($activeRows as $r) {
            $networkTotal = (int) ($r['network_ports_total'] ?? 0);
            $networkUp = (int) ($r['network_ports_up'] ?? 0);
            $networkTraffic = (float) ($r['network_traffic_total'] ?? 0.0);
            $serviceKey = (int) ($r['serviceid'] ?? 0);
            if ($serviceKey <= 0) {
                continue;
            }
            if (!isset($byService[$serviceKey])) {
                $byService[$serviceKey] = [
                    'row' => $r,
                    'issues' => [],
                ];
            }

            if ($networkTotal > 0 && $networkUp === 0) {
                $byService[$serviceKey]['issues'][] = $this->t('warn_active_port_down');
            }
            if ($networkTotal > 0 && $networkTraffic <= 0.0) {
                $byService[$serviceKey]['issues'][] = $this->t('warn_active_no_traffic');
            }
        }
        foreach ($byService as $bundle) {
            if (empty($bundle['issues'])) {
                continue;
            }
            $row = $bundle['row'];
            $issues = array_values(array_unique($bundle['issues']));
            $serviceLink = '<a href="' . htmlspecialchars((string) $row['service_url']) . '">#' . (int) $row['serviceid'] . '</a>';
            $clientLink = '<a href="' . htmlspecialchars((string) $row['client_url']) . '">' . htmlspecialchars((string) $row['client_name']) . '</a>';
            $warnings[] = [
                'type' => $this->t('warn_service_issues'),
                'text' => $this->t('service') . ' ' . $serviceLink . ' | ' . $this->t('client') . ': ' . $clientLink . ' | ' . implode(' ، ', array_map('htmlspecialchars', $issues)),
            ];
        }

        foreach ($activeRows as $r) {
            $easyEmail = $this->normalizeEmail((string) ($r['easydcim_client_email'] ?? ''));
            $easyName = $this->normalizeName((string) ($r['easydcim_client_name'] ?? ''));
            if ($easyEmail === '' && $easyName === '') {
                continue;
            }

            $whmcsEmail = $this->normalizeEmail((string) ($r['email'] ?? ''));
            $whmcsName = $this->normalizeName((string) ($r['client_name'] ?? ''));
            $emailMismatch = ($easyEmail !== '' && $whmcsEmail !== '' && $easyEmail !== $whmcsEmail);
            $nameMismatch = ($easyName !== '' && $whmcsName !== '' && $easyName !== $whmcsName);
            if (!$emailMismatch && !$nameMismatch) {
                continue;
            }

            $serviceLink = '<a href="' . htmlspecialchars((string) $r['service_url']) . '">#' . (int) $r['serviceid'] . '</a>';
            $clientLink = '<a href="' . htmlspecialchars((string) $r['client_url']) . '">' . htmlspecialchars((string) $r['client_name']) . '</a>';
            $parts = [];
            if ($emailMismatch) {
                $parts[] = ($this->isFa ? 'ایمیل' : 'Email') . ' WHMCS=' . htmlspecialchars((string) ($r['email'] ?? '-')) . ' / EasyDCIM=' . htmlspecialchars((string) ($r['easydcim_client_email'] ?? '-'));
            }
            if ($nameMismatch) {
                $parts[] = ($this->isFa ? 'نام' : 'Name') . ' WHMCS=' . htmlspecialchars((string) ($r['client_name'] ?? '-')) . ' / EasyDCIM=' . htmlspecialchars((string) ($r['easydcim_client_name'] ?? '-'));
            }
            $warnings[] = [
                'type' => $this->t('warn_client_mismatch'),
                'text' => $this->t('service') . ' ' . $serviceLink . ' | ' . $this->t('client') . ': ' . $clientLink . ' | ' . implode(' | ', $parts),
            ];
        }

        $explicitMappedServiceIds = $this->getExplicitMappedEasyServiceIdsForScope();
        $unassigned = $this->buildUnassignedEasyServices($easyServices, $explicitMappedServiceIds);
        if (!empty($unassigned)) {
            $byIp = [];
            $byLabel = [];
            foreach ($activeRows as $r) {
                $ip = $this->normalizeLooseIdentifier((string) ($r['ip'] ?? ''));
                $domain = $this->normalizeLooseIdentifier((string) ($r['domain'] ?? ''));
                if ($ip !== '') {
                    $byIp[$ip][] = $r;
                }
                if ($domain !== '') {
                    $byLabel[$domain][] = $r;
                }
            }

            foreach ($unassigned as $item) {
                $matches = [];
                $iloIp = $this->normalizeLooseIdentifier((string) ($item['ilo_ip'] ?? ''));
                $serviceIp = $this->normalizeLooseIdentifier((string) ($item['ip'] ?? ''));
                $label = $this->normalizeLooseIdentifier((string) ($item['label'] ?? ''));

                if ($iloIp !== '' && isset($byIp[$iloIp])) {
                    foreach ($byIp[$iloIp] as $m) {
                        $matches[(int) $m['serviceid']] = $m;
                    }
                }
                if ($serviceIp !== '' && isset($byIp[$serviceIp])) {
                    foreach ($byIp[$serviceIp] as $m) {
                        $matches[(int) $m['serviceid']] = $m;
                    }
                }
                if ($label !== '' && isset($byLabel[$label])) {
                    foreach ($byLabel[$label] as $m) {
                        $matches[(int) $m['serviceid']] = $m;
                    }
                }
                if (empty($matches)) {
                    continue;
                }

                $serviceLinks = [];
                foreach ($matches as $m) {
                    $serviceLinks[] = '<a href="' . htmlspecialchars((string) ($m['service_url'] ?? '#')) . '">#' . (int) $m['serviceid'] . '</a>';
                }
                $parts = [];
                if (trim((string) ($item['service_id'] ?? '')) !== '') {
                    $parts[] = 'EasyDCIM Service=' . htmlspecialchars((string) $item['service_id']);
                }
                if (trim((string) ($item['server_id'] ?? '')) !== '') {
                    $parts[] = 'Server=' . htmlspecialchars((string) $item['server_id']);
                }
                if (trim((string) ($item['order_id'] ?? '')) !== '') {
                    $parts[] = 'Order=' . htmlspecialchars((string) $item['order_id']);
                }
                if (trim((string) ($item['ilo_ip'] ?? '')) !== '') {
                    $parts[] = 'iLO=' . htmlspecialchars((string) $item['ilo_ip']);
                }
                if (trim((string) ($item['label'] ?? '')) !== '') {
                    $parts[] = 'Label=' . htmlspecialchars((string) $item['label']);
                }
                if (trim((string) ($item['ip'] ?? '')) !== '') {
                    $parts[] = 'IP=' . htmlspecialchars((string) $item['ip']);
                }
                $warnings[] = [
                    'type' => $this->t('warn_unassigned_possible_match'),
                    'text' => implode(' | ', $parts) . ' | ' . htmlspecialchars($this->t('warn_unassigned_possible_match_target')) . ': ' . implode(' , ', $serviceLinks),
                ];
            }
        }

        foreach ($activeRows as $r) {
            $explicitService = trim((string) ($r['easydcim_service_id_explicit'] ?? ''));
            $resolvedService = trim((string) ($r['easydcim_service_id'] ?? ''));
            $resolvedOrder = trim((string) ($r['easydcim_order_id'] ?? ''));
            $resolvedServer = trim((string) ($r['easydcim_server_id'] ?? ''));
            if ($resolvedService === '') {
                continue;
            }
            if ($explicitService === '') {
                $serviceLink = '<a href="' . htmlspecialchars((string) $r['service_url']) . '">#' . (int) $r['serviceid'] . '</a>';
                $warnings[] = [
                    'type' => $this->t('warn_missing_explicit_map'),
                    'text' => $this->t('service') . ' ' . $serviceLink
                        . ' | EasyDCIM Service=' . htmlspecialchars($resolvedService)
                        . ($resolvedOrder !== '' ? (' | Order=' . htmlspecialchars($resolvedOrder)) : '')
                        . ($resolvedServer !== '' ? (' | Server=' . htmlspecialchars($resolvedServer)) : ''),
                ];
                continue;
            }
            if ($explicitService !== $resolvedService) {
                $serviceLink = '<a href="' . htmlspecialchars((string) $r['service_url']) . '">#' . (int) $r['serviceid'] . '</a>';
                $warnings[] = [
                    'type' => $this->t('warn_explicit_map_mismatch'),
                    'text' => $this->t('service') . ' ' . $serviceLink
                        . ' | ' . htmlspecialchars($this->t('warn_expected')) . '=' . htmlspecialchars($explicitService)
                        . ' | ' . htmlspecialchars($this->t('warn_detected')) . '=' . htmlspecialchars($resolvedService),
                ];
            }
        }

        return $warnings;
    }

    private function buildHealthChecks(): array
    {
        $cacheJson = (string) Capsule::table('mod_easydcim_bw_guard_meta')->where('meta_key', 'health_checks_cache_json')->value('meta_value');
        $cacheAt = (string) Capsule::table('mod_easydcim_bw_guard_meta')->where('meta_key', 'health_checks_cache_at')->value('meta_value');
        if ($cacheJson !== '' && $cacheAt !== '' && strtotime($cacheAt) > time() - 300) {
            $cached = json_decode($cacheJson, true);
            if (is_array($cached) && !empty($cached)) {
                return $cached;
            }
        }

        $checks = [];
        $phpOk = version_compare(PHP_VERSION, '8.0.0', '>=');
        $checks[] = ['name' => $this->t('hc_php_version'), 'ok' => $phpOk, 'detail' => $this->t('hc_current') . ': ' . PHP_VERSION . ', ' . $this->t('hc_required') . ': >= 8.0'];

        $checks[] = ['name' => $this->t('hc_curl_extension'), 'ok' => function_exists('curl_init'), 'detail' => function_exists('curl_init') ? $this->t('hc_available') : $this->t('hc_missing')];
        $checks[] = ['name' => $this->t('hc_update_engine'), 'ok' => true, 'detail' => $this->t('hc_release_engine')];
        $checks[] = ['name' => $this->t('hc_zip_extension'), 'ok' => class_exists(\ZipArchive::class), 'detail' => class_exists(\ZipArchive::class) ? $this->t('hc_available') : $this->t('hc_missing')];
        $checks[] = ['name' => $this->t('hc_module_status'), 'ok' => $this->settings->getBool('module_enabled', true), 'detail' => $this->settings->getBool('module_enabled', true) ? $this->t('active') : $this->t('disabled')];

        $baseUrl = $this->settings->getString('easydcim_base_url');
        $token = $this->settings->getString('easydcim_api_token');
        $checks[] = ['name' => $this->t('hc_base_url'), 'ok' => $baseUrl !== '', 'detail' => $baseUrl !== '' ? $this->t('hc_configured') : $this->t('hc_not_configured')];
        $checks[] = ['name' => $this->t('hc_api_token'), 'ok' => $token !== '', 'detail' => $token !== '' ? $this->t('hc_configured') : $this->t('hc_not_configured')];

        $scopeSet = !empty($this->settings->getCsvList('managed_pids')) || !empty($this->settings->getCsvList('managed_gids'));
        $checks[] = ['name' => $this->t('hc_scope'), 'ok' => $scopeSet, 'detail' => $scopeSet ? $this->t('hc_configured') : $this->t('hc_no_scope')];

        $scopedProducts = $this->getScopedProducts();
        $totalScoped = count($scopedProducts);
        $cfCount = ['easydcim_service_id' => 0, 'easydcim_order_id' => 0, 'easydcim_server_id' => 0];
        foreach ($scopedProducts as $p) {
            if (!empty($p['cf_service'])) {
                $cfCount['easydcim_service_id']++;
            }
            if (!empty($p['cf_order'])) {
                $cfCount['easydcim_order_id']++;
            }
            if (!empty($p['cf_server'])) {
                $cfCount['easydcim_server_id']++;
            }
        }
        foreach ($cfCount as $field => $configured) {
            if ($totalScoped <= 0) {
                $checks[] = ['name' => 'Custom field: ' . $field, 'ok' => false, 'level' => 'fail', 'detail' => $this->t('hc_no_scope')];
                continue;
            }
            if ($field === 'easydcim_service_id') {
                $level = 'ok';
                $detail = $configured . '/' . $totalScoped . ' ' . ($this->isFa ? 'محصول در محدوده تنظیم شده' : 'scoped products configured') . ' (' . $this->t('hc_optional') . ')';
            } else {
                $level = $configured === $totalScoped ? 'ok' : ($configured > 0 ? 'warn' : 'fail');
                $detail = $configured . '/' . $totalScoped . ' ' . ($this->isFa ? 'محصول در محدوده تنظیم شده' : 'scoped products configured');
            }
            $checks[] = [
                'name' => 'Custom field: ' . $field,
                'ok' => $level !== 'fail',
                'level' => $level,
                'detail' => $detail,
            ];
        }

        Capsule::table('mod_easydcim_bw_guard_meta')->updateOrInsert(
            ['meta_key' => 'health_checks_cache_json'],
            ['meta_value' => json_encode($checks, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'updated_at' => date('Y-m-d H:i:s')]
        );
        Capsule::table('mod_easydcim_bw_guard_meta')->updateOrInsert(
            ['meta_key' => 'health_checks_cache_at'],
            ['meta_value' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')]
        );

        return $checks;
    }

    private function invalidateHealthCheckCache(): void
    {
        Capsule::table('mod_easydcim_bw_guard_meta')
            ->whereIn('meta_key', ['health_checks_cache_json', 'health_checks_cache_at'])
            ->delete();
    }

    private function buildRuntimeStatus(): array
    {
        $limitedCount = (int) Capsule::table('mod_easydcim_bw_guard_service_state')->where('last_status', 'limited')->count();
        $syncedInLastHour = (int) Capsule::table('mod_easydcim_bw_guard_service_state')->where('last_check_at', '>=', date('Y-m-d H:i:s', time() - 3600))->count();
        $suspendedQuery = Capsule::table('tblhosting as h')
            ->join('tblproducts as p', 'p.id', '=', 'h.packageid')
            ->leftJoin('mod_easydcim_bw_guard_service_state as s', 's.serviceid', '=', 'h.id')
            ->where('h.domainstatus', 'Suspended')
            ->where(static function ($q): void {
                $q->whereNull('s.last_status')->orWhere('s.last_status', '!=', 'limited');
            });
        $this->applyScopeFilter($suspendedQuery);
        $suspendedOther = (int) $suspendedQuery->count();

        $lastWhmcsCron = (string) Capsule::table('mod_easydcim_bw_guard_meta')->where('meta_key', 'last_whmcs_cron_at')->value('meta_value');
        $cronOk = $lastWhmcsCron !== '' && strtotime($lastWhmcsCron) > time() - 360;

        return [
            ['label' => $this->t('rt_module_status'), 'value' => $this->settings->getBool('module_enabled', true) ? $this->t('active') : $this->t('disabled'), 'state' => $this->settings->getBool('module_enabled', true) ? 'ok' : 'neutral', 'icon' => '<svg viewBox="0 0 24 24"><path d="M12 2v10"></path><path d="M6 6a8 8 0 1012 0"></path></svg>'],
            ['label' => $this->t('rt_cron_status'), 'value' => $cronOk ? ($this->t('rt_cron_active') . ' (' . $lastWhmcsCron . ')') : $this->t('rt_cron_down'), 'state' => $cronOk ? 'ok' : 'error', 'icon' => '<svg viewBox="0 0 24 24"><path d="M12 6v6l4 2"></path><circle cx="12" cy="12" r="9"></circle></svg>'],
            ['label' => $this->t('rt_traffic_limited'), 'value' => (string) $limitedCount, 'state' => $limitedCount > 0 ? 'warn' : 'ok', 'icon' => '<svg viewBox="0 0 24 24"><path d="M4 20h16M7 16h10M10 12h4M12 4v4"></path></svg>'],
            ['label' => $this->t('rt_synced_last_hour'), 'value' => (string) $syncedInLastHour, 'state' => $syncedInLastHour > 0 ? 'ok' : 'neutral', 'icon' => '<svg viewBox="0 0 24 24"><path d="M3 12h6l3-8 4 16 3-8h2"></path></svg>'],
            ['label' => $this->t('rt_suspended_other'), 'value' => (string) $suspendedOther, 'state' => $suspendedOther > 0 ? 'warn' : 'neutral', 'icon' => '<svg viewBox="0 0 24 24"><path d="M7 11V8a5 5 0 1110 0v3"></path><rect x="5" y="11" width="14" height="10" rx="2"></rect></svg>'],
            ['label' => $this->t('rt_test_mode'), 'value' => $this->settings->getBool('test_mode', false) ? $this->t('rt_test_mode_on') : $this->t('rt_test_mode_off'), 'state' => $this->settings->getBool('test_mode', false) ? 'warn' : 'neutral', 'icon' => '<svg viewBox="0 0 24 24"><path d="M6 2h12M9 2v4l-5 8a4 4 0 003.4 6h9.2A4 4 0 0020 14l-5-8V2"></path></svg>'],
        ];
    }

    private function saveSettings(): array
    {
        $current = Settings::loadFromDatabase();
        $payload = $current;
        $action = (string) ($_POST['action'] ?? '');
        $allowedGeneral = [
            'module_enabled', 'ui_language', 'poll_interval_minutes', 'graph_cache_minutes',
            'servers_test_chunk_size',
            'autobuy_enabled', 'autobuy_threshold_gb', 'autobuy_default_package_id', 'autobuy_max_per_cycle',
            'update_mode', 'traffic_direction_map', 'default_calculation_mode', 'test_mode',
            'log_retention_days', 'preflight_strict_mode', 'purge_on_deactivate',
        ];
        $allowedConnection = [
            'easydcim_base_url', 'use_impersonation',
            'proxy_enabled', 'proxy_type', 'proxy_host', 'proxy_port', 'proxy_username',
            'allow_self_signed',
        ];
        $allowed = $action === 'save_connection' ? $allowedConnection : $allowedGeneral;
        $boolKeys = ['autobuy_enabled', 'preflight_strict_mode', 'purge_on_deactivate', 'test_mode', 'proxy_enabled', 'allow_self_signed'];
        $selectBoolKeys = ['use_impersonation'];

        foreach ($allowed as $key) {
            if (in_array($key, $boolKeys, true)) {
                $payload[$key] = isset($_POST[$key]) ? '1' : '0';
                continue;
            }
            if (in_array($key, $selectBoolKeys, true)) {
                $payload[$key] = ((string) ($_POST[$key] ?? '0')) === '1' ? '1' : '0';
                continue;
            }
            if (!isset($_POST[$key])) {
                continue;
            }
            $payload[$key] = trim((string) $_POST[$key]);
        }

        if ($action === 'save_connection') {
            $newToken = trim((string) ($_POST['easydcim_api_token'] ?? ''));
            if ($newToken !== '') {
                $payload['easydcim_api_token'] = function_exists('encrypt') ? encrypt($newToken) : $newToken;
            } else {
                $payload['easydcim_api_token'] = $current['easydcim_api_token'] ?? '';
            }

            $newProxyPassword = trim((string) ($_POST['proxy_password'] ?? ''));
            if ($newProxyPassword !== '') {
                $payload['proxy_password'] = function_exists('encrypt') ? encrypt($newProxyPassword) : $newProxyPassword;
            } else {
                $payload['proxy_password'] = $current['proxy_password'] ?? '';
            }
        }

        if ((int) ($payload['log_retention_days'] ?? 30) < 1) {
            $payload['log_retention_days'] = '30';
        }
        $chunkSize = (int) ($payload['servers_test_chunk_size'] ?? 1);
        if ($chunkSize < 1) {
            $chunkSize = 1;
        } elseif ($chunkSize > 50) {
            $chunkSize = 50;
        }
        $payload['servers_test_chunk_size'] = (string) $chunkSize;

        Settings::saveToDatabase($payload);
            return ['type' => 'success', 'text' => $this->t('settings_saved')];
    }

    private function testEasyDcimConnection(): array
    {
        try {
            $baseUrl = trim((string) ($_POST['easydcim_base_url'] ?? $this->settings->getString('easydcim_base_url')));
            $storedToken = Crypto::safeDecrypt($this->settings->getString('easydcim_api_token'));
            $token = trim((string) ($_POST['easydcim_api_token'] ?? ''));
            if ($token === '') {
                $token = $storedToken;
            }
            $useImpersonation = ((string) ($_POST['use_impersonation'] ?? ($this->settings->getBool('use_impersonation', false) ? '1' : '0'))) === '1';
            $proxy = [
                'enabled' => isset($_POST['proxy_enabled']) ? true : $this->settings->getBool('proxy_enabled', false),
                'type' => trim((string) ($_POST['proxy_type'] ?? $this->settings->getString('proxy_type', 'http'))),
                'host' => trim((string) ($_POST['proxy_host'] ?? $this->settings->getString('proxy_host'))),
                'port' => (int) ($_POST['proxy_port'] ?? $this->settings->getInt('proxy_port', 0)),
                'username' => trim((string) ($_POST['proxy_username'] ?? $this->settings->getString('proxy_username'))),
                'password' => trim((string) ($_POST['proxy_password'] ?? '')) !== '' ? trim((string) $_POST['proxy_password']) : Crypto::safeDecrypt($this->settings->getString('proxy_password')),
                'allow_self_signed' => isset($_POST['allow_self_signed']) ? true : $this->settings->getBool('allow_self_signed', true),
            ];
            if ($baseUrl === '' || $token === '') {
                return ['type' => 'warning', 'text' => $this->t('base_or_token_missing')];
            }

            $client = new EasyDcimClient($baseUrl, $token, $useImpersonation, $this->logger, $proxy);
            $probe = $client->pingInfo();
            if (!empty($probe['ok'])) {
                $this->storeConnectionRuntimeState(['text' => $this->t('m_connected'), 'state' => 'ok']);
                return ['type' => 'success', 'text' => $this->t('connection_ok')];
            }
            if (!empty($probe['reachable'])) {
                $this->storeConnectionRuntimeState(['text' => $this->t('m_configured_reachable'), 'state' => 'warn']);
                return ['type' => 'success', 'text' => $this->t('connection_reachable_limited') . ' (HTTP ' . (int) ($probe['http_code'] ?? 0) . ')'];
            }
            $extra = trim((string) ($probe['error'] ?? ''));
            $code = (int) ($probe['http_code'] ?? 0);
            if ($extra === '' && $code > 0) {
                $extra = 'HTTP ' . $code;
            }
            $this->storeConnectionRuntimeState(['text' => $this->t('m_configured_disconnected'), 'state' => 'warn']);
            return ['type' => 'warning', 'text' => $this->t('connection_unhealthy') . ($extra !== '' ? (' (' . $extra . ')') : '')];
        } catch (\Throwable $e) {
            $this->storeConnectionRuntimeState(['text' => $this->t('m_configured_disconnected'), 'state' => 'warn']);
            return ['type' => 'danger', 'text' => $this->t('connection_failed') . ': ' . $e->getMessage()];
        }
    }

    private function storeConnectionRuntimeState(array $state): void
    {
        Capsule::table('mod_easydcim_bw_guard_meta')->updateOrInsert(
            ['meta_key' => 'conn_runtime_cache_json'],
            ['meta_value' => json_encode($state, JSON_UNESCAPED_UNICODE), 'updated_at' => date('Y-m-d H:i:s')]
        );
        Capsule::table('mod_easydcim_bw_guard_meta')->updateOrInsert(
            ['meta_key' => 'conn_runtime_cache_at'],
            ['meta_value' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')]
        );
    }

    private function cleanupLogsNow(): array
    {
        try {
            $days = max(1, $this->settings->getInt('log_retention_days', 30));
            $cutoff = date('Y-m-d H:i:s', time() - ($days * 86400));
            $deleted = Capsule::table('mod_easydcim_bw_guard_logs')->where('created_at', '<', $cutoff)->delete();
            return ['type' => 'success', 'text' => 'Log cleanup complete. Removed ' . (int) $deleted . ' rows.'];
        } catch (\Throwable $e) {
            return ['type' => 'danger', 'text' => 'Log cleanup failed: ' . $e->getMessage()];
        }
    }

    private function cleanupLogsAllNow(): array
    {
        try {
            $deleted = Capsule::table('mod_easydcim_bw_guard_logs')->delete();
            return ['type' => 'success', 'text' => 'All logs removed: ' . (int) $deleted];
        } catch (\Throwable $e) {
            return ['type' => 'danger', 'text' => 'Delete all logs failed: ' . $e->getMessage()];
        }
    }

    private function saveScopeSettings(): array
    {
        $current = Settings::loadFromDatabase();
        $current['managed_pids'] = trim((string) ($_POST['managed_pids'] ?? ''));
        $current['managed_gids'] = trim((string) ($_POST['managed_gids'] ?? ''));
        Settings::saveToDatabase($current);
        return ['type' => 'success', 'text' => 'Scope saved.'];
    }

    private function saveProductDefault(): array
    {
        try {
            $pid = (int) ($_POST['pd_pid'] ?? 0);
            $mode = strtoupper(trim((string) ($_POST['pd_mode'] ?? 'TOTAL')));
            $quota = ($_POST['pd_quota_gb'] ?? '') !== '' ? (float) $_POST['pd_quota_gb'] : null;
            $unlimited = isset($_POST['pd_unlimited']) ? 1 : 0;
            $action = trim((string) ($_POST['pd_action'] ?? 'disable_ports'));
            if ($pid <= 0) {
                return ['type' => 'danger', 'text' => 'Invalid PID.'];
            }
            if (!in_array($mode, ['IN', 'OUT', 'TOTAL'], true)) {
                $mode = 'TOTAL';
            }
            if (!in_array($action, ['disable_ports', 'suspend', 'both'], true)) {
                $action = 'disable_ports';
            }

            $row = Capsule::table('mod_easydcim_bw_guard_product_defaults')->where('pid', $pid)->first();
            $data = [
                'pid' => $pid,
                'default_mode' => $mode,
                'default_action' => $action,
                'enabled' => 1,
                'updated_at' => date('Y-m-d H:i:s'),
                'created_at' => date('Y-m-d H:i:s'),
            ];

            if ($mode === 'IN') {
                $data['default_quota_in_gb'] = $quota;
                $data['unlimited_in'] = $unlimited;
            } elseif ($mode === 'OUT') {
                $data['default_quota_out_gb'] = $quota;
                $data['unlimited_out'] = $unlimited;
            } else {
                $data['default_quota_total_gb'] = $quota;
                $data['default_quota_gb'] = $quota ?? 0;
                $data['unlimited_total'] = $unlimited;
            }

            if ($row) {
                Capsule::table('mod_easydcim_bw_guard_product_defaults')->where('id', (int) $row->id)->update($data);
            } else {
                Capsule::table('mod_easydcim_bw_guard_product_defaults')->insert($data);
            }

            return ['type' => 'success', 'text' => 'Plan quota rule saved.'];
        } catch (\Throwable $e) {
            return ['type' => 'danger', 'text' => 'Failed to save plan rule: ' . $e->getMessage()];
        }
    }

    private function saveProductPlan(): array
    {
        try {
            $pid = (int) ($_POST['pd_pid'] ?? 0);
            if ($pid <= 0) {
                return ['type' => 'danger', 'text' => 'Invalid PID.'];
            }

            $action = trim((string) ($_POST['pd_action'] ?? 'disable_ports'));
            if (!in_array($action, ['disable_ports', 'suspend', 'both'], true)) {
                $action = 'disable_ports';
            }
            $row = Capsule::table('mod_easydcim_bw_guard_product_defaults')->where('pid', $pid)->first();
            $mode = $row ? (string) ($row->default_mode ?? 'TOTAL') : strtoupper($this->settings->getString('default_calculation_mode', 'TOTAL'));
            if (!in_array($mode, ['IN', 'OUT', 'TOTAL'], true)) {
                $mode = 'TOTAL';
            }

            $data = [
                'pid' => $pid,
                'default_mode' => $mode,
                'default_action' => $action,
                'default_quota_in_gb' => ($_POST['pd_quota_in_gb'] ?? '') !== '' ? (float) $_POST['pd_quota_in_gb'] : null,
                'default_quota_out_gb' => ($_POST['pd_quota_out_gb'] ?? '') !== '' ? (float) $_POST['pd_quota_out_gb'] : null,
                'default_quota_total_gb' => ($_POST['pd_quota_total_gb'] ?? '') !== '' ? (float) $_POST['pd_quota_total_gb'] : null,
                'default_quota_gb' => ($_POST['pd_quota_total_gb'] ?? '') !== '' ? (float) $_POST['pd_quota_total_gb'] : 0,
                'unlimited_in' => isset($_POST['pd_unlimited_in']) ? 1 : 0,
                'unlimited_out' => isset($_POST['pd_unlimited_out']) ? 1 : 0,
                'unlimited_total' => isset($_POST['pd_unlimited_total']) ? 1 : 0,
                'enabled' => 1,
                'updated_at' => date('Y-m-d H:i:s'),
                'created_at' => date('Y-m-d H:i:s'),
            ];

            if ($row) {
                Capsule::table('mod_easydcim_bw_guard_product_defaults')->where('id', (int) $row->id)->update($data);
            } else {
                Capsule::table('mod_easydcim_bw_guard_product_defaults')->insert($data);
            }

            return ['type' => 'success', 'text' => 'Product plan saved for PID ' . $pid];
        } catch (\Throwable $e) {
            return ['type' => 'danger', 'text' => 'Failed to save product plan: ' . $e->getMessage()];
        }
    }

    private function testServiceItem(): array
    {
        try {
            $serviceId = (int) ($_POST['test_serviceid'] ?? 0);
            $bulkMode = (string) ($_POST['__bulk_test'] ?? '0') === '1';
            if ($serviceId <= 0) {
                return ['type' => 'danger', 'text' => $this->t('invalid_service')];
            }

            $baseUrl = $this->settings->getString('easydcim_base_url');
            $token = Crypto::safeDecrypt($this->settings->getString('easydcim_api_token'));
            if ($baseUrl === '' || $token === '') {
                return ['type' => 'warning', 'text' => $this->t('base_or_token_missing')];
            }

            $rows = $this->getScopedHostingServices($this->getEasyServicesCacheOnly(), false, false);
            $target = null;
            foreach ($rows as $row) {
                if ((int) ($row['serviceid'] ?? 0) === $serviceId) {
                    $target = $row;
                    break;
                }
            }
            if (!$target) {
                return ['type' => 'warning', 'text' => $this->t('service_not_found_scope')];
            }

            $useImpersonation = $this->settings->getBool('use_impersonation', false);
            $client = new EasyDcimClient($baseUrl, $token, $useImpersonation, $this->logger, $this->proxyConfig());
            $email = (string) ($target['email'] ?? '');
            $response = ['http_code' => 0, 'error' => ''];
            $mode = 'none';

            $serviceCandidates = [];
            $addCandidate = static function (array &$list, string $serviceId, string $source): void {
                $serviceId = trim($serviceId);
                if ($serviceId === '') {
                    return;
                }
                foreach ($list as $row) {
                    if ((string) ($row['id'] ?? '') === $serviceId) {
                        return;
                    }
                }
                $list[] = ['id' => $serviceId, 'source' => $source];
            };
            $addCandidate($serviceCandidates, (string) ($target['easydcim_service_id'] ?? ''), 'service_cf');
            $orderId = trim((string) ($target['easydcim_order_id'] ?? ''));
            $serverId = trim((string) ($target['easydcim_server_id'] ?? ''));
            $ip = trim((string) ($target['ip'] ?? ''));
            $portsEndpointMode = trim((string) Capsule::table('mod_easydcim_bw_guard_meta')->where('meta_key', 'ports_endpoint_mode')->value('meta_value'));
            $orderPortPrimary = ['ok' => false, 'reachable' => false, 'items' => []];
            $orderPortLoaded = false;
            $orderOnlyFallback = false;
            if (!$bulkMode && $orderId === '' && $serverId !== '') {
                $orderId = $this->resolveOrderIdFromServer($client, $serverId, $ip);
            }
            foreach ($this->getEasyServicesCacheOnly() as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $itemService = trim((string) ($item['service_id'] ?? ''));
                if ($itemService === '') {
                    continue;
                }
                if ($orderId !== '' && trim((string) ($item['order_id'] ?? '')) === $orderId) {
                    $addCandidate($serviceCandidates, $itemService, 'order_cache');
                }
                if ($serverId !== '' && trim((string) ($item['server_id'] ?? '')) === $serverId) {
                    $addCandidate($serviceCandidates, $itemService, 'server_cache');
                }
                if ($ip !== '' && trim((string) ($item['ip'] ?? '')) === $ip) {
                    $addCandidate($serviceCandidates, $itemService, 'ip_cache');
                }
            }
            if ($orderId !== '' && !$bulkMode && empty($serviceCandidates)) {
                $addCandidate($serviceCandidates, $this->resolveServiceIdFromOrder($client, $orderId, false), 'order_api');
            }

            // Prefer order-details when server/order mapping exists (more stable in restricted client endpoints).
            if ($orderId !== '' && $serverId !== '') {
                $orderPortPrimary = $this->portsFromOrderDetails($client, $orderId);
                $orderPortLoaded = true;
                if (!empty($orderPortPrimary['ok'])) {
                    $response = ['http_code' => 200, 'data' => ['ports' => $orderPortPrimary['items']], 'error' => ''];
                    $mode = 'order_details_ports:server_order';
                }
            }

            if ($bulkMode && $mode === 'none' && $orderId !== '') {
                if (!$orderPortLoaded) {
                    $orderPortPrimary = $this->portsFromOrderDetails($client, $orderId);
                    $orderPortLoaded = true;
                }
                if (!empty($orderPortPrimary['ok'])) {
                    $response = ['http_code' => 200, 'data' => ['ports' => $orderPortPrimary['items']], 'error' => ''];
                    $mode = 'order_details_ports:bulk';
                } elseif ($portsEndpointMode === 'order_only' || !empty($orderPortPrimary['reachable'])) {
                    $response = ['http_code' => 200, 'data' => ['ports' => []], 'error' => ''];
                    $mode = 'order_id_only:bulk';
                    $orderOnlyFallback = true;
                }
            }

            if (!$bulkMode && $mode === 'none' && $orderId !== '' && $portsEndpointMode === 'order_only') {
                if (!$orderPortLoaded) {
                    $orderPortPrimary = $this->portsFromOrderDetails($client, $orderId);
                    $orderPortLoaded = true;
                }
                if (!empty($orderPortPrimary['ok'])) {
                    $response = ['http_code' => 200, 'data' => ['ports' => $orderPortPrimary['items']], 'error' => ''];
                    $mode = 'order_details_ports:order_only';
                } elseif (!empty($orderPortPrimary['reachable'])) {
                    $response = ['http_code' => 200, 'data' => ['ports' => []], 'error' => ''];
                    $mode = 'order_id_only';
                    $orderOnlyFallback = true;
                }
            }

            if ($mode === 'none' || $orderOnlyFallback) {
                foreach ($serviceCandidates as $candidate) {
                    if ($mode !== 'none') {
                        if (!$orderOnlyFallback) {
                            break;
                        }
                        if (!str_starts_with((string) $mode, 'order_id_only')) {
                            break;
                        }
                    }
                    if ($mode !== 'none' && str_starts_with((string) $mode, 'order_details_ports')) {
                        break;
                    }
                    $candidateId = (string) ($candidate['id'] ?? '');
                    $candidateSource = (string) ($candidate['source'] ?? 'candidate');
                    $candidateResp = $client->ports($candidateId, true, $email, false);
                    $candidateCode = (int) ($candidateResp['http_code'] ?? 0);
                    if ($candidateCode >= 200 && $candidateCode < 300) {
                        $response = $candidateResp;
                        $mode = 'service_id:' . $candidateSource;
                        break;
                    }
                    if (($candidateCode === 401 || $candidateCode === 403 || $candidateCode === 422) && $useImpersonation) {
                        $fallbackClient = new EasyDcimClient($baseUrl, $token, false, $this->logger, $this->proxyConfig());
                        $fallbackResp = $fallbackClient->ports($candidateId, true, null, false);
                        $fallbackCode = (int) ($fallbackResp['http_code'] ?? 0);
                        if ($fallbackCode >= 200 && $fallbackCode < 300) {
                            $response = $fallbackResp;
                            $mode = 'service_id:' . $candidateSource . ':no_impersonation';
                            $orderOnlyFallback = false;
                            break;
                        }
                        if ($fallbackCode !== 0) {
                            $candidateResp = $fallbackResp;
                            $candidateCode = $fallbackCode;
                        }
                    }
                    if ((int) ($response['http_code'] ?? 0) === 0 || $candidateCode > (int) ($response['http_code'] ?? 0)) {
                        $response = $candidateResp;
                    }
                }
            }

            if (($mode === 'none' || $orderOnlyFallback) && $serverId !== '') {
                $serverResp = $client->portsByServer($serverId, true, $email);
                $serverCode = (int) ($serverResp['http_code'] ?? 0);
                if (($serverCode === 401 || $serverCode === 403 || $serverCode === 422) && $useImpersonation) {
                    $fallbackClient = new EasyDcimClient($baseUrl, $token, false, $this->logger, $this->proxyConfig());
                    $fallbackServerResp = $fallbackClient->portsByServer($serverId, true, null);
                    $fallbackServerCode = (int) ($fallbackServerResp['http_code'] ?? 0);
                    if ($fallbackServerCode >= 200 && $fallbackServerCode < 300) {
                        $serverResp = $fallbackServerResp;
                        $serverCode = $fallbackServerCode;
                    }
                }
                if ($serverCode >= 200 && $serverCode < 300) {
                    $response = $serverResp;
                    $mode = $bulkMode ? 'server_id:bulk' : 'server_id';
                    $orderOnlyFallback = false;
                } elseif ((int) ($response['http_code'] ?? 0) === 0) {
                    $response = $serverResp;
                    $mode = 'server_id_only';
                }
            }
            $code = (int) ($response['http_code'] ?? 0);
            $err = trim((string) ($response['error'] ?? ''));
            $orderIdForFallback = $orderId;
            $orderDetailsReachable = false;
            if (($code === 401 || $code === 403 || $code === 404 || $code === 422 || $code === 0) && $orderIdForFallback !== '') {
                if (!$orderPortLoaded) {
                    $orderPortPrimary = $this->portsFromOrderDetails($client, $orderIdForFallback);
                    $orderPortLoaded = true;
                }
                $orderPortFallback = $orderPortPrimary;
                $orderDetailsReachable = !empty($orderPortFallback['reachable']);
                if (!empty($orderPortFallback['ok'])) {
                    $response = ['http_code' => 200, 'data' => ['ports' => $orderPortFallback['items']], 'error' => ''];
                    $code = 200;
                    $err = '';
                    $mode = 'order_details_ports';
                }
            }
            if (($code === 401 || $code === 403 || $code === 422) && $orderIdForFallback !== '' && $mode === 'none' && $orderDetailsReachable) {
                // If order is reachable in admin API but client ports endpoint is restricted/inactive, do not hard-fail.
                $prevCode = $code;
                    $response = ['http_code' => 200, 'data' => ['ports' => []], 'error' => ''];
                    $code = 200;
                    $err = '';
                    $mode = 'order_details_only';
                    Capsule::table('mod_easydcim_bw_guard_meta')->updateOrInsert(
                        ['meta_key' => 'ports_endpoint_mode'],
                        ['meta_value' => 'order_only', 'updated_at' => date('Y-m-d H:i:s')]
                    );
                    $this->logger->log('INFO', 'server_item_test_order_only', [
                        'serviceid' => $serviceId,
                        'order_id' => $orderIdForFallback,
                        'prev_http_code' => $prevCode,
                    ]);
            }
            if ($code === 422 && $orderIdForFallback !== '' && $mode === 'none') {
                // Final safety net: never block queue on 422 when order mapping exists.
                $prevCode = $code;
                $response = ['http_code' => 200, 'data' => ['ports' => []], 'error' => ''];
                $code = 200;
                $err = '';
                $mode = 'order_id_only';
                Capsule::table('mod_easydcim_bw_guard_meta')->updateOrInsert(
                    ['meta_key' => 'ports_endpoint_mode'],
                    ['meta_value' => 'order_only', 'updated_at' => date('Y-m-d H:i:s')]
                );
                $this->logger->log('INFO', 'server_item_test_order_only_forced', [
                    'serviceid' => $serviceId,
                    'order_id' => $orderIdForFallback,
                    'prev_http_code' => $prevCode,
                ]);
            }
            $ok = $code >= 200 && $code < 300;
            $statusType = 'warning';
            $summary = $this->t('no_data');
            $statusText = $this->t('test_failed') . ' (HTTP ' . $code . ($err !== '' ? ', ' . $err : '') . ')';

            $items = $this->extractPortItems((array) ($response['data'] ?? []));
            $totalPorts = count($items);
            $networkPorts = 0;
            $networkUp = 0;
            $networkTraffic = 0.0;
            $portIds = [];
            $connectedPortIds = [];
            $connectedItemIds = [];
            $portRows = [];
            foreach ($items as $p) {
                if (!$this->isNetworkPortCandidate((string) ($p['name'] ?? ''), (string) ($p['description'] ?? ''), (string) ($p['type'] ?? ''))) {
                    continue;
                }
                $networkPorts++;
                $portState = strtolower(trim((string) ($p['state'] ?? '')));
                if (!empty($p['is_up']) || in_array($portState, ['up', 'idle'], true)) {
                    $networkUp++;
                }
                $networkTraffic += (float) ($p['traffic_total'] ?? 0.0);
                $pid = trim((string) ($p['port_id'] ?? ''));
                $cpid = trim((string) ($p['connected_port_id'] ?? ''));
                $ciid = trim((string) ($p['connected_item_id'] ?? ''));
                if ($pid !== '') {
                    $portIds[$pid] = true;
                }
                if ($cpid !== '') {
                    $connectedPortIds[$cpid] = true;
                }
                if ($ciid !== '') {
                    $connectedItemIds[$ciid] = true;
                }
                $portRows[] = [
                    'name' => (string) ($p['name'] ?? ''),
                    'description' => (string) ($p['description'] ?? ''),
                    'state' => (string) ($p['state'] ?? ''),
                    'is_up' => !empty($p['is_up']),
                    'traffic_total' => (float) ($p['traffic_total'] ?? 0.0),
                    'speed' => (string) ($p['speed'] ?? ''),
                    'port_id' => $pid,
                    'connected_port_id' => $cpid,
                    'connected_item_id' => $ciid,
                    'connected_port_label' => (string) ($p['connected_port_label'] ?? ''),
                    'connected_item_label' => (string) ($p['connected_item_label'] ?? ''),
                ];
            }
            if (!empty($portRows)) {
                $portRows = $this->enrichPortRowsWithPortDetails($client, $portRows);
                $networkPorts = count($portRows);
                $networkUp = 0;
                $networkTraffic = 0.0;
                $portIds = [];
                $connectedPortIds = [];
                $connectedItemIds = [];
                foreach ($portRows as $row) {
                    $rowState = strtolower(trim((string) ($row['state'] ?? '')));
                    if (!empty($row['is_up']) || in_array($rowState, ['up', 'idle'], true)) {
                        $networkUp++;
                    }
                    $networkTraffic += (float) ($row['traffic_total'] ?? 0.0);
                    $pid = trim((string) ($row['port_id'] ?? ''));
                    $cpid = trim((string) ($row['connected_port_id'] ?? ''));
                    $ciid = trim((string) ($row['connected_item_id'] ?? ''));
                    if ($pid !== '') {
                        $portIds[$pid] = true;
                    }
                    if ($cpid !== '') {
                        $connectedPortIds[$cpid] = true;
                    }
                    if ($ciid !== '') {
                        $connectedItemIds[$ciid] = true;
                    }
                }
            }

            if (($mode === 'none' || $mode === 'server_id_only') && $err === '') {
                $err = $this->isFa ? 'Service ID از روی Order ID پیدا نشد' : 'Service ID was not resolved from order';
                $statusText = $this->t('test_failed') . ' (HTTP ' . $code . ', ' . $err . ')';
            } elseif ($mode === 'server_id_only' && $code === 404 && $err === 'No server port endpoint matched') {
                $statusText = $this->t('test_failed') . ' (HTTP ' . $code . ', ' . $this->t('server_ports_not_supported') . ')';
            } elseif (($code === 401 || $code === 403) && $err === '') {
                $err = $this->isFa ? 'عدم دسترسی به endpoint پورت‌ها با توکن/حالت فعلی' : 'Access denied for ports endpoint with current token/mode';
                $statusText = $this->t('test_failed') . ' (HTTP ' . $code . ', ' . $err . ')';
            } elseif ($code === 422 && $err === '') {
                $err = $this->isFa ? 'Service ID معتبر نیست یا برای endpoint پورت‌ها قابل استفاده نیست' : 'Service ID is not valid for ports endpoint';
                $statusText = $this->t('test_failed') . ' (HTTP ' . $code . ', ' . $err . ')';
            } elseif ($ok) {
                if ($networkPorts > 0) {
                    $summary = $networkUp . '/' . $networkPorts . ' ' . $this->t('ports_up');
                    $statusType = 'success';
                    $statusText = $this->t('test_ok') . ' (HTTP ' . $code . ', ' . $summary . ', ' . $this->t('mode') . ': ' . $mode . ')';
                } elseif ($totalPorts > 0) {
                    $summary = $this->t('network_ports_not_found');
                    $statusType = 'warning';
                    $statusText = $this->t('test_ok') . ' (HTTP ' . $code . ', ' . $this->t('network_ports_not_found') . ')';
                } else {
                    $summary = $this->t('ports_not_found');
                    $statusType = 'warning';
                    $statusText = $this->t('test_ok') . ' (HTTP ' . $code . ', ' . $this->t('ports_not_found') . ')';
                }
            }

            $this->logger->log($ok ? 'INFO' : 'WARNING', 'server_item_test', [
                'serviceid' => $serviceId,
                'mode' => $mode,
                'http_code' => $code,
                'error' => $err,
                'total_ports' => $totalPorts,
                'network_ports' => $networkPorts,
                'network_ports_up' => $networkUp,
                'network_traffic_total' => $networkTraffic,
                'port_ids' => array_values(array_keys($portIds)),
                'connected_port_ids' => array_values(array_keys($connectedPortIds)),
                'connected_item_ids' => array_values(array_keys($connectedItemIds)),
                'connected_port_labels' => array_values(array_unique(array_filter(array_map(static fn (array $r): string => trim((string) ($r['connected_port_label'] ?? '')), $portRows)))),
                'easydcim_service_id' => (string) ($target['easydcim_service_id'] ?? ''),
                'easydcim_server_id' => (string) ($target['easydcim_server_id'] ?? ''),
                'easydcim_order_id' => (string) ($target['easydcim_order_id'] ?? ''),
            ]);
            $this->storeServiceTestCache($serviceId, [
                'summary' => $summary,
                'type' => $statusType,
                'http_code' => $code,
                'mode' => $mode,
                'port_rows' => array_slice($portRows, 0, 30),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
            return ['type' => $statusType, 'text' => $statusText];
        } catch (\Throwable $e) {
            $this->logger->log('ERROR', 'server_item_test_exception', ['error' => $e->getMessage()]);
            return ['type' => 'danger', 'text' => $this->t('test_failed') . ': ' . $e->getMessage()];
        }
    }

    private function storeServiceTestCache(int $serviceId, array $payload): void
    {
        Capsule::table('mod_easydcim_bw_guard_meta')->updateOrInsert(
            ['meta_key' => 'service_test_cache_' . $serviceId],
            ['meta_value' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'updated_at' => date('Y-m-d H:i:s')]
        );
    }

    private function getServiceTestCache(int $serviceId): ?array
    {
        $raw = (string) Capsule::table('mod_easydcim_bw_guard_meta')
            ->where('meta_key', 'service_test_cache_' . $serviceId)
            ->value('meta_value');
        if ($raw === '') {
            return null;
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : null;
    }

    private function autoRefreshReleaseStatus(): void
    {
        try {
            $last = (string) Capsule::table('mod_easydcim_bw_guard_meta')->where('meta_key', 'release_last_auto_check_at')->value('meta_value');
            if ($last !== '' && strtotime($last) > time() - 300) {
                return;
            }
            $release = $this->fetchLatestRelease(self::RELEASE_REPO, 5);
            $latestTag = (string) ($release['tag_name'] ?? '');
            if ($latestTag === '') {
                return;
            }
            $latestVersion = ltrim($latestTag, 'vV');
            $currentVersion = Version::current($this->moduleDir)['module_version'];
            $available = $this->compareVersion($latestVersion, $currentVersion) > 0;
            Capsule::table('mod_easydcim_bw_guard_meta')->updateOrInsert(
                ['meta_key' => 'release_latest_tag'],
                ['meta_value' => $latestTag, 'updated_at' => date('Y-m-d H:i:s')]
            );
            Capsule::table('mod_easydcim_bw_guard_meta')->updateOrInsert(
                ['meta_key' => 'release_latest_zip'],
                ['meta_value' => (string) $this->extractZipUrl($release), 'updated_at' => date('Y-m-d H:i:s')]
            );
            Capsule::table('mod_easydcim_bw_guard_meta')->updateOrInsert(
                ['meta_key' => 'release_update_available'],
                ['meta_value' => $available ? '1' : '0', 'updated_at' => date('Y-m-d H:i:s')]
            );
            Capsule::table('mod_easydcim_bw_guard_meta')->updateOrInsert(
                ['meta_key' => 'release_source_status'],
                ['meta_value' => 'ok', 'updated_at' => date('Y-m-d H:i:s')]
            );
            Capsule::table('mod_easydcim_bw_guard_meta')->updateOrInsert(
                ['meta_key' => 'release_last_auto_check_at'],
                ['meta_value' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')]
            );
        } catch (\Throwable $e) {
            Capsule::table('mod_easydcim_bw_guard_meta')->updateOrInsert(
                ['meta_key' => 'release_last_auto_check_at'],
                ['meta_value' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')]
            );
            Capsule::table('mod_easydcim_bw_guard_meta')->updateOrInsert(
                ['meta_key' => 'release_source_status'],
                ['meta_value' => 'error', 'updated_at' => date('Y-m-d H:i:s')]
            );
            $this->logger->log('WARNING', 'release_auto_refresh_failed', ['error' => $e->getMessage()]);
        }
    }

    private function getConnectionRuntimeState(): array
    {
        $baseUrl = $this->settings->getString('easydcim_base_url');
        $token = Crypto::safeDecrypt($this->settings->getString('easydcim_api_token'));
        if ($baseUrl === '' || $token === '') {
            return ['text' => $this->t('m_not_configured'), 'state' => 'error'];
        }

        $nowTs = time();
        $cacheAt = (string) Capsule::table('mod_easydcim_bw_guard_meta')->where('meta_key', 'conn_runtime_cache_at')->value('meta_value');
        $cacheJson = (string) Capsule::table('mod_easydcim_bw_guard_meta')->where('meta_key', 'conn_runtime_cache_json')->value('meta_value');
        $cachedState = null;
        if ($cacheAt !== '' && strtotime($cacheAt) > $nowTs - 60 && $cacheJson !== '') {
            $decoded = json_decode($cacheJson, true);
            if (is_array($decoded) && isset($decoded['text'], $decoded['state'])) {
                return ['text' => (string) $decoded['text'], 'state' => (string) $decoded['state']];
            }
        }
        if ($cacheJson !== '') {
            $decoded = json_decode($cacheJson, true);
            if (is_array($decoded) && isset($decoded['text'], $decoded['state'])) {
                $cachedState = ['text' => (string) $decoded['text'], 'state' => (string) $decoded['state']];
            }
        }

        $probeAt = (string) Capsule::table('mod_easydcim_bw_guard_meta')->where('meta_key', 'conn_runtime_probe_at')->value('meta_value');
        if ($probeAt !== '' && strtotime($probeAt) > $nowTs - 90 && is_array($cachedState)) {
            return $cachedState;
        }
        Capsule::table('mod_easydcim_bw_guard_meta')->updateOrInsert(
            ['meta_key' => 'conn_runtime_probe_at'],
            ['meta_value' => date('Y-m-d H:i:s', $nowTs), 'updated_at' => date('Y-m-d H:i:s', $nowTs)]
        );

        $recentState = $this->connectionStateFromRecentApiLogs($nowTs);
        if (is_array($recentState)) {
            $this->storeConnectionRuntimeState($recentState);
            return $recentState;
        }

        try {
            $client = $this->makeEasyDcimClient();
            $probe = $client->pingInfo(3);
            if (!empty($probe['ok'])) {
                $state = ['text' => $this->t('m_connected'), 'state' => 'ok'];
                $this->storeConnectionRuntimeState($state);
                return $state;
            }
            if (!empty($probe['reachable'])) {
                $state = ['text' => $this->t('m_configured_reachable'), 'state' => 'warn'];
                $this->storeConnectionRuntimeState($state);
                return $state;
            }

            $state = ['text' => $this->t('m_configured_disconnected'), 'state' => 'warn'];
            $this->storeConnectionRuntimeState($state);
            return is_array($cachedState) ? $cachedState : $state;
        } catch (\Throwable $e) {
            return is_array($cachedState)
                ? $cachedState
                : ['text' => $this->t('m_configured_disconnected'), 'state' => 'warn'];
        }
    }

    private function connectionStateFromRecentApiLogs(int $nowTs): ?array
    {
        try {
            $since = date('Y-m-d H:i:s', $nowTs - 300);
            $rows = Capsule::table('mod_easydcim_bw_guard_logs')
                ->where('message', 'easydcim_api_call')
                ->where('created_at', '>=', $since)
                ->orderBy('id', 'desc')
                ->limit(20)
                ->get(['details_json']);

            $hasReachable = false;
            foreach ($rows as $row) {
                $payload = json_decode((string) ($row->details_json ?? ''), true);
                if (!is_array($payload)) {
                    continue;
                }
                $code = (int) ($payload['http_code'] ?? 0);
                if ($code >= 200 && $code < 300) {
                    return ['text' => $this->t('m_connected'), 'state' => 'ok'];
                }
                if ($code >= 300 && $code < 500) {
                    $hasReachable = true;
                }
            }

            if ($hasReachable) {
                return ['text' => $this->t('m_configured_reachable'), 'state' => 'warn'];
            }
        } catch (\Throwable $e) {
            // Ignore runtime status inference failures; dashboard must stay responsive.
        }

        return null;
    }

    private function checkReleaseUpdate(): array
    {
        try {
            $repo = self::RELEASE_REPO;
            $release = $this->fetchLatestRelease($repo, 5);
            $latestTag = (string) ($release['tag_name'] ?? '');
            $latestVersion = ltrim($latestTag, 'vV');
            $currentVersion = Version::current($this->moduleDir)['module_version'];
            $available = $this->compareVersion($latestVersion, $currentVersion) > 0;

            Capsule::table('mod_easydcim_bw_guard_meta')->updateOrInsert(
                ['meta_key' => 'release_latest_tag'],
                ['meta_value' => $latestTag, 'updated_at' => date('Y-m-d H:i:s')]
            );
            Capsule::table('mod_easydcim_bw_guard_meta')->updateOrInsert(
                ['meta_key' => 'release_latest_zip'],
                ['meta_value' => (string) $this->extractZipUrl($release), 'updated_at' => date('Y-m-d H:i:s')]
            );
            Capsule::table('mod_easydcim_bw_guard_meta')->updateOrInsert(
                ['meta_key' => 'release_update_available'],
                ['meta_value' => $available ? '1' : '0', 'updated_at' => date('Y-m-d H:i:s')]
            );
            Capsule::table('mod_easydcim_bw_guard_meta')->updateOrInsert(
                ['meta_key' => 'release_source_status'],
                ['meta_value' => 'ok', 'updated_at' => date('Y-m-d H:i:s')]
            );

            return ['type' => 'success', 'text' => $available ? ('New release found: ' . $latestTag) : 'No newer release found.'];
        } catch (\Throwable $e) {
            Capsule::table('mod_easydcim_bw_guard_meta')->updateOrInsert(
                ['meta_key' => 'release_source_status'],
                ['meta_value' => 'error', 'updated_at' => date('Y-m-d H:i:s')]
            );
            return ['type' => 'danger', 'text' => 'Release check failed: ' . $e->getMessage()];
        }
    }

    private function applyReleaseUpdate(): array
    {
        try {
            if (function_exists('set_time_limit')) {
                @set_time_limit(45);
            }
            if (!class_exists(\ZipArchive::class)) {
                throw new \RuntimeException('ZipArchive extension is required.');
            }
            $addonDir = realpath($this->moduleDir);
            if ($addonDir === false || !is_dir($addonDir)) {
                throw new \RuntimeException('Addon path is not accessible.');
            }
            if (!is_writable($addonDir)) {
                throw new \RuntimeException('Addon directory is not writable: ' . $addonDir);
            }

            Capsule::table('mod_easydcim_bw_guard_meta')->updateOrInsert(
                ['meta_key' => 'update_in_progress'],
                ['meta_value' => '1', 'updated_at' => date('Y-m-d H:i:s')]
            );

            $repo = self::RELEASE_REPO;
            $release = $this->fetchLatestRelease($repo, 5);
            $zipUrl = $this->extractZipUrl($release);
            if ($zipUrl === '') {
                throw new \RuntimeException('No ZIP asset found in latest release.');
            }

            $tmpZip = tempnam(sys_get_temp_dir(), 'edbw_rel_');
            if ($tmpZip === false) {
                throw new \RuntimeException('Could not allocate temp file.');
            }
            $this->downloadFile($zipUrl, $tmpZip, 20);
            $written = $this->extractAddonFromZip($tmpZip);
            @unlink($tmpZip);
            if ($written <= 0) {
                throw new \RuntimeException('No addon files were written. Check filesystem permissions for modules/addons/easydcim_bw.');
            }

            $latestVersion = ltrim((string) ($release['tag_name'] ?? ''), 'vV');
            $installedVersion = (string) (Version::current($this->moduleDir)['module_version'] ?? '');
            if ($latestVersion !== '' && $this->compareVersion($installedVersion, $latestVersion) < 0) {
                throw new \RuntimeException(
                    'Update copy did not apply to active module path (installed=' . $installedVersion . ', expected=' . $latestVersion . ').'
                );
            }

            Capsule::table('mod_easydcim_bw_guard_meta')->updateOrInsert(
                ['meta_key' => 'release_update_available'],
                ['meta_value' => '0', 'updated_at' => date('Y-m-d H:i:s')]
            );
            Capsule::table('mod_easydcim_bw_guard_meta')->updateOrInsert(
                ['meta_key' => 'release_apply_requested'],
                ['meta_value' => '0', 'updated_at' => date('Y-m-d H:i:s')]
            );
            $this->logger->log('INFO', 'release_update_applied_click', [
                'tag' => (string) ($release['tag_name'] ?? ''),
                'zip_url' => $zipUrl,
            ]);
            return ['type' => 'success', 'text' => $this->isFa ? 'آپدیت فوری با موفقیت اعمال شد.' : 'Release update applied immediately.'];
        } catch (\Throwable $e) {
            $this->logger->log('ERROR', 'release_update_apply_click_failed', ['error' => $e->getMessage()]);
            return ['type' => 'danger', 'text' => ($this->isFa ? 'آپدیت فوری ناموفق بود' : 'Immediate release update failed') . ': ' . $e->getMessage()];
        } finally {
            Capsule::table('mod_easydcim_bw_guard_meta')->updateOrInsert(
                ['meta_key' => 'update_in_progress'],
                ['meta_value' => '0', 'updated_at' => date('Y-m-d H:i:s')]
            );
        }
    }

    private function fetchLatestRelease(string $repo, int $timeout = 30): array
    {
        $repo = trim($repo);
        if (!preg_match('/^[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+$/', $repo)) {
            throw new \RuntimeException('GitHub repo format must be owner/repo.');
        }
        $url = 'https://api.github.com/repos/' . $repo . '/releases/latest';
        $response = $this->httpGetJson($url, $timeout);
        if (!isset($response['tag_name'])) {
            throw new \RuntimeException('GitHub latest release payload is invalid.');
        }
        return $response;
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

    private function httpGetJson(string $url, int $timeout = 30): array
    {
        if (!function_exists('curl_init')) {
            throw new \RuntimeException('cURL extension is required.');
        }

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, min(5, max(1, $timeout)));
        curl_setopt($ch, CURLOPT_TIMEOUT, max(1, $timeout));
        curl_setopt($ch, CURLOPT_LOW_SPEED_LIMIT, 1);
        curl_setopt($ch, CURLOPT_LOW_SPEED_TIME, max(3, min(8, $timeout)));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['User-Agent: EasyDcim-BW', 'Accept: application/vnd.github+json']);
        $raw = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($code < 200 || $code >= 300) {
            throw new \RuntimeException('HTTP ' . $code . ' ' . $err);
        }

        $data = json_decode((string) $raw, true);
        if (!is_array($data)) {
            throw new \RuntimeException('Invalid JSON response.');
        }
        return $data;
    }

    private function downloadFile(string $url, string $target, int $timeout = 25): void
    {
        $fh = fopen($target, 'wb');
        if ($fh === false) {
            throw new \RuntimeException('Cannot open temp file for writing.');
        }

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_FILE, $fh);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($ch, CURLOPT_TIMEOUT, max(5, $timeout));
        curl_setopt($ch, CURLOPT_LOW_SPEED_LIMIT, 1);
        curl_setopt($ch, CURLOPT_LOW_SPEED_TIME, 8);
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

    private function extractAddonFromZip(string $zipPath): int
    {
        $zip = new \ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new \RuntimeException('Cannot open downloaded ZIP.');
        }

        $whmcsRoot = realpath($this->moduleDir . '/../../../');
        if ($whmcsRoot === false) {
            $zip->close();
            throw new \RuntimeException('Cannot resolve WHMCS root path.');
        }

        $writtenFiles = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (!is_string($name) || strpos($name, 'modules/addons/easydcim_bw/') !== 0) {
                continue;
            }
            $target = $whmcsRoot . '/' . $name;
            if (substr($name, -1) === '/') {
                if (!is_dir($target)) {
                    mkdir($target, 0755, true);
                }
                continue;
            }

            $dir = dirname($target);
            if (!is_dir($dir)) {
                if (!mkdir($dir, 0755, true) && !is_dir($dir)) {
                    $zip->close();
                    throw new \RuntimeException('Cannot create directory: ' . $dir);
                }
            }
            @chmod($dir, 0755);
            $content = $zip->getFromIndex($i);
            if ($content === false) {
                continue;
            }
            if (is_file($target) && !is_writable($target)) {
                @chmod($target, 0644);
            }
            if (file_put_contents($target, $content) === false) {
                $zip->close();
                throw new \RuntimeException('Cannot write file: ' . $target);
            }
            $writtenFiles++;
        }
        $zip->close();
        return $writtenFiles;
    }

    private function compareVersion(string $a, string $b): int
    {
        $normalize = static function (string $v): array {
            if (!preg_match('/^(\\d+)\\.(\\d{1,2})$/', trim($v), $m)) {
                return [0, 0];
            }
            return [(int) $m[1], (int) $m[2]];
        };
        [$aMaj, $aMin] = $normalize($a);
        [$bMaj, $bMin] = $normalize($b);
        return ($aMaj <=> $bMaj) ?: ($aMin <=> $bMin);
    }

    private function applyOneClickUpdate(): void
    {
        $manager = new GitUpdateManager($this->moduleDir, $this->logger);
        try {
            $origin = $this->settings->getString('git_origin_url');
            $branch = $this->settings->getString('git_branch', 'main');
            $manager->applyOneClickUpdate($origin, $branch);
            echo '<div class="alert alert-success">Update applied successfully.</div>';
        } catch (\Throwable $e) {
            $this->logger->log('ERROR', 'update_apply_failed', ['error' => $e->getMessage()]);
            echo '<div class="alert alert-danger">Update failed: ' . htmlspecialchars($e->getMessage()) . '</div>';
        }
    }

    private function getPurchaseLogs(int $limit = 200): array
    {
        return Capsule::table('mod_easydcim_bw_guard_purchases')
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(static fn ($r): array => (array) $r)
            ->all();
    }

    private function getEnforcementLogs(int $limit = 200): array
    {
        return Capsule::table('mod_easydcim_bw_guard_logs')
            ->whereIn('message', ['traffic_enforced', 'traffic_unlocked', 'service_poll_failed', 'test_mode_action'])
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(static fn ($r): array => (array) $r)
            ->all();
    }

    private function getSystemLogs(int $limit = 400, array $filters = []): array
    {
        return $this->buildSystemLogsQuery($filters)
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(static fn ($r): array => (array) $r)
            ->all();
    }

    private function getSystemLogsPaged(int $page, int $perPage, array $filters = []): array
    {
        $page = max(1, $page);
        $perPage = max(1, $perPage);
        $query = $this->buildSystemLogsQuery($filters);
        $total = (int) $query->count();
        $pages = max(1, (int) ceil($total / $perPage));
        $page = min($page, $pages);
        $rows = $query->orderByDesc('id')
            ->offset(($page - 1) * $perPage)
            ->limit($perPage)
            ->get()
            ->map(static fn ($r): array => (array) $r)
            ->all();
        return [
            'rows' => $rows,
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
            'per_page' => $perPage,
        ];
    }

    private function buildSystemLogsQuery(array $filters = [])
    {
        $qText = trim((string) ($filters['q'] ?? ''));
        $source = trim((string) ($filters['source'] ?? ''));
        $level = strtoupper(trim((string) ($filters['level'] ?? '')));
        $onlyErrors = !empty($filters['only_errors']);

        $query = Capsule::table('mod_easydcim_bw_guard_logs');
        if ($onlyErrors) {
            $query->where(function ($sub): void {
                $sub->where('level', 'ERROR')
                    ->orWhere('level', 'WARNING');
            });
        }
        if (in_array($level, ['INFO', 'WARNING', 'ERROR'], true)) {
            $query->where('level', $level);
        }
        if ($source !== '') {
            $query->where('source', 'LIKE', '%' . $source . '%');
        }
        if ($qText !== '') {
            $like = '%' . $qText . '%';
            $query->where(function ($sub) use ($like): void {
                $sub->where('message', 'LIKE', $like)
                    ->orWhere('source', 'LIKE', $like)
                    ->orWhere('context_json', 'LIKE', $like)
                    ->orWhere('level', 'LIKE', $like)
                    ->orWhere('created_at', 'LIKE', $like);
            });
        }
        return $query;
    }

    private function getPerPage(string $key, int $default = 10): int
    {
        $allowed = [10, 25, 50, 100];
        $value = (int) ($_REQUEST[$key] ?? $default);
        if (!in_array($value, $allowed, true)) {
            return $default;
        }
        return $value;
    }

    private function getPage(string $key): int
    {
        return max(1, (int) ($_REQUEST[$key] ?? 1));
    }

    private function renderPager(string $tab, string $pageKey, int $page, int $pages, array $extra = []): void
    {
        if ($pages <= 1) {
            return;
        }
        $page = max(1, min($page, $pages));
        $prevUrl = $page > 1 ? $this->buildTabUrl($tab, array_merge($extra, [$pageKey => (string) ($page - 1)])) : '';
        $nextUrl = $page < $pages ? $this->buildTabUrl($tab, array_merge($extra, [$pageKey => (string) ($page + 1)])) : '';
        echo '<div class="edbw-pager">';
        if ($prevUrl !== '') {
            echo '<a class="btn btn-default btn-sm" href="' . htmlspecialchars($prevUrl) . '">' . htmlspecialchars($this->t('pager_prev')) . '</a>';
        } else {
            echo '<span class="btn btn-default btn-sm disabled">' . htmlspecialchars($this->t('pager_prev')) . '</span>';
        }
        echo '<span class="edbw-pager-label">' . htmlspecialchars($this->t('pager_page')) . ' ' . $page . ' / ' . $pages . '</span>';
        if ($nextUrl !== '') {
            echo '<a class="btn btn-default btn-sm" href="' . htmlspecialchars($nextUrl) . '">' . htmlspecialchars($this->t('pager_next')) . '</a>';
        } else {
            echo '<span class="btn btn-default btn-sm disabled">' . htmlspecialchars($this->t('pager_next')) . '</span>';
        }
        echo '</div>';
    }

    private function json(array $data): void
    {
        header('Content-Type: application/json');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function isAjaxRequest(): bool
    {
        if (isset($_REQUEST['ajax']) && (string) $_REQUEST['ajax'] === '1') {
            return true;
        }
        if (isset($_POST['ajax']) && (string) $_POST['ajax'] === '1') {
            return true;
        }
        if (isset($_GET['ajax']) && (string) $_GET['ajax'] === '1') {
            return true;
        }
        $hdr = strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''));
        if ($hdr === 'xmlhttprequest') {
            return true;
        }
        $accept = strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? ''));
        return str_contains($accept, 'application/json');
    }

    private function buildBatchAjaxPayload(array $result): array
    {
        $state = $this->buildBatchStatePayload();
        return [
            'type' => (string) ($result['type'] ?? 'info'),
            'message' => (string) ($result['text'] ?? ''),
            'silent' => (bool) ($result['silent'] ?? false),
            'state' => $state,
        ];
    }

    private function buildBatchStatePayload(): array
    {
        $state = $this->getTestAllState();
        $running = ((int) ($state['remaining'] ?? 0) > 0)
            || ((int) ($state['total'] ?? 0) > (int) ($state['done'] ?? 0));
        return [
            'total' => (int) ($state['total'] ?? 0),
            'done' => (int) ($state['done'] ?? 0),
            'ok' => (int) ($state['ok'] ?? 0),
            'warn' => (int) ($state['warn'] ?? 0),
            'fail' => (int) ($state['fail'] ?? 0),
            'remaining' => (int) ($state['remaining'] ?? 0),
            'running' => $running,
        ];
    }

    private function addPackage(): array
    {
        $name = trim((string) ($_POST['pkg_name'] ?? ''));
        $size = (float) ($_POST['pkg_size_gb'] ?? 0);
        $price = (float) ($_POST['pkg_price'] ?? 0);
        $active = ((string) ($_POST['pkg_active'] ?? '1')) === '1' ? 1 : 0;
        if ($name === '' || $size <= 0 || $price < 0) {
            return ['type' => 'danger', 'text' => 'Invalid package values.'];
        }

        Capsule::table('mod_easydcim_bw_guard_packages')->insert([
            'name' => $name,
            'size_gb' => $size,
            'price' => $price,
            'taxed' => 1,
            'available_for_pids' => null,
            'available_for_gids' => null,
            'is_active' => $active,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        return ['type' => 'success', 'text' => 'Package added.'];
    }

    private function updatePackage(): array
    {
        $id = (int) ($_POST['pkg_id'] ?? 0);
        $name = trim((string) ($_POST['pkg_name'] ?? ''));
        $size = (float) ($_POST['pkg_size_gb'] ?? 0);
        $price = (float) ($_POST['pkg_price'] ?? 0);
        $active = ((string) ($_POST['pkg_active'] ?? '1')) === '1' ? 1 : 0;
        if ($id <= 0 || $name === '' || $size <= 0 || $price < 0) {
            return ['type' => 'danger', 'text' => 'Invalid package values.'];
        }

        $exists = Capsule::table('mod_easydcim_bw_guard_packages')->where('id', $id)->exists();
        if (!$exists) {
            return ['type' => 'warning', 'text' => 'Package not found.'];
        }
        Capsule::table('mod_easydcim_bw_guard_packages')->where('id', $id)->update([
            'name' => $name,
            'size_gb' => $size,
            'price' => $price,
            'is_active' => $active,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        return ['type' => 'success', 'text' => 'Package updated.'];
    }

    private function deletePackage(): array
    {
        $id = (int) ($_POST['delete_pkg_id'] ?? 0);
        if ($id <= 0) {
            return ['type' => 'danger', 'text' => 'Invalid package id.'];
        }
        $deleted = (int) Capsule::table('mod_easydcim_bw_guard_packages')->where('id', $id)->delete();
        if ($deleted < 1) {
            return ['type' => 'warning', 'text' => 'Package not found.'];
        }
        return ['type' => 'success', 'text' => 'Package deleted.'];
    }

    private function saveOverride(): void
    {
        $serviceId = (int) ($_POST['ov_serviceid'] ?? 0);
        $quota = ($_POST['ov_quota_gb'] ?? '') !== '' ? (float) $_POST['ov_quota_gb'] : null;
        $mode = strtoupper(trim((string) ($_POST['ov_mode'] ?? '')));
        $action = trim((string) ($_POST['ov_action'] ?? ''));

        if ($serviceId <= 0) {
            echo '<div class="alert alert-danger">Invalid service id.</div>';
            return;
        }
        if ($mode !== '' && !in_array($mode, ['IN', 'OUT', 'TOTAL'], true)) {
            echo '<div class="alert alert-danger">Invalid mode.</div>';
            return;
        }
        if ($action !== '' && !in_array($action, ['disable_ports', 'suspend', 'both'], true)) {
            echo '<div class="alert alert-danger">Invalid action.</div>';
            return;
        }

        Capsule::table('mod_easydcim_bw_guard_service_overrides')->updateOrInsert(
            ['serviceid' => $serviceId],
            [
                'override_base_quota_gb' => $quota,
                'override_mode' => $mode !== '' ? $mode : null,
                'override_action' => $action !== '' ? $action : null,
                'updated_at' => date('Y-m-d H:i:s'),
                'created_at' => date('Y-m-d H:i:s'),
            ]
        );
        echo '<div class="alert alert-success">Override saved.</div>';
    }

    private function applyScopeFilter($query): void
    {
        $pids = array_map('intval', $this->settings->getCsvList('managed_pids'));
        $gids = array_map('intval', $this->settings->getCsvList('managed_gids'));
        if (!empty($pids)) {
            $query->whereIn('h.packageid', $pids);
            return;
        }
        if (!empty($gids)) {
            $gidPids = Capsule::table('tblproducts')
                ->whereIn('gid', $gids)
                ->pluck('id')
                ->map(static function ($v): int {
                    return (int) $v;
                })
                ->all();
            if (empty($gidPids)) {
                $query->whereRaw('1=0');
                return;
            }
            $query->whereIn('h.packageid', $gidPids);
        }
    }

    private function getScopedProducts(): array
    {
        $pids = array_map('intval', $this->settings->getCsvList('managed_pids'));
        $gids = array_map('intval', $this->settings->getCsvList('managed_gids'));
        if (empty($pids) && empty($gids)) {
            return [];
        }

        $q = Capsule::table('tblproducts')->select(['id', 'name', 'gid']);
        if (!empty($pids)) {
            $q->whereIn('id', $pids);
        } elseif (!empty($gids)) {
            $q->whereIn('gid', $gids);
        }
        $products = $q->orderBy('gid')->orderBy('id')->get();
        if ($products->isEmpty()) {
            return [];
        }

        $pidList = $products->pluck('id')->map(static fn ($v): int => (int) $v)->all();
        $defaults = Capsule::table('mod_easydcim_bw_guard_product_defaults')->whereIn('pid', $pidList)->get()->keyBy('pid');
        $cfRows = Capsule::table('tblcustomfields')
            ->where('type', 'product')
            ->whereIn('relid', $pidList)
            ->get(['relid', 'fieldname']);
        $cfMap = [];
        foreach ($cfRows as $r) {
            $pid = (int) $r->relid;
            $name = $this->normalizeFieldKey((string) $r->fieldname);
            $cfMap[$pid][$name] = true;
        }

        $cfgMap = [];
        if (Capsule::schema()->hasTable('tblproductconfiglinks') && Capsule::schema()->hasTable('tblproductconfigoptions')) {
            $cfgRows = Capsule::table('tblproductconfiglinks as l')
                ->join('tblproductconfigoptions as o', 'o.gid', '=', 'l.gid')
                ->whereIn('l.pid', $pidList)
                ->get(['l.pid', 'o.optionname']);
            foreach ($cfgRows as $r) {
                $pid = (int) $r->pid;
                $name = $this->normalizeFieldKey((string) $r->optionname);
                $cfgMap[$pid][$name] = true;
            }
        }

        $out = [];
        foreach ($products as $p) {
            $pid = (int) $p->id;
            $d = $defaults[$pid] ?? null;
            $hasService = !empty($cfMap[$pid]['easydcim_service_id']) || !empty($cfgMap[$pid]['easydcim_service_id']);
            $hasOrder = !empty($cfMap[$pid]['easydcim_order_id']) || !empty($cfgMap[$pid]['easydcim_order_id']);
            $hasServer = !empty($cfMap[$pid]['easydcim_server_id']) || !empty($cfgMap[$pid]['easydcim_server_id']);
            $out[] = [
                'pid' => $pid,
                'name' => (string) $p->name,
                'gid' => (int) $p->gid,
                'quota_in' => $d ? (string) ($d->default_quota_in_gb ?? '') : '',
                'quota_out' => $d ? (string) ($d->default_quota_out_gb ?? '') : '',
                'quota_total' => $d ? (string) ($d->default_quota_total_gb ?? $d->default_quota_gb ?? '') : '',
                'unlimited_in' => $d ? ((int) ($d->unlimited_in ?? 0) === 1) : false,
                'unlimited_out' => $d ? ((int) ($d->unlimited_out ?? 0) === 1) : false,
                'unlimited_total' => $d ? ((int) ($d->unlimited_total ?? 0) === 1) : false,
                'mode' => $d ? (string) ($d->default_mode ?? 'TOTAL') : 'TOTAL',
                'action' => $d ? (string) ($d->default_action ?? 'disable_ports') : 'disable_ports',
                'cf_service' => $hasService,
                'cf_order' => $hasOrder,
                'cf_server' => $hasServer,
            ];
        }
        return $out;
    }

    private function getScopedHostingServices(array $easyServiceItems = [], bool $withPortLookup = true, bool $resolveFromApi = false): array
    {
        $q = Capsule::table('tblhosting as h')
            ->join('tblproducts as p', 'p.id', '=', 'h.packageid')
            ->leftJoin('tblclients as c', 'c.id', '=', 'h.userid')
            ->leftJoin('mod_easydcim_bw_guard_service_state as s', 's.serviceid', '=', 'h.id')
            ->select([
                'h.id as serviceid', 'h.userid', 'h.packageid as pid', 'h.domainstatus', 'h.dedicatedip',
                'h.domain',
                'p.name as product_name',
                'c.firstname', 'c.lastname', 'c.email',
                's.easydcim_service_id as state_service_id',
                's.mode',
                's.last_used_gb', 's.last_remaining_gb', 's.last_check_at', 's.cycle_start', 's.cycle_end',
            ]);
        $this->applyScopeFilter($q);
        $q->whereIn('h.domainstatus', ['Active', 'Suspended']);
        $rows = $q->orderByDesc('h.id')->limit(300)->get();
        if ($rows->isEmpty()) {
            return [];
        }

        $serviceIds = $rows->pluck('serviceid')->map(static fn ($v): int => (int) $v)->all();
        $cfVals = $this->getServiceCustomFieldValues($serviceIds);

        $baseUrl = $this->settings->getString('easydcim_base_url');
        $token = Crypto::safeDecrypt($this->settings->getString('easydcim_api_token'));
        $apiAvailable = $baseUrl !== '' && $token !== '';
        $resolverClient = null;
        $portClient = null;
        if ($apiAvailable) {
            $resolverClient = new EasyDcimClient($baseUrl, $token, $this->settings->getBool('use_impersonation', false), $this->logger, $this->proxyConfig());
            if ($withPortLookup) {
                $portClient = $resolverClient;
            }
        }
        $easyByIp = [];
        foreach ($easyServiceItems as $item) {
            $ip = trim((string) ($item['ip'] ?? ''));
            if ($ip === '') {
                continue;
            }
            $easyByIp[$ip] = $item;
        }
        $easyByService = [];
        $easyByServer = [];
        $easyByOrder = [];
        foreach ($easyServiceItems as $item) {
            $sid = trim((string) ($item['service_id'] ?? ''));
            $srv = trim((string) ($item['server_id'] ?? ''));
            $ord = trim((string) ($item['order_id'] ?? ''));
            if ($sid !== '') {
                $easyByService[$sid] = $item;
            }
            if ($srv !== '') {
                $easyByServer[$srv] = $item;
            }
            if ($ord !== '') {
                $easyByOrder[$ord] = $item;
            }
        }
        $easyClientByService = [];
        $easyClientByOrder = [];
        $easyClientByServer = [];
        foreach ($easyServiceItems as $item) {
            if (!is_array($item)) {
                continue;
            }
            $clientName = trim((string) ($item['client_name'] ?? ''));
            $clientEmail = trim((string) ($item['client_email'] ?? ''));
            if ($clientName === '' && $clientEmail === '') {
                continue;
            }
            $identity = ['name' => $clientName, 'email' => $clientEmail];
            $sid = trim((string) ($item['service_id'] ?? ''));
            $srv = trim((string) ($item['server_id'] ?? ''));
            $ord = trim((string) ($item['order_id'] ?? ''));
            if ($sid !== '' && !isset($easyClientByService[$sid])) {
                $easyClientByService[$sid] = $identity;
            }
            if ($ord !== '' && !isset($easyClientByOrder[$ord])) {
                $easyClientByOrder[$ord] = $identity;
            }
            if ($srv !== '' && !isset($easyClientByServer[$srv])) {
                $easyClientByServer[$srv] = $identity;
            }
        }
        $resolvedFromOrder = [];

        $out = [];
        foreach ($rows as $r) {
            $sid = (int) $r->serviceid;
            $svcCf = $cfVals[$sid]['easydcim_service_id'] ?? '';
            $srvCf = $cfVals[$sid]['easydcim_server_id'] ?? '';
            $ordCf = $cfVals[$sid]['easydcim_order_id'] ?? '';
            $explicitService = $svcCf !== '' ? $svcCf : (string) ($r->state_service_id ?? '');
            $resolvedService = $explicitService;
            $resolvedServer = $srvCf;
            $resolvedOrder = $ordCf;

            if ($resolvedService === '' && $resolvedOrder !== '' && isset($easyByOrder[$resolvedOrder])) {
                $resolvedService = (string) ($easyByOrder[$resolvedOrder]['service_id'] ?? '');
                if ($resolvedServer === '') {
                    $resolvedServer = (string) ($easyByOrder[$resolvedOrder]['server_id'] ?? '');
                }
            }
            if ($resolvedOrder !== '' && isset($easyByOrder[$resolvedOrder])) {
                $mappedService = trim((string) ($easyByOrder[$resolvedOrder]['service_id'] ?? ''));
                if ($mappedService !== '') {
                    if ($resolvedService !== '' && $resolvedService !== $mappedService) {
                        $this->logger->log('WARNING', 'service_order_mismatch_corrected', [
                            'serviceid' => $sid,
                            'order_id' => $resolvedOrder,
                            'from_service_id' => $resolvedService,
                            'to_service_id' => $mappedService,
                        ]);
                    }
                    $resolvedService = $mappedService;
                }
            }
            if ($resolvedService === '' && $resolvedOrder !== '') {
                $cachedService = $this->getServiceIdFromOrderMapCache($resolvedOrder);
                if ($cachedService !== '') {
                    $resolvedService = $cachedService;
                }
            }
            if ($resolveFromApi && $resolvedService === '' && $resolvedOrder !== '' && $resolverClient instanceof EasyDcimClient) {
                if (array_key_exists($resolvedOrder, $resolvedFromOrder)) {
                    $resolvedService = (string) $resolvedFromOrder[$resolvedOrder];
                } else {
                    $resolvedService = $this->resolveServiceIdFromOrder($resolverClient, $resolvedOrder);
                    $resolvedFromOrder[$resolvedOrder] = $resolvedService;
                }
            }
            if ($resolvedService !== '' && isset($easyByService[$resolvedService]) && $resolvedServer === '') {
                $resolvedServer = (string) ($easyByService[$resolvedService]['server_id'] ?? '');
            }
            if ($resolvedService !== '' && isset($easyByService[$resolvedService]) && $resolvedOrder === '') {
                $resolvedOrder = (string) ($easyByService[$resolvedService]['order_id'] ?? '');
            }
            if ($resolvedService === '' && $resolvedServer !== '' && isset($easyByServer[$resolvedServer])) {
                $resolvedService = (string) ($easyByServer[$resolvedServer]['service_id'] ?? '');
            }
            $serviceIp = trim((string) ($r->dedicatedip ?? ''));
            if ($resolvedService === '' && $serviceIp !== '' && isset($easyByIp[$serviceIp])) {
                $resolvedService = (string) ($easyByIp[$serviceIp]['service_id'] ?? '');
                if ($resolvedServer === '') {
                    $resolvedServer = (string) ($easyByIp[$serviceIp]['server_id'] ?? '');
                }
                if ($resolvedOrder === '') {
                    $resolvedOrder = (string) ($easyByIp[$serviceIp]['order_id'] ?? '');
                }
            }
            $easyClientName = '';
            $easyClientEmail = '';
            if ($resolvedService !== '' && isset($easyClientByService[$resolvedService])) {
                $easyClientName = (string) ($easyClientByService[$resolvedService]['name'] ?? '');
                $easyClientEmail = (string) ($easyClientByService[$resolvedService]['email'] ?? '');
            } elseif ($resolvedOrder !== '' && isset($easyClientByOrder[$resolvedOrder])) {
                $easyClientName = (string) ($easyClientByOrder[$resolvedOrder]['name'] ?? '');
                $easyClientEmail = (string) ($easyClientByOrder[$resolvedOrder]['email'] ?? '');
            } elseif ($resolvedServer !== '' && isset($easyClientByServer[$resolvedServer])) {
                $easyClientName = (string) ($easyClientByServer[$resolvedServer]['name'] ?? '');
                $easyClientEmail = (string) ($easyClientByServer[$resolvedServer]['email'] ?? '');
            } elseif ($serviceIp !== '' && isset($easyByIp[$serviceIp])) {
                $easyClientName = trim((string) ($easyByIp[$serviceIp]['client_name'] ?? ''));
                $easyClientEmail = trim((string) ($easyByIp[$serviceIp]['client_email'] ?? ''));
            }
            $portsSummary = $this->t('no_data');
            $networkPortsTotal = 0;
            $networkPortsUp = 0;
            $networkTrafficTotal = 0.0;

            if ($portClient instanceof EasyDcimClient) {
                try {
                    $email = (string) ($r->email ?? '');
                    if ($resolvedService !== '') {
                        $ports = $portClient->ports($resolvedService, true, $email);
                    } else {
                        $ports = ['data' => []];
                    }
                    $items = $this->extractPortItems((array) ($ports['data'] ?? []));
                    if (!empty($items)) {
                        $up = 0;
                        $total = 0;
                        $traffic = 0.0;
                        foreach ($items as $p) {
                            if (!$this->isNetworkPortCandidate((string) ($p['name'] ?? ''), (string) ($p['description'] ?? ''), (string) ($p['type'] ?? ''))) {
                                continue;
                            }
                            $total++;
                            if (!empty($p['is_up'])) {
                                $up++;
                            }
                            $traffic += (float) ($p['traffic_total'] ?? 0.0);
                        }
                        $networkPortsTotal = $total;
                        $networkPortsUp = $up;
                        $networkTrafficTotal = $traffic;
                        if ($total > 0) {
                            $portsSummary = $up . '/' . $total . ' ' . $this->t('ports_up');
                        } else {
                            $portsSummary = $this->t('network_ports_not_found');
                        }
                } elseif ($resolvedService !== '' || $resolvedServer !== '') {
                    $portsSummary = $this->t('ports_not_found');
                }
            } catch (\Throwable $e) {
                $portsSummary = $this->t('ports_error');
                $this->logger->log('WARNING', 'servers_ports_lookup_failed', [
                    'serviceid' => $sid,
                    'error' => $e->getMessage(),
                    'easydcim_service_id' => $resolvedService,
                    'easydcim_server_id' => $resolvedServer,
                    'easydcim_order_id' => $resolvedOrder,
                ]);
            }
        }

        $out[] = [
                'serviceid' => $sid,
                'userid' => (int) $r->userid,
                'pid' => (int) $r->pid,
                'product_name' => (string) ($r->product_name ?? ''),
                'domainstatus' => (string) ($r->domainstatus ?? ''),
                'domain' => (string) ($r->domain ?? ''),
            'firstname' => (string) ($r->firstname ?? ''),
            'lastname' => (string) ($r->lastname ?? ''),
            'email' => (string) ($r->email ?? ''),
                'client_name' => trim((string) ($r->firstname ?? '') . ' ' . (string) ($r->lastname ?? '')) ?: ('#' . (int) $r->userid),
                'client_url' => 'clientssummary.php?userid=' . (int) $r->userid,
                'service_url' => 'clientsservices.php?userid=' . (int) $r->userid . '&id=' . $sid,
                'easydcim_client_name' => $easyClientName,
                'easydcim_client_email' => $easyClientEmail,
                'ip' => (string) ($r->dedicatedip ?? ''),
                'easydcim_service_id_explicit' => $explicitService,
                'easydcim_order_id' => $resolvedOrder,
                'easydcim_service_id' => $resolvedService,
                'easydcim_server_id' => $resolvedServer,
                'ports_summary' => $portsSummary,
                'network_ports_total' => $networkPortsTotal,
                'network_ports_up' => $networkPortsUp,
                'network_traffic_total' => $networkTrafficTotal,
                'mode' => (string) ($r->mode ?? ''),
                'last_used_gb' => (float) ($r->last_used_gb ?? 0.0),
                'last_remaining_gb' => (float) ($r->last_remaining_gb ?? 0.0),
                'last_check_at' => (string) ($r->last_check_at ?? ''),
                'cycle_start' => (string) ($r->cycle_start ?? ''),
                'cycle_end' => (string) ($r->cycle_end ?? ''),
            ];
        }

        return $out;
    }

    private function normalizeFieldKey(string $name): string
    {
        $plain = strtolower(trim(explode('|', $name)[0]));
        $plain = str_replace(['-', '.', ':'], [' ', ' ', ' '], $plain);
        $plain = preg_replace('/\s+/', ' ', (string) $plain);
        $compact = str_replace(' ', '', (string) $plain);
        if (in_array($compact, ['easydcimserviceid', 'serviceid'], true)) {
            return 'easydcim_service_id';
        }
        if (in_array($compact, ['easydcimorderid', 'orderid'], true)) {
            return 'easydcim_order_id';
        }
        if (in_array($compact, ['easydcimserverid', 'serverid'], true)) {
            return 'easydcim_server_id';
        }
        return $compact;
    }

    private function normalizeEmail(string $value): string
    {
        return strtolower(trim($value));
    }

    private function normalizeName(string $value): string
    {
        $v = strtolower(trim($value));
        $v = preg_replace('/\s+/', ' ', $v) ?? $v;
        return $v;
    }

    private function normalizeLooseIdentifier(string $value): string
    {
        $v = strtolower(trim($value));
        $v = preg_replace('/\s+/', '', $v) ?? $v;
        return $v;
    }

    private function refreshServersCacheNow(): array
    {
        try {
            $items = $this->fetchEasyServices();
            Capsule::table('mod_easydcim_bw_guard_meta')->updateOrInsert(
                ['meta_key' => 'servers_list_cache_json'],
                ['meta_value' => json_encode($items, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'updated_at' => date('Y-m-d H:i:s')]
            );
            Capsule::table('mod_easydcim_bw_guard_meta')->updateOrInsert(
                ['meta_key' => 'servers_list_cache_at'],
                ['meta_value' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')]
            );
            $mapped = $this->persistResolvedMappings($this->getScopedHostingServices($items, false, false));
            return ['type' => 'success', 'text' => $this->t('servers_cache_refreshed') . ': ' . count($items) . ' | ' . $this->t('servers_mapping_updated') . ': ' . $mapped];
        } catch (\Throwable $e) {
            $this->logger->log('WARNING', 'servers_cache_refresh_failed', ['error' => $e->getMessage()]);
            return ['type' => 'danger', 'text' => $this->t('servers_cache_refresh_failed') . ': ' . $e->getMessage()];
        }
    }

    private function persistResolvedMappings(array $services): int
    {
        $hasServerColumn = Capsule::schema()->hasColumn('mod_easydcim_bw_guard_service_state', 'easydcim_server_id');
        $updated = 0;
        foreach ($services as $svc) {
            $whmcsServiceId = (int) ($svc['serviceid'] ?? 0);
            $userId = (int) ($svc['userid'] ?? 0);
            $serviceId = trim((string) ($svc['easydcim_service_id'] ?? ''));
            $orderId = trim((string) ($svc['easydcim_order_id'] ?? ''));
            $serverId = trim((string) ($svc['easydcim_server_id'] ?? ''));
            if ($whmcsServiceId <= 0 || $userId <= 0) {
                continue;
            }
            if ($serviceId === '' && $orderId === '' && $serverId === '') {
                continue;
            }

            $existing = Capsule::table('mod_easydcim_bw_guard_service_state')->where('serviceid', $whmcsServiceId)->first();
            if ($existing) {
                $changes = [];
                if ($serviceId !== '' && trim((string) ($existing->easydcim_service_id ?? '')) !== $serviceId) {
                    $changes['easydcim_service_id'] = $serviceId;
                }
                if ($orderId !== '' && trim((string) ($existing->easydcim_order_id ?? '')) !== $orderId) {
                    $changes['easydcim_order_id'] = $orderId;
                }
                if ($hasServerColumn && $serverId !== '' && trim((string) ($existing->easydcim_server_id ?? '')) !== $serverId) {
                    $changes['easydcim_server_id'] = $serverId;
                }
                if (!empty($changes)) {
                    $changes['updated_at'] = date('Y-m-d H:i:s');
                    Capsule::table('mod_easydcim_bw_guard_service_state')->where('serviceid', $whmcsServiceId)->update($changes);
                    $updated++;
                }
                continue;
            }

            if ($serviceId === '') {
                continue;
            }

            $insert = [
                'serviceid' => $whmcsServiceId,
                'userid' => $userId,
                'easydcim_service_id' => $serviceId,
                'easydcim_order_id' => $orderId !== '' ? $orderId : null,
                'base_quota_gb' => 0,
                'mode' => 'TOTAL',
                'action' => 'disable_ports',
                'last_used_gb' => 0,
                'last_remaining_gb' => 0,
                'last_status' => 'ok',
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ];
            if ($hasServerColumn) {
                $insert['easydcim_server_id'] = $serverId !== '' ? $serverId : null;
            }
            Capsule::table('mod_easydcim_bw_guard_service_state')->insert($insert);
            $updated++;
        }
        return $updated;
    }

    public function processQueuedServerTestsFromCron(int $chunkSize = 5): void
    {
        try {
            $state = $this->getTestAllState();
            if (empty($state['queue']) || (int) ($state['remaining'] ?? 0) <= 0) {
                return;
            }
            $effectiveChunk = max(1, (int) $this->settings->getInt('servers_test_chunk_size', $chunkSize));
            $result = $this->testAllServices($effectiveChunk, false);
            if (!empty($result['text'])) {
                $this->logger->log('INFO', 'servers_test_all_background_tick', [
                    'message' => (string) $result['text'],
                    'remaining' => (int) ($this->getTestAllState()['remaining'] ?? 0),
                ]);
            }
        } catch (\Throwable $e) {
            $this->logger->log('ERROR', 'servers_test_all_background_failed', ['error' => $e->getMessage()]);
        }
    }

    public function runBackgroundMaintenanceFromCron(int $chunkSize = 5): void
    {
        try {
            $this->refreshTrafficSnapshotFromCron(60);
        } catch (\Throwable $e) {
            $this->logger->log('ERROR', 'traffic_snapshot_refresh_failed', ['error' => $e->getMessage()]);
        }

        try {
            $this->ensureScheduledServerTestQueue();
            $this->processQueuedServerTestsFromCron(max(1, $chunkSize));
        } catch (\Throwable $e) {
            $this->logger->log('ERROR', 'servers_maintenance_failed', ['error' => $e->getMessage()]);
        }
    }

    private function ensureScheduledServerTestQueue(): void
    {
        $state = $this->getTestAllState();
        if (!empty($state['queue']) && (int) ($state['remaining'] ?? 0) > 0) {
            return;
        }

        $now = time();
        $lastFull = strtotime((string) Capsule::table('mod_easydcim_bw_guard_meta')->where('meta_key', 'servers_last_full_test_at')->value('meta_value'));
        if (!$lastFull || $lastFull <= ($now - 86400)) {
            $queue = $this->buildScopedServiceIdQueue();
            if (!empty($queue)) {
                $this->saveTestAllState([
                    'queue' => $queue,
                    'total' => count($queue),
                    'done' => 0,
                    'ok' => 0,
                    'warn' => 0,
                    'fail' => 0,
                    'remaining' => count($queue),
                    'started_at' => date('Y-m-d H:i:s'),
                    'source' => 'daily',
                ]);
                Capsule::table('mod_easydcim_bw_guard_meta')->updateOrInsert(
                    ['meta_key' => 'servers_last_full_test_at'],
                    ['meta_value' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')]
                );
            }
            return;
        }

        $lastRetry = strtotime((string) Capsule::table('mod_easydcim_bw_guard_meta')->where('meta_key', 'servers_last_retry_test_at')->value('meta_value'));
        if ($lastRetry && $lastRetry > ($now - 300)) {
            return;
        }

        $queue = $this->buildProblemServiceIdQueue();
        if (empty($queue)) {
            return;
        }
        $this->saveTestAllState([
            'queue' => $queue,
            'total' => count($queue),
            'done' => 0,
            'ok' => 0,
            'warn' => 0,
            'fail' => 0,
            'remaining' => count($queue),
            'started_at' => date('Y-m-d H:i:s'),
            'source' => 'retry',
        ]);
        Capsule::table('mod_easydcim_bw_guard_meta')->updateOrInsert(
            ['meta_key' => 'servers_last_retry_test_at'],
            ['meta_value' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')]
        );
    }

    private function buildScopedServiceIdQueue(): array
    {
        $services = $this->getScopedHostingServices($this->getEasyServicesCacheOnly(), false, false);
        $ids = [];
        foreach ($services as $svc) {
            $sid = (int) ($svc['serviceid'] ?? 0);
            if ($sid > 0) {
                $ids[$sid] = $sid;
            }
        }
        return array_values($ids);
    }

    private function buildProblemServiceIdQueue(): array
    {
        $scoped = $this->buildScopedServiceIdQueue();
        if (empty($scoped)) {
            return [];
        }
        $scopedSet = array_fill_keys($scoped, true);
        $rows = Capsule::table('mod_easydcim_bw_guard_meta')
            ->where('meta_key', 'like', 'service_test_cache_%')
            ->get(['meta_key', 'meta_value']);

        $queue = [];
        foreach ($rows as $row) {
            $key = (string) ($row->meta_key ?? '');
            if (!preg_match('/^service_test_cache_(\d+)$/', $key, $m)) {
                continue;
            }
            $sid = (int) $m[1];
            if (!isset($scopedSet[$sid])) {
                continue;
            }
            $payload = json_decode((string) ($row->meta_value ?? ''), true);
            if (!is_array($payload)) {
                continue;
            }
            $type = strtolower((string) ($payload['type'] ?? ''));
            $httpCode = (int) ($payload['http_code'] ?? 0);
            if (in_array($type, ['warning', 'danger'], true) || $httpCode >= 400) {
                $queue[$sid] = $sid;
            }
        }
        return array_values($queue);
    }

    private function testAllServices(?int $chunkOverride = null, bool $allowInitialize = true): array
    {
        try {
            if (function_exists('set_time_limit')) {
                @set_time_limit(60);
            }
            $chunkSize = max(1, (int) ($chunkOverride ?? $this->settings->getInt('servers_test_chunk_size', 1)));
            $state = $this->getTestAllState();
            $queue = $state['queue'];

            // Recover queue when legacy/corrupt state has progress counters but no remaining queue.
            if (empty($queue) && (int) ($state['total'] ?? 0) > (int) ($state['done'] ?? 0)) {
                $queue = $this->rebuildTestQueueFromProgress((int) ($state['done'] ?? 0));
                $state['queue'] = $queue;
                $state['remaining'] = count($queue);
            }

            if (empty($queue) && !$allowInitialize) {
                return ['type' => 'info', 'silent' => true, 'text' => ''];
            }

            if (empty($queue)) {
                $services = $this->getScopedHostingServices($this->getEasyServicesCacheOnly(), false, false);
                if (empty($services)) {
                    return ['type' => 'warning', 'text' => $this->t('no_rows')];
                }
                $queue = [];
                foreach ($services as $svc) {
                    $id = (int) ($svc['serviceid'] ?? 0);
                    if ($id > 0) {
                        $queue[] = $id;
                    }
                }
                $queue = array_values(array_unique($queue));
                $state = [
                    'queue' => $queue,
                    'total' => count($queue),
                    'done' => 0,
                    'ok' => 0,
                    'warn' => 0,
                    'fail' => 0,
                    'remaining' => count($queue),
                    'started_at' => date('Y-m-d H:i:s'),
                ];
            }

            if (empty($queue)) {
                $this->clearTestAllState();
                return ['type' => 'warning', 'text' => $this->t('no_rows')];
            }

            $original = $_POST['test_serviceid'] ?? null;
            $batch = array_slice($queue, 0, $chunkSize);
            $remaining = array_slice($queue, $chunkSize);
            foreach ($batch as $id) {
                $_POST['test_serviceid'] = (string) $id;
                $_POST['__bulk_test'] = '1';
                $result = $this->testServiceItem();
                $type = (string) ($result['type'] ?? 'warning');
                if ($type === 'success') {
                    $state['ok'] = (int) ($state['ok'] ?? 0) + 1;
                } elseif ($type === 'danger') {
                    $state['fail'] = (int) ($state['fail'] ?? 0) + 1;
                } else {
                    $state['warn'] = (int) ($state['warn'] ?? 0) + 1;
                }
                $state['done'] = (int) ($state['done'] ?? 0) + 1;
            }
            if ($original === null) {
                unset($_POST['test_serviceid']);
            } else {
                $_POST['test_serviceid'] = $original;
            }
            unset($_POST['__bulk_test']);

            $state['queue'] = $remaining;
            $state['remaining'] = count($remaining);
            $state['updated_at'] = date('Y-m-d H:i:s');
            $this->saveTestAllState($state);

            if (!empty($remaining)) {
                return [
                    'type' => 'info',
                    'silent' => true,
                    'text' => $this->t('servers_test_all_progress') . ': '
                        . (int) ($state['done'] ?? 0) . '/' . (int) ($state['total'] ?? 0)
                        . ' (OK: ' . (int) ($state['ok'] ?? 0)
                        . ', WARN: ' . (int) ($state['warn'] ?? 0)
                        . ', FAIL: ' . (int) ($state['fail'] ?? 0) . ')',
                ];
            }

            $ok = (int) ($state['ok'] ?? 0);
            $warn = (int) ($state['warn'] ?? 0);
            $fail = (int) ($state['fail'] ?? 0);
            $startedAtRaw = trim((string) ($state['started_at'] ?? ''));
            $durationSeconds = 0;
            if ($startedAtRaw !== '') {
                $startedTs = strtotime($startedAtRaw);
                if ($startedTs !== false) {
                    $durationSeconds = max(0, time() - $startedTs);
                }
            }
            Capsule::table('mod_easydcim_bw_guard_meta')->updateOrInsert(
                ['meta_key' => 'servers_test_all_last_duration_seconds'],
                ['meta_value' => (string) $durationSeconds, 'updated_at' => date('Y-m-d H:i:s')]
            );
            Capsule::table('mod_easydcim_bw_guard_meta')->updateOrInsert(
                ['meta_key' => 'servers_test_all_last_finished_at'],
                ['meta_value' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')]
            );
            $this->clearTestAllState();
            return [
                'type' => $fail > 0 ? 'warning' : 'success',
                'text' => $this->t('servers_test_all_done') . ' | OK: ' . $ok . ' | WARN: ' . $warn . ' | FAIL: ' . $fail
                    . ' | ' . $this->t('servers_test_all_total_time') . ': ' . $this->formatDuration($durationSeconds),
            ];
        } catch (\Throwable $e) {
            $this->logger->log('ERROR', 'servers_test_all_failed', ['error' => $e->getMessage()]);
            return ['type' => 'danger', 'text' => $this->t('servers_test_all_failed') . ': ' . $e->getMessage()];
        }
    }

    private function getTestAllState(): array
    {
        $raw = (string) Capsule::table('mod_easydcim_bw_guard_meta')
            ->where('meta_key', 'servers_test_all_state')
            ->value('meta_value');
        if ($raw === '') {
            return ['queue' => [], 'total' => 0, 'done' => 0, 'ok' => 0, 'warn' => 0, 'fail' => 0, 'remaining' => 0];
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return ['queue' => [], 'total' => 0, 'done' => 0, 'ok' => 0, 'warn' => 0, 'fail' => 0, 'remaining' => 0];
        }
        $decoded['queue'] = isset($decoded['queue']) && is_array($decoded['queue']) ? $decoded['queue'] : [];
        $decoded['total'] = isset($decoded['total']) ? max(0, (int) $decoded['total']) : count($decoded['queue']);
        $decoded['done'] = isset($decoded['done']) ? max(0, (int) $decoded['done']) : 0;
        $decoded['ok'] = isset($decoded['ok']) ? max(0, (int) $decoded['ok']) : 0;
        $decoded['warn'] = isset($decoded['warn']) ? max(0, (int) $decoded['warn']) : 0;
        $decoded['fail'] = isset($decoded['fail']) ? max(0, (int) $decoded['fail']) : 0;
        $decoded['remaining'] = count($decoded['queue']);
        if ($decoded['remaining'] === 0 && $decoded['total'] > $decoded['done']) {
            $decoded['queue'] = $this->rebuildTestQueueFromProgress($decoded['done']);
            $decoded['remaining'] = count($decoded['queue']);
        }
        return $decoded;
    }

    private function rebuildTestQueueFromProgress(int $done): array
    {
        $services = $this->getScopedHostingServices($this->getEasyServicesCacheOnly(), false, false);
        $all = [];
        foreach ($services as $svc) {
            $id = (int) ($svc['serviceid'] ?? 0);
            if ($id > 0) {
                $all[] = $id;
            }
        }
        $all = array_values(array_unique($all));
        if (empty($all)) {
            return [];
        }
        if ($done <= 0) {
            return $all;
        }
        if ($done >= count($all)) {
            return [];
        }
        return array_slice($all, $done);
    }

    private function saveTestAllState(array $state): void
    {
        Capsule::table('mod_easydcim_bw_guard_meta')->updateOrInsert(
            ['meta_key' => 'servers_test_all_state'],
            ['meta_value' => json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'updated_at' => date('Y-m-d H:i:s')]
        );
    }

    private function clearTestAllState(): void
    {
        Capsule::table('mod_easydcim_bw_guard_meta')->where('meta_key', 'servers_test_all_state')->delete();
    }

    private function resolveBatchElapsedSeconds(array $state): int
    {
        $started = trim((string) ($state['started_at'] ?? ''));
        if ($started === '') {
            return 0;
        }
        $ts = strtotime($started);
        if ($ts === false) {
            return 0;
        }
        return max(0, time() - $ts);
    }

    private function formatDuration(int $seconds): string
    {
        $seconds = max(0, $seconds);
        $h = intdiv($seconds, 3600);
        $m = intdiv($seconds % 3600, 60);
        $s = $seconds % 60;
        if ($h > 0) {
            return sprintf('%02d:%02d:%02d', $h, $m, $s);
        }
        return sprintf('%02d:%02d', $m, $s);
    }

    private function resetTestAllServices(): array
    {
        $this->clearTestAllState();
        return ['type' => 'success', 'text' => $this->t('servers_test_all_reset_done')];
    }

    private function stopTestAllServices(): array
    {
        $this->clearTestAllState();
        return ['type' => 'success', 'text' => $this->t('servers_test_all_stopped')];
    }

    private function getScopedClientEmails(int $limit = 40): array
    {
        $q = Capsule::table('tblhosting as h')
            ->join('tblproducts as p', 'p.id', '=', 'h.packageid')
            ->leftJoin('tblclients as c', 'c.id', '=', 'h.userid')
            ->whereIn('h.domainstatus', ['Active', 'Suspended'])
            ->select(['c.email']);
        $this->applyScopeFilter($q);
        return $q->whereNotNull('c.email')
            ->where('c.email', '!=', '')
            ->limit(max(1, $limit))
            ->pluck('c.email')
            ->map(static fn ($e): string => trim((string) $e))
            ->filter(static fn ($e): bool => $e !== '')
            ->unique()
            ->values()
            ->all();
    }

    private function getServiceCustomFieldValues(array $serviceIds): array
    {
        if (empty($serviceIds)) {
            return [];
        }

        static $fieldByIdCache = null;
        if ($fieldByIdCache === null) {
            $fields = Capsule::table('tblcustomfields')
                ->where('type', 'product')
                ->get(['id', 'fieldname']);
            $aliases = [
                'easydcim_service_id' => 'easydcim_service_id',
                'service id' => 'easydcim_service_id',
                'serviceid' => 'easydcim_service_id',
                'easydcim order id' => 'easydcim_order_id',
                'easydcim_order_id' => 'easydcim_order_id',
                'order id' => 'easydcim_order_id',
                'orderid' => 'easydcim_order_id',
                'easydcim server id' => 'easydcim_server_id',
                'easydcim_server_id' => 'easydcim_server_id',
                'server id' => 'easydcim_server_id',
                'serverid' => 'easydcim_server_id',
            ];
            $fieldByIdCache = [];
            foreach ($fields as $f) {
                $normalized = strtolower(trim(explode('|', (string) $f->fieldname)[0]));
                if (!isset($aliases[$normalized])) {
                    continue;
                }
                $fieldByIdCache[(int) $f->id] = $aliases[$normalized];
            }
        }
        $fieldById = $fieldByIdCache;
        if (empty($fieldById)) {
            return [];
        }

        $values = Capsule::table('tblcustomfieldsvalues')
            ->whereIn('relid', $serviceIds)
            ->whereIn('fieldid', array_keys($fieldById))
            ->get(['relid', 'fieldid', 'value']);
        $out = [];
        foreach ($values as $v) {
            $serviceId = (int) $v->relid;
            $name = $fieldById[(int) $v->fieldid] ?? '';
            if ($name === '') {
                continue;
            }
            $out[$serviceId][$name] = trim((string) $v->value);
        }
        return $out;
    }

    private function extractServiceItems(array $payload): array
    {
        $items = [];
        if (isset($payload['data']) && is_array($payload['data'])) {
            $items = $this->extractListFromObject($payload['data']);
        } elseif (isset($payload['result']) && is_array($payload['result'])) {
            $items = $this->extractListFromObject($payload['result']);
        } elseif (isset($payload['services']) && is_array($payload['services'])) {
            $items = $payload['services'];
        } elseif (array_keys($payload) === range(0, count($payload) - 1)) {
            $items = $payload;
        } else {
            $items = [$payload];
        }

        $normalized = [];
        foreach ($items as $row) {
            if (!is_array($row)) {
                continue;
            }
            $identity = $this->extractEasyClientIdentity($row);
            $related = isset($row['related']) && is_array($row['related']) ? $row['related'] : [];
            $relatedId = (string) ($row['related_id'] ?? $row['server_id'] ?? $row['device_id'] ?? $row['item_id'] ?? '');
            $label = $this->extractPreferredLabel($row);
            $hostname = $this->extractPreferredHostname($row);
            $iloIp = $this->extractManagementIp($row);
            $ip = (string) ($row['ip'] ?? $row['dedicated_ip'] ?? $row['ipv4'] ?? '');
            if ($ip === '' && isset($related['ip_addresses']) && is_array($related['ip_addresses'])) {
                foreach ($related['ip_addresses'] as $ipRow) {
                    if (!is_array($ipRow)) {
                        continue;
                    }
                    $cand = trim((string) ($ipRow['address'] ?? $ipRow['ip'] ?? ''));
                    if ($cand !== '') {
                        $ip = $cand;
                        break;
                    }
                }
            }
            if ($ip === '') {
                $ip = trim((string) ($related['ip'] ?? $related['dedicated_ip'] ?? $related['ipv4'] ?? ''));
            }
            $state = strtolower((string) ($row['status'] ?? $row['state'] ?? ''));
            $upRaw = $row['is_up'] ?? $row['up'] ?? null;
            $isUp = false;
            if (is_bool($upRaw)) {
                $isUp = $upRaw;
            } elseif (is_numeric($upRaw)) {
                $isUp = (int) $upRaw === 1;
            } elseif (is_string($upRaw) && $upRaw !== '') {
                $isUp = in_array(strtolower($upRaw), ['1', 'true', 'up', 'active', 'enabled', 'online'], true);
            } else {
                $isUp = in_array($state, ['up', 'active', 'enabled', 'online'], true);
            }
            $normalized[] = [
                'service_id' => (string) ($row['id'] ?? $row['service_id'] ?? ''),
                'server_id' => $relatedId,
                'order_id' => (string) ($row['order_id'] ?? $row['orderId'] ?? (($row['order']['id'] ?? $row['order']['order_id'] ?? ''))),
                'ip' => $ip,
                'ilo_ip' => $iloIp,
                'label' => $label,
                'hostname' => $hostname,
                'status' => (string) ($row['status'] ?? $row['state'] ?? ''),
                'client_name' => $identity['name'],
                'client_email' => $identity['email'],
                'is_up' => $isUp,
            ];
        }
        $clean = [];
        foreach ($normalized as $item) {
            $serviceId = trim((string) ($item['service_id'] ?? ''));
            $serverId = trim((string) ($item['server_id'] ?? ''));
            $orderId = trim((string) ($item['order_id'] ?? ''));
            $ip = trim((string) ($item['ip'] ?? ''));
            $status = strtolower(trim((string) ($item['status'] ?? '')));
            if ($serviceId === '' && $serverId === '' && $orderId === '' && $ip === '') {
                continue;
            }
            if ($serviceId !== '' && $serverId === '' && $orderId === '' && $ip === '' && in_array($status, ['accepted', 'pending', 'rejected'], true)) {
                continue;
            }
            $clean[] = $item;
        }
        return $clean;
    }

    private function extractEasyClientIdentity(array $row): array
    {
        $name = '';
        $email = '';

        $emailKeys = ['email', 'client_email', 'user_email', 'customer_email'];
        foreach ($emailKeys as $k) {
            $v = trim((string) ($row[$k] ?? ''));
            if ($v !== '') {
                $email = $v;
                break;
            }
        }
        if ($email === '') {
            foreach (['client', 'user', 'customer', 'owner', 'account'] as $node) {
                if (!isset($row[$node]) || !is_array($row[$node])) {
                    continue;
                }
                foreach ($emailKeys as $k) {
                    $v = trim((string) ($row[$node][$k] ?? ''));
                    if ($v !== '') {
                        $email = $v;
                        break 2;
                    }
                }
            }
        }

        $directName = trim((string) ($row['name'] ?? ''));
        if ($directName !== '') {
            $name = $directName;
        }
        if ($name === '') {
            $full = trim((string) ($row['fullname'] ?? $row['full_name'] ?? ''));
            if ($full !== '') {
                $name = $full;
            }
        }
        if ($name === '') {
            $first = trim((string) ($row['firstname'] ?? $row['first_name'] ?? ''));
            $last = trim((string) ($row['lastname'] ?? $row['last_name'] ?? ''));
            $joined = trim($first . ' ' . $last);
            if ($joined !== '') {
                $name = $joined;
            }
        }
        if ($name === '') {
            foreach (['client', 'user', 'customer', 'owner', 'account'] as $node) {
                if (!isset($row[$node]) || !is_array($row[$node])) {
                    continue;
                }
                $nested = trim((string) ($row[$node]['name'] ?? $row[$node]['fullname'] ?? $row[$node]['full_name'] ?? ''));
                if ($nested !== '') {
                    $name = $nested;
                    break;
                }
                $first = trim((string) ($row[$node]['firstname'] ?? $row[$node]['first_name'] ?? ''));
                $last = trim((string) ($row[$node]['lastname'] ?? $row[$node]['last_name'] ?? ''));
                $joined = trim($first . ' ' . $last);
                if ($joined !== '') {
                    $name = $joined;
                    break;
                }
            }
        }

        return ['name' => $name, 'email' => $email];
    }

    private function extractPreferredLabel(array $row): string
    {
        foreach (['label', 'name', 'hostname', 'title', 'server_label', 'service_label'] as $k) {
            $v = trim((string) ($row[$k] ?? ''));
            if ($v !== '') {
                return $v;
            }
        }
        foreach (['related', 'service', 'order', 'item', 'server'] as $node) {
            if (!isset($row[$node]) || !is_array($row[$node])) {
                continue;
            }
            foreach (['label', 'name', 'hostname', 'title'] as $k) {
                $v = trim((string) ($row[$node][$k] ?? ''));
                if ($v !== '') {
                    return $v;
                }
            }
        }
        return '';
    }

    private function extractPreferredHostname(array $row): string
    {
        foreach (['hostname', 'host_name', 'fqdn', 'domain', 'server_hostname'] as $k) {
            $v = trim((string) ($row[$k] ?? ''));
            if ($v !== '') {
                return $v;
            }
        }
        foreach (['related', 'service', 'order', 'item', 'server'] as $node) {
            if (!isset($row[$node]) || !is_array($row[$node])) {
                continue;
            }
            foreach (['hostname', 'host_name', 'fqdn', 'domain', 'server_hostname'] as $k) {
                $v = trim((string) ($row[$node][$k] ?? ''));
                if ($v !== '') {
                    return $v;
                }
            }
        }
        return '';
    }

    private function extractManagementIp(array $row): string
    {
        $keys = ['ilo_ip', 'ipmi_ip', 'idrac_ip', 'bmc_ip', 'management_ip', 'mgmt_ip', 'out_of_band_ip'];
        foreach ($keys as $k) {
            $v = trim((string) ($row[$k] ?? ''));
            if ($v !== '') {
                return $v;
            }
        }
        foreach (['related', 'service', 'order', 'item', 'server', 'management', 'oob'] as $node) {
            if (!isset($row[$node]) || !is_array($row[$node])) {
                continue;
            }
            foreach ($keys as $k) {
                $v = trim((string) ($row[$node][$k] ?? ''));
                if ($v !== '') {
                    return $v;
                }
            }
            foreach (['ip', 'ipv4'] as $k) {
                $v = trim((string) ($row[$node][$k] ?? ''));
                if ($v !== '' && !filter_var($v, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_IPV6)) {
                    continue;
                }
                if ($v !== '') {
                    return $v;
                }
            }
        }
        return '';
    }

    private function extractListFromObject(array $obj): array
    {
        foreach (['items', 'data', 'records', 'rows', 'services', 'collection'] as $key) {
            if (isset($obj[$key]) && is_array($obj[$key])) {
                return $obj[$key];
            }
        }
        if (array_keys($obj) === range(0, count($obj) - 1)) {
            return $obj;
        }
        return [$obj];
    }

    private function getServiceIdFromOrderMapCache(string $orderId): string
    {
        $orderId = trim($orderId);
        if ($orderId === '') {
            return '';
        }
        $raw = (string) Capsule::table('mod_easydcim_bw_guard_meta')
            ->where('meta_key', 'order_service_map_' . $orderId)
            ->value('meta_value');
        if ($raw === '') {
            return '';
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return '';
        }
        return trim((string) ($decoded['service_id'] ?? ''));
    }

    private function portsFromOrderDetails(EasyDcimClient $client, string $orderId): array
    {
        $orderId = trim($orderId);
        if ($orderId === '') {
            return ['ok' => false, 'reachable' => false, 'items' => []];
        }
        if (isset($this->orderPortsRuntimeCache[$orderId]) && is_array($this->orderPortsRuntimeCache[$orderId])) {
            return $this->orderPortsRuntimeCache[$orderId];
        }

        $result = ['ok' => false, 'reachable' => false, 'items' => []];
        try {
            $details = $this->getOrderDetailsCached($client, $orderId);
            $data = $this->extractApiDataPayload($details);
            $httpCode = (int) ($details['http_code'] ?? 0);
            $reachable = $httpCode >= 200 && $httpCode < 300;
            $items = $this->extractPortsRecursive($data);
            if (empty($items) && $reachable && $this->shouldTryOrderPortsEndpoint()) {
                $orderPortsResp = $client->orderPorts($orderId, true);
                $orderPortsCode = (int) ($orderPortsResp['http_code'] ?? 0);
                if ($orderPortsCode >= 200 && $orderPortsCode < 300) {
                    $items = $this->extractPortItems((array) ($orderPortsResp['data'] ?? []));
                    if (!empty($items)) {
                        $this->logger->log('INFO', 'resolved_ports_from_order', [
                            'order_id' => $orderId,
                            'count' => count($items),
                            'http_code' => $orderPortsCode,
                            'via' => 'admin_order_ports_endpoint',
                        ]);
                        $result = ['ok' => true, 'reachable' => true, 'items' => $items];
                        $this->orderPortsRuntimeCache[$orderId] = $result;
                        $this->setOrderPortsEndpointSupported(true);
                        return $result;
                    }
                } elseif ($orderPortsCode === 404) {
                    $this->setOrderPortsEndpointSupported(false);
                }
            }
            if (!empty($items)) {
                $this->logger->log('INFO', 'resolved_ports_from_order', [
                    'order_id' => $orderId,
                    'count' => count($items),
                    'http_code' => $httpCode,
                ]);
                $result = ['ok' => true, 'reachable' => $reachable, 'items' => $items];
                $this->orderPortsRuntimeCache[$orderId] = $result;
                return $result;
            }
            $this->logger->log('INFO', 'resolved_ports_from_order_empty', [
                'order_id' => $orderId,
                'http_code' => $httpCode,
                'top_keys' => array_slice(array_values(array_map(static fn ($k): string => (string) $k, array_keys($data))), 0, 25),
                'port_paths' => $this->findPortLikePaths($data, 18),
            ]);
            if (empty($items) && $reachable) {
                $serverId = trim((string) ($data['related_id'] ?? $data['server_id'] ?? $data['item_id'] ?? ''));
                if ($serverId === '' && isset($data['related']) && is_array($data['related'])) {
                    $serverId = trim((string) ($data['related']['id'] ?? $data['related']['server_id'] ?? $data['related']['item_id'] ?? ''));
                }
                if ($serverId !== '') {
                    $serverDetailsResp = $client->serverDetailsById($serverId);
                    $serverDetailsCode = (int) ($serverDetailsResp['http_code'] ?? 0);
                    if ($serverDetailsCode >= 200 && $serverDetailsCode < 300) {
                        $serverDetailsData = $this->extractApiDataPayload($serverDetailsResp);
                        $serverItems = $this->extractPortsRecursive($serverDetailsData);
                        if (!empty($serverItems)) {
                            $this->logger->log('INFO', 'resolved_ports_from_server_details', [
                                'order_id' => $orderId,
                                'server_id' => $serverId,
                                'count' => count($serverItems),
                                'http_code' => $serverDetailsCode,
                            ]);
                            $result = ['ok' => true, 'reachable' => true, 'items' => $serverItems];
                            $this->orderPortsRuntimeCache[$orderId] = $result;
                            return $result;
                        }
                    }
                }
            }
            $result = ['ok' => false, 'reachable' => $reachable, 'items' => []];
        } catch (\Throwable $e) {
            $this->logger->log('WARNING', 'resolve_ports_from_order_failed', [
                'order_id' => $orderId,
                'error' => $e->getMessage(),
            ]);
        }
        $this->orderPortsRuntimeCache[$orderId] = $result;
        return $result;
    }

    private function extractApiDataPayload(array $response): array
    {
        $decoded = (array) ($response['data'] ?? []);
        if (isset($decoded['data']) && is_array($decoded['data'])) {
            return (array) $decoded['data'];
        }
        return $decoded;
    }

    private function shouldTryOrderPortsEndpoint(): bool
    {
        if ($this->orderPortsEndpointSupported !== null) {
            return $this->orderPortsEndpointSupported;
        }

        $raw = (string) Capsule::table('mod_easydcim_bw_guard_meta')
            ->where('meta_key', 'order_ports_endpoint_supported')
            ->value('meta_value');
        if ($raw === '0') {
            $this->orderPortsEndpointSupported = false;
            return false;
        }
        if ($raw === '1') {
            $this->orderPortsEndpointSupported = true;
            return true;
        }
        $this->orderPortsEndpointSupported = true;
        return true;
    }

    private function setOrderPortsEndpointSupported(bool $supported): void
    {
        $this->orderPortsEndpointSupported = $supported;
        Capsule::table('mod_easydcim_bw_guard_meta')->updateOrInsert(
            ['meta_key' => 'order_ports_endpoint_supported'],
            ['meta_value' => $supported ? '1' : '0', 'updated_at' => date('Y-m-d H:i:s')]
        );
    }

    private function findPortLikePaths($value, int $limit = 20, string $path = ''): array
    {
        $found = [];
        $walk = function ($node, string $nodePath) use (&$walk, &$found, $limit): void {
            if (count($found) >= $limit) {
                return;
            }
            if (is_array($node)) {
                foreach ($node as $k => $child) {
                    if (count($found) >= $limit) {
                        return;
                    }
                    $key = is_string($k) ? $k : '[' . $k . ']';
                    $childPath = $nodePath === '' ? $key : $nodePath . '.' . $key;
                    $keyLower = strtolower((string) $k);
                    if (is_string($k) && preg_match('/port|interface|connection|uplink|ethernet/i', $keyLower)) {
                        $found[] = $childPath;
                    }
                    $walk($child, $childPath);
                }
                return;
            }
            if (is_string($node) && preg_match('/#\\d+\\s+.*(port|ethernet|gig|eth)/i', $node)) {
                $found[] = $nodePath . '="' . substr($node, 0, 70) . '"';
            }
        };
        $walk($value, $path);
        return array_values(array_unique($found));
    }

    private function extractPortsRecursive($value, string $parentKey = ''): array
    {
        $result = [];
        if (is_array($value)) {
            $parsed = $this->parsePortRow($value, $parentKey);
            if ($parsed !== null) {
                $result[] = $parsed;
            }

            foreach ($value as $k => $child) {
                if (is_array($child)) {
                    $nextParent = is_string($k) ? strtolower(trim($k)) : $parentKey;
                    foreach ($this->extractPortsRecursive($child, $nextParent) as $p) {
                        $result[] = $p;
                    }
                }
            }
        }
        return $result;
    }

    private function parsePortRow(array $row, string $parentKey = ''): ?array
    {
        if (!$this->isLikelyPortRow($row, $parentKey)) {
            return null;
        }

        $selectedPort = $this->extractMixedRef($row['selected_port'] ?? $row['selectedPort'] ?? $row['port'] ?? $row['interface'] ?? null);
        $connectedPort = $this->extractMixedRef($row['connected_port'] ?? $row['connectedPort'] ?? $row['conn_port'] ?? null);
        $connectedItem = $this->extractMixedRef($row['connected_item'] ?? $row['connectedItem'] ?? $row['connection_item'] ?? $row['conn_item'] ?? null);

        $portId = trim((string) ($row['port_id'] ?? $row['portId'] ?? $row['portid'] ?? $row['id'] ?? $selectedPort['id'] ?? ''));
        $name = trim((string) ($row['name'] ?? $row['label'] ?? $row['user_label'] ?? $row['interface_name'] ?? $row['if_name'] ?? $row['ifname'] ?? $row['description'] ?? $row['number'] ?? $row['port_number'] ?? ''));
        $connectedPortId = trim((string) ($row['connected_port_id'] ?? $row['conn_port_id'] ?? $row['port_connection_id'] ?? $connectedPort['id'] ?? ''));
        $connectedItemId = trim((string) ($row['connected_item_id'] ?? $row['conn_item_id'] ?? $row['connection_item_id'] ?? $connectedItem['id'] ?? ''));
        $connectedPortLabel = trim((string) ($row['connected_port_label'] ?? $row['connected_port_name'] ?? $row['connected_port_description'] ?? $row['connected_port_number'] ?? $connectedPort['label'] ?? ''));
        $connectedItemLabel = trim((string) ($row['connected_item_label'] ?? $row['connected_item_name'] ?? $row['connected_item_description'] ?? $row['connection_item_label'] ?? $row['connection_item_name'] ?? $connectedItem['label'] ?? ''));
        if ($name === '' || $this->isSimpleNumericLabel($name)) {
            $name = $this->pickDescriptivePortLabel([
                $selectedPort['label'] ?? '',
                $connectedPortLabel,
                $connectedItemLabel,
                $this->extractPortLikeLabelFromRow($row),
            ], $name);
        }
        if ($connectedPortLabel === '' && isset($row['connected_port']) && is_scalar($row['connected_port'])) {
            $connectedPortLabel = trim((string) $row['connected_port']);
        }
        if ($connectedPortLabel === '') {
            $connectedPortLabel = $this->pickDescriptivePortLabel([
                $connectedPort['label'] ?? '',
                $selectedPort['label'] ?? '',
                $this->extractPortLikeLabelFromRow($row),
            ]);
        }
        if ($connectedItemLabel === '' && isset($row['connected_item']) && is_scalar($row['connected_item'])) {
            $connectedItemLabel = trim((string) $row['connected_item']);
        }
        if ($connectedPortLabel !== '' && $connectedPortId !== '' && !str_contains($connectedPortLabel, '#' . $connectedPortId)) {
            $connectedPortLabel = '#' . $connectedPortId . ' ' . $connectedPortLabel;
        } elseif ($connectedPortLabel === '' && $connectedPortId !== '') {
            $connectedPortLabel = '#' . $connectedPortId;
        }
        if ($connectedItemLabel !== '' && $connectedItemId !== '' && !str_contains($connectedItemLabel, '#' . $connectedItemId)) {
            $connectedItemLabel = '#' . $connectedItemId . ' ' . $connectedItemLabel;
        } elseif ($connectedItemLabel === '' && $connectedItemId !== '') {
            $connectedItemLabel = '#' . $connectedItemId;
        }
        if ($name === '' && $portId !== '') {
            $name = '#' . $portId;
        } elseif ($name !== '' && $portId !== '' && !str_contains($name, '#' . $portId) && !$this->isSimpleNumericLabel($name)) {
            $name = '#' . $portId . ' ' . $name;
        }
        if ($name === '' && $connectedPortLabel === '' && $portId === '' && $connectedPortId === '' && $connectedItemId === '') {
            return null;
        }

        $trafficTotal = $this->extractTrafficTotal($row);
        $statusParts = [
            is_scalar($row['status'] ?? null) ? strtolower(trim((string) ($row['status'] ?? ''))) : '',
            is_scalar($row['state'] ?? null) ? strtolower(trim((string) ($row['state'] ?? ''))) : '',
            strtolower(trim((string) ($row['admin_state'] ?? $row['adminState'] ?? ''))),
            strtolower(trim((string) ($row['oper_state'] ?? $row['operState'] ?? ''))),
            strtolower(trim((string) ($selectedPort['state'] ?? ''))),
            strtolower(trim((string) ($connectedPort['state'] ?? ''))),
            strtolower(trim((string) ($connectedItem['state'] ?? ''))),
        ];
        $status = trim(implode(' ', array_filter($statusParts, static fn (string $v): bool => $v !== '')));
        $state = 'unknown';
        if ($status !== '' && preg_match('/down|inactive|disabled|offline|not.?connected|disconnected|link.?down|error|fault/i', $status)) {
            $state = 'down';
        } elseif ($status !== '' && preg_match('/up|active|enabled|online|accepted|connected|link.?up|running/i', $status)) {
            $state = 'up';
        }
        if ($state === 'unknown') {
            $numericCandidates = [
                $row['status'] ?? null,
                $row['admin_state'] ?? null,
                $row['oper_state'] ?? null,
                $selectedPort['state'] ?? null,
                $connectedPort['state'] ?? null,
                $connectedItem['state'] ?? null,
            ];
            $sawNegative = false;
            foreach ($numericCandidates as $numericStatus) {
                if (!is_numeric($numericStatus)) {
                    continue;
                }
                $statusInt = (int) $numericStatus;
                if ($statusInt > 0) {
                    $state = 'up';
                    break;
                }
                if ($statusInt < 0) {
                    $sawNegative = true;
                }
            }
            if ($state === 'unknown' && $sawNegative) {
                $state = 'down';
            }
        }

        $upRaw = $row['is_up'] ?? $row['up'] ?? $row['link_up'] ?? null;
        $isUp = false;
        if (is_bool($upRaw)) {
            $isUp = $upRaw;
            $state = $isUp ? 'up' : 'down';
        } elseif (is_numeric($upRaw)) {
            $isUp = (int) $upRaw === 1;
            $state = $isUp ? 'up' : 'down';
        } elseif (is_string($upRaw) && trim($upRaw) !== '') {
            $isUp = in_array(strtolower(trim($upRaw)), ['1', 'true', 'up', 'active', 'enabled', 'online', 'accepted', 'connected', 'running'], true);
            $state = $isUp ? 'up' : 'down';
        } elseif ($state === 'up') {
            $isUp = true;
        } elseif ($state === 'down') {
            $isUp = false;
        } elseif ($trafficTotal > 0.0) {
            $isUp = true;
            $state = 'up';
        }
        if ($isUp && $trafficTotal <= 0.0 && $state === 'up') {
            $state = 'idle';
        }

        $speed = $this->normalizePortSpeedValue($row['speed'] ?? $row['speed_label'] ?? $row['speed_human'] ?? $row['bandwidth'] ?? $row['port_speed'] ?? $row['link_speed'] ?? null);
        if ($speed === '') {
            $speed = $this->normalizePortSpeedValue($selectedPort['speed'] ?? $connectedPort['speed'] ?? $connectedItem['speed'] ?? null);
        }
        if ($speed === '') {
            $speed = $this->extractSpeedFromText(trim($name . ' ' . $connectedPortLabel . ' ' . (string) ($connectedItem['label'] ?? '')));
        }

        return [
            'name' => $name,
            'description' => (string) ($row['description'] ?? ''),
            'type' => (string) ($row['type'] ?? $row['port_type'] ?? ''),
            'state' => $state,
            'is_up' => $isUp,
            'traffic_total' => $trafficTotal,
            'speed' => $speed,
            'port_id' => $portId,
            'connected_item_id' => $connectedItemId,
            'connected_port_id' => $connectedPortId,
            'connected_port_label' => $connectedPortLabel,
            'connected_item_label' => $connectedItemLabel,
        ];
    }

    private function extractMixedRef($value): array
    {
        $id = '';
        $label = '';
        $state = '';
        $speed = '';

        if (is_array($value)) {
            $id = trim((string) ($value['id'] ?? $value['port_id'] ?? $value['item_id'] ?? $value['related_id'] ?? ''));
            $label = trim((string) ($value['name'] ?? $value['label'] ?? $value['port_name'] ?? $value['interface_name'] ?? $value['if_name'] ?? $value['ifname'] ?? $value['display_name'] ?? $value['user_label'] ?? $value['description'] ?? $value['number'] ?? $value['port'] ?? $value['title'] ?? ''));
            $state = trim((string) ($value['status'] ?? $value['state'] ?? $value['admin_state'] ?? $value['oper_state'] ?? ''));
            $speed = $this->normalizePortSpeedValue($value['speed'] ?? $value['speed_label'] ?? $value['speed_human'] ?? $value['bandwidth'] ?? $value['port_speed'] ?? null);
            if ($id === '' && $label !== '' && preg_match('/#\\s*(\\d+)/', $label, $m)) {
                $id = trim((string) $m[1]);
            }
            if ($speed === '') {
                $speed = $this->extractSpeedFromText($label);
            }
            return ['id' => $id, 'label' => $label, 'state' => $state, 'speed' => $speed];
        }

        if (!is_scalar($value)) {
            return ['id' => '', 'label' => '', 'state' => '', 'speed' => ''];
        }

        $text = trim((string) $value);
        if ($text === '') {
            return ['id' => '', 'label' => '', 'state' => '', 'speed' => ''];
        }
        if (preg_match('/#\\s*(\\d+)/', $text, $m)) {
            $id = trim((string) $m[1]);
        } elseif (ctype_digit($text)) {
            $id = $text;
        }
        if ($id !== '' && $this->isSimpleNumericLabel($text)) {
            $text = '#' . $id;
        }
        return ['id' => $id, 'label' => $text, 'state' => '', 'speed' => $this->extractSpeedFromText($text)];
    }

    private function extractTrafficTotal(array $row): float
    {
        if (isset($row['traffic_total']) && is_numeric($row['traffic_total'])) {
            return (float) $row['traffic_total'];
        }
        if (isset($row['total']) && is_numeric($row['total'])) {
            return (float) $row['total'];
        }
        if (isset($row['usage']) && is_numeric($row['usage'])) {
            return (float) $row['usage'];
        }
        if (isset($row['total_1m']) && is_numeric($row['total_1m'])) {
            return (float) $row['total_1m'];
        }
        if (isset($row['traffic']) && is_array($row['traffic'])) {
            foreach (['total', 'total_1m', 'usage', 'sum', 'value'] as $k) {
                if (isset($row['traffic'][$k]) && is_numeric($row['traffic'][$k])) {
                    return (float) $row['traffic'][$k];
                }
            }
            $sum = 0.0;
            $seen = false;
            foreach (['in', 'out', 'rx', 'tx', 'download', 'upload'] as $k) {
                if (isset($row['traffic'][$k]) && is_numeric($row['traffic'][$k])) {
                    $sum += (float) $row['traffic'][$k];
                    $seen = true;
                }
            }
            if ($seen) {
                return $sum;
            }
        }
        return 0.0;
    }

    private function resolveServiceIdFromOrder(EasyDcimClient $client, string $orderId, bool $allowClientScan = true): string
    {
        $orderId = trim($orderId);
        if ($orderId === '') {
            return '';
        }
        if (array_key_exists($orderId, $this->orderServiceRuntimeCache)) {
            return (string) $this->orderServiceRuntimeCache[$orderId];
        }
        $cacheKey = 'order_service_map_' . $orderId;
        $cachedRaw = (string) Capsule::table('mod_easydcim_bw_guard_meta')->where('meta_key', $cacheKey)->value('meta_value');
        if ($cachedRaw !== '') {
            $cached = json_decode($cachedRaw, true);
            if (is_array($cached) && isset($cached['checked_at'], $cached['service_id']) && strtotime((string) $cached['checked_at']) > time() - 1800) {
                $cachedId = trim((string) $cached['service_id']);
                $this->orderServiceRuntimeCache[$orderId] = $cachedId;
                return $cachedId;
            }
        }
        try {
            $details = $this->getOrderDetailsCached($client, $orderId);
            $data = $this->extractApiDataPayload($details);
            $id = $this->findServiceIdRecursive($data);
            if ($id !== '') {
                Capsule::table('mod_easydcim_bw_guard_meta')->updateOrInsert(
                    ['meta_key' => $cacheKey],
                    ['meta_value' => json_encode(['service_id' => $id, 'checked_at' => date('Y-m-d H:i:s')], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'updated_at' => date('Y-m-d H:i:s')]
                );
                $this->logger->log('INFO', 'resolved_service_id_from_order', [
                    'order_id' => $orderId,
                    'service_id' => $id,
                        'via' => 'admin_order_details',
                        'http_code' => (int) ($details['http_code'] ?? 0),
                ]);
                $this->orderServiceRuntimeCache[$orderId] = $id;
                return $id;
            }
        } catch (\Throwable $e) {
            $this->logger->log('WARNING', 'resolve_service_id_from_order_failed', [
                'order_id' => $orderId,
                'error' => $e->getMessage(),
            ]);
        }

        if (!$allowClientScan) {
            $this->logger->log('WARNING', 'resolve_service_id_from_order_empty', ['order_id' => $orderId, 'scan' => 'disabled']);
            Capsule::table('mod_easydcim_bw_guard_meta')->updateOrInsert(
                ['meta_key' => $cacheKey],
                ['meta_value' => json_encode(['service_id' => '', 'checked_at' => date('Y-m-d H:i:s')], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'updated_at' => date('Y-m-d H:i:s')]
            );
            $this->orderServiceRuntimeCache[$orderId] = '';
            return '';
        }

        // fallback: scan client/services pages and match order_id
        try {
            for ($page = 1; $page <= 3; $page++) {
                $resp = $client->listServices(null, ['page' => $page, 'per_page' => 100]);
                $items = $this->extractServiceItems((array) ($resp['data'] ?? []));
                foreach ($items as $item) {
                    if (trim((string) ($item['order_id'] ?? '')) !== $orderId) {
                        continue;
                    }
                    $sid = trim((string) ($item['service_id'] ?? ''));
                    if ($sid === '') {
                        continue;
                    }
                    $this->logger->log('INFO', 'resolved_service_id_from_order', [
                        'order_id' => $orderId,
                        'service_id' => $sid,
                        'via' => 'client_services_scan',
                        'http_code' => (int) ($resp['http_code'] ?? 0),
                        'page' => $page,
                    ]);
                    Capsule::table('mod_easydcim_bw_guard_meta')->updateOrInsert(
                        ['meta_key' => $cacheKey],
                        ['meta_value' => json_encode(['service_id' => $sid, 'checked_at' => date('Y-m-d H:i:s')], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'updated_at' => date('Y-m-d H:i:s')]
                    );
                    $this->orderServiceRuntimeCache[$orderId] = $sid;
                    return $sid;
                }
                if (count($items) < 100) {
                    break;
                }
            }
        } catch (\Throwable $e) {
            $this->logger->log('WARNING', 'resolve_service_id_from_order_scan_failed', [
                'order_id' => $orderId,
                'error' => $e->getMessage(),
            ]);
        }

        $this->logger->log('WARNING', 'resolve_service_id_from_order_empty', ['order_id' => $orderId]);
        Capsule::table('mod_easydcim_bw_guard_meta')->updateOrInsert(
            ['meta_key' => $cacheKey],
            ['meta_value' => json_encode(['service_id' => '', 'checked_at' => date('Y-m-d H:i:s')], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'updated_at' => date('Y-m-d H:i:s')]
        );
        $this->orderServiceRuntimeCache[$orderId] = '';
        return '';
    }

    private function getOrderDetailsCached(EasyDcimClient $client, string $orderId): array
    {
        $orderId = trim($orderId);
        if ($orderId === '') {
            return ['http_code' => 0, 'data' => [], 'raw' => null, 'error' => 'missing_order_id'];
        }
        if (isset($this->orderDetailsRuntimeCache[$orderId]) && is_array($this->orderDetailsRuntimeCache[$orderId])) {
            return $this->orderDetailsRuntimeCache[$orderId];
        }
        $details = $client->orderDetails($orderId);
        $this->orderDetailsRuntimeCache[$orderId] = $details;
        return $details;
    }

    private function resolveOrderIdFromServer(EasyDcimClient $client, string $serverId, string $ip = ''): string
    {
        $serverId = trim($serverId);
        if ($serverId === '') {
            return '';
        }
        $cacheKey = 'server_order_map_' . $serverId;
        $cachedRaw = (string) Capsule::table('mod_easydcim_bw_guard_meta')->where('meta_key', $cacheKey)->value('meta_value');
        if ($cachedRaw !== '') {
            $cached = json_decode($cachedRaw, true);
            if (is_array($cached) && isset($cached['checked_at'], $cached['order_id']) && strtotime((string) $cached['checked_at']) > time() - 1800) {
                return trim((string) $cached['order_id']);
            }
        }

        try {
            $pickedOrder = '';
            $pickedIp = trim($ip);
            for ($page = 1; $page <= 3; $page++) {
                $resp = $client->listAdminOrders(['page' => $page, 'per_page' => 100]);
                $httpCode = (int) ($resp['http_code'] ?? 0);
                if ($httpCode < 200 || $httpCode >= 300) {
                    break;
                }
                $rows = $this->extractListFromObject((array) ($resp['data'] ?? []));
                if (empty($rows)) {
                    break;
                }
                foreach ($rows as $row) {
                    if (!is_array($row)) {
                        continue;
                    }
                    $rowServer = trim((string) ($row['related_id'] ?? $row['server_id'] ?? $row['item_id'] ?? ''));
                    if ($rowServer !== $serverId) {
                        continue;
                    }
                    $orderId = trim((string) ($row['id'] ?? $row['order_id'] ?? $row['orderId'] ?? ''));
                    if ($orderId === '') {
                        continue;
                    }
                    $rowIp = trim((string) ($row['ip'] ?? $row['dedicated_ip'] ?? $row['ipv4'] ?? ''));
                    if ($rowIp === '' && isset($row['related']) && is_array($row['related'])) {
                        $rowIp = trim((string) ($row['related']['ip'] ?? $row['related']['dedicated_ip'] ?? $row['related']['ipv4'] ?? ''));
                    }
                    if ($pickedIp !== '' && $rowIp !== '' && $rowIp === $pickedIp) {
                        $pickedOrder = $orderId;
                        break 2;
                    }
                    if ($pickedOrder === '') {
                        $pickedOrder = $orderId;
                    }
                }
                if (count($rows) < 100) {
                    break;
                }
            }

            if ($pickedOrder !== '') {
                Capsule::table('mod_easydcim_bw_guard_meta')->updateOrInsert(
                    ['meta_key' => $cacheKey],
                    ['meta_value' => json_encode(['order_id' => $pickedOrder, 'checked_at' => date('Y-m-d H:i:s')], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'updated_at' => date('Y-m-d H:i:s')]
                );
                $this->logger->log('INFO', 'resolved_order_id_from_server', [
                    'server_id' => $serverId,
                    'order_id' => $pickedOrder,
                    'ip' => $pickedIp,
                ]);
                return $pickedOrder;
            }
        } catch (\Throwable $e) {
            $this->logger->log('WARNING', 'resolve_order_id_from_server_failed', [
                'server_id' => $serverId,
                'error' => $e->getMessage(),
            ]);
        }

        Capsule::table('mod_easydcim_bw_guard_meta')->updateOrInsert(
            ['meta_key' => $cacheKey],
            ['meta_value' => json_encode(['order_id' => '', 'checked_at' => date('Y-m-d H:i:s')], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'updated_at' => date('Y-m-d H:i:s')]
        );
        return '';
    }

    private function findServiceIdRecursive($value): string
    {
        if (is_array($value)) {
            foreach (['service_id', 'serviceId'] as $k) {
                if (isset($value[$k]) && trim((string) $value[$k]) !== '') {
                    return trim((string) $value[$k]);
                }
            }
            if (isset($value['service']) && is_array($value['service'])) {
                $sid = trim((string) ($value['service']['id'] ?? $value['service']['service_id'] ?? ''));
                if ($sid !== '') {
                    return $sid;
                }
            }
            foreach ($value as $child) {
                $sid = $this->findServiceIdRecursive($child);
                if ($sid !== '') {
                    return $sid;
                }
            }
        }
        return '';
    }

    private function extractPortItems(array $payload): array
    {
        $items = [];
        if (isset($payload['data']) && is_array($payload['data'])) {
            $items = $this->extractListFromObject($payload['data']);
        } elseif (isset($payload['ports']) && is_array($payload['ports'])) {
            $items = $this->extractListFromObject($payload['ports']);
        } elseif (isset($payload['result']) && is_array($payload['result'])) {
            $items = $this->extractListFromObject($payload['result']);
        } elseif (array_keys($payload) === range(0, count($payload) - 1)) {
            $items = $payload;
        } else {
            // Avoid treating API error objects as one fake port row.
            if (isset($payload['id']) || isset($payload['name']) || isset($payload['label']) || isset($payload['port'])) {
                $items = [$payload];
            } else {
                $items = [];
            }
        }

        if (empty($items)) {
            $items = $this->extractListFromObject($payload);
        }

        $out = [];
        foreach ($items as $row) {
            if (!is_array($row)) {
                continue;
            }
            $parsed = $this->parsePortRow($row);
            if ($parsed === null) {
                continue;
            }
            $out[] = $parsed;
        }

        if (empty($out)) {
            $out = $this->extractPortsRecursive($payload);
        }

        return $out;
    }

    private function isLikelyPortRow(array $row, string $parentKey = ''): bool
    {
        $keys = array_map(static fn ($k): string => strtolower((string) $k), array_keys($row));
        $entityOnlyKeys = ['order_id', 'service_id', 'userid', 'client_id', 'product_id', 'packageid', 'domainstatus'];
        $entityHits = 0;
        foreach ($entityOnlyKeys as $k) {
            if (in_array($k, $keys, true)) {
                $entityHits++;
            }
        }
        $portKeys = [
            'port', 'port_id', 'portid', 'interface', 'port_type',
            'number', 'port_number', 'user_label', 'connection', 'connection_item',
            'selected_port', 'selectedport', 'connected_port', 'connectedport', 'connected_item', 'connecteditem',
            'connected_port_id', 'conn_port_id', 'conn_port',
            'connected_item_id', 'conn_item_id', 'conn_item',
            'connected_port_label', 'connected_port_name',
            'admin_state', 'oper_state', 'speed', 'vlan', 'vlans', 'duplex',
        ];
        $portHit = 0;
        foreach ($portKeys as $k) {
            if (in_array($k, $keys, true)) {
                $portHit++;
            }
        }
        if ($portHit > 0) {
            return true;
        }
        if ($entityHits >= 2) {
            return false;
        }
        if (in_array($parentKey, ['ports', 'port', 'portconnections', 'connections', 'interfaces', 'networkinterfaces', 'switchports', 'uplinks', 'links'], true)) {
            if (isset($row['id']) || isset($row['name']) || isset($row['label']) || isset($row['number'])) {
                return true;
            }
        }
        if ($parentKey !== '' && preg_match('/port|interface|connection|uplink|switch/i', $parentKey)) {
            if (isset($row['id']) || isset($row['name']) || isset($row['label']) || isset($row['number']) || isset($row['description'])) {
                return true;
            }
        }
        $label = strtolower(trim((string) ($row['name'] ?? $row['label'] ?? $row['description'] ?? '')));
        if ($label !== '' && preg_match('/(port|ethernet|gig|gi\\d|te\\d|eth\\d)/i', $label)) {
            return true;
        }
        foreach (['selected_port', 'connected_port', 'connected_item'] as $mixedKey) {
            if (!isset($row[$mixedKey])) {
                continue;
            }
            $ref = $this->extractMixedRef($row[$mixedKey]);
            if ($ref['id'] !== '' || $ref['label'] !== '') {
                return true;
            }
        }
        return false;
    }

    private function renderPortStatusHtml(array $svc, ?array $testCache): string
    {
        $summary = (string) ($svc['ports_summary'] ?? $this->t('no_data'));
        if (($summary === '' || $summary === $this->t('no_data')) && is_array($testCache)) {
            $summary = (string) ($testCache['summary'] ?? $summary);
        }

        $lines = [];
        if (is_array($testCache) && isset($testCache['port_rows']) && is_array($testCache['port_rows'])) {
            $rows = $testCache['port_rows'];
            $connectedRows = array_values(array_filter($rows, static function (array $r): bool {
                return trim((string) ($r['connected_port_id'] ?? '')) !== ''
                    || trim((string) ($r['connected_item_id'] ?? '')) !== ''
                    || trim((string) ($r['connected_port_label'] ?? '')) !== '';
            }));
            if (!empty($connectedRows)) {
                $rows = $connectedRows;
            }
            foreach ($rows as $r) {
                $stateHint = strtolower(trim((string) ($r['state'] ?? '')));
                $isUp = !empty($r['is_up']) || in_array($stateHint, ['up', 'idle', 'enabled', 'online', 'connected'], true);
                $traffic = (float) ($r['traffic_total'] ?? 0.0);
                $stateClass = 'edbw-dot-gray';
                $stateText = $this->isFa ? 'بدون وضعیت لینک' : 'No link data';
                if ($stateHint === 'down') {
                    $stateClass = 'edbw-dot-red';
                    $stateText = $this->isFa ? 'قطع' : 'Down';
                } elseif ($isUp && $traffic > 0.0) {
                    $stateClass = 'edbw-dot-green';
                    $stateText = $this->isFa ? 'فعال' : 'Up';
                } elseif ($isUp) {
                    $stateClass = 'edbw-dot-yellow';
                    $stateText = $this->isFa ? 'بدون ترافیک' : 'Idle';
                }
                $name = $this->resolvePortDisplayName($r);
                $name = $this->normalizePortPresentationLabel($name);
                $speed = trim((string) ($r['speed'] ?? ''));
                $traffic = $this->formatPortTraffic((float) ($r['traffic_total'] ?? 0.0));
                $speedHtml = $speed !== '' ? '<span class="edbw-port-speed">' . htmlspecialchars($speed) . '</span>' : '';
                $trafficHtml = $traffic !== '' ? '<span class="edbw-port-traffic">' . htmlspecialchars($traffic) . '</span>' : '';
                $lines[] = '<div class="edbw-port-line"><span class="edbw-dot ' . $stateClass . '"></span><span class="edbw-port-name">' . htmlspecialchars($name) . '</span>' . $speedHtml . $trafficHtml . '<span class="edbw-port-state">(' . htmlspecialchars($stateText) . ')</span></div>';
            }
        }

        $html = '<div class="edbw-port-summary">' . htmlspecialchars($summary !== '' ? $summary : $this->t('no_data')) . '</div>';
        if (!empty($lines)) {
            $html .= '<div class="edbw-port-list">' . implode('', $lines) . '</div>';
        }
        return $html;
    }

    private function formatPortTraffic(float $value): string
    {
        if ($value <= 0) {
            return '';
        }
        $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
        $i = 0;
        while ($value >= 1024 && $i < count($units) - 1) {
            $value /= 1024;
            $i++;
        }
        return number_format($value, $value >= 100 ? 0 : 2, '.', '') . ' ' . $units[$i];
    }

    private function resolvePortDisplayName(array $row): string
    {
        $connected = trim((string) ($row['connected_port_label'] ?? ''));
        $connectedItem = trim((string) ($row['connected_item_label'] ?? ''));
        $name = trim((string) ($row['name'] ?? ''));
        $connectedId = trim((string) ($row['connected_port_id'] ?? ''));
        $connectedItemId = trim((string) ($row['connected_item_id'] ?? ''));
        $portId = trim((string) ($row['port_id'] ?? ''));

        $interface = $this->extractInterfaceName($connected);
        if ($interface !== '') {
            return $connectedItem !== '' && !$this->isSimpleNumericLabel($connectedItem)
                ? ($interface . ' @ ' . $connectedItem)
                : $interface;
        }

        $nameInterface = $this->extractInterfaceName($name);
        if ($nameInterface !== '') {
            return $connectedItem !== '' && !$this->isSimpleNumericLabel($connectedItem)
                ? ($nameInterface . ' @ ' . $connectedItem)
                : $nameInterface;
        }
        // If interface name is missing, show stable numeric IDs instead of vague labels.
        if ($connectedId !== '') {
            return '#' . $connectedId;
        }
        if ($portId !== '') {
            return '#' . $portId;
        }

        if ($connected !== '' && !$this->isSimpleNumericLabel($connected)) {
            if ($name !== '' && !$this->isSimpleNumericLabel($name) && strcasecmp($name, $connected) !== 0) {
                return $name . ' -> ' . $connected;
            }
            return $connected;
        }
        if ($name !== '' && !$this->isSimpleNumericLabel($name)) {
            if ($connected !== '' && $connectedId !== '' && str_contains($connected, '#' . $connectedId)) {
                return $name . ' -> ' . $connected;
            }
            return $name;
        }
        if ($connectedId !== '') {
            return '#' . $connectedId;
        }
        if ($connected !== '') {
            return $connected;
        }
        if ($connectedItemId !== '') {
            return '#' . $connectedItemId;
        }
        if ($connectedItem !== '') {
            return $connectedItem;
        }
        if ($name !== '') {
            return $name;
        }
        if ($portId !== '') {
            return '#' . $portId;
        }
        return '-';
    }

    private function normalizePortPresentationLabel(string $value): string
    {
        $value = trim($value);
        if ($value === '' || $value === '-') {
            return '-';
        }
        $iface = $this->extractInterfaceName($value);
        if ($iface !== '') {
            return $iface;
        }
        if (preg_match('/#\s*\d+\s+(.+)/', $value, $m)) {
            $tail = trim((string) ($m[1] ?? ''));
            $ifaceTail = $this->extractInterfaceName($tail);
            if ($ifaceTail !== '') {
                return $ifaceTail;
            }
            if ($tail !== '' && !$this->isSimpleNumericLabel($tail)) {
                return $tail;
            }
        }
        return $value;
    }

    private function normalizeSwitchDisplayLabel(string $value): string
    {
        $value = trim($value);
        if ($value === '' || $value === '-') {
            return '';
        }
        if (preg_match('/#\s*\d+\s+(.+)/', $value, $m)) {
            $tail = trim((string) ($m[1] ?? ''));
            if ($tail !== '') {
                return $tail;
            }
        }
        if ($this->isSimpleNumericLabel($value)) {
            return '';
        }
        return $value;
    }

    private function extractInterfaceName(string $text): string
    {
        $text = trim($text);
        if ($text === '') {
            return '';
        }
        if (preg_match('/(Ethernet\\s*[^\\s\\|,;]+|Eth\\s*[^\\s\\|,;]+|Gi\\s*[^\\s\\|,;]+|Te\\s*[^\\s\\|,;]+|Fa\\s*[^\\s\\|,;]+|SFP\\+?\\s*[^\\s\\|,;]+)/i', $text, $m)) {
            return trim((string) $m[1]);
        }
        if (preg_match('/(port\\s*\\d+\\/\\d+(?:\\/\\d+)?)/i', $text, $m)) {
            return trim((string) $m[1]);
        }
        return '';
    }

    private function isSimpleNumericLabel(string $value): bool
    {
        $v = trim($value);
        if ($v === '') {
            return false;
        }
        if (preg_match('/^#?\\d+$/', $v)) {
            return true;
        }
        return false;
    }

    private function pickDescriptivePortLabel(array $candidates, string $fallback = ''): string
    {
        foreach ($candidates as $candidate) {
            $text = trim((string) $candidate);
            if ($text === '' || $this->isSimpleNumericLabel($text)) {
                continue;
            }
            if ($this->isUsefulPortLabel($text)) {
                return $text;
            }
        }
        return trim($fallback);
    }

    private function isUsefulPortLabel(string $value): bool
    {
        $v = trim($value);
        if ($v === '' || $this->isSimpleNumericLabel($v)) {
            return false;
        }
        $lower = strtolower($v);
        if (in_array($lower, ['accepted', 'pending', 'rejected', 'active', 'inactive', 'enabled', 'disabled', 'up', 'down', 'unknown'], true)) {
            return false;
        }
        if (preg_match('/(ethernet|gig|sfp|switch|router|interface|uplink|port|eth\\d|gi\\d|te\\d|lan|wan)/i', $v)) {
            return true;
        }
        if (preg_match('/#\\s*\\d+\\s+/', $v)) {
            return true;
        }
        return strlen($v) >= 6 && preg_match('/[a-z]/i', $v) && preg_match('/\\d/', $v);
    }

    private function extractPortLikeLabelFromRow(array $row): string
    {
        $stack = [$row];
        $visited = 0;
        while (!empty($stack) && $visited < 200) {
            $node = array_pop($stack);
            $visited++;
            if (!is_array($node)) {
                continue;
            }
            foreach ($node as $key => $value) {
                if (is_array($value)) {
                    $stack[] = $value;
                    continue;
                }
                if (!is_scalar($value)) {
                    continue;
                }
                $keyName = strtolower((string) $key);
                if (!preg_match('/name|label|description|port|interface|ethernet|connection|uplink|title|number|if_/', $keyName)) {
                    continue;
                }
                $text = trim((string) $value);
                if ($this->isUsefulPortLabel($text)) {
                    return $text;
                }
            }
        }
        return '';
    }

    private function normalizePortSpeedValue($raw): string
    {
        if ($raw === null) {
            return '';
        }
        if (is_bool($raw)) {
            return '';
        }
        $text = strtolower(trim((string) $raw));
        if ($text === '') {
            return '';
        }
        $normalized = str_replace(
            ['gbps', 'gbit/s', 'gbit', 'gigabit', 'tbps', 'tbit/s', 'tbit', 'mbps', 'mbit/s', 'mbit', 'kbps', 'kbit/s', 'kbit'],
            ['g', 'g', 'g', 'g', 't', 't', 't', 'm', 'm', 'm', 'k', 'k', 'k'],
            $text
        );
        $normalized = str_replace([',', ' ', "\t", "\n", "\r"], '', $normalized);
        if (!preg_match('/(-?\\d+(?:\\.\\d+)?)([tgmk])?/', $normalized, $match)) {
            return '';
        }
        $value = (float) ($match[1] ?? 0);
        $unit = strtolower((string) ($match[2] ?? ''));
        if ($value <= 0) {
            return '';
        }
        $mbps = $this->normalizeSpeedToPlausibleMbps($value, $unit);
        return $this->formatPlausibleSpeed($mbps);
    }

    private function extractSpeedFromText(string $text): string
    {
        return $this->normalizePortSpeedValue($text);
    }

    private function normalizeSpeedToPlausibleMbps(float $value, string $unit): float
    {
        $candidates = [];
        switch ($unit) {
            case 't':
                $candidates = [$value * 1000000];
                break;
            case 'g':
                $candidates = [$value * 1000, $value, $value / 1000, $value / 1000000];
                break;
            case 'm':
                $candidates = [$value, $value * 1000, $value / 1000];
                break;
            case 'k':
                $candidates = [$value / 1000, $value, $value / 1000000];
                break;
            default:
                $candidates = [$value, $value / 1000, $value / 1000000, $value * 1000];
                break;
        }

        $standards = [10, 100, 1000, 2500, 5000, 10000, 25000, 40000, 50000, 100000, 200000, 400000, 800000];
        $best = 0.0;
        $bestScore = INF;
        foreach (array_values($candidates) as $idx => $candidate) {
            $candidate = (float) $candidate;
            if ($candidate <= 0) {
                continue;
            }
            $nearest = $standards[0];
            $delta = INF;
            foreach ($standards as $std) {
                $d = abs($candidate - $std) / $std;
                if ($d < $delta) {
                    $delta = $d;
                    $nearest = $std;
                }
            }
            $score = $delta + ($idx * 0.08);
            if ($candidate < 1) {
                $score += 1.5;
            }
            if ($candidate > 1200000) {
                $score += 1.5;
            }
            if ($score < $bestScore) {
                $bestScore = $score;
                $best = (float) $nearest;
            }
        }

        if ($best > 0) {
            return $best;
        }
        foreach ($candidates as $candidate) {
            $candidate = (float) $candidate;
            if ($candidate > 0) {
                return $candidate;
            }
        }
        return 0.0;
    }

    private function formatPlausibleSpeed(float $mbps): string
    {
        if ($mbps <= 0) {
            return '';
        }
        if ($mbps >= 1000) {
            $g = $mbps / 1000;
            if (abs($g - round($g)) < 0.01) {
                return (string) (int) round($g) . 'G';
            }
            return rtrim(rtrim(number_format($g, 1, '.', ''), '0'), '.') . 'G';
        }
        if (abs($mbps - round($mbps)) < 0.01) {
            return (string) (int) round($mbps) . 'M';
        }
        return rtrim(rtrim(number_format($mbps, 1, '.', ''), '0'), '.') . 'M';
    }

    private function enrichPortRowsWithPortDetails(EasyDcimClient $client, array $rows): array
    {
        if (empty($rows)) {
            return $rows;
        }

        foreach ($rows as $idx => $row) {
            if (!is_array($row)) {
                continue;
            }
            $detailId = trim((string) ($row['connected_port_id'] ?? ''));
            if ($detailId === '') {
                $detailId = trim((string) ($row['port_id'] ?? ''));
            }
            if ($detailId === '') {
                continue;
            }

            $detail = $this->getPortDetailCached($client, $detailId);
            if (empty($detail)) {
                continue;
            }

            $currentConnected = trim((string) ($rows[$idx]['connected_port_label'] ?? ''));
            $currentName = trim((string) ($rows[$idx]['name'] ?? ''));
            $detailLabel = trim((string) ($detail['label'] ?? ''));
            if ($detailLabel !== '') {
                if ($currentConnected === '' || $this->isSimpleNumericLabel($currentConnected)) {
                    $rows[$idx]['connected_port_label'] = $detailLabel;
                }
                if ($currentName === '' || $this->isSimpleNumericLabel($currentName)) {
                    $rows[$idx]['name'] = $detailLabel;
                }
            }

            $detailSpeed = trim((string) ($detail['speed'] ?? ''));
            if ($detailSpeed !== '' && trim((string) ($rows[$idx]['speed'] ?? '')) === '') {
                $rows[$idx]['speed'] = $detailSpeed;
            }

            $detailState = strtolower(trim((string) ($detail['state'] ?? '')));
            $rowState = strtolower(trim((string) ($rows[$idx]['state'] ?? 'unknown')));
            if ($detailState !== '' && ($rowState === '' || $rowState === 'unknown' || ($rowState === 'down' && in_array($detailState, ['up', 'idle'], true)))) {
                $rows[$idx]['state'] = $detailState;
            }

            $detailUp = (bool) ($detail['is_up'] ?? false);
            if (!empty($rows[$idx]['is_up'])) {
                // Keep current positive state.
            } elseif ($detailUp) {
                $rows[$idx]['is_up'] = true;
            }

            $detailTraffic = (float) ($detail['traffic_total'] ?? 0.0);
            if ($detailTraffic > 0.0 && (float) ($rows[$idx]['traffic_total'] ?? 0.0) <= 0.0) {
                $rows[$idx]['traffic_total'] = $detailTraffic;
            }
        }

        return $rows;
    }

    private function getPortDetailCached(EasyDcimClient $client, string $portId): array
    {
        $portId = trim($portId);
        if ($portId === '') {
            return [];
        }
        if (array_key_exists($portId, $this->portDetailsRuntimeCache)) {
            $cached = $this->portDetailsRuntimeCache[$portId];
            return is_array($cached) ? $cached : [];
        }

        try {
            $response = $client->portDetails($portId);
            $parsed = $this->parsePortDetailPayload($response, $portId);
            $this->portDetailsRuntimeCache[$portId] = $parsed;
            return $parsed;
        } catch (\Throwable $e) {
            $this->logger->log('WARNING', 'port_details_fetch_failed', [
                'port_id' => $portId,
                'error' => $e->getMessage(),
            ]);
            $this->portDetailsRuntimeCache[$portId] = [];
            return [];
        }
    }

    private function parsePortDetailPayload(array $response, string $portId): array
    {
        $code = (int) ($response['http_code'] ?? 0);
        if ($code < 200 || $code >= 300) {
            return [];
        }
        $data = $this->extractApiDataPayload($response);
        $rows = $this->extractPortItems($data);
        $picked = null;
        if (!empty($rows)) {
            foreach ($rows as $row) {
                $pid = trim((string) ($row['port_id'] ?? ''));
                if ($pid !== '' && $pid === $portId) {
                    $picked = $row;
                    break;
                }
            }
            if ($picked === null) {
                $picked = $rows[0];
            }
        }

        if (is_array($picked)) {
            $label = trim($this->resolvePortDisplayName($picked));
            if ($label === '-' || $label === '') {
                $label = trim((string) ($picked['name'] ?? ''));
            }
            return [
                'label' => $label,
                'state' => strtolower(trim((string) ($picked['state'] ?? ''))),
                'is_up' => !empty($picked['is_up']),
                'speed' => trim((string) ($picked['speed'] ?? '')),
                'traffic_total' => (float) ($picked['traffic_total'] ?? 0.0),
            ];
        }

        $label = trim((string) ($data['name'] ?? $data['label'] ?? $data['user_label'] ?? $data['description'] ?? $data['number'] ?? ''));
        if ($label === '') {
            $label = $this->extractPortLikeLabelFromRow($data);
        }
        if ($label === '') {
            $label = '#' . $portId;
        }
        $stateRaw = strtolower(trim((string) ($data['status'] ?? $data['state'] ?? $data['admin_state'] ?? $data['oper_state'] ?? '')));
        $state = 'unknown';
        if ($stateRaw !== '' && preg_match('/down|inactive|disabled|offline|not.?connected|disconnected|link.?down|error|fault/i', $stateRaw)) {
            $state = 'down';
        } elseif ($stateRaw !== '' && preg_match('/up|active|enabled|online|accepted|connected|link.?up|running/i', $stateRaw)) {
            $state = 'up';
        }
        $speed = $this->normalizePortSpeedValue($data['speed'] ?? $data['speed_label'] ?? $data['speed_human'] ?? $data['bandwidth'] ?? $data['port_speed'] ?? null);
        return [
            'label' => $label,
            'state' => $state,
            'is_up' => $state === 'up',
            'speed' => $speed,
            'traffic_total' => $this->extractTrafficTotal($data),
        ];
    }

    private function resolveItemLabelCached(EasyDcimClient $client, string $itemId): string
    {
        $itemId = trim($itemId);
        if ($itemId === '') {
            return '';
        }
        if (array_key_exists($itemId, $this->itemLabelRuntimeCache)) {
            return (string) $this->itemLabelRuntimeCache[$itemId];
        }

        try {
            $response = $client->serverDetailsById($itemId);
            $code = (int) ($response['http_code'] ?? 0);
            if ($code < 200 || $code >= 300) {
                $this->itemLabelRuntimeCache[$itemId] = '';
                return '';
            }
            $payload = $this->extractApiDataPayload($response);
            $label = $this->extractPreferredLabel($payload);
            if ($label === '') {
                $label = trim((string) ($payload['name'] ?? $payload['hostname'] ?? $payload['title'] ?? ''));
            }
            $label = $this->normalizeSwitchDisplayLabel($label);
            $this->itemLabelRuntimeCache[$itemId] = $label;
            return $label;
        } catch (\Throwable $e) {
            $this->logger->log('WARNING', 'resolve_item_label_failed', [
                'item_id' => $itemId,
                'error' => $e->getMessage(),
            ]);
            $this->itemLabelRuntimeCache[$itemId] = '';
            return '';
        }
    }

    private function isNetworkPortCandidate(string $name, string $description, string $type): bool
    {
        $haystack = strtolower(trim($name . ' ' . $description . ' ' . $type));
        if ($haystack === '') {
            return true;
        }
        foreach (['ilo', 'idrac', 'bmc', 'ipmi', 'mgmt', 'management', 'kvm'] as $bad) {
            if (str_contains($haystack, $bad)) {
                return false;
            }
        }
        return true;
    }

    private function domainStatusLabel(string $status): string
    {
        $s = strtolower(trim($status));
        if ($s === 'active') {
            return $this->t('active');
        }
        if ($s === 'suspended') {
            return $this->isFa ? 'مسدود' : 'Suspended';
        }
        return $status !== '' ? $status : '-';
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

    private function t(string $key): string
    {
        $fa = [
            'subtitle' => 'مرکز کنترل ترافیک سرویس‌های EasyDCIM',
            'tab_dashboard' => 'داشبورد',
            'tab_health' => 'هلث چک',
            'tab_connection' => 'Easy DCIM',
            'tab_settings' => 'تنظیمات',
            'tab_scope' => 'سرویس/گروه',
            'tab_servers' => 'سرورها',
            'tab_traffic' => 'ترافیک',
            'tab_packages' => 'پکیج‌ها',
            'tab_logs' => 'لاگ‌ها',
            'easy_connection' => 'اتصال EasyDCIM',
            'base_url' => 'آدرس Base',
            'api_token' => 'توکن API ادمین',
            'keep_secret' => 'برای نگه داشتن مقدار فعلی خالی بگذارید',
            'access_mode' => 'حالت دسترسی API',
            'restricted_mode' => 'Restricted Mode (محدود)',
            'unrestricted_mode' => 'Unrestricted Mode (بدون محدودیت)',
            'proxy_title' => 'تنظیمات پروکسی',
            'proxy_enable' => 'فعال‌سازی پروکسی',
            'proxy_type' => 'نوع پروکسی',
            'proxy_host' => 'هاست پروکسی',
            'proxy_port' => 'پورت پروکسی',
            'proxy_user' => 'نام کاربری پروکسی',
            'proxy_pass' => 'رمز پروکسی',
            'save_connection' => 'ذخیره',
            'test_connection' => 'تست اتصال EasyDCIM',
            'allow_self_signed' => 'پذیرش SSL Self-Signed',
            'allow_self_signed_help' => 'برای اتصال با IP یا گواهی self-signed فعال بماند.',
            'module_settings' => 'تنظیمات ماژول',
            'module_status' => 'وضعیت ماژول',
            'active' => 'فعال',
            'disabled' => 'غیرفعال',
            'module_status_help' => 'بدون حذف داده‌ها، موقت غیرفعال می‌شود.',
            'ui_language' => 'زبان رابط',
            'lang_default' => 'پیش‌فرض',
            'poll_interval' => 'فاصله Poll (دقیقه)',
            'graph_cache' => 'کش گراف (دقیقه)',
            'autobuy_enabled' => 'خرید خودکار فعال',
            'autobuy_threshold' => 'آستانه خرید خودکار (GB)',
            'autobuy_package' => 'Package ID پیش‌فرض خرید خودکار',
            'autobuy_max' => 'حداکثر خرید خودکار در سیکل',
            'update_mode' => 'حالت آپدیت',
            'update_mode_notify' => 'فقط اعلان',
            'update_mode_check_oneclick' => 'بررسی + آپدیت یک‌کلیکی',
            'update_mode_auto' => 'آپدیت خودکار',
            'direction_mapping' => 'نگاشت جهت ترافیک',
            'normal' => 'عادی',
            'swap_in_out' => 'جابجایی IN/OUT',
            'direction_mapping_help' => 'اگر IN/OUT در شبکه شما برعکس است، این گزینه را روی Swap بگذارید.',
            'default_calc_mode' => 'حالت محاسبه پیش‌فرض',
            'test_mode' => 'حالت تست (Dry Run)',
            'test_mode_help' => 'هیچ دستور واقعی برای قطع/وصل ارسال نمی‌شود و فقط در لاگ ثبت می‌شود.',
            'log_retention' => 'نگهداری لاگ (روز)',
            'preflight_strict' => 'Preflight سخت‌گیرانه',
            'purge_on_deactivate' => 'پاکسازی کامل هنگام غیرفعال‌سازی',
            'purge_on_deactivate_help' => 'با فعال بودن این گزینه، جداول ماژول هنگام deactivate حذف می‌شوند.',
            'save_settings' => 'ذخیره تنظیمات',
            'managed_scope' => 'محدوده مدیریت',
            'managed_pids' => 'PIDهای مدیریت‌شده',
            'managed_gids' => 'GIDهای مدیریت‌شده',
            'comma_pids' => 'شناسه محصولات را با کاما جدا کنید',
            'comma_gids' => 'شناسه گروه‌ها را با کاما جدا کنید',
            'save_scope' => 'ذخیره محدوده',
            'loaded_products' => 'تعداد محصول بارگذاری‌شده در محدوده',
            'plan_quotas' => 'سقف پلن‌ها (IN / OUT / TOTAL)',
            'plan_quotas_help' => 'هر ردیف با تغییرات شما خودکار ذخیره می‌شود. دکمه پایین برای ذخیره همه ردیف‌هاست.',
            'in_label' => 'دانلود',
            'out_label' => 'آپلود',
            'total_label' => 'مجموع',
            'product' => 'محصول',
            'product_id' => 'شناسه محصول',
            'cf_check' => 'بررسی CF',
            'unlimited_label' => 'نامحدود (IN/OUT/TOTAL)',
            'action' => 'اقدام',
            'disable_ports' => 'بستن پورت‌ها',
            'suspend' => 'تعلیق سرویس',
            'both' => 'هر دو',
            'save_all_plans' => 'ذخیره همه پلن‌ها',
            'saved' => 'ذخیره شد',
            'save_failed' => 'ذخیره ناموفق بود',
            'all_rows_saved' => 'همه ردیف‌ها ذخیره شدند',
            'settings_saved' => 'تنظیمات با موفقیت ذخیره شد.',
            'base_or_token_missing' => 'Base URL یا API Token وارد نشده است.',
            'connection_ok' => 'اتصال EasyDCIM صحیح است.',
            'connection_reachable_limited' => 'اتصال برقرار است اما endpoint کلاینت محدود است',
            'connection_unhealthy' => 'EasyDCIM در دسترس است ولی پاسخ سالم نیست.',
            'connection_failed' => 'تست اتصال ناموفق بود',
            'servers_tab_title' => 'سرورها و سرویس‌های EasyDCIM',
            'servers_api_missing' => 'برای نمایش لیست سرورهای EasyDCIM ابتدا Base URL و API Token را تنظیم کنید.',
            'servers_api_loaded' => 'تعداد آیتم دریافتی از EasyDCIM',
            'servers_api_empty_hint' => 'لیست API خالی است. اگر توکن ادمین استفاده می‌کنید، حالت Unrestricted را روشن کنید یا Service/Order/Server ID را در سرویس‌های WHMCS تکمیل کنید.',
            'servers_assigned' => 'سرویس‌های واگذار شده به مشتری',
            'servers_traffic_hint' => 'نمایش سریع سوییچ، پورت و وضعیت سرویس. برای گزارش مصرف کامل روی شماره سرویس کلیک کنید یا تب «ترافیک» را باز کنید.',
            'servers_unassigned' => 'سرویس‌های آزاد (بدون اتصال به WHMCS)',
            'search' => 'جستجو',
            'clear_search' => 'پاک کردن',
            'rows_per_page' => 'تعداد در صفحه',
            'pager_prev' => 'قبلی',
            'pager_next' => 'بعدی',
            'pager_page' => 'صفحه',
            'servers_search_placeholder' => 'جستجو: IP, iLO, Label/MDP, نام، ایمیل، Service/Order/Server ID',
            'traffic_search_placeholder' => 'جستجو: IP, iLO, Label/MDP, نام، ایمیل، Service/Order/Server ID',
            'logs_search_placeholder' => 'جستجو در پیام/سورس/جزئیات JSON/تاریخ/شناسه سرویس',
            'logs_only_errors' => 'فقط خطا/هشدار',
            'test' => 'تست',
            'mode' => 'روش',
            'invalid_service' => 'شناسه سرویس نامعتبر است.',
            'service_not_found_scope' => 'سرویس در محدوده فعلی پیدا نشد.',
            'test_ok' => 'تست با موفقیت انجام شد',
            'test_failed' => 'تست ناموفق بود',
            'service' => 'سرویس',
            'client' => 'مشتری',
            'ports_status' => 'وضعیت پورت‌ها',
            'ports_up' => 'پورت بالا',
            'network_ports_not_found' => 'پورت شبکه‌ای پیدا نشد',
            'ports_not_found' => 'پورتی پیدا نشد',
            'ports_error' => 'خطا در بررسی پورت',
            'server_ports_not_supported' => 'endpoint پورت با Server ID در EasyDCIM شما پشتیبانی نمی‌شود',
            'traffic_report_title' => 'گزارش مصرف ترافیک سرویس‌ها',
            'default_plan_bw' => 'پلن پیش‌فرض BW',
            'traffic_used_gb' => 'مصرف (GB)',
            'traffic_remaining_gb' => 'باقی‌مانده (GB)',
            'traffic_allowed_gb' => 'سقف موثر (GB)',
            'traffic_cycle' => 'بازه سیکل',
            'traffic_last_check' => 'آخرین بررسی',
            'base_quota' => 'سقف پایه',
            'extra_quota' => 'خرید اضافه',
            'reset_at' => 'تاریخ ریست',
            'connected_switches' => 'SW',
            'connected_ports' => 'پورت‌های متصل',
            'port_speeds' => 'سرعت لینک',
            'status' => 'وضعیت',
            'no_rows' => 'موردی یافت نشد',
            'order_id' => 'Order ID',
            'server_id' => 'Server ID',
            'important_warnings' => 'Important Warnings',
            'no_important_warnings' => 'مورد مهمی برای هشدار فوری پیدا نشد.',
            'warning_type' => 'نوع هشدار',
            'warn_shared_server' => 'سرور مشترک بین سرویس‌های فعال',
            'warn_service_issues' => 'هشدار سرویس فعال',
            'warn_client_mismatch' => 'اختلاف اطلاعات مشتری بین WHMCS و EasyDCIM',
            'warn_active_port_down' => 'سرویس فعال با پورت شبکه غیرفعال',
            'warn_active_no_traffic' => 'سرویس فعال بدون ترافیک شبکه',
            'warn_unassigned_possible_match' => 'سرویس آزاد با احتمال مپ اشتباه',
            'warn_unassigned_possible_match_target' => 'تطابق احتمالی با سرویس‌های WHMCS',
            'warn_missing_explicit_map' => 'نگاشت صریح EasyDCIM ثبت نشده',
            'warn_explicit_map_mismatch' => 'اختلاف نگاشت صریح با نگاشت تشخیص‌داده‌شده',
            'warn_expected' => 'ثبت‌شده',
            'warn_detected' => 'تشخیص‌داده‌شده',
            'warn_easy_cache_empty' => 'کش EasyDCIM خالی است',
            'warn_easy_cache_empty_text' => 'ابتدا از تب Servers گزینه Refresh Cache را اجرا کنید تا اختلاف‌ها و سرویس‌های آزاد قابل تحلیل شوند.',
            'm_version' => 'نسخه',
            'm_commit' => 'کامیت',
            'm_update_source' => 'آپدیت سورس',
            'm_update_source_github' => 'گیت هاب',
            'm_update_status' => 'وضعیت آپدیت',
            'm_release_status' => 'وضعیت ریلیز',
            'm_cron_poll' => 'آخرین Poll',
            'm_api_fail_count' => 'تعداد خطای API',
            'm_update_lock' => 'قفل آپدیت',
            'm_connection' => 'وضعیت اتصال EasyDCIM',
            'm_latest_release' => 'آخرین ریلیز',
            'm_update_available' => 'آپدیت موجود است',
            'm_uptodate' => 'به‌روز',
            'm_no_data' => 'بدون داده',
            'm_locked' => 'قفل شده',
            'm_free' => 'آزاد',
            'm_configured' => 'تنظیم شده',
            'm_not_configured' => 'تنظیم نشده',
            'm_connected' => 'متصل',
            'm_configured_reachable' => 'تنظیم شده (دسترسی محدود)',
            'm_configured_disconnected' => 'تنظیم شده ولی متصل نیست',
            'm_unknown' => 'نامشخص',
            'update_actions' => 'اقدامات آپدیت',
            'check_update_now' => 'بررسی آپدیت',
            'check_update_again' => 'چک کردن مجدد',
            'apply_latest_release' => 'اعمال آخرین ریلیز',
            'update_banner' => 'آپدیت جدید برای ماژول موجود است. لطفا از داشبورد اقدام کنید.',
            'release_apply_queued' => 'درخواست آپدیت ثبت شد و در اجرای بعدی کرون اعمال می‌شود.',
            'release_apply_queue_failed' => 'ثبت درخواست آپدیت ناموفق بود',
            'no_data' => 'بدون داده',
            'hc_php_version' => 'نسخه PHP',
            'hc_current' => 'فعلی',
            'hc_required' => 'لازم',
            'hc_curl_extension' => 'افزونه cURL',
            'hc_update_engine' => 'موتور آپدیت',
            'hc_release_engine' => 'آپدیتر مبتنی بر ریلیز (بدون نیاز به دستور shell)',
            'hc_zip_extension' => 'افزونه ZIP',
            'hc_module_status' => 'وضعیت ماژول',
            'hc_base_url' => 'EasyDCIM Base URL',
            'hc_api_token' => 'EasyDCIM API Token',
            'hc_scope' => 'محدوده مدیریت (PID/GID)',
            'hc_configured' => 'تنظیم شده',
            'hc_not_configured' => 'تنظیم نشده',
            'hc_no_scope' => 'هیچ PID/GID تنظیم نشده',
            'hc_found' => 'موجود',
            'hc_available' => 'موجود',
            'hc_missing' => 'ناموجود',
            'hc_optional' => 'اختیاری',
            'preflight_checks' => 'بررسی‌های پیش از اجرا',
            'preflight_retested' => 'بررسی پیش از اجرا دوباره انجام شد.',
            'health_cron_title' => 'وضعیت کرون',
            'retest' => 'تست مجدد',
            'check' => 'چک',
            'details' => 'جزئیات',
            'missing_fail' => 'ناموجود/خطا',
            'preflight_warn' => 'ماژول اجرا می‌شود اما برای محیط پروداکشن، موارد ناقص را برطرف کنید.',
            'preflight_ok' => 'همه بررسی‌ها با موفقیت پاس شدند.',
            'rt_module_status' => 'وضعیت ماژول',
            'rt_cron_status' => 'وضعیت کرون',
            'rt_cron_active' => 'فعال (آخرین پینگ)',
            'rt_cron_down' => 'در ۶ دقیقه اخیر اجرا نشده',
            'rt_traffic_limited' => 'سرویس‌های محدودشده بر اساس ترافیک',
            'rt_synced_last_hour' => 'همگام‌سازی در ۱ ساعت اخیر',
            'rt_suspended_other' => 'تعلیق‌شده (سایر دلایل)',
            'rt_test_mode' => 'حالت تست',
            'rt_test_mode_on' => 'فعال (Dry Run)',
            'rt_test_mode_off' => 'غیرفعال',
            'health_runtime_title' => 'وضعیت اجرا و کرون',
            'servers_cache_at' => 'آخرین به‌روزرسانی کش سرورها',
            'servers_refresh_cache' => 'کش و تکمیل شناسه‌ها',
            'servers_test_all' => 'تست همه',
            'servers_test_all_continue' => 'ادامه تست (بچ بعدی)',
            'servers_test_all_stop' => 'توقف تست',
            'servers_test_all_stopped' => 'تست گروهی متوقف شد',
            'servers_test_all_reset' => 'ریست صف تست',
            'servers_test_all_reset_done' => 'صف تست همه سرویس‌ها ریست شد',
            'servers_test_all_progress' => 'پیشرفت تست گروهی',
            'servers_test_all_elapsed' => 'زمان سپری‌شده',
            'servers_test_all_total_time' => 'زمان کل تست',
            'servers_test_all_last_run' => 'آخرین اجرای تست گروهی',
            'servers_test_chunk_size' => 'تعداد تست در هر اجرا',
            'servers_test_chunk_size_help' => 'هر چه بیشتر باشد تست سریع‌تر می‌شود اما فشار بیشتری می‌آورد.',
            'servers_sync_now' => 'همگام‌سازی سرورها (دستی)',
            'servers_cache_empty_hint' => 'کش سرورها خالی است. برای جلوگیری از کندی، لیست با همگام‌سازی دستی یا کرون پر می‌شود.',
            'servers_cache_refreshed' => 'کش سرورها به‌روزرسانی شد',
            'servers_cache_refresh_failed' => 'به‌روزرسانی کش سرورها ناموفق بود',
            'servers_mapping_updated' => 'شناسه‌های تکمیل/به‌روز شده',
            'servers_test_all_done' => 'تست همه سرویس‌ها انجام شد',
            'servers_test_all_failed' => 'تست همه سرویس‌ها ناموفق بود',
        ];
        $en = [
            'subtitle' => 'Bandwidth control center for EasyDCIM services',
            'tab_dashboard' => 'Dashboard',
            'tab_health' => 'Health Check',
            'tab_connection' => 'Easy DCIM',
            'tab_settings' => 'Settings',
            'tab_scope' => 'Services / Group',
            'tab_servers' => 'Servers',
            'tab_traffic' => 'Traffic',
            'tab_packages' => 'Packages',
            'tab_logs' => 'Logs',
            'easy_connection' => 'EasyDCIM Connection',
            'base_url' => 'Base URL',
            'api_token' => 'Admin API Token',
            'keep_secret' => 'Leave empty to keep current value',
            'access_mode' => 'API Access Mode',
            'restricted_mode' => 'Restricted Mode',
            'unrestricted_mode' => 'Unrestricted Mode',
            'proxy_title' => 'Proxy Settings',
            'proxy_enable' => 'Enable Proxy',
            'proxy_type' => 'Proxy Type',
            'proxy_host' => 'Proxy Host',
            'proxy_port' => 'Proxy Port',
            'proxy_user' => 'Proxy Username',
            'proxy_pass' => 'Proxy Password',
            'save_connection' => 'Save',
            'test_connection' => 'Test EasyDCIM Connection',
            'allow_self_signed' => 'Allow Self-Signed SSL',
            'allow_self_signed_help' => 'Keep enabled for IP-based endpoints or self-signed certificates.',
            'module_settings' => 'Module Settings',
            'module_status' => 'Module Status',
            'active' => 'Active',
            'disabled' => 'Disabled',
            'module_status_help' => 'Disable temporarily without losing data.',
            'ui_language' => 'UI Language',
            'lang_default' => 'Default',
            'poll_interval' => 'Poll Interval (min)',
            'graph_cache' => 'Graph Cache (min)',
            'autobuy_enabled' => 'Auto-Buy Enabled',
            'autobuy_threshold' => 'Auto-Buy Threshold GB',
            'autobuy_package' => 'Auto-Buy Default Package ID',
            'autobuy_max' => 'Auto-Buy Max/Cycle',
            'update_mode' => 'Update Mode',
            'update_mode_notify' => 'Notify only',
            'update_mode_check_oneclick' => 'Check + One-click update',
            'update_mode_auto' => 'Auto update',
            'direction_mapping' => 'Direction Mapping',
            'normal' => 'Normal',
            'swap_in_out' => 'Swap IN/OUT',
            'direction_mapping_help' => 'Use swap if EasyDCIM IN/OUT is reversed on your network devices.',
            'default_calc_mode' => 'Default Calculation Mode',
            'test_mode' => 'Test Mode (Dry Run)',
            'test_mode_help' => 'No real suspend/disable/enable/unsuspend calls; logs show what would be sent.',
            'log_retention' => 'Log Retention (days)',
            'preflight_strict' => 'Preflight Strict Mode',
            'purge_on_deactivate' => 'Purge Data On Deactivate',
            'purge_on_deactivate_help' => 'If enabled, all module tables are deleted on deactivate.',
            'save_settings' => 'Save Settings',
            'managed_scope' => 'Managed Scope',
            'managed_pids' => 'Managed PIDs',
            'managed_gids' => 'Managed GIDs',
            'comma_pids' => 'Comma separated product IDs',
            'comma_gids' => 'Comma separated group IDs',
            'save_scope' => 'Save Scope',
            'loaded_products' => 'Loaded products by current scope',
            'plan_quotas' => 'Plan Quotas (IN / OUT / TOTAL)',
            'plan_quotas_help' => 'Rows auto-save on change. Use the bottom button to save all rows in one click.',
            'in_label' => 'Download',
            'out_label' => 'Upload',
            'total_label' => 'Total',
            'product' => 'Product',
            'product_id' => 'Product ID',
            'cf_check' => 'CF Check',
            'unlimited_label' => 'Unlimited IN/OUT/TOTAL',
            'action' => 'Action',
            'disable_ports' => 'Disable Ports',
            'suspend' => 'Suspend',
            'both' => 'Both',
            'save_all_plans' => 'Save All Plans',
            'saved' => 'Saved',
            'save_failed' => 'Save failed',
            'all_rows_saved' => 'All rows saved',
            'settings_saved' => 'Settings saved successfully.',
            'base_or_token_missing' => 'Base URL or API token is missing.',
            'connection_ok' => 'EasyDCIM connection is OK.',
            'connection_reachable_limited' => 'EasyDCIM is reachable but client endpoint is restricted',
            'connection_unhealthy' => 'EasyDCIM is reachable but response is not healthy.',
            'connection_failed' => 'EasyDCIM test failed',
            'servers_tab_title' => 'EasyDCIM Servers and Services',
            'servers_api_missing' => 'Configure EasyDCIM Base URL and API token to load server list.',
            'servers_api_loaded' => 'Items loaded from EasyDCIM',
            'servers_api_empty_hint' => 'API list is empty. If you use an admin token, enable Unrestricted mode or fill Service/Order/Server IDs on WHMCS services.',
            'servers_assigned' => 'Assigned Services (WHMCS mapped)',
            'servers_traffic_hint' => 'Quick view of switch, ports, and status. Click a service ID for full traffic history in the Traffic tab.',
            'servers_unassigned' => 'Unassigned Services (not mapped to WHMCS)',
            'search' => 'Search',
            'clear_search' => 'Clear',
            'rows_per_page' => 'Rows per page',
            'pager_prev' => 'Prev',
            'pager_next' => 'Next',
            'pager_page' => 'Page',
            'servers_search_placeholder' => 'Search by IP, iLO, Label/MDP, name, email, Service/Order/Server ID',
            'traffic_search_placeholder' => 'Search by IP, iLO, Label/MDP, name, email, Service/Order/Server ID',
            'logs_search_placeholder' => 'Search message/source/JSON details/date/service id',
            'logs_only_errors' => 'Only Errors/Warnings',
            'test' => 'Test',
            'mode' => 'Mode',
            'invalid_service' => 'Invalid service id.',
            'service_not_found_scope' => 'Service was not found in current scope.',
            'test_ok' => 'Test completed successfully',
            'test_failed' => 'Test failed',
            'service' => 'Service',
            'client' => 'Client',
            'ports_status' => 'Ports Status',
            'ports_up' => 'ports up',
            'network_ports_not_found' => 'No network ports detected',
            'ports_not_found' => 'No ports found',
            'ports_error' => 'Port lookup failed',
            'server_ports_not_supported' => 'Server-ID ports endpoint is not supported in your EasyDCIM',
            'traffic_report_title' => 'Traffic Usage Report',
            'default_plan_bw' => 'Default Plan BW',
            'traffic_used_gb' => 'Used (GB)',
            'traffic_remaining_gb' => 'Remaining (GB)',
            'traffic_allowed_gb' => 'Effective Allowed (GB)',
            'traffic_cycle' => 'Cycle Window',
            'traffic_last_check' => 'Last Check',
            'base_quota' => 'Base Quota',
            'extra_quota' => 'Extra Bought',
            'reset_at' => 'Reset At',
            'connected_switches' => 'SW',
            'connected_ports' => 'Connected ports',
            'port_speeds' => 'Link speed',
            'status' => 'Status',
            'no_rows' => 'No rows found',
            'order_id' => 'Order ID',
            'server_id' => 'Server ID',
            'important_warnings' => 'Important Warnings',
            'no_important_warnings' => 'No critical warnings found.',
            'warning_type' => 'Warning Type',
            'warn_shared_server' => 'Shared server among active services',
            'warn_service_issues' => 'Active service warning',
            'warn_client_mismatch' => 'Client identity mismatch (WHMCS vs EasyDCIM)',
            'warn_active_port_down' => 'Active service with network port down',
            'warn_active_no_traffic' => 'Active service with no network traffic',
            'warn_unassigned_possible_match' => 'Unassigned service with possible WHMCS match',
            'warn_unassigned_possible_match_target' => 'Possible match with WHMCS services',
            'warn_missing_explicit_map' => 'Missing explicit EasyDCIM mapping',
            'warn_explicit_map_mismatch' => 'Explicit mapping mismatch',
            'warn_expected' => 'Expected',
            'warn_detected' => 'Detected',
            'warn_easy_cache_empty' => 'EasyDCIM cache is empty',
            'warn_easy_cache_empty_text' => 'Run Refresh Cache in the Servers tab first so mismatches and unassigned services can be analyzed.',
            'm_version' => 'Version',
            'm_commit' => 'Commit',
            'm_update_source' => 'Update Source',
            'm_update_source_github' => 'GitHub',
            'm_update_status' => 'Update Status',
            'm_release_status' => 'Release Status',
            'm_cron_poll' => 'Cron Poll',
            'm_api_fail_count' => 'API Fail Count',
            'm_update_lock' => 'Update Lock',
            'm_connection' => 'EasyDCIM Connection',
            'm_latest_release' => 'Latest Release',
            'm_update_available' => 'Update available',
            'm_uptodate' => 'Up to date',
            'm_no_data' => 'No data',
            'm_locked' => 'Locked',
            'm_free' => 'Free',
            'm_configured' => 'Configured',
            'm_not_configured' => 'Not configured',
            'm_connected' => 'Connected',
            'm_configured_reachable' => 'Configured (limited access)',
            'm_configured_disconnected' => 'Configured but disconnected',
            'm_unknown' => 'Unknown',
            'update_actions' => 'Update Actions',
            'check_update_now' => 'Check Update Now',
            'check_update_again' => 'Check Again',
            'apply_latest_release' => 'Apply Latest Release',
            'update_banner' => 'A new module update is available. Please apply it from the dashboard.',
            'release_apply_queued' => 'Update request queued. It will be applied on the next cron run.',
            'release_apply_queue_failed' => 'Failed to queue release update',
            'no_data' => 'No data',
            'hc_php_version' => 'PHP version',
            'hc_current' => 'Current',
            'hc_required' => 'required',
            'hc_curl_extension' => 'cURL extension',
            'hc_update_engine' => 'Update engine',
            'hc_release_engine' => 'Release-based updater (no shell command required)',
            'hc_zip_extension' => 'ZIP extension',
            'hc_module_status' => 'Module status',
            'hc_base_url' => 'EasyDCIM Base URL',
            'hc_api_token' => 'EasyDCIM API Token',
            'hc_scope' => 'Managed scope (PID/GID)',
            'hc_configured' => 'Configured',
            'hc_not_configured' => 'Not configured',
            'hc_no_scope' => 'No PID/GID set',
            'hc_found' => 'Found',
            'hc_available' => 'Available',
            'hc_missing' => 'Missing',
            'hc_optional' => 'optional',
            'preflight_checks' => 'Preflight Checks',
            'preflight_retested' => 'Preflight retest completed.',
            'health_cron_title' => 'Cron Status',
            'retest' => 'Retest',
            'check' => 'Check',
            'details' => 'Details',
            'missing_fail' => 'Missing/Fail',
            'preflight_warn' => 'Module can run, but missing items should be fixed before production traffic enforcement.',
            'preflight_ok' => 'All preflight checks passed.',
            'rt_module_status' => 'Module status',
            'rt_cron_status' => 'Cron status',
            'rt_cron_active' => 'Active (last ping)',
            'rt_cron_down' => 'Not running in last 6 minutes',
            'rt_traffic_limited' => 'Traffic-limited services',
            'rt_synced_last_hour' => 'Synced (last 1h)',
            'rt_suspended_other' => 'Suspended (other reasons)',
            'rt_test_mode' => 'Test Mode',
            'rt_test_mode_on' => 'Enabled (Dry Run)',
            'rt_test_mode_off' => 'Disabled',
            'health_runtime_title' => 'Runtime and Cron Status',
            'servers_cache_at' => 'Servers cache last update',
            'servers_refresh_cache' => 'Refresh Cache + Complete IDs',
            'servers_test_all' => 'Test All',
            'servers_test_all_continue' => 'Continue (next batch)',
            'servers_test_all_stop' => 'Stop',
            'servers_test_all_stopped' => 'Bulk test stopped',
            'servers_test_all_reset' => 'Reset Test Queue',
            'servers_test_all_reset_done' => 'Test-all queue has been reset',
            'servers_test_all_progress' => 'Batch test progress',
            'servers_test_all_elapsed' => 'Elapsed',
            'servers_test_all_total_time' => 'Total test time',
            'servers_test_all_last_run' => 'Last batch run',
            'servers_test_chunk_size' => 'Tests per run',
            'servers_test_chunk_size_help' => 'Higher value finishes faster but adds more load.',
            'servers_sync_now' => 'Sync servers now',
            'servers_cache_empty_hint' => 'Servers cache is empty. To avoid page slowness, list is loaded by manual sync or cron.',
            'servers_cache_refreshed' => 'Servers cache refreshed',
            'servers_cache_refresh_failed' => 'Servers cache refresh failed',
            'servers_mapping_updated' => 'IDs completed/updated',
            'servers_test_all_done' => 'All services test completed',
            'servers_test_all_failed' => 'All services test failed',
        ];
        $map = $this->isFa ? $fa : $en;
        return $map[$key] ?? $key;
    }
}
