<?php

define('APP_DIR', dirname(__DIR__));
define('APP_NAMESPACE', 'MADSCORE');
require APP_DIR . '/SPTK/App.php';

function expectGlissando(bool $condition, string $message): void {
  if (!$condition) {
    throw new RuntimeException($message);
  }
}

/** Count crossings of the audio center line in a short window. */
function glissandoCrossings(string $pcm, int $start, int $length): int {
  $samples = array_values(unpack('g*', substr($pcm, $start * 4, $length * 4)));
  $crossings = 0;
  for ($index = 1; $index < count($samples); $index++) {
    $crossings += (int)($samples[$index - 1] <= 0 && $samples[$index] > 0);
  }
  return $crossings;
}

$definition = (new SPTK\XmlParser\XmlParser())->windows[0];
$window = (new ReflectionClass(SPTK\Core\Window::class))->newInstanceWithoutConstructor();
(new ReflectionProperty(SPTK\Core\Window::class, 'screens'))->setValue($window, $definition['screens']);
expectGlissando($window->screen('editor')->widget('glissando')->hotkey() === chr(92),
  'Backslash activates the glissando button.');

$score = new MADSCORE\Score\Score();
$first = $score->place(0, 0, 0, 4, 1, 2);
$second = $score->place(0, 0, 2, 4, 4, 2);
$ordinary = MADSCORE\Render\BarPainter::render($score, 0, 4, 240, 240);
$score->setGlissandoToNext($first, true);
expectGlissando($score->notes[$first]['glissandoToNext'] && count($score->notes) === 2,
  'A reversible glissando joins two notes without discarding their endpoints.');
$sloped = MADSCORE\Render\BarPainter::render($score, 0, 4, 240, 240);
expectGlissando($sloped->pixels !== $ordinary->pixels, 'The glissando replaces the ordinary note shapes.');
$pixel = static fn (SPTK\Core\RasterImage $image, int $x, int $y): int =>
  (unpack('L', substr($image->pixels, ($y * $image->width + $x) * 4, 4))[1] >> 8) & 0xffffff;
expectGlissando($pixel($sloped, 60, 150) !== 0xffffff && $pixel($sloped, 60, 170) === 0xffffff,
  'The sloped body has a colored outline and an empty interior.');
expectGlissando(MADSCORE\Score\Score::fromData($score->data())->data() === $score->data(),
  'A glissando saves and reloads with its endpoints.');
$invalid = $score->data();
$invalid['notes'][$second]['alter'] = 1;
try {
  MADSCORE\Score\Score::fromData($invalid);
  throw new RuntimeException('A saved glissando to a black key was accepted.');
} catch (DomainException) {
  expectGlissando($score->notes[$first]['glissandoToNext'], 'Rejecting invalid saved links leaves the live score unchanged.');
}
$source = new MADSCORE\Play\ScoreSource($score);
$events = (new ReflectionProperty(MADSCORE\Play\ScoreSource::class, 'events'))->getValue($source);
expectGlissando(count($events) === 3
  && array_map(static fn (array $event): int => $event['onset'], $events) === [0, 16000, 32000]
  && array_map(static fn (array $event): int => $event['ending'], $events) === [16000, 32000, 48000],
  'A to C plays A, B, and C as three equal note events over two beats.');
$pcm = '';
while (($part = $source->read(4096)) !== '') {
  $pcm .= $part;
}
$rate = MADSCORE\Play\ScoreSource::SAMPLE_RATE;
$early = glissandoCrossings($pcm, (int)(0.05 * $rate), (int)(0.05 * $rate));
$late = glissandoCrossings($pcm, (int)(0.85 * $rate), (int)(0.05 * $rate));
expectGlissando($late > $early, 'The sequence reaches a higher white key near its end.');
$score->setGlissandoToNext($first, false);
expectGlissando(MADSCORE\Render\BarPainter::render($score, 0, 4, 240, 240)->pixels === $ordinary->pixels,
  'Turning off the glissando restores both ordinary notes.');
$score->setGlissandoToNext($first, true);
$score->setStaccato($second, true);
expectGlissando(!$score->notes[$first]['glissandoToNext'],
  'Articulating the destination restores two separate notes.');

$crossBar = new MADSCORE\Score\Score();
$crossBar->ensureBar(1);
$from = $crossBar->place(0, 0, 6, 4, 4, 2);
$crossBar->place(0, 1, 0, 4, 6, 2);
$plainSheet = MADSCORE\Render\SheetPainter::render($crossBar, 0, 2, 500, 490);
$crossBar->setGlissandoToNext($from, true);
expectGlissando(MADSCORE\Render\SheetPainter::render($crossBar, 0, 2, 500, 490)->pixels !== $plainSheet->pixels,
  'A glissando crosses a bar line on the sheet.');
$pdf = tempnam(sys_get_temp_dir(), 'madscore-glissando-');
try {
  MADSCORE\Render\PdfWriter::write($pdf, $crossBar);
  expectGlissando(str_starts_with(file_get_contents($pdf), '%PDF-'), 'A glissando exports to PDF.');
} finally {
  unlink($pdf);
}
$crossBar->setPitchPosition($crossBar->nextAdjacent($from), 4, 5);
expectGlissando(!$crossBar->notes[$from]['glissandoToNext'],
  'Changing an endpoint to a black key removes the glissando.');
try {
  $crossBar->setGlissandoToNext($from, true);
  throw new RuntimeException('A black-key glissando was accepted.');
} catch (DomainException) {
  expectGlissando(!$crossBar->notes[$from]['glissandoToNext'], 'A rejected glissando leaves the score unchanged.');
}

$octaves = new MADSCORE\Score\Score();
$low = $octaves->place(0, 0, 0, 3, 1, 2);
$octaves->place(0, 0, 2, 5, 4, 2);
$octaves->setGlissandoToNext($low, true);
expectGlissando(array_intersect([3, 4, 5], MADSCORE\Render\Prescription::soundingBands($octaves, 0)) === [3, 4, 5],
  'Printing keeps every octave staff crossed by a long glissando.');

$seven = new MADSCORE\Score\Score();
$fromA = $seven->place(0, 0, 0, 4, 1, 3);
$seven->place(0, 0, 3, 4, 11, 3);
$seven->setGlissandoToNext($fromA, true);
$steps = (new ReflectionProperty(MADSCORE\Play\ScoreSource::class, 'events'))
  ->getValue(new MADSCORE\Play\ScoreSource($seven, 0, 0, 6));
expectGlissando(count($steps) === 7, 'A to G includes seven white-key notes.');
foreach (['A', 'B', 'C', 'D', 'E', 'F', 'G'] as $index => $key) {
  $frequency = 440.0 * 2 ** ((MADSCORE\Score\Pitch::naturalPosition($key) - 1) / 12);
  expectGlissando(abs($steps[$index]['frequency'] - $frequency) < 0.001
    && $steps[$index]['onset'] === (int)round($index * 1.5 / 7 * $rate)
    && $steps[$index]['ending'] === (int)round(($index + 1) * 1.5 / 7 * $rate),
    'Seven natural pitches divide three beats into seven equal written durations.');
}

echo "Glissando checks passed\n";
