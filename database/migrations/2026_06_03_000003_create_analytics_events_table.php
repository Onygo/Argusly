<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('analytics_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('analytics_site_id')->constrained()->cascadeOnDelete();
            $table->string('event_type', 32);
            $table->string('visitor_hash', 64)->index();
            $table->string('session_hash', 64)->index();
            $table->string('url', 2000)->nullable();
            $table->string('canonical_url', 2000)->nullable();
            $table->string('url_key', 512)->nullable();
            $table->string('canonical_url_hash', 64)->nullable();
            $table->string('path', 2000);
            $table->string('path_hash', 64)->index();
            $table->string('title', 500)->nullable();
            $table->string('referrer', 2000)->nullable();
            $table->string('host', 255);
            $table->string('article_id', 128)->nullable()->index();
            $table->string('content_type', 64)->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('event_time');
            $table->timestamp('received_at')->nullable();
            $table->string('event_hash', 64)->nullable()->unique();
            $table->string('ip_hash', 64)->nullable()->index();
            $table->string('user_agent_family', 64)->nullable();
            $table->string('device_type', 32)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['analytics_site_id', 'event_time', 'event_type']);
            $table->index(['analytics_site_id', 'path_hash', 'event_time']);
            $table->index(['analytics_site_id', 'received_at']);
            $table->index(['canonical_url_hash', 'received_at']);
            $table->index(['analytics_site_id', 'url_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('analytics_events');
    }
};
