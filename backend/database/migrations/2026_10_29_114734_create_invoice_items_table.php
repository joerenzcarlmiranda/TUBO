<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('position');
            $table->text('description');
            $table->decimal('quantity', 12, 3);
            $table->decimal('unit_price', 15, 4);
            $table->decimal('tax_rate', 7, 4)->default(0);
            $table->decimal('tax_amount', 15, 2);
            $table->decimal('line_total', 15, 2);
            $table->timestamps();

            $table->unique(['invoice_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_items');
    }
};
