<?php

namespace App\Http\Resources;

use App\Models\InvoiceItem;
use App\Models\InvoiceProcessingAttempt;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InvoiceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'invoice_number' => $this->invoice_number,
            'invoice_date' => $this->invoice_date->toDateString(),
            'seller' => [
                'name' => $this->seller_name,
                'tax_id' => $this->seller_tax_id,
            ],
            'customer' => [
                'name' => $this->customer_name,
                'tax_id' => $this->customer_tax_id,
                'email' => $this->customer_email,
            ],
            'currency' => $this->currency,
            'subtotal' => $this->subtotal,
            'tax_amount' => $this->tax_amount,
            'total_amount' => $this->total_amount,
            'status' => $this->status->value,
            'items' => $this->whenLoaded(
                'items',
                fn () => $this->items->map(fn (InvoiceItem $item): array => [
                    'id' => $item->id,
                    'position' => $item->position,
                    'description' => $item->description,
                    'quantity' => $item->quantity,
                    'unit_price' => $item->unit_price,
                    'tax_rate' => $item->tax_rate,
                    'tax_amount' => $item->tax_amount,
                    'line_total' => $item->line_total,
                ])->values(),
            ),
            'processing_attempts' => $this->whenLoaded(
                'processingAttempts',
                fn () => $this->processingAttempts
                    ->map(fn (InvoiceProcessingAttempt $attempt): array => [
                        'attempt_number' => $attempt->attempt_number,
                        'status' => $attempt->status->value,
                        'started_at' => $attempt->started_at,
                        'finished_at' => $attempt->finished_at,
                        'http_status_code' => $attempt->http_status_code,
                        'error_message' => $attempt->error_message,
                    ])
                    ->values(),
            ),
            'retry_available_at' => $this->whenLoaded(
                'processingAttempts',
                function (): ?string {
                    if ($this->status->value !== 'failed') {
                        return null;
                    }

                    /** @var InvoiceProcessingAttempt|null $latestAttempt */
                    $latestAttempt = $this->processingAttempts->last();

                    if ($latestAttempt === null || ! $latestAttempt->canBeRetried()) {
                        return null;
                    }

                    $cooldownMinutes = (int) config(
                        'services.government.manual_retry_cooldown_minutes',
                        5,
                    );

                    return ($latestAttempt->finished_at ?? $latestAttempt->started_at)
                        ->copy()
                        ->addMinutes($cooldownMinutes)
                        ->toIso8601String();
                },
            ),
            'created_at' => $this->created_at,
        ];
    }
}
