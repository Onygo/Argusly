<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_invoices', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subscription_id')->nullable()->constrained()->nullOnDelete();
            $table->string('provider')->default('mollie')->index();
            $table->string('provider_invoice_id')->nullable()->index();
            $table->string('provider_payment_id')->nullable()->index();
            $table->string('status')->default('draft')->index();
            $table->string('currency', 3)->default('EUR');
            $table->integer('subtotal_amount')->default(0);
            $table->integer('tax_amount')->default(0);
            $table->integer('total_amount')->default(0);
            $table->date('period_start')->nullable();
            $table->date('period_end')->nullable();
            $table->json('line_items')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('issued_at')->nullable()->index();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('due_at')->nullable();
            $table->timestamps();

            $table->index(['account_id', 'status', 'issued_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_invoices');
    }
};
