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
[$bar, $slot, $inserted] = $inGap->insertInverseBeat(0, 0, 0, 2, 2, 1);
expectInsertion([$bar, $slot] === [0, 2] && $inserted !== null, 'Insert targets the end of the cursor.');
expectInsertion($inGap->notes[$inserted]['slot'] === 2 && $inGap->notes[$inserted]['length'] === 2 && $inGap->notes[$inserted]['octave'] === 2, 'Insert adds a one-beat note with the cursor pitch in an empty beat.');
expectInsertion($inGap->notes[$first]['slot'] === 0 && $inGap->notes[$later]['slot'] === 6 && $inGap->notes[$other]['slot'] === 2, 'Inserting a note shifts only later notes in the active melody.');
MADSCORE\Score\Score::fromData($inGap->data());

$onNote = new MADSCORE\Score\Score();
$onNote->put(0, 0, 0, 1, 'C', 2);
$next = $onNote->put(0, 0, 2, 1, 'D', 2);
[$bar, $slot, $inserted] = $onNote->insertInverseBeat(0, 0, 0, 2, 1, 1);
expectInsertion([$bar, $slot, $inserted] === [0, 2, null], 'Insert creates a gap when the next beat contains a note.');
expectInsertion($onNote->notes[$next]['slot'] === 4 && $onNote->noteAt(0, 0, 2) === null, 'The inserted gap is one beat wide.');
MADSCORE\Score\Score::fromData($onNote->data());

$spanning = new MADSCORE\Score\Score();
$long = $spanning->put(0, 0, 0, 1, 'C', 5);
$later = $spanning->put(0, 0, 5, 1, 'D', 2);
[$bar, $slot, $inserted] = $spanning->insertInverseBeat(0, 0, 2, 5, 1, 1);
expectInsertion([$bar, $slot, $inserted] === [0, 5, null], 'Insert after a sustained note uses its true end, even when the cursor is inside it.');
expectInsertion($spanning->notes[$long]['length'] === 5 && $spanning->notes[$later]['slot'] === 7, 'Insert leaves the current note intact and moves the following note right.');
expectInsertion($spanning->noteAt(0, 0, 2) === $long && $spanning->noteAt(0, 0, 5) === null, 'The inserted gap begins after the current note.');
MADSCORE\Score\Score::fromData($spanning->data());

$afterGap = new MADSCORE\Score\Score();
$followingGap = $afterGap->put(0, 0, 4, 1, 'D', 2);
[$bar, $slot, $inserted] = $afterGap->insertInverseBeat(0, 0, 0, 4, 1, 1);
expectInsertion([$bar, $slot, $inserted] === [0, 4, null] && $afterGap->notes[$followingGap]['slot'] === 6, 'Insert uses the end of the selected gap, rather than its first beat.');
MADSCORE\Score\Score::fromData($afterGap->data());

$acrossBar = new MADSCORE\Score\Score();
$acrossBar->put(0, 0, 6, 1, 'C', 2);
$following = $acrossBar->put(0, 1, 0, 1, 'D', 2);
[$bar, $slot, $inserted] = $acrossBar->insertInverseBeat(0, 0, 6, 2, 1, 1);
expectInsertion([$bar, $slot, $inserted] === [1, 0, null], 'Insert finds the cursor end across a bar line.');
expectInsertion($acrossBar->notes[$following]['bar'] === 1 && $acrossBar->notes[$following]['slot'] === 2, 'Insert shifts a following-bar note by one beat.');
MADSCORE\Score\Score::fromData($acrossBar->data());

echo "Insertion checks passed\n";
