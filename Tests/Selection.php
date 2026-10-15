<?php

define('APP_DIR', dirname(__DIR__));
define('APP_NAMESPACE', 'MADSCORE');
require APP_DIR . '/SPTK/App.php';

function expectSelection(bool $condition, string $message): void {
  if (!$condition) {
    throw new \RuntimeException($message);
  }
}

function selectionRgb(\SPTK\Core\RasterImage $image, int $x, int $y): int {
  $offset = ($y * $image->width + $x) * 4;
  return (unpack('L', substr($image->pixels, $offset, 4))[1] >> 8) & 0xffffff;
}

$selection = new MADSCORE\MelodySelection();
$score = new MADSCORE\Score();
$score->place(0, 0, 0, 4, 4, 2);
$score->place(0, 0, 4, 4, 6, 2);
[$bar, $slot] = $score->moveCursor(0, 0, 0, 1);
$selection->extend(0, $score->start($bar) + $slot, 0);
expectSelection($selection->state()['anchor'] === 0 && $selection->state()['caret'] === 2, 'Shift+Right uses the ordinary step from a note into its gap.');
[$bar, $slot] = $score->moveCursor(0, $bar, $slot, 1);
$selection->extend(2, $score->start($bar) + $slot, 0);
expectSelection($selection->state()['start'] === 0 && $selection->state()['end'] === 4, 'A second step extends to the next note without moving the anchor.');
$selection->extend(4, 0, 0);
expectSelection($selection->state() === null, 'Returning the caret to the anchor collapses the visible selection.');
$selection->clear();
$selection->extend(4, 2, 0);
expectSelection($selection->state()['anchor'] === 4 && $selection->state()['caret'] === 2
  && $selection->state()['start'] === 2 && $selection->state()['end'] === 4, 'Shift+Left keeps the later anchor and selects backward.');
$selection->extend(0, 3, 1);
expectSelection($selection->state()['melody'] === 1 && $selection->state()['anchor'] === 0, 'Changing melodies starts a selection for the new melody.');
$selection->clear();
expectSelection($selection->state() === null, 'Plain navigation clears the selection.');

$lastBar = new MADSCORE\Score();
expectSelection($lastBar->moveCursor(0, 0, 7, 1, true) === [0, 8], 'Selection can reach the end of the final bar.');
expectSelection($lastBar->moveCursor(0, 0, 8, 1, true) === [0, 8] && count($lastBar->bars) === 1, 'Extending selection past the end does not add a bar.');
expectSelection($lastBar->moveCursor(0, 0, 8, -1, true) === [0, 7], 'Selection can retract from the score end.');
$lastNote = new MADSCORE\Score();
$lastNote->place(0, 0, 6, 4, 4, 2);
expectSelection($lastNote->moveCursor(0, 0, 6, 1, true) === [0, 8] && count($lastNote->bars) === 1, 'Selecting through a final note does not append a bar.');
expectSelection($lastNote->moveCursor(0, 0, 8, -1, true) === [0, 6], 'Retracting from the end returns to the last note onset.');
expectSelection($lastBar->moveCursor(0, 0, 8, 1) === [1, 0], 'Plain navigation from a selection endpoint enters the next bar at its start.');
expectSelection($lastBar->moveCursor(0, 0, 7, 1) === [1, 0] && count($lastBar->bars) === 2, 'Ordinary navigation can still add a bar.');

$blank = new MADSCORE\Score();
$state = MADSCORE\Prescription::states($blank)[0];
$rows = MADSCORE\Prescription::rows($state, 480, 24);
$tops = array_column($rows, 'top');
$top = min($tops);
$bottom = max(array_map(static fn (array $row): int => $row['top'] + $row['height'], $rows));
$image = MADSCORE\SheetPainter::render($blank, 0, 1, 250, 490, selection: ['anchor' => 0, 'caret' => 2, 'start' => 0, 'end' => 2, 'melody' => 0]);
expectSelection(selectionRgb($image, 45, 5 + $top + 3) !== 0xffffff, 'The selection shades the upper octave row.');
expectSelection(selectionRgb($image, 45, 5 + $bottom - 5) !== 0xffffff, 'The selection shades the lower octave row.');
expectSelection(selectionRgb($image, 120, 5 + $top + 3) === 0xffffff, 'Time outside the selected span remains unshaded.');

echo "Selection checks passed\n";
