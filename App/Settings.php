<?php

namespace MADSCORE\App;

use SPTK\Core\AppData;
use SPTK\Events\EventContext;

/** Manages application preferences and the settings screen. */
final class Settings {

  private static string $directory = '';
  private static bool $handlingDirectoryInput = false;

  /** Restore the default directory and show the license. */
  public static function initialize(): void {
    $stored = AppData::loadJson('settings.json');
    self::$directory = is_string($stored['defaultDir'] ?? null) ? $stored['defaultDir'] : '';
    $input = Controller::screen('settings')->widget('defaultDirectory');
    $input->setValue(self::$directory);
    $input->setInputInterceptor(self::interceptDirectoryInput(...));
    Controller::screen('settings')->widget('settingsUnlicense')->setText(file_get_contents(APP_DIR . '/UNLICENSE'));
  }

  /** Return a usable initial location even when the saved directory disappeared. */
  public static function defaultDirectory(): string {
    $path = trim(self::$directory) === '' ? (getenv('HOME') ?: APP_DIR) . '/Music' : self::$directory;
    $directory = realpath($path);
    return $directory !== false && is_dir($directory) && is_readable($directory) ? $directory : APP_DIR;
  }

  /** Store every edit without a separate Save button. */
  public static function directoryChanged(EventContext $event): void {
    self::$directory = Controller::screen('settings')->widget('defaultDirectory')->getValue();
    if (!AppData::saveJson('settings.json', ['defaultDir' => self::$directory])) {
      Controller::status('Could not save settings.', 'settings');
    }
  }

  /** Open the bundled Tristaff guide with the desktop's default browser. */
  public static function openTutorial(EventContext $event): bool {
    $path = APP_DIR . '/Tristaff/index.html';
    try {
      $process = proc_open(['xdg-open', $path], [
        0 => ['file', '/dev/null', 'r'],
        1 => ['file', '/dev/null', 'w'],
        2 => ['file', '/dev/null', 'w'],
      ], $pipes);
      if (!is_resource($process) || proc_close($process) !== 0) {
        throw new \RuntimeException('Could not open the Tristaff tutorial in a browser.');
      }
    } catch (\Throwable $error) {
      Controller::status($error->getMessage(), 'settings');
    }
    return true;
  }

  /** Save an Input edit as soon as its text changes. */
  private static function interceptDirectoryInput(mixed $event): bool {
    if (self::$handlingDirectoryInput) {
      return false;
    }
    $input = Controller::screen('settings')->widget('defaultDirectory');
    $before = $input->getValue();
    self::$handlingDirectoryInput = true;
    try {
      $handled = $input->handleInput($event);
    } finally {
      self::$handlingDirectoryInput = false;
    }
    if ($input->getValue() !== $before) {
      self::directoryChanged(new EventContext('change', $input));
    }
    return $handled;
  }
}
