<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('content_translations', function (Blueprint $table): void {
            if (! Schema::hasColumn('content_translations', 'processing_last_recovered_at')) {
                $table->timestamp('processing_last_recovered_at')->nullable()->after('processing_failed_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('content_translations', function (Blueprint $table): void {
            if (Schema::hasColumn('content_translations', 'processing_last_recovered_at')) {
                $table->dropColumn('processing_last_recovered_at');
            }
        });
    }
};
