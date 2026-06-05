<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            $table->foreignId('organization_id')->nullable()->after('name')->constrained('organizations')->nullOnDelete();
        });

        $orgCount = DB::table('organizations')->count();
        if ($orgCount === 1) {
            $orgId = DB::table('organizations')->value('id');
            DB::table('workspaces')
                ->whereNull('organization_id')
                ->update(['organization_id' => $orgId]);
        }
    }

    public function down(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            $table->dropConstrainedForeignId('organization_id');
        });
    }
};
