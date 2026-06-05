<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Support\OrganizationSafetyGuard;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CleanupJunkOrganizationsCommand extends Command
{
    protected $signature = 'organizations:cleanup-junk
        {--dry-run : Preview matching organizations without deleting them}';

    protected $description = 'Remove obvious junk test/debug organizations from local development data.';

    public function handle(): int
    {
        $candidates = Organization::query()
            ->with([
                'users:id,organization_id,email,is_admin',
                'workspaces:id,organization_id',
            ])
            ->withCount([
                'brandVoices',
                'clientSites',
                'contentSeries',
                'enrichmentRuns',
                'invoices',
                'invites',
                'organizationProfile',
                'personas',
                'planChanges',
                'subscriptions',
                'teamMembers',
                'websiteScans',
            ])
            ->orderBy('created_at')
            ->get()
            ->filter(fn (Organization $organization): bool => OrganizationSafetyGuard::looksLikeTestArtifact(
                $organization->name,
                $organization->slug
            ));

        if ($candidates->isEmpty()) {
            $this->info('No junk organizations matched the cleanup patterns.');

            return self::SUCCESS;
        }

        $rows = [];
        $safeOrganizations = [];

        foreach ($candidates as $organization) {
            [$isSafeToDelete, $reasons] = $this->safetyReportFor($organization);

            $rows[] = [
                'id' => (string) $organization->id,
                'name' => (string) $organization->name,
                'slug' => (string) $organization->slug,
                'users' => (string) $organization->users->count(),
                'workspaces' => (string) $organization->workspaces->count(),
                'safe' => $isSafeToDelete ? 'yes' : 'no',
                'notes' => $reasons !== [] ? implode('; ', $reasons) : 'matched cleanup rules',
            ];

            if ($isSafeToDelete) {
                $safeOrganizations[] = $organization;
            }
        }

        $this->table(['id', 'name', 'slug', 'users', 'workspaces', 'safe', 'notes'], $rows);

        if ((bool) $this->option('dry-run')) {
            $this->warn(sprintf(
                'Dry run: %d junk organization(s) matched, %d safe candidate(s) would be deleted.',
                $candidates->count(),
                count($safeOrganizations)
            ));

            return self::SUCCESS;
        }

        $deleted = 0;

        foreach ($safeOrganizations as $organization) {
            DB::transaction(function () use ($organization): void {
                $organization->forceFill([
                    'primary_user_id' => null,
                    'active_subscription_id' => null,
                ])->save();

                $organization->invites()->delete();
                $organization->users()->delete();
                $organization->workspaces()->delete();
                $organization->delete();
            });

            $deleted++;
        }

        $this->info(sprintf('Deleted %d junk organization(s).', $deleted));

        $skipped = $candidates->count() - $deleted;
        if ($skipped > 0) {
            $this->warn(sprintf('%d matched organization(s) were skipped because they were not safe to delete automatically.', $skipped));
        }

        return self::SUCCESS;
    }

    /**
     * @return array{0:bool,1:array<int,string>}
     */
    private function safetyReportFor(Organization $organization): array
    {
        $reasons = [];

        foreach ([
            'subscriptions_count' => 'has subscriptions',
            'invoices_count' => 'has invoices',
            'plan_changes_count' => 'has plan changes',
            'client_sites_count' => 'has client sites',
            'brand_voices_count' => 'has brand voices',
            'team_members_count' => 'has team members',
            'personas_count' => 'has personas',
            'enrichment_runs_count' => 'has enrichment runs',
            'content_series_count' => 'has content series',
            'website_scans_count' => 'has website scans',
            'organization_profile_count' => 'has profile data',
        ] as $column => $reason) {
            if ((int) $organization->getAttribute($column) > 0) {
                $reasons[] = $reason;
            }
        }

        if ($organization->users->contains(fn ($user): bool => (bool) $user->is_admin)) {
            $reasons[] = 'has admin users';
        }

        if ($organization->users->contains(function ($user): bool {
            $email = strtolower((string) $user->email);

            return ! str_ends_with($email, '@example.com')
                && ! str_ends_with($email, '@example.test')
                && ! str_ends_with($email, '@test.com')
                && ! str_ends_with($email, '@local.test');
        })) {
            $reasons[] = 'has non-test user emails';
        }

        return [$reasons === [], $reasons];
    }
}
