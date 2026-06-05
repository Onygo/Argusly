<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Agent;
use App\Models\AgentRun;
use App\Models\Brand;
use App\Models\Recommendation;
use Illuminate\Support\Collection;

class AgentManager
{
    /**
     * @return Collection<int, Agent>
     */
    public function agents(): Collection
    {
        $this->ensureDefaultAgents();

        return Agent::query()
            ->withCount(['runs', 'tasks'])
            ->orderBy('name')
            ->get();
    }

    /**
     * @return Collection<int, AgentRun>
     */
    public function latestRuns(Account $account, ?Brand $brand = null, int $limit = 8): Collection
    {
        return AgentRun::query()
            ->where('account_id', $account->id)
            ->when(
                $brand !== null,
                fn ($query) => $query->where(fn ($scope) => $scope->whereNull('brand_id')->orWhere('brand_id', $brand->id)),
                fn ($query) => $query->whereNull('brand_id'),
            )
            ->with(['agent', 'brand', 'tasks'])
            ->recent()
            ->limit($limit)
            ->get();
    }

    /**
     * @return Collection<int, Recommendation>
     */
    public function latestRecommendations(Account $account, ?Brand $brand = null, int $limit = 6): Collection
    {
        return app(RecommendationService::class)->recentForTenant($account, $brand, $limit);
    }

    public function findAgent(string $key): Agent
    {
        $this->ensureDefaultAgents();

        return Agent::query()->where('key', $key)->firstOrFail();
    }

    public function ensureDefaultAgents(): void
    {
        foreach ($this->definitions() as $definition) {
            Agent::query()->updateOrCreate(
                ['key' => $definition['key']],
                [
                    'name' => $definition['name'],
                    'description' => $definition['description'],
                    'status' => $definition['status'],
                    'capabilities' => $definition['capabilities'],
                ],
            );
        }
    }

    /**
     * @return array<int, array{key: string, name: string, description: string, status: string, capabilities: array<int, string>}>
     */
    public function definitions(): array
    {
        return [
            ['key' => 'content', 'name' => 'Content Agent', 'description' => 'Prepares briefs, drafts and content refresh work from approved recommendations.', 'status' => 'idle', 'capabilities' => ['content_generation', 'briefing', 'refresh_planning']],
            ['key' => 'seo', 'name' => 'SEO Agent', 'description' => 'Turns visibility, audit and lifecycle findings into search-focused tasks.', 'status' => 'idle', 'capabilities' => ['technical_review', 'metadata', 'query_mapping']],
            ['key' => 'visibility', 'name' => 'Visibility Agent', 'description' => 'Monitors provider visibility checks and prepares follow-up actions.', 'status' => 'idle', 'capabilities' => ['ai_visibility_tracking', 'serp_tracking', 'snapshot_review']],
            ['key' => 'research', 'name' => 'Research Agent', 'description' => 'Builds knowledge graph context, topics and source research packages.', 'status' => 'idle', 'capabilities' => ['entity_research', 'topic_mapping', 'source_collection']],
            ['key' => 'social', 'name' => 'Social Agent', 'description' => 'Repurposes content and recommendations into social campaign tasks.', 'status' => 'idle', 'capabilities' => ['linkedin_posts', 'social_variants', 'campaign_handoffs']],
            ['key' => 'campaign', 'name' => 'Campaign Agent', 'description' => 'Coordinates multi-step campaign work across content, social and reporting.', 'status' => 'idle', 'capabilities' => ['campaign_planning', 'handoffs', 'qa']],
            ['key' => 'lifecycle', 'name' => 'Lifecycle Agent', 'description' => 'Watches lifecycle scores and schedules refresh or audit work.', 'status' => 'idle', 'capabilities' => ['content_decay', 'refresh_priority', 'audit_requests']],
            ['key' => 'competitor', 'name' => 'Competitor Agent', 'description' => 'Tracks competitor intelligence signals and prepares comparison tasks.', 'status' => 'idle', 'capabilities' => ['competitor_snapshots', 'gap_detection', 'market_watch']],
            ['key' => 'monitoring', 'name' => 'Monitoring Agent', 'description' => 'Tracks integration health and prepares reconnect or recovery tasks.', 'status' => 'idle', 'capabilities' => ['integration_health', 'reconnect_handoffs', 'alert_review']],
        ];
    }
}
