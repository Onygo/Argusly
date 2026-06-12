<?php

it('serves the Argusly tracking script from the track subdomain', function () {
    $response = $this
        ->withHeaders(['Host' => 'track.argusly.local'])
        ->get('http://track.argusly.local/argusly.js?v=1.2.1');

    $response->assertOk();
    $response->assertHeader('Content-Type', 'application/javascript; charset=utf-8');
    $response->assertSee('Argusly Analytics - privacy-first pageview tracker', false);
    $response->assertSee('window.Argusly.track = track', false);
    $response->assertDontSee('script not found', false);
});
