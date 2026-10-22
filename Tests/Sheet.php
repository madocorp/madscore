<?php

define('APP_DIR', dirname(__DIR__));
define('APP_NAMESPACE', 'MADSCORE');
require APP_DIR . '/SPTK/App.php';

function sheetRgb(\SPTK\Core\RasterImage $image, int $x, int $y): int {
  $offset = ($y * $image->width + $x) * 4;
  return (unpack('L', substr($image->pixels, $offset, 4))[1] >> 8) & 0xffffff;
}

function expectSheet(bool $condition, string $message): void {
  if (!$condition) {
    throw new \RuntimeException($message);
  }
}

function hasSheetColor(\SPTK\Core\RasterImage $image, int $color, int $left, int $top, int $right, int $bottom): bool {
  for ($y = $top; $y < $bottom; $y++) {
    for ($x = $left; $x < $right; $x++) {
      if (sheetRgb($image, $x, $y) === $color) {
        return true;
      }
    }
  }
  return false;
}

$score = new MADSCORE\Score\Score(12);
$primaryRgb = hexdec(substr(MADSCORE\Score\Score::COLORS[0], 1));
$blank = MADSCORE\Render\SheetPainter::render($score, 0, 1, 250, 490);
$state = MADSCORE\Render\Prescription::states($score)[0];
$rows = MADSCORE\Render\Prescription::rows($state, 480, 48);
expectSheet($state['primary'] === 3 && array_keys($rows) === [4, 3, 2, 1],
  'An empty score centers its four rows on the middle-C band.');
$primaryLine = 5 + (int)round($rows[$state['primary']]['top'] + $rows[$state['primary']]['height'] / 12);
$emptyLine = 5 + (int)round($rows[4]['top'] + $rows[4]['height'] / 12);
expectSheet(sheetRgb($blank, 100, $primaryLine) !== 0xffffff, 'The primary octave staff crosses an empty bar.');
expectSheet(sheetRgb($blank, 100, $emptyLine) === 0xffffff, 'An unused secondary octave staff is omitted.');
expectSheet(hasSheetColor($blank, 0x000000, 10, 195, 23, 210)
  && hasSheetColor($blank, 0x000000, 10, 86, 23, 102),
  'The prescription draws marks for the middle-C band and the octave above.');

$high = new MADSCORE\Score\Score(12);
$high->place(0, 0, 0, 8, 11, 2);
$sharp = $high->place(0, 0, 2, 8, 12, 2);
expectSheet($high->notes[$sharp]['pitch'] === 'G' && $high->notes[$sharp]['alter'] === 1, 'The highest G-sharp can be placed normally.');
$highSheet = MADSCORE\Render\SheetPainter::render($high, 0, 1, 250, 490);
expectSheet(hasSheetColor($highSheet, $primaryRgb, 30, 30, 90, 60), 'The highest G and G-sharp draw above their staff.');
$highCursor = MADSCORE\Render\SheetPainter::render($high, 0, 1, 250, 490, ['bar' => 0, 'start' => 2, 'end' => 4, 'octave' => 8, 'pitch' => 12], 0);
expectSheet(hasSheetColor($highCursor, $primaryRgb, 85, 30, 125, 60), 'The cursor is visible on the highest G-sharp.');
$high->setMelodyColor(0, '#123abc');
$coloredSheet = MADSCORE\Render\SheetPainter::render($high, 0, 1, 250, 490);
expectSheet(hasSheetColor($coloredSheet, 0x123abc, 30, 30, 90, 60), 'A melody color change repaints its notes.');

$bar = MADSCORE\Render\BarPainter::render($score, 0, 4, 240, 240);
expectSheet(sheetRgb($bar, 1, 60) === sheetRgb($bar, 60, 20), 'Bar separators and staff lines use the same ink and stroke weight.');

$ending = new MADSCORE\Score\Score(12);
$ending->ensureBar(1);
$ending->place(0, 0, 0, 4, 4, 2);
$ending->place(0, 1, 0, 4, 4, 2);
$ending->place(1, 0, 0, 6, 4, 2);
$endingSheet = MADSCORE\Render\SheetPainter::render($ending, 0, 2, 500, 490);
$endingRows = MADSCORE\Render\Prescription::rows(MADSCORE\Render\Prescription::states($ending)[0], 480, 48);
$boundaryX = 261;
$secondaryY = 5 + (int)round($endingRows[6]['top'] + 3 * $endingRows[6]['height'] / 12);
$primaryY = 5 + (int)round($endingRows[4]['top'] + 3 * $endingRows[4]['height'] / 12);
expectSheet(sheetRgb($endingSheet, $boundaryX, $secondaryY) !== 0xffffff, 'A disappearing secondary staff has a right bar separator.');
expectSheet(sheetRgb($endingSheet, $boundaryX, $primaryY) === 0xffffff, 'A continuing primary staff stays open at the same bar boundary.');

echo "Sheet rendering checks passed\n";
