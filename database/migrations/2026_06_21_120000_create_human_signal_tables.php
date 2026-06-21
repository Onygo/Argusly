<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('human_signals', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->uuid('workspace_id')->index();
            $table->uuid('site_id')->nullable()->index();
            $table->string('type', 80)->index();
            $table->string('title', 220);
            $table->text('observation');
            $table->text('impact')->nullable();
            $table->decimal('confidence_score', 8, 2)->default(50);
            $table->string('status', 40)->default('detected')->index();
            $table->timestamp('detected_at')->index();
            $table->timestamp('expires_at')->nullable()->index();
            $table->json('metadata_json')->nullable();
            $table->char('dedupe_hash', 64)->index();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->foreign('site_id')->references('id')->on('client_sites')->nullOnDelete();
            $table->unique(['workspace_id', 'dedupe_hash'], 'human_signals_workspace_dedupe_unique');
            $table->index(['workspace_id', 'status', 'confidence_score'], 'human_signals_workspace_status_confidence_idx');
            $table->index(['workspace_id', 'type', 'detected_at'], 'human_signals_workspace_type_detected_idx');
        });

        Schema::create('human_signal_evidence', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('human_signal_id')->index();
            $table->string('source_type', 100)->index();
            $table->string('source_id', 191)->nullable()->index();
            $table->string('title', 220)->nullable();
            $table->text('summary')->nullable();
            $table->decimal('weight', 8, 2)->default(1);
            $table->json('metrics_json')->nullable();
            $table->json('metadata_json')->nullable();
            $table->timestamps();

            $table->foreign('human_signal_id')->references('id')->on('human_signals')->cascadeOnDelete();
            $table->index(['human_signal_id', 'source_type'], 'human_signal_evidence_signal_source_idx');
        });

        Schema::create('human_signal_insights', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('human_signal_id')->index();
            $table->string('title', 220);
            $table->text('insight');
            $table->text('recommended_action')->nullable();
            $table->decimal('quality_score', 8, 2)->default(0);
            $table->json('metadata_json')->nullable();
            $table->timestamps();

            $table->foreign('human_signal_id')->references('id')->on('human_signals')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('human_signal_insights');
        Schema::dropIfExists('human_signal_evidence');
        Schema::dropIfExists('human_signals');
    }
};
