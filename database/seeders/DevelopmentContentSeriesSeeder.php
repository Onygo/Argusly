<?php

namespace Database\Seeders;

use App\Models\ClientSite;
use App\Models\ContentSeries;
use App\Models\Organization;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class DevelopmentContentSeriesSeeder extends Seeder
{
    public function run(): void
    {
        $organization = Organization::query()->where('slug', 'demo-org')->first();
        if (! $organization) {
            return;
        }

        $workspace = Workspace::query()
            ->where('organization_id', $organization->id)
            ->orderBy('created_at')
            ->first();

        if (! $workspace) {
            return;
        }

        $site = ClientSite::query()
            ->where('workspace_id', $workspace->id)
            ->orderBy('created_at')
            ->first();

        if (! $site) {
            return;
        }

        $creatorId = User::query()
            ->where('organization_id', $organization->id)
            ->orderBy('id')
            ->value('id');

        ContentSeries::query()->firstOrCreate(
            [
                'organization_id' => $organization->id,
                'site_id' => $site->id,
                'name' => 'Demo Chained Content Engine',
            ],
            [
                'id' => (string) Str::uuid(),
                'main_topic' => 'AI content governance for B2B SaaS teams',
                'primary_keyword' => 'ai content governance',
                'supporting_keywords' => [
                    'content governance framework',
                    'ai workflow compliance',
                    'brand voice governance',
                    'editorial review automation',
                ],
                'audience' => 'Marketing and content leads in B2B SaaS',
                'tone' => 'clear, pragmatic, strategic',
                'funnel_stage' => 'consideration',
                'articles_count' => 5,
                'status' => 'strategy_ready',
                'strategy_json' => [
                    'angle' => 'Guide readers from governance foundations to operational rollout.',
                    'articles' => [
                        [
                            'article_number' => 1,
                            'title' => 'AI content governance fundamentals for SaaS teams',
                            'primary_keyword' => 'ai content governance fundamentals',
                            'secondary_keywords' => ['governance model', 'content compliance'],
                            'internal_links_to' => [2, 3],
                        ],
                        [
                            'article_number' => 2,
                            'title' => 'How to build a content governance framework that scales',
                            'primary_keyword' => 'content governance framework',
                            'secondary_keywords' => ['workflow ownership', 'editorial operations'],
                            'internal_links_to' => [1, 4],
                        ],
                        [
                            'article_number' => 3,
                            'title' => 'Brand voice controls in AI-assisted content production',
                            'primary_keyword' => 'brand voice governance',
                            'secondary_keywords' => ['voice consistency', 'style enforcement'],
                            'internal_links_to' => [1, 5],
                        ],
                        [
                            'article_number' => 4,
                            'title' => 'Operational review loops for AI-generated drafts',
                            'primary_keyword' => 'editorial review automation',
                            'secondary_keywords' => ['quality assurance', 'review handoffs'],
                            'internal_links_to' => [2, 5],
                        ],
                        [
                            'article_number' => 5,
                            'title' => 'Measuring ROI of governed AI content workflows',
                            'primary_keyword' => 'ai content workflow roi',
                            'secondary_keywords' => ['performance metrics', 'content velocity'],
                            'internal_links_to' => [3, 4],
                        ],
                    ],
                ],
                'created_by' => $creatorId,
            ]
        );
    }
}
