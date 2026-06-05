<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('llm_tracking_query_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('llm_tracking_query_id')->constrained('llm_tracking_queries')->cascadeOnDelete();
            $table->timestamp('run_at');
            $table->string('model', 120)->nullable();
            $table->enum('status', ['running', 'succeeded', 'failed'])->default('running');
            $table->longText('raw_response')->nullable();
            $table->json('parsed_payload')->nullable();
            $table->boolean('brand_mentioned')->default(false);
            $table->boolean('urls_cited')->default(false);
            $table->boolean('competitors_mentioned')->default(false);
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index(['llm_tracking_query_id', 'run_at'], 'llm_track_runs_query_runat_idx');
            $table->index(['status', 'run_at'], 'llm_track_runs_status_runat_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('llm_tracking_query_runs');
    }
};
