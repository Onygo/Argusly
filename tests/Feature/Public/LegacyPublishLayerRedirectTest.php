<?php

it('redirects localized PublishLayer page urls to Argusly equivalents', function () {
    $this->get('https://publishlayer.com/nl/pricing?utm_source=legacy')
        ->assertStatus(301)
        ->assertRedirect('https://argusly.com/nl/prijzen?utm_source=legacy');

    $this->get('https://publishlayer.com/en/product/capabilities')
        ->assertStatus(301)
        ->assertRedirect('https://argusly.com/en/product/platform#capabilities');
});

it('preserves localized blog slugs on the Argusly domain', function () {
    $this->get('https://www.publishlayer.com/nl/blog/ai-zichtbaarheid-voor-b2b')
        ->assertStatus(301)
        ->assertRedirect('https://argusly.com/nl/blog/ai-zichtbaarheid-voor-b2b');

    $this->get('https://publishlayer.com/en/blog/ai-visibility-for-b2b.md')
        ->assertStatus(301)
        ->assertRedirect('https://argusly.com/en/blog/ai-visibility-for-b2b.md');
});

it('does not intercept normal Argusly marketing routes', function () {
    $this->get('https://argusly.com/robots.txt')
        ->assertOk()
        ->assertHeaderMissing('Location');
});
