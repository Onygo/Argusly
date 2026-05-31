<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('integration_connections', 'refresh_expires_at')) {
            return;
        }

        Schema::table('integration_connections', function (Blueprint $table): void {
            $table->timestamp('refresh_expires_at')->nullable()->after('token_expires_at');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('integration_connections', 'refresh_expires_at')) {
            return;
        }

        Schema::table('integration_connections', function (Blueprint $table): void {
            $table->dropColumn('refresh_expires_at');
        });
    }
};
