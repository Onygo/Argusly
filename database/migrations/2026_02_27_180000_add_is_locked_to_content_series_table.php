<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('content_series', function (Blueprint $table): void {
            if (! Schema::hasColumn('content_series', 'is_locked')) {
                $table->boolean('is_locked')->default(false)->after('status');
                $table->index(['status', 'is_locked'], 'content_series_status_locked_idx');
            }
        });

        DB::table('content_series')
            ->where('status', 'published')
            ->update(['is_locked' => true]);
    }

    public function down(): void
    {
        Schema::table('content_series', function (Blueprint $table): void {
            if (Schema::hasColumn('content_series', 'is_locked')) {
                $table->dropIndex('content_series_status_locked_idx');
                $table->dropColumn('is_locked');
            }
        });
    }
};
