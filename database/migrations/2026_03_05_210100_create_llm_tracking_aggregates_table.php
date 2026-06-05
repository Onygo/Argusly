<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('llm_tracking_aggregates', function (Blueprint $table): void {
            $table->id();
            $table->uuid('site_id');
            $table->foreignId('query_id')->constrained('llm_tracking_queries')->cascadeOnDelete();
            $table->string('period', 16);
            $table->date('period_start');
            $table->string('model', 120)->nullable();
            $table->string('locale', 16)->default('en');
            $table->json('metrics')->nullable();
            $table->timestamps();

            $table->index(['site_id', 'period', 'period_start'], 'llm_track_aggs_site_period_idx');
            $table->unique([
                'query_id',
                'period',
                'period_start',
                'model',
                'locale',
            ], 'llm_track_aggs_unique_idx');

            $table->foreign('site_id')->references('id')->on('client_sites')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('llm_tracking_aggregates');
    }
};
