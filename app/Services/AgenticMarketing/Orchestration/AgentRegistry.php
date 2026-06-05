<?php

namespace App\Services\AgenticMarketing\Orchestration;

use App\Services\AgenticMarketing\Orchestration\Agents\AeoAnswerEngineAgent;
use App\Services\AgenticMarketing\Orchestration\Agents\BuyerIntentAnalystAgent;
use App\Services\AgenticMarketing\Orchestration\Agents\CampaignPlannerAgent;
use App\Services\AgenticMarketing\Orchestration\Agents\CompetitorAnalystAgent;
use App\Services\AgenticMarketing\Orchestration\Agents\ContentStrategistAgent;
use App\Services\AgenticMarketing\Orchestration\Agents\InternalLinkingAgent;
use App\Services\AgenticMarketing\Orchestration\Agents\LifecycleOptimizerAgent;
use App\Services\AgenticMarketing\Orchestration\Agents\SeoStrategistAgent;
use App\Services\AgenticMarketing\Orchestration\Contracts\MarketingAgent;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Collection;
use InvalidArgumentException;

class AgentRegistry
{
    /**
     * @var array<int,class-string<MarketingAgent>>
     */
    private array $agents = [
        SeoStrategistAgent::class,
        ContentStrategistAgent::class,
        CompetitorAnalystAgent::class,
        BuyerIntentAnalystAgent::class,
        CampaignPlannerAgent::class,
        LifecycleOptimizerAgent::class,
        InternalLinkingAgent::class,
        AeoAnswerEngineAgent::class,
    ];

    public function __construct(private readonly Container $container) {}

    /**
     * @return Collection<int,MarketingAgent>
     */
    public function all(): Collection
    {
        return collect($this->agents)
            ->map(fn (string $class): MarketingAgent => $this->container->make($class))
            ->filter(fn (MarketingAgent $agent): bool => $agent->definition()->enabled)
            ->values();
    }

    public function get(string $key): MarketingAgent
    {
        $agent = $this->all()->first(fn (MarketingAgent $agent): bool => $agent->definition()->key === $key);

        if (! $agent) {
            throw new InvalidArgumentException('Unknown Agentic Marketing agent: '.$key);
        }

        return $agent;
    }

    public function definitions(): array
    {
        return $this->all()
            ->map(fn (MarketingAgent $agent): array => $agent->definition()->toArray())
            ->all();
    }

    public function keys(): array
    {
        return $this->all()
            ->map(fn (MarketingAgent $agent): string => $agent->definition()->key)
            ->all();
    }
}
