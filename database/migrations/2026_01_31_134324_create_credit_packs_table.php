<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credit_packs', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->string('key')->unique(); // pack_50, pack_200
            $table->string('name');

            $table->unsignedInteger('credits_amount');

            $table->unsignedInteger('price_cents');
            $table->string('currency', 8)->default('EUR');

            $table->boolean('is_active')->default(true);

            // Voor straks Mollie product ids
            $table->string('provider', 32)->nullable(); // mollie
            $table->string('provider_product_id', 128)->nullable();

            $table->json('meta')->nullable();

            $table->timestamps();

            $table->index(['is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_packs');
    }
};
