<?php

define('APP_DIR', dirname(__DIR__));
define('APP_NAMESPACE', 'MADSCORE');
require APP_DIR . '/SPTK/App.php';

function expectUndo(bool $condition, string $message): void {
  if (!$condition) {
    throw new RuntimeException($message);
  }
}

$score = new MADSCORE\Score\Score(2);
$history = new MADSCORE\App\MelodyHistory($score);
expectUndo(!$history->undo($score, 0), 'An untouched melody has nothing to undo.');
$first = $score->place(0, 0, 0, 4, 4, 2);
expectUndo($history->observe($score, 0), 'Adding a note records its melody.');
$score->place(1, 0, 0, 5, 4, 2);
$history->observe($score, 1);
$otherNote = $score->notes[$score->noteAt(1, 0, 0)];
expectUndo($history->undo($score, 0), 'The first melody can undo independently.');
expectUndo(count($score->notes) === 1 && $score->notes[0] === $otherNote,
  'Undo leaves the other melody untouched.');
expectUndo($history->redo($score, 0) && count($score->notes) === 2,
  'Redo restores the first melody beside the second.');
$score->setStaccato($score->noteAt(0, 0, 0), true);
$history->observe($score, 0);
expectUndo($history->undo($score, 0)
  && $score->notes[$score->noteAt(0, 0, 0)]['articulation'] === 'ordinary',
  'Undo restores note articulation.');
$score->setMelodyColor(1, '#123abc');
$history->observe($score, 1);
expectUndo($history->undo($score, 1) && $score->melodies[1]['color'] !== '#123abc',
  'A melody setting has its own undo step.');
expectUndo($history->redo($score, 0)
  && $score->notes[$score->noteAt(0, 0, 0)]['articulation'] === 'staccato',
  'Redo in the first melody survives undo in the second.');
expectUndo($history->undo($score, 0), 'The first melody has a redo branch after undo.');
$score->setMelodyVolume(0, 50);
$history->observe($score, 0);
expectUndo(!$history->redo($score, 0), 'A new edit clears only its melody redo branch.');
expectUndo($history->redo($score, 1) && $score->melodies[1]['color'] === '#123abc',
  'The other melody keeps its redo branch.');

$third = $score->addMelody();
$history->addedMelody($score, $third);
$score->place($third, 0, 2, 4, 6, 2);
$history->observe($score, $third);
$score->removeMelody(1);
$history->removedMelody(1);
expectUndo($history->undo($score, 1)
  && !in_array(1, array_column($score->notes, 'melody'), true),
  'Deleting an earlier melody shifts the later melody history with it.');
expectUndo($history->redo($score, 1)
  && in_array(1, array_column($score->notes, 'melody'), true),
  'The shifted melody can redo its note.');

echo "Per-melody undo checks passed\n";
