<?php

use App\Enums\EarlyAccessSignupStatus;
use App\Mail\ContactSubmissionReceived;
use App\Models\ContactSubmission;
use App\Models\EarlyAccessSignup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('services.recaptcha.site_key', 'recaptcha-site-key');
    config()->set('services.recaptcha.secret_key', 'recaptcha-secret-key');
    config()->set('services.recaptcha.min_score', 0.5);
});

function contactPayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Jane Doe',
        'email' => 'jane@example.com',
        'company' => 'Onygo',
        'website' => 'https://onygo.example',
        'market' => 'B2B SaaS',
        'competitors' => 'Acme AI, Northstar Content',
        'growth_goal' => 'Improve AI answer visibility',
        'interest_area' => 'AI Visibility',
        'subject' => 'Enterprise pricing',
        'message' => 'We want a multi brand rollout.',
        'topic' => 'Enterprise pricing',
        'source_page' => 'landing.cta',
        'cta_label' => 'Request enterprise pricing',
        'url' => 'https://argusly.test/company/contact?topic=Enterprise+pricing',
        'recaptcha_token' => 'valid-recaptcha-token',
    ], $overrides);
}

function recaptchaSuccessResponse(): array
{
    return [
        'success' => true,
        'score' => 0.9,
        'action' => 'contact',
        'hostname' => 'argusly.test',
        'error-codes' => [],
    ];
}

function recaptchaFailedTokenResponse(): array
{
    return [
        'success' => false,
        'score' => 0.0,
        'action' => 'contact',
        'hostname' => 'argusly.test',
        'error-codes' => ['invalid-input-response'],
    ];
}

function recaptchaLowScoreResponse(): array
{
    return [
        'success' => true,
        'score' => 0.2,
        'action' => 'contact',
        'hostname' => 'argusly.test',
        'error-codes' => [],
    ];
}

function recaptchaActionMismatchResponse(): array
{
    return [
        'success' => true,
        'score' => 0.9,
        'action' => 'wrong_action',
        'hostname' => 'argusly.test',
        'error-codes' => [],
    ];
}

it('fails when the captcha token is missing', function () {
    Mail::fake();
    Http::fake();

    $payload = contactPayload();
    unset($payload['recaptcha_token']);

    $response = $this->from('/company/contact')->post('/company/contact', $payload);

    $response->assertRedirect('/company/contact');
    $response->assertSessionHasErrors(['recaptcha_token']);

    expect(ContactSubmission::query()->count())->toBe(0);

    Mail::assertNothingSent();
    Http::assertNothingSent();
});

it('fails when the captcha token is invalid', function () {
    Mail::fake();

    Http::fake([
        'www.google.com/recaptcha/api/siteverify' => Http::response(recaptchaFailedTokenResponse(), 200),
    ]);

    $response = $this->from('/company/contact')->post('/company/contact', contactPayload([
        'recaptcha_token' => 'invalid-recaptcha-token',
    ]));

    $response->assertRedirect('/company/contact');
    $response->assertSessionHasErrors(['recaptcha_token']);

    expect(ContactSubmission::query()->count())->toBe(0);

    Mail::assertNothingSent();
});

it('fails when the captcha score is below threshold', function () {
    Mail::fake();

    Http::fake([
        'www.google.com/recaptcha/api/siteverify' => Http::response(recaptchaLowScoreResponse(), 200),
    ]);

    $response = $this->from('/company/contact')->post('/company/contact', contactPayload());

    $response->assertRedirect('/company/contact');
    $response->assertSessionHasErrors(['recaptcha_token']);

    expect(ContactSubmission::query()->count())->toBe(0);

    Mail::assertNothingSent();
});

it('fails when the captcha action does not match', function () {
    Mail::fake();

    Http::fake([
        'www.google.com/recaptcha/api/siteverify' => Http::response(recaptchaActionMismatchResponse(), 200),
    ]);

    $response = $this->from('/company/contact')->post('/company/contact', contactPayload());

    $response->assertRedirect('/company/contact');
    $response->assertSessionHasErrors(['recaptcha_token']);

    expect(ContactSubmission::query()->count())->toBe(0);

    Mail::assertNothingSent();
});

it('stores contact submissions and sends notification email when captcha verification succeeds', function () {
    Mail::fake();

    Http::fake([
        'www.google.com/recaptcha/api/siteverify' => Http::response(recaptchaSuccessResponse(), 200),
    ]);

    $response = $this->from('/company/contact')->post('/company/contact', contactPayload());

    $response->assertRedirect('/company/contact');
    $response->assertSessionHas('contact_status');

    $submission = ContactSubmission::query()->where('email', 'jane@example.com')->first();

    expect($submission)->not->toBeNull();
    expect((string) $submission->topic)->toBe('Enterprise pricing');
    expect((string) $submission->cta_label)->toBe('Request enterprise pricing');
    expect((string) $submission->website)->toBe('https://onygo.example');
    expect((string) $submission->market)->toBe('B2B SaaS');
    expect((string) $submission->competitors)->toBe('Acme AI, Northstar Content');
    expect((string) $submission->growth_goal)->toBe('Improve AI answer visibility');
    expect((string) $submission->interest_area)->toBe('AI Visibility');

    Mail::assertSent(ContactSubmissionReceived::class);

    Http::assertSent(function ($request): bool {
        parse_str($request->body(), $body);

        return $request->url() === 'https://www.google.com/recaptcha/api/siteverify'
            && ($body['secret'] ?? null) === 'recaptcha-secret-key'
            && ($body['response'] ?? null) === 'valid-recaptcha-token';
    });
});

it('creates an admin pilot signup from pilot contact submissions', function () {
    Mail::fake();

    Http::fake([
        'www.google.com/recaptcha/api/siteverify' => Http::response(recaptchaSuccessResponse(), 200),
    ]);

    $response = $this->from('/nl/bedrijf/contact?subject=pilot-aanvraag#contact-form')->post('/nl/bedrijf/contact', contactPayload([
        'name' => 'Pilot Prospect',
        'email' => 'pilot@example.com',
        'company' => 'Pilot Co',
        'website' => 'https://pilot.example',
        'market' => 'B2B SaaS',
        'competitors' => 'Northstar',
        'growth_goal' => 'Improve AI visibility',
        'interest_area' => 'AI Visibility',
        'subject' => 'Pilot aanvraag',
        'topic' => 'pilot',
        'message' => 'We want to join the pilot with our content team.',
        'source_page' => 'pricing',
        'cta_label' => 'Pilot aanvragen',
    ]));

    $response->assertRedirect('/nl/bedrijf/contact?subject=pilot-aanvraag#contact-form');
    $response->assertSessionHas('contact_status');

    $submission = ContactSubmission::query()->where('email', 'pilot@example.com')->first();
    expect($submission)->not->toBeNull()
        ->and((string) $submission->topic)->toBe('pilot')
        ->and((string) $submission->subject)->toBe('Pilot aanvraag');

    $signup = EarlyAccessSignup::query()->where('email', 'pilot@example.com')->first();
    expect($signup)->not->toBeNull()
        ->and($signup->status)->toBe(EarlyAccessSignupStatus::NEW)
        ->and((string) $signup->full_name)->toBe('Pilot Prospect')
        ->and((string) $signup->company_name)->toBe('Pilot Co')
        ->and((string) $signup->website)->toBe('https://pilot.example')
        ->and((string) $signup->use_case)->toContain('join the pilot')
        ->and((string) $signup->source)->toBe('pilot_contact')
        ->and((string) $signup->notes)->toContain('Subject: Pilot aanvraag')
        ->and((string) $signup->notes)->toContain('Growth goal: Improve AI visibility')
        ->and($signup->submitted_at)->not->toBeNull();

    Mail::assertSent(ContactSubmissionReceived::class);
});

it('fails safely when google captcha verification is unreachable', function () {
    Mail::fake();

    Http::fake(function () {
        throw new ConnectionException('reCAPTCHA connection failed');
    });

    $response = $this->from('/company/contact')->post('/company/contact', contactPayload());

    $response->assertRedirect('/company/contact');
    $response->assertSessionHasErrors(['recaptcha_token']);

    expect(ContactSubmission::query()->count())->toBe(0);

    Mail::assertNothingSent();
});

it('throttles repeated public contact submissions', function () {
    Mail::fake();
    config()->set('security.rate_limits.contact_per_minute', 2);

    Http::fake([
        'www.google.com/recaptcha/api/siteverify' => Http::response(recaptchaSuccessResponse(), 200),
    ]);

    foreach (range(1, 2) as $attempt) {
        $response = $this->from('/company/contact')->post('/company/contact', contactPayload([
            'email' => "jane{$attempt}@example.com",
            'recaptcha_token' => "valid-recaptcha-token-{$attempt}",
        ]));

        $response->assertRedirect('/company/contact');
    }

    $this->post('/company/contact', contactPayload([
        'email' => 'jane3@example.com',
        'recaptcha_token' => 'valid-recaptcha-token-3',
    ]))->assertStatus(429);
});

it('prefills subject on short contact route', function () {
    $this->followingRedirects()->get('/contact?subject=maatwerk-enterprise')
        ->assertOk()
        ->assertSee('name="subject"', false)
        ->assertSee('value="maatwerk-enterprise"', false);
});

it('prefills pilot application subject and request type from subject slug', function () {
    $this->get('/nl/bedrijf/contact?subject=pilot-aanvraag')
        ->assertOk()
        ->assertSee('<option value="pilot" selected>Pilot aanvraag</option>', false)
        ->assertSee('value="Pilot aanvraag"', false);
});
