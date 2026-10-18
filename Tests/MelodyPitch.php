<?php

define('APP_DIR', dirname(__DIR__));
define('APP_NAMESPACE', 'MADSCORE');
require APP_DIR . '/SPTK/App.php';

/** Check a pitch chosen when switching the selected melody. */
function expectMelodyPitch(?array $actual, ?array $expected, string $message): void {
  if ($actual !== $expected) {
    throw new RuntimeException($message);
  }
}

$score = new MADSCORE\Score\Score(2);
$score->place(1, 0, 0, 3, 4, 2);
$score->place(1, 1, 4, 3, 8, 2);
$score->place(0, 0, 4, 6, 2, 2);
expectMelodyPitch(MADSCORE\Score\MelodyRange::cursorPitch($score, 1, 0, 0), [3, 4], 'A note under the cursor sets its own pitch.');
expectMelodyPitch(MADSCORE\Score\MelodyRange::cursorPitch($score, 1, 0, 6), [3, 6], 'A cross-bar gap averages the surrounding melody notes.');
expectMelodyPitch(MADSCORE\Score\MelodyRange::cursorPitch($score, 1, 1, 6), [3, 8], 'After the last note, its pitch is used.');
$before = new MADSCORE\Score\Score(2);
$before->place(1, 0, 4, 2, 10, 2);
expectMelodyPitch(MADSCORE\Score\MelodyRange::cursorPitch($before, 1, 0, 0), [2, 10], 'Before the first note, its pitch is used.');
expectMelodyPitch(MADSCORE\Score\MelodyRange::cursorPitch($before, 0, 0, 0), null, 'An empty melody has no suggested pitch.');
$sustained = new MADSCORE\Score\Score(2);
$sustained->place(1, 0, 6, 4, 2, 4);
expectMelodyPitch(MADSCORE\Score\MelodyRange::cursorPitch($sustained, 1, 1, 0), [4, 2], 'A note sustained into the next bar counts as current.');
$semitone = new MADSCORE\Score\Score(2);
$semitone->place(1, 0, 0, 3, 4, 2);
$semitone->place(1, 0, 4, 3, 5, 2);
expectMelodyPitch(MADSCORE\Score\MelodyRange::cursorPitch($semitone, 1, 0, 2), [3, 5], 'A half-semitone average rounds upward.');
echo "Melody pitch checks passed\n";
