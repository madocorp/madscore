<?php

define('APP_DIR', dirname(__DIR__));
define('APP_NAMESPACE', 'MADSCORE');
require APP_DIR . '/SPTK/App.php';

function expectInsertion(bool $condition, string $message): void {
  if (!$condition) {
    throw new \RuntimeException($message);
  }
}

$score = new MADSCORE\Score();
$source = $score->put(2, 0, 7, 3, 'F', 5);
$score->notes[$source]['alter'] = -1;
$base = $score->baseAfter($source);
$note = $score->notes[$base];
expectInsertion([$note['melody'], $note['bar'], $note['slot'], $note['octave'], $note['pitch'], $note['alter'], $note['length']] === [2, 1, 4, 3, 'F', -1, 2], 'Insert adds a one-beat note at the source end with the same pitch.');

$duplicateScore = new MADSCORE\Score();
$source = $duplicateScore->put(2, 0, 7, 3, 'F', 5);
$duplicateScore->notes[$source]['alter'] = -1;
$duplicate = $duplicateScore->repeatAfter($source);
expectInsertion($duplicateScore->notes[$duplicate]['length'] === 5 && $duplicateScore->notes[$duplicate]['slot'] === 4, 'Shift+Insert keeps the source duration and onset offset.');

$shifted = new MADSCORE\Score();
$source = $shifted->put(0, 0, 0, 1, 'C', 2);
$adjacent = $shifted->put(0, 0, 2, 1, 'D', 2);
$later = $shifted->put(0, 0, 5, 1, 'E', 2);
$other = $shifted->put(1, 0, 0, 1, 'F', 8);
$otherLater = $shifted->put(1, 1, 0, 1, 'G', 2);
$inserted = $shifted->baseAfter($source);
expectInsertion($shifted->notes[$inserted]['slot'] === 2, 'Insert starts at the source end.');
expectInsertion($shifted->notes[$adjacent]['slot'] === 4 && $shifted->notes[$later]['slot'] === 7, 'Insert shifts following notes by its full length and preserves gaps.');
expectInsertion($shifted->notes[$other] === ['melody' => 1, 'bar' => 0, 'slot' => 0, 'octave' => 1, 'pitch' => 'F', 'alter' => 0, 'length' => 8] && $shifted->notes[$otherLater]['bar'] === 1 && $shifted->notes[$otherLater]['slot'] === 0, 'Insert leaves other melodies unchanged, including notes spanning the insertion point.');
MADSCORE\Score::fromData($shifted->data());

$shiftedCopy = new MADSCORE\Score();
$source = $shiftedCopy->put(0, 0, 0, 1, 'C', 3);
$later = $shiftedCopy->put(0, 0, 4, 1, 'D', 2);
$other = $shiftedCopy->put(1, 0, 4, 1, 'E', 2);
$duplicate = $shiftedCopy->repeatAfter($source);
expectInsertion($shiftedCopy->notes[$duplicate]['slot'] === 3 && $shiftedCopy->notes[$duplicate]['length'] === 3, 'Shift+Insert adds a full-length copy at the source end.');
expectInsertion($shiftedCopy->notes[$later]['slot'] === 7 && $shiftedCopy->notes[$other]['slot'] === 4, 'Shift+Insert shifts only its own melody by the copied length.');

echo "Insertion checks passed\n";
