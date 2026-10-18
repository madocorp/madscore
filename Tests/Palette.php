<?php

define('APP_DIR', dirname(__DIR__));
define('APP_NAMESPACE', 'MADSCORE');
require APP_DIR . '/SPTK/App.php';

/** Fail when a default color loses its screen or paper visibility. */
function expectPalette(bool $condition, string $message): void {
  if (!$condition) {
    throw new RuntimeException($message);
  }
}

/** Return CIELAB coordinates and relative luminance for a hex color. */
function paletteColor(string $hex): array {
  $rgb = sscanf($hex, '#%02x%02x%02x');
  [$red, $green, $blue] = array_map(static function(int $channel): float {
    $value = $channel / 255;
    return $value <= 0.04045 ? $value / 12.92 : (($value + 0.055) / 1.055) ** 2.4;
  }, $rgb);
  $x = ($red * 0.4124564 + $green * 0.3575761 + $blue * 0.1804375) / 0.95047;
  $y = $red * 0.2126729 + $green * 0.7151522 + $blue * 0.0721750;
  $z = ($red * 0.0193339 + $green * 0.1191920 + $blue * 0.9503041) / 1.08883;
  $curve = static fn (float $value): float => $value > 0.008856 ? $value ** (1 / 3) : 7.787 * $value + 16 / 116;
  [$fx, $fy, $fz] = [$curve($x), $curve($y), $curve($z)];
  return [[116 * $fy - 16, 500 * ($fx - $fy), 200 * ($fy - $fz)], $y];
}

/** Measure the separation of two colors in CIELAB space. */
function paletteDistance(array $left, array $right): float {
  return sqrt(($left[0] - $right[0]) ** 2 + ($left[1] - $right[1]) ** 2 + ($left[2] - $right[2]) ** 2);
}

/** Find an exact RGB fill in the PDF page preview. */
function paletteHasColor(\SPTK\Core\RasterImage $image, string $hex): bool {
  $rgb = hexdec(substr($hex, 1));
  for ($offset = 0, $limit = strlen($image->pixels); $offset < $limit; $offset += 4) {
    if (((unpack('L', substr($image->pixels, $offset, 4))[1] >> 8) & 0xffffff) === $rgb) {
      return true;
    }
  }
  return false;
}

$colors = MADSCORE\Score\Score::COLORS;
expectPalette(count($colors) === 12 && count(array_unique($colors)) === 12, 'The default palette has twelve unique colors.');
[$staff] = paletteColor('#18201c');
$labs = [];
foreach ($colors as $index => $hex) {
  [$lab, $luminance] = paletteColor($hex);
  $labs[] = $lab;
  expectPalette(1.05 / ($luminance + 0.05) >= ($index < 4 ? 4.5 : 3.5), 'A melody color must remain visible on white paper.');
  expectPalette(paletteDistance($lab, $staff) >= 45, 'A melody color must differ from the dark staff ink.');
  foreach (array_slice($labs, 0, -1) as $otherIndex => $other) {
    $minimum = $index < 4 && $otherIndex < 4 ? 38 : 30;
    expectPalette(paletteDistance($lab, $other) >= $minimum, 'Default melody colors must remain distinct.');
  }
}

$score = new MADSCORE\Score\Score(4);
foreach (range(0, 3) as $melody) {
  $score->place($melody, 0, $melody * 2, $melody + 3, 1, 2);
}
$page = MADSCORE\Render\PagePreview::render($score, 0, 1684, 1190);
foreach (array_slice($colors, 0, 4) as $hex) {
  expectPalette(paletteHasColor($page, $hex), "The PDF page painter must draw primary color {$hex}.");
}
foreach (range(0, 3) as $melody) {
  $editor = MADSCORE\Render\SheetPainter::render($score, 0, 1, 350, 490, melody: $melody, design: true);
  expectPalette(paletteHasColor($editor, $colors[$melody]), 'The editor must show each selected melody in its primary color.');
}
echo "Palette checks passed\n";
