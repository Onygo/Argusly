<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Brand;
use App\Models\Competitor;
use App\Models\VisibilityCheck;
use App\Models\VisibilityProviderRun;
use App\Models\VisibilityResult;
use App\Services\Visibility\VisibilityScoreCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AiVisibilityDeepScoringTest extends TestCase
{
    use RefreshDatabase;

    public function test_brand_present_scores_answer_presence(): void
    {
        [$brand, $result] = $this->visibilityResultWithRun(
            answer: 'Alpha Brand is a strong AI visibility platform for content teams.',
        );

        $score = app(VisibilityScoreCalculator::class)->calculateForResult($result);

        $this->assertSame($brand->id, $score->brand_id);
        $this->assertGreaterThan(70, $score->answer_presence_score);
        $this->assertGreaterThan(40, $score->ai_attention_score);
        $this->assertStringContainsString('Alpha Brand is present', $score->summary);
    }

    public function test_brand_absent_scores_zero_answer_presence(): void
    {
        [, $result] = $this->visibilityResultWithRun(
            answer: 'The category includes several general monitoring tools, but no named brand is recommended.',
        );

        $score = app(VisibilityScoreCalculator::class)->calculateForResult($result);

        $this->assertSame(0, $score->answer_presence_score);
        $this->assertLessThan(40, $score->ai_attention_score);
        $this->assertStringContainsString('absent', $score->summary);
    }

    public function test_competitor_present_is_recorded_and_penalizes_attention(): void
    {
        [$brand, $result] = $this->visibilityResultWithRun(
            answer: 'Alpha Brand and RivalStack are both mentioned, but RivalStack appears more prominently.',
        );
        Competitor::query()->create([
            'account_id' => $brand->account_id,
            'brand_id' => $brand->id,
            'name' => 'RivalStack',
            'website' => 'https://rival.example',
            'industry' => 'AI visibility',
        ]);

        $score = app(VisibilityScoreCalculator::class)->calculateForResult($result);

        $this->assertGreaterThan(0, $score->competitor_presence_score);
        $this->assertDatabaseHas('visibility_competitor_snapshots', [
            'brand_id' => $brand->id,
            'competitor_name' => 'RivalStack',
            'mentions_count' => 2,
        ]);
    }

    public function test_citation_present_scores_citation_intelligence(): void
    {
        [, $result, $run] = $this->visibilityResultWithRun(
            answer: 'Alpha Brand is cited by independent market coverage.',
        );
        $run->citations()->create([
            'account_id' => $run->account_id,
            'brand_id' => $run->brand_id,
            'visibility_check_id' => $run->visibility_check_id,
            'source_url' => 'https://analyst.example/ai-visibility',
            'source_domain' => 'analyst.example',
            'source_title' => 'AI visibility analysis',
            'url' => 'https://analyst.example/ai-visibility',
            'domain' => 'analyst.example',
            'title' => 'AI visibility analysis',
            'rank' => 1,
            'trust_score' => 88,
            'confidence_score' => 88,
        ]);

        $score = app(VisibilityScoreCalculator::class)->calculateForResult($result);

        $this->assertGreaterThan(25, $score->citation_score);
        $this->assertGreaterThan(80, $score->authority_score);
        $this->assertDatabaseHas('visibility_sources', [
            'brand_id' => $run->brand_id,
            'domain' => 'analyst.example',
            'source_type' => \App\Services\Visibility\CitationClassificationService::NEUTRAL_SOURCE,
        ]);
    }

    public function test_owned_source_present_scores_source_presence(): void
    {
        [$brand, $result, $run] = $this->visibilityResultWithRun(
            answer: 'Alpha Brand is supported by its own documentation.',
        );
        $run->citations()->create([
            'account_id' => $run->account_id,
            'brand_id' => $run->brand_id,
            'visibility_check_id' => $run->visibility_check_id,
            'source_url' => 'https://alpha.example/docs/visibility',
            'source_domain' => 'alpha.example',
            'source_title' => 'Alpha visibility docs',
            'url' => 'https://alpha.example/docs/visibility',
            'domain' => 'alpha.example',
            'title' => 'Alpha visibility docs',
            'rank' => 1,
            'trust_score' => 92,
            'confidence_score' => 92,
        ]);

        $score = app(VisibilityScoreCalculator::class)->calculateForResult($result);

        $this->assertGreaterThan(70, $score->source_presence_score);
        $this->assertDatabaseHas('visibility_sources', [
            'brand_id' => $brand->id,
            'domain' => 'alpha.example',
            'is_owned' => true,
        ]);
        $this->assertDatabaseHas('visibility_citations', [
            'brand_id' => $brand->id,
            'source_domain' => 'alpha.example',
            'is_owned_source' => true,
        ]);
    }

    public function test_scores_are_stored_per_provider_and_model(): void
    {
        [, $chatResult] = $this->visibilityResultWithRun(
            provider: 'ChatGPT',
            model: 'gpt-test',
            answer: 'Alpha Brand is visible in ChatGPT.',
        );
        [, $claudeResult] = $this->visibilityResultWithRun(
            provider: 'Claude',
            model: 'claude-test',
            answer: 'Alpha Brand is visible in Claude.',
            reuseTenantFrom: $chatResult,
        );

        app(VisibilityScoreCalculator::class)->calculateForResult($chatResult);
        app(VisibilityScoreCalculator::class)->calculateForResult($claudeResult);

        $this->assertDatabaseHas('visibility_scores', [
            'brand_id' => $chatResult->brand_id,
            'provider' => 'ChatGPT',
            'model' => 'gpt-test',
        ]);
        $this->assertDatabaseHas('visibility_scores', [
            'brand_id' => $chatResult->brand_id,
            'provider' => 'Claude',
            'model' => 'claude-test',
        ]);
        $this->assertDatabaseHas('visibility_trends', [
            'brand_id' => $chatResult->brand_id,
            'provider' => 'ChatGPT',
            'scores_count' => 1,
        ]);
        $this->assertDatabaseHas('visibility_trends', [
            'brand_id' => $chatResult->brand_id,
            'provider' => 'Claude',
            'scores_count' => 1,
        ]);
    }

    public function test_empty_ai_response_scores_low_and_does_not_fail(): void
    {
        [, $result] = $this->visibilityResultWithRun(answer: '');

        $score = app(VisibilityScoreCalculator::class)->calculateForResult($result);

        $this->assertSame(0, $score->answer_presence_score);
        $this->assertSame(0, $score->citation_score);
        $this->assertSame(0, $score->source_presence_score);
        $this->assertLessThanOrEqual(10, $score->ai_attention_score);
    }

    /**
     * @return array{0: Brand, 1: VisibilityResult, 2: VisibilityProviderRun}
     */
    private function visibilityResultWithRun(
        string $provider = 'ChatGPT',
        string $model = 'test-model',
        string $answer = 'Alpha Brand is visible.',
        ?VisibilityResult $reuseTenantFrom = null,
    ): array {
        if ($reuseTenantFrom) {
            $account = $reuseTenantFrom->account;
            $brand = $reuseTenantFrom->brandModel;
        } else {
            $account = Account::query()->create(['name' => 'Alpha Account', 'slug' => fake()->unique()->slug()]);
            $brand = Brand::query()->create([
                'account_id' => $account->id,
                'name' => 'Alpha Brand',
                'slug' => fake()->unique()->slug(),
                'domain' => 'alpha.example',
                'website_url' => 'https://alpha.example',
            ]);
        }

        $check = VisibilityCheck::query()->create([
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'provider' => $provider,
            'brand' => $brand->name,
            'query' => 'Which AI visibility platforms should content teams evaluate?',
            'status' => 'active',
        ]);

        $result = VisibilityResult::query()->create([
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'visibility_check_id' => $check->id,
            'provider' => $provider,
            'query' => $check->query,
            'language' => 'en',
            'locale' => 'en_US',
            'market' => 'US',
            'brand' => $brand->name,
            'score' => null,
            'position' => null,
            'mention_found' => str_contains($answer, $brand->name),
            'metadata' => ['answer' => $answer],
            'captured_at' => now(),
        ]);

        $run = VisibilityProviderRun::query()->create([
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'visibility_check_id' => $check->id,
            'provider' => $provider,
            'model' => $model,
            'query' => $check->query,
            'language' => 'en',
            'locale' => 'en_US',
            'market' => 'US',
            'input_language' => 'en',
            'normalized_answer_language' => 'en',
            'raw_response' => $answer,
            'normalized_answer' => $answer,
            'status' => 'completed',
            'captured_at' => now(),
            'metadata' => ['result_id' => $result->id],
        ]);

        return [$brand, $result, $run];
    }
}
