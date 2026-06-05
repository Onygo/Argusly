<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\Content;
use App\Models\ContentChainGuidance;
use App\Models\ContentChainSuggestion;
use App\Services\ContentChain\ChainedContentCreationService;
use App\Services\ContentChain\ChainedContentOpportunityService;
use App\Services\ContentChain\ContextualLinkInsertionService;
use App\Services\Entitlements\FeatureGate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class AppContentChainController extends Controller
{
    public function refresh(
        Request $request,
        Content $content,
        ChainedContentOpportunityService $opportunityService,
        FeatureGate $featureGate,
    ): RedirectResponse {
        $this->assertContentInOrganization($request, $content);
        $this->authorize('update', $content);
        $this->assertFeatureEnabled($content, $featureGate);

        $result = $opportunityService->refreshForContent($content->fresh());

        return back()->with('status', sprintf(
            'Chained content suggestions refreshed. %d growth, %d inline and %d footer suggestions.',
            (int) $result['growth'],
            (int) $result['inline'],
            (int) $result['footer'],
        ));
    }

    public function updateGuidance(Request $request, Content $content, FeatureGate $featureGate): RedirectResponse
    {
        $this->assertContentInOrganization($request, $content);
        $this->authorize('update', $content);
        $this->assertFeatureEnabled($content, $featureGate);

        $data = $request->validate([
            'is_source_enabled' => ['nullable', 'boolean'],
            'preferred_angle' => ['nullable', 'string', 'max:191'],
            'goal_type' => ['nullable', 'string', 'max:64'],
            'priority' => ['required', 'in:low,medium,high,critical'],
            'target_keyword' => ['nullable', 'string', 'max:191'],
            'target_audience' => ['nullable', 'string', 'max:191'],
            'target_intent' => ['nullable', 'string', 'max:64'],
            'explicit_topic' => ['nullable', 'string', 'max:191'],
            'editor_notes' => ['nullable', 'string', 'max:5000'],
            'inline_link_mode' => ['required', 'in:automatic,suggestions_only,review,off'],
            'allow_heading_links' => ['nullable', 'boolean'],
            'max_inline_links' => ['nullable', 'integer', 'min:1', 'max:10'],
        ]);

        ContentChainGuidance::query()->updateOrCreate(
            ['content_id' => $content->id],
            [
                'id' => (string) Str::uuid(),
                'workspace_id' => (string) $content->workspace_id,
                'is_source_enabled' => (bool) ($data['is_source_enabled'] ?? false),
                'preferred_angle' => $this->nullableString($data['preferred_angle'] ?? null),
                'goal_type' => $this->nullableString($data['goal_type'] ?? null),
                'priority' => (string) $data['priority'],
                'target_keyword' => $this->nullableString($data['target_keyword'] ?? null),
                'target_audience' => $this->nullableString($data['target_audience'] ?? null),
                'target_intent' => $this->nullableString($data['target_intent'] ?? null),
                'explicit_topic' => $this->nullableString($data['explicit_topic'] ?? null),
                'editor_notes' => $this->nullableString($data['editor_notes'] ?? null),
                'inline_link_mode' => (string) $data['inline_link_mode'],
                'allow_heading_links' => (bool) ($data['allow_heading_links'] ?? false),
                'max_inline_links' => $data['max_inline_links'] ?? null,
                'updated_by_user_id' => $request->user()->id,
            ],
        );

        return back()->with('status', 'Chained content guidance updated.');
    }

    public function approve(
        Request $request,
        Content $content,
        ContentChainSuggestion $suggestion,
        FeatureGate $featureGate,
    ): RedirectResponse {
        $this->assertSuggestionOwnership($request, $content, $suggestion);
        $this->authorize('update', $content);
        $this->assertFeatureEnabled($content, $featureGate);

        $suggestion->update([
            'status' => ContentChainSuggestion::STATUS_APPROVED,
            'reviewed_by_user_id' => $request->user()->id,
            'reviewed_at' => now(),
        ]);

        return back()->with('status', 'Suggestion approved.');
    }

    public function reject(
        Request $request,
        Content $content,
        ContentChainSuggestion $suggestion,
        FeatureGate $featureGate,
    ): RedirectResponse {
        $this->assertSuggestionOwnership($request, $content, $suggestion);
        $this->authorize('update', $content);
        $this->assertFeatureEnabled($content, $featureGate);

        $suggestion->update([
            'status' => ContentChainSuggestion::STATUS_REJECTED,
            'reviewed_by_user_id' => $request->user()->id,
            'reviewed_at' => now(),
        ]);

        return back()->with('status', 'Suggestion rejected.');
    }

    public function applyApprovedLinks(
        Request $request,
        Content $content,
        ContextualLinkInsertionService $insertionService,
        FeatureGate $featureGate,
    ): RedirectResponse {
        $this->assertContentInOrganization($request, $content);
        $this->authorize('update', $content);
        $this->assertFeatureEnabled($content, $featureGate);

        $result = $insertionService->applyApprovedSuggestions($content->fresh());

        return back()->with('status', sprintf(
            'Applied %d inline and %d footer chained links.',
            (int) $result['applied_inline'],
            (int) $result['applied_footer'],
        ));
    }

    public function createFromSuggestion(
        Request $request,
        Content $content,
        ContentChainSuggestion $suggestion,
        ChainedContentCreationService $creationService,
        FeatureGate $featureGate,
    ): RedirectResponse {
        $this->assertSuggestionOwnership($request, $content, $suggestion);
        $this->authorize('update', $content);
        $this->assertFeatureEnabled($content, $featureGate);

        $created = $creationService->createFromSuggestion($suggestion, $request->user());

        return redirect()
            ->route('app.content.show', ['content' => $created, 'tab' => 'overview'])
            ->with('status', 'New chained content created from suggestion.');
    }

    private function assertFeatureEnabled(Content $content, FeatureGate $featureGate): void
    {
        $entitlement = $featureGate->value($content->workspace, 'content_network_analysis_enabled', false);
        $enabled = (bool) config('features.content_network_analysis', false)
            && ! in_array(strtolower(trim((string) $entitlement)), ['', '0', 'false', 'off', 'no'], true);

        if (! $enabled) {
            abort(404);
        }
    }

    private function assertContentInOrganization(Request $request, Content $content): void
    {
        $content->loadMissing('workspace', 'clientSite.workspace');

        $workspaceOrgId = (int) ($content->workspace?->organization_id ?? 0);
        $siteOrgId = (int) ($content->clientSite?->workspace?->organization_id ?? 0);
        $organizationId = (int) $request->user()->organization_id;

        if ($workspaceOrgId !== $organizationId && $siteOrgId !== $organizationId) {
            abort(404);
        }
    }

    private function assertSuggestionOwnership(Request $request, Content $content, ContentChainSuggestion $suggestion): void
    {
        $this->assertContentInOrganization($request, $content);

        if ((string) $suggestion->source_content_id !== (string) $content->id) {
            abort(404);
        }
    }

    private function nullableString(mixed $value): ?string
    {
        $normalized = trim((string) $value);

        return $normalized !== '' ? $normalized : null;
    }
}
