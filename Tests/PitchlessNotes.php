<?php

define('APP_DIR', dirname(__DIR__));
define('APP_NAMESPACE', 'MADSCORE');
require APP_DIR . '/SPTK/App.php';

function expectPitchless(bool $condition, string $message): void {
  if (!$condition) {
    throw new RuntimeException($message);
  }
}

function pitchlessPixel(\SPTK\Core\RasterImage $image, int $x, int $y): int {
  return (unpack('L', substr($image->pixels, ($y * $image->width + $x) * 4, 4))[1] >> 8) & 0xffffff;
}

$score = new MADSCORE\Score\Score();
$score->place(0, 0, 0, 4, 4, 4);
$written = $score->notes;
$pitched = MADSCORE\Render\BarPainter::render($score, 0, 4, 240, 120);
expectPitchless(pitchlessPixel($pitched, 30, 70) === 0xffffff, 'A pitched note has a hollow circular onset.');
foreach (MADSCORE\Play\Drum::NAMES as $sound) {
  $score->setMelodyPattern(0, $sound);
  $image = MADSCORE\Render\BarPainter::render($score, 0, 4, 240, 120);
  expectPitchless(pitchlessPixel($image, 30, 70) !== 0xffffff,
    'Each unpitched sound gives a long note a pointed onset.');
  expectPitchless($score->notes === $written, 'Changing sound does not change written notes.');
}
$score->setMelodyPattern(0, 'organ');
expectPitchless(MADSCORE\Render\BarPainter::render($score, 0, 4, 240, 120)->pixels === $pitched->pixels,
  'Switching back to a pitched sound restores round notes.');

$score->setMelodyPattern(0, 'kick');
$score->notes[0]['length'] = 1;
$short = MADSCORE\Render\BarPainter::render($score, 0, 4, 240, 120);
expectPitchless(pitchlessPixel($short, 15, 70) !== 0xffffff
  && pitchlessPixel($short, 19, 61) !== 0xffffff
  && pitchlessPixel($short, 19, 79) !== 0xffffff
  && pitchlessPixel($short, 22, 70) === 0xffffff,
  'A half-beat unpitched note has a left edge, top and bottom lines, and a right notch.');
$score->notes[0]['length'] = 2;
$beat = MADSCORE\Render\BarPainter::render($score, 0, 4, 240, 120);
expectPitchless(pitchlessPixel($beat, 30, 61) !== 0xffffff
  && pitchlessPixel($beat, 30, 79) !== 0xffffff
  && pitchlessPixel($beat, 27, 70) !== 0xffffff
  && pitchlessPixel($beat, 33, 70) !== 0xffffff
  && pitchlessPixel($beat, 30, 70) === 0xffffff,
  'A one-beat unpitched note has both horizontal edges and an hourglass waist.');

echo "Pitchless note checks passed\n";
