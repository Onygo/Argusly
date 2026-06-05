<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_sequences', function (Blueprint $table) {
            $table->unsignedSmallInteger('year')->primary();
            $table->unsignedInteger('next_number')->default(1);
            $table->timestamps();
        });

        Schema::create('invoices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('organization_id');
            $table->uuid('subscription_id')->nullable();
            $table->uuid('payment_intent_id')->nullable();
            $table->uuid('credit_pack_purchase_id')->nullable();

            $table->string('type', 32); // subscription | credit_pack
            $table->string('number', 32)->unique();
            $table->string('status', 32)->default('issued');

            $table->string('currency', 8)->default('EUR');
            $table->unsignedInteger('subtotal_cents');
            $table->unsignedInteger('tax_cents')->default(0);
            $table->unsignedInteger('total_cents');

            $table->decimal('vat_rate', 5, 2)->default(0);
            $table->string('vat_type', 32)->nullable(); // nl_vat | eu_reverse_charge | export_outside_eu
            $table->boolean('reverse_charge')->default(false);

            $table->string('refund_reference', 128)->nullable();
            $table->timestamp('issued_at');
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('refunded_at')->nullable();

            $table->string('billing_company_name');
            $table->string('billing_address_line1')->nullable();
            $table->string('billing_address_line2')->nullable();
            $table->string('billing_postal_code', 64)->nullable();
            $table->string('billing_city', 128)->nullable();
            $table->string('billing_country_code', 2)->nullable();
            $table->string('billing_vat_number', 64)->nullable();
            $table->string('billing_kvk_number', 64)->nullable();

            $table->string('pdf_path')->nullable();
            $table->string('pdf_checksum', 128)->nullable();
            $table->string('immutable_hash', 128)->nullable();
            $table->json('meta')->nullable();

            $table->timestamps();

            $table->index(['organization_id', 'issued_at']);
            $table->index(['type', 'issued_at']);
            $table->index(['status', 'issued_at']);
            $table->unique(['payment_intent_id']);

            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('subscription_id')->references('id')->on('subscriptions')->nullOnDelete();
            $table->foreign('payment_intent_id')->references('id')->on('payment_intents')->nullOnDelete();
            $table->foreign('credit_pack_purchase_id')->references('id')->on('credit_pack_purchases')->nullOnDelete();
        });

        Schema::create('invoice_items', function (Blueprint $table) {
            $table->id();
            $table->uuid('invoice_id');
            $table->string('description');
            $table->decimal('quantity', 8, 2)->default(1);
            $table->unsignedInteger('unit_price_cents');
            $table->unsignedInteger('subtotal_cents');
            $table->decimal('tax_rate', 5, 2)->default(0);
            $table->unsignedInteger('tax_cents')->default(0);
            $table->unsignedInteger('total_cents');
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index('invoice_id');
            $table->foreign('invoice_id')->references('id')->on('invoices')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_items');
        Schema::dropIfExists('invoices');
        Schema::dropIfExists('invoice_sequences');
    }
};
