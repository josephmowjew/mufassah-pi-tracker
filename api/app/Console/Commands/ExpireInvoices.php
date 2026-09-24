<?php

namespace App\Console\Commands;

use App\Services\InvoiceService;
use Illuminate\Console\Command;

class ExpireInvoices extends Command
{
    protected $signature = 'invoices:expire';

    protected $description = 'Expire outstanding invoices and release their device reservations';

    public function handle(InvoiceService $invoiceService): int
    {
        $count = $invoiceService->expireDueInvoices();

        $this->info("Expired {$count} invoice(s).");

        return self::SUCCESS;
    }
}
