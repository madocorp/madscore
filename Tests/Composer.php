<?php

define('APP_DIR', dirname(__DIR__));
define('APP_NAMESPACE', 'MADSCORE');
require APP_DIR . '/SPTK/App.php';

use MADSCORE\Score\PhraseGenerator;
use MADSCORE\Score\Score;

function expectComposer(bool $condition, string $message): void {
  if (!$condition) {
    throw new RuntimeException($message);
  }
}

foreach ([16 => 4, 15 => 5, 18 => 6, 21 => 7] as $beats => $meter) {
  $original = new Score();
  $result = PhraseGenerator::replace($original, 0, $beats, 'quadratic-chirp');
  $score = $result['score'];
  expectComposer($original->notes === [] && count($original->bars) === 1,
    'Generation leaves the original score untouched.');
  expectComposer($result['meter'] === $meter && count($score->bars) === intdiv($beats, $meter),
    "{$beats} beats form whole {$meter}-beat bars.");
  expectComposer(count($score->notes) === $beats && $score->bars[0]['note'] === 'quadratic-chirp',
    'Every beat has a note and the section carries the shape name.');
  expectComposer(array_unique(array_column($score->notes, 'length')) === [2],
    'Each generated note lasts one beat.');
  $saved = json_decode(json_encode($score->data(), JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
  $loaded = Score::fromData($saved);
  $generation = $loaded->melodies[0]['generation'];
  expectComposer($generation === PhraseGenerator::metadata($beats, 'quadratic-chirp', $meter)
    && PhraseGenerator::fromMetadata($generation) === $result['phrase'],
    'Saved generation settings reconstruct the same notes and curve.');
}

$invalid = (new Score())->data();
$invalid['melodies'][0]['generation'] = ['name' => 'wave'];
try {
  Score::fromData($invalid);
  throw new RuntimeException('Incomplete generation settings should be rejected.');
} catch (DomainException $error) {
  expectComposer($error->getMessage() === 'Invalid melody generation settings.',
    'Loading rejects incomplete generation settings.');
}

$withOther = new Score(2);
$withOther->place(1, 0, 0, 4, 1, 2);
$replaced = PhraseGenerator::replace($withOther, 0, 8, 'wave')['score'];
expectComposer(count($replaced->notes) === 9 && count(array_filter($replaced->notes,
  static fn (array $note): bool => $note['melody'] === 1)) === 1,
  'Replacing the selected melody keeps another melody intact.');
try {
  PhraseGenerator::replace($withOther, 0, 15, 'wave');
  throw new RuntimeException('An incompatible meter should be rejected.');
} catch (DomainException $error) {
  expectComposer(count($withOther->notes) === 1 && $withOther->beats(0) === 4,
    'A meter conflict leaves the original score untouched.');
}

expectComposer(PhraseGenerator::meter(8) === 4, 'Eight beats fit two four-beat bars.');
expectComposer(PhraseGenerator::replace(new Score(), 0, 20, 'wave', 5)['meter'] === 5,
  'Regeneration can keep its saved bar length.');
foreach ([11, 13] as $beats) {
  try {
    PhraseGenerator::meter($beats);
    throw new RuntimeException("{$beats} beats should be rejected.");
  } catch (DomainException $error) {
    // No supported bar length divides this count.
  }
}

foreach (PhraseGenerator::NAMES as $name) {
  expectComposer(PhraseGenerator::choose($name) !== $name, 'Regeneration chooses another shape.');
  $phrase = PhraseGenerator::generate(16, $name);
  expectComposer(count($phrase['pitches']) === 16 && count($phrase['curve']) > 16,
    'Each shape has note samples and a smooth preview curve.');
  expectComposer(min($phrase['pitches']) >= 0 && max($phrase['pitches']) <= 127,
    'Each shape is within MIDI pitch range.');
}
$patterns = PhraseGenerator::patterns();
expectComposer(count($patterns) === 22 && count(array_unique($patterns)) === 22,
  'The palette keeps only distinct base, inverted, and mirrored patterns.');
$simplePatterns = PhraseGenerator::patternsForNoteCount(3);
expectComposer(count($simplePatterns) === 6
  && in_array('wave', $simplePatterns, true)
  && !in_array('quadratic-chirp', $simplePatterns, true)
  && PhraseGenerator::patternsForNoteCount(7) === $patterns,
  'Small note sets use slower curves while seven notes retain the full palette.');
expectComposer(in_array(PhraseGenerator::chooseLayers(16, 4, noteCount: 3)['pattern'],
  $simplePatterns, true),
  'Composer selection respects the note-count complexity limit.');
foreach ([0.17, 0.43, 0.81] as $u) {
  $base = PhraseGenerator::patternValue('quadratic-chirp', $u);
  expectComposer(abs(PhraseGenerator::patternValue('quadratic-chirp-inverted', $u) + $base) < 0.000001
    && abs(PhraseGenerator::patternValue('quadratic-chirp-mirrored', $u)
      - PhraseGenerator::patternValue('quadratic-chirp', 1 - $u)) < 0.000001
    && abs(PhraseGenerator::patternValue('quadratic-chirp-mirrored-inverted', $u)
      + PhraseGenerator::patternValue('quadratic-chirp', 1 - $u)) < 0.000001,
    'Inversion changes pitch sign and mirroring reverses time.');
}
foreach ($patterns as $pattern) {
  $phrase = PhraseGenerator::generateLayered(16, 4, 'constant', $pattern, 1);
  expectComposer(count($phrase['pitches']) === 16
    && min($phrase['pitches']) >= 0 && max($phrase['pitches']) <= 127,
    'Every palette pattern generates playable notes.');
}

foreach (PhraseGenerator::SHAPES as $shape) {
  $phrase = PhraseGenerator::generateLayered(16, 4, $shape, 'double-wave', 1);
  expectComposer($phrase['degrees'][0] === 0
    && $phrase['degrees'][15] === ($shape === 'linear' ? 7 : 0),
    'Every phrase contour starts on C and ends on C or the next octave.');
  expectComposer(count($phrase['pitches']) === 16 && count($phrase['curve']) > 16,
    'Layered phrases sample one note per beat and retain a smooth curve.');
}
$layered = PhraseGenerator::replaceLayered(new Score(), 0, 16, 'arch', 'phase-warp', 2);
$settings = $layered['score']->melodies[0]['generation'];
expectComposer($settings === PhraseGenerator::layeredMetadata(16, 4, 'arch', 'phase-warp', 2)
  && PhraseGenerator::fromMetadata(Score::fromData($layered['score']->data())->melodies[0]['generation'])
    === $layered['phrase'],
  'Both functions and the repeat length survive a score round trip.');
foreach (range(3, 7) as $count) {
  $chosen = PhraseGenerator::chooseNoteSet($count);
  expectComposer(count($chosen['noteSet']) === $count
    && count(array_unique($chosen['noteSet'])) === $count
    && $chosen['noteSet'] === array_values(array_intersect(range(0, 6), $chosen['noteSet']))
    && in_array($chosen['startDegree'], $chosen['noteSet'], true),
    'Random note selection respects the configured count and picks an allowed start.');
}
expectComposer(PhraseGenerator::chordNoteSet(0, 3)
  === ['noteSet' => [0, 2, 4], 'startDegree' => 0, 'chordRoot' => 0]
  && PhraseGenerator::chordNoteSet(1, 3)
    === ['noteSet' => [1, 3, 5], 'startDegree' => 1, 'chordRoot' => 1]
  && PhraseGenerator::chordNoteSet(6, 3)
    === ['noteSet' => [1, 3, 6], 'startDegree' => 6, 'chordRoot' => 6],
  'Diatonic triads follow every other C major scale degree from their root.');
$chosenChord = PhraseGenerator::chooseChordNoteSet(3);
expectComposer($chosenChord === PhraseGenerator::chordNoteSet($chosenChord['chordRoot'], 3),
  'Random chord selection chooses a complete diatonic triad.');
$chord = PhraseGenerator::chordNoteSet(4, 3);
$chordScore = PhraseGenerator::replaceLayered(new Score(), 0, 16, 'arch', 'wave', 1,
  noteSet: $chord['noteSet'], startDegree: $chord['startDegree'],
  noteMode: 'chord', chordRoot: $chord['chordRoot']);
$chordSettings = Score::fromData($chordScore['score']->data())->melodies[0]['generation'];
expectComposer($chordSettings['noteMode'] === 'chord' && $chordSettings['chordRoot'] === 4
  && PhraseGenerator::fromMetadata($chordSettings) === $chordScore['phrase'],
  'The chord choice and its root survive a score round trip.');
try {
  PhraseGenerator::chooseChordNoteSet(6);
  throw new RuntimeException('Six-note chord mode should be rejected.');
} catch (DomainException $error) {
  // Chord mode deliberately uses triads, seventh chords, or five-note extensions.
}
$restricted = PhraseGenerator::replaceLayered(new Score(), 0, 16, 'linear', 'wave', 1,
  noteSet: [1, 2, 3], startDegree: 1);
$restrictedPhrase = $restricted['phrase'];
expectComposer($restrictedPhrase['degrees'][0] === 1
  && $restrictedPhrase['degrees'][15] === 8
  && array_diff(array_map(static fn (int $degree): int => (($degree % 7) + 7) % 7,
    $restrictedPhrase['degrees']), [1, 2, 3]) === [],
  'A D-E-F subset starts on D, ends on the next D, and uses only its chosen notes.');
$restrictedSettings = Score::fromData($restricted['score']->data())->melodies[0]['generation'];
expectComposer($restrictedSettings['version'] === 4
  && $restrictedSettings['noteSet'] === [1, 2, 3]
  && $restrictedSettings['startDegree'] === 1
  && PhraseGenerator::fromMetadata($restrictedSettings) === $restrictedPhrase,
  'Saved note choices reconstruct the restricted phrase and graph.');
$highStart = PhraseGenerator::replaceLayered(new Score(), 0, 16, 'arch', 'wave', 1,
  noteSet: [2, 4, 6], startDegree: 6);
$highPlain = MADSCORE\Render\SheetPainter::render($highStart['score'], 0, 2, 800, 600, design: true);
$highCurve = MADSCORE\Render\SheetPainter::render($highStart['score'], 0, 2, 800, 600,
  design: true, composer: $highStart['phrase']);
expectComposer($highPlain->pixels !== $highCurve->pixels,
  'The curve remains visible when the random starting note is high in the scale.');
try {
  PhraseGenerator::chooseNoteSet(2);
  throw new RuntimeException('Two notes should be outside the Composer setting range.');
} catch (DomainException $error) {
  // The Composer tests use three to seven distinct notes.
}
expectComposer(PhraseGenerator::chooseLayers(16, 4,
  ['shape' => 'arch', 'pattern' => 'phase-warp', 'repeatBars' => 2])
  !== ['shape' => 'arch', 'pattern' => 'phase-warp', 'repeatBars' => 2],
  'Regeneration chooses a different layer combination.');
$constant = PhraseGenerator::generateLayered(16, 4, 'constant', 'phase-warp', 1);
expectComposer(array_slice($constant['degrees'], 0, 4) === array_slice($constant['degrees'], 4, 4)
  && array_slice($constant['degrees'], 4, 4) === array_slice($constant['degrees'], 8, 4),
  'A flat phrase contour repeats the same notes in each group.');
$rising = PhraseGenerator::generateLayered(16, 4, 'linear', 'phase-warp', 1);
expectComposer(array_slice($rising['degrees'], 0, 4) !== array_slice($rising['degrees'], 4, 4),
  'The phrase contour alone changes the repeated pattern.');
$oldSettings = $settings;
$oldSettings['version'] = 2;
expectComposer(PhraseGenerator::fromMetadata($oldSettings)
  === PhraseGenerator::generateLayered(16, 4, 'arch', 'phase-warp', 2, true),
  'Saved phrases from the earlier varied version retain their original curve.');
try {
  PhraseGenerator::generateLayered(16, 4, 'arch', 'wave', 3);
  throw new RuntimeException('A repeat length that does not divide the phrase should be rejected.');
} catch (DomainException $error) {
  // Three bars cannot divide a four-bar phrase.
}

$historyScore = new Score();
$history = new MADSCORE\App\ScoreHistory($historyScore);
$historyScore = PhraseGenerator::replace($historyScore, 0, 16, 'wave')['score'];
$history->observe($historyScore);
$historyScore = PhraseGenerator::replace($historyScore, 0, 16, 'double-wave')['score'];
$history->observe($historyScore);
expectComposer($history->undo($historyScore)
  && $historyScore->melodies[0]['generation']['name'] === 'wave'
  && $history->redo($historyScore)
  && $historyScore->melodies[0]['generation']['name'] === 'double-wave',
  'Undo and redo restore the generation settings with the notes.');

$screens = (new SPTK\XmlParser\XmlParser())->windows[0]['screens'];
$window = (new ReflectionClass(SPTK\Core\Window::class))->newInstanceWithoutConstructor();
(new ReflectionProperty(SPTK\Core\Window::class, 'screens'))->setValue($window, $screens);
$editor = $window->screen('editor');
expectComposer($editor->widget('composerBeats') !== null
  && $editor->widget('composerNotes') !== null
  && $editor->widget('composerScale') !== null
  && $editor->widget('composerHomeNote') !== null
  && $editor->widget('composerScalePicker') !== null
  && $editor->widget('composerHomeNotePicker') !== null
  && $editor->widget('composerGenerate') !== null
  && $editor->widget('composerOpenGenerate') !== null
  && $editor->widget('composerType') !== null
  && $editor->widget('composerTypePicker') !== null
  && $editor->widget('composerGenerateTitle') !== null
  && $editor->widget('composerCancelGenerate') === null
  && $editor->widget('composerAddVersion') !== null
  && $editor->widget('motifVersionCancel') === null
  && $editor->widget('motifVersionList') !== null
  && $editor->widget('motifVersionSourceTarget') !== null
  && $editor->widget('motifVersionParamsSourceTarget') !== null
  && $editor->widget('motifVersionValue') !== null
  && $editor->widget('scoreToMotif') !== null
  && $editor->widget('scoreBuild') !== null
  && $editor->widget('scorePattern') instanceof SPTK\Widgets\TextEditor\TextEditor
  && $editor->widget('composerToScore') !== null
  && $editor->widget('composerClose') === null
  && $editor->widget('scoreToMotif')->hotkey() === 'm'
  && $editor->widget('scoreBuild')->hotkey() === 'b'
  && $editor->widget('composerToScore')->hotkey() === 's'
  && $editor->widget('composerOpenGenerate')->hotkey() === 'g'
  && $editor->widget('composerAddVersion')->hotkey() === 'v'
  && $editor->widget('composerItem') instanceof SPTK\Widgets\Input\Input
  && $editor->widget('composerItems') !== null
  && $editor->widget('contextId') !== null
  && $editor->widget('contextName') !== null
  && $editor->widget('contextInfo') !== null,
  'The editor exposes score building and motif editing controls.');
expectComposer(count($editor->widget('composerScalePicker')->items()) === 8
  && count($editor->widget('composerHomeNotePicker')->items()) === 13,
  'The motif selectors offer Random, seven scales, and twelve home notes.');
expectComposer($editor->widget('motifVersionList')->values()
  === array_keys(MADSCORE\Score\MotifVersions::ALGORITHMS),
  'The version picker offers every supported transformation.');
expectComposer($editor->widget('composerTypePicker')->values()
  === array_keys(MADSCORE\Score\MotifScoreGenerator::TYPES),
  'The generator selector offers random steps, waves, alternating, and skipping.');
$controller = new ReflectionClass(MADSCORE\App\Controller::class);
$versionViewport = $controller->getMethod('firstBarForNewSection');
expectComposer($versionViewport->invoke(null, 0, 2, 3, 4) === 0
  && $versionViewport->invoke(null, 2, 3, 5, 4) === 2,
  'A new version already fully visible does not scroll the sheet.');
expectComposer($versionViewport->invoke(null, 0, 3, 5, 4) === 2
  && $versionViewport->invoke(null, 2, 5, 7, 4) === 4
  && $versionViewport->invoke(null, 0, 1, 8, 4) === 5,
  'An appended version beyond the viewport ends at the right edge.');
$controller->getProperty('window')->setValue(null, $window);
$headerProject = new MADSCORE\Score\Project();
$headerId = $headerProject->create();
$controller->getProperty('project')->setValue(null, $headerProject);
$controller->getProperty('activeItemId')->setValue(null, $headerId);
$controller->getProperty('score')->setValue(null, $headerProject->score($headerId));
$controller->getMethod('refreshContextHeader')->invoke(null);
expectComposer($editor->widget('contextId')->text() === 'Motif A'
  && $editor->widget('contextName')->getValue() === 'Motif A',
  'The item header shows its ID and editable name separately.');
$editor->widget('contextName')->setValue('First idea');
MADSCORE\App\Controller::contextNameAccepted(new SPTK\Events\EventContext(
  'accept', $editor->widget('contextName')));
expectComposer($headerProject->item('A')['name'] === 'First idea'
  && $editor->widget('composerItem')->getValue() === 'A · First idea'
  && (new ReflectionProperty(SPTK\Widgets\Input\Input::class, 'label'))
    ->getValue($editor->widget('composerItem')) === 'Current motif',
  'Editing the header name updates the labeled current motif field.');
$motifFieldEvents = (new ReflectionProperty(SPTK\Layout\LayoutLeaf::class, 'events'))
  ->getValue($editor->layout->findTile('composerItem'));
expectComposer($motifFieldEvents[0]->type === 'activate'
  && $motifFieldEvents[0]->action === 'MADSCORE\App\Controller::showComposerItems',
  'Return activation on Current motif opens the motif list.');
$controller->getProperty('composerOpen')->setValue(null, true);
$hotkeys = static function() use ($editor): array {
  $input = (new ReflectionProperty(SPTK\Core\Screen::class, 'screenInput'))->getValue($editor);
  return (new ReflectionProperty(SPTK\Events\ScreenInput::class, 'hotkeys'))->getValue($input);
};
$editor->show('composerScorePanel');
expectComposer($controller->getMethod('composerFocused')->invoke(null),
  'A selected Composer button is recognized by its widget ID.');
$controller->getProperty('activeItemId')->setValue(null, 'main');
$controller->getProperty('score')->setValue(null, $headerProject->score('main'));
$editor->widget('scorePattern')->setValue("A B |\nA1");
$savedPattern = MADSCORE\App\Controller::projectData();
expectComposer(MADSCORE\Score\Project::fromData($savedPattern)->pattern() === "A B |\nA1",
  'An unbuilt score pattern is saved with the project.');
$controller->getProperty('activeItemId')->setValue(null, $headerId);
$controller->getProperty('score')->setValue(null, $headerProject->score($headerId));
expectComposer($hotkeys()['m']->id() === 'scoreToMotif'
  && $hotkeys()['b']->id() === 'scoreBuild',
  'The score tile binds its Motif and Build buttons.');
$editor->restore('composerScorePanel');
$editor->show('composerPanel');
expectComposer(array_map(static fn ($leaf): ?string => $leaf->instance()->id(),
    $editor->layout->findTile('composerPanel')->movementLeaves())
    === ['composerItem', 'composerToScore', 'composerOpenGenerate', 'composerAddVersion']
  && $hotkeys()['s']->id() === 'composerToScore'
  && $hotkeys()['g']->id() === 'composerOpenGenerate'
  && $hotkeys()['v']->id() === 'composerAddVersion',
  'The motif tile shows the current motif and its three mode buttons.');
$editor->statusBar->setScheduler(static function (int $durationMs, callable $dismiss): void {});
expectComposer($controller->getMethod('composerFocused')->invoke(null),
  'The motif composer is focused before its V shortcut.');
expectComposer($editor->handleEvent((object)['type' => SPTK\SDLWrapper\SDL::SDL_EVENT_KEY_DOWN,
    'key' => (object)['key' => ord('v'), 'mod' => 0, 'repeat' => false]]),
  'V is captured for the Derive version button.');
$vText = FFI::new('char[64]');
FFI::memcpy($vText, 'v', 1);
expectComposer($editor->handleEvent((object)['type' => SPTK\SDLWrapper\SDL::SDL_EVENT_TEXT_INPUT,
    'text' => (object)['text' => $vText]])
  && str_contains($editor->statusBar->text(), 'Generate the original motif'),
  'V reaches Derive version instead of the PDF preview shortcut: '
    . $editor->statusBar->text());
$editor->statusBar->clear();
$editor->restore('composerPanel');
$editor->show('composerGeneratePanel');
expectComposer($editor->layout->findTile('composerGeneratePanel')->overwriting() !== null
  && !isset($hotkeys()['g']),
  'The generation parameter panel has separate controls.');
$editor->restore('composerGeneratePanel');
$editor->show('composerTypePicker');
expectComposer($editor->layout->findTile('composerTypePicker')->overwriting() !== null,
  'The generator type choices can replace the parameter panel.');
$editor->restore('composerTypePicker');
$editor->show('motifVersionPicker');
expectComposer($editor->layout->findTile('motifVersionPicker')->overwriting() !== null
  && $editor->selectedLeaf()?->instance()->id() === 'motifVersionList',
  'The version picker shows its source and target above the algorithm list.');
$editor->restore('motifVersionPicker');
$editor->restore('composerGeneratePanel');
$editor->show('composerPanel');
expectComposer($editor->layout->findTile('composerPanel')->overwriting() !== null,
  'The three-button motif tile can be restored after generation settings.');
$editor->restore('composerPanel');
expectComposer($hotkeys()['s']->id() === 'staccato',
  'Restoring the Note tile restores its S hotkey.');
$controller->getProperty('composerOpen')->setValue(null, false);

$rendered = PhraseGenerator::replace(new Score(), 0, 16, 'quadratic-chirp');
$controller->getProperty('score')->setValue(null, Score::fromData($rendered['score']->data()));
$controller->getProperty('melody')->setValue(null, 0);
$controller->getProperty('composerOpen')->setValue(null, false);
expectComposer(MADSCORE\App\Controller::composerPreview() === $rendered['phrase'],
  'The saved curve remains available after the Composer tile closes.');
$plain = MADSCORE\Render\SheetPainter::render($rendered['score'], 0, 2, 800, 600, design: true);
$withCurve = MADSCORE\Render\SheetPainter::render($rendered['score'], 0, 2, 800, 600,
  design: true, composer: $rendered['phrase']);
expectComposer($plain->pixels !== $withCurve->pixels, 'The composer curve appears on the sheet background.');

echo "Composer checks passed\n";
