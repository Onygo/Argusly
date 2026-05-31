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
        Schema::create('answer_blocks', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('brand_id')->constrained()->cascadeOnDelete();
            $table->foreignId('content_asset_id')->nullable()->constrained()->nullOnDelete();
            $table->text('question');
            $table->longText('answer');
            $table->string('type')->index();
            $table->string('status')->default('draft')->index();
            $table->string('language', 16)->default('en')->index();
            $table->unsignedInteger('position')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['account_id', 'brand_id', 'status']);
            $table->index(['account_id', 'brand_id', 'type']);
            $table->index(['content_asset_id', 'position']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('answer_blocks');
    }
};
