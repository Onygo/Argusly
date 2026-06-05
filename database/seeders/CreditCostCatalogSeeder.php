<?php

namespace Database\Seeders;

use App\Models\CreditCostCatalog;
use Illuminate\Database\Seeder;

class CreditCostCatalogSeeder extends Seeder
{
    /**
     * Seed the central credit cost catalog.
     */
    public function run(): void
    {
        foreach ($this->catalog() as $code => $definition) {
            CreditCostCatalog::query()->updateOrCreate(
                ['code' => $code],
                [
                    'name' => $definition['name'],
                    'description' => $definition['description'] ?? null,
                    'category' => $definition['category'],
                    'default_cost' => $definition['default_cost'],
                    'minimum_cost' => $definition['minimum_cost'] ?? null,
                    'maximum_cost' => $definition['maximum_cost'] ?? null,
                    'cost_type' => $definition['cost_type'] ?? 'fixed',
                    'status' => 'active',
                    'metadata' => $definition['metadata'] ?? null,
                ],
            );
        }
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function catalog(): array
    {
        return [
            'blog_generation' => ['name' => 'Blog Generation', 'category' => 'content', 'default_cost' => 100],
            'content_generation' => [
                'name' => 'Content Generation',
                'description' => 'General first draft and generated asset creation.',
                'category' => 'content',
                'default_cost' => 100,
                'metadata' => ['canonical_code' => 'blog_generation'],
            ],
            'landing_page_generation' => ['name' => 'Landing Page Generation', 'category' => 'content', 'default_cost' => 125],
            'newsletter_generation' => ['name' => 'Newsletter Generation', 'category' => 'newsletter', 'default_cost' => 75],
            'social_post_generation' => ['name' => 'Social Post Generation', 'category' => 'social', 'default_cost' => 25],
            'answer_block_generation' => ['name' => 'Answer Block Generation', 'category' => 'content', 'default_cost' => 25],
            'faq_generation' => ['name' => 'FAQ Generation', 'category' => 'content', 'default_cost' => 30],
            'translation' => [
                'name' => 'Translation',
                'category' => 'translation',
                'default_cost' => 50,
                'cost_type' => 'variable',
                'metadata' => [
                    'variable_rules' => [
                        'base_cost' => 50,
                        'unit' => '1000_words',
                        'additional_cost' => 0,
                        'status' => 'planned',
                    ],
                ],
            ],
            'content_audit' => ['name' => 'Content Audit', 'category' => 'content', 'default_cost' => 25],
            'lifecycle_check' => ['name' => 'Lifecycle Check', 'category' => 'monitoring', 'default_cost' => 10],
            'visibility_check' => [
                'name' => 'Visibility Check',
                'category' => 'visibility',
                'default_cost' => 15,
                'cost_type' => 'variable',
                'metadata' => [
                    'variable_rules' => [
                        'base_cost' => 15,
                        'unit' => 'provider',
                        'additional_cost' => 0,
                        'status' => 'planned',
                    ],
                ],
            ],
            'prompt_run' => ['name' => 'Prompt Run', 'category' => 'visibility', 'default_cost' => 10],
            'citation_analysis' => ['name' => 'Citation Analysis', 'category' => 'visibility', 'default_cost' => 5],
            'competitor_analysis' => ['name' => 'Competitor Analysis', 'category' => 'monitoring', 'default_cost' => 20],
            'agent_task' => [
                'name' => 'Agent Task',
                'category' => 'agent',
                'default_cost' => 50,
                'cost_type' => 'variable',
                'metadata' => [
                    'variable_rules' => [
                        'base_cost' => 50,
                        'unit' => 'llm_usage',
                        'additional_cost' => 0,
                        'status' => 'planned',
                    ],
                ],
            ],
            'marketing_plan_generation' => ['name' => 'Marketing Plan Generation', 'category' => 'agent', 'default_cost' => 150],
            'url_to_draft' => ['name' => 'URL To Draft', 'category' => 'content', 'default_cost' => 75],
            'content_chain_execution' => ['name' => 'Content Chain Execution', 'category' => 'agent', 'default_cost' => 200],
            'newsletter_send' => ['name' => 'Newsletter Send', 'category' => 'newsletter', 'default_cost' => 1],
            'social_publish' => ['name' => 'Social Publish', 'category' => 'social', 'default_cost' => 5],
        ];
    }
}
