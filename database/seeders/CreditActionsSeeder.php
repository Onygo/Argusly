<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CreditActionsSeeder extends Seeder
{
    public function run(): void
    {
        $rows = [
            [
                'key' => 'content.article',
                'category' => 'content',
                'credits_cost' => 10,
                'label_nl' => 'Content generatie: artikel',
                'label_en' => 'Content generation: article',
                'meta' => ['notes' => 'Standaard artikel output'],
            ],
            [
                'key' => 'content.faq_set',
                'category' => 'content',
                'credits_cost' => 6,
                'label_nl' => 'Content generatie: FAQ set',
                'label_en' => 'Content generation: FAQ set',
                'meta' => ['notes' => 'Set met FAQs'],
            ],
            [
                'key' => 'content.brief',
                'category' => 'content',
                'credits_cost' => 4,
                'label_nl' => 'Content generatie: brief',
                'label_en' => 'Content generation: brief',
                'meta' => ['notes' => 'Korte briefing'],
            ],
            [
                'key' => 'content.outline',
                'category' => 'content',
                'credits_cost' => 3,
                'label_nl' => 'Content generatie: outline',
                'label_en' => 'Content generation: outline',
                'meta' => ['notes' => 'Outline of structuur'],
            ],
            [
                'key' => 'rewrite.refresh',
                'category' => 'rewrite',
                'credits_cost' => 4,
                'label_nl' => 'Rewrite: refresh',
                'label_en' => 'Rewrite: refresh',
                'meta' => ['notes' => 'Opfrissen zonder grote wijziging'],
            ],
            [
                'key' => 'rewrite.restructure',
                'category' => 'rewrite',
                'credits_cost' => 5,
                'label_nl' => 'Rewrite: herstructureren',
                'label_en' => 'Rewrite: restructure',
                'meta' => ['notes' => 'Herindelen en verbeteren van flow'],
            ],
            [
                'key' => 'rewrite.tone_shift',
                'category' => 'rewrite',
                'credits_cost' => 3,
                'label_nl' => 'Rewrite: tone shift',
                'label_en' => 'Rewrite: tone shift',
                'meta' => ['notes' => 'Aanpassen tone of voice'],
            ],
            [
                'key' => 'rewrite.cta_variants',
                'category' => 'rewrite',
                'credits_cost' => 2,
                'label_nl' => 'Rewrite: CTA varianten',
                'label_en' => 'Rewrite: CTA variants',
                'meta' => ['notes' => 'Meerdere CTA varianten genereren'],
            ],
            [
                'key' => 'translate.locale_version',
                'category' => 'translate',
                'credits_cost' => 6,
                'label_nl' => 'Vertaling: taalversie',
                'label_en' => 'Translation: locale version',
                'meta' => ['requires' => ['locale']],
            ],
            [
                'key' => 'video.short_script',
                'category' => 'video',
                'credits_cost' => 4,
                'label_nl' => 'Video: kort script',
                'label_en' => 'Video: short script',
                'meta' => ['notes' => 'Uitlegscript voor clip of voice over'],
            ],
            [
                'key' => 'draft.analysis',
                'category' => 'draft',
                'credits_cost' => 1,
                'label_nl' => 'Draft intelligence: analyse',
                'label_en' => 'Draft intelligence: analysis',
                'meta' => [
                    'display_credits_cost' => 0.2,
                    'transaction_type' => 'draft_analysis',
                    'billing_note' => 'Fractional draft pricing requested; stored on top of integer wallet units.',
                ],
            ],
            [
                'key' => 'draft.improvement',
                'category' => 'draft',
                'credits_cost' => 1,
                'label_nl' => 'Draft intelligence: verbetering',
                'label_en' => 'Draft intelligence: improvement',
                'meta' => [
                    'display_credits_cost' => 0.5,
                    'transaction_type' => 'draft_improvement',
                    'billing_note' => 'Fractional draft pricing requested; stored on top of integer wallet units.',
                ],
            ],
        ];

        $now = now();

        $payload = array_map(function (array $r) use ($now) {
            return [
                'id' => (string) Str::uuid(),
                'key' => $r['key'],
                'category' => $r['category'],
                'credits_cost' => $r['credits_cost'],
                'label_nl' => $r['label_nl'],
                'label_en' => $r['label_en'],
                'is_active' => true,
                'meta' => json_encode($r['meta'] ?? new \stdClass()),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }, $rows);

        DB::table('credit_actions')->upsert(
            $payload,
            ['key'],
            ['category', 'credits_cost', 'label_nl', 'label_en', 'is_active', 'meta', 'updated_at']
        );
    }
}
