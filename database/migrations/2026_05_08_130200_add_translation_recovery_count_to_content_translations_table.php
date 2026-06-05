<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('content_translations', function (Blueprint $table): void {
            $table->unsignedInteger('processing_recovery_count')->default(0)->after('processing_error_message');
        });
    }

    public function down(): void
    {
        Schema::table('content_translations', function (Blueprint $table): void {
            $table->dropColumn('processing_recovery_count');
        });
    }
};
