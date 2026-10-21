<?php

define('APP_DIR', dirname(__DIR__));
define('APP_NAMESPACE', 'MADSCORE');
require APP_DIR . '/SPTK/App.php';

function expectSound(bool $condition, string $message): void {
  if (!$condition) {
    throw new RuntimeException($message);
  }
}

$items = MADSCORE\Score\Sound::items();
expectSound(count($items) === 175 && count(array_unique(array_column($items, 'value'))) === 175,
  'One sound catalog offers 128 melodic programs and 47 drum sounds.');
foreach ($items as $item) {
  expectSound(MADSCORE\Score\Sound::valid($item['value'])
    && in_array(MADSCORE\Score\Sound::fallback($item['value']), MADSCORE\Play\Waveform::NAMES, true),
    'Every catalog item has a valid portable ID and built-in fallback.');
}
expectSound(MADSCORE\Score\Sound::label('gm:40') === 'Violin'
  && MADSCORE\Score\Sound::label('drum:49') === 'Crash Cymbal 1'
  && count(SPTK\Widgets\List\ItemSearch::matchingIndices($items, 'violin')) === 1,
  'Sound names are stable and searchable without typing their category.');

$score = new MADSCORE\Score\Score(2);
$score->setMelodyInstrument(0, 'gm:40');
$score->setMelodyInstrument(1, 'drum:49');
expectSound($score->melodies[0]['pattern'] === 'sawtooth'
  && $score->melodies[1]['pattern'] === 'open_hat',
  'Every saved instrument has a built-in playback sound.');
$score->place(0, 0, 0, 4, 1, 2);
$score->place(1, 0, 0, 4, 1, 1);
$third = $score->addMelody();
$score->setMelodyInstrument($third, 'gm:41');
$score->place($third, 0, 0, 4, 1, 2);
expectSound(MADSCORE\Score\Score::fromData($score->data())->data() === $score->data(),
  'Instrument IDs and fallback sounds survive a score round trip.');
$midi = MADSCORE\Play\MidiWriter::encode($score);
expectSound(str_contains($midi, "\xc0\x28") && str_contains($midi, "\xc1\x29")
  && str_contains($midi, "\x99\x31"),
  'MIDI export gives two instruments separate channels and uses the crash cymbal key.');

$legacy = $score->data();
unset($legacy['melodies'][0]['instrument'], $legacy['melodies'][1]['instrument'], $legacy['melodies'][2]['instrument']);
$legacy['melodies'][0]['pattern'] = 'triangle';
$legacy['melodies'][1]['pattern'] = 'snare';
$restored = MADSCORE\Score\Score::fromData($legacy);
expectSound($restored->melodies[0]['instrument'] === 'gm:10'
  && $restored->melodies[0]['pattern'] === 'triangle'
  && $restored->melodies[1]['instrument'] === 'drum:38'
  && $restored->melodies[1]['pattern'] === 'snare',
  'Old pattern-only scores retain their original software sounds.');

$definition = (new SPTK\XmlParser\XmlParser())->windows[0];
$window = (new ReflectionClass(SPTK\Core\Window::class))->newInstanceWithoutConstructor();
(new ReflectionProperty(SPTK\Core\Window::class, 'screens'))->setValue($window, $definition['screens']);
(new ReflectionProperty(MADSCORE\App\Controller::class, 'window'))->setValue(null, $window);
(new ReflectionProperty(MADSCORE\App\Controller::class, 'score'))->setValue(null, $score);
(new ReflectionProperty(MADSCORE\App\Controller::class, 'melody'))->setValue(null, 0);
(new ReflectionMethod(MADSCORE\App\Controller::class, 'refreshMelodyFields'))->invoke(null);
$picker = $window->screen('editor')->widget('soundPicker');
expectSound(count($picker->items()) === 175 && $picker->getValue() === 'gm:40',
  'The editor shows the full catalog and selects the saved instrument.');
$editor = $window->screen('editor');
$editor->measureGrid(new SPTK\Layout\Tile(0, 0, 160, 50));
$panel = $editor->layout->findNode('settingsPanel')->grid();
$boardHeight = $editor->layout->findNode('board')->grid()->height;
expectSound($editor->show('soundPicker'), 'The full sound picker opens over the Note tile.');
$editor->measureGrid(new SPTK\Layout\Tile(0, 0, 160, 50));
$pickerTile = $editor->layout->findTile('soundPicker')->grid();
expectSound($editor->layout->findNode('settingsPanel')->grid()->height === $panel->height
  && $editor->layout->findNode('board')->grid()->height === $boardHeight
  && $pickerTile->y === $panel->y && $pickerTile->height === $panel->height,
  'The instrument list stays inside the existing settings panel and leaves the score visible.');

echo "Sound checks passed\n";
