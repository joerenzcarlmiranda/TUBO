<?php

namespace App\Services;

use App\Models\Invoice;
use Illuminate\Http\Client\ConnectionException;

class MockGovernmentInvoiceGateway implements GovernmentInvoiceGateway
{
    public function submit(Invoice $invoice): GovernmentInvoiceResponse
    {
        $outcome = config('services.government.mock_outcome', 'random');
        if ($outcome === 'random') {
            $outcomes = ['success', 'temporary_failure', 'invalid', 'timeout'];
            $outcome = $outcomes[random_int(0, count($outcomes) - 1)];
        }

        return match ($outcome) {
            'success' => new GovernmentInvoiceResponse(
                200,
                json_encode([
                    'status' => 'accepted',
                    'reference' => 'GOV-'.$invoice->id,
                ], JSON_THROW_ON_ERROR),
            ),
            'temporary_failure' => new GovernmentInvoiceResponse(
                503,
                '{"message":"The government service is temporarily unavailable."}',
            ),
            'invalid' => new GovernmentInvoiceResponse(
                400,
                '{"message":"The government service rejected the invoice."}',
            ),
            'timeout' => throw new ConnectionException('The mock government service timed out.'),
            default => throw new \InvalidArgumentException('Unsupported mock government API outcome.'),
        };
    }
}
