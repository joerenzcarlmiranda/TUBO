<?php

namespace App\Http\Requests;

use App\Enums\InvoiceStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexInvoicesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->company_id !== null;
    }

    public function rules(): array
    {
        return [
            'status' => ['sometimes', 'string', Rule::enum(InvoiceStatus::class)],
            'invoice_number' => ['sometimes', 'string', 'max:100'],
            'invoice_date_from' => ['sometimes', 'date_format:Y-m-d'],
            'invoice_date_to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:invoice_date_from'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
