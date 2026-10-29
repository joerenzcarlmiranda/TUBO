<?php

namespace Tests\Unit;

use App\Services\GovernmentInvoiceResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class GovernmentInvoiceResponseTest extends TestCase
{
    #[DataProvider('successStatusCodes')]
    public function test_only_successful_http_status_codes_are_marked_successful(
        int $statusCode,
        bool $successful,
    ): void {
        $response = new GovernmentInvoiceResponse($statusCode, '');

        $this->assertSame($successful, $response->wasSuccessful());
    }

    #[DataProvider('retryableStatusCodes')]
    public function test_only_transient_http_status_codes_are_retryable(
        int $statusCode,
        bool $retryable,
    ): void {
        $response = new GovernmentInvoiceResponse($statusCode, '');

        $this->assertSame($retryable, $response->isRetryable());
    }

    public static function successStatusCodes(): array
    {
        return [
            'just below successful range' => [199, false],
            'first successful status' => [200, true],
            'last successful status' => [299, true],
            'first redirect status' => [300, false],
        ];
    }

    public static function retryableStatusCodes(): array
    {
        return [
            'request timeout' => [408, true],
            'too many requests' => [429, true],
            'last client error' => [499, false],
            'first server error' => [500, true],
            'last server error' => [599, true],
            'outside HTTP status range' => [600, false],
        ];
    }
}
