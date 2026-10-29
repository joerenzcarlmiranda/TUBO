<?php

namespace Tests\Feature;

use App\Enums\InvoiceProcessingAttemptStatus;
use App\Enums\InvoiceStatus;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InvoiceSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_invoice_company_items_and_processing_attempts_are_related(): void
    {
        $company = Company::query()->create([
            'legal_name' => 'ABC Corporation',
            'tax_id' => 'ABC-123',
        ]);
        $user = User::factory()->create();
        $user->company()->associate($company);
        $user->save();

        $invoice = $company->invoices()->create([
            'invoice_number' => 'INV-10001',
            'invoice_date' => '2026-10-29',
            'seller_name' => 'ABC Corporation',
            'seller_tax_id' => 'ABC-123',
            'customer_name' => 'Customer One',
            'customer_tax_id' => 'CUST-456',
            'currency' => 'USD',
            'subtotal' => 100,
            'tax_amount' => 10,
            'total_amount' => 110,
            'status' => InvoiceStatus::Pending,
        ]);
        $invoice->createdBy()->associate($user);
        $invoice->save();

        $invoice->items()->create([
            'position' => 1,
            'description' => 'Consulting',
            'quantity' => 2,
            'unit_price' => 50,
            'tax_rate' => 10,
            'tax_amount' => 10,
            'line_total' => 110,
        ]);
        $invoice->processingAttempts()->create([
            'attempt_number' => 1,
            'status' => InvoiceProcessingAttemptStatus::Processing,
            'started_at' => now(),
        ]);

        $invoice->load('company', 'createdBy', 'items', 'processingAttempts');

        $this->assertSame($company->id, $invoice->company->id);
        $this->assertSame($user->id, $invoice->createdBy->id);
        $this->assertSame(InvoiceStatus::Pending, $invoice->status);
        $this->assertSame('110.00', $invoice->total_amount);
        $this->assertCount(1, $invoice->items);
        $this->assertSame('2.000', $invoice->items->first()->quantity);
        $this->assertSame(
            InvoiceProcessingAttemptStatus::Processing,
            $invoice->processingAttempts->first()->status,
        );
    }

    public function test_invoice_number_is_unique_per_company(): void
    {
        $firstCompany = Company::query()->create(['legal_name' => 'Company One']);
        $secondCompany = Company::query()->create(['legal_name' => 'Company Two']);

        $this->createInvoice($firstCompany, 'INV-10001');
        $this->createInvoice($secondCompany, 'INV-10001');

        $this->expectException(QueryException::class);
        $this->createInvoice($firstCompany, 'INV-10001');
    }

    public function test_attempt_number_is_unique_per_invoice(): void
    {
        $company = Company::query()->create(['legal_name' => 'ABC Corporation']);
        $invoice = $this->createInvoice($company, 'INV-10001');
        $attempt = [
            'attempt_number' => 1,
            'status' => InvoiceProcessingAttemptStatus::Failed,
            'started_at' => now(),
            'finished_at' => now(),
            'error_message' => 'Temporary service failure',
        ];

        $invoice->processingAttempts()->create($attempt);

        $this->expectException(QueryException::class);
        $invoice->processingAttempts()->create($attempt);
    }

    private function createInvoice(Company $company, string $invoiceNumber): Invoice
    {
        return $company->invoices()->create([
            'invoice_number' => $invoiceNumber,
            'invoice_date' => '2026-10-29',
            'seller_name' => $company->legal_name,
            'customer_name' => 'Customer One',
            'currency' => 'USD',
            'subtotal' => 100,
            'tax_amount' => 10,
            'total_amount' => 110,
            'status' => InvoiceStatus::Pending,
        ]);
    }
}
