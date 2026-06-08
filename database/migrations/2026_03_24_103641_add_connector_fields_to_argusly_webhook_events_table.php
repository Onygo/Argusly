<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('argusly_webhook_events')) {
            return;
        }

        Schema::table('argusly_webhook_events', function (Blueprint $table) {
            if (! Schema::hasColumn('argusly_webhook_events', 'site_key')) {
                $table->string('site_key', 128)->default('default')->after('id');
            }

            if (! Schema::hasColumn('argusly_webhook_events', 'request_headers')) {
                $table->json('request_headers')->nullable()->after('payload');
            }
        });

        Schema::table('argusly_webhook_events', function (Blueprint $table) {
            if (! $this->hasIndex('argusly_webhook_events', 'pl_webhook_events_site_received_idx')) {
                $table->index(['site_key', 'received_at'], 'pl_webhook_events_site_received_idx');
            }

            if (! $this->hasIndex('argusly_webhook_events', 'pl_webhook_events_site_status_idx')) {
                $table->index(['site_key', 'status'], 'pl_webhook_events_site_status_idx');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('argusly_webhook_events')) {
            return;
        }

        Schema::table('argusly_webhook_events', function (Blueprint $table) {
            if ($this->hasIndex('argusly_webhook_events', 'pl_webhook_events_site_received_idx')) {
                $table->dropIndex('pl_webhook_events_site_received_idx');
            }

            if ($this->hasIndex('argusly_webhook_events', 'pl_webhook_events_site_status_idx')) {
                $table->dropIndex('pl_webhook_events_site_status_idx');
            }

            if (Schema::hasColumn('argusly_webhook_events', 'site_key')) {
                $table->dropColumn('site_key');
            }

            if (Schema::hasColumn('argusly_webhook_events', 'request_headers')) {
                $table->dropColumn('request_headers');
            }
        });
    }

    private function hasIndex(string $table, string $indexName): bool
    {
        foreach (Schema::getIndexes($table) as $index) {
            $name = $index['name'] ?? $index['index'] ?? null;

            if (is_string($name) && $name === $indexName) {
                return true;
            }
        }

        return false;
    }
};
