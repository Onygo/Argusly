<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('early_access_pilot_costs')) {
            return;
        }

        Schema::create('early_access_pilot_costs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('early_access_signup_id')->constrained('early_access_signups')->cascadeOnDelete();
            $table->string('category', 40)->default('other');
            $table->string('description', 255);
            $table->unsignedInteger('amount_cents')->default(0);
            $table->string('currency', 3)->default('EUR');
            $table->date('incurred_on')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['early_access_signup_id', 'incurred_on'], 'ea_pilot_costs_signup_date_idx');
            $table->index(['category'], 'ea_pilot_costs_category_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('early_access_pilot_costs');
    }
};
