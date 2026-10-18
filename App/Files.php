<?php

namespace MADSCORE\App;

use MADSCORE\Render\PdfWriter;
use MADSCORE\Score\LegacyImporter;
use MADSCORE\Score\Score;

use SPTK\Events\EventContext;

/** Opens, saves, imports, and exports score files. */
final class Files {

  private static ?string $path = null;
  private static ?Score $pendingImport = null;

  /** Populate default paths on the Files screen. */
  public static function initialize(): void {
    $screen = Controller::screen('files');
    $screen->widget('path')->setValue(APP_DIR . '/score.json');
    $screen->widget('pdf')->setValue(APP_DIR . '/score.pdf');
  }

  /** Show only the name of the score path in the editor header. */
  public static function filename(): string {
    $path = trim(Controller::screen('files')->widget('path')->getValue());
    return $path === '' ? 'score.json' : basename($path);
  }

  /** Apply an editor filename in the directory selected on the Files screen. */
  public static function setFilename(string $filename): void {
    $filename = trim($filename);
    if ($filename === '' || $filename === '.' || $filename === '..' || strpbrk($filename, '/\\') !== false) {
      throw new \DomainException('Enter a filename without directory separators.');
    }
    $widget = Controller::screen('files')->widget('path');
    $oldPath = trim($widget->getValue());
    if ($oldPath !== '' && basename($oldPath) === $filename) {
      return;
    }
    $directory = $oldPath === '' ? APP_DIR : dirname($oldPath);
    $path = $directory . '/' . $filename;
    $widget->setValue($path);
    if (self::$path !== $path) {
      self::$path = null;
    }
  }

  /** A path edited on the Files screen becomes the editor's proposed filename. */
  public static function syncPathFromFiles(): void {
    $path = trim(Controller::screen('files')->widget('path')->getValue());
    if ($path !== self::$path) {
      self::$path = null;
    }
  }

  /** Save to the current path, opening Files for a new score. */
  public static function save(EventContext $event): void {
    if (self::$path === null) {
      Controller::showFiles($event);
      Controller::status('Choose a score path and press Save as.', 'files');
      return;
    }
    self::writeScore(self::$path);
  }

  /** Save to the path entered on the Files screen. */
  public static function saveAs(EventContext $event): void {
    $path = trim(Controller::screen('files')->widget('path')->getValue());
    if ($path === '') {
      Controller::status('Enter a score path.', 'files');
      return;
    }
    if (is_dir($path)) {
      Controller::status('The score path names a directory.', 'files');
      return;
    }
    if (self::writeScore($path)) {
      self::$path = $path;
    }
  }

  /** Read and validate a JSON score before replacing the current one. */
  public static function open(EventContext $event): void {
    $path = trim(Controller::screen('files')->widget('path')->getValue());
    try {
      $source = file_get_contents($path);
      if ($source === false) {
        throw new \RuntimeException('Could not read score: ' . $path);
      }
      $data = json_decode($source, true, 512, JSON_THROW_ON_ERROR);
      $score = Score::fromData($data);
      Controller::loadScore($score);
      self::$path = $path;
      Controller::showEditor($event);
      Controller::status('Opened ' . basename($path));
    } catch (\Throwable $error) {
      Controller::status($error->getMessage(), 'files');
    }
  }

  /** Preview old source features that cannot be edited before accepting import. */
  public static function importLegacy(EventContext $event): void {
    $path = trim(Controller::screen('files')->widget('legacy')->getValue());
    try {
      [$score, $warnings] = LegacyImporter::read($path);
      self::$pendingImport = $score;
      $screen = Controller::screen('files');
      if ($warnings === []) {
        $screen->widget('importWarnings')->setText('No unsupported features found.');
        self::acceptImport();
        return;
      }
      $screen->widget('importWarnings')->setText('Omitted on import: ' . implode(', ', $warnings));
      $screen->statusBar->confirm('Import core notes and omit listed features?', self::acceptImport(...), self::cancelImport(...));
    } catch (\Throwable $error) {
      Controller::status($error->getMessage(), 'files');
    }
  }

  /** Commit a legacy import after the user accepts its omissions. */
  public static function acceptImport(): void {
    if (self::$pendingImport === null) {
      return;
    }
    self::$path = null;
    Controller::screen('files')->widget('path')->setValue(APP_DIR . '/score.json');
    Controller::loadScore(self::$pendingImport);
    self::$pendingImport = null;
    Controller::showEditor(new EventContext('accept'));
    Controller::status('Imported core notes. Save as JSON to keep editing.');
  }

  /** Discard a pending legacy import. */
  public static function cancelImport(): void {
    self::$pendingImport = null;
  }

  /** Export every printed page to a single PDF. */
  public static function exportPdf(EventContext $event): void {
    if (Controller::currentScreenId() !== 'files') {
      Controller::showFiles($event);
      Controller::status('Choose a PDF path and press Export PDF.', 'files');
      return;
    }
    $path = trim(Controller::screen('files')->widget('pdf')->getValue());
    try {
      if ($path === '' || is_dir($path)) {
        throw new \DomainException('Enter a PDF file path.');
      }
      PdfWriter::write($path, Controller::score());
      Controller::status('Exported ' . basename($path), 'files');
    } catch (\Throwable $error) {
      Controller::status($error->getMessage(), 'files');
    }
  }

  /** Atomically store the current JSON score. */
  private static function writeScore(string $path): bool {
    try {
      $encoded = json_encode(Controller::score()->data(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
      $temp = $path . '.tmp-' . bin2hex(random_bytes(6));
      if (file_put_contents($temp, $encoded) === false || !rename($temp, $path)) {
        if (is_file($temp)) {
          unlink($temp);
        }
        throw new \RuntimeException('Could not save score: ' . $path);
      }
      Controller::status('Saved ' . basename($path), Controller::currentScreenId());
      return true;
    } catch (\Throwable $error) {
      Controller::status($error->getMessage(), Controller::currentScreenId());
      return false;
    }
  }
}
