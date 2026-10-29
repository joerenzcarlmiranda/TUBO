<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\IndexInvoicesRequest;
use App\Http\Resources\InvoiceResource;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class InvoiceReadController extends Controller
{
    public function index(IndexInvoicesRequest $request): AnonymousResourceCollection
    {
        /** @var User $user */
        $user = $request->user();
        $company = $user->company;

        abort_unless($company instanceof Company, 403, 'Your account is not associated with a company.');

        $filters = $request->validated();
        $invoices = $company->invoices()
            ->when(
                isset($filters['status']),
                fn ($query) => $query->where('status', $filters['status']),
            )
            ->when(
                isset($filters['invoice_number']),
                fn ($query) => $query->where('invoice_number', $filters['invoice_number']),
            )
            ->when(
                isset($filters['invoice_date_from']),
                fn ($query) => $query->whereDate('invoice_date', '>=', $filters['invoice_date_from']),
            )
            ->when(
                isset($filters['invoice_date_to']),
                fn ($query) => $query->whereDate('invoice_date', '<=', $filters['invoice_date_to']),
            )
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($filters['per_page'] ?? 15)
            ->withQueryString();

        return InvoiceResource::collection($invoices);
    }

    public function show(Invoice $invoice): InvoiceResource
    {
        Gate::authorize('view', $invoice);

        return new InvoiceResource($invoice->load(['items', 'processingAttempts']));
    }
}
