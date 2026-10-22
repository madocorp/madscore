<?php

define('APP_DIR', dirname(__DIR__));
define('APP_NAMESPACE', 'MADSCORE');
require APP_DIR . '/SPTK/App.php';

use MADSCORE\Score\MotifScoreGenerator;
use MADSCORE\Score\MotifVersions;
use MADSCORE\Score\Score;

function expectVersions(bool $condition, string $message): void {
  if (!$condition) {
    throw new RuntimeException($message);
  }
}

$draw = static fn (int $low, int $high): int => min($high, max($low, 2));
$base = MotifScoreGenerator::place(new Score(), 8, 4, random: $draw,
  scale: 'Major', homeNote: 'C')['score'];
$beforeFirst = new Score();
$beforeFirst->ensureBar(1);
$beforeFirst->setSectionLeader(1, true);
$beforeFirst->setBarNote(1, 'Manual before generation');
$beforeFirst->place(0, 1, 0, 3, 4, 2);
$firstGeneration = MotifVersions::regenerate($beforeFirst, null, 4, 3,
  'Major', 'C', $draw)['score'];
expectVersions($firstGeneration->bars[1]['note'] === 'Manual before generation'
  && $firstGeneration->notes[count($firstGeneration->notes) - 1]['bar'] === 1,
  'First generation also preserves a hand-added later section.');
$specs = [
  ['algorithm' => 'keep-opening', 'source' => 0, 'parameters' => ['keepBeats' => 4]],
  ['algorithm' => 'new-ending', 'source' => 0, 'parameters' => ['replaceNotes' => 2]],
  ['algorithm' => 'transpose', 'source' => 0, 'parameters' => ['steps' => 1]],
  ['algorithm' => 'invert', 'source' => 0, 'parameters' => []],
  ['algorithm' => 'reverse', 'source' => 0, 'parameters' => []],
  ['algorithm' => 'rhythm', 'source' => 0, 'parameters' => ['shiftBeats' => 1]],
];
foreach ($specs as $spec) {
  $made = MotifVersions::append($base, 0, $spec, $draw);
  $score = $made['score'];
  $firstVersionBar = count($base->bars);
  $versionNotes = array_values(array_filter($score->notes,
    static fn (array $note): bool => $note['bar'] >= $firstVersionBar));
  $versionPitches = array_unique(array_map(static fn (array $note): int =>
    20 + 12 * $note['octave'] + MADSCORE\Score\Pitch::position($note), $versionNotes));
  expectVersions($made['version'] === 1
    && $score->bars[$firstVersionBar]['sectionLeader']
    && $score->bars[$firstVersionBar]['motifVersion'] === $spec
    && count($score->notes) === 2 * count($base->notes)
    && count($versionPitches) === 4
    && Score::fromData($score->data())->bars[$firstVersionBar]['motifVersion'] === $spec,
    'Each algorithm appends a saved generated section.');
}
$invalid = $base->data();
$invalid['bars'][0]['motifVersion'] = $specs[2];
try {
  Score::fromData($invalid);
  throw new RuntimeException('Generation settings on the original section were accepted.');
} catch (DomainException $error) {
  expectVersions(true, 'Only appended section leaders can store version settings.');
}

$generated = MotifVersions::append($base, 0, $specs[2], $draw)['score'];
$chained = MotifVersions::append($generated, 0,
  ['algorithm' => 'reverse', 'source' => 1, 'parameters' => []], $draw)['score'];
$chainedAgain = MotifVersions::regenerate($chained, 0, 8, 4,
  'Major', 'C', $draw)['score'];
expectVersions($chainedAgain->sectionNumber(count($chainedAgain->bars) - 1) === 3
  && $chainedAgain->bars[2 * count($base->bars)]['motifVersion']['source'] === 1,
  'A2 can be derived from A1 and rebuilt after the original changes.');
$versionBar = count($base->bars);
$edited = clone $generated;
$editedIndex = array_key_first(array_filter($edited->notes,
  static fn (array $note): bool => $note['bar'] >= $versionBar));
$edited->stepPitch($editedIndex, 1);
expectVersions($edited->notes[$editedIndex] !== $generated->notes[$editedIndex],
  'The generated version can be edited before regeneration.');

$manual = clone $edited;
$manualBar = count($manual->bars);
$manual->ensureBar($manualBar);
$manual->setSectionLeader($manualBar, true);
$manual->setBarNote($manualBar, 'Handmade');
$manual->place(0, $manualBar, 0, 3, 4, 2);
$manualNote = end($manual->notes);
expectVersions(!isset($manual->bars[$manualBar]['motifVersion']),
  'A section added by hand has no generation settings.');

$rebuilt = MotifVersions::regenerate($manual, 0, 8, 4, 'Major', 'C', $draw)['score'];
expectVersions($rebuilt->bars[$versionBar]['motifVersion'] === $specs[2]
  && $rebuilt->notes[$editedIndex] === $generated->notes[$editedIndex]
  && $rebuilt->bars[$manualBar]['note'] === 'Handmade'
  && $rebuilt->notes[count($rebuilt->notes) - 1] === $manualNote,
  'Regeneration discards edits in generated sections and preserves the manual section.');

$resized = MotifVersions::regenerate($manual, 0, 9, 4, 'Major', 'C', $draw)['score'];
$resizedManualBar = count($resized->bars) - 1;
$resizedManualNote = end($resized->notes);
expectVersions($resized->bars[$resizedManualBar]['note'] === 'Handmade'
  && $resizedManualNote['bar'] === $resizedManualBar
  && $resizedManualNote['pitch'] === $manualNote['pitch'],
  'A manual section follows the rebuilt sections when their bar count changes.');

$newScale = MotifVersions::regenerate($manual, 0, 8, 4,
  'Dorian', 'F#', $draw)['score'];
expectVersions(end($newScale->notes) === $manualNote
  && $newScale->melodies[0]['motif']['scale'] === 'Dorian'
  && $newScale->melodies[0]['motif']['homeNote'] === 'F#',
  'Changing scale and home note rebuilds generated sections without changing manual notes.');

echo "Motif version checks passed\n";
