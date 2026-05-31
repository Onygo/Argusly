<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ga4_metric_snapshots', function (Blueprint $table): void {
            $table->string('page_path')->nullable()->after('content_asset_id');
            $table->index(['ga4_property_id', 'date', 'page_path'], 'ga4_snapshot_property_date_page_index');
        });
    }

    public function down(): void
    {
        Schema::table('ga4_metric_snapshots', function (Blueprint $table): void {
            $table->dropIndex('ga4_snapshot_property_date_page_index');
            $table->dropColumn('page_path');
        });
    }
};
