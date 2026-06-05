<?php

namespace App\Http\Controllers\Api\V1\Headless;

use App\Http\Controllers\Api\V1\Concerns\RespondsWithApi;
use App\Http\Controllers\Controller;
use App\Models\AsyncOperationRun;
use App\Models\CreditWalletTransaction;
use App\Models\WorkspaceUsage;
use App\Services\OrganizationAccessService;
use App\Services\SubscriptionService;
use Illuminate\Http\Request;

class IdentityController extends Controller
{
    use RespondsWithApi;

    public function me(
        Request $request,
        SubscriptionService $subscriptions,
        OrganizationAccessService $access
    )
    {
        $workspace = $request->attributes->get('workspace');
        $apiKey = $request->attributes->get('apiKey');

        if (! $workspace) {
            return $this->error('Workspace not resolved', code: 'WORKSPACE_NOT_RESOLVED', status: 401);
        }

        $subscription = $workspace->organization
            ? $subscriptions->getActiveForOrganization($workspace->organization)
            : null;
        $earlyBirdActive = $workspace->organization ? $access->isEarlyBirdActive($workspace->organization) : false;

        $periodYm = now()->format('Ym');
        $legacyYearMonth = now()->format('Y-m');
        $usageRow = WorkspaceUsage::query()
            ->where('workspace_id', $workspace->id)
            ->where(function ($query) use ($periodYm, $legacyYearMonth): void {
                $query->where('period_ym', $periodYm)
                    ->orWhere('year_month', $legacyYearMonth);
            })
            ->whereNull('site_id')
            ->first();

        return $this->success([
            'workspace' => [
                'id' => (string) $workspace->id,
                'name' => (string) $workspace->display_name,
            ],
            'plan' => [
                'id' => (string) ($subscription?->plan_id ?? ''),
                'status' => $earlyBirdActive ? 'early_bird' : (string) ($subscription?->status ?? 'none'),
            ],
            'api_key' => [
                'id' => $apiKey?->id,
                'name' => $apiKey?->name,
                'scopes' => is_array($apiKey?->scopes) ? array_values($apiKey->scopes) : [],
                'expires_at' => $apiKey?->expires_at?->toIso8601String(),
            ],
            'usage_summary' => [
                'year_month' => $legacyYearMonth,
                'briefs_count' => (int) ($usageRow?->briefs_count ?? 0),
                'drafts_count' => (int) ($usageRow?->drafts_count ?? 0),
                'articles_generated' => (int) ($usageRow?->articles_generated ?? 0),
                'llm_queries_run' => (int) ($usageRow?->llm_queries_run ?? 0),
                'audit_pages_crawled' => (int) ($usageRow?->audit_pages_crawled ?? 0),
            ],
        ]);
    }

    public function usage(Request $request)
    {
        $workspace = $request->attributes->get('workspace');
        $apiKey = $request->attributes->get('apiKey');

        if (! $workspace) {
            return $this->error('Workspace not resolved', code: 'WORKSPACE_NOT_RESOLVED', status: 401);
        }

        $start = now()->startOfMonth();
        $end = now()->endOfMonth();

        $creditsUsed = (int) CreditWalletTransaction::query()
            ->where('workspace_id', $workspace->id)
            ->where('type', 'usage')
            ->whereBetween('created_at', [$start, $end])
            ->sum('amount');

        $creditsReserved = (int) CreditWalletTransaction::query()
            ->where('workspace_id', $workspace->id)
            ->where('type', 'reservation')
            ->whereBetween('created_at', [$start, $end])
            ->sum('amount');

        $queuedOperations = (int) AsyncOperationRun::query()
            ->where('workspace_id', $workspace->id)
            ->whereIn('status', ['queued', 'processing'])
            ->count();

        return $this->success([
            'period' => [
                'start' => $start->toDateString(),
                'end' => $end->toDateString(),
            ],
            'credits' => [
                'used' => abs($creditsUsed),
                'reserved' => max(0, $creditsReserved),
            ],
            'queued_jobs' => $queuedOperations,
            'rate_limit' => [
                'key_id' => $apiKey?->id,
                'throttle' => 'integration-api',
            ],
        ]);
    }
}
