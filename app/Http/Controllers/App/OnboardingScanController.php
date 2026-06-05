<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Jobs\Onboarding\GenerateInitialContentJob;
use App\Jobs\Onboarding\ScanWebsiteJob;
use App\Models\WebsiteScan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class OnboardingScanController extends Controller
{
    /**
     * Start a new website scan.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'url' => ['required', 'url', 'max:2048'],
            'workspace_id' => ['nullable', 'uuid', 'exists:workspaces,id'],
        ]);

        $user = $request->user();
        $organization = $user->organization;

        if (! $organization) {
            return response()->json([
                'error' => 'User must belong to an organization.',
            ], 422);
        }

        // Check for existing in-progress scan
        $existingScan = WebsiteScan::query()
            ->where('organization_id', $organization->id)
            ->whereIn('status', [
                WebsiteScan::STATUS_QUEUED,
                WebsiteScan::STATUS_CRAWLING,
                WebsiteScan::STATUS_EXTRACTING,
                WebsiteScan::STATUS_ANALYZING,
            ])
            ->first();

        if ($existingScan) {
            return response()->json([
                'error' => 'A scan is already in progress.',
                'scan_id' => $existingScan->id,
                'status' => $existingScan->status,
                'progress' => $existingScan->progress,
            ], 409);
        }

        $scan = WebsiteScan::create([
            'id' => (string) Str::uuid(),
            'organization_id' => $organization->id,
            'workspace_id' => $data['workspace_id'] ?? null,
            'user_id' => $user->id,
            'url' => $data['url'],
            'status' => WebsiteScan::STATUS_QUEUED,
            'progress' => 0,
        ]);

        ScanWebsiteJob::dispatch($scan->id)->onQueue('default');

        return response()->json([
            'scan_id' => $scan->id,
            'status' => $scan->status,
            'message' => 'Website scan started. Poll the status endpoint to track progress.',
        ], 201);
    }

    /**
     * Get scan status and results.
     */
    public function show(Request $request, string $scanId): JsonResponse
    {
        $user = $request->user();

        $scan = WebsiteScan::query()
            ->where('id', $scanId)
            ->where('organization_id', $user->organization_id)
            ->first();

        if (! $scan) {
            return response()->json([
                'error' => 'Scan not found.',
            ], 404);
        }

        $response = [
            'id' => $scan->id,
            'url' => $scan->url,
            'status' => $scan->status,
            'progress' => $scan->progress,
            'user_confirmed' => $scan->user_confirmed,
            'started_at' => $scan->started_at?->toIso8601String(),
            'completed_at' => $scan->completed_at?->toIso8601String(),
        ];

        // Only include profiles when scan is completed
        if ($scan->isCompleted()) {
            $response['brand_profile'] = $scan->brand_profile;
            $response['seo_profile'] = $scan->seo_profile;
            $response['design_profile'] = $scan->design_profile;
            $response['technical_profile'] = $scan->technical_profile;
            $response['suggested_briefs'] = $scan->suggested_briefs;
        }

        // Include error info if failed
        if ($scan->isFailed()) {
            $response['error_code'] = $scan->error_code;
            $response['error_message'] = $scan->error_message;
            $response['failed_at'] = $scan->failed_at?->toIso8601String();
        }

        return response()->json($response);
    }

    /**
     * Confirm scan results and optionally generate initial content.
     */
    public function confirm(Request $request, string $scanId): JsonResponse
    {
        $data = $request->validate([
            'client_site_id' => ['nullable', 'uuid', 'exists:client_sites,id'],
            'apply_to_organization' => ['boolean'],
            'generate_content' => ['boolean'],
        ]);

        $user = $request->user();

        $scan = WebsiteScan::query()
            ->where('id', $scanId)
            ->where('organization_id', $user->organization_id)
            ->first();

        if (! $scan) {
            return response()->json([
                'error' => 'Scan not found.',
            ], 404);
        }

        if (! $scan->isCompleted()) {
            return response()->json([
                'error' => 'Scan is not completed yet.',
                'status' => $scan->status,
            ], 422);
        }

        if ($scan->user_confirmed) {
            return response()->json([
                'error' => 'Scan has already been confirmed.',
            ], 409);
        }

        $scan->update(['user_confirmed' => true]);

        // Apply profiles to organization if requested
        $applyToOrganization = $data['apply_to_organization'] ?? true;
        if ($applyToOrganization) {
            $user->organization->update([
                'brand_profile' => $scan->brand_profile,
                'seo_profile' => $scan->seo_profile,
                'design_profile' => $scan->design_profile,
                'technical_profile' => $scan->technical_profile,
                'onboarding_scan_id' => $scan->id,
            ]);
        }

        // Dispatch content generation if requested and client_site_id provided
        $generateContent = $data['generate_content'] ?? false;
        $clientSiteId = $data['client_site_id'] ?? null;

        if ($generateContent && $clientSiteId) {
            GenerateInitialContentJob::dispatch($scan->id, $clientSiteId)
                ->onQueue('generation');
        }

        return response()->json([
            'message' => 'Scan confirmed successfully.',
            'scan_id' => $scan->id,
            'profiles_applied' => $applyToOrganization,
            'content_generation_queued' => $generateContent && $clientSiteId,
        ]);
    }

    /**
     * Get the latest scan for the user's organization.
     */
    public function latest(Request $request): JsonResponse
    {
        $user = $request->user();

        $scan = WebsiteScan::query()
            ->where('organization_id', $user->organization_id)
            ->orderByDesc('created_at')
            ->first();

        if (! $scan) {
            return response()->json([
                'scan' => null,
            ]);
        }

        return $this->show($request, $scan->id);
    }
}
