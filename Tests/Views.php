<?php

define('APP_DIR', dirname(__DIR__));
define('APP_NAMESPACE', 'MADSCORE');
require APP_DIR . '/SPTK/App.php';

function expectView(bool $condition, string $message): void {
  if (!$condition) {
    throw new \RuntimeException($message);
  }
}

function hasColor(\SPTK\Core\RasterImage $image, string $color): bool {
  $rgb = hexdec(substr($color, 1));
  for ($offset = 0, $limit = strlen($image->pixels); $offset < $limit; $offset += 4) {
    if (((unpack('L', substr($image->pixels, $offset, 4))[1] >> 8) & 0xffffff) === $rgb) {
      return true;
    }
  }
  return false;
}

$score = new MADSCORE\Score\Score();
expectView(count($score->melodies) === 1, 'A new score starts with one melody.');
for ($band = 1; $band <= 8; $band++) {
  $score->addMelody();
}
foreach (range(0, 8) as $band) {
  $score->place($band, 0, 0, $band, 4, 2);
}
expectView(count(MADSCORE\Render\Prescription::states($score)[0]['bands']) === 9, 'The sheet prescription includes all nine sounding bands.');
$top = MADSCORE\Render\SheetPainter::render($score, 0, 1, 350, 490);
$bottom = MADSCORE\Render\SheetPainter::render($score, 0, 1, 350, 490, firstRow: 5);
expectView(hasColor($top, $score->melodies[8]['color']), 'The sheet shows its highest band.');
expectView(hasColor($bottom, $score->melodies[0]['color']), 'The sheet can scroll to its lowest band.');
$designHigh = MADSCORE\Render\SheetPainter::render($score, 0, 1, 350, 490, melody: 8, design: true);
$designLow = MADSCORE\Render\SheetPainter::render($score, 0, 1, 350, 490, melody: 0, design: true);
expectView(hasColor($designHigh, $score->melodies[8]['color']) && !hasColor($designHigh, $score->melodies[0]['color'])
  && hasColor($designLow, $score->melodies[0]['color']), 'The editor keeps four rows around the active melody.');
expectView(count(MADSCORE\Score\MelodyRange::bands($score, 8)) === 4, 'An active melody has exactly four consecutive editor rows.');
$colors = new MADSCORE\Score\Score(2);
$colors->place(0, 0, 0, 4, 2, 2);
$colors->place(0, 0, 4, 4, 2, 2);
$colors->place(1, 0, 0, 4, 2, 2);
$colors->place(1, 0, 2, 4, 2, 2);
$editorFirst = MADSCORE\Render\SheetPainter::render($colors, 0, 1, 350, 490, melody: 0, design: true);
$editorSecond = MADSCORE\Render\SheetPainter::render($colors, 0, 1, 350, 490, melody: 1, design: true);
$inactiveBlue = MADSCORE\Render\BarPainter::inactiveColor($colors, 1);
expectView($inactiveBlue !== $colors->melodies[1]['color'], 'Inactive blue is lightened toward white.');
expectView(hasColor($editorFirst, $colors->melodies[0]['color']) && hasColor($editorFirst, $inactiveBlue)
  && !hasColor($editorFirst, $colors->melodies[1]['color']), 'The first melody is vivid while the second is pale.');
$overlapPixel = unpack('L', substr($editorFirst->pixels, (259 * $editorFirst->width + 63) * 4, 4))[1];
expectView((($overlapPixel >> 8) & 0xffffff) === hexdec(substr($colors->melodies[0]['color'], 1)),
  'The active note stays on top when two melodies have the same pitch and time.');
expectView(hasColor($editorSecond, $colors->melodies[1]['color'])
  && hasColor($editorSecond, MADSCORE\Render\BarPainter::inactiveColor($colors, 0))
  && !hasColor($editorSecond, $colors->melodies[0]['color']), 'Switching melodies swaps the vivid and pale colors.');
$shortFirst = new MADSCORE\Score\Score(2);
$shortFirst->place(0, 0, 0, 4, 4, 2);
$shortFirst->place(1, 0, 0, 4, 4, 4);
$longFirst = new MADSCORE\Score\Score(2);
$longFirst->place(1, 0, 0, 4, 4, 4);
$longFirst->place(0, 0, 0, 4, 4, 2);
$shortFirstImage = MADSCORE\Render\BarPainter::render($shortFirst, 0, 4, 240, 120);
$longFirstImage = MADSCORE\Render\BarPainter::render($longFirst, 0, 4, 240, 120);
expectView($shortFirstImage->pixels === $longFirstImage->pixels,
  'The shorter overlapping melody note draws last regardless of insertion order.');
$shortOnly = new MADSCORE\Score\Score(2);
$shortOnly->place(0, 0, 0, 4, 4, 2);
$longOnly = new MADSCORE\Score\Score(2);
$longOnly->place(1, 0, 0, 4, 4, 4);
$shortImage = MADSCORE\Render\BarPainter::render($shortOnly, 0, 4, 240, 120);
$longImage = MADSCORE\Render\BarPainter::render($longOnly, 0, 4, 240, 120);
$shortInk = hexdec(substr($shortFirst->melodies[0]['color'], 1));
$longInk = hexdec(substr($shortFirst->melodies[1]['color'], 1));
$shortOnTop = false;
for ($offset = 0; $offset < strlen($shortImage->pixels); $offset += 4) {
  $top = (unpack('L', substr($shortFirstImage->pixels, $offset, 4))[1] >> 8) & 0xffffff;
  $shortPixel = (unpack('L', substr($shortImage->pixels, $offset, 4))[1] >> 8) & 0xffffff;
  $longPixel = (unpack('L', substr($longImage->pixels, $offset, 4))[1] >> 8) & 0xffffff;
  if ($shortPixel === $shortInk && $longPixel === $longInk && $top === $shortInk) {
    $shortOnTop = true;
    break;
  }
}
expectView($shortOnTop, 'The short note remains visible where both note shapes overlap.');
$sameLength = new MADSCORE\Score\Score(2);
$sameLength->place(0, 0, 0, 4, 4, 2);
$sameLength->place(1, 0, 0, 4, 4, 2);
expectView(MADSCORE\Render\BarPainter::render($sameLength, 0, 4, 240, 120)->pixels
  === $shortImage->pixels,
  'Equal-length notes at the same pitch and onset draw only once.');
$rangeScore = new MADSCORE\Score\Score();
$rangeScore->place(0, 0, 0, 3, 4, 2);
$rangeScore->place(0, 0, 2, 3, 4, 2);
$rangeScore->place(0, 0, 4, 5, 4, 2);
expectView(array_search(3, MADSCORE\Score\MelodyRange::bands($rangeScore, 0), true) === 2, 'The dominant octave takes an interior row.');
$rangeScore->place(0, 0, 6, 6, 4, 2);
expectView(MADSCORE\Score\MelodyRange::bands($rangeScore, 0) === [6, 5, 4, 3], 'The editor range follows the melody to its four octave limit.');
$symmetric = new MADSCORE\Score\Score();
$lowerEndpoint = $symmetric->place(0, 0, 0, 3, 1, 2);
$upperEndpoint = $symmetric->place(0, 0, 2, 6, 12, 2);
expectView(MADSCORE\Score\MelodyRange::bands($symmetric, 0, 2, 12) === [6, 5, 4, 3],
  'The pitch preview can reach the lower G-sharp before placing it.');
$symmetric->stepPitch($lowerEndpoint, -1);
expectView($symmetric->notes[$lowerEndpoint]['octave'] === 2
  && MADSCORE\Score\Pitch::position($symmetric->notes[$lowerEndpoint]) === 12,
  'The lower A steps down to the extra G-sharp endpoint.');
expectView(MADSCORE\Score\MelodyRange::bands($symmetric, 0) === [6, 5, 4, 3],
  'G-sharp to G-sharp uses the same four editor rows.');
expectView(MADSCORE\Score\Score::fromData($symmetric->data())->data() === $symmetric->data(),
  'Both G-sharp endpoints survive loading.');
$symmetricSheet = MADSCORE\Render\SheetPainter::render($symmetric, 0, 1, 250, 490, melody: 0, design: true);
$melodyInk = hexdec(substr($symmetric->melodies[0]['color'], 1));
$lowerPixel = (unpack('L', substr($symmetricSheet->pixels, (475 * 250 + 50) * 4, 4))[1] >> 8) & 0xffffff;
expectView($lowerPixel === $melodyInk, 'The lower G-sharp is visible in the bottom editor row.');
$lowerCursor = MADSCORE\Render\SheetPainter::render($symmetric, 0, 1, 250, 490,
  ['start' => 4, 'end' => 6, 'octave' => 2, 'pitch' => 12], 0, design: true);
$cursorPixel = (unpack('L', substr($lowerCursor->pixels, (476 * 250 + 150) * 4, 4))[1] >> 8) & 0xffffff;
expectView($cursorPixel === $melodyInk,
  'The cursor appears at the lower G-sharp outside the four stored octave bands.');
$sideAbove = (unpack('L', substr($lowerCursor->pixels, (470 * 250 + 135) * 4, 4))[1] >> 8) & 0xffffff;
$sideBelow = (unpack('L', substr($lowerCursor->pixels, (480 * 250 + 135) * 4, 4))[1] >> 8) & 0xffffff;
expectView($sideAbove === 0xffffff && $sideBelow !== 0xffffff,
  'The lower G-sharp cursor sides extend into the overflow octave below its crossbar.');
$printBands = MADSCORE\Render\Prescription::states($symmetric)[0]['bands'];
expectView($printBands === [6, 5, 4, 3]
  && MADSCORE\Render\Prescription::soundingBands($symmetric, 0) === [3, 6],
  'The lower G-sharp prints on the bottom staff without adding its stored octave.');
$symmetricPreview = MADSCORE\Render\PagePreview::render($symmetric, 0, 842, 595);
$blankPreview = new SPTK\Core\RasterImage(APP_DIR . '/Assets/preview-blank.png');
expectView($blankPreview->width === 1 && $blankPreview->height === 1
  && ((unpack('L', $blankPreview->pixels)[1] >> 8) & 0xffffff) === 0x0000aa,
  'The initial PDF viewer source is a plain blue pixel.');
$viewerPath = tempnam(sys_get_temp_dir(), 'madscore-viewer-');
try {
  MADSCORE\Render\PagePreview::writePng($symmetric, 0, $viewerPath, 1684, 1191);
  $viewer = new SPTK\Widgets\Image\Image($viewerPath, fit: 'contain', padding: false);
  expectView([$viewer->source()->width, $viewer->source()->height] === [1684, 1191],
    'SPTK image viewer can open a detailed PDF preview page.');
  $viewport = new SPTK\Layout\Tile(0, 0, 842, 595);
  $fitted = $viewer->destination($viewport);
  $viewer->handleInput((object)['type' => SPTK\SDLWrapper\SDL::SDL_EVENT_KEY_DOWN,
    'key' => (object)['key' => SPTK\SDLWrapper\SDL::KEY_PLUS, 'mod' => 0]]);
  $zoomed = $viewer->destination($viewport);
  $viewer->handleInput((object)['type' => SPTK\SDLWrapper\SDL::SDL_EVENT_KEY_DOWN,
    'key' => (object)['key' => SPTK\SDLWrapper\SDL::KEY_DOWN, 'mod' => 0]]);
  expectView($zoomed->width > $fitted->width && $viewer->destination($viewport)->y < $zoomed->y,
    'The PDF preview viewer zooms and scrolls the rendered page.');
} finally {
  unlink($viewerPath);
}
$printedPitch = false;
for ($y = 170; $y < 195; $y++) {
  for ($x = 20; $x < 50; $x++) {
    $pixel = (unpack('L', substr($symmetricPreview->pixels, ($y * 842 + $x) * 4, 4))[1] >> 8) & 0xffffff;
    $printedPitch = $printedPitch || $pixel === $melodyInk;
  }
}
expectView($printedPitch, 'The lower G-sharp is visible in the PDF preview.');
$symmetric->setSlurToNext($lowerEndpoint, true);
$slurredPreview = MADSCORE\Render\PagePreview::render($symmetric, 0, 842, 595);
expectView($slurredPreview->pixels !== $symmetricPreview->pixels,
  'A slur beginning at the lower overflow G-sharp appears in the PDF preview.');
$symmetricPdf = tempnam(sys_get_temp_dir(), 'madscore-low-gsharp-');
try {
  MADSCORE\Render\PdfWriter::write($symmetricPdf, $symmetric);
  expectView(str_starts_with(file_get_contents($symmetricPdf), '%PDF-') && filesize($symmetricPdf) > 1000,
    'The lower overflow G-sharp exports to PDF.');
} finally {
  unlink($symmetricPdf);
}
try {
  $symmetric->stepPitch($lowerEndpoint, -1);
  throw new \RuntimeException('A pitch below the lower G-sharp was accepted.');
} catch (\DomainException $error) {
  expectView(MADSCORE\Score\Pitch::position($symmetric->notes[$lowerEndpoint]) === 12,
    'The lower G-sharp remains the endpoint.');
}
try {
  $symmetric->stepPitch($upperEndpoint, 1);
  throw new \RuntimeException('A pitch above the upper G-sharp was accepted.');
} catch (\DomainException $error) {
  expectView(MADSCORE\Score\Pitch::position($symmetric->notes[$upperEndpoint]) === 12,
    'The upper G-sharp remains the endpoint.');
}
try {
  $rangeScore->stepPitch(3, 1);
} catch (\DomainException $error) {
  throw new \RuntimeException('A valid step within the four octave range was rejected.', 0, $error);
}
try {
  $rangeScore->setPitchPosition(3, 7, 4);
  throw new \RuntimeException('A pitch move beyond four octaves was accepted.');
} catch (\DomainException $error) {
  expectView($rangeScore->notes[3]['octave'] === 6, 'An invalid pitch edit preserves the note.');
}
$invalid = $rangeScore->data();
$invalid['bars'][] = $invalid['bars'][0];
$invalid['bars'][1]['sectionLeader'] = false;
$invalid['notes'][] = ['melody' => 0, 'bar' => 1, 'slot' => 0, 'octave' => 7, 'pitch' => 'A', 'alter' => 0, 'length' => 2];
try {
  MADSCORE\Score\Score::fromData($invalid);
  throw new \RuntimeException('A saved melody spanning five octaves was accepted.');
} catch (\DomainException $error) {
  expectView(true, 'A saved melody cannot bypass the range rule.');
}
for ($melody = 9; $melody < 14; $melody++) {
  $score->addMelody();
}
$score->place(13, 0, 2, 4, 6, 2);
try {
  $score->place(13, 0, 4, 8, 4, 2);
  throw new \RuntimeException('A fifth octave was accepted.');
} catch (\DomainException $error) {
  expectView(count($score->notes) === 10, 'An out-of-range note leaves the score intact.');
}
expectView(MADSCORE\Score\Score::fromData($score->data())->data() === $score->data(), 'A score with more than twelve melodies round trips in JSON.');
expectView(strlen((new MADSCORE\Play\ScoreSource($score, 13))->read(32)) === 128, 'A melody beyond the former limit can play.');
$pdf = tempnam(sys_get_temp_dir(), 'madscore-views-');
try {
  MADSCORE\Render\PdfWriter::write($pdf, $score);
  expectView(str_starts_with(file_get_contents($pdf), '%PDF-') && filesize($pdf) > 1000, 'A nine-band measure exports to PDF.');
  $preview = MADSCORE\Render\PagePreview::render($score, 0, 842, 595);
  expectView($preview->width === 842 && $preview->height === 595, 'PDF preview renders a full page at screen size.');
  $headerInkTop = null;
  for ($y = 0; $y < 45 && $headerInkTop === null; $y++) {
    for ($x = 35; $x < 260; $x++) {
      $rgb = (unpack('L', substr($preview->pixels, ($y * 842 + $x) * 4, 4))[1] >> 8) & 0xffffff;
      if ($rgb !== 0xffffff) {
        $headerInkTop = $y;
        break;
      }
    }
  }
  expectView($headerInkTop !== null && $headerInkTop >= 15,
    'The PDF header starts beyond a five-millimeter top printing margin.');
  $paperPixel = static fn (\SPTK\Core\RasterImage $image, int $x, int $y): string =>
    substr($image->pixels, ($y * $image->width + $x) * 4, 4);
  $widePreview = MADSCORE\Render\PagePreview::render(new MADSCORE\Score\Score(), 0, 1200, 600);
  $bluePixel = $paperPixel($widePreview, 0, 300);
  expectView($bluePixel === $paperPixel($widePreview, 1199, 300)
    && ((unpack('L', $bluePixel)[1] >> 8) & 0xffffff) === 0x0000aa
    && $bluePixel !== $paperPixel($widePreview, 600, 300),
    'A wide preview keeps the app blue on both sides of the centered A4 page.');
  $tallPreview = MADSCORE\Render\PagePreview::render(new MADSCORE\Score\Score(), 0, 600, 1200);
  expectView($paperPixel($tallPreview, 300, 0) === $bluePixel
    && $paperPixel($tallPreview, 300, 1199) === $bluePixel
    && $bluePixel !== $paperPixel($tallPreview, 300, 600),
    'A tall preview keeps the app blue above and below the centered A4 page.');
  $long = new MADSCORE\Score\Score();
  $long->ensureBar(69);
  $pages = MADSCORE\Render\PagePainter::pages($long);
  expectView(count($pages) > 1 && count(array_merge(...array_map(static fn (array $page): array => array_merge(...$page), $pages))) === 70,
    'Preview pagination covers every bar exactly once.');
  $headerPixels = static function(\SPTK\Core\RasterImage $image, int $left, int $right, int $top, int $bottom): string {
    $pixels = '';
    for ($y = $top; $y < $bottom; $y++) {
      $pixels .= substr($image->pixels, ($y * $image->width + $left) * 4, ($right - $left) * 4);
    }
    return $pixels;
  };
  $blankHeader = MADSCORE\Render\PagePreview::render($long, 0, 842, 595);
  $long->title = 'A Small Study';
  $long->author = 'Ada Example';
  $long->catalog = 'Op. 12';
  $firstPage = MADSCORE\Render\PagePreview::render($long, 0, 842, 595);
  $leftInk = 842;
  $rightInk = -1;
  for ($y = 0; $y < 595; $y++) {
    for ($x = 1; $x < 841; $x++) {
      $rgb = (unpack('L', $paperPixel($firstPage, $x, $y))[1] >> 8) & 0xffffff;
      if ($rgb !== 0xffffff) {
        $leftInk = min($leftInk, $x);
        $rightInk = max($rightInk, $x);
      }
    }
  }
  expectView($leftInk >= 16 && $leftInk <= 22 && $rightInk >= 819 && $rightInk <= 825,
    'The full A4 preview uses approximately 6.3 mm side margins without crossing them.');
  expectView($headerPixels($firstPage, 0, 500, 0, 45) !== $headerPixels($blankHeader, 0, 500, 0, 45)
    && $headerPixels($firstPage, 700, 842, 17, 29) !== $headerPixels($blankHeader, 700, 842, 17, 29),
    'The PDF preview prints title and author on the left and catalog on the right.');
  $laterPage = MADSCORE\Render\PagePreview::render($long, 1, 842, 595);
  expectView($laterPage->width === 842 && $laterPage->height === 595, 'A later PDF page can be previewed.');
  expectView($headerPixels($firstPage, 700, 842, 29, 42) !== $headerPixels($laterPage, 700, 842, 29, 42),
    'The PDF preview numbers each page against the total page count.');
} finally {
  unlink($pdf);
}

echo "View checks passed\n";
