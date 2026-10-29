<?php

namespace App\Services;

use App\Models\Invoice;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

class HttpGovernmentInvoiceGateway implements GovernmentInvoiceGateway
{
    public function submit(Invoice $invoice): GovernmentInvoiceResponse
    {
        $invoice->loadMissing('items');

        $response = $this->request()
            ->withHeaders(['Idempotency-Key' => 'tubo-invoice-'.$invoice->id])
            ->post(config('services.government.url').'/invoices', [
                'invoice_number' => $invoice->invoice_number,
                'invoice_date' => $invoice->invoice_date->toDateString(),
                'seller' => [
                    'name' => $invoice->seller_name,
                    'tax_id' => $invoice->seller_tax_id,
                ],
                'customer' => [
                    'name' => $invoice->customer_name,
                    'tax_id' => $invoice->customer_tax_id,
                    'email' => $invoice->customer_email,
                ],
                'currency' => $invoice->currency,
                'subtotal' => $invoice->subtotal,
                'tax_amount' => $invoice->tax_amount,
                'total_amount' => $invoice->total_amount,
                'items' => $invoice->items->map(fn ($item): array => [
                    'description' => $item->description,
                    'quantity' => $item->quantity,
                    'unit_price' => $item->unit_price,
                    'tax_rate' => $item->tax_rate,
                    'tax_amount' => $item->tax_amount,
                    'line_total' => $item->line_total,
                ])->all(),
            ]);

        return new GovernmentInvoiceResponse(
            $response->status(),
            substr($response->body(), 0, 65535),
        );
    }

    private function request(): PendingRequest
    {
        return Http::acceptJson()
            ->asJson()
            ->timeout((int) config('services.government.timeout', 30));
    }
}
