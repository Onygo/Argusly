<?php

namespace App\Console\Commands;

use App\Models\Organization;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class BackfillWorkspaceOrganizations extends Command
{
    protected $signature = 'tenancy:backfill-workspace-organizations
        {--organization-id= : Assign all workspaces without organization to this organization}
        {--dry-run : Only report what would change}';

    protected $description = 'Backfill workspaces.organization_id and report tenancy integrity.';

    public function handle(): int
    {
        $workspaceNullCount = DB::table('workspaces')->whereNull('organization_id')->count();
        $workspaceOrphanOrgCount = DB::table('workspaces')
            ->leftJoin('organizations', 'workspaces.organization_id', '=', 'organizations.id')
            ->whereNotNull('workspaces.organization_id')
            ->whereNull('organizations.id')
            ->count();
        $clientSiteOrphanWorkspaceCount = DB::table('client_sites')
            ->leftJoin('workspaces', 'client_sites.workspace_id', '=', 'workspaces.id')
            ->whereNull('workspaces.id')
            ->count();

        $this->line('Integrity snapshot:');
        $this->line("- workspaces.organization_id IS NULL: {$workspaceNullCount}");
        $this->line("- workspaces with missing organization: {$workspaceOrphanOrgCount}");
        $this->line("- client_sites with missing workspace: {$clientSiteOrphanWorkspaceCount}");

        if ($workspaceNullCount === 0) {
            $this->info('No workspace backfill needed.');
            return self::SUCCESS;
        }

        $orgIdOption = $this->option('organization-id');
        $targetOrgId = null;

        if (is_string($orgIdOption) && $orgIdOption !== '') {
            $exists = Organization::query()->whereKey($orgIdOption)->exists();
            if (! $exists) {
                $this->error("Organization {$orgIdOption} not found.");
                return self::FAILURE;
            }
            $targetOrgId = (int) $orgIdOption;
        } else {
            $orgCount = Organization::query()->count();
            if ($orgCount === 1) {
                $targetOrgId = (int) Organization::query()->value('id');
            }
        }

        if (! $targetOrgId) {
            $this->error(
                'Cannot infer a target organization automatically. ' .
                'Use --organization-id=<id> to backfill safely.'
            );
            return self::FAILURE;
        }

        if ($this->option('dry-run')) {
            $this->warn("Dry-run: would assign {$workspaceNullCount} workspace(s) to organization {$targetOrgId}.");
            return self::SUCCESS;
        }

        DB::transaction(function () use ($targetOrgId) {
            DB::table('workspaces')
                ->whereNull('organization_id')
                ->update([
                    'organization_id' => $targetOrgId,
                    'updated_at' => now(),
                ]);
        });

        $remaining = DB::table('workspaces')->whereNull('organization_id')->count();
        if ($remaining > 0) {
            $this->error("Backfill incomplete, {$remaining} workspace(s) still missing organization_id.");
            return self::FAILURE;
        }

        $this->info('Workspace organization backfill completed.');
        return self::SUCCESS;
    }
}
