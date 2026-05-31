<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Brand;
use App\Models\Mention;
use App\Models\Source;
use App\Services\EvidenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EvidenceLayerTest extends TestCase
{
    use RefreshDatabase;

    public function test_evidence_can_attach_to_tenant_scoped_subjects(): void
    {
        [$account, $brand] = $this->tenant();
        $source = Source::query()->create([
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'name' => 'RSS corpus',
            'type' => 'blog',
            'provider' => 'rss',
            'status' => 'active',
        ]);
        $mention = Mention::query()->create([
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'source_id' => $source->id,
            'title' => 'Evidence-backed mention',
            'sentiment' => 'positive',
        ]);

        $evidence = app(EvidenceService::class)->createForSubject($mention, [
            'source_id' => $source->id,
            'evidence_type' => 'web_page',
            'title' => 'Original page',
            'url' => 'https://example.test/evidence',
            'snippet' => 'The page mentions the brand.',
            'confidence_score' => 91,
        ]);

        $this->assertSame($account->id, $evidence->account_id);
        $this->assertSame($brand->id, $evidence->brand_id);
        $this->assertSame($evidence->id, $mention->evidenceItems()->firstOrFail()->id);
    }

    public function test_evidence_rejects_cross_account_sources(): void
    {
        [$account, $brand] = $this->tenant();
        [$otherAccount, $otherBrand] = $this->tenant('Other Account', 'other-account');
        $otherSource = Source::query()->create([
            'account_id' => $otherAccount->id,
            'brand_id' => $otherBrand->id,
            'name' => 'Other RSS',
            'type' => 'blog',
            'provider' => 'rss',
            'status' => 'active',
        ]);
        $mention = Mention::query()->create([
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'title' => 'Tenant safe mention',
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Evidence source must belong to the same account.');

        app(EvidenceService::class)->createForSubject($mention, [
            'source_id' => $otherSource->id,
            'evidence_type' => 'web_page',
        ]);
    }

    /**
     * @return array{Account, Brand}
     */
    private function tenant(string $name = 'Evidence Account', string $slug = 'evidence-account'): array
    {
        $account = Account::query()->create(['name' => $name, 'slug' => $slug]);
        $brand = Brand::query()->create([
            'account_id' => $account->id,
            'name' => "{$name} Brand",
            'slug' => "{$slug}-brand",
        ]);

        return [$account, $brand];
    }
}
