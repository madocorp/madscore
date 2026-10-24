<?php

define('APP_DIR', dirname(__DIR__));
define('APP_NAMESPACE', 'MADSCORE');
require APP_DIR . '/SPTK/App.php';

/** Fail a settings regression check with a clear message. */
function expectSettings(bool $condition, string $message): void {
  if (!$condition) {
    throw new RuntimeException($message);
  }
}

$home = sys_get_temp_dir() . '/madscore-settings-' . bin2hex(random_bytes(6));
mkdir($home);
mkdir($home . '/Music');
mkdir($home . '/Other');
$oldPath = getenv('PATH');
putenv('HOME=' . $home);
try {
  $screens = (new SPTK\XmlParser\XmlParser())->windows[0]['screens'];
  $window = (new ReflectionClass(SPTK\Core\Window::class))->newInstanceWithoutConstructor();
  (new ReflectionProperty(SPTK\Core\Window::class, 'screens'))->setValue($window, $screens);
  (new ReflectionProperty(MADSCORE\App\Controller::class, 'window'))->setValue(null, $window);
  MADSCORE\App\Settings::initialize();
  expectSettings($screens[2]->widget('tristaffTutorial')->hotkey() === 'f1'
    && is_file(APP_DIR . '/Tristaff/index.html'),
    'The Settings tutorial shortcut points to the bundled guide.');
  $pages = ['index.html', '01-why.html', '02-pitch.html', '03-time.html',
    '04-octaves.html', '05-marks.html', '06-phrase.html'];
  $figures = [];
  foreach ($pages as $page) {
    $guide = file_get_contents(APP_DIR . '/Tristaff/' . $page);
    expectSettings($guide !== false, 'The tutorial page exists: ' . $page);
    if ($page !== 'index.html') {
      expectSettings(str_contains($guide, 'class="chapter-nav"')
        && str_contains($guide, 'href="index.html"'),
        'A chapter has navigation back to the start: ' . $page);
    }
    preg_match_all('/<img src="(graphics\/[^"]+\.png)"/', $guide, $images);
    array_push($figures, ...$images[1]);
  }
  expectSettings(count(array_unique($figures)) === 17, 'Every notation example uses a generated image.');
  foreach ($figures as $figure) {
    expectSettings(getimagesize(APP_DIR . '/Tristaff/' . $figure) !== false,
      'The tutorial image exists and is readable: ' . $figure);
  }
  mkdir($home . '/bin');
  $opener = $home . '/bin/xdg-open';
  file_put_contents($opener, "#!/usr/bin/env php\n<?php file_put_contents(getenv('MADSCORE_TUTORIAL_CAPTURE'), \$argv[1]);\n");
  chmod($opener, 0700);
  putenv('PATH=' . $home . '/bin:' . $oldPath);
  putenv('MADSCORE_TUTORIAL_CAPTURE=' . $home . '/opened');
  MADSCORE\App\Settings::openTutorial(new SPTK\Events\EventContext('activate'));
  expectSettings(file_get_contents($home . '/opened') === APP_DIR . '/Tristaff/index.html',
    'The tutorial action passes the local HTML file to the default-browser opener.');
  expectSettings(MADSCORE\App\Settings::defaultDirectory() === $home . '/Music',
    'An unset default directory uses ~/Music.');
  MADSCORE\App\Files::initialize();
  expectSettings($screens[0]->widget('loadSelector')->path() === $home . '/Music',
    'The first file selector starts in ~/Music.');
  $directory = $screens[2]->widget('defaultDirectory');
  $directory->setValue($home . '/Other', true);
  $key = (object)['type' => SPTK\SDLWrapper\SDL::SDL_EVENT_KEY_DOWN,
    'key' => (object)['key' => SPTK\SDLWrapper\SDL::KEY_BACKSPACE, 'mod' => 0]];
  $directory->handleInput($key);
  expectSettings(SPTK\Core\AppData::loadJson('settings.json')['defaultDir'] === $home . '/Othe',
    'Editing the directory stores the current value immediately.');
  $directory->setValue($home . '/Other');
  MADSCORE\App\Settings::directoryChanged(new SPTK\Events\EventContext('change', $directory));
  expectSettings(MADSCORE\App\Settings::defaultDirectory() === $home . '/Other',
    'A configured directory becomes the first browser location.');
  $savedLayout = SPTK\Core\AppData::loadJson('settings.json');
  expectSettings($savedLayout['defaultDir'] === $home . '/Other'
    && !isset($savedLayout['pdfNoteSize'], $savedLayout['pdfBeatSize']),
    'App settings keep the directory without project PDF sizes.');
  MADSCORE\Render\PdfLayout::configure(70, 200);
  SPTK\Core\AppData::saveJson('settings.json', ['defaultDir' => $home . '/Other',
    'pdfNoteSize' => 70, 'pdfBeatSize' => 200]);
  MADSCORE\Render\PdfLayout::configure(100, 100);
  MADSCORE\App\Settings::initialize();
  expectSettings(MADSCORE\App\Settings::legacyPdfLayout() === [70, 200]
    && MADSCORE\Render\PdfLayout::beatSize() === 100,
    'Old app PDF sizes are available for a one-time project migration.');
  MADSCORE\App\Files::initialize();
  $browserDirectory = new ReflectionMethod(MADSCORE\App\Files::class, 'browserDirectory');
  expectSettings($browserDirectory->invoke(null) === $home . '/Other',
    'The default applies before browsing.');
  $selector = $screens[0]->widget('loadSelector');
  $selector->setPath($home . '/Music');
  MADSCORE\App\Files::directoryChanged(new SPTK\Events\EventContext('change', $selector));
  expectSettings($browserDirectory->invoke(null) === $home . '/Music',
    'The last selected directory takes precedence after browsing.');
} finally {
  MADSCORE\Render\PdfLayout::configure(100, 100);
  putenv('PATH=' . $oldPath);
  putenv('MADSCORE_TUTORIAL_CAPTURE');
  if (is_file($home . '/opened')) {
    unlink($home . '/opened');
  }
  if (is_file($home . '/bin/xdg-open')) {
    unlink($home . '/bin/xdg-open');
  }
  if (is_dir($home . '/bin')) {
    rmdir($home . '/bin');
  }
  if (is_file($home . '/.madscore/settings.json')) {
    unlink($home . '/.madscore/settings.json');
  }
  if (is_dir($home . '/.madscore')) {
    rmdir($home . '/.madscore');
  }
  rmdir($home . '/Music');
  rmdir($home . '/Other');
  rmdir($home);
}

echo "Settings checks passed\n";
