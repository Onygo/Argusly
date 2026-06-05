<?php

use App\Jobs\GenerateContentFeaturedImageJob;
use Illuminate\Support\Str;

it('uses an explicit timeout suitable for image generation jobs', function () {
    $job = new GenerateContentFeaturedImageJob((string) Str::uuid());

    expect($job->timeout)->toBe(180);
    expect($job->failOnTimeout)->toBeTrue();
});
