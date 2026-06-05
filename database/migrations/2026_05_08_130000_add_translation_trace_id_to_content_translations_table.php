<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('content_translations', function (Blueprint $table): void {
            $table->uuid('translation_trace_id')->nullable()->after('requested_by_user_id');
            $table->index(['translation_trace_id'], 'content_translations_trace_id_idx');
        });
    }

    public function down(): void
    {
        Schema::table('content_translations', function (Blueprint $table): void {
            $table->dropIndex('content_translations_trace_id_idx');
            $table->dropColumn('translation_trace_id');
        });
    }
};
