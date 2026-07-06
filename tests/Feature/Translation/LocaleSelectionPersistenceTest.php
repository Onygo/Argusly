<?php

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('persists public locale override in a cookie', function () {
    $response = $this->get('https://argusly.local/?lang=nl');

    $response->assertMovedPermanently()
        ->assertRedirect('https://argusly.local/nl')
        ->assertCookie('argusly_locale', 'nl');

    $this->withCookie('argusly_locale', 'nl')
        ->get('https://argusly.local/nl')
        ->assertOk()
        ->assertSee('lang="nl"', false);
});

it('falls back to english for unsupported browser locales on public pages', function () {
    $this->withHeader('Accept-Language', 'ja,fr;q=0.8')
        ->get('https://argusly.local/')
        ->assertMovedPermanently()
        ->assertRedirect('https://argusly.local/en');
});
