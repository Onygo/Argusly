<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\Brief;
use App\Models\Content;
use App\Models\Draft;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

class AppContentPipelineController extends Controller
{
    private const LANES = [
        'ideas' => 'Ideas',
        'in_progress' => 'In Progress',
        'review' => 'Review',
        'ready' => 'Ready',
        'published' => 'Published',
    ];

    public function index(Request $request): View
    {
        $organizationId = (int) $request->user()->organization_id;
        $selectedLane = (string) $request->query('lane', '');

        $contents = Content::query()
            ->where(function ($query) use ($organizationId): void {
                $query->whereHas('workspace', fn ($workspace) => $workspace->where('organization_id', $organizationId))
                    ->orWhereHas('clientSite.workspace', fn ($workspace) => $workspace->where('organization_id', $organizationId));
            })
            ->where('status', '!=', 'archived')
            ->with([
                'workspace:id,organization_id,name,display_name',
                'clientSite:id,name,workspace_id',
                'brief:id,content_id,title,status,client_site_id',
                'drafts:id,brief_id,content_id,client_site_id,title,status,delivery_status,delivered_at,updated_at,created_at',
                'publications:id,content_id,delivery_status,remote_status,last_delivered_at,last_error_at',
            ])
            ->latest('updated_at')
            ->limit(80)
            ->get();

        $representedContentIds = $contents->pluck('id')->filter()->map(fn ($id): string => (string) $id)->all();
        $representedBriefIds = $contents
            ->pluck('brief.id')
            ->merge($contents->flatMap(fn (Content $content) => $content->drafts->pluck('brief_id')))
            ->filter()
            ->map(fn ($id): string => (string) $id)
            ->unique()
            ->values()
            ->all();
        $representedDraftIds = $contents->flatMap(fn (Content $content) => $content->drafts->pluck('id'))->map(fn ($id): string => (string) $id)->all();

        $briefs = Brief::query()
            ->whereHas('clientSite.workspace', fn ($workspace) => $workspace->where('organization_id', $organizationId))
            ->when($representedBriefIds !== [], fn ($query) => $query->whereNotIn('id', $representedBriefIds))
            ->where('status', '!=', 'archived')
            ->with([
                'clientSite:id,name,workspace_id',
                'clientSite.workspace:id,organization_id,name,display_name',
                'drafts:id,brief_id,content_id,client_site_id,title,status,delivery_status,delivered_at,updated_at,created_at',
            ])
            ->latest('updated_at')
            ->limit(50)
            ->get();

        $representedBriefIds = collect($representedBriefIds)
            ->merge($briefs->pluck('id'))
            ->filter()
            ->map(fn ($id): string => (string) $id)
            ->unique()
            ->values()
            ->all();
        $representedDraftIds = collect($representedDraftIds)
            ->merge($briefs->flatMap(fn (Brief $brief) => $brief->drafts->pluck('id')))
            ->filter()
            ->map(fn ($id): string => (string) $id)
            ->unique()
            ->values()
            ->all();

        $drafts = Draft::query()
            ->whereHas('clientSite.workspace', fn ($workspace) => $workspace->where('organization_id', $organizationId))
            ->when($representedDraftIds !== [], fn ($query) => $query->whereNotIn('id', $representedDraftIds))
            ->when($representedContentIds !== [], fn ($query) => $query->where(function ($draftQuery) use ($representedContentIds): void {
                $draftQuery->whereNull('content_id')->orWhereNotIn('content_id', $representedContentIds);
            }))
            ->when($representedBriefIds !== [], fn ($query) => $query->where(function ($draftQuery) use ($representedBriefIds): void {
                $draftQuery->whereNull('brief_id')->orWhereNotIn('brief_id', $representedBriefIds);
            }))
            ->where('status', '!=', Draft::STATUS_ARCHIVED)
            ->with([
                'clientSite:id,name,workspace_id',
                'clientSite.workspace:id,organization_id,name,display_name',
                'brief:id,title,status,client_site_id',
                'content:id,title,status,publish_status,published_url,first_published_at,client_site_id,workspace_id',
            ])
            ->latest('updated_at')
            ->limit(50)
            ->get();

        $cards = collect($contents->map(fn (Content $content): array => $this->contentCard($content))->all())
            ->merge($briefs->map(fn (Brief $brief): array => $this->briefCard($brief)))
            ->merge($drafts->map(fn (Draft $draft): array => $this->draftCard($draft)))
            ->sortByDesc('updated_at')
            ->values();

        $visibleCards = array_key_exists($selectedLane, self::LANES)
            ? $cards->where('lane', $selectedLane)->values()
            : $cards;

        return view('app.content.pipeline', [
            'title' => 'Content Pipeline',
            'lanes' => self::LANES,
            'selectedLane' => $selectedLane,
            'cards' => $visibleCards,
            'cardsByLane' => $visibleCards->groupBy('lane'),
            'summary' => collect(self::LANES)
                ->mapWithKeys(fn (string $label, string $key): array => [$key => $cards->where('lane', $key)->count()])
                ->all(),
        ]);
    }

    private function contentCard(Content $content): array
    {
        $latestDraft = $content->drafts->sortByDesc('updated_at')->first();
        $lane = $this->laneForContent($content, $latestDraft);

        return [
            'lane' => $lane,
            'title' => $content->title,
            'site' => $content->clientSite?->name,
            'status' => $this->statusLabel($lane),
            'progress' => $this->progressForLane($lane),
            'next_step' => $this->nextStepForLane($lane),
            'updated_at' => $content->updated_at,
            'url' => route('app.content.show', $content),
            'advanced_url' => route('app.content.show', $content),
        ];
    }

    private function briefCard(Brief $brief): array
    {
        $latestDraft = $brief->drafts->sortByDesc('updated_at')->first();
        $lane = $latestDraft ? $this->laneForDraft($latestDraft) : 'ideas';

        return [
            'lane' => $lane,
            'title' => $brief->title,
            'site' => $brief->clientSite?->name,
            'status' => $this->statusLabel($lane),
            'progress' => $this->progressForLane($lane),
            'next_step' => $latestDraft ? $this->nextStepForLane($lane) : 'Turn the idea into an active piece of content.',
            'updated_at' => $brief->updated_at,
            'url' => route('app.content.workspace.show', $brief),
            'advanced_url' => route('app.briefs.show', $brief),
        ];
    }

    private function draftCard(Draft $draft): array
    {
        $lane = $this->laneForDraft($draft);

        return [
            'lane' => $lane,
            'title' => $draft->title ?: $draft->brief?->title ?: $draft->content?->title ?: 'Untitled content',
            'site' => $draft->clientSite?->name,
            'status' => $this->statusLabel($lane),
            'progress' => $this->progressForLane($lane),
            'next_step' => $this->nextStepForLane($lane),
            'updated_at' => $draft->updated_at,
            'url' => route('app.drafts.show', $draft),
            'advanced_url' => route('app.drafts.show', $draft),
        ];
    }

    private function laneForContent(Content $content, ?Draft $latestDraft): string
    {
        $status = (string) $content->status;
        $publishStatus = (string) ($content->publish_status ?? '');

        if ($status === 'published' || $publishStatus === 'published' || $content->first_published_at || filled($content->published_url)) {
            return 'published';
        }

        if (in_array($publishStatus, ['scheduled', 'publishing'], true) || $status === 'approved' || $latestDraft?->status === Draft::STATUS_APPROVED_FOR_PUBLISHING) {
            return 'ready';
        }

        if ($status === 'review' || $latestDraft?->status === Draft::STATUS_READY_FOR_REVIEW) {
            return 'review';
        }

        if (in_array($status, ['draft', 'generating'], true) || in_array((string) $latestDraft?->status, [Draft::STATUS_DRAFT, Draft::STATUS_CHANGES_REQUESTED], true)) {
            return 'in_progress';
        }

        return 'ideas';
    }

    private function laneForDraft(Draft $draft): string
    {
        if ($draft->delivered_at || in_array((string) $draft->delivery_status, ['delivered', 'published'], true)) {
            return 'published';
        }

        return match ((string) $draft->status) {
            Draft::STATUS_APPROVED_FOR_PUBLISHING => 'ready',
            Draft::STATUS_READY_FOR_REVIEW => 'review',
            Draft::STATUS_DRAFT, Draft::STATUS_CHANGES_REQUESTED => 'in_progress',
            default => 'in_progress',
        };
    }

    private function statusLabel(string $lane): string
    {
        return self::LANES[$lane] ?? 'In Progress';
    }

    private function progressForLane(string $lane): int
    {
        return match ($lane) {
            'ideas' => 10,
            'in_progress' => 40,
            'review' => 65,
            'ready' => 85,
            'published' => 100,
            default => 25,
        };
    }

    private function nextStepForLane(string $lane): string
    {
        return match ($lane) {
            'ideas' => 'Choose the idea and start production.',
            'in_progress' => 'Continue writing, improving, or preparing the piece.',
            'review' => 'Review the piece and request changes or approve it.',
            'ready' => 'Schedule or publish when the final checks are clear.',
            'published' => 'Measure performance and decide whether to improve or distribute further.',
            default => 'Open the content workspace.',
        };
    }
}
