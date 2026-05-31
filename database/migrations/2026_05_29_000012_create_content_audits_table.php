<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('content_audits', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('brand_id')->constrained()->cascadeOnDelete();
            $table->foreignId('content_asset_id')->constrained()->cascadeOnDelete();
            $table->string('status')->default('queued')->index();
            $table->unsignedTinyInteger('score')->nullable();
            $table->unsignedTinyInteger('seo_score')->nullable();
            $table->unsignedTinyInteger('ai_visibility_score')->nullable();
            $table->unsignedTinyInteger('readability_score')->nullable();
            $table->unsignedTinyInteger('entity_score')->nullable();
            $table->unsignedTinyInteger('answer_score')->nullable();
            $table->json('issues')->nullable();
            $table->json('recommendations')->nullable();
            $table->text('summary')->nullable();
            $table->timestamp('audited_at')->nullable();
            $table->timestamps();

            $table->index(['account_id', 'brand_id', 'status', 'created_at']);
            $table->index(['content_asset_id', 'status', 'audited_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('content_audits');
    }
};
