<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('analytics_rollups_daily', function (Blueprint $table) {
            $table->id();
            $table->uuid('analytics_site_id')->index();
            $table->date('date')->index();
            $table->string('path', 2000);
            $table->string('path_hash', 64)->index();
            $table->uuid('article_id')->nullable()->index();
            $table->string('title', 500)->nullable();

            // Aggregated metrics
            $table->unsignedInteger('page_views')->default(0);
            $table->unsignedInteger('unique_visitors')->default(0);
            $table->unsignedInteger('scroll_50')->default(0);
            $table->unsignedInteger('scroll_100')->default(0);
            $table->unsignedInteger('heartbeats')->default(0);
            $table->unsignedInteger('engaged_views')->default(0);
            $table->unsignedInteger('total_time_seconds')->default(0);

            $table->timestamps();

            $table->foreign('analytics_site_id')
                ->references('id')
                ->on('analytics_sites')
                ->onDelete('cascade');

            // Unique constraint for upsert
            $table->unique(['analytics_site_id', 'date', 'path_hash'], 'analytics_rollups_daily_unique');

            // Index for trending queries
            $table->index(['analytics_site_id', 'date', 'page_views']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('analytics_rollups_daily');
    }
};
