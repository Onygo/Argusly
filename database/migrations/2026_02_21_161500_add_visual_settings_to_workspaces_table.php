<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workspaces', function (Blueprint $table): void {
            if (! Schema::hasColumn('workspaces', 'visual_settings')) {
                $table->json('visual_settings')->nullable()->after('organization_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('workspaces', function (Blueprint $table): void {
            if (Schema::hasColumn('workspaces', 'visual_settings')) {
                $table->dropColumn('visual_settings');
            }
        });
    }
};
