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
(new ReflectionProperty(MADSCORE\App\Controller::class, 'score'))->setValue(null, $score);
MADSCORE\App\Files::restorePath(null);
MADSCORE\App\Files::initialize();
expectFiles($screens[0]->widget('saveDirectory')->path() === MADSCORE\App\Settings::defaultDirectory()
  && $screens[0]->widget('saveFilename')->getValue() === 'score.json',
  'Save starts in the configured directory with a JSON file name.');

$path = tempnam(sys_get_temp_dir(), 'madscore-file-');
$badPath = $path . '.bad.json';
try {
  $write = new ReflectionMethod(MADSCORE\App\Files::class, 'writeScore');
  expectFiles($write->invoke(null, $path), 'Atomic JSON save succeeds.');
  $loaded = MADSCORE\Score\Score::fromData(json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR));
  expectFiles($loaded->data() === $score->data(), 'Saved score can be loaded without changing its music.');
  expectFiles(glob($path . '.tmp-*') === [], 'Successful save leaves no temporary file.');
  MADSCORE\App\Files::restorePath($path);
  expectFiles(MADSCORE\App\Files::currentPath() === $path, 'Named path can be restored for Ctrl+W.');
  $score->title = 'Written again';
  MADSCORE\App\Files::write(new SPTK\Events\EventContext('activate'));
  expectFiles(json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR)['title'] === 'Written again'
    && !$screens[0]->statusBar->confirming(), 'Write overwrites the current file without a prompt.');
  MADSCORE\App\Files::exportMidi(new SPTK\Events\EventContext('activate'));
  expectFiles(str_starts_with(file_get_contents($path . '.mid'), 'MThd')
    && json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR)['title'] === 'Written again',
    'MIDI export writes beside the score without changing it.');
  MADSCORE\App\Files::restorePath(null);
  $score->title = 'New unsaved score';
  MADSCORE\App\Files::write(new SPTK\Events\EventContext('activate'));
  expectFiles(json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR)['title'] === 'Written again',
    'Write cannot overwrite the previous file after its path is cleared.');
  MADSCORE\App\Files::restorePath('relative.json');
  expectFiles(MADSCORE\App\Files::currentPath() === null, 'Invalid session paths do not become write targets.');
  MADSCORE\App\Files::write(new SPTK\Events\EventContext('activate'));
  expectFiles($screens[0]->layout->findTile('savePanel')->overwriting() === null,
    'Write without a current file does not open Save as.');
  file_put_contents($badPath, '{broken');
  $selector = $screens[0]->widget('loadSelector');
  $selector->setPath(dirname($badPath));
  $selector->setValue($badPath);
  MADSCORE\App\Files::open(new SPTK\Events\EventContext('activate'));
  expectFiles(MADSCORE\App\Controller::score()->data() === $score->data()
    && MADSCORE\App\Files::currentPath() === null,
    'An invalid selected file leaves the score and current path intact.');
} finally {
  unlink($path);
  if (is_file($path . '.mid')) {
    unlink($path . '.mid');
  }
  if (is_file($badPath)) {
    unlink($badPath);
  }
}

echo "File checks passed\n";
