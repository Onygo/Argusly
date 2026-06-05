<?php

use App\Models\Brief;
use App\Models\ClientSite;
use App\Models\Draft;
use App\Models\Organization;
use App\Models\SiteToken;
use App\Models\Workspace;
use App\Services\CreditWalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function makeApiContractContext(): array
{
    $organization = Organization::query()->create([
        'name' => 'API Contract Org',
        'slug' => 'api-contract-' . Str::random(6),
        'status' => 'active',
    ]);

    $workspace = Workspace::query()->create([
        'name' => 'API Contract Workspace',
        'organization_id' => $organization->id,
    ]);

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => 'wordpress',
        'name' => 'API Contract Site',
        'site_url' => 'https://api-contract.example.com',
        'allowed_domains' => ['api-contract.example.com'],
        'is_active' => true,
        'status' => 'connected',
    ]);

    app(CreditWalletService::class)->addCredits(
        clientSiteId: (string) $site->id,
        amount: 100,
        type: CreditWalletService::TYPE_ALLOWANCE
    );

    $plainToken = 'pl_site_' . Str::random(48);
    SiteToken::query()->create([
        'client_site_id' => $site->id,
        'token_hash' => hash('sha256', $plainToken),
        'scopes' => ['briefs:write', 'drafts:read'],
        'revoked' => false,
    ]);

    return [$site, $plainToken];
}

it('keeps brief create and draft list api response structures stable', function () {
    [$site, $plainToken] = makeApiContractContext();

    $headers = [
        'Authorization' => 'Bearer ' . $plainToken,
        'X-PublishLayer-Site' => 'api-contract.example.com',
    ];

    $briefResponse = $this->withHeaders($headers)->postJson('/api/v1/briefs', [
        'client' => [
            'type' => 'wordpress',
            'site_url' => 'https://api-contract.example.com',
            'wp_brief_id' => 'contract-brief-1',
        ],
        'brief' => [
            'title' => 'Contract stability brief',
            'language' => 'en',
            'intent_keys' => ['technical'],
            'audience_keys' => ['developer'],
            'output_type' => 'kb_article',
        ],
    ]);

    $briefResponse
        ->assertCreated()
        ->assertJsonStructure([
            'id',
            'status',
            'created_at',
            'content_id',
            'draft_id',
        ]);

    $briefId = (string) $briefResponse->json('id');
    $contentId = (string) $briefResponse->json('content_id');
    $draftId = (string) $briefResponse->json('draft_id');

    $draft = $draftId !== ''
        ? Draft::query()->find($draftId)
        : null;

    if (! $draft) {
        $draft = Draft::query()->create([
            'brief_id' => $briefId,
            'client_site_id' => (string) $site->id,
            'content_id' => $contentId !== '' ? $contentId : null,
            'status' => 'ready',
            'title' => 'Contract draft',
            'output_type' => 'kb_article',
            'content_html' => '<p>Contract draft body.</p>',
        ]);
    } else {
        $draft->update([
            'status' => 'ready',
            'content_html' => $draft->content_html ?: '<p>Contract draft body.</p>',
        ]);
    }

    $draftsResponse = $this->withHeaders($headers)->getJson('/api/v1/drafts?status=ready');

    $draftsResponse
        ->assertOk()
        ->assertJsonStructure([
            'items' => [[
                'id',
                'brief_id',
                'content_id',
                'status',
                'title',
                'output_type',
                'content_html',
                'meta',
                'links',
                'featured_image_url',
                'og_image_url',
                'created_at',
                'updated_at',
            ]],
        ]);

    $brief = Brief::query()->find($briefId);
    expect($brief)->not->toBeNull();
    expect((string) $draft->id)->not->toBe('');
});
