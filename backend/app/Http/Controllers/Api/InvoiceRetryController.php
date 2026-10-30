<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\InvoiceResource;
use App\Models\Invoice;
use App\Services\RetryInvoiceSubmission;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class InvoiceRetryController extends Controller
{
    public function __invoke(Invoice $invoice, RetryInvoiceSubmission $retryInvoiceSubmission): JsonResponse
    {
        Gate::authorize('retry', $invoice);

        $retriedInvoice = $retryInvoiceSubmission->retry($invoice->id);

        if ($retriedInvoice === null) {
            return response()->json([
                'message' => 'This invoice cannot be retried right now. Check its status, failure type, and retry cooldown.',
            ], 409);
        }

        return (new InvoiceResource($retriedInvoice->load(['items', 'processingAttempts'])))
            ->response()
            ->setStatusCode(202);
    }
}
