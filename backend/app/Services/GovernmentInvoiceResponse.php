<?php

namespace App\Services;

readonly class GovernmentInvoiceResponse
{
    public function __construct(
        public int $statusCode,
        public string $body,
    ) {}

    public function wasSuccessful(): bool
    {
        return $this->statusCode >= 200 && $this->statusCode < 300;
    }

    public function isRetryable(): bool
    {
        return $this->statusCode === 408
            || $this->statusCode === 429
            || ($this->statusCode >= 500 && $this->statusCode < 600);
    }
}
