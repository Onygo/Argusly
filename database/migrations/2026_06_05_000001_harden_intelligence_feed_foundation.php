<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recommendations', function (Blueprint $table): void {
            $table->timestamp('reviewed_at')->nullable()->after('status');
            $table->timestamp('dismissed_at')->nullable()->after('reviewed_at');
            $table->timestamp('archived_at')->nullable()->after('dismissed_at');
        });
    }

    public function down(): void
    {
        Schema::table('recommendations', function (Blueprint $table): void {
            $table->dropColumn(['reviewed_at', 'dismissed_at', 'archived_at']);
        });
    }
};
