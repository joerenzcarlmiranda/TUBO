<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('created_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->string('invoice_number', 100);
            $table->date('invoice_date');
            $table->string('seller_name');
            $table->string('seller_tax_id')->nullable();
            $table->string('customer_name');
            $table->string('customer_tax_id')->nullable();
            $table->string('customer_email')->nullable();
            $table->char('currency', 3);
            $table->decimal('subtotal', 15, 2);
            $table->decimal('tax_amount', 15, 2);
            $table->decimal('total_amount', 15, 2);
            $table->string('status', 20)->default('pending');
            $table->timestamps();

            $table->unique(
                ['company_id', 'invoice_number'],
                'invoices_company_invoice_number_unique',
            );
            $table->index(['company_id', 'status'], 'invoices_company_status_index');
            $table->index(['company_id', 'invoice_date'], 'invoices_company_date_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
