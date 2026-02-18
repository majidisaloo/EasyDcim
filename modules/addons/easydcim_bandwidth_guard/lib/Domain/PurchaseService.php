<?php

declare(strict_types=1);

namespace EasyDcimBandwidthGuard\Domain;

use EasyDcimBandwidthGuard\Support\Logger;
use WHMCS\Database\Capsule;

final class PurchaseService
{
    private Logger $logger;

    public function __construct(Logger $logger)
    {
        $this->logger = $logger;
    }

    public function recordPurchase(array $payload): int
    {
        $id = (int) Capsule::table('mod_easydcim_bw_purchases')->insertGetId([
            'whmcs_serviceid' => (int) $payload['whmcs_serviceid'],
            'userid' => (int) $payload['userid'],
            'package_id' => (int) $payload['package_id'],
            'size_gb' => (float) $payload['size_gb'],
            'price' => (float) $payload['price'],
            'invoiceid' => isset($payload['invoice_id']) ? (int) $payload['invoice_id'] : null,
            'cycle_start' => $payload['cycle_start'],
            'cycle_end' => $payload['cycle_end'],
            'reset_at' => $payload['reset_at'],
            'actor' => $payload['actor'] ?? 'client_manual',
            'payment_status' => $payload['payment_status'] ?? 'paid',
            'remaining_before_gb' => (float) ($payload['remaining_before_gb'] ?? 0),
            'remaining_after_gb' => (float) ($payload['remaining_after_gb'] ?? 0),
            'purchase_context_json' => json_encode($payload['context'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'created_at' => $payload['created_at'] ?? date('Y-m-d H:i:s'),
        ]);

        $this->logger->log('INFO', 'traffic_purchase_recorded', [
            'purchase_id' => $id,
            'service_id' => $payload['whmcs_serviceid'],
            'invoice_id' => $payload['invoice_id'] ?? null,
            'cycle_start' => $payload['cycle_start'],
            'cycle_end' => $payload['cycle_end'],
            'reset_at' => $payload['reset_at'],
            'actor' => $payload['actor'] ?? 'client_manual',
        ]);

        return $id;
    }
}
