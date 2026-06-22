<?php

use App\Enums\SocialPlatform;

return [
    SocialPlatform::LINKEDIN->value => [
        'label' => 'LinkedIn',
        'post_label' => 'LinkedIn Post',
        'requires_media' => false,
        'supports_text_only' => true,
        'supports_single_image' => true,
        'supports_carousel' => false,
        'supports_reels' => false,
        'caption_limit' => 3000,
    ],

    SocialPlatform::INSTAGRAM->value => [
        'label' => 'Instagram',
        'post_label' => 'Instagram Post',
        'requires_media' => true,
        'supports_text_only' => false,
        'supports_single_image' => true,
        'supports_carousel' => false,
        'supports_reels' => false,
        'caption_limit' => 2200,
    ],
];
