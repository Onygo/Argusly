<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('llm_requests', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('workspace_id')->nullable();
            $table->uuid('site_id')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('feature', 64);
            $table->string('modality', 16)->default('text');
            $table->string('provider', 32);
            $table->string('model', 120)->nullable();
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->unsignedInteger('total_tokens')->default(0);
            $table->decimal('credits_consumed', 12, 4)->default(0);
            $table->unsignedInteger('latency_ms')->nullable();
            $table->enum('status', ['success', 'error'])->default('success');
            $table->string('error_type', 120)->nullable();
            $table->text('error_message')->nullable();
            $table->string('error_code', 64)->nullable();
            $table->string('request_id', 191)->nullable();
            $table->string('job_id', 191)->nullable();
            $table->unsignedSmallInteger('retry_count')->default(0);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['created_at'], 'llm_requests_created_idx');
            $table->index(['workspace_id', 'site_id'], 'llm_requests_scope_idx');
            $table->index(['feature', 'provider', 'model'], 'llm_requests_feature_provider_model_idx');
            $table->index(['status'], 'llm_requests_status_idx');
            $table->index(['provider', 'created_at'], 'llm_requests_provider_created_idx');

            $table->foreign('workspace_id')->references('id')->on('workspaces')->nullOnDelete();
            $table->foreign('site_id')->references('id')->on('client_sites')->nullOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('llm_requests');
    }
};
