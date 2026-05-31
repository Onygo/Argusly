<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('visibility_run_schedules', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('brand_id')->constrained()->cascadeOnDelete();
            $table->foreignId('prompt_template_id')->constrained('visibility_prompt_templates')->cascadeOnDelete();
            $table->string('provider')->index();
            $table->string('frequency')->index();
            $table->string('status')->default('active')->index();
            $table->timestamp('last_run_at')->nullable();
            $table->timestamp('next_run_at')->nullable()->index();
            $table->json('settings')->nullable();
            $table->timestamps();

            $table->index(['account_id', 'brand_id', 'status']);
            $table->index(['status', 'next_run_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('visibility_run_schedules');
    }
};
