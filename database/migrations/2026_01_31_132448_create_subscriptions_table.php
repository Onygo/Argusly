<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->uuid('client_site_id')->unique();
            $table->uuid('plan_id');

            // trialing, active, past_due, canceled
            $table->string('status', 32)->default('active');

            $table->timestamp('current_period_start')->nullable();
            $table->timestamp('current_period_end')->nullable();

            // Voor straks Mollie
            $table->string('provider', 32)->nullable(); // mollie
            $table->string('provider_customer_id', 128)->nullable();
            $table->string('provider_subscription_id', 128)->nullable();

            $table->timestamp('canceled_at')->nullable();

            $table->json('meta')->nullable();

            $table->timestamps();

            $table->index(['status']);
            $table->index(['plan_id']);

            $table->foreign('client_site_id')
                ->references('id')
                ->on('client_sites')
                ->cascadeOnDelete();

            $table->foreign('plan_id')
                ->references('id')
                ->on('plans')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
    }
};
