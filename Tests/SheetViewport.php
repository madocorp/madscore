<?php

define('APP_DIR', dirname(__DIR__));
define('APP_NAMESPACE', 'MADSCORE');
require APP_DIR . '/SPTK/App.php';

function expectViewport(bool $condition, string $message): void {
  if (!$condition) {
    throw new RuntimeException($message);
  }
}

$score = new MADSCORE\Score\Score(3);
$score->ensureBar(11);
$score->place(0, 2, 4, 4, 4, 24);
$linked = $score->place(1, 3, 6, 4, 4, 2);
$score->place(1, 4, 0, 4, 6, 2);
$score->setSlurToNext($linked, true);
$glide = $score->place(1, 5, 6, 4, 4, 2);
$score->place(1, 6, 0, 4, 6, 2);
$score->setGlissandoToNext($glide, true);
$wedge = $score->place(2, 1, 0, 4, 4, 2);
$score->place(2, 8, 0, 4, 6, 2);
$score->setDynamicToNext($wedge, 'crescendo');
$window = new ReflectionMethod(MADSCORE\App\SheetCanvas::class, 'visibleWindow');
$bands = MADSCORE\Score\MelodyRange::bands($score, 0, 4, 4);
foreach ([3, 4, 5, 6] as $first) {
  $visible = $window->invoke(null, $score, $first, 3);
  $cursor = ['start' => $score->start($first), 'end' => $score->start($first) + 2,
    'bar' => $first, 'octave' => 4, 'pitch' => 4];
  $full = MADSCORE\Render\SheetPainter::render($score, $first, 3, 900, 490,
    $cursor, 0, null, true);
  $cropped = MADSCORE\Render\SheetPainter::render($visible, $first, 3, 900, 490,
    $cursor, 0, null, true, fixedBands: $bands);
  expectViewport($full->pixels === $cropped->pixels,
    'A visible sheet keeps spanning notes, slurs, glissandos, and dynamics.');
}
$score->setMelodyMuted(1, true);
$visible = $window->invoke(null, $score, 4, 3);
$cursor = ['start' => $score->start(4), 'end' => $score->start(4) + 2,
  'bar' => 4, 'octave' => 4, 'pitch' => 4];
$bands = MADSCORE\Score\MelodyRange::bands($score->visibleCopy(), 0, 4, 4);
expectViewport(MADSCORE\Render\SheetPainter::render($score, 4, 3, 900, 490,
  $cursor, 0, null, true)->pixels
  === MADSCORE\Render\SheetPainter::render($visible, 4, 3, 900, 490,
    $cursor, 0, null, true, fixedBands: $bands)->pixels,
  'The visible sheet preserves muted melody rendering.');

$score = new MADSCORE\Score\Score();
$cursor = ['start' => 0, 'end' => 2, 'bar' => 0, 'octave' => 3, 'pitch' => 4];
$bands = MADSCORE\Score\MelodyRange::bands($score, 0, 3, 4);
$base = MADSCORE\Render\SheetPainter::render($score, 0, 1, 400, 300,
  melody: 0, design: true, fixedBands: $bands);
$overlay = MADSCORE\Render\SheetPainter::cursorOverlay($score, 0, 1, 400, 300,
  $cursor, 0, $bands);
$full = MADSCORE\Render\SheetPainter::render($score, 0, 1, 400, 300,
  $cursor, 0, null, true);
$covered = 0;
for ($offset = 0; $offset < strlen($overlay->pixels); $offset += 4) {
  $alpha = ord($overlay->pixels[$offset]);
  if ($alpha === 0) {
    continue;
  }
  $covered++;
  $overlayPixel = unpack('L', substr($overlay->pixels, $offset, 4))[1];
  $basePixel = unpack('L', substr($base->pixels, $offset, 4))[1];
  $fullPixel = unpack('L', substr($full->pixels, $offset, 4))[1];
  for ($shift = 8; $shift <= 24; $shift += 8) {
    $premultiplied = ($overlayPixel >> $shift) & 255;
    $background = ($basePixel >> $shift) & 255;
    $expected = ($fullPixel >> $shift) & 255;
    expectViewport((int)round($premultiplied + $background * (255 - $alpha) / 255) === $expected,
      'The transparent cursor overlay has the same placement and color as a full redraw.');
  }
}
expectViewport($covered > 0, 'The cursor overlay contains visible pixels.');

$long = new MADSCORE\Score\Score();
$long->ensureBar(119);
for ($bar = 0; $bar < 120; $bar++) {
  $long->place(0, $bar, 0, 3, 4, 2);
}
$visible = $window->invoke(null, $long, 50, 4);
expectViewport(count($visible->notes) === 4,
  'An ordinary viewport paints its four visible notes instead of the whole score.');

$meter = new MADSCORE\Score\Score();
$meter->ensureBar(3);
$meter->setBeats(1, 3);
$meter->setBeats(3, 5);
$render = $meter->renderCopy();
foreach ([-1, 0, 1, 2, 3, 4, 5, 8] as $bar) {
  expectViewport($render->start($bar) === $meter->start($bar),
    'Cached render times preserve variable bar lengths and preview bars.');
}

echo "Sheet viewport checks passed\n";
