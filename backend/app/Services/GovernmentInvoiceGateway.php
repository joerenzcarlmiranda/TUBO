<?php

namespace App\Services;

use App\Models\Invoice;

interface GovernmentInvoiceGateway
{
    public function submit(Invoice $invoice): GovernmentInvoiceResponse;
}
