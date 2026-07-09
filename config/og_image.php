<?php

return [
    'logo_path' => env('ARGUSLY_OG_LOGO_PATH', public_path('images/argusly-logo-standalone.png')),
    'logo_max_width' => (int) env('ARGUSLY_OG_LOGO_MAX_WIDTH', 150),
    'logo_margin' => (int) env('ARGUSLY_OG_LOGO_MARGIN', 32),
];
