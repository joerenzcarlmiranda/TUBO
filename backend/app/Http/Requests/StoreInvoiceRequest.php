<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->company_id !== null;
    }

    public function rules(): array
    {
        return [
            'company_id' => ['prohibited'],
            'created_by_user_id' => ['prohibited'],
            'seller_name' => ['prohibited'],
            'seller_tax_id' => ['prohibited'],
            'subtotal' => ['prohibited'],
            'tax_amount' => ['prohibited'],
            'total_amount' => ['prohibited'],
            'status' => ['prohibited'],
            'invoice_number' => ['required', 'string', 'max:100'],
            'invoice_date' => ['required', 'date_format:Y-m-d'],
            'customer_name' => ['required', 'string', 'max:255'],
            'customer_tax_id' => ['nullable', 'string', 'max:255'],
            'customer_email' => ['nullable', 'email:rfc', 'max:255'],
            'currency' => ['required', 'string', 'regex:/\\A[A-Z]{3}\\z/'],
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*' => ['required', 'array'],
            'items.*.position' => ['prohibited'],
            'items.*.tax_amount' => ['prohibited'],
            'items.*.line_total' => ['prohibited'],
            'items.*.description' => ['required', 'string', 'max:10000'],
            'items.*.quantity' => [
                'required',
                'numeric',
                'decimal:0,3',
                'gt:0',
                'max:999999999.999',
            ],
            'items.*.unit_price' => [
                'required',
                'numeric',
                'decimal:0,4',
                'min:0',
                'max:99999999999.9999',
            ],
            'items.*.tax_rate' => [
                'sometimes',
                'nullable',
                'numeric',
                'decimal:0,4',
                'min:0',
                'max:100',
            ],
        ];
    }
}
