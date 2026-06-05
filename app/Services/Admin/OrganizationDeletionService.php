<?php

namespace App\Services\Admin;

use App\Models\Organization;
use Illuminate\Support\Facades\DB;

class OrganizationDeletionService
{
    /**
     * Check if an organization can be safely deleted.
     *
     * @return array{can_delete: bool, reasons: array<string>}
     */
    public function canDelete(Organization $organization): array
    {
        $reasons = [];

        // Check for active subscription
        if ($organization->active_subscription_id !== null) {
            $reasons[] = 'Organization has an active subscription.';
        }

        // Check for users (other than the primary user in a test org scenario)
        $userCount = $organization->users()->count();
        if ($userCount > 1) {
            $reasons[] = "Organization has {$userCount} users.";
        }

        // Check for workspaces
        $workspaceCount = $organization->workspaces()->count();
        if ($workspaceCount > 0) {
            $reasons[] = "Organization has {$workspaceCount} workspace(s).";
        }

        // Check for invoices
        $invoiceCount = $organization->invoices()->count();
        if ($invoiceCount > 0) {
            $reasons[] = "Organization has {$invoiceCount} invoice(s).";
        }

        // Check for subscriptions (historical)
        $subscriptionCount = $organization->subscriptions()->count();
        if ($subscriptionCount > 0) {
            $reasons[] = "Organization has {$subscriptionCount} subscription record(s).";
        }

        // Check for brand voices
        $brandVoiceCount = $organization->brandVoices()->count();
        if ($brandVoiceCount > 0) {
            $reasons[] = "Organization has {$brandVoiceCount} brand voice(s).";
        }

        // Check for team members
        $teamMemberCount = $organization->teamMembers()->count();
        if ($teamMemberCount > 0) {
            $reasons[] = "Organization has {$teamMemberCount} team member(s).";
        }

        // Check for personas
        $personaCount = $organization->personas()->count();
        if ($personaCount > 0) {
            $reasons[] = "Organization has {$personaCount} persona(s).";
        }

        // Check for content series
        $contentSeriesCount = $organization->contentSeries()->count();
        if ($contentSeriesCount > 0) {
            $reasons[] = "Organization has {$contentSeriesCount} content series.";
        }

        // Check for enrichment runs
        $enrichmentRunCount = $organization->enrichmentRuns()->count();
        if ($enrichmentRunCount > 0) {
            $reasons[] = "Organization has {$enrichmentRunCount} enrichment run(s).";
        }

        return [
            'can_delete' => count($reasons) === 0,
            'reasons' => $reasons,
        ];
    }

    /**
     * Get a summary of all related data for an organization.
     *
     * @return array<string, int>
     */
    public function getRelatedDataSummary(Organization $organization): array
    {
        return [
            'users' => $organization->users()->count(),
            'workspaces' => $organization->workspaces()->count(),
            'client_sites' => $organization->clientSites()->count(),
            'subscriptions' => $organization->subscriptions()->count(),
            'invoices' => $organization->invoices()->count(),
            'brand_voices' => $organization->brandVoices()->count(),
            'team_members' => $organization->teamMembers()->count(),
            'personas' => $organization->personas()->count(),
            'content_series' => $organization->contentSeries()->count(),
            'enrichment_runs' => $organization->enrichmentRuns()->count(),
            'website_scans' => $organization->websiteScans()->count(),
            'invites' => $organization->invites()->count(),
        ];
    }

    /**
     * Permanently delete an organization and all related data.
     *
     * This method will forcefully delete everything, including:
     * - All users belonging to the organization
     * - All workspaces and their sites
     * - All subscriptions and invoices
     * - All brand-related data
     *
     * Use with extreme caution!
     */
    public function forceDelete(Organization $organization): void
    {
        DB::transaction(function () use ($organization): void {
            // Delete invites
            $organization->invites()->delete();

            // Delete website scans
            $organization->websiteScans()->delete();

            // Delete enrichment runs
            $organization->enrichmentRuns()->delete();

            // Delete content series
            $organization->contentSeries()->delete();

            // Delete personas
            $organization->personas()->delete();

            // Delete team members
            $organization->teamMembers()->delete();

            // Delete brand voices
            $organization->brandVoices()->delete();

            // Delete organization profile
            $organization->organizationProfile()?->delete();

            // Delete taxonomy set associations
            $organization->taxonomySets()->detach();

            // Delete plan changes
            $organization->planChanges()->delete();

            // Delete invoices
            $organization->invoices()->delete();

            // Delete subscriptions
            $organization->subscriptions()->delete();

            // Delete client sites through workspaces
            foreach ($organization->workspaces as $workspace) {
                $workspace->clientSites()->delete();
            }

            // Delete workspaces
            $organization->workspaces()->delete();

            // Clear primary user reference before deleting users
            $organization->primary_user_id = null;
            $organization->active_subscription_id = null;
            $organization->save();

            // Delete users
            $organization->users()->delete();

            // Finally, delete the organization itself
            $organization->delete();
        });
    }
}
