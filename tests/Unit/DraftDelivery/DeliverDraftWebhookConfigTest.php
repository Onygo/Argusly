<?php

use App\Models\Draft;
use App\Models\ClientSite;
use App\Services\DraftDelivery\DeliverDraftToWordPress;
use Illuminate\Support\Str;

it('uses publishlayer webhook config secret fallback when webhook url exists without secret', function () {
    config()->set('publishlayer.webhooks.secret', 'configured-secret');
    putenv('PUBLISHLAYER_WEBHOOK_SECRET=legacy-env-secret');

    $service = app(DeliverDraftToWordPress::class);
    $draft = new Draft([
        'id' => (string) Str::uuid(),
        'client_site_id' => (string) Str::uuid(),
    ]);
    $draft->setRelation('clientSite', new ClientSite([
        'id' => (string) $draft->client_site_id,
        'draft_webhook_url' => null,
        'draft_webhook_secret' => null,
    ]));

    $method = new ReflectionMethod($service, 'resolveWebhookCredentials');
    $method->setAccessible(true);

    [$url, $secret] = $method->invoke($service, $draft, [
        'draft_webhook_url' => 'https://client.example.com/wp-json/publishlayer/v1/webhook/draft',
    ]);

    expect($url)->toBe('https://client.example.com/wp-json/publishlayer/v1/webhook/draft')
        ->and($secret)->toBe('configured-secret');
});
