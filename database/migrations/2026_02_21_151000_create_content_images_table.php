<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_images', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('content_id');
            $table->string('type', 32)->default('featured');
            $table->text('prompt')->nullable();
            $table->string('provider', 64)->nullable();
            $table->string('image_path')->nullable();
            $table->string('image_url', 2048)->nullable();
            $table->unsignedInteger('credit_cost')->default(0);
            $table->string('status', 32)->default('queued');
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index(['content_id']);
            $table->index(['content_id', 'type']);
            $table->foreign('content_id')->references('id')->on('contents')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_images');
    }
};
