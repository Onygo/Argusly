<?php

use App\Models\ClientSite;
use App\Models\Content;
use App\Models\ContentImage;
use App\Models\CreditLedgerEntry;
use App\Models\Organization;
use App\Models\Workspace;
use App\Services\CreditWalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('refunds failed content image generations without output and is idempotent', function () {
    [$site, $imageA, $imageB, $imageWithOutput] = createFailedImageRefundFixtures();
    $wallets = app(CreditWalletService::class);

    $before = $wallets->getSummary((string) $site->id);
    expect((int) $before['available'])->toBe(2);

    $dryRunExit = Artisan::call('credits:refund-failed-generations', [
        '--workspace' => (string) $site->workspace_id,
        '--dry-run' => true,
    ]);
    expect($dryRunExit)->toBe(0);

    $afterDryRun = $wallets->getSummary((string) $site->id);
    expect((int) $afterDryRun['available'])->toBe(2);

    $exit = Artisan::call('credits:refund-failed-generations', [
        '--workspace' => (string) $site->workspace_id,
    ]);
    expect($exit)->toBe(0);

    $afterFirstRun = $wallets->getSummary((string) $site->id);
    expect((int) $afterFirstRun['available'])->toBe(14);

    expect(ContentImage::query()->findOrFail($imageA->id)->credit_status)->toBe('released');
    expect(ContentImage::query()->findOrFail($imageB->id)->credit_status)->toBe('released');
    expect(ContentImage::query()->findOrFail($imageWithOutput->id)->credit_status)->toBe('committed');

    expect(CreditLedgerEntry::query()
        ->where('source_type', ContentImage::class)
        ->whereIn('source_id', [(string) $imageA->id, (string) $imageB->id])
        ->where('type', CreditWalletService::TYPE_REFUND)
        ->count())->toBe(2);

    Artisan::call('credits:refund-failed-generations', [
        '--workspace' => (string) $site->workspace_id,
    ]);

    $afterSecondRun = $wallets->getSummary((string) $site->id);
    expect((int) $afterSecondRun['available'])->toBe(14);

    expect(CreditLedgerEntry::query()
        ->where('source_type', ContentImage::class)
        ->whereIn('source_id', [(string) $imageA->id, (string) $imageB->id])
        ->where('type', CreditWalletService::TYPE_REFUND)
        ->count())->toBe(2);
});

/**
 * @return array{0:ClientSite,1:ContentImage,2:ContentImage,3:ContentImage}
 */
function createFailedImageRefundFixtures(): array
{
    $organization = Organization::query()->create([
        'name' => 'Image Repair Org',
        'slug' => 'image-repair-org-'.Str::random(6),
        'status' => 'active',
        'approved_at' => now(),
    ]);

    $workspace = Workspace::query()->create([
        'name' => 'Image Repair Workspace',
        'organization_id' => $organization->id,
    ]);

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => 'wordpress',
        'name' => 'Image Repair Site',
        'site_url' => 'https://image-repair.example.com',
        'allowed_domains' => ['image-repair.example.com'],
        'is_active' => true,
    ]);

    $content = Content::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'title' => 'Image Repair Content',
        'type' => 'article',
        'status' => 'draft',
        'source' => 'wp',
    ]);

    $wallets = app(CreditWalletService::class);
    $wallets->addCredits(
        clientSiteId: (string) $site->id,
        amount: 20,
        type: CreditWalletService::TYPE_ALLOWANCE,
    );

    $imageA = ContentImage::query()->create([
        'id' => (string) Str::uuid(),
        'content_id' => $content->id,
        'type' => 'featured',
        'status' => 'failed',
        'error_message' => 'Manually unlocked after WP 409 lock',
        'credit_cost' => 6,
    ]);

    $imageB = ContentImage::query()->create([
        'id' => (string) Str::uuid(),
        'content_id' => $content->id,
        'type' => 'featured',
        'status' => 'failed',
        'error_message' => 'Manually unlocked after WP 409 lock',
        'credit_cost' => 6,
    ]);

    $imageWithOutput = ContentImage::query()->create([
        'id' => (string) Str::uuid(),
        'content_id' => $content->id,
        'type' => 'featured',
        'status' => 'failed',
        'error_message' => 'post-processing warning',
        'credit_cost' => 6,
        'image_path' => 'content-images/has-output.png',
    ]);

    foreach ([$imageA, $imageB, $imageWithOutput] as $image) {
        $wallets->reserveForContentImage($image->fresh());
        $wallets->commitUsageForContentImage($image->fresh());
    }

    ContentImage::query()->whereKey($imageA->id)->update([
        'credit_status' => 'failed',
        'credit_release_reason' => 'Manually unlocked after WP 409 lock',
    ]);
    ContentImage::query()->whereKey($imageB->id)->update([
        'credit_status' => 'pending',
        'credit_release_reason' => 'Manually unlocked after WP 409 lock',
    ]);

    return [$site, $imageA->fresh(), $imageB->fresh(), $imageWithOutput->fresh()];
}
