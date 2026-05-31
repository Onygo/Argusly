<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('search_console_query_snapshots', function (Blueprint $table): void {
            $table->index(
                ['search_console_site_id', 'date', 'query', 'country', 'device'],
                'search_console_snapshot_dimension_index'
            );
        });
    }

    public function down(): void
    {
        Schema::table('search_console_query_snapshots', function (Blueprint $table): void {
            $table->dropIndex('search_console_snapshot_dimension_index');
        });
    }
};
