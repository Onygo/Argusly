<?php

namespace Database\Seeders;

use App\Models\Account;
use App\Models\Brand;
use App\Services\IntelligenceSignalService;
use Illuminate\Database\Seeder;

class IntelligenceSignalSeeder extends Seeder
{
    /**
     * Seed realistic demo intelligence signals.
     */
    public function run(): void
    {
        $service = app(IntelligenceSignalService::class);

        Account::query()
            ->with('brands')
            ->get()
            ->each(function (Account $account) use ($service): void {
                $brand = $account->brands->first();

                $this->create($service, $account, $brand, [
                    'source' => 'demo.visibility',
                    'type' => 'visibility_change',
                    'title' => 'AI visibility rose for buying-intent prompts',
                    'summary' => 'The brand appeared more often in assistant responses for high-intent category prompts over the last week.',
                    'impact_score' => 82,
                    'confidence_score' => 91,
                    'status' => 'new',
                    'recommended_action' => 'Review the winning prompts and turn them into a reusable content brief.',
                    'payload' => ['surface' => 'ai_answers', 'change' => '+14%'],
                    'detected_at' => now()->subHours(6),
                ]);

                $this->create($service, $account, $brand, [
                    'source' => 'demo.content',
                    'type' => 'content_opportunity',
                    'title' => 'Comparison article gap found',
                    'summary' => 'Competitors are being cited for comparison topics where this brand has no dedicated landing page.',
                    'impact_score' => 74,
                    'confidence_score' => 87,
                    'status' => 'reviewed',
                    'recommended_action' => 'Create a comparison page focused on decision criteria and proof points.',
                    'payload' => ['topic' => 'comparison', 'estimated_effort' => 'medium'],
                    'detected_at' => now()->subDay(),
                ]);

                $this->create($service, $account, null, [
                    'source' => 'demo.integration',
                    'type' => 'integration_event',
                    'title' => 'Search integration sync completed',
                    'summary' => 'A connected search data source completed its latest sync and is ready for analysis.',
                    'impact_score' => 42,
                    'confidence_score' => 95,
                    'status' => 'resolved',
                    'recommended_action' => null,
                    'payload' => ['provider' => 'google', 'records' => 1284],
                    'detected_at' => now()->subDays(2),
                    'resolved_at' => now()->subDays(2)->addMinutes(12),
                ]);
            });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function create(IntelligenceSignalService $service, Account $account, ?Brand $brand, array $attributes): void
    {
        $service->create($account, $attributes, $brand);
    }
}
