<?php

namespace App\Services\Onboarding;

use App\Models\BrandContext;
use App\Models\ClientSite;
use App\Models\LlmTrackingQuery;
use App\Models\LlmTrackingQueryRun;
use App\Models\Opportunity;
use App\Models\SignalDetection;
use App\Models\SignalEvent;
use App\Models\SiteCompetitor;
use App\Models\Workspace;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route;

class FirstValueActivationService
{
    /**
     * @return array<string,mixed>
     */
    public function forWorkspace(Workspace $workspace): array
    {
        $cacheKey = 'first_value_activation.'.(string) $workspace->id;
        if ($this->usesRequestCache() && request()->attributes->has($cacheKey)) {
            return request()->attributes->get($cacheKey);
        }

        $site = ClientSite::query()
            ->where('workspace_id', $workspace->id)
            ->orderBy('created_at')
            ->first();

        $brandProfileReady = BrandContext::query()->where('workspace_id', $workspace->id)->exists();
        $websiteReady = (bool) $site;
        $competitorsReady = SiteCompetitor::query()
            ->where('workspace_id', $workspace->id)
            ->where('is_active', true)
            ->exists();
        $queryCount = LlmTrackingQuery::query()->where('workspace_id', $workspace->id)->count();
        $runCount = LlmTrackingQueryRun::query()
            ->whereHas('trackingQuery', fn ($query) => $query->where('workspace_id', $workspace->id))
            ->count();
        $eventCount = SignalEvent::query()->where('workspace_id', $workspace->id)->count();
        $detectionCount = SignalDetection::query()->where('workspace_id', $workspace->id)->count();
        $candidateCount = SignalDetection::query()
            ->where('workspace_id', $workspace->id)
            ->open()
            ->where(function ($query): void {
                $query->where('category', SignalDetection::CATEGORY_OPPORTUNITY_DETECTION)
                    ->orWhere('opportunity_score', '>=', 70);
            })
            ->count();
        $opportunityCount = Opportunity::query()->where('workspace_id', $workspace->id)->count();
        $hasOpportunityCandidateProgress = $candidateCount > 0 || $opportunityCount > 0;

        $steps = collect([
            $this->step(
                'brand_profile',
                'Brand profile',
                'Define the brand terms Argusly should recognize in AI answers and signal evidence.',
                $brandProfileReady,
                'Open brand profile',
                $this->route('app.brand.company-profile'),
            ),
            $this->step(
                'website',
                'Website',
                'Connect the site that anchors your target domain and AI Visibility tracking.',
                $websiteReady,
                'Add website',
                $this->route('app.sites'),
            ),
            $this->step(
                'competitors',
                'Competitors',
                'Add at least one competitor so AI Visibility and Signal Intelligence can compare the market.',
                $competitorsReady,
                $websiteReady ? 'Manage competitors' : 'Add website first',
                $site ? $this->route('app.sites.competitors.index', $site) : $this->route('app.sites'),
            ),
            $this->step(
                'ai_visibility_queries',
                'AI Visibility queries',
                'Create the first tracking prompt for the questions your buyers ask AI systems.',
                $queryCount > 0,
                $websiteReady ? 'Create query' : 'Add website first',
                $site ? $this->route('app.sites.llm-tracking.index', $site) : $this->route('app.sites'),
            ),
            $this->step(
                'first_run',
                'First AI Visibility run',
                'Run a tracking query once so Argusly has answer evidence to analyze.',
                $runCount > 0,
                $queryCount > 0 && $site ? 'Run query' : 'Create query first',
                $site ? $this->route('app.sites.llm-tracking.index', $site) : $this->route('app.sites'),
            ),
            $this->step(
                'first_signal_event',
                'First Signal Event',
                'Signal Intelligence receives its first normalized event from AI Visibility or another source.',
                $eventCount > 0,
                $runCount > 0 ? 'Open Signal Intelligence' : 'Run query first',
                $this->route('app.signal-intelligence.index', ['workspace' => $workspace->id]),
            ),
            $this->step(
                'first_detection',
                'First Detection',
                'Run detection after signal events exist to group evidence into something reviewable.',
                $detectionCount > 0,
                $eventCount > 0 ? 'Run detection' : 'Create signal event first',
                $this->route('app.signal-intelligence.index', ['workspace' => $workspace->id]),
            ),
            $this->step(
                'first_opportunity_candidate',
                'First Opportunity Candidate',
                'Review a detection with enough opportunity score to decide whether it should become an opportunity.',
                $hasOpportunityCandidateProgress,
                $candidateCount > 0 ? 'Review Opportunity' : ($detectionCount > 0 ? 'Find Opportunity Candidate' : 'Create detection first'),
                $candidateCount > 0
                    ? $this->route('app.opportunity-review.index', ['workspace' => $workspace->id])
                    : $this->route('app.signal-intelligence.index', ['workspace' => $workspace->id]).'#priority',
            ),
        ]);

        $score = (int) round(($steps->where('completed', true)->count() / max(1, $steps->count())) * 100);
        $next = $steps->firstWhere('completed', false);
        $bannerSteps = $steps->whereIn('key', [
            'brand_profile',
            'website',
            'competitors',
            'ai_visibility_queries',
            'first_run',
        ])->values();

        $activation = [
            'workspace' => $workspace,
            'score' => $score,
            'is_active' => $steps->every(fn (array $step): bool => (bool) $step['completed']),
            'steps' => $steps,
            'banner_steps' => $bannerSteps,
            'next_action' => $next,
            'remaining_banner_steps' => $bannerSteps->where('completed', false)->count(),
            'counts' => [
                'queries' => $queryCount,
                'runs' => $runCount,
                'signal_events' => $eventCount,
                'detections' => $detectionCount,
                'opportunity_candidates' => $candidateCount,
            ],
            'quick_actions' => $this->quickActions($workspace, $site, $next, $candidateCount),
        ];

        if ($this->usesRequestCache()) {
            request()->attributes->set($cacheKey, $activation);
        }

        return $activation;
    }

    /**
     * @return array<string,mixed>
     */
    private function step(string $key, string $label, string $description, bool $completed, string $actionLabel, ?string $actionRoute): array
    {
        return [
            'key' => $key,
            'label' => $this->runtime($label),
            'description' => $this->runtime($description),
            'completed' => $completed,
            'action_label' => $this->runtime($actionLabel),
            'action_route' => $actionRoute,
        ];
    }

    /**
     * @return Collection<int,array<string,string|null>>
     */
    private function quickActions(Workspace $workspace, ?ClientSite $site, ?array $next, int $candidateCount): Collection
    {
        return collect([
            $next ? [
                'label' => $next['action_label'],
                'description' => $next['description'],
                'route' => $next['action_route'],
                'type' => 'primary',
            ] : null,
            [
                'label' => $this->runtime('Open Setup'),
                'description' => $this->runtime('Review all workspace readiness requirements.'),
                'route' => $this->route('app.setup.index', ['workspace' => $workspace->id]),
                'type' => 'secondary',
            ],
            [
                'label' => $this->runtime('Open AI Visibility'),
                'description' => $this->runtime('Create or run tracking queries.'),
                'route' => $site ? $this->route('app.sites.llm-tracking.index', $site) : null,
                'type' => 'secondary',
            ],
            [
                'label' => $this->runtime('Open Signal Intelligence'),
                'description' => $this->runtime('Review signal events, detections and opportunity candidates.'),
                'route' => $this->route('app.signal-intelligence.index', ['workspace' => $workspace->id]),
                'type' => 'secondary',
            ],
            [
                'label' => $this->runtime('Open Opportunity Review'),
                'description' => $this->runtime('Review the first opportunity candidate before moving into Opportunity Intelligence.'),
                'route' => $candidateCount > 0 ? $this->route('app.opportunity-review.index', ['workspace' => $workspace->id]) : null,
                'type' => 'secondary',
            ],
        ])->filter(fn (?array $action): bool => (bool) ($action['route'] ?? false))->values();
    }

    private function route(string $name, mixed $parameters = []): ?string
    {
        return Route::has($name) ? route($name, $parameters) : null;
    }

    private function usesRequestCache(): bool
    {
        return request()->route() !== null;
    }

    private function runtime(string $key): string
    {
        $lines = trans('app.runtime');

        return is_array($lines) ? (string) ($lines[$key] ?? $key) : $key;
    }
}
