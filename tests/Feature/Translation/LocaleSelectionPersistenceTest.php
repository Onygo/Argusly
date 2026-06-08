<?php

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('persists public locale override in a cookie', function () {
    $response = $this->get('/?lang=nl');

    $response->assertMovedPermanently()
        ->assertRedirect('/nl')
        ->assertCookie('argusly_locale', 'nl');

    $this->withCookie('argusly_locale', 'nl')
        ->get('/nl')
        ->assertOk()
        ->assertSee('lang="nl"', false);
});

it('falls back to english for unsupported browser locales on public pages', function () {
    $this->withHeader('Accept-Language', 'ja,fr;q=0.8')
        ->get('/')
        ->assertMovedPermanently()
        ->assertRedirect('/en');
});
