<?php

define('APP_DIR', dirname(__DIR__));
define('APP_NAMESPACE', 'MADSCORE');
require APP_DIR . '/SPTK/App.php';

use MADSCORE\App\ScoreHistory;
use MADSCORE\Score\{ChordGenerator, MotifArrangement, MotifScoreGenerator,
  MotifVersions, Project, Score};

function checkChord(bool $condition, string $message): void {
  if (!$condition) {
    throw new RuntimeException($message);
  }
}

$music = ['scale' => 'Major', 'homeNote' => 'C'];
$score = new Score();
$score->place(0, 0, 0, 3, 4, 2);
$score->setSectionMusic(0, $music);
$chord = ChordGenerator::place($score, 0, 0, 2, 3, 1, $music, 60, 0, 1, 0);
checkChord(count($chord->notes) === 3 && count($chord->melodies) === 3,
  'A triad keeps the selected note and adds one melody per other tone.');
$group = ChordGenerator::groupAt($chord, 0, 1);
checkChord($group['count'] === 3 && $group['degree'] === 1
  && $group['anchor'] === 60
  && $group['placement'] === 'fixed'
  && count(array_unique(array_column($chord->notes, 'melody'))) === 3,
  'A chord has three separate voices and can be found while sounding.');
$fixedNote = $chord->notes[$chord->noteAt(0, 0, 0)];
checkChord($fixedNote['octave'] === $score->notes[0]['octave']
  && $fixedNote['pitch'] === $score->notes[0]['pitch']
  && $fixedNote['length'] === $score->notes[0]['length']
  && $fixedNote['chord']['fixed'] === true,
  'The existing melody note keeps its pitch and duration.');
$free = ChordGenerator::place(new Score(), 0, 0, 2, 3, 1,
  $music, 60, 0, 1, 0);
$top = $free->notes[$free->noteAt(0, 0, 0)];
$topPitch = 20 + 12 * $top['octave'] + MADSCORE\Score\Pitch::position($top);
checkChord($topPitch === max(array_map(static fn (array $note): int =>
  20 + 12 * $note['octave'] + MADSCORE\Score\Pitch::position($note), $free->notes)),
  'In empty space the selected melody receives the highest chord tone.');
$inside = ChordGenerator::place($score, 0, 1, 2, 3, 1, $music, 60, 0, 1, 0);
checkChord($inside->noteAt(0, 0, 0) !== null
  && ChordGenerator::groupAt($inside, 0, 0, 0) !== null
  && count($inside->notes) === 3,
  'Placing a chord inside a selected note replaces that note from its onset.');
$replacedChord = ChordGenerator::place($chord, 0, 0, 2, 4, 1, $music,
  60, 0, 1, 0, replaceId: $group['id']);
checkChord(count($replacedChord->notes) === 4
  && ChordGenerator::groupAt($replacedChord, 0, 0, 0)['count'] === 4
  && $replacedChord->notes[$replacedChord->noteAt(0, 0, 0)]['pitch']
    === $score->notes[0]['pitch'],
  'Reopening settings can replace the chord while preserving its fixed note.');
checkChord(Score::fromData($chord->data())->data() === $chord->data(),
  'Chord metadata and section harmony survive a score round trip.');
$earlierData = $free->data();
foreach ($earlierData['notes'] as &$note) {
  if (isset($note['chord'])) {
    unset($note['chord']['anchor'], $note['chord']['placement']);
  }
}
unset($note);
$earlier = Score::fromData($earlierData);
$oldBass = min(array_map(static fn (array $note): int =>
  20 + 12 * $note['octave'] + MADSCORE\Score\Pitch::position($note), $earlier->notes));
checkChord(ChordGenerator::groupAt(ChordGenerator::reroll($earlier, 0, 0), 0, 0)['anchor'] === $oldBass,
  'An existing chord gains a stable anchor on its next reroll.');

$draw = 0;
$different = ChordGenerator::reroll($chord, 0, 1,
  static function (int $minimum, int $maximum) use (&$draw): int {
    $draw++;
    return $draw % 2 ? min($maximum, $minimum + 1) : $minimum;
  });
checkChord($different->notes !== $chord->notes
  && $different->noteAt(0, 0, 0) !== null
  && $different->notes[$different->noteAt(0, 0, 0)]['pitch']
    === $score->notes[0]['pitch'],
  'Reroll changes supporting tones while keeping the melody note fixed.');
$fixedRun = $chord;
$fixedDraw = 0;
for ($step = 0; $step < 40; $step++) {
  $fixedRun = ChordGenerator::reroll($fixedRun, 0, 0,
    static function (int $minimum, int $maximum) use (&$fixedDraw): int {
      return $minimum + (++$fixedDraw % ($maximum - $minimum + 1));
    });
  $kept = $fixedRun->notes[$fixedRun->noteAt(0, 0, 0)];
  checkChord($kept['octave'] === $score->notes[0]['octave']
    && $kept['pitch'] === $score->notes[0]['pitch']
    && $kept['alter'] === $score->notes[0]['alter']
    && $kept['length'] === $score->notes[0]['length'],
    'Repeated rerolls never change the fixed melody note.');
}
$chromatic = new Score();
$chromatic->place(0, 0, 0, 3, 10, 2);
try {
  ChordGenerator::place($chromatic, 0, 0, 2, 3, 1, $music, 66, 0);
  throw new RuntimeException('A note outside the scale was changed.');
} catch (DomainException $error) {
  checkChord(str_contains($error->getMessage(), 'does not fit a chord')
    && $chromatic->notes[0]['pitch'] === 'F',
    'An incompatible melody note is rejected without changing it.');
}
$history = new ScoreHistory($chord);
$history->observe($different);
$history->undo($different);
checkChord($different->data() === $chord->data(),
  'Undo restores the previous generated chord.');

$repeated = $free;
$seed = 0;
for ($step = 0; $step < 80; $step++) {
  $repeated = ChordGenerator::reroll($repeated, 0, 0,
    static function (int $minimum, int $maximum) use (&$seed): int {
      return $minimum + (++$seed % ($maximum - $minimum + 1));
    });
  $bass = ChordGenerator::groupAt($repeated, 0, 0);
  checkChord($bass['anchor'] === 60,
    'Repeated rerolls preserve the original pitch anchor.');
  $currentTop = $repeated->notes[$repeated->noteAt(0, 0, 0)];
  $currentTopMidi = 20 + 12 * $currentTop['octave']
    + MADSCORE\Score\Pitch::position($currentTop);
  checkChord($currentTopMidi === max(array_map(static fn (array $note): int =>
    20 + 12 * $note['octave'] + MADSCORE\Score\Pitch::position($note),
    $repeated->notes)),
    'Reroll keeps the highest tone in the selected melody.');
  foreach (array_keys($repeated->melodies) as $melody) {
    $pitch = MADSCORE\Score\MelodyRange::cursorPitch($repeated, $melody, 0, 0);
    MADSCORE\Score\MelodyRange::bands($repeated, $melody, ...$pitch);
  }
}
$topNote = $repeated->notes[$repeated->noteAt(0, 0, 0)];
$topMidi = 20 + 12 * $topNote['octave']
  + MADSCORE\Score\Pitch::position($topNote);
checkChord(abs($topMidi - 60) <= 6,
  'Repeated rerolls keep the top tone near the original cursor pitch.');
foreach (['score' => $repeated, 'melody' => 0,
  'bar' => 0, 'slot' => 0, 'octave' => 8, 'pitchPosition' => 12] as $field => $value) {
  (new ReflectionProperty(MADSCORE\App\Controller::class, $field))->setValue(null, $value);
}
(new ReflectionMethod(MADSCORE\App\ControllerChords::class, 'followChordPitch'))->invoke(null);
$cursorOctave = (new ReflectionProperty(MADSCORE\App\Controller::class, 'octave'))->getValue();
$cursorPosition = (new ReflectionProperty(MADSCORE\App\Controller::class, 'pitchPosition'))->getValue();
MADSCORE\Score\MelodyRange::bands($repeated, 0,
  $cursorOctave, $cursorPosition);
checkChord($cursorOctave === $topNote['octave']
  && $cursorPosition === MADSCORE\Score\Pitch::position($topNote),
  'After a reroll the cursor follows the new note into visible pitch rows.');

$series = ChordGenerator::place(new Score(), 0, 0, 2, 3, 3, $music,
  60, 0, 1, 0);
checkChord(count($series->notes) === 9 && count($series->bars) === 1,
  'A series places evenly spaced chords at the cursor length.');
checkChord(count(array_filter($series->notes,
  static fn (array $note): bool => $note['melody'] === 0)) === 3,
  'Every chord in a series puts its highest tone in the current melody.');
$ids = array_unique(array_column(array_column($series->notes, 'chord'), 'id'));
checkChord(count($ids) === 3, 'Each chord in a series has a separate group ID.');
$longSeries = ChordGenerator::place(new Score(), 0, 0, 2, 3, 5, $music,
  60, 0, 1, 0);
checkChord(ChordGenerator::groupAt($longSeries, 1, 0) !== null,
  'A chord series continues into the next bar.');
$split = new Score();
$split->ensureBar(1);
$split->setSectionLeader(1, true);
try {
  ChordGenerator::place($split, 0, 0, 2, 3, 5, $music, 60, 0, 1, 0);
  throw new RuntimeException('A series crossed into a different section.');
} catch (DomainException $error) {
  checkChord(str_contains($error->getMessage(), 'another section'),
    'A series stops at a section with its own harmonic context.');
}
try {
  ChordGenerator::place($series, 0, 0, 2, 3, 1, $music, 60, 0, 1, 0);
  throw new RuntimeException('A chord overwrote an occupied voice.');
} catch (DomainException $error) {
  checkChord(str_contains($error->getMessage(), 'cannot be used')
    || str_contains($error->getMessage(), 'already has a note'),
    'An occupied generated chord cannot be overwritten as a new chord.');
}

$project = new Project();
$a = $project->create();
$b = $project->create();
$aScore = ChordGenerator::place(new Score(), 0, 0, 2, 3, 1, $music, 60, 0, 1, 0);
$aScore->setSectionMusic(0, $music);
$project->setScore($a, $aScore);
$minor = ['scale' => 'Minor', 'homeNote' => 'A'];
$bScore = new Score();
$bScore->place(0, 0, 0, 4, 4, 2);
$bScore->setSectionMusic(0, $minor);
$project->setScore($b, $bScore);
$arranged = MotifArrangement::build($project, 'A B | A');
checkChord($arranged->sectionMusic(0) === null
  && $arranged->sectionMusic(2) === $music,
  'Mixed motif keys leave a section unset; a matching section inherits its key.');
checkChord(Score::fromData($arranged->data())->data() === $arranged->data(),
  'Arranged chord voices and keys survive save and load.');

$motif = MotifScoreGenerator::place(new Score(), 4, 3,
  scale: 'Major', homeNote: 'C')['score'];
$motif = ChordGenerator::place($motif, 0, 0,
  $motif->notes[$motif->noteAt(0, 0, 0)]['length'], 3, 1, $music, 60, 0, 1, 0);
$versioned = MotifVersions::append($motif, 0,
  ['algorithm' => 'reverse', 'source' => 0, 'parameters' => []])['score'];
$originalId = ChordGenerator::groupAt($versioned, 0, 0)['id'];
$versionId = ChordGenerator::groupAt($versioned, 1, 0)['id'];
checkChord($originalId !== $versionId,
  'A derived motif section gives its copied chord a separate group ID.');
checkChord(Score::fromData($versioned->data())->data() === $versioned->data(),
  'A derived section with chords remains valid.');

$screens = (new SPTK\XmlParser\XmlParser())->windows[0]['screens'];
foreach ($screens as $screen) {
  if ($screen->id !== 'editor') {
    continue;
  }
  checkChord($screen->show('chordPanel')
    && $screen->restore('chordPanel')
    && $screen->show('chordScalePicker'),
    'The chord scale list replaces the chord panel.');
  $screen->widget('chordScalePicker')->setValue('Dorian');
  checkChord($screen->widget('chordScalePicker')->activeValue() === 'Dorian'
    && $screen->restore('chordScalePicker')
    && $screen->show('chordHomeNotePicker'),
    'The chord scale and home-note lists select named values.');
  $screen->widget('chordHomeNotePicker')->setValue('G#');
  checkChord($screen->widget('chordHomeNotePicker')->activeValue() === 'G#'
    && $screen->restore('chordHomeNotePicker'),
    'The home-note list returns to the chord panel.');
  checkChord($screen->show('chordPanel') && $screen->restore('chordPanel')
    && $screen->show('composerPanel'),
    'Composer can occupy the note tile after chord settings close.');
  break;
}

echo "chord checks passed.\n";
