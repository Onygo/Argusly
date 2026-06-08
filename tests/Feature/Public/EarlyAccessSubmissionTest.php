<?php

use App\Enums\EarlyAccessSignupStatus;
use App\Mail\ContactSubmissionReceived;
use App\Models\ContactSubmission;
use App\Models\EarlyAccessSignup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

it('stores an early access signup alongside the existing contact submission email flow', function () {
    Mail::fake();

    $response = $this->from('/early-access?intent=demo')->post('/early-access', [
        'full_name' => 'Jane Doe',
        'work_email' => 'jane@example.com',
        'phone' => '+31 20 123 4567',
        'country' => 'Netherlands',
        'job_title' => 'Head of Content',
        'company' => 'Acme',
        'company_size_visible' => '51-200',
        'industry' => 'B2B SaaS',
        'website' => 'https://acme.test',
        'message' => 'We want a controlled rollout for two teams.',
        'intent' => 'demo',
        'utm_source' => 'linkedin',
        'utm_medium' => 'paid',
        'utm_campaign' => 'pilot-q2',
        'marketing_consent' => '1',
        'company_size' => '',
    ]);

    $response->assertRedirectToRoute('test.public.early-access.show', ['intent' => 'demo']);
    $response->assertSessionHas('early_access_status');

    $submission = ContactSubmission::query()->where('email', 'jane@example.com')->first();
    expect($submission)->not->toBeNull()
        ->and((string) $submission->topic)->toBe('Book a demo')
        ->and((string) $submission->subject)->toBe('Demo request');

    $signup = EarlyAccessSignup::query()->where('email', 'jane@example.com')->first();
    expect($signup)->not->toBeNull()
        ->and($signup->status)->toBe(EarlyAccessSignupStatus::NEW)
        ->and((string) $signup->full_name)->toBe('Jane Doe')
        ->and((string) $signup->phone)->toBe('+31 20 123 4567')
        ->and((string) $signup->country)->toBe('Netherlands')
        ->and((string) $signup->job_title)->toBe('Head of Content')
        ->and((string) $signup->company_name)->toBe('Acme')
        ->and((string) $signup->company_size)->toBe('51-200')
        ->and((string) $signup->industry)->toBe('B2B SaaS')
        ->and((string) $signup->website)->toBe('https://acme.test')
        ->and((string) $signup->use_case)->toContain('controlled rollout')
        ->and((string) $signup->source)->toBe('demo')
        ->and((string) $signup->utm_source)->toBe('linkedin')
        ->and((string) $signup->utm_medium)->toBe('paid')
        ->and((string) $signup->utm_campaign)->toBe('pilot-q2')
        ->and((bool) $signup->marketing_consent)->toBeTrue()
        ->and($signup->qualification_score)->toBeGreaterThan(0)
        ->and($signup->submitted_at)->not->toBeNull();

    Mail::assertSent(ContactSubmissionReceived::class);
});
