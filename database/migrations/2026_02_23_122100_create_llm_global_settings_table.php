<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('llm_global_settings', function (Blueprint $table): void {
            $table->unsignedTinyInteger('id')->primary();
            $table->string('default_text_provider', 32)->default('openai');
            $table->string('default_image_provider', 32)->default('openai');
            $table->json('default_text_model_map')->nullable();
            $table->json('default_image_model_map')->nullable();
            $table->unsignedInteger('timeout_seconds')->default(180);
            $table->unsignedSmallInteger('retry_max')->default(2);
            $table->unsignedInteger('retry_backoff_ms')->default(800);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('llm_global_settings');
    }
};
