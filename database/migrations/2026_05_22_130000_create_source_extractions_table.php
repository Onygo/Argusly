<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('source_extractions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable();
            $table->string('url_hash', 64);
            $table->text('url');
            $table->text('final_url')->nullable();
            $table->string('title')->nullable();
            $table->string('author')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->string('language', 16)->nullable();
            $table->text('summary')->nullable();
            $table->longText('extracted_text')->nullable();
            $table->unsignedInteger('word_count')->default(0);
            $table->unsignedInteger('chars')->default(0);
            $table->unsignedInteger('estimated_tokens')->default(0);
            $table->string('method', 80)->nullable();
            $table->string('status', 32)->default('pending');
            $table->string('error_code', 80)->nullable();
            $table->text('error_message')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('fetched_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'url_hash']);
            $table->index(['url_hash']);
            $table->index(['status', 'expires_at']);
            $table->unique(['tenant_id', 'url_hash'], 'source_extractions_tenant_url_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('source_extractions');
    }
};
