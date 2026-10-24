<?php

define('APP_DIR', dirname(__DIR__));
define('APP_NAMESPACE', 'MADSCORE');
require APP_DIR . '/SPTK/App.php';

function expectFiles(bool $condition, string $message): void {
  if (!$condition) {
    throw new RuntimeException($message);
  }
}

$screens = (new SPTK\XmlParser\XmlParser())->windows[0]['screens'];
expectFiles(count($screens) === 3 && $screens[0]->id === 'editor' && $screens[1]->id === 'sheet'
  && $screens[2]->id === 'settings', 'The settings screen is available without a separate Files screen.');
expectFiles($screens[0]->widget('saveButton')->hotkey() === 'ctrl+s'
  && $screens[0]->widget('writeButton')->hotkey() === 'ctrl+w'
  && $screens[0]->widget('loadButton')->hotkey() === 'ctrl+o'
  && $screens[0]->widget('newScore') !== null,
  'File action buttons own their modifier shortcuts.');
$window = (new ReflectionClass(SPTK\Core\Window::class))->newInstanceWithoutConstructor();
(new ReflectionProperty(SPTK\Core\Window::class, 'screens'))->setValue($window, $screens);
(new ReflectionProperty(MADSCORE\App\Controller::class, 'window'))->setValue(null, $window);
$screens[0]->statusBar->setScheduler(static function(int $delay, callable $callback): void {});
$score = new MADSCORE\Score\Score();
$score->title = 'File round trip';
$score->place(0, 0, 0, 4, 1, 2);
(new ReflectionProperty(MADSCORE\App\Controller::class, 'score'))->setValue(null, $score);
(new ReflectionProperty(MADSCORE\App\Controller::class, 'project'))->setValue(null,
  MADSCORE\Score\Project::fromScore($score));
(new ReflectionProperty(MADSCORE\App\Controller::class, 'activeItemId'))->setValue(null, 'main');
MADSCORE\App\Files::restorePath(null);
MADSCORE\App\Files::initialize();
expectFiles($screens[0]->widget('saveDirectory')->path() === MADSCORE\App\Settings::defaultDirectory()
  && $screens[0]->widget('saveFilename')->getValue() === 'score.json.gz',
  'Save starts in the configured directory with a compressed project name.');

$path = sys_get_temp_dir() . '/madscore-file-' . bin2hex(random_bytes(6)) . '.json.gz';
$legacyPath = substr($path, 0, -3);
$badPath = $path . '.bad.json.gz';
$midiPath = substr($path, 0, -8) . '.mid';
$pdfPath = substr($path, 0, -8) . '.pdf';
$wavPath = substr($path, 0, -8) . '.wav';
expectFiles($screens[0]->widget('exportFormats')->getValue() === ['midi', 'pdf'],
  'Export starts with MIDI and PDF selected.');
try {
  $projectName = new ReflectionMethod(MADSCORE\App\Files::class, 'projectName');
  expectFiles($projectName->invoke(null, 'new score') === 'new score.json.gz'
    && $projectName->invoke(null, 'score.json') === 'score.json.gz'
    && $projectName->invoke(null, 'score.json.gz') === 'score.json.gz',
    'New project names use the gzip JSON suffix.');
  $write = new ReflectionMethod(MADSCORE\App\Files::class, 'writeScore');
  expectFiles($write->invoke(null, $path), 'Atomic compressed project save succeeds.');
  $compressed = file_get_contents($path);
  expectFiles(str_starts_with($compressed, "\x1f\x8b"), 'The saved project has a gzip header.');
  $saved = json_decode(gzdecode($compressed), true, 512, JSON_THROW_ON_ERROR);
  $read = new ReflectionMethod(MADSCORE\App\Files::class, 'readProject');
  $loaded = MADSCORE\Score\Project::fromData($saved);
  expectFiles($saved === MADSCORE\Score\Project::fromScore($score)->data()
    && $saved['items'][0]['id'] === 'main' && !isset($saved['items'][0]['type'])
    && !isset($saved['items'][0]['score']['title']) && $loaded->score('main')->data() === $score->data()
    && $read->invoke(null, $path)->data() === $saved,
    'Saved compressed project has one main item and loads without changing the score.');
  try {
    MADSCORE\Score\Project::fromData($score->data());
    throw new RuntimeException('The old bare score format was accepted.');
  } catch (DomainException $error) {
    expectFiles(true, 'The old bare score format is rejected.');
  }
  $extra = $saved;
  $extra['items'][] = ['id' => 'A', 'name' => 'Motif A',
    'music' => ['scale' => 'Major', 'homeNote' => 'C'], 'score' => $saved['items'][0]['score']];
  expectFiles(MADSCORE\Score\Project::fromData($extra)->data() === $extra,
    'Project items survive a saved-file round trip.');
  expectFiles(glob($path . '.tmp-*') === [], 'Successful save leaves no temporary file.');
  file_put_contents($legacyPath, json_encode($saved, JSON_THROW_ON_ERROR));
  expectFiles($read->invoke(null, $legacyPath)->data() === $saved,
    'An older plain JSON project still opens.');
  MADSCORE\App\Files::restorePath($path);
  expectFiles(MADSCORE\App\Files::currentPath() === $path, 'Named path can be restored for Ctrl+W.');
  $score->title = 'Written again';
  MADSCORE\App\Files::write(new SPTK\Events\EventContext('activate'));
  expectFiles(json_decode(gzdecode(file_get_contents($path)), true, 512, JSON_THROW_ON_ERROR)['title'] === 'Written again'
    && !$screens[0]->statusBar->confirming(), 'Write overwrites the current file without a prompt.');
  MADSCORE\App\Files::exportSelected(new SPTK\Events\EventContext('activate'));
  expectFiles(str_starts_with(file_get_contents($midiPath), 'MThd')
    && str_starts_with(file_get_contents($pdfPath), '%PDF-')
    && json_decode(gzdecode(file_get_contents($path)), true, 512, JSON_THROW_ON_ERROR)['title'] === 'Written again',
    'Selected MIDI and PDF exports write beside the score without changing it.');
  unlink($pdfPath);
  $screens[0]->widget('exportFormats')->setValue(['midi']);
  MADSCORE\App\Files::exportSelected(new SPTK\Events\EventContext('activate'));
  expectFiles(!is_file($pdfPath), 'Unselected formats are not exported.');
  $screens[0]->widget('exportFormats')->setValue(['wav']);
  $originalPath = getenv('PATH');
  putenv('PATH=/nonexistent');
  try {
    MADSCORE\App\Files::exportSelected(new SPTK\Events\EventContext('activate'));
  } finally {
    $originalPath === false ? putenv('PATH') : putenv('PATH=' . $originalPath);
  }
  $wav = file_get_contents($wavPath);
  expectFiles(substr($wav, 0, 4) === 'RIFF' && substr($wav, 8, 8) === 'WAVEfmt '
    && unpack('V', substr($wav, 4, 4))[1] === strlen($wav) - 8
    && unpack('v', substr($wav, 20, 2))[1] === 1
    && unpack('v', substr($wav, 22, 2))[1] === 1
    && unpack('V', substr($wav, 24, 4))[1] === MADSCORE\Play\ScoreSource::SAMPLE_RATE
    && unpack('v', substr($wav, 34, 2))[1] === 16
    && unpack('V', substr($wav, 40, 4))[1] === strlen($wav) - 44
    && trim(substr($wav, 44), "\0") !== '',
    'WAV falls back to complete mono 16-bit PCM with audible score data.');
  unlink($wavPath);
  if (MADSCORE\Play\MidiPlayback::unavailableReason() === null) {
    $rendered = MADSCORE\Play\MidiPlayback::render($score);
    try {
      MADSCORE\App\Files::exportSelected(new SPTK\Events\EventContext('activate'));
      expectFiles(hash_file('sha256', $wavPath) === hash_file('sha256', $rendered),
        'WAV export prefers the same SoundFont rendering as MIDI playback.');
    } finally {
      unlink($rendered);
      if (is_file($wavPath)) {
        unlink($wavPath);
      }
    }
  }
  $screens[0]->widget('exportFormats')->setValue([]);
  MADSCORE\App\Files::exportSelected(new SPTK\Events\EventContext('activate'));
  expectFiles(!is_file($pdfPath) && !is_file($wavPath), 'An empty selection leaves files unchanged.');
  MADSCORE\App\Files::restorePath($legacyPath);
  $score->title = 'Updated old file';
  MADSCORE\App\Files::write(new SPTK\Events\EventContext('activate'));
  expectFiles(json_decode(file_get_contents($legacyPath), true, 512, JSON_THROW_ON_ERROR)['title'] === 'Updated old file',
    'Write keeps an older plain JSON project in its original format.');
  MADSCORE\App\Files::restorePath(null);
  $score->title = 'New unsaved score';
  MADSCORE\App\Files::write(new SPTK\Events\EventContext('activate'));
  expectFiles(json_decode(gzdecode(file_get_contents($path)), true, 512, JSON_THROW_ON_ERROR)['title'] === 'Written again',
    'Write cannot overwrite the previous file after its path is cleared.');
  MADSCORE\App\Files::restorePath('relative.json');
  expectFiles(MADSCORE\App\Files::currentPath() === null, 'Invalid session paths do not become write targets.');
  MADSCORE\App\Files::write(new SPTK\Events\EventContext('activate'));
  expectFiles($screens[0]->layout->findTile('savePanel')->overwriting() === null,
    'Write without a current file does not open Save as.');
  file_put_contents($badPath, 'not gzip');
  try {
    $read->invoke(null, $badPath);
    throw new RuntimeException('A damaged gzip project was accepted.');
  } catch (DomainException $error) {
    expectFiles(str_contains($error->getMessage(), 'compressed project'),
      'A damaged gzip project reports a clear error.');
  }
  $selector = $screens[0]->widget('loadSelector');
  $selector->setPath(dirname($badPath));
  $selector->setValue($badPath);
  MADSCORE\App\Files::open(new SPTK\Events\EventContext('activate'));
  expectFiles(MADSCORE\App\Controller::score()->data() === $score->data()
    && MADSCORE\App\Files::currentPath() === null,
    'An invalid selected file leaves the score and current path intact.');
} finally {
  foreach ([$path, $legacyPath, $badPath, $midiPath, $pdfPath, $wavPath] as $file) {
    if (is_file($file)) {
      unlink($file);
    }
  }
}

echo "File checks passed\n";
