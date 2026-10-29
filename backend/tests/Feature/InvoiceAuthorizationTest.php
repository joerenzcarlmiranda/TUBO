<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InvoiceAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_only_view_or_update_invoices_for_their_company(): void
    {
        $companyA = Company::query()->create(['legal_name' => 'Company A']);
        $companyB = Company::query()->create(['legal_name' => 'Company B']);
        $userA = $this->createCompanyUser($companyA);
        $invoiceA = $this->createInvoice($companyA);
        $invoiceB = $this->createInvoice($companyB);

        $this->assertTrue($userA->can('view', $invoiceA));
        $this->assertTrue($userA->can('update', $invoiceA));
        $this->assertFalse($userA->can('view', $invoiceB));
        $this->assertFalse($userA->can('update', $invoiceB));
    }

    private function createCompanyUser(Company $company): User
    {
        $user = User::factory()->create();
        $user->company()->associate($company);
        $user->save();

        return $user;
    }

    private function createInvoice(Company $company): Invoice
    {
        return $company->invoices()->create([
            'invoice_number' => 'INV-'.fake()->unique()->numerify('######'),
            'invoice_date' => '2026-10-29',
            'seller_name' => $company->legal_name,
            'customer_name' => 'Customer',
            'currency' => 'USD',
            'subtotal' => 100,
            'tax_amount' => 10,
            'total_amount' => 110,
            'status' => 'pending',
        ]);
    }
}
