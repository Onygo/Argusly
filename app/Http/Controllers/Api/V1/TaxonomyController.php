<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\TaxonomyItem;
use App\Support\EditorialTaxonomyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TaxonomyController extends Controller
{
    public function intents(Request $request, EditorialTaxonomyService $taxonomyService): JsonResponse
    {
        $siteToken = $request->attributes->get('siteToken');
        if (! $siteToken || ! $siteToken->hasScope('briefs:write')) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        $organizationId = (int) $request->attributes->get('clientSite')?->workspace?->organization_id;
        if (! $organizationId) {
            return response()->json(['error' => 'Client site not resolved'], 401);
        }

        $items = $taxonomyService
            ->activeItemsByTenantAndType($organizationId, TaxonomyItem::TYPE_INTENT)
            ->map(fn (TaxonomyItem $intent): array => [
                'key' => (string) $intent->slug,
                'label' => (string) $intent->name,
                'description' => '',
            ])
            ->values();

        return response()->json(['items' => $items]);
    }

    public function audiences(Request $request, EditorialTaxonomyService $taxonomyService): JsonResponse
    {
        $siteToken = $request->attributes->get('siteToken');
        if (! $siteToken || ! $siteToken->hasScope('briefs:write')) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        $organizationId = (int) $request->attributes->get('clientSite')?->workspace?->organization_id;
        if (! $organizationId) {
            return response()->json(['error' => 'Client site not resolved'], 401);
        }

        $items = $taxonomyService
            ->activeItemsByTenantAndType($organizationId, TaxonomyItem::TYPE_AUDIENCE)
            ->map(fn (TaxonomyItem $audience): array => [
                'key' => (string) $audience->slug,
                'label' => (string) $audience->name,
                'description' => '',
            ])
            ->values();

        return response()->json(['items' => $items]);
    }
}
