<?php

define('APP_DIR', dirname(__DIR__));
define('APP_NAMESPACE', 'MADSCORE');
require APP_DIR . '/SPTK/App.php';

use MADSCORE\Composer\Generator;
use MADSCORE\App\ScoreHistory;
use MADSCORE\Play\MidiWriter;
use MADSCORE\Play\ScoreSource;
use MADSCORE\Render\Prescription;
use MADSCORE\Render\PagePreview;
use MADSCORE\Render\SheetPainter;
use MADSCORE\Score\RangeEdit;
use MADSCORE\Score\Score;

function expectComposer(bool $condition, string $message): void {
  if (!$condition) {
    throw new RuntimeException($message);
  }
}

$screens = (new SPTK\XmlParser\XmlParser())->windows[0]['screens'];
$screen = $screens[0];
expectComposer($screen->widget('composerButton') !== null && $screen->widget('composerGenerate') !== null
  && $screen->widget('muteMelody') !== null && $screens[2]->widget('playbackSound') !== null,
  'Composer, mute, and playback sound controls parse.');

$score = new Score(2);
$score->setBeats(0, 3);
$score->ensureBar(1);
$score->setBeats(1, 5);
$score->ensureBar(2);
foreach ([[0, 0, 4], [0, 2, 6], [0, 4, 8], [1, 0, 4], [1, 2, 6], [1, 4, 8], [1, 6, 6]] as [$bar, $slot, $pitch]) {
  $score->place(0, $bar, $slot, 4, $pitch, 2);
}
$score->setMelodyPattern(1, 'kick');
$score->place(1, 0, 0, 4, 1, 1);
$before = $score->data();
$start = 3;
$end = $score->start(2) + 3;
$generated = Generator::generate($score, $start, $end, 3, 'balanced', 'high');
expectComposer($score->data() === $before && count($generated->melodies) === 5,
  'Generating versions leaves the original score untouched.');
$signatures = [];
for ($melody = 2; $melody < 5; $melody++) {
  expectComposer($generated->melodyMuted($melody), 'Every generated version starts muted.');
  $notes = array_values(array_filter($generated->notes,
    static fn (array $note): bool => $note['melody'] === $melody));
  expectComposer($notes !== [], 'Every version has notes.');
  foreach ($notes as $note) {
    $time = $generated->start($note['bar']) + $note['slot'];
    expectComposer($time >= $start && $time + $note['length'] <= $end,
      'Generated notes stay within the selected span, including bar boundaries.');
  }
  $signatures[] = json_encode($notes);
}
expectComposer(count(array_unique($signatures)) === 3, 'Generated versions have distinct notes.');
$short = Generator::generate($score, 0, 1, 5, 'sparse', 'low');
expectComposer(count($short->melodies) === 7, 'A one-slot selection can still produce five distinct versions.');
expectComposer(Score::fromData($generated->data())->data() === $generated->data(),
  'Generated melodies and mute settings survive JSON round trip.');
$mutedIndex = array_key_first(array_filter($generated->notes,
  static fn (array $note): bool => $note['melody'] === 2));
$locked = $generated->data();
foreach ([
  static fn () => $generated->setStaccato($mutedIndex, true),
  static fn () => $generated->setMelodyVolume(2, 30),
  static fn () => $generated->setMelodyColor(2, '#123abc'),
  static fn () => $generated->setMelodyInstrument(2, 'gm:40'),
  static fn () => $generated->place(2, 0, 0, 4, 4, 2),
  static fn () => $generated->delete($mutedIndex),
  static fn () => $generated->setBeats(0, 4),
  static fn () => RangeEdit::transpose($generated,
    ['scope' => 'score', 'melody' => 0, 'start' => 0, 'end' => $end], 1),
  static fn () => RangeEdit::clear($generated,
    ['scope' => 'melody', 'melody' => 2, 'start' => 0, 'end' => $end]),
] as $edit) {
  try {
    $edit();
    throw new RuntimeException('A muted melody accepted an edit.');
  } catch (DomainException) {
    expectComposer($generated->data() === $locked, 'Rejected edits leave muted melodies unchanged.');
  }
}
$history = new ScoreHistory($score);
$history->observe($generated);
$historyScore = Score::fromData($generated->data());
expectComposer($history->undo($historyScore) && count($historyScore->melodies) === 2
  && $history->redo($historyScore) && count($historyScore->melodies) === 5,
  'One undo or redo removes or restores the complete generated batch.');
$visible = $generated->visibleCopy();
expectComposer(count($visible->notes) === count($score->notes), 'Muted notes are absent from drawing data.');
$generated->setMelodyMuted(2, false);
expectComposer(count($generated->visibleCopy()->notes) > count($visible->notes),
  'Unmuting makes one generated version visible.');

$old = $generated->data();
foreach ($old['melodies'] as &$settings) {
  unset($settings['muted']);
}
unset($settings);
$legacy = Score::fromData($old);
expectComposer(!$legacy->melodyMuted(2), 'Older JSON scores load as unmuted.');

$onlyMuted = new Score(2);
$onlyMuted->place(0, 0, 0, 4, 4, 2);
$onlyMuted->setMelodyMuted(0, true);
$onlyMuted->setMelodyPattern(1, 'kick');
$onlyMuted->place(1, 0, 0, 4, 1, 1);
try {
  Generator::generate($onlyMuted, 0, 8);
  throw new RuntimeException('Composer accepted only muted notes and drums as reference.');
} catch (DomainException) {
  expectComposer(true, 'Composer requires an audible pitched reference.');
}

$test = new Score(2);
$test->place(0, 0, 0, 4, 4, 2);
$test->place(1, 0, 2, 7, 4, 2);
$test->setMelodyMuted(1, true);
$midi = MidiWriter::encode($test);
expectComposer(unpack('n', substr($midi, 10, 2))[1] === 2,
  'Muted pitched melodies do not consume MIDI tracks or channels.');
$bands = Prescription::soundingBands($test->visibleCopy(), 0);
expectComposer(!in_array(7, $bands, true), 'Muted notes do not create printed staff rows.');
$source = new ScoreSource($test);
$events = (new ReflectionProperty(ScoreSource::class, 'events'))->getValue($source);
expectComposer(count($events) === 1, 'Built-in playback skips muted notes.');
$withoutHidden = $test->visibleCopy();
$sheet = SheetPainter::render($test, 0, 1, 320, 500, melody: 0, design: true);
$plainSheet = SheetPainter::render($withoutHidden, 0, 1, 320, 500, melody: 0, design: true);
expectComposer($sheet->pixels === $plainSheet->pixels, 'Editor sheet hides muted notes.');
$page = PagePreview::render($test, 0, 500, 350);
$plainPage = PagePreview::render($withoutHidden, 0, 500, 350);
expectComposer($page->pixels === $plainPage->pixels, 'PDF preview hides muted notes and their staff rows.');

echo "Composer checks passed\n";
