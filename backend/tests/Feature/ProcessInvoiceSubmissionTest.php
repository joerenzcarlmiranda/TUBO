<?php

namespace Tests\Feature;

use App\Enums\InvoiceProcessingAttemptStatus;
use App\Enums\InvoiceStatus;
use App\Exceptions\RetryableGovernmentSubmissionException;
use App\Jobs\ProcessInvoiceSubmission;
use App\Models\Company;
use App\Models\Invoice;
use App\Services\GovernmentInvoiceGateway;
use App\Services\GovernmentInvoiceResponse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Mockery;
use Tests\TestCase;

class ProcessInvoiceSubmissionTest extends TestCase
{
    use RefreshDatabase;

    public function test_successful_submission_records_attempt_and_marks_invoice_submitted(): void
    {
        $invoice = $this->createInvoice();
        $gateway = Mockery::mock(GovernmentInvoiceGateway::class);
        $gateway->shouldReceive('submit')
            ->once()
            ->with(Mockery::on(fn (Invoice $submitted): bool => $submitted->id === $invoice->id))
            ->andReturn(new GovernmentInvoiceResponse(200, '{"status":"accepted"}'));

        (new ProcessInvoiceSubmission($invoice->id))->handle($gateway);

        $invoice->refresh();
        $attempt = $invoice->processingAttempts()->first();

        $this->assertSame(InvoiceStatus::Submitted, $invoice->status);
        $this->assertSame(1, $attempt->attempt_number);
        $this->assertSame(InvoiceProcessingAttemptStatus::Succeeded, $attempt->status);
        $this->assertSame(200, $attempt->http_status_code);
        $this->assertSame('{"status":"accepted"}', $attempt->response_body);
        $this->assertNotNull($attempt->finished_at);
    }

    public function test_temporary_failures_are_logged_and_retried_before_final_failure(): void
    {
        $invoice = $this->createInvoice();
        $job = new ProcessInvoiceSubmission($invoice->id);

        foreach (range(1, 3) as $attemptNumber) {
            $gateway = Mockery::mock(GovernmentInvoiceGateway::class);
            $gateway->shouldReceive('submit')
                ->once()
                ->andReturn(new GovernmentInvoiceResponse(
                    503,
                    '{"message":"Service unavailable."}',
                ));

            try {
                $job->handle($gateway);
                $this->fail('A temporary failure must be retried by the queue.');
            } catch (RetryableGovernmentSubmissionException) {
                $this->assertDatabaseHas('invoice_processing_attempts', [
                    'invoice_id' => $invoice->id,
                    'attempt_number' => $attemptNumber,
                    'status' => InvoiceProcessingAttemptStatus::Failed->value,
                    'http_status_code' => 503,
                    'error_message' => 'Service unavailable.',
                ]);
            }
        }

        $job->failed(new RetryableGovernmentSubmissionException('Retries exhausted.'));

        $this->assertSame(InvoiceStatus::Failed, $invoice->refresh()->status);
        $this->assertDatabaseCount('invoice_processing_attempts', 3);
    }

    public function test_permanent_rejection_marks_invoice_failed_without_retrying(): void
    {
        $invoice = $this->createInvoice();
        $gateway = Mockery::mock(GovernmentInvoiceGateway::class);
        $gateway->shouldReceive('submit')
            ->once()
            ->andReturn(new GovernmentInvoiceResponse(400, '{"message":"Invalid invoice."}'));

        (new ProcessInvoiceSubmission($invoice->id))->handle($gateway);

        $this->assertSame(InvoiceStatus::Failed, $invoice->refresh()->status);
        $this->assertDatabaseHas('invoice_processing_attempts', [
            'invoice_id' => $invoice->id,
            'attempt_number' => 1,
            'status' => InvoiceProcessingAttemptStatus::Failed->value,
            'http_status_code' => 400,
            'error_message' => 'Invalid invoice.',
        ]);
    }

    public function test_connection_failure_is_logged_so_the_queue_can_retry(): void
    {
        $invoice = $this->createInvoice();
        $gateway = Mockery::mock(GovernmentInvoiceGateway::class);
        $gateway->shouldReceive('submit')
            ->once()
            ->andThrow(new ConnectionException('Connection timed out.'));

        try {
            (new ProcessInvoiceSubmission($invoice->id))->handle($gateway);
            $this->fail('A connection failure must be retried by the queue.');
        } catch (ConnectionException) {
            $this->assertSame(InvoiceStatus::Processing, $invoice->refresh()->status);
            $this->assertDatabaseHas('invoice_processing_attempts', [
                'invoice_id' => $invoice->id,
                'attempt_number' => 1,
                'status' => InvoiceProcessingAttemptStatus::Failed->value,
                'error_message' => 'The government service request timed out or could not connect.',
            ]);
        }
    }

    public function test_stale_processing_attempt_is_recovered_after_a_worker_crash(): void
    {
        $invoice = $this->createInvoice();
        $invoice->update(['status' => InvoiceStatus::Processing]);
        $invoice->processingAttempts()->create([
            'attempt_number' => 1,
            'status' => InvoiceProcessingAttemptStatus::Processing,
            'started_at' => now()->subSeconds(61),
        ]);
        $gateway = Mockery::mock(GovernmentInvoiceGateway::class);
        $gateway->shouldReceive('submit')
            ->once()
            ->andReturn(new GovernmentInvoiceResponse(200, '{"status":"accepted"}'));

        (new ProcessInvoiceSubmission($invoice->id))->handle($gateway);

        $this->assertSame(InvoiceStatus::Submitted, $invoice->refresh()->status);
        $this->assertDatabaseHas('invoice_processing_attempts', [
            'invoice_id' => $invoice->id,
            'attempt_number' => 1,
            'status' => InvoiceProcessingAttemptStatus::Failed->value,
            'error_message' => 'The previous worker stopped before recording a result.',
        ]);
        $this->assertDatabaseHas('invoice_processing_attempts', [
            'invoice_id' => $invoice->id,
            'attempt_number' => 2,
            'status' => InvoiceProcessingAttemptStatus::Succeeded->value,
        ]);
    }

    public function test_active_attempt_is_not_processed_twice(): void
    {
        $invoice = $this->createInvoice();
        $invoice->update(['status' => InvoiceStatus::Processing]);
        $invoice->processingAttempts()->create([
            'attempt_number' => 1,
            'status' => InvoiceProcessingAttemptStatus::Processing,
            'started_at' => now(),
        ]);
        $gateway = Mockery::mock(GovernmentInvoiceGateway::class);
        $gateway->shouldNotReceive('submit');

        (new ProcessInvoiceSubmission($invoice->id))->handle($gateway);

        $this->assertSame(InvoiceStatus::Processing, $invoice->refresh()->status);
        $this->assertDatabaseCount('invoice_processing_attempts', 1);
    }

    private function createInvoice(): Invoice
    {
        $company = Company::query()->create(['legal_name' => 'ABC Corporation']);

        return $company->invoices()->create([
            'invoice_number' => 'INV-'.fake()->unique()->numerify('######'),
            'invoice_date' => '2026-10-29',
            'seller_name' => $company->legal_name,
            'customer_name' => 'Customer',
            'currency' => 'USD',
            'subtotal' => 100,
            'tax_amount' => 10,
            'total_amount' => 110,
            'status' => InvoiceStatus::Pending,
        ]);
    }
}
