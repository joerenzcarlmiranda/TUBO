<?php

namespace Tests\Feature;

use App\Enums\InvoiceStatus;
use App\Jobs\ProcessInvoiceSubmission;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

class CreateInvoiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['jwt.secret' => 'test-only-jwt-secret-for-feature-tests']);
        Queue::fake();
    }

    public function test_authenticated_company_user_can_create_invoice_with_server_calculated_totals(): void
    {
        $company = Company::query()->create([
            'legal_name' => 'ABC Corporation',
            'tax_id' => 'ABC-123',
        ]);
        $user = $this->createCompanyUser($company);
        $token = $this->authenticate($user);

        $response = $this->postInvoice($token, [
            'invoice_number' => 'INV-10001',
            'invoice_date' => '2026-10-29',
            'customer_name' => 'Customer One',
            'customer_tax_id' => 'CUST-456',
            'customer_email' => 'customer@example.com',
            'currency' => 'USD',
            'items' => [
                [
                    'description' => 'Consulting',
                    'quantity' => '2',
                    'unit_price' => '12.3456',
                    'tax_rate' => '10',
                ],
                [
                    'description' => 'Support',
                    'quantity' => '1.5',
                    'unit_price' => '3.3333',
                ],
            ],
        ]);

        $response
            ->assertAccepted()
            ->assertJsonPath('data.invoice_number', 'INV-10001')
            ->assertJsonPath('data.seller.name', 'ABC Corporation')
            ->assertJsonPath('data.seller.tax_id', 'ABC-123')
            ->assertJsonPath('data.customer.email', 'customer@example.com')
            ->assertJsonPath('data.currency', 'USD')
            ->assertJsonPath('data.subtotal', '29.69')
            ->assertJsonPath('data.tax_amount', '2.47')
            ->assertJsonPath('data.total_amount', '32.16')
            ->assertJsonPath('data.status', InvoiceStatus::Pending->value)
            ->assertJsonPath('data.items.0.tax_amount', '2.47')
            ->assertJsonPath('data.items.0.line_total', '27.16')
            ->assertJsonPath('data.items.1.tax_amount', '0.00')
            ->assertJsonPath('data.items.1.line_total', '5.00');

        $this->assertDatabaseHas('invoices', [
            'company_id' => $company->id,
            'created_by_user_id' => $user->id,
            'invoice_number' => 'INV-10001',
            'subtotal' => '29.69',
            'tax_amount' => '2.47',
            'total_amount' => '32.16',
        ]);
        $this->assertDatabaseCount('invoice_items', 2);
        Queue::assertPushed(
            ProcessInvoiceSubmission::class,
            fn (ProcessInvoiceSubmission $job): bool => $job->invoiceId === $response->json('data.id')
                && $job->afterCommit === true,
        );
    }

    public function test_database_unique_constraint_returns_conflict_for_duplicate_invoice(): void
    {
        $company = Company::query()->create(['legal_name' => 'ABC Corporation']);
        $token = $this->authenticate($this->createCompanyUser($company));
        $payload = $this->validPayload('INV-10001');

        $this->postInvoice($token, $payload)->assertAccepted();

        $this->postInvoice($token, $payload)
            ->assertConflict()
            ->assertJsonPath(
                'message',
                'An invoice with this number already exists for your company.',
            );

        $this->assertDatabaseCount('invoices', 1);
        $this->assertDatabaseCount('invoice_items', 1);
    }

    public function test_client_cannot_assign_company_seller_totals_or_status(): void
    {
        $company = Company::query()->create(['legal_name' => 'ABC Corporation']);
        $token = $this->authenticate($this->createCompanyUser($company));
        $payload = $this->validPayload('INV-10001');
        $payload['company_id'] = 999;
        $payload['seller_name'] = 'Forged Seller';
        $payload['subtotal'] = 0;
        $payload['total_amount'] = 0;
        $payload['status'] = 'submitted';

        $this->postInvoice($token, $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'company_id',
                'seller_name',
                'subtotal',
                'total_amount',
                'status',
            ]);

        $this->assertDatabaseCount('invoices', 0);
    }

    public function test_invalid_invoice_fields_and_items_are_rejected(): void
    {
        $company = Company::query()->create(['legal_name' => 'ABC Corporation']);
        $token = $this->authenticate($this->createCompanyUser($company));

        $this->postInvoice($token, [
            'invoice_number' => '',
            'invoice_date' => 'not-a-date',
            'customer_name' => '',
            'currency' => 'usd',
            'items' => [
                [
                    'description' => '',
                    'quantity' => '0',
                    'unit_price' => '-1',
                    'tax_rate' => '101',
                ],
            ],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'invoice_number',
                'invoice_date',
                'customer_name',
                'currency',
                'items.0.description',
                'items.0.quantity',
                'items.0.unit_price',
                'items.0.tax_rate',
            ]);

        $this->assertDatabaseCount('invoices', 0);
    }

    public function test_invoice_creation_requires_authentication(): void
    {
        $this->withHeader('Accept', 'application/json')
            ->postJson('/api/invoices', $this->validPayload('INV-10001'))
            ->assertUnauthorized();

        $this->assertDatabaseCount('invoices', 0);
    }

    public function test_calculated_amounts_must_fit_the_invoice_decimal_columns(): void
    {
        $company = Company::query()->create(['legal_name' => 'ABC Corporation']);
        $token = $this->authenticate($this->createCompanyUser($company));
        $payload = $this->validPayload('INV-10001');
        $payload['items'][0]['quantity'] = '999999999.999';
        $payload['items'][0]['unit_price'] = '99999999999.9999';

        $this->postInvoice($token, $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('items.0.unit_price');

        $this->assertDatabaseCount('invoices', 0);
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

    /**
     * Clear Laravel's cached guard so each simulated HTTP request authenticates its own bearer token.
     *
     * @param  array<string, mixed>  $payload
     */
    private function postInvoice(string $token, array $payload): TestResponse
    {
        JWTAuth::unsetToken();
        Auth::forgetGuards();

        return $this->withToken($token)->postJson('/api/invoices', $payload);
    }

    /**
     * @return array{
     *     invoice_number: string,
     *     invoice_date: string,
     *     customer_name: string,
     *     currency: string,
     *     items: array<int, array{
     *         description: string,
     *         quantity: string,
     *         unit_price: string,
     *         tax_rate: string
     *     }>
     * }
     */
    private function validPayload(string $invoiceNumber): array
    {
        return [
            'invoice_number' => $invoiceNumber,
            'invoice_date' => '2026-10-29',
            'customer_name' => 'Customer One',
            'currency' => 'USD',
            'items' => [
                [
                    'description' => 'Consulting',
                    'quantity' => '2',
                    'unit_price' => '10.00',
                    'tax_rate' => '10',
                ],
            ],
        ];
    }
}
