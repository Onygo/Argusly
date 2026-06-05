<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contents', function (Blueprint $table): void {
            if (! Schema::hasColumn('contents', 'source_content_updated_at_snapshot')) {
                $table->timestamp('source_content_updated_at_snapshot')
                    ->nullable()
                    ->after('translation_source_updated_at');
            }

            if (! Schema::hasColumn('contents', 'locale_repair_meta')) {
                $table->json('locale_repair_meta')
                    ->nullable()
                    ->after('source_content_updated_at_snapshot');
            }
        });
    }

    public function down(): void
    {
        Schema::table('contents', function (Blueprint $table): void {
            foreach (['locale_repair_meta', 'source_content_updated_at_snapshot'] as $column) {
                if (Schema::hasColumn('contents', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
