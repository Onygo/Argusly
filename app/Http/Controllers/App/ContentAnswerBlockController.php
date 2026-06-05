<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\Brief;
use App\Models\Content;
use App\Models\Draft;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ContentAnswerBlockController extends Controller
{
    public function updateSettings(Request $request, string $content): RedirectResponse|JsonResponse
    {
        $content = $this->resolveContentFromIdentifier($content);
        $this->assertContentInUserOrganization($request, $content);
        $this->authorize('update', $content);

        $validated = $request->validate([
            'answer_block_render_mode' => ['nullable', 'in:' . implode(',', Content::answerBlockRenderModes())],
            'answer_block_visibility' => ['nullable', 'in:' . implode(',', Content::answerBlockVisibilities())],
            'answer_block_position' => ['nullable', 'in:' . implode(',', Content::answerBlockPositions())],
            'answer_block_max_visible' => ['nullable', 'integer', 'min:1', 'max:10'],
        ]);

        $settings = [
            'answer_block_render_mode' => Content::resolveAnswerBlockRenderModeSetting(
                $validated['answer_block_render_mode'] ?? null,
                $validated['answer_block_visibility'] ?? null,
                $validated['answer_block_position'] ?? null,
            ),
            'answer_block_visibility' => $validated['answer_block_visibility'] ?? null,
            'answer_block_position' => $validated['answer_block_position'] ?? null,
            'answer_block_max_visible' => $validated['answer_block_max_visible'] ?? null,
        ];

        $content->fill($settings)->save();

        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'Answer block settings updated.',
                'content_id' => (string) $content->id,
                'settings' => $settings,
            ]);
        }

        return redirect()
            ->route('app.content.show', ['content' => $content, 'tab' => 'answers'])
            ->with('success', 'Answer block settings updated.');
    }

    private function assertContentInUserOrganization(Request $request, Content $content): void
    {
        $content->loadMissing('workspace', 'clientSite.workspace');

        $organizationId = (int) $request->user()->organization_id;
        $workspaceOrganizationId = (int) ($content->workspace?->organization_id ?? 0);
        $clientSiteOrganizationId = (int) ($content->clientSite?->workspace?->organization_id ?? 0);

        if ($workspaceOrganizationId !== $organizationId && $clientSiteOrganizationId !== $organizationId) {
            abort(404);
        }
    }

    private function resolveContentFromIdentifier(string $identifier): Content
    {
        $content = Content::query()->find($identifier);
        if ($content) {
            return $content;
        }

        $briefContent = Brief::query()
            ->whereKey($identifier)
            ->with('content')
            ->first()?->content;
        if ($briefContent) {
            return $briefContent;
        }

        $draftContent = Draft::query()
            ->whereKey($identifier)
            ->with('content')
            ->first()?->content;
        if ($draftContent) {
            return $draftContent;
        }

        abort(404);
    }
}
