<?php

namespace App\Providers;

use App\Agents\AgentOrchestrator;
use App\Agents\AgentWorkflowOrchestrator;
use App\Agents\ContentRefresh\ContentRefreshAgent;
use App\Agents\Drafts\DraftSmartSuggestionsAgent;
use App\Agents\InternalLinking\InternalLinkingAgent;
use App\Agents\Localization\LocalizationAgent;
use App\Agents\Workflows\DraftPostProcessingWorkflow;
use App\Agents\Workflows\PublishedContentOptimizationWorkflow;
use Illuminate\Support\ServiceProvider;

class AgentServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(AgentOrchestrator::class);
        $this->app->singleton(AgentWorkflowOrchestrator::class);
        $this->app->singleton(DraftSmartSuggestionsAgent::class);
        $this->app->singleton(ContentRefreshAgent::class);
        $this->app->singleton(InternalLinkingAgent::class);
        $this->app->singleton(LocalizationAgent::class);
        $this->app->singleton(DraftPostProcessingWorkflow::class);
        $this->app->singleton(PublishedContentOptimizationWorkflow::class);
    }
}
