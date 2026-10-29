<?php

namespace App\Http\Controllers\Api;

use App\Enums\InvoiceStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreInvoiceRequest;
use App\Http\Resources\InvoiceResource;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\User;
use App\Services\InvoiceTotalsCalculator;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class InvoiceController extends Controller
{
    public function store(
        StoreInvoiceRequest $request,
        InvoiceTotalsCalculator $totalsCalculator,
    ): JsonResponse {
        /** @var User $user */
        $user = $request->user();
        $company = $user->company;

        abort_unless($company instanceof Company, 403, 'Your account is not associated with a company.');

        $validated = $request->validated();
        $totals = $totalsCalculator->calculate($validated['items']);

        try {
            $invoice = DB::transaction(function () use ($company, $totals, $user, $validated): Invoice {
                $invoice = $company->invoices()->create([
                    'invoice_number' => $validated['invoice_number'],
                    'invoice_date' => $validated['invoice_date'],
                    'seller_name' => $company->legal_name,
                    'seller_tax_id' => $company->tax_id,
                    'customer_name' => $validated['customer_name'],
                    'customer_tax_id' => $validated['customer_tax_id'] ?? null,
                    'customer_email' => $validated['customer_email'] ?? null,
                    'currency' => $validated['currency'],
                    'subtotal' => $totals['subtotal'],
                    'tax_amount' => $totals['tax_amount'],
                    'total_amount' => $totals['total_amount'],
                    'status' => InvoiceStatus::Pending,
                ]);
                $invoice->createdBy()->associate($user);
                $invoice->save();
                $invoice->items()->createMany($totals['items']);

                return $invoice->load('items');
            });
        } catch (QueryException $exception) {
            if (! $this->isInvoiceNumberConflict($exception)) {
                throw $exception;
            }

            return response()->json([
                'message' => 'An invoice with this number already exists for your company.',
            ], 409);
        }

        return (new InvoiceResource($invoice))
            ->response()
            ->setStatusCode(201);
    }

    private function isInvoiceNumberConflict(QueryException $exception): bool
    {
        $message = strtolower($exception->getMessage());

        return match (DB::connection()->getDriverName()) {
            'sqlite' => str_contains(
                $message,
                'unique constraint failed: invoices.company_id, invoices.invoice_number',
            ),
            'mysql', 'pgsql' => str_contains(
                $message,
                'invoices_company_invoice_number_unique',
            ),
            default => false,
        };
    }
}
