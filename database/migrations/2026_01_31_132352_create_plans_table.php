<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->string('key')->unique(); // starter, pro, agency
            $table->string('name');

            $table->unsignedInteger('monthly_price_cents')->default(0);
            $table->string('currency', 8)->default('EUR');

            $table->unsignedInteger('included_credits')->default(0);

            // toekomst: seats, projects, sites, retention, etc
            $table->json('limits')->nullable();

            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->index(['is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plans');
    }
};
