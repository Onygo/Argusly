<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('api_keys', function (Blueprint $table): void {
            if (! Schema::hasColumn('api_keys', 'origin_type')) {
                $table->string('origin_type', 64)->nullable()->after('content_destination_id');
            }

            if (! Schema::hasColumn('api_keys', 'origin_id')) {
                $table->string('origin_id', 64)->nullable()->after('origin_type');
            }

            if (! Schema::hasColumn('api_keys', 'origin_label')) {
                $table->string('origin_label', 191)->nullable()->after('origin_id');
            }

            if (! Schema::hasColumn('api_keys', 'is_legacy_import')) {
                $table->boolean('is_legacy_import')->default(false)->after('origin_label');
            }

            if (! Schema::hasColumn('api_keys', 'managed_via')) {
                $table->string('managed_via', 64)->nullable()->after('is_legacy_import');
            }

            if (! Schema::hasColumn('api_keys', 'notes')) {
                $table->text('notes')->nullable()->after('managed_via');
            }
        });

        Schema::table('api_keys', function (Blueprint $table): void {
            $table->index(['workspace_id', 'is_legacy_import'], 'api_keys_workspace_legacy_idx');
            $table->index(['origin_type', 'origin_id'], 'api_keys_origin_idx');
            $table->index(['workspace_id', 'managed_via'], 'api_keys_workspace_managed_via_idx');
        });
    }

    public function down(): void
    {
        Schema::table('api_keys', function (Blueprint $table): void {
            $table->dropIndex('api_keys_workspace_managed_via_idx');
            $table->dropIndex('api_keys_origin_idx');
            $table->dropIndex('api_keys_workspace_legacy_idx');

            if (Schema::hasColumn('api_keys', 'notes')) {
                $table->dropColumn('notes');
            }
            if (Schema::hasColumn('api_keys', 'managed_via')) {
                $table->dropColumn('managed_via');
            }
            if (Schema::hasColumn('api_keys', 'is_legacy_import')) {
                $table->dropColumn('is_legacy_import');
            }
            if (Schema::hasColumn('api_keys', 'origin_label')) {
                $table->dropColumn('origin_label');
            }
            if (Schema::hasColumn('api_keys', 'origin_id')) {
                $table->dropColumn('origin_id');
            }
            if (Schema::hasColumn('api_keys', 'origin_type')) {
                $table->dropColumn('origin_type');
            }
        });
    }
};

