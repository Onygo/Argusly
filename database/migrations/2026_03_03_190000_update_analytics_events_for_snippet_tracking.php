<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('analytics_events', function (Blueprint $table): void {
            $table->string('url', 2000)->nullable();
            $table->string('canonical_url', 2000)->nullable();
            $table->string('canonical_url_hash', 64)->nullable();
            $table->timestamp('received_at')->nullable();
            $table->string('event_hash', 64)->nullable();
            $table->string('ip_hash', 64)->nullable();
            $table->string('user_agent_family', 64)->nullable();
            $table->string('device_type', 32)->nullable();

            $table->unique('event_hash');
            $table->index(['analytics_site_id', 'received_at']);
            $table->index(['canonical_url_hash', 'received_at']);
            $table->index('ip_hash');
        });
    }

    public function down(): void
    {
        Schema::table('analytics_events', function (Blueprint $table): void {
            $table->dropUnique('analytics_events_event_hash_unique');
            $table->dropIndex('analytics_events_analytics_site_id_received_at_index');
            $table->dropIndex('analytics_events_canonical_url_hash_received_at_index');
            $table->dropIndex('analytics_events_ip_hash_index');

            $table->dropColumn([
                'url',
                'canonical_url',
                'canonical_url_hash',
                'received_at',
                'event_hash',
                'ip_hash',
                'user_agent_family',
                'device_type',
            ]);
        });
    }
};
