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
expectVersions(MotifVersions::regenerationExtras($base, 0, 0) === [],
  'An untouched generated motif needs no overwrite confirmation.');
$mutedEmpty = clone $base;
$mutedMelody = $mutedEmpty->addMelody();
$mutedEmpty->setMelodyMuted($mutedMelody, true);
$withMuted = MotifVersions::regenerate($mutedEmpty, 0, 6, 3,
  'Major', 'C', $draw)['score'];
expectVersions($withMuted->beats(0) === 6 && $withMuted->melodyMuted($mutedMelody),
  'An empty muted melody does not block motif generation or lose its mute setting.');
$sixBeat = MotifScoreGenerator::place(new Score(), 6, 3, random: $draw,
  scale: 'Major', homeNote: 'C')['score'];
$sixBeatMuted = $sixBeat->addMelody();
$sixBeat->setMelodyMuted($sixBeatMuted, true);
$redrawnSixBeat = MotifVersions::regenerateSection($sixBeat, 0, 0, $draw);
expectVersions($redrawnSixBeat->beats(0) === 6
    && $redrawnSixBeat->melodyMuted($sixBeatMuted),
  'An empty muted melody does not block regeneration of a six-beat motif.');
$protected = clone $mutedEmpty;
$protected->setMelodyMuted($mutedMelody, false);
$protected->place($mutedMelody, 0, 0, 4, 4, 2);
$protected->setMelodyMuted($mutedMelody, true);
try {
  $protected->setBeats(0, 6);
  throw new RuntimeException('Changing beats altered a muted melody with notes.');
} catch (DomainException $error) {
  expectVersions(str_contains($error->getMessage(), 'muted and read-only'),
    'Changing beats still protects notes in a muted melody.');
}
$extras = clone $base;
$extras->addMelody();
$extras->place(1, 0, 0, 4, 4, 2);
$extras->setFinger(0, 'L1');
$extras->setScoreDynamicLevel(0, 'mf');
$extras->setSectionRepeats(0, 1);
$extras->setTempo(1, '90');
$extras->setBarNote(1, 'Added later');
expectVersions(MotifVersions::regenerationExtras($extras, 0, 0)
  === ['note marks', 'other notes', 'shared dynamics', 'repeats',
    'tempo changes', 'bar notes'],
  'Regeneration identifies added notes, marks, repeats, and later bar settings.');
$derivedExtras = MotifVersions::append($base, 0,
  ['algorithm' => 'reverse', 'source' => 0, 'parameters' => []], $draw)['score'];
$derivedStart = $derivedExtras->sectionStart(count($derivedExtras->bars) - 1);
$derivedExtras->setScoreDynamicLevel($derivedExtras->start($derivedStart), 'mf');
expectVersions(MotifVersions::regenerationExtras($derivedExtras, 0, 0)
    === ['shared dynamics'],
  'Regenerating the original also warns about additions in derived versions.');
$up = MotifScoreGenerator::generate(8, 3, $draw, 'Major', 'C',
  type: 'up-wave');
$down = MotifScoreGenerator::generate(8, 3, $draw, 'Major', 'C',
  type: 'down-wave');
$wideUp = MotifScoreGenerator::generate(8, 5, $draw, 'Major', 'C',
  type: 'up-wave');
$wideDown = MotifScoreGenerator::generate(8, 5, $draw, 'Major', 'C',
  type: 'down-wave');
expectVersions(array_slice($up['steps'], 0, 4) === [1, 1, -1, -1]
  && array_slice($down['steps'], 0, 4) === [-1, -1, 1, 1]
  && array_slice($wideUp['steps'], 0, 4) === [1, 1, 1, 1]
  && array_slice($wideDown['steps'], 0, 4) === [-1, -1, -1, -1]
  && $wideUp['pitchCount'] === 5 && $wideUp['palette'] === range(0, 4),
  'The note count controls the range of both up and down waves.');
$alternate = MotifScoreGenerator::generate(8, 5, $draw, 'Major', 'C',
  type: 'alternating');
$skipping = MotifScoreGenerator::generate(8, 5, $draw, 'Major', 'C',
  type: 'skipping');
expectVersions(array_slice(array_column($alternate['notes'], 'pitch'), 0, 5)
    === [60, 67, 62, 65, 64]
  && array_slice(array_column($skipping['notes'], 'pitch'), 0, 5)
    === [60, 64, 67, 62, 65]
  && $alternate['type'] === 'alternating' && $skipping['type'] === 'skipping',
  'Alternating and skipping visit each of five selected scale notes in order.');
foreach (['alternating', 'skipping'] as $type) {
  foreach (range(1, 7) as $count) {
    $pattern = MotifScoreGenerator::generate(max(3, $count), $count,
      $draw, 'Major', 'C', type: $type);
    expectVersions(count(array_unique(array_slice(array_column($pattern['notes'],
      'pitch'), 0, $count))) === $count,
      'Each ordered formula visits all selected notes, including even counts.');
  }
}
$skippingScore = MotifScoreGenerator::place(new Score(), 8, 5, random: $draw,
  scale: 'Major', homeNote: 'C', type: 'skipping')['score'];
expectVersions(Score::fromData($skippingScore->data())->melodies[0]['motif']['type']
    === 'skipping'
  && MotifVersions::regenerateSection($skippingScore, 0, 0, $draw)
    ->melodies[0]['motif']['type'] === 'skipping',
  'The selected skipping generator survives save and regeneration.');
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
$newSection = MotifVersions::generateAt($generated, 0, 1, 6, 3,
  'Major', 'C', 'up-wave', $draw)['score'];
$originalNotes = array_values(array_filter($newSection->notes,
  static fn (array $note): bool => $note['bar'] < count($base->bars)));
expectVersions($originalNotes === $base->notes
    && $newSection->bars[count($base->bars)]['motifVersion']['algorithm'] === 'generate'
    && $newSection->bars[count($base->bars)]['motifVersion']['source'] === null
    && Score::fromData($newSection->data())->data() === $newSection->data(),
  'Generate new can replace the selected version section and save its settings.');
$alternatingSection = MotifVersions::generateAt($generated, 0, 1, 6, 3,
  'Major', 'C', 'alternating', $draw)['score'];
expectVersions(Score::fromData($alternatingSection->data())
    ->bars[count($base->bars)]['motifVersion']['parameters']['type'] === 'alternating',
  'An independently generated section remembers the alternating formula.');
$oldWaveData = $newSection->data();
$oldWaveData['bars'][count($base->bars)]['motifVersion']['parameters']['type']
  = 'base-up-wave';
expectVersions(Score::fromData($oldWaveData)
    ->bars[count($base->bars)]['motifVersion']['parameters']['type'] === 'up-wave',
  'Previously saved wave names load as the combined wave type.');
$derivedFromNew = MotifVersions::append($newSection, 0,
  ['algorithm' => 'reverse', 'source' => 1, 'parameters' => []], $draw)['score'];
$changedTarget = MotifVersions::generateAt($derivedFromNew, 0, 1, 6, 3,
  'Major', 'C', 'down-wave', $draw)['score'];
$notesInSection = static fn (Score $score, int $section): array => array_values(array_filter(
  $score->notes, static fn (array $note): bool =>
    $score->sectionNumber($note['bar']) - 1 === $section));
expectVersions($notesInSection($changedTarget, 0) === $notesInSection($derivedFromNew, 0)
  && $notesInSection($changedTarget, 1) !== $notesInSection($derivedFromNew, 1)
  && $notesInSection($changedTarget, 2) !== $notesInSection($derivedFromNew, 2)
  && $changedTarget->bars[count($base->bars)]['motifVersion']['parameters']['type']
    === 'down-wave',
  'Replacing a generated target rebuilds versions derived from that section.');
$baseRedrawn = MotifVersions::regenerate($newSection, 0, 9, 4,
  'Major', 'C', $draw, 'random-step')['score'];
expectVersions(array_column($notesInSection($baseRedrawn, 1), 'pitch')
    === array_column($notesInSection($newSection, 1), 'pitch')
  && $baseRedrawn->bars[$baseRedrawn->sectionStart(count($baseRedrawn->bars) - 1)]
    ['motifVersion']['algorithm'] === 'generate',
  'Redrawing the original preserves an independently generated later section.');
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

$branch = MotifVersions::append($base, 0, $specs[2], $draw)['score'];
$branch = MotifVersions::append($branch, 0,
  ['algorithm' => 'reverse', 'source' => 1, 'parameters' => []], $draw)['score'];
$branch = MotifVersions::append($branch, 0,
  ['algorithm' => 'invert', 'source' => 0, 'parameters' => []], $draw)['score'];
$branch = MotifVersions::append($branch, 0,
  ['algorithm' => 'reverse', 'source' => 3, 'parameters' => []], $draw)['score'];
$sectionNotes = static fn (Score $score, int $section): array => array_values(array_filter(
  $score->notes, static fn (array $note): bool =>
    $score->sectionNumber($note['bar']) - 1 === $section));
$modified = clone $branch;
foreach ([1, 2, 3, 4] as $section) {
  $noteIndex = array_key_first(array_filter($modified->notes,
    static fn (array $note): bool =>
      $modified->sectionNumber($note['bar']) - 1 === $section));
  $modified->stepPitch($noteIndex, 1);
}
$partial = MotifVersions::regenerateSection($modified, 0, 1, $draw);
expectVersions($sectionNotes($partial, 0) === $sectionNotes($modified, 0)
  && $sectionNotes($partial, 1) === $sectionNotes($branch, 1)
  && $sectionNotes($partial, 2) === $sectionNotes($branch, 2)
  && $sectionNotes($partial, 3) === $sectionNotes($modified, 3)
  && $sectionNotes($partial, 4) === $sectionNotes($modified, 4),
  'Regenerating A1 rebuilds A1 and A2 while preserving the other branch.');

$manualBranch = clone $branch;
$manualStart = count($manualBranch->bars);
$manualBranch->ensureBar($manualStart);
$manualBranch->setSectionLeader($manualStart, true);
$manualBranch->place(0, $manualStart, 0, 4, 4, 2);
$manualBranch->place(0, $manualStart, 2, 4, 6, 2);
$manualBranch = MotifVersions::append($manualBranch, 0,
  ['algorithm' => 'reverse', 'source' => 5, 'parameters' => []], $draw)['score'];
$firstOnly = MotifVersions::regenerateSection($manualBranch, 0, 0,
  static fn (int $low, int $high): int => $high);
expectVersions($sectionNotes($firstOnly, 0) !== $sectionNotes($manualBranch, 0)
  && $sectionNotes($firstOnly, 5) === $sectionNotes($manualBranch, 5)
  && $sectionNotes($firstOnly, 6) === $sectionNotes($manualBranch, 6),
  'Regenerating A changes its descendants but leaves a manual branch intact.');
try {
  MotifVersions::regenerateSection($manualBranch, 0, 5, $draw);
  throw new RuntimeException('A handmade section was regenerated.');
} catch (DomainException $error) {
  expectVersions(str_contains($error->getMessage(), 'added by hand'),
    'Shift+C cannot regenerate a handmade section.');
}

$baseDraws = 0;
$countedLow = static function (int $low, int $high) use (&$baseDraws): int {
  $baseDraws++;
  return $low;
};
$sameBase = MotifScoreGenerator::place(new Score(), 8, 4, random: $countedLow,
  scale: 'Major', homeNote: 'C')['score'];
$tries = 0;
$lowThenHigh = static function (int $low, int $high) use (&$tries, $baseDraws): int {
  return ++$tries <= $baseDraws ? $low : $high;
};
$differentBase = MotifVersions::regenerateSection($sameBase, 0, 0, $lowThenHigh);
expectVersions($tries > $baseDraws && $differentBase->notes !== $sameBase->notes,
  'The original motif retries a repeated random draw.');
$tries = 0;
$differentFromGenerate = MotifVersions::regenerate($sameBase, 0, 8, 4,
  'Major', 'C', $lowThenHigh)['score'];
expectVersions($tries > $baseDraws && $differentFromGenerate->notes !== $sameBase->notes,
  'Generate new also retries a repeated original motif.');
$singleDraws = 0;
$singleLow = static function (int $low, int $high) use (&$singleDraws): int {
  $singleDraws++;
  return $low;
};
$single = MotifScoreGenerator::place(new Score(), 3, 1, random: $singleLow,
  scale: 'Major', homeNote: 'C')['score'];
$tries = 0;
$alwaysLow = static function (int $low, int $high) use (&$tries): int {
  $tries++;
  return $low;
};
$sameAgain = MotifVersions::regenerateSection($single, 0, 0, $alwaysLow);
expectVersions($sameAgain->notes === $single->notes && $tries === 12 * $singleDraws,
  'A motif with one possible result stops after twelve draws.');

$endingDraws = 0;
$endingLow = static function (int $low, int $high) use (&$endingDraws): int {
  $endingDraws++;
  return $low;
};
$ending = MotifVersions::append($base, 0, $specs[0], $endingLow)['score'];
$tries = 0;
$endingLowThenHigh = static function (int $low, int $high) use (&$tries, $endingDraws): int {
  return ++$tries <= $endingDraws ? $low : $high;
};
$differentEnding = MotifVersions::regenerateSection($ending, 0, 1, $endingLowThenHigh);
expectVersions($tries > $endingDraws
  && $sectionNotes($differentEnding, 1) !== $sectionNotes($ending, 1),
  'A random ending retries when its first result matches the old version.');
$fixedEnding = MotifVersions::append($base, 0, $specs[1], $endingLow)['score'];
$tries = 0;
$sameEnding = MotifVersions::regenerateSection($fixedEnding, 0, 1, $alwaysLow);
expectVersions($sectionNotes($sameEnding, 1) === $sectionNotes($fixedEnding, 1)
  && $tries === 12 * $specs[1]['parameters']['replaceNotes'],
  'A constrained random ending stops after twelve attempts.');

$screens = (new SPTK\XmlParser\XmlParser())->windows[0]['screens'];
$window = (new ReflectionClass(SPTK\Core\Window::class))->newInstanceWithoutConstructor();
(new ReflectionProperty(SPTK\Core\Window::class, 'screens'))->setValue($window, $screens);
foreach (['window' => $window, 'score' => $extras, 'activeItemId' => 'A',
  'view' => 'editor', 'melody' => 0, 'bar' => 0] as $name => $value) {
  (new ReflectionProperty(MADSCORE\App\Controller::class, $name))->setValue(null, $value);
}
$status = $screens[0]->statusBar;
$status->setScheduler(static function(int $durationMs, callable $dismiss): void {});
$beforeRegeneration = $extras->data();
MADSCORE\App\ControllerMotifGeneration::regenerateComposerFromSheet();
expectVersions($status->confirming() && str_contains($status->text(), 'other notes')
  && MADSCORE\App\Controller::score()->data() === $beforeRegeneration,
  'Shift+C asks before discarding additions to a motif.');
$status->handleBlockingInput((object)['type' => SPTK\SDLWrapper\SDL::SDL_EVENT_KEY_DOWN,
  'key' => (object)['key' => SPTK\SDLWrapper\SDL::KEY_ESCAPE, 'mod' => 0]]);
expectVersions(!$status->confirming() && MADSCORE\App\Controller::score()->data() === $beforeRegeneration,
  'Cancelling motif regeneration keeps the added material.');
(new ReflectionMethod(MADSCORE\App\ControllerMotifGeneration::class, 'applyMotif'))
  ->invoke(null, 8, 4, 0, 'Major', 'C', 'random-step', 0);
expectVersions($status->confirming() && MADSCORE\App\Controller::score()->data() === $beforeRegeneration,
  'Generate new also asks before replacing additions in the first section.');
$status->handleBlockingInput((object)['type' => SPTK\SDLWrapper\SDL::SDL_EVENT_KEY_DOWN,
  'key' => (object)['key' => SPTK\SDLWrapper\SDL::KEY_ESCAPE, 'mod' => 0]]);

echo "Motif version checks passed\n";
