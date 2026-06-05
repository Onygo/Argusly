<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_sites', function (Blueprint $table): void {
            if (! Schema::hasColumn('client_sites', 'automation_settings')) {
                $table->json('automation_settings')->nullable()->after('connector_meta');
            }
        });
    }

    public function down(): void
    {
        Schema::table('client_sites', function (Blueprint $table): void {
            if (Schema::hasColumn('client_sites', 'automation_settings')) {
                $table->dropColumn('automation_settings');
            }
        });
    }
};
