<?php

namespace App\Http\Controllers\App;

use App\Contracts\LinkIntelligence\LinkApplyService;
use App\Contracts\LinkIntelligence\LinkSuggestionService;
use App\DTO\LinkIntelligence\ApplyOptions;
use App\Http\Controllers\Controller;
use App\Jobs\DeliverDraftJob;
use App\Models\Draft;
use App\Models\LinkSuggestion;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Throwable;

class DraftLinkSuggestionsController extends Controller
{
    public function generate(Draft $draft, LinkSuggestionService $linkSuggestionService): RedirectResponse
    {
        $this->assertDraftInOrganization($draft);

        $linkSuggestionService->generateSuggestions($draft);

        return back()->with('status', 'Link suggestions regenerated.');
    }

    public function resetApplied(Request $request, Draft $draft): RedirectResponse
    {
        $this->assertDraftInOrganization($draft);

        $appliedSuggestions = LinkSuggestion::query()
            ->where('source_article_id', $draft->id)
            ->where('status', 'applied')
            ->with('sourceWorkspace')
            ->get();

        foreach ($appliedSuggestions as $suggestion) {
            Gate::authorize('review', $suggestion);
        }

        $updatedCount = 0;
        foreach ($appliedSuggestions as $suggestion) {
            $suggestion->update([
                'status' => 'rejected',
                'reviewed_by_user_id' => $request->user()->id,
                'reviewed_at' => now(),
                'applied_at' => null,
            ]);
            $updatedCount++;
        }

        return back()->with('status', 'Reset applied suggestions: ' . $updatedCount . '. You can regenerate now.');
    }

    public function approve(Request $request, Draft $draft, LinkSuggestion $suggestion, LinkApplyService $linkApplyService): RedirectResponse
    {
        $this->assertOwnedSuggestion($draft, $suggestion);
        Gate::authorize('review', $suggestion);

        $data = $request->validate([
            'suggested_placement' => ['nullable', 'in:inline,footnote'],
            'anchor_text' => ['nullable', 'string', 'max:190'],
            'apply_now' => ['nullable', 'boolean'],
        ]);

        $anchors = (array) ($suggestion->suggested_anchor_variants ?? []);
        if (! empty($data['anchor_text'])) {
            array_unshift($anchors, (string) $data['anchor_text']);
            $anchors = array_values(array_unique(array_filter($anchors)));
        }

        $suggestion->update([
            'status' => 'approved',
            'suggested_placement' => (string) ($data['suggested_placement'] ?? $suggestion->suggested_placement),
            'suggested_anchor_variants' => $anchors,
            'reviewed_by_user_id' => $request->user()->id,
            'reviewed_at' => now(),
        ]);

        if ((bool) ($data['apply_now'] ?? false)) {
            Gate::authorize('apply', $suggestion);

            try {
                $linkApplyService->applySuggestion($suggestion->fresh(), new ApplyOptions(
                    placement: (string) ($data['suggested_placement'] ?? $suggestion->suggested_placement),
                    anchorText: (string) ($data['anchor_text'] ?? ''),
                ));
            } catch (Throwable $exception) {
                return back()->with('status', 'Apply failed: ' . $exception->getMessage());
            }

            $this->queueRepush($draft);
        }

        return back()->with('status', 'Suggestion approved.');
    }

    public function reject(Request $request, Draft $draft, LinkSuggestion $suggestion): RedirectResponse
    {
        $this->assertOwnedSuggestion($draft, $suggestion);
        Gate::authorize('review', $suggestion);

        $suggestion->update([
            'status' => 'rejected',
            'reviewed_by_user_id' => $request->user()->id,
            'reviewed_at' => now(),
        ]);

        return back()->with('status', 'Suggestion rejected.');
    }

    public function delete(Request $request, Draft $draft, LinkSuggestion $suggestion): RedirectResponse
    {
        $this->assertOwnedSuggestion($draft, $suggestion);
        Gate::authorize('review', $suggestion);

        if ($suggestion->status !== 'rejected') {
            return back()->with('status', 'Only rejected suggestions can be removed.');
        }

        $suggestion->delete();

        return back()->with('status', 'Rejected suggestion removed.');
    }

    public function clearRejected(Request $request, Draft $draft): RedirectResponse
    {
        $this->assertDraftInOrganization($draft);

        $rejectedSuggestions = LinkSuggestion::query()
            ->where('source_article_id', $draft->id)
            ->where('status', 'rejected')
            ->with('sourceWorkspace')
            ->get();

        foreach ($rejectedSuggestions as $suggestion) {
            Gate::authorize('review', $suggestion);
        }

        $deletedCount = 0;
        foreach ($rejectedSuggestions as $suggestion) {
            $suggestion->delete();
            $deletedCount++;
        }

        return back()->with('status', 'Removed rejected suggestions: ' . $deletedCount . '.');
    }

    public function apply(Request $request, Draft $draft, LinkSuggestion $suggestion, LinkApplyService $linkApplyService): RedirectResponse
    {
        $this->assertOwnedSuggestion($draft, $suggestion);
        Gate::authorize('apply', $suggestion);

        $data = $request->validate([
            'placement' => ['nullable', 'in:inline,footnote'],
            'anchor_text' => ['nullable', 'string', 'max:190'],
        ]);

        $placement = (string) ($data['placement'] ?? $suggestion->suggested_placement);

        try {
            $linkApplyService->applySuggestion($suggestion, new ApplyOptions(
                placement: $placement,
                anchorText: (string) ($data['anchor_text'] ?? ''),
            ));
        } catch (Throwable $exception) {
            return back()->with('status', 'Apply failed: ' . $exception->getMessage());
        }

        $this->queueRepush($draft);

        return back()->with('status', 'Suggestion applied and queued for WP repush.');
    }

    private function assertDraftInOrganization(Draft $draft): void
    {
        $organizationId = request()->user()->organization_id;
        $draft->loadMissing('clientSite.workspace');

        if ((int) $draft->clientSite?->workspace?->organization_id !== (int) $organizationId) {
            abort(404);
        }
    }

    private function assertOwnedSuggestion(Draft $draft, LinkSuggestion $suggestion): void
    {
        $this->assertDraftInOrganization($draft);

        if ((string) $suggestion->source_article_id !== (string) $draft->id) {
            abort(404);
        }

        $suggestion->loadMissing('sourceWorkspace');
        if ((int) $suggestion->sourceWorkspace?->organization_id !== (int) request()->user()->organization_id) {
            abort(404);
        }
    }

    private function queueRepush(Draft $draft): void
    {
        $draft->update([
            'status' => 'ready_to_deliver',
            'delivery_status' => 'pending',
            'delivery_last_error' => null,
        ]);

        DeliverDraftJob::dispatch((string) $draft->id)->onQueue((string) config('publishlayer.webhooks.queue', 'deliveries'));
    }
}
