<?php

namespace App\Services;

use App\Enums\InvoiceStatus;
use App\Jobs\ProcessInvoiceSubmission;
use App\Models\Invoice;
use Illuminate\Support\Facades\DB;

class RetryInvoiceSubmission
{
    public function retry(int $invoiceId): ?Invoice
    {
        return DB::transaction(function () use ($invoiceId): ?Invoice {
            $invoice = Invoice::query()->lockForUpdate()->find($invoiceId);

            if ($invoice === null || $invoice->status !== InvoiceStatus::Failed) {
                return null;
            }

            $latestAttempt = $invoice->processingAttempts()
                ->reorder()
                ->orderByDesc('attempt_number')
                ->first();

            if ($latestAttempt === null || ! $latestAttempt->canBeRetried()) {
                return null;
            }

            $invoice->update(['status' => InvoiceStatus::Pending]);

            ProcessInvoiceSubmission::dispatch($invoice->id)->afterCommit();

            return $invoice->fresh();
        });
    }
}
