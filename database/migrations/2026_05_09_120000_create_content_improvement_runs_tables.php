<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_improvement_runs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('content_id')->index();
            $table->unsignedBigInteger('organization_id')->nullable()->index();
            $table->string('type', 120);
            $table->string('recommendation_label')->nullable();
            $table->string('status', 32)->index();
            $table->unsignedTinyInteger('progress_percentage')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->text('error_message')->nullable();
            $table->unsignedBigInteger('created_by')->nullable()->index();
            $table->uuid('draft_id')->nullable()->index();
            $table->json('result_payload')->nullable();
            $table->json('diagnostics')->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->unsignedBigInteger('applied_by')->nullable();
            $table->timestamps();

            $table->foreign('content_id')->references('id')->on('contents')->cascadeOnDelete();
        });

        Schema::create('content_improvement_events', function (Blueprint $table): void {
            $table->id();
            $table->uuid('content_improvement_run_id')->index();
            $table->string('event_type', 64)->index();
            $table->string('message');
            $table->json('payload')->nullable();
            $table->timestamps();

            $table->foreign('content_improvement_run_id', 'content_improvement_events_run_fk')
                ->references('id')
                ->on('content_improvement_runs')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_improvement_events');
        Schema::dropIfExists('content_improvement_runs');
    }
};
