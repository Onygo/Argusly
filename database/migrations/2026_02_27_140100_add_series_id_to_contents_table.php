<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contents', function (Blueprint $table): void {
            if (! Schema::hasColumn('contents', 'series_id')) {
                $table->uuid('series_id')->nullable()->after('client_site_id');
                $table->index('series_id', 'contents_series_id_idx');
                $table->foreign('series_id', 'contents_series_id_fk')
                    ->references('id')
                    ->on('content_series')
                    ->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('contents', function (Blueprint $table): void {
            if (Schema::hasColumn('contents', 'series_id')) {
                $table->dropForeign('contents_series_id_fk');
                $table->dropIndex('contents_series_id_idx');
                $table->dropColumn('series_id');
            }
        });
    }
};
