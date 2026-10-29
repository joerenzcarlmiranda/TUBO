<?php

namespace App\Services;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Validation\ValidationException;

class InvoiceTotalsCalculator
{
    private const MAX_AMOUNT = '9999999999999.99';

    /**
     * @param  array<int, array{description: string, quantity: int|float|string, unit_price: int|float|string, tax_rate?: int|float|string|null}>  $items
     * @return array{
     *     subtotal: string,
     *     tax_amount: string,
     *     total_amount: string,
     *     items: array<int, array{
     *         position: int,
     *         description: string,
     *         quantity: string,
     *         unit_price: string,
     *         tax_rate: string,
     *         tax_amount: string,
     *         line_total: string
     *     }>
     * }
     */
    public function calculate(array $items): array
    {
        $subtotal = BigDecimal::zero();
        $taxAmount = BigDecimal::zero();
        $calculatedItems = [];

        foreach ($items as $index => $item) {
            $quantity = BigDecimal::of((string) $item['quantity']);
            $unitPrice = BigDecimal::of((string) $item['unit_price']);
            $taxRate = BigDecimal::of((string) ($item['tax_rate'] ?? '0'));
            $lineSubtotal = $quantity
                ->multipliedBy($unitPrice)
                ->toScale(2, RoundingMode::HALF_UP);

            $this->ensureAmountFits($lineSubtotal, "items.{$index}.unit_price");

            $lineTaxAmount = $lineSubtotal
                ->multipliedBy($taxRate)
                ->dividedBy('100', 2, RoundingMode::HALF_UP);

            $this->ensureAmountFits($lineTaxAmount, "items.{$index}.tax_rate");

            $lineTotal = $lineSubtotal->plus($lineTaxAmount);
            $this->ensureAmountFits($lineTotal, "items.{$index}");

            $subtotal = $subtotal->plus($lineSubtotal);
            $taxAmount = $taxAmount->plus($lineTaxAmount);
            $this->ensureAmountFits($subtotal, 'items');
            $this->ensureAmountFits($taxAmount, 'items');

            $calculatedItems[] = [
                'position' => $index + 1,
                'description' => $item['description'],
                'quantity' => (string) $quantity,
                'unit_price' => (string) $unitPrice,
                'tax_rate' => (string) $taxRate,
                'tax_amount' => (string) $lineTaxAmount,
                'line_total' => (string) $lineTotal,
            ];
        }

        $totalAmount = $subtotal->plus($taxAmount);
        $this->ensureAmountFits($totalAmount, 'items');

        return [
            'subtotal' => (string) $subtotal->toScale(2),
            'tax_amount' => (string) $taxAmount->toScale(2),
            'total_amount' => (string) $totalAmount->toScale(2),
            'items' => $calculatedItems,
        ];
    }

    private function ensureAmountFits(BigDecimal $amount, string $field): void
    {
        if ($amount->compareTo(self::MAX_AMOUNT) > 0) {
            throw ValidationException::withMessages([
                $field => 'The calculated amount exceeds the supported invoice limit.',
            ]);
        }
    }
}
