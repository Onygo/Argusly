<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $orphanClientSites = DB::table('client_sites')
            ->leftJoin('workspaces', 'client_sites.workspace_id', '=', 'workspaces.id')
            ->whereNull('workspaces.id')
            ->count();

        if ($orphanClientSites > 0) {
            throw new \RuntimeException('Found orphan client_sites records. Resolve them before migrating.');
        }

        $nullWorkspaceOrgCount = DB::table('workspaces')
            ->whereNull('organization_id')
            ->count();

        if ($nullWorkspaceOrgCount > 0) {
            $orgCount = DB::table('organizations')->count();

            if ($orgCount === 0) {
                $orgId = DB::table('organizations')->insertGetId([
                    'name' => 'Legacy Organization',
                    'slug' => 'legacy-organization',
                    'status' => 'active',
                    'approved_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                DB::table('workspaces')
                    ->whereNull('organization_id')
                    ->update([
                        'organization_id' => $orgId,
                        'updated_at' => now(),
                    ]);
            } elseif ($orgCount === 1) {
                $orgId = DB::table('organizations')->value('id');

                DB::table('workspaces')
                    ->whereNull('organization_id')
                    ->update([
                        'organization_id' => $orgId,
                        'updated_at' => now(),
                    ]);
            } else {
                throw new \RuntimeException(
                    'Found workspaces without organization_id while multiple organizations exist. ' .
                    'Run php artisan tenancy:backfill-workspace-organizations first.'
                );
            }
        }

        Schema::table('workspaces', function (Blueprint $table) {
            $table->dropForeign(['organization_id']);
        });

        Schema::table('workspaces', function (Blueprint $table) {
            $table->unsignedBigInteger('organization_id')->nullable(false)->change();
        });

        Schema::table('workspaces', function (Blueprint $table) {
            $table->foreign('organization_id')
                ->references('id')
                ->on('organizations')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            $table->dropForeign(['organization_id']);
        });

        Schema::table('workspaces', function (Blueprint $table) {
            $table->unsignedBigInteger('organization_id')->nullable()->change();
        });

        Schema::table('workspaces', function (Blueprint $table) {
            $table->foreign('organization_id')
                ->references('id')
                ->on('organizations')
                ->nullOnDelete();
        });
    }
};
