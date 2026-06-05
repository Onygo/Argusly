<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('analytics_events', function (Blueprint $table) {
            $table->id();
            $table->uuid('analytics_site_id')->index();
            $table->string('event_type', 32);
            $table->string('visitor_hash', 64)->index();
            $table->string('session_hash', 64)->index();
            $table->string('path', 2000);
            $table->string('path_hash', 64)->index();
            $table->string('title', 500)->nullable();
            $table->string('referrer', 2000)->nullable();
            $table->string('host', 255);
            $table->uuid('article_id')->nullable()->index();
            $table->string('content_type', 64)->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('event_time');
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('analytics_site_id')
                ->references('id')
                ->on('analytics_sites')
                ->onDelete('cascade');

            // Composite index for rollup queries
            $table->index(['analytics_site_id', 'event_time', 'event_type']);
            $table->index(['analytics_site_id', 'path_hash', 'event_time']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('analytics_events');
    }
};
