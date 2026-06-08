<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ContentImage;
use App\Models\Draft;
use App\Services\Ai\ImageGenerationService;
use App\Services\CreditWalletService;
use App\Services\GenerationFinalizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ImageController extends Controller
{
    public function generate(
        Request $request,
        ImageGenerationService $imageGenerationService,
        CreditWalletService $creditWalletService,
        GenerationFinalizer $finalizer
    ): JsonResponse {
        $siteToken = $request->attributes->get('siteToken');
        if (! $siteToken || ! $siteToken->hasScope('drafts:write')) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        $clientSite = $request->attributes->get('clientSite');
        if (! $clientSite) {
            return response()->json(['error' => 'Client site not resolved'], 401);
        }

        $data = $request->validate([
            'draft_id' => ['required', 'uuid'],
            'content_id' => ['nullable', 'uuid'],
        ]);

        $draft = Draft::query()
            ->where('client_site_id', $clientSite->id)
            ->where('id', $data['draft_id'])
            ->with('content')
            ->first();

        if (! $draft) {
            $fallbackContentId = (string) ($data['content_id'] ?? '');
            if ($fallbackContentId !== '') {
                $draft = Draft::query()
                    ->where('client_site_id', $clientSite->id)
                    ->where('content_id', $fallbackContentId)
                    ->with('content')
                    ->latest('created_at')
                    ->first();
            }
        }

        if (! $draft) {
            Log::warning('api.v1.images.generate.draft_not_found', [
                'requested_draft_id' => (string) $data['draft_id'],
                'requested_content_id' => (string) ($data['content_id'] ?? ''),
                'client_site_id' => (string) $clientSite->id,
            ]);

            return response()->json(['error' => 'Draft not found'], 404);
        }

        if (! $draft->content) {
            return response()->json(['error' => 'Draft is not linked to content'], 422);
        }

        $activeGeneration = ContentImage::query()
            ->where('content_id', $draft->content->id)
            ->where('type', 'featured')
            ->whereIn('status', ['queued', 'generating'])
            ->latest('updated_at')
            ->first();

        if ($activeGeneration) {
            $lockTimeoutMinutes = max(1, (int) config('argusly.ai.images.generation_lock_timeout_minutes', 5));
            $staleCutoff = now()->subMinutes($lockTimeoutMinutes);
            if ($activeGeneration->updated_at && $activeGeneration->updated_at->lt($staleCutoff)) {
                $previousStatus = (string) $activeGeneration->status;
                $activeGeneration = $finalizer->markContentImageFailedAndRefundIfNeeded(
                    $activeGeneration,
                    'stale_lock_timeout',
                    'Marked failed after stale generation lock timeout.'
                ) ?? $activeGeneration;

                Log::warning('api.v1.images.generate.stale_lock_released', [
                    'stale_content_image_id' => (string) $activeGeneration->id,
                    'content_id' => (string) $draft->content->id,
                    'client_site_id' => (string) $clientSite->id,
                    'previous_status' => $previousStatus,
                    'credit_status' => (string) ($activeGeneration->credit_status ?? ''),
                    'credit_ledger_entry_id' => (string) ($activeGeneration->credit_ledger_entry_id ?? ''),
                    'lock_timeout_minutes' => $lockTimeoutMinutes,
                    'updated_at' => optional($activeGeneration->updated_at)->toIso8601String(),
                ]);
            } else {
                Log::info('api.v1.images.generate.locked', [
                    'content_image_id' => (string) $activeGeneration->id,
                    'content_id' => (string) $draft->content->id,
                    'client_site_id' => (string) $clientSite->id,
                    'status' => (string) $activeGeneration->status,
                    'lock_timeout_minutes' => $lockTimeoutMinutes,
                    'updated_at' => optional($activeGeneration->updated_at)->toIso8601String(),
                ]);

                return response()->json(['error' => 'Image generation is already running'], 409);
            }
        }

        $activeGenerationAfterRelease = ContentImage::query()
            ->where('content_id', $draft->content->id)
            ->where('type', 'featured')
            ->whereIn('status', ['queued', 'generating'])
            ->exists();

        if ($activeGenerationAfterRelease) {
            return response()->json(['error' => 'Image generation is already running'], 409);
        }

        $required = $imageGenerationService->resolveCreditCost();
        $available = $creditWalletService->getAvailableForClientSite((string) $clientSite->id);
        if ($available < $required) {
            return response()->json([
                'error' => sprintf('Insufficient credits. Required: %d, available: %d.', $required, $available),
                'required' => $required,
                'available' => $available,
                'action' => 'image_generate',
            ], 422);
        }

        $image = $imageGenerationService->generateFeaturedImage($draft->content);

        Log::info('api.v1.images.generate.queued', [
            'draft_id' => (string) $draft->id,
            'content_id' => (string) $draft->content->id,
            'client_site_id' => (string) $clientSite->id,
            'image_id' => (string) $image->id,
            'status' => (string) $image->status,
        ]);

        return response()->json([
            'ok' => true,
            'image_id' => (string) $image->id,
            'status' => (string) $image->status,
            'draft_id' => (string) $draft->id,
            'content_id' => (string) $draft->content->id,
        ], 202);
    }
}
