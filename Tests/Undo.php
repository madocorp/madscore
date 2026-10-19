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
$history = new MADSCORE\App\ScoreHistory($score);
expectUndo(!$history->undo($score), 'An untouched score has nothing to undo.');
$score->place(0, 0, 0, 4, 4, 2);
expectUndo($history->observe($score), 'Adding a note records an edit.');
$score->place(1, 0, 0, 5, 4, 2);
$history->observe($score);
expectUndo($history->undo($score) && count($score->notes) === 1 && $score->notes[0]['melody'] === 0,
  'Undo removes the latest edit even when it belongs to another melody.');
expectUndo($history->undo($score) && $score->notes === [], 'The next undo removes the earlier edit.');
expectUndo($history->redo($score) && count($score->notes) === 1, 'Redo restores the earlier edit.');
expectUndo($history->redo($score) && count($score->notes) === 2, 'Redo restores the later melody edit.');
$score->notes[0]['octave'] = 5;
$score->notes[1]['octave'] = 6;
$history->observe($score);
expectUndo($history->undo($score) && $score->notes[0]['octave'] === 4 && $score->notes[1]['octave'] === 5,
  'One undo restores a multi-melody edit together.');
$score->setMelodyColor(1, '#123abc');
$history->observe($score);
expectUndo(!$history->redo($score), 'A new edit clears the chronological redo branch.');
expectUndo($history->undo($score) && $score->melodies[1]['color'] !== '#123abc',
  'Melody settings participate in score-wide history.');
$score->addMelody();
$history->observe($score);
expectUndo($history->undo($score) && count($score->melodies) === 2,
  'Adding a melody is one undoable score edit.');
$score->setScoreDynamicLevel(0, 'fff');
$history->observe($score);
expectUndo($history->undo($score) && $score->scoreDynamics === [],
  'Undo removes a shared level.');
expectUndo($history->redo($score) && $score->scoreDynamics[0]['level'] === 'fff',
  'Redo restores a shared level.');

echo "Score undo checks passed\n";
