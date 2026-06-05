<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            try {
                $table->dropForeign(['client_site_id']);
            } catch (\Throwable) {
                // Already dropped or database engine handles constraints differently.
            }

            try {
                $table->dropUnique(['client_site_id']);
            } catch (\Throwable) {
                // Unique index may already be absent.
            }
        });

        Schema::table('subscriptions', function (Blueprint $table) {
            if (Schema::hasColumn('subscriptions', 'client_site_id')) {
                $table->uuid('client_site_id')->nullable()->change();
            }
        });

        Schema::table('subscriptions', function (Blueprint $table) {
            try {
                $table->foreign('client_site_id')
                    ->references('id')
                    ->on('client_sites')
                    ->nullOnDelete();
            } catch (\Throwable) {
                // Constraint may already exist.
            }

            try {
                $table->index('client_site_id', 'subs_client_site_idx');
            } catch (\Throwable) {
                // Index may already exist.
            }
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            try {
                $table->dropForeign(['client_site_id']);
            } catch (\Throwable) {
                // no-op
            }

            try {
                $table->dropIndex('subs_client_site_idx');
            } catch (\Throwable) {
                // no-op
            }
        });

        Schema::table('subscriptions', function (Blueprint $table) {
            if (Schema::hasColumn('subscriptions', 'client_site_id')) {
                $table->uuid('client_site_id')->nullable(false)->change();
            }
        });

        Schema::table('subscriptions', function (Blueprint $table) {
            try {
                $table->unique('client_site_id');
            } catch (\Throwable) {
                // no-op
            }

            try {
                $table->foreign('client_site_id')
                    ->references('id')
                    ->on('client_sites')
                    ->cascadeOnDelete();
            } catch (\Throwable) {
                // no-op
            }
        });
    }
};
