<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Content;
use App\Services\Api\ApiScopes;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ContentAnswerController extends Controller
{
    public function show(Request $request, string $id): JsonResponse
    {
        $workspace = $request->attributes->get('workspace');
        $apiKey = $request->attributes->get('apiKey');

        abort_unless($workspace && $apiKey, 401);
        abort_unless($apiKey->hasScope(ApiScopes::CONTENT_READ) || $apiKey->hasScope(ApiScopes::DRAFTS_READ), 403);

        $content = Content::query()
            ->with(['answerBlocks'])
            ->where('workspace_id', (string) $workspace->id)
            ->findOrFail($id);

        return response()->json([
            'answers' => $content->answerBlocks
                ->sortBy('order')
                ->values()
                ->map(fn ($block): array => [
                    'id' => (string) $block->id,
                    'question' => (string) $block->question,
                    'answer' => (string) $block->answer,
                    'entities' => array_values((array) ($block->entities ?? [])),
                    'order' => (int) $block->order,
                ])
                ->all(),
            'aeo_score' => $content->aeo_score,
        ]);
    }
}
