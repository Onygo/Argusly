<?php

use App\Models\ClientSite;
use App\Models\Content;
use App\Models\ContentImage;
use App\Models\CreditLedgerEntry;
use App\Models\Organization;
use App\Models\Workspace;
use App\Services\CreditWalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('reserves and releases content image credits idempotently', function () {
    $organization = Organization::query()->create([
        'name' => 'Image Credits Org',
        'slug' => 'image-credits-org-'.Str::random(6),
        'status' => 'active',
        'approved_at' => now(),
    ]);

    $workspace = Workspace::query()->create([
        'name' => 'Image Credits Workspace',
        'organization_id' => $organization->id,
    ]);

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => 'wordpress',
        'name' => 'Image Credits Site',
        'site_url' => 'https://image-credits.example.com',
        'allowed_domains' => ['image-credits.example.com'],
        'is_active' => true,
    ]);

    $content = Content::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'title' => 'Image Credits Content',
        'type' => 'article',
        'status' => 'draft',
        'source' => 'wp',
    ]);

    $image = ContentImage::query()->create([
        'id' => (string) Str::uuid(),
        'content_id' => $content->id,
        'type' => 'featured',
        'status' => 'queued',
        'credit_cost' => 6,
    ]);

    $credits = app(CreditWalletService::class);
    $credits->addCredits(
        clientSiteId: (string) $site->id,
        amount: 20,
        type: CreditWalletService::TYPE_ALLOWANCE,
    );

    $reservationA = $credits->reserveForContentImage($image->fresh());
    $reservationB = $credits->reserveForContentImage($image->fresh());
    expect((string) $reservationB->id)->toBe((string) $reservationA->id);

    $releaseA = $credits->releaseReservationForContentImage($image->fresh(), 'provider_error');
    $releaseB = $credits->releaseReservationForContentImage($image->fresh(), 'provider_error');

    expect((string) ($releaseA?->id ?? ''))->not->toBe('')
        ->and((string) ($releaseB?->id ?? ''))->toBe((string) ($releaseA?->id ?? ''));

    $image->refresh();
    $walletSummary = $credits->getSummary((string) $site->id);

    expect((string) $image->credit_status)->toBe('released')
        ->and((int) $walletSummary['reserved_cached'])->toBe(0)
        ->and((int) $walletSummary['available'])->toBe(20);

    expect(CreditLedgerEntry::query()
        ->where('source_type', ContentImage::class)
        ->where('source_id', $image->id)
        ->where('type', CreditWalletService::TYPE_RESERVATION)
        ->count())->toBe(1);

    expect(CreditLedgerEntry::query()
        ->where('source_type', ContentImage::class)
        ->where('source_id', $image->id)
        ->where('type', CreditWalletService::TYPE_RELEASE)
        ->count())->toBe(1);
});

it('refunds committed image usage when failed without output and is idempotent', function () {
    $organization = Organization::query()->create([
        'name' => 'Image Refund Org',
        'slug' => 'image-refund-org-'.Str::random(6),
        'status' => 'active',
        'approved_at' => now(),
    ]);

    $workspace = Workspace::query()->create([
        'name' => 'Image Refund Workspace',
        'organization_id' => $organization->id,
    ]);

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => 'wordpress',
        'name' => 'Image Refund Site',
        'site_url' => 'https://image-refund.example.com',
        'allowed_domains' => ['image-refund.example.com'],
        'is_active' => true,
    ]);

    $content = Content::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'title' => 'Image Refund Content',
        'type' => 'article',
        'status' => 'draft',
        'source' => 'wp',
    ]);

    $image = ContentImage::query()->create([
        'id' => (string) Str::uuid(),
        'content_id' => $content->id,
        'type' => 'featured',
        'status' => 'failed',
        'error_message' => 'Manually unlocked after WP 409 lock',
        'credit_cost' => 6,
    ]);

    $credits = app(CreditWalletService::class);
    $credits->addCredits(
        clientSiteId: (string) $site->id,
        amount: 20,
        type: CreditWalletService::TYPE_ALLOWANCE,
    );

    $credits->reserveForContentImage($image->fresh());
    $credits->commitUsageForContentImage($image->fresh());

    $summaryAfterCommit = $credits->getSummary((string) $site->id);
    expect((int) $summaryAfterCommit['available'])->toBe(14);

    $refundA = $credits->ensureReleasedForContentImage($image->fresh(), 'wp_409_manual_unlock');
    $refundB = $credits->ensureReleasedForContentImage($image->fresh(), 'wp_409_manual_unlock');

    expect((string) ($refundA?->type ?? ''))->toBe(CreditWalletService::TYPE_REFUND)
        ->and((string) ($refundB?->id ?? ''))->toBe((string) ($refundA?->id ?? ''));

    $image->refresh();
    $summaryAfterRefund = $credits->getSummary((string) $site->id);

    expect((string) $image->credit_status)->toBe('released')
        ->and((int) $summaryAfterRefund['reserved_cached'])->toBe(0)
        ->and((int) $summaryAfterRefund['available'])->toBe(20);

    expect(CreditLedgerEntry::query()
        ->where('source_type', ContentImage::class)
        ->where('source_id', $image->id)
        ->where('type', CreditWalletService::TYPE_REFUND)
        ->count())->toBe(1);
});

it('does not refund committed image usage when output exists', function () {
    $organization = Organization::query()->create([
        'name' => 'Image Success Org',
        'slug' => 'image-success-org-'.Str::random(6),
        'status' => 'active',
        'approved_at' => now(),
    ]);

    $workspace = Workspace::query()->create([
        'name' => 'Image Success Workspace',
        'organization_id' => $organization->id,
    ]);

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => 'wordpress',
        'name' => 'Image Success Site',
        'site_url' => 'https://image-success.example.com',
        'allowed_domains' => ['image-success.example.com'],
        'is_active' => true,
    ]);

    $content = Content::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'title' => 'Image Success Content',
        'type' => 'article',
        'status' => 'draft',
        'source' => 'wp',
    ]);

    $image = ContentImage::query()->create([
        'id' => (string) Str::uuid(),
        'content_id' => $content->id,
        'type' => 'featured',
        'status' => 'ready',
        'image_path' => 'content-images/success.png',
        'credit_cost' => 6,
    ]);

    $credits = app(CreditWalletService::class);
    $credits->addCredits(
        clientSiteId: (string) $site->id,
        amount: 20,
        type: CreditWalletService::TYPE_ALLOWANCE,
    );

    $credits->reserveForContentImage($image->fresh());
    $usage = $credits->commitUsageForContentImage($image->fresh());
    $result = $credits->ensureReleasedForContentImage($image->fresh(), 'should_not_refund');

    $summary = $credits->getSummary((string) $site->id);

    expect((string) ($result?->id ?? ''))->toBe((string) $usage->id)
        ->and((int) $summary['available'])->toBe(14);

    expect(CreditLedgerEntry::query()
        ->where('source_type', ContentImage::class)
        ->where('source_id', $image->id)
        ->where('type', CreditWalletService::TYPE_REFUND)
        ->count())->toBe(0);
});
