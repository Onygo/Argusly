<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contents', function (Blueprint $table): void {
            if (! Schema::hasColumn('contents', 'sync_with_source')) {
                $table->boolean('sync_with_source')->default(true)->after('is_source_locale');
            }

            if (! Schema::hasColumn('contents', 'auto_publish')) {
                $table->boolean('auto_publish')->default(true)->after('sync_with_source');
            }
        });
    }

    public function down(): void
    {
        Schema::table('contents', function (Blueprint $table): void {
            foreach (['auto_publish', 'sync_with_source'] as $column) {
                if (Schema::hasColumn('contents', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
