<?php

namespace Database\Seeders;

use App\Models\LlmSourceRule;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Cache;

class LlmSourceRulesSeeder extends Seeder
{
    public function run(): void
    {
        $rules = [
            ['type' => 'wikipedia', 'domain_pattern' => '*.wikipedia.org', 'priority' => 10],
            ['type' => 'blog', 'domain_pattern' => 'blog.*', 'priority' => 20],
            ['type' => 'blog', 'domain_pattern' => '*medium.com*', 'priority' => 25],
            ['type' => 'blog', 'domain_pattern' => '*substack.com*', 'priority' => 25],
            ['type' => 'news', 'domain_pattern' => '*.reuters.com', 'priority' => 30],
            ['type' => 'news', 'domain_pattern' => '*.bloomberg.com', 'priority' => 30],
            ['type' => 'news', 'domain_pattern' => '*.nytimes.com', 'priority' => 30],
            ['type' => 'news', 'domain_pattern' => '*.wsj.com', 'priority' => 30],
            ['type' => 'docs', 'domain_pattern' => 'docs.*', 'priority' => 40],
            ['type' => 'forum', 'domain_pattern' => '*.reddit.com', 'priority' => 50],
            ['type' => 'forum', 'domain_pattern' => '*.stackoverflow.com', 'priority' => 50],
            ['type' => 'forum', 'domain_pattern' => '*.stackexchange.com', 'priority' => 50],
        ];

        foreach ($rules as $rule) {
            LlmSourceRule::query()->updateOrCreate(
                [
                    'type' => (string) $rule['type'],
                    'domain_pattern' => (string) $rule['domain_pattern'],
                ],
                ['priority' => (int) $rule['priority']]
            );
        }

        Cache::forget('llm_tracking_source_rules.v1');
    }
}
