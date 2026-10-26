<?php

define('APP_DIR', dirname(__DIR__));
define('APP_NAMESPACE', 'MADSCORE');
require APP_DIR . '/SPTK/App.php';

/** Report a playback mapping regression. */
function expectPlayback(bool $condition, string $message): void {
  if (!$condition) {
    throw new RuntimeException($message);
  }
}

$score = new MADSCORE\Score\Score();
$score->ensureBar(2);
$score->setSectionLeader(2, true);
$score->setSectionRepeats(0, 1);
$score->setTempo(1, '60');
$timeline = new MADSCORE\Play\PlaybackTimeline($score);
expectPlayback($timeline->passes === [[0, 16, true], [0, 16, false], [16, 24, false]],
  'The visual timeline follows section repetitions in playback order.');
expectPlayback(abs($timeline->beats[4]['time'] - 2.0) < 0.000001
  && abs($timeline->beats[8]['time'] - 6.0) < 0.000001,
  'Bar tempo changes and a repeated section keep their elapsed times.');
expectPlayback($timeline->beatAt(6.01) === 8, 'The repeated opening beat is selected after the section jump.');
expectPlayback(abs($timeline->durationSeconds - 16.0) < 0.000001,
  'The written playback end excludes renderer-added silence.');
$fromCursor = new MADSCORE\Play\PlaybackTimeline($score, 1);
expectPlayback($fromCursor->passes === [[1, 16, true], [0, 16, false], [16, 24, false]]
  && $fromCursor->beats[0]['bar'] === 0 && $fromCursor->beats[0]['beat'] === 0
  && $fromCursor->beats[0]['length'] === 1
  && abs((new MADSCORE\Play\ScoreSource($score, null, 1))->durationSeconds()
    - $fromCursor->durationSeconds) < 0.000001,
  'Playback from a half-beat cursor keeps later section repeats and synchronized timing.');
$laterCursor = new MADSCORE\Play\PlaybackTimeline($score, 17);
expectPlayback($laterCursor->passes === [[17, 24, false]],
  'Starting in a later section skips earlier sections and their repeats.');
$held = new MADSCORE\Score\Score();
$held->place(0, 0, 0, 4, 1, 4);
$heldSource = new MADSCORE\Play\ScoreSource($held, null, 1);
$samples = unpack('g*', $heldSource->read(1000));
expectPlayback(max(array_map('abs', $samples)) > 0.001,
  'Built-in playback continues a note already sounding at the cursor.');
$selection = new MADSCORE\Play\PlaybackTimeline($score, 1, 5);
expectPlayback(count($selection->passes) === 1 && $selection->beats[0]['length'] === 1
  && abs($selection->beats[1]['time'] - 0.25) < 0.000001,
  'A selected half-beat begins with a partial beat and plays once.');
$tail = new MADSCORE\Score\Score();
$tail->setTempo(0, '600');
$tail->setMelodyPattern(0, 'kick');
$tail->place(0, 0, 7, 4, 1, 1);
expectPlayback((new MADSCORE\Play\ScoreSource($tail))->durationSeconds()
  > (new MADSCORE\Play\PlaybackTimeline($tail))->durationSeconds,
  'Built-in playback preserves an intentional final drum tail.');
$rect = MADSCORE\Render\SheetPainter::beatRect($score, 0, 2, 640, 360, 0, 0);
$next = MADSCORE\Render\SheetPainter::beatRect($score, 0, 2, 640, 360, 0, 1);
expectPlayback($rect[2] > 0 && $rect[3] > 0 && $next[0] >= $rect[0] + $rect[2] - 1,
  'Full-beat highlight regions advance across the score without overlap.');
$mixedBars = new MADSCORE\Score\Score();
$mixedBars->ensureBar(2);
$mixedBars->setBeats(1, 2);
$fourBeat = MADSCORE\Render\SheetPainter::beatRect($mixedBars, 0, 3, 640, 360, 0, 0);
$twoBeat = MADSCORE\Render\SheetPainter::beatRect($mixedBars, 0, 3, 640, 360, 1, 0);
$laterFourBeat = MADSCORE\Render\SheetPainter::beatRect($mixedBars, 0, 3, 640, 360, 2, 0);
expectPlayback(abs($fourBeat[2] - $twoBeat[2]) <= 1
  && abs($twoBeat[2] - $laterFourBeat[2]) <= 1,
  'Playback uses one beat width across four-beat and two-beat bars.');
$cacheScore = new MADSCORE\Score\Score();
$cacheScore->ensureBar(7);
$cursor = ['start' => 0, 'end' => 2, 'octave' => 4, 'pitch' => 4];
$cache = new MADSCORE\Play\SheetCache($cacheScore, 2, 320, 220, $cursor, 0, 0);
try {
  expectPlayback($cache->page(0) !== null && $cache->page(1) !== null,
    'The current and following page are ready before playback.');
  $deadline = microtime(true) + 5;
  while ($cache->page(3) === null && microtime(true) < $deadline) {
    usleep(10000);
  }
  expectPlayback($cache->page(3) !== null && $cache->failure() === null,
    'The background renderer prepares a later page.');
  expectPlayback($cache->page(2) !== null && $cache->page(0) !== null,
    'A repeat can revisit an opening page after the memory cache evicts it.');
} finally {
  $cache->close();
}

echo "Playback checks passed\n";
