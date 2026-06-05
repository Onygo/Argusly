<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Brand;
use App\Models\Competitor;
use App\Models\VisibilityCheck;
use App\Models\VisibilityCitation;
use App\Models\VisibilityProviderRun;
use App\Services\Visibility\CitationClassificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CitationClassificationServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_domains_are_extracted_and_normalized_from_ai_answers(): void
    {
        $service = app(CitationClassificationService::class);

        $domains = $service->domainsFromAnswer('Sources include https://www.Alpha.example/docs, rival.example/report and https://news.example/story.');

        $this->assertEqualsCanonicalizing([
            'alpha.example',
            'rival.example',
            'news.example',
        ], $domains->all());
    }

    public function test_owned_source_is_detected_and_recorded_as_evidence(): void
    {
        [$brand, $run] = $this->runForBrand('Alpha answer cites https://www.alpha.example/docs.');

        $citations = app(CitationClassificationService::class)->classifyRun($run);

        $citation = $citations->firstWhere('source_domain', 'alpha.example');
        $this->assertNotNull($citation);
        $this->assertSame(CitationClassificationService::OWNED_SOURCE, $citation->citation_type);
        $this->assertTrue($citation->is_owned_source);
        $this->assertDatabaseHas('visibility_sources', [
            'brand_id' => $brand->id,
            'domain' => 'alpha.example',
            'source_type' => CitationClassificationService::OWNED_SOURCE,
            'is_owned' => true,
        ]);
        $this->assertDatabaseHas('evidence_items', [
            'brand_id' => $brand->id,
            'subject_type' => (new VisibilityCitation())->getMorphClass(),
            'subject_id' => $citation->id,
            'evidence_type' => 'citation',
        ]);
    }

    public function test_competitor_source_is_detected(): void
    {
        [$brand, $run] = $this->runForBrand('Alpha answer cites competitor coverage at https://rival.example/playbook.');
        Competitor::query()->create([
            'account_id' => $brand->account_id,
            'brand_id' => $brand->id,
            'name' => 'RivalStack',
            'website' => 'https://www.rival.example',
            'industry' => 'AI visibility',
        ]);

        $citations = app(CitationClassificationService::class)->classifyRun($run);

        $citation = $citations->firstWhere('source_domain', 'rival.example');
        $this->assertNotNull($citation);
        $this->assertSame(CitationClassificationService::COMPETITOR_SOURCE, $citation->citation_type);
        $this->assertTrue($citation->is_competitor_source);
    }

    public function test_duplicate_sources_are_merged_in_brand_overview(): void
    {
        [$brand, $run] = $this->runForBrand('No inline links here.');
        $run->citations()->create([
            'account_id' => $run->account_id,
            'brand_id' => $run->brand_id,
            'visibility_check_id' => $run->visibility_check_id,
            'source_url' => 'https://media.example/one',
            'source_domain' => 'media.example',
            'url' => 'https://media.example/one',
            'domain' => 'media.example',
            'title' => 'Media one',
        ]);
        $run->citations()->create([
            'account_id' => $run->account_id,
            'brand_id' => $run->brand_id,
            'visibility_check_id' => $run->visibility_check_id,
            'source_url' => 'https://media.example/two',
            'source_domain' => 'media.example',
            'url' => 'https://media.example/two',
            'domain' => 'media.example',
            'title' => 'Media two',
        ]);

        $service = app(CitationClassificationService::class);
        $service->classifyRun($run);
        $overview = $service->sourceOverviewForBrand($brand);

        $source = $overview->firstWhere('domain', 'media.example');
        $this->assertNotNull($source);
        $this->assertSame(2, $source['seen_count']);
        $this->assertSame(CitationClassificationService::MEDIA_SOURCE, $source['type']);
        $this->assertSame(1, \App\Models\VisibilitySource::query()->where('brand_id', $brand->id)->where('domain', 'media.example')->count());
    }

    public function test_empty_or_unclear_citations_are_handled_as_unknown(): void
    {
        [, $run] = $this->runForBrand('');
        $citation = $run->citations()->create([
            'account_id' => $run->account_id,
            'brand_id' => $run->brand_id,
            'visibility_check_id' => $run->visibility_check_id,
            'source_url' => 'not clear',
            'url' => 'not clear',
            'title' => 'Unclear source',
        ]);

        $classified = app(CitationClassificationService::class)->classifyCitation($citation);

        $this->assertSame(CitationClassificationService::UNKNOWN_SOURCE, $classified->citation_type);
        $this->assertFalse($classified->is_owned_source);
        $this->assertFalse($classified->is_competitor_source);
        $this->assertNotNull($classified->confidence_score);
    }

    /**
     * @return array{0: Brand, 1: VisibilityProviderRun}
     */
    private function runForBrand(string $answer): array
    {
        $account = Account::query()->create(['name' => 'Alpha Account', 'slug' => fake()->unique()->slug()]);
        $brand = Brand::query()->create([
            'account_id' => $account->id,
            'name' => 'Alpha Brand',
            'slug' => fake()->unique()->slug(),
            'domain' => 'alpha.example',
            'website_url' => 'https://alpha.example',
        ]);
        $check = VisibilityCheck::query()->create([
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'provider' => 'ChatGPT',
            'brand' => $brand->name,
            'query' => 'Which AI visibility sources matter?',
            'status' => 'active',
        ]);
        $run = VisibilityProviderRun::query()->create([
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'visibility_check_id' => $check->id,
            'provider' => 'ChatGPT',
            'model' => 'test-model',
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
        ]);

        return [$brand, $run];
    }
}
