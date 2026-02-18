<?php

declare(strict_types=1);

namespace EasyDcimBw;

use WHMCS\Database\Capsule;

class PurchaseManager
{
    public function __construct(private readonly Logger $logger)
    {
    }

    /** @return array<string,mixed>|null */
    public function getPackage(int $packageId): ?array
    {
        $row = Capsule::table('mod_easydcim_bw_packages')->where('id', $packageId)->where('active', 1)->first();

        return $row ? (array) $row : null;
    }

    public function createInvoiceAndRecord(int $userId, int $serviceId, array $package, string $cycleStart, string $cycleEnd, bool $autoPayWithCredit = false): ?int
    {
        $description = sprintf('Extra Bandwidth %sGB for service #%d (valid until %s)', $package['size_gb'], $serviceId, $cycleEnd);
        $invoice = localAPI('CreateInvoice', [
            'userid' => $userId,
            'date' => date('Y-m-d'),
            'duedate' => date('Y-m-d'),
            'itemdescription1' => $description,
            'itemamount1' => $package['price'],
            'itemtaxed1' => (int) ($package['taxed'] ?? 0),
            'sendinvoice' => true,
        ]);

        if (($invoice['result'] ?? '') !== 'success') {
            $this->logger->log('error', ['event' => 'invoice_create_failed', 'response' => $invoice]);
            return null;
        }

        $invoiceId = (int) $invoice['invoiceid'];

        if ($autoPayWithCredit) {
            localAPI('ApplyCredit', ['invoiceid' => $invoiceId]);
            localAPI('AddInvoicePayment', [
                'invoiceid' => $invoiceId,
                'transid' => 'autobuy-' . $serviceId . '-' . time(),
                'gateway' => 'credit',
                'amount' => $package['price'],
                'date' => date('Y-m-d H:i:s'),
            ]);
        }

        $invoiceStatus = Capsule::table('tblinvoices')->where('id', $invoiceId)->value('status');
        if ($invoiceStatus !== 'Paid') {
            return $invoiceId;
        }

        Capsule::table('mod_easydcim_bw_purchases')->insert([
            'whmcs_serviceid' => $serviceId,
            'userid' => $userId,
            'package_id' => (int) $package['id'],
            'size_gb' => $package['size_gb'],
            'price' => $package['price'],
            'invoiceid' => $invoiceId,
            'cycle_start' => $cycleStart,
            'cycle_end' => $cycleEnd,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        $this->logger->log('info', ['event' => 'purchase_recorded', 'invoice_id' => $invoiceId, 'service_id' => $serviceId]);

        return $invoiceId;
    }

    public function countAutoBuyInCycle(int $serviceId, string $cycleStart, string $cycleEnd): int
    {
        return (int) Capsule::table('mod_easydcim_bw_purchases')
            ->where('whmcs_serviceid', $serviceId)
            ->where('cycle_start', $cycleStart)
            ->where('cycle_end', $cycleEnd)
            ->whereNotNull('invoiceid')
            ->count();
    }
}
