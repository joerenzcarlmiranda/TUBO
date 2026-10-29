<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Invoice;
use App\Services\HttpGovernmentInvoiceGateway;
use App\Services\MockGovernmentInvoiceGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GovernmentInvoiceGatewayTest extends TestCase
{
    use RefreshDatabase;

    public function test_http_gateway_sends_invoice_and_stable_idempotency_key(): void
    {
        Http::fake([
            'government.example.test/government-api/invoices' => Http::response(
                ['status' => 'accepted'],
                200,
            ),
        ]);
        $invoice = $this->createInvoice();

        $response = (new HttpGovernmentInvoiceGateway)->submit($invoice);

        $this->assertSame(200, $response->statusCode);
        $this->assertSame('{"status":"accepted"}', $response->body);
        Http::assertSent(fn ($request): bool => $request->hasHeader(
            'Idempotency-Key',
            'tubo-invoice-'.$invoice->id,
        ) && $request->url() === 'https://government.example.test/government-api/invoices'
            && $request['invoice_number'] === $invoice->invoice_number);
    }

    public function test_mock_gateway_can_simulate_configured_outcomes(): void
    {
        $invoice = $this->createInvoice();
        $gateway = new MockGovernmentInvoiceGateway;

        config(['services.government.mock_outcome' => 'success']);
        $this->assertSame(200, $gateway->submit($invoice)->statusCode);

        config(['services.government.mock_outcome' => 'temporary_failure']);
        $this->assertSame(503, $gateway->submit($invoice)->statusCode);

        config(['services.government.mock_outcome' => 'invalid']);
        $this->assertSame(400, $gateway->submit($invoice)->statusCode);

        config(['services.government.mock_outcome' => 'timeout']);
        $this->expectException(ConnectionException::class);
        $gateway->submit($invoice);
    }

    private function createInvoice(): Invoice
    {
        $company = Company::query()->create(['legal_name' => 'ABC Corporation']);

        return $company->invoices()->create([
            'invoice_number' => 'INV-10001',
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
