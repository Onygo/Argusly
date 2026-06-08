<?php

use App\Mail\ContactSubmissionReceived;
use App\Models\ContactSubmission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

it('shows the temporary launch homepage when soft launch mode is enabled', function () {
    config([
        'argusly.launch.soft_launch_mode' => true,
    ]);

    $this->get(route('landing'))
        ->assertOk()
        ->assertSee(__('public.early_access.request_early_access'), false)
        ->assertSee(__('public.early_access.book_demo'), false)
        ->assertSee(__('public.early_access.soft_launch_badge'), false);
});

it('blocks public registration routes when registration is disabled', function () {
    config([
        'argusly.launch.public_registration_enabled' => false,
        'argusly.launch.registration_block_mode' => 'redirect',
    ]);

    $this->get('/register')
        ->assertRedirectToRoute('public.early-access.show');

    $this->post('/register', [
        'name' => 'Blocked User',
        'email' => 'blocked@example.com',
        'password' => 'secret1234',
        'password_confirmation' => 'secret1234',
        'company_name' => 'Blocked Co',
        'plan' => 'starter',
    ])->assertRedirectToRoute('public.early-access.show');
});

it('returns 404 for registration routes when configured', function () {
    config([
        'argusly.launch.public_registration_enabled' => false,
        'argusly.launch.registration_block_mode' => '404',
    ]);

    $this->get('/register')->assertNotFound();
});

it('keeps login available while registration is disabled', function () {
    config([
        'argusly.launch.public_registration_enabled' => false,
    ]);

    $this->get('/login')->assertOk();
});

it('stores early access submissions and sends notification email', function () {
    Mail::fake();

    $response = $this->from('/early-access?intent=demo')
        ->post('/early-access', [
            'full_name' => 'Jane Doe',
            'work_email' => 'jane@example.com',
            'company' => 'Acme',
            'website' => 'https://acme.test',
            'message' => 'We want a controlled rollout for two teams.',
            'intent' => 'demo',
            'company_size' => '',
        ]);

    $response->assertRedirectToRoute('test.public.early-access.show', ['intent' => 'demo']);
    $response->assertSessionHas('early_access_status');

    $submission = ContactSubmission::query()->where('email', 'jane@example.com')->first();
    expect($submission)->not->toBeNull();
    expect((string) $submission->topic)->toBe('Book a demo');
    expect((string) $submission->subject)->toBe('Demo request');

    Mail::assertSent(ContactSubmissionReceived::class);
});
