<?php

namespace Tests\Feature;

use App\Enums\InvoiceProcessingAttemptStatus;
use App\Enums\InvoiceStatus;
use App\Jobs\ProcessInvoiceSubmission;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

class RetryInvoiceSubmissionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['jwt.secret' => 'test-only-jwt-secret-for-feature-tests']);
        Queue::fake();
    }

    public function test_retryable_failed_invoice_is_queued_and_history_is_preserved(): void
    {
        $company = Company::query()->create(['legal_name' => 'Company A']);
        $invoice = $this->createFailedInvoice($company, 503);
        $token = $this->authenticate($this->createCompanyUser($company));

        $this->withToken($token)
            ->postJson("/api/v1/invoices/{$invoice->id}/retry")
            ->assertAccepted()
            ->assertJsonPath('data.id', $invoice->id)
            ->assertJsonPath('data.status', InvoiceStatus::Pending->value)
            ->assertJsonPath('data.processing_attempts.0.attempt_number', 1)
            ->assertJsonPath('data.processing_attempts.0.http_status_code', 503);

        $this->assertDatabaseCount('invoice_processing_attempts', 1);
        $this->assertDatabaseHas('invoice_processing_attempts', [
            'invoice_id' => $invoice->id,
            'attempt_number' => 1,
            'status' => InvoiceProcessingAttemptStatus::Failed->value,
            'http_status_code' => 503,
            'error_message' => 'Service unavailable.',
        ]);
        Queue::assertPushed(
            ProcessInvoiceSubmission::class,
            fn (ProcessInvoiceSubmission $job): bool => $job->invoiceId === $invoice->id
                && $job->afterCommit,
        );
    }

    public function test_retry_can_be_requested_only_once_while_submission_is_pending(): void
    {
        $company = Company::query()->create(['legal_name' => 'Company A']);
        $invoice = $this->createFailedInvoice($company, null);
        $token = $this->authenticate($this->createCompanyUser($company));

        $this->withToken($token)
            ->postJson("/api/v1/invoices/{$invoice->id}/retry")
            ->assertAccepted();

        $this->withToken($token)
            ->postJson("/api/v1/invoices/{$invoice->id}/retry")
            ->assertConflict()
            ->assertJsonPath(
                'message',
                'This invoice cannot be retried right now. Check its status, failure type, and retry cooldown.',
            );

        Queue::assertPushed(ProcessInvoiceSubmission::class, 1);
        $this->assertDatabaseCount('invoice_processing_attempts', 1);
    }

    public function test_permanent_rejection_cannot_be_manually_retried(): void
    {
        $company = Company::query()->create(['legal_name' => 'Company A']);
        $invoice = $this->createFailedInvoice($company, 400);
        $token = $this->authenticate($this->createCompanyUser($company));

        $this->withToken($token)
            ->postJson("/api/v1/invoices/{$invoice->id}/retry")
            ->assertConflict()
            ->assertJsonPath(
                'message',
                'This invoice cannot be retried right now. Check its status, failure type, and retry cooldown.',
            );

        Queue::assertNothingPushed();
        $this->assertSame(InvoiceStatus::Failed, $invoice->refresh()->status);
    }

    public function test_non_http_status_above_server_error_range_cannot_be_retried(): void
    {
        $company = Company::query()->create(['legal_name' => 'Company A']);
        $invoice = $this->createFailedInvoice($company, 600);
        $token = $this->authenticate($this->createCompanyUser($company));

        $this->withToken($token)
            ->postJson("/api/v1/invoices/{$invoice->id}/retry")
            ->assertConflict()
            ->assertJsonPath(
                'message',
                'This invoice cannot be retried right now. Check its status, failure type, and retry cooldown.',
            );

        Queue::assertNothingPushed();
        $this->assertSame(InvoiceStatus::Failed, $invoice->refresh()->status);
    }

    public function test_manual_retry_is_rejected_until_five_minute_cooldown_expires(): void
    {
        $this->travelTo(Carbon::parse('2026-10-29 12:10:00'));
        $company = Company::query()->create(['legal_name' => 'Company A']);
        $invoice = $this->createFailedInvoice($company, 503);
        $invoice->processingAttempts()->first()->update([
            'started_at' => now()->subMinutes(4)->subSeconds(30),
            'finished_at' => now()->subMinutes(4),
        ]);
        $token = $this->authenticate($this->createCompanyUser($company));

        $this->withToken($token)
            ->postJson("/api/v1/invoices/{$invoice->id}/retry")
            ->assertConflict();

        $this->withToken($token)
            ->getJson("/api/v1/invoices/{$invoice->id}")
            ->assertOk()
            ->assertJsonPath(
                'data.retry_available_at',
                now()->addMinute()->toIso8601String(),
            );

        Queue::assertNothingPushed();
        $this->assertSame(InvoiceStatus::Failed, $invoice->refresh()->status);
    }

    public function test_manual_retry_is_allowed_after_cooldown_expires(): void
    {
        $this->travelTo(Carbon::parse('2026-10-29 12:10:00'));
        $company = Company::query()->create(['legal_name' => 'Company A']);
        $invoice = $this->createFailedInvoice($company, 503);
        $invoice->processingAttempts()->first()->update([
            'started_at' => now()->subMinutes(5)->subSeconds(30),
            'finished_at' => now()->subMinutes(5),
        ]);
        $token = $this->authenticate($this->createCompanyUser($company));

        $this->withToken($token)
            ->postJson("/api/v1/invoices/{$invoice->id}/retry")
            ->assertAccepted()
            ->assertJsonPath('data.status', InvoiceStatus::Pending->value);

        Queue::assertPushed(ProcessInvoiceSubmission::class, 1);
    }

    public function test_invoice_from_another_company_cannot_be_retried(): void
    {
        $companyA = Company::query()->create(['legal_name' => 'Company A']);
        $companyB = Company::query()->create(['legal_name' => 'Company B']);
        $invoice = $this->createFailedInvoice($companyB, 503);
        $token = $this->authenticate($this->createCompanyUser($companyA));

        $this->withToken($token)
            ->postJson("/api/v1/invoices/{$invoice->id}/retry")
            ->assertForbidden();

        Queue::assertNothingPushed();
        $this->assertSame(InvoiceStatus::Failed, $invoice->refresh()->status);
    }

    public function test_retry_requires_authentication(): void
    {
        $company = Company::query()->create(['legal_name' => 'Company A']);
        $invoice = $this->createFailedInvoice($company, 503);

        $this->postJson("/api/v1/invoices/{$invoice->id}/retry")
            ->assertUnauthorized();

        Queue::assertNothingPushed();
    }

    public function test_retry_rejects_invoice_without_a_retryable_attempt(): void
    {
        $company = Company::query()->create(['legal_name' => 'Company A']);
        $invoice = $company->invoices()->create([
            'invoice_number' => 'INV-10001',
            'invoice_date' => '2026-10-29',
            'seller_name' => $company->legal_name,
            'customer_name' => 'Customer',
            'currency' => 'USD',
            'subtotal' => 100,
            'tax_amount' => 10,
            'total_amount' => 110,
            'status' => InvoiceStatus::Failed,
        ]);
        $token = $this->authenticate($this->createCompanyUser($company));

        $this->withToken($token)
            ->postJson("/api/v1/invoices/{$invoice->id}/retry")
            ->assertConflict();

        Queue::assertNothingPushed();
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

    private function createFailedInvoice(Company $company, ?int $httpStatusCode): Invoice
    {
        $invoice = $company->invoices()->create([
            'invoice_number' => 'INV-'.fake()->unique()->numerify('######'),
            'invoice_date' => '2026-10-29',
            'seller_name' => $company->legal_name,
            'customer_name' => 'Customer',
            'currency' => 'USD',
            'subtotal' => 100,
            'tax_amount' => 10,
            'total_amount' => 110,
            'status' => InvoiceStatus::Failed,
        ]);
        $invoice->processingAttempts()->create([
            'attempt_number' => 1,
            'status' => InvoiceProcessingAttemptStatus::Failed,
            'started_at' => now()->subMinutes(7),
            'finished_at' => now()->subMinutes(6),
            'http_status_code' => $httpStatusCode,
            'response_body' => $httpStatusCode === 400
                ? '{"message":"Invalid invoice."}'
                : '{"message":"Service unavailable."}',
            'error_message' => $httpStatusCode === 400
                ? 'Invalid invoice.'
                : 'Service unavailable.',
        ]);

        return $invoice;
    }
}
