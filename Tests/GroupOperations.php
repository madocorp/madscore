<?php

define('APP_DIR', dirname(__DIR__));
define('APP_NAMESPACE', 'MADSCORE');
require APP_DIR . '/SPTK/App.php';

use MADSCORE\App\MelodySelection;
use MADSCORE\Score\ChordGenerator;
use MADSCORE\Score\Figure;
use MADSCORE\Score\RangeEdit;
use MADSCORE\Score\Score;

function expectGroup(bool $condition, string $message): void {
  if (!$condition) {
    throw new RuntimeException($message);
  }
}

$selection = new MelodySelection();
$selection->extend(2, 4, 0);
expectGroup($selection->state()['scope'] === 'melody', 'Shift creates a melody selection.');
$selection->extend(4, 5, 0, 'score');
expectGroup($selection->state()['scope'] === 'score' && $selection->state()['anchor'] === 4,
  'Changing selection scope starts at the current cursor.');

$score = new Score(2);
$score->place(0, 0, 0, 4, 4, 6);
$score->place(1, 0, 2, 5, 4, 4);
$score->place(0, 1, 0, 4, 6, 2);
$score->setScoreDynamicLevel(2, 'p');
$score->setScoreDynamicWedge(2, 5, 'crescendo');
$whole = ['scope' => 'score', 'melody' => 0, 'start' => 2, 'end' => 5];
$clip = RangeEdit::copy($score, $whole);
expectGroup($clip['duration'] === 3 && count($clip['notes']) === 2
  && $clip['notes'][0]['time'] === 0 && $clip['notes'][0]['note']['length'] === 3
  && $clip['notes'][1]['note']['length'] === 3 && $clip['dynamics'][0]['time'] === 0,
  'Whole-score copying clips notes and shared dynamics at both time boundaries.');
$figured = new Score();
$figuredIndex = $figured->place(0, 0, 0, 3, 4, 6);
$figured->setFigure($figuredIndex, Figure::parse('+2+4', $figured->notes[$figuredIndex]));
$middle = ['scope' => 'score', 'melody' => 0, 'start' => 2, 'end' => 4];
$figureClip = RangeEdit::copy($figured, $middle);
expectGroup(!isset($figureClip['notes'][0]['note']['figure'])
  && isset($figured->notes[0]['figure']),
  'Copying part of a figured note makes the copied fragment a normal note.');
$splitFigure = RangeEdit::clear($figured, $middle);
expectGroup(count($splitFigure->notes) === 2
  && !isset($splitFigure->notes[0]['figure'])
  && !isset($splitFigure->notes[1]['figure']),
  'Splitting a figured note does not duplicate its figure.');
$generated = ChordGenerator::place(new Score(), 0, 0, 6, 3, 1,
  ['scale' => 'Major', 'homeNote' => 'C'], 60, 0, 1, 0);
$splitGenerated = RangeEdit::clear($generated, $middle);
expectGroup(count($splitGenerated->notes) === 6
  && count(array_filter($splitGenerated->notes,
    static fn (array $note): bool => isset($note['chord']))) === 0,
  'Splitting a generated chord leaves ordinary note fragments without a shared ID.');
$oneVoice = RangeEdit::clear($generated,
  ['scope' => 'melody', 'melody' => 1, 'start' => 0, 'end' => 6]);
expectGroup(count($oneVoice->notes) === 2
  && count(array_filter($oneVoice->notes,
    static fn (array $note): bool => isset($note['chord']))) === 0,
  'Removing one chord voice clears stale generated-chord IDs on the remaining voices.');
$cleared = RangeEdit::clear($score, $whole);
expectGroup(count($cleared->notes) === 4 && $cleared->noteAt(0, 0, 2) === null
  && $cleared->noteAt(1, 0, 2) === null && $cleared->noteAt(0, 0, 5) !== null,
  'First Delete removes selected fragments and preserves outside fragments.');
$closed = RangeEdit::close($cleared, $whole);
expectGroup($closed->noteAt(0, 0, 2) !== null && $closed->noteAt(1, 0, 2) !== null
  && $closed->noteAt(0, 0, 5) !== null && $closed->scoreDynamics === [],
  'Second Delete closes the selected space in every melody.');
$before = $score->data();
$raised = RangeEdit::transpose($score, $whole, 1);
expectGroup($raised->noteAt(0, 0, 0) !== null && $raised->notes[$raised->noteAt(0, 0, 0)]['pitch'] === 'C'
  && $raised->notes[$raised->noteAt(0, 0, 2)]['alter'] === 1
  && $score->data() === $before,
  'Transposition splits selected note segments and leaves the source score unchanged.');
$replaced = RangeEdit::paste($score, ['scope' => 'score', 'duration' => 2, 'notes' => []], $whole, 0, 0);
expectGroup($replaced->noteAt(0, 0, 5) === null && $replaced->noteAt(0, 0, 4) !== null,
  'Replacing a three-slot range with two slots shifts later notes left by one.');
$inserted = RangeEdit::paste($score, $clip, null, 0, 6);
expectGroup($inserted->noteAt(0, 0, 6) !== null && $inserted->noteAt(1, 0, 6) !== null
  && $inserted->noteAt(0, 1, 3) !== null && $inserted->scoreDynamics[1]['time'] === 6,
  'Whole-score insertion places copied fragments in both melodies and shifts later notes.');
$pasteBefore = new Score();
$pasteBefore->place(0, 0, 0, 4, 4, 2);
$pasteBefore->place(0, 0, 2, 4, 8, 2);
$noteClip = RangeEdit::copy($pasteBefore,
  ['scope' => 'melody', 'melody' => 0, 'start' => 2, 'end' => 4]);
$pastedBefore = RangeEdit::paste($pasteBefore, $noteClip, null, 0, 0);
expectGroup($pastedBefore->notes[$pastedBefore->noteAt(0, 0, 0)]['pitch'] === 'E'
  && $pastedBefore->notes[$pastedBefore->noteAt(0, 0, 2)]['pitch'] === 'C'
  && $pastedBefore->notes[$pastedBefore->noteAt(0, 0, 4)]['pitch'] === 'E',
  'Pasting at a note onset inserts before that note and shifts it right.');
try {
  RangeEdit::paste($score, $clip, ['scope' => 'melody', 'melody' => 0, 'start' => 0, 'end' => 2], 0, 0);
  throw new RuntimeException('A mismatched paste scope was accepted.');
} catch (DomainException) {
  expectGroup($score->data() === $before, 'Rejected pastes leave the original score unchanged.');
}

$melody = new Score(2);
$first = $melody->place(0, 0, 0, 4, 4, 2);
$second = $melody->place(0, 0, 2, 4, 6, 2);
$third = $melody->place(0, 0, 4, 4, 8, 2);
$melody->place(1, 0, 0, 5, 4, 2);
$range = ['scope' => 'melody', 'melody' => 0, 'start' => 0, 'end' => 6];
$linked = RangeEdit::mark($melody, $range, 'link');
expectGroup($linked->notes[$first]['slurToNext'] && $linked->notes[$second]['slurToNext']
  && !$linked->notes[$third]['slurToNext'], 'L links adjacent selected notes without crossing melodies.');
$unlinked = RangeEdit::mark($linked, $range, 'link');
expectGroup(!$unlinked->notes[$first]['slurToNext'] && !$unlinked->notes[$second]['slurToNext'],
  'A second L clears the group links.');
$crescendo = RangeEdit::mark($melody, $range, 'dynamic');
$decrescendo = RangeEdit::mark($crescendo, $range, 'dynamic');
$plain = RangeEdit::mark($decrescendo, $range, 'dynamic');
expectGroup($crescendo->notes[$first]['dynamicToNext'] === 'crescendo'
  && $decrescendo->notes[$first]['dynamicToNext'] === 'decrescendo'
  && $plain->notes[$first]['dynamicToNext'] === '',
  '* cycles one group wedge through crescendo, decrescendo, and none.');
$high = new Score();
$high->place(0, 0, 0, 8, 12, 2);
try {
  RangeEdit::transpose($high, ['scope' => 'melody', 'melody' => 0, 'start' => 0, 'end' => 2], 1);
  throw new RuntimeException('An out-of-range transposition was accepted.');
} catch (DomainException) {
  expectGroup($high->notes[0]['octave'] === 8, 'A failed group transposition changes no notes.');
}

echo "Group operation checks passed\n";
