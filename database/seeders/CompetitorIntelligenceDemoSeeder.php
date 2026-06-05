<?php

namespace Database\Seeders;

use App\Models\SiteCompetitor;
use App\Models\Workspace;
use App\Services\CompetitorIntelligence\CompetitorContentImportPipeline;
use App\Services\CompetitorIntelligence\CompetitorIntelligenceAnalyzer;
use Illuminate\Database\Seeder;

class CompetitorIntelligenceDemoSeeder extends Seeder
{
    public function run(CompetitorContentImportPipeline $pipeline, CompetitorIntelligenceAnalyzer $analyzer): void
    {
        $workspace = Workspace::query()->with('clientSites')->first();
        $site = $workspace?->clientSites()->first();

        if (! $workspace || ! $site) {
            return;
        }

        $competitor = SiteCompetitor::query()->firstOrCreate(
            [
                'workspace_id' => (string) $workspace->id,
                'client_site_id' => (string) $site->id,
                'domain' => 'compass-content.example',
            ],
            [
                'name' => 'Compass Content',
                'notes' => 'Demo competitor for internal intelligence examples.',
                'is_active' => true,
            ]
        );

        foreach ($this->demoItems() as $item) {
            $pipeline->import($competitor, $item, 'demo_seed');
        }

        $analyzer->analyze($workspace, $competitor, ['source' => 'demo_seed']);
    }

    private function demoItems(): array
    {
        return [
            [
                'url' => 'https://compass-content.example/ai-visibility-implementation-guide',
                'title' => 'AI Visibility Implementation Guide for Content Teams',
                'meta_description' => 'A practical workflow for AI visibility, answer blocks, and entity-rich content.',
                'content_excerpt' => 'This implementation guide explains how content teams configure AI visibility workflows, answer blocks, schema, and recurring topic refreshes.',
            ],
            [
                'url' => 'https://compass-content.example/publishlayer-alternatives',
                'title' => 'Best PublishLayer Alternatives for Agentic Marketing',
                'meta_description' => 'Compare agentic marketing platforms, pricing, features, and implementation tradeoffs.',
                'content_excerpt' => 'A comparison page for PublishLayer alternatives, pricing, demos, AI SEO workflows, competitor tracking, and content opportunity planning.',
            ],
            [
                'url' => 'https://compass-content.example/use-cases/b2b-saas-content-ops',
                'title' => 'B2B SaaS Content Operations Use Case',
                'meta_description' => 'How SaaS teams use AI content operations to find gaps and ship BOFU pages.',
                'content_excerpt' => 'This use case shows B2B SaaS teams turning competitor topics into landing pages, implementation guides, and answer blocks.',
            ],
        ];
    }
}
