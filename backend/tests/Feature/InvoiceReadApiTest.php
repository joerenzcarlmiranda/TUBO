<?php

namespace Tests\Feature;

use App\Enums\InvoiceProcessingAttemptStatus;
use App\Enums\InvoiceStatus;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

class InvoiceReadApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['jwt.secret' => 'test-only-jwt-secret-for-feature-tests']);
    }

    public function test_list_returns_only_company_invoices_with_paginated_data(): void
    {
        $company = Company::query()->create(['legal_name' => 'Company A']);
        $otherCompany = Company::query()->create(['legal_name' => 'Company B']);
        $user = $this->createCompanyUser($company);
        $first = $this->createInvoice($company, 'INV-10001', '2026-10-28');
        $second = $this->createInvoice($company, 'INV-10002', '2026-10-29');
        $this->createInvoice($otherCompany, 'INV-20001', '2026-10-29');
        $token = $this->authenticate($user);

        $firstPage = $this->withToken($token)
            ->getJson('/api/v1/invoices?per_page=1')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $second->id)
            ->assertJsonPath('data.0.invoice_number', 'INV-10002')
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.per_page', 1)
            ->assertJsonPath('meta.total', 2)
            ->assertJsonMissingPath('data.0.items')
            ->assertJsonMissingPath('data.0.processing_attempts');
        $this->assertNotNull($firstPage->json('links.next'));

        JWTAuth::unsetToken();
        Auth::forgetGuards();

        $this->withToken($token)
            ->getJson('/api/v1/invoices?page=2&per_page=1')
            ->assertOk()
            ->assertJsonPath('data.0.id', $first->id)
            ->assertJsonPath('meta.current_page', 2);
    }

    public function test_list_filters_by_status_invoice_number_and_invoice_date_range(): void
    {
        $company = Company::query()->create(['legal_name' => 'Company A']);
        $user = $this->createCompanyUser($company);
        $matching = $this->createInvoice(
            $company,
            'INV-10002',
            '2026-10-29',
            InvoiceStatus::Submitted,
        );
        $this->createInvoice($company, 'INV-10001', '2026-10-29', InvoiceStatus::Pending);
        $this->createInvoice($company, 'INV-10003', '2026-10-30', InvoiceStatus::Submitted);
        $token = $this->authenticate($user);

        $this->withToken($token)
            ->getJson(
                '/api/v1/invoices?status=submitted&invoice_number=INV-10002'.
                '&invoice_date_from=2026-10-29&invoice_date_to=2026-10-29',
            )
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $matching->id)
            ->assertJsonPath('meta.total', 1);
    }

    public function test_invalid_invoice_filters_return_validation_errors(): void
    {
        $company = Company::query()->create(['legal_name' => 'Company A']);
        $token = $this->authenticate($this->createCompanyUser($company));

        $this->withToken($token)
            ->getJson(
                '/api/v1/invoices?status=unknown&invoice_date_from=not-a-date'.
                '&invoice_date_to=2026-10-29&per_page=101',
            )
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'status',
                'invoice_date_from',
                'invoice_date_to',
                'per_page',
            ]);
    }

    public function test_detail_returns_invoice_items_and_processing_attempts(): void
    {
        $company = Company::query()->create(['legal_name' => 'Company A']);
        $user = $this->createCompanyUser($company);
        $invoice = $this->createInvoice($company, 'INV-10001', '2026-10-29');
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
            'status' => InvoiceProcessingAttemptStatus::Failed,
            'started_at' => '2026-10-29 12:00:00',
            'finished_at' => '2026-10-29 12:00:05',
            'http_status_code' => 503,
            'response_body' => 'Do not expose raw responses.',
            'error_message' => 'Government service is temporarily unavailable.',
        ]);
        $token = $this->authenticate($user);

        $this->withToken($token)
            ->getJson("/api/v1/invoices/{$invoice->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $invoice->id)
            ->assertJsonPath('data.items.0.position', 1)
            ->assertJsonPath('data.items.0.description', 'Consulting')
            ->assertJsonPath('data.processing_attempts.0.attempt_number', 1)
            ->assertJsonPath('data.processing_attempts.0.status', 'failed')
            ->assertJsonPath('data.processing_attempts.0.http_status_code', 503)
            ->assertJsonPath(
                'data.processing_attempts.0.error_message',
                'Government service is temporarily unavailable.',
            )
            ->assertJsonMissingPath('data.processing_attempts.0.response_body');
    }

    public function test_detail_denies_access_to_another_company_invoice(): void
    {
        $companyA = Company::query()->create(['legal_name' => 'Company A']);
        $companyB = Company::query()->create(['legal_name' => 'Company B']);
        $user = $this->createCompanyUser($companyA);
        $invoice = $this->createInvoice($companyB, 'INV-20001', '2026-10-29');
        $token = $this->authenticate($user);

        $this->withToken($token)
            ->getJson("/api/v1/invoices/{$invoice->id}")
            ->assertForbidden();
    }

    public function test_invoice_read_endpoints_require_authentication(): void
    {
        $this->getJson('/api/v1/invoices')->assertUnauthorized();
        $this->getJson('/api/v1/invoices/1')->assertUnauthorized();
    }

    private function createCompanyUser(Company $company): User
    {
        $user = User::factory()->create();
        $user->company()->associate($company);
        $user->save();

        return $user;
    }

    private function authenticate(User $user): string
    {
        $token = $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertOk()->json('access_token');

        JWTAuth::unsetToken();
        Auth::forgetGuards();

        return $token;
    }

    private function createInvoice(
        Company $company,
        string $invoiceNumber,
        string $invoiceDate,
        InvoiceStatus $status = InvoiceStatus::Pending,
    ): Invoice {
        return $company->invoices()->create([
            'invoice_number' => $invoiceNumber,
            'invoice_date' => $invoiceDate,
            'seller_name' => $company->legal_name,
            'customer_name' => 'Customer',
            'currency' => 'USD',
            'subtotal' => 100,
            'tax_amount' => 10,
            'total_amount' => 110,
            'status' => $status,
        ]);
    }
}
