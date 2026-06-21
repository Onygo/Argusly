<?php

$outputDir = __DIR__ . '/../public/images/social';

if (! is_dir($outputDir)) {
    mkdir($outputDir, 0755, true);
}

$font = firstReadable([
    '/Users/ricardohagens/Library/Fonts/InstrumentSans-Regular.ttf',
    '/Library/Fonts/InstrumentSans-Regular.ttf',
    '/System/Library/Fonts/SFNS.ttf',
    '/System/Library/Fonts/HelveticaNeue.ttc',
    '/System/Library/Fonts/Supplemental/Arial Bold.ttf',
]);

if ($font === null) {
    fwrite(STDERR, "No readable font found.\n");
    exit(1);
}

$variants = [
    'argusly-og-ai-visibility.jpg' => 'AI Visibility',
    'argusly-og-opportunity-intelligence.jpg' => 'Opportunity Intelligence',
    'argusly-og-agentic-marketing.jpg' => 'Agentic Marketing',
    'argusly-og-growth-intelligence.jpg' => 'Growth Intelligence',
    'argusly-og-autonomous-marketing.jpg' => 'Autonomous Marketing Operations',
];

foreach ($variants as $filename => $title) {
    renderSocialImage($outputDir . '/' . $filename, $font, $title);
}

function renderSocialImage(string $path, string $font, string $title): void
{
    $width = 1200;
    $height = 630;
    $image = imagecreatetruecolor($width, $height);
    imageantialias($image, true);

    for ($y = 0; $y < $height; $y++) {
        $t = $y / max(1, $height - 1);
        $r = (int) round(7 + (34 - 7) * $t);
        $g = (int) round(12 + (27 - 12) * $t);
        $b = (int) round(28 + (88 - 28) * $t);
        imageline($image, 0, $y, $width, $y, imagecolorallocate($image, $r, $g, $b));
    }

    drawRadialGlow($image, 920, 170, 390, [118, 87, 255], 0.35);
    drawRadialGlow($image, 290, 470, 360, [35, 92, 255], 0.22);
    drawNetwork($image);
    drawPanelLines($image);
    drawBrand($image, $font);
    drawTitle($image, $font, $title);

    imagejpeg($image, $path, 92);
    imagedestroy($image);
}

function drawBrand(GdImage $image, string $font): void
{
    $white = imagecolorallocate($image, 255, 255, 255);
    $blue = imagecolorallocate($image, 35, 92, 255);
    $blueSoft = imagecolorallocatealpha($image, 35, 92, 255, 78);
    $blueRing = imagecolorallocatealpha($image, 35, 92, 255, 55);

    imagefilledellipse($image, 116, 104, 50, 50, $blueSoft);
    imageellipse($image, 116, 104, 42, 42, $blueRing);
    imagefilledellipse($image, 116, 104, 24, 24, $blue);

    imagettftext($image, 35, 0, 154, 117, $white, $font, 'Argusly');
}

function drawTitle(GdImage $image, string $font, string $title): void
{
    $white = imagecolorallocate($image, 255, 255, 255);
    $muted = imagecolorallocatealpha($image, 229, 237, 255, 22);
    $accent = imagecolorallocate($image, 164, 188, 253);

    $lines = wrapTitle($title);
    $fontSize = count($lines) > 1 ? 68 : 82;
    $lineHeight = count($lines) > 1 ? 86 : 104;
    $y = count($lines) > 1 ? 316 : 355;

    foreach ($lines as $line) {
        imagettftext($image, $fontSize, 0, 92, $y, $white, $font, $line);
        $y += $lineHeight;
    }

    imagettftext($image, 24, 0, 96, 535, $muted, $font, 'AI visibility, opportunity intelligence, and autonomous growth workflows.');
}

function wrapTitle(string $title): array
{
    if ($title === 'Autonomous Marketing Operations') {
        return ['Autonomous Marketing', 'Operations'];
    }

    return [$title];
}

function drawNetwork(GdImage $image): void
{
    $line = imagecolorallocatealpha($image, 164, 188, 253, 96);
    $lineStrong = imagecolorallocatealpha($image, 164, 188, 253, 70);
    $dot = imagecolorallocatealpha($image, 224, 231, 255, 38);
    $dotStrong = imagecolorallocatealpha($image, 255, 255, 255, 18);

    $points = [
        [690, 146], [780, 88], [895, 132], [1012, 80], [1105, 166],
        [748, 256], [864, 292], [1018, 252], [1124, 338],
        [708, 428], [842, 474], [986, 426], [1100, 512],
    ];

    $edges = [[0, 1], [1, 2], [2, 3], [3, 4], [0, 5], [5, 6], [6, 7], [7, 8], [5, 9], [9, 10], [10, 11], [11, 12], [6, 10], [7, 11]];

    foreach ($edges as [$a, $b]) {
        imageline($image, $points[$a][0], $points[$a][1], $points[$b][0], $points[$b][1], $line);
    }

    foreach ($points as $index => [$x, $y]) {
        imagefilledellipse($image, $x, $y, $index % 3 === 0 ? 16 : 11, $index % 3 === 0 ? 16 : 11, $index % 3 === 0 ? $dotStrong : $dot);
    }

    unset($lineStrong);
}

function drawPanelLines(GdImage $image): void
{
    imagerectangle($image, 56, 50, 1144, 580, imagecolorallocatealpha($image, 255, 255, 255, 104));
}

function drawRadialGlow(GdImage $image, int $cx, int $cy, int $radius, array $rgb, float $strength): void
{
    for ($r = $radius; $r > 0; $r -= 8) {
        $alpha = (int) min(127, 127 - (127 * $strength * (1 - ($r / $radius))));
        $color = imagecolorallocatealpha($image, $rgb[0], $rgb[1], $rgb[2], $alpha);
        imagefilledellipse($image, $cx, $cy, $r * 2, $r * 2, $color);
    }
}

function firstReadable(array $paths): ?string
{
    foreach ($paths as $path) {
        if (is_readable($path)) {
            return $path;
        }
    }

    return null;
}
