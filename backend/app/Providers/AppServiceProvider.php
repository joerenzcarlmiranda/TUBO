<?php

namespace App\Providers;

use App\Models\Invoice;
use App\Policies\InvoicePolicy;
use App\Services\GovernmentInvoiceGateway;
use App\Services\HttpGovernmentInvoiceGateway;
use App\Services\MockGovernmentInvoiceGateway;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(
            GovernmentInvoiceGateway::class,
            config('services.government.mock', true)
                ? MockGovernmentInvoiceGateway::class
                : HttpGovernmentInvoiceGateway::class,
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::policy(Invoice::class, InvoicePolicy::class);
    }
}
