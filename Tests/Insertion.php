<?php

define('APP_DIR', dirname(__DIR__));
define('APP_NAMESPACE', 'MADSCORE');
require APP_DIR . '/SPTK/App.php';

function expectInsertion(bool $condition, string $message): void {
  if (!$condition) {
    throw new \RuntimeException($message);
  }
}

$score = new MADSCORE\Score\Score(12);
$source = $score->put(2, 0, 7, 3, 'F', 5);
$score->notes[$source]['alter'] = -1;
$base = $score->baseAfter($source);
$note = $score->notes[$base];
expectInsertion([$note['melody'], $note['bar'], $note['slot'], $note['octave'], $note['pitch'], $note['alter'], $note['length']] === [2, 1, 4, 3, 'F', -1, 2], 'A base copy starts at the source end with the same pitch.');

$duplicateScore = new MADSCORE\Score\Score(12);
$source = $duplicateScore->put(2, 0, 7, 3, 'F', 5);
$duplicateScore->notes[$source]['alter'] = -1;
$duplicate = $duplicateScore->repeatAfter($source);
expectInsertion($duplicateScore->notes[$duplicate]['length'] === 5 && $duplicateScore->notes[$duplicate]['slot'] === 4, 'Duplicate keeps the source duration and onset offset.');

$shifted = new MADSCORE\Score\Score(12);
$source = $shifted->put(0, 0, 0, 1, 'C', 2);
$adjacent = $shifted->put(0, 0, 2, 1, 'D', 2);
$later = $shifted->put(0, 0, 5, 1, 'E', 2);
$other = $shifted->put(1, 0, 0, 1, 'F', 8);
$otherLater = $shifted->put(1, 1, 0, 1, 'G', 2);
$inserted = $shifted->baseAfter($source);
expectInsertion($shifted->notes[$inserted]['slot'] === 2, 'A base copy starts at the source end.');
expectInsertion($shifted->notes[$adjacent]['slot'] === 4 && $shifted->notes[$later]['slot'] === 7, 'A base copy shifts following notes by its full length and preserves gaps.');
expectInsertion($shifted->notes[$other] === ['melody' => 1, 'bar' => 0, 'slot' => 0, 'octave' => 1, 'pitch' => 'F', 'alter' => 0, 'length' => 8,
  'articulation' => 'ordinary', 'slurToNext' => false, 'glissandoToNext' => false, 'fermata' => false, 'trill' => 0, 'finger' => '', 'dynamicToNext' => '', 'dynamicLevel' => ''] && $shifted->notes[$otherLater]['bar'] === 1 && $shifted->notes[$otherLater]['slot'] === 0, 'A base copy leaves other melodies unchanged, including notes spanning the insertion point.');
MADSCORE\Score\Score::fromData($shifted->data());

$shiftedCopy = new MADSCORE\Score\Score(12);
$source = $shiftedCopy->put(0, 0, 0, 1, 'C', 3);
$later = $shiftedCopy->put(0, 0, 4, 1, 'D', 2);
$other = $shiftedCopy->put(1, 0, 4, 1, 'E', 2);
$duplicate = $shiftedCopy->repeatAfter($source);
expectInsertion($shiftedCopy->notes[$duplicate]['slot'] === 3 && $shiftedCopy->notes[$duplicate]['length'] === 3, 'Duplicate adds a full-length copy at the source end.');
expectInsertion($shiftedCopy->notes[$later]['slot'] === 7 && $shiftedCopy->notes[$other]['slot'] === 4, 'Duplicate shifts only its own melody by the copied length.');

$inGap = new MADSCORE\Score\Score(2);
$first = $inGap->put(0, 0, 0, 1, 'C', 2);
$later = $inGap->put(0, 0, 4, 1, 'D', 2);
$other = $inGap->put(1, 0, 2, 1, 'E', 2);
[$bar, $slot, $inserted] = $inGap->insertInverseBeat(0, 0, 2, 2, 1);
expectInsertion([$bar, $slot] === [0, 2] && $inserted !== null, 'Insert targets the left edge of an empty cursor.');
expectInsertion($inGap->notes[$inserted]['slot'] === 2 && $inGap->notes[$inserted]['length'] === 2 && $inGap->notes[$inserted]['octave'] === 2, 'Insert adds a one-beat note with the cursor pitch in an empty beat.');
expectInsertion($inGap->notes[$first]['slot'] === 0 && $inGap->notes[$later]['slot'] === 6 && $inGap->notes[$other]['slot'] === 2, 'Inserting a note shifts only later notes in the active melody.');
MADSCORE\Score\Score::fromData($inGap->data());

$onNote = new MADSCORE\Score\Score();
$onNote->put(0, 0, 0, 1, 'C', 2);
$next = $onNote->put(0, 0, 2, 1, 'D', 2);
[$bar, $slot, $inserted] = $onNote->insertInverseBeat(0, 0, 0, 2, 1);
expectInsertion([$bar, $slot] === [0, 0] && $inserted !== null,
  'A note at the score start has empty time on its left, so Insert adds a note.');
expectInsertion($onNote->notes[0]['slot'] === 2 && $onNote->notes[$next]['slot'] === 4
  && $onNote->notes[$inserted]['octave'] === 2
  && MADSCORE\Score\Pitch::position($onNote->notes[$inserted]) === 1,
  'The inserted note uses the cursor pitch and shifts the old note right.');
MADSCORE\Score\Score::fromData($onNote->data());

$adjacentNotes = new MADSCORE\Score\Score();
$left = $adjacentNotes->put(0, 0, 0, 1, 'C', 2);
$right = $adjacentNotes->put(0, 0, 2, 1, 'D', 2);
[$bar, $slot, $inserted] = $adjacentNotes->insertInverseBeat(0, 0, 2, 1, 1);
expectInsertion([$bar, $slot, $inserted] === [0, 2, null]
  && $adjacentNotes->notes[$left]['slot'] === 0
  && $adjacentNotes->notes[$right]['slot'] === 4
  && $adjacentNotes->noteAt(0, 0, 2) === null,
  'Insert creates a one-beat gap between adjacent notes.');
MADSCORE\Score\Score::fromData($adjacentNotes->data());

$gapBeforeNote = new MADSCORE\Score\Score();
$gapBeforeNote->put(0, 0, 0, 1, 'C', 2);
$afterGapNote = $gapBeforeNote->put(0, 0, 4, 1, 'D', 2);
[$bar, $slot, $inserted] = $gapBeforeNote->insertInverseBeat(0, 0, 4, 2, 1);
expectInsertion([$bar, $slot] === [0, 4] && $inserted !== null
  && $gapBeforeNote->notes[$afterGapNote]['slot'] === 6
  && $gapBeforeNote->notes[$inserted]['octave'] === 2,
  'Insert adds a cursor-pitch note when a gap precedes the current note.');
MADSCORE\Score\Score::fromData($gapBeforeNote->data());

$spanning = new MADSCORE\Score\Score();
$long = $spanning->put(0, 0, 0, 1, 'C', 5);
$later = $spanning->put(0, 0, 5, 1, 'D', 2);
[$bar, $slot, $inserted] = $spanning->insertInverseBeat(0, 0, 2, 1, 1);
expectInsertion([$bar, $slot] === [0, 0] && $inserted !== null,
  'Insert before a sustained note uses its onset when the cursor is inside it.');
expectInsertion($spanning->notes[$long]['slot'] === 2
  && $spanning->notes[$long]['length'] === 5 && $spanning->notes[$later]['slot'] === 7,
  'Insert shifts the sustained note and following notes without changing their lengths.');
expectInsertion($spanning->noteAt(0, 0, 0) === $inserted
  && $spanning->noteAt(0, 0, 2) === $long, 'The inserted note begins before the current note.');
MADSCORE\Score\Score::fromData($spanning->data());

$afterGap = new MADSCORE\Score\Score();
$followingGap = $afterGap->put(0, 0, 4, 1, 'D', 2);
[$bar, $slot, $inserted] = $afterGap->insertInverseBeat(0, 0, 0, 1, 1);
expectInsertion([$bar, $slot] === [0, 0] && $inserted !== null
  && $afterGap->notes[$followingGap]['slot'] === 6,
  'Insert fills the left edge of a gap and shifts following notes.');
MADSCORE\Score\Score::fromData($afterGap->data());

$acrossBar = new MADSCORE\Score\Score();
$acrossBar->put(0, 0, 6, 1, 'C', 2);
$following = $acrossBar->put(0, 1, 0, 1, 'D', 2);
[$bar, $slot, $inserted] = $acrossBar->insertInverseBeat(0, 0, 6, 1, 1);
expectInsertion([$bar, $slot] === [0, 6] && $inserted !== null,
  'Insert starts before a note at the end of a bar.');
expectInsertion($acrossBar->notes[0]['bar'] === 1 && $acrossBar->notes[0]['slot'] === 0
  && $acrossBar->notes[$following]['bar'] === 1 && $acrossBar->notes[$following]['slot'] === 2,
  'Insert shifts the selected note and following-bar note across the boundary.');
MADSCORE\Score\Score::fromData($acrossBar->data());

$boundary = new MADSCORE\Score\Score();
$atBoundary = $boundary->put(0, 1, 0, 1, 'C', 2);
[$bar, $slot, $inserted] = $boundary->insertInverseBeat(0, 0, 8, 1, 1);
expectInsertion([$bar, $slot] === [1, 0] && $inserted !== null
  && $boundary->notes[$atBoundary]['bar'] === 1
  && $boundary->notes[$atBoundary]['slot'] === 2,
  'Insert at a bar boundary adds a note before the next bar\'s note.');
MADSCORE\Score\Score::fromData($boundary->data());

$joinedBars = new MADSCORE\Score\Score();
$beforeBoundary = $joinedBars->put(0, 0, 6, 1, 'C', 2);
$afterBoundary = $joinedBars->put(0, 1, 0, 1, 'D', 2);
[$bar, $slot, $inserted] = $joinedBars->insertInverseBeat(0, 1, 0, 1, 1);
expectInsertion([$bar, $slot, $inserted] === [1, 0, null]
  && $joinedBars->notes[$beforeBoundary]['bar'] === 0
  && $joinedBars->notes[$beforeBoundary]['slot'] === 6
  && $joinedBars->notes[$afterBoundary]['bar'] === 1
  && $joinedBars->notes[$afterBoundary]['slot'] === 2,
  'Adjacent notes across a bar boundary receive a gap between them.');
MADSCORE\Score\Score::fromData($joinedBars->data());

echo "Insertion checks passed\n";
