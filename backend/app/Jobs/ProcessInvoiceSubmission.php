<?php

namespace App\Jobs;

use App\Enums\InvoiceProcessingAttemptStatus;
use App\Enums\InvoiceStatus;
use App\Exceptions\RetryableGovernmentSubmissionException;
use App\Models\Invoice;
use App\Models\InvoiceProcessingAttempt;
use App\Services\GovernmentInvoiceGateway;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Throwable;

class ProcessInvoiceSubmission implements ShouldBeUnique, ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 40;

    public int $uniqueFor = 600;

    public function __construct(public readonly int $invoiceId) {}

    public function backoff(): array
    {
        return [30, 120];
    }

    public function uniqueId(): string
    {
        return (string) $this->invoiceId;
    }

    public function handle(GovernmentInvoiceGateway $gateway): void
    {
        $attemptId = $this->beginAttempt();

        if ($attemptId === null) {
            return;
        }

        $invoice = Invoice::query()->with('items')->find($this->invoiceId);

        if ($invoice === null) {
            return;
        }

        try {
            $response = $gateway->submit($invoice);
        } catch (ConnectionException $exception) {
            $this->finishAttempt(
                $attemptId,
                InvoiceProcessingAttemptStatus::Failed,
                null,
                'The government service request timed out or could not connect.',
            );

            throw $exception;
        }

        if ($response->wasSuccessful()) {
            $this->finishAttempt(
                $attemptId,
                InvoiceProcessingAttemptStatus::Succeeded,
                $response->statusCode,
                null,
                $response->body,
            );

            return;
        }

        $message = $this->errorMessage($response->body);
        $this->finishAttempt(
            $attemptId,
            InvoiceProcessingAttemptStatus::Failed,
            $response->statusCode,
            $message,
            $response->body,
            ! $response->isRetryable(),
        );

        if ($response->isRetryable()) {
            throw new RetryableGovernmentSubmissionException(
                "Government service returned HTTP {$response->statusCode}.",
            );
        }
    }

    public function failed(?Throwable $exception): void
    {
        DB::transaction(function (): void {
            $invoice = Invoice::query()->lockForUpdate()->find($this->invoiceId);

            if ($invoice === null || $invoice->status === InvoiceStatus::Submitted) {
                return;
            }

            $latestAttempt = $invoice->processingAttempts()
                ->reorder()
                ->orderByDesc('attempt_number')
                ->first();

            if ($latestAttempt?->status === InvoiceProcessingAttemptStatus::Processing) {
                $latestAttempt->update([
                    'status' => InvoiceProcessingAttemptStatus::Failed,
                    'finished_at' => now(),
                    'error_message' => 'Invoice processing stopped after all queue attempts failed.',
                ]);
            }

            $invoice->update(['status' => InvoiceStatus::Failed]);
        });
    }

    private function beginAttempt(): ?int
    {
        return DB::transaction(function (): ?int {
            $invoice = Invoice::query()->lockForUpdate()->find($this->invoiceId);

            if ($invoice === null
                || in_array($invoice->status, [InvoiceStatus::Submitted, InvoiceStatus::Failed], true)
            ) {
                return null;
            }

            $latestAttempt = $invoice->processingAttempts()
                ->reorder()
                ->orderByDesc('attempt_number')
                ->first();

            if ($latestAttempt?->status === InvoiceProcessingAttemptStatus::Processing) {
                $leaseExpiresAt = now()->subSeconds(
                    (int) config('services.government.processing_lease_seconds', 60),
                );

                if ($latestAttempt->started_at->greaterThan($leaseExpiresAt)) {
                    return null;
                }

                $latestAttempt->update([
                    'status' => InvoiceProcessingAttemptStatus::Failed,
                    'finished_at' => now(),
                    'error_message' => 'The previous worker stopped before recording a result.',
                ]);
            }

            $attempt = $invoice->processingAttempts()->create([
                'attempt_number' => ($latestAttempt?->attempt_number ?? 0) + 1,
                'status' => InvoiceProcessingAttemptStatus::Processing,
                'started_at' => now(),
            ]);

            $invoice->update(['status' => InvoiceStatus::Processing]);

            return $attempt->id;
        });
    }

    private function finishAttempt(
        int $attemptId,
        InvoiceProcessingAttemptStatus $status,
        ?int $httpStatusCode,
        ?string $errorMessage,
        ?string $responseBody = null,
        bool $invoiceFailed = false,
    ): void {
        DB::transaction(function () use (
            $attemptId,
            $errorMessage,
            $httpStatusCode,
            $invoiceFailed,
            $responseBody,
            $status,
        ): void {
            $invoice = Invoice::query()->lockForUpdate()->find($this->invoiceId);
            $attempt = InvoiceProcessingAttempt::query()->find($attemptId);

            if ($invoice === null || $attempt === null) {
                return;
            }

            $attempt->update([
                'status' => $status,
                'finished_at' => now(),
                'http_status_code' => $httpStatusCode,
                'response_body' => $responseBody,
                'error_message' => $errorMessage,
            ]);

            $latestAttemptId = $invoice->processingAttempts()
                ->reorder()
                ->orderByDesc('attempt_number')
                ->value('id');

            if ($latestAttemptId !== $attempt->id) {
                return;
            }

            if ($status === InvoiceProcessingAttemptStatus::Succeeded) {
                $invoice->update(['status' => InvoiceStatus::Submitted]);
            } elseif ($invoiceFailed) {
                $invoice->update(['status' => InvoiceStatus::Failed]);
            }
        });
    }

    private function errorMessage(string $responseBody): string
    {
        $decoded = json_decode($responseBody, true);

        if (is_array($decoded) && isset($decoded['message']) && is_string($decoded['message'])) {
            return substr($decoded['message'], 0, 2000);
        }

        return 'The government service rejected the invoice.';
    }
}
