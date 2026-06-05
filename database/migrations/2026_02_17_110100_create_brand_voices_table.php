<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('brand_voices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('workspace_id');
            $table->string('name');
            $table->string('default_language', 10)->default('en');
            $table->string('default_tone')->nullable();
            $table->text('style_guide')->nullable();
            $table->text('preferred_terminology')->nullable();
            $table->text('disallowed_terminology')->nullable();
            $table->text('formatting_rules')->nullable();
            $table->boolean('is_default')->default(false);
            $table->string('ai_model_override')->nullable();
            $table->timestamps();

            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->index('workspace_id');
            $table->index(['workspace_id', 'is_default']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('brand_voices');
    }
};
