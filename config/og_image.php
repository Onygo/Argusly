<?php

return [
    'padding' => (int) env('ARGUSLY_OG_PADDING', 72),
    'max_text_width' => (int) env('ARGUSLY_OG_MAX_TEXT_WIDTH', 980),
    'keyword_title_gap' => (int) env('ARGUSLY_OG_KEYWORD_TITLE_GAP', 28),

    // Prefer system Arial/Helvetica Bold fonts for consistent OG rendering.
    'font_paths' => array_values(array_filter([
        env('ARGUSLY_OG_FONT_PATH'),
        '/System/Library/Fonts/Supplemental/Arial Bold.ttf',
        '/System/Library/Fonts/Supplemental/Arial.ttf',
        '/System/Library/Fonts/Helvetica.ttc',
        '/usr/share/fonts/truetype/msttcorefonts/Arial_Bold.ttf',
        '/usr/share/fonts/truetype/liberation/LiberationSans-Bold.ttf',
        '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf',
    ])),

    'keyword_font_size' => (int) env('ARGUSLY_OG_KEYWORD_FONT_SIZE', 40),
    'title_font_size_min' => (int) env('ARGUSLY_OG_TITLE_FONT_SIZE_MIN', 64),
    'title_font_size_max' => (int) env('ARGUSLY_OG_TITLE_FONT_SIZE_MAX', 78),
    'title_line_height' => (float) env('ARGUSLY_OG_TITLE_LINE_HEIGHT', 1.1),

    'keyword_max_chars' => (int) env('ARGUSLY_OG_KEYWORD_MAX_CHARS', 90),
    'title_max_chars' => (int) env('ARGUSLY_OG_TITLE_MAX_CHARS', 260),

    // If keyword already appears in title, omit keyword line to avoid duplicate phrase.
    'omit_keyword_if_in_title' => (bool) env('ARGUSLY_OG_OMIT_KEYWORD_IF_IN_TITLE', true),

    'overlay_opacity_min' => (float) env('ARGUSLY_OG_OVERLAY_OPACITY_MIN', 0.35),
    'overlay_opacity_max' => (float) env('ARGUSLY_OG_OVERLAY_OPACITY_MAX', 0.55),

    'text_shadow' => (bool) env('ARGUSLY_OG_TEXT_SHADOW', true),
    'shadow_offset' => (int) env('ARGUSLY_OG_TEXT_SHADOW_OFFSET', 2),
];
