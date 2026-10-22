<?php

namespace MADSCORE\App;

use MADSCORE\Play\MidiWriter;
use MADSCORE\Render\PdfWriter;
use MADSCORE\Score\LegacyImporter;
use MADSCORE\Score\Project;
use MADSCORE\Score\Score;
use SPTK\Events\EventContext;

  /** Named project files and the editor's temporary file panels. */
final class Files {

  private static ?string $path = null;
  private static ?string $lastDirectory = null;

  public static function currentPath(): ?string {
    return self::$path;
  }

  /** Restore only an absolute named path; the score itself lives in the session. */
  public static function restorePath(mixed $path): void {
    self::$path = is_string($path) && str_starts_with($path, '/') && !str_contains($path, "\0") ? $path : null;
  }

  public static function initialize(): void {
    $screen = Controller::screen('editor');
    self::$lastDirectory = null;
    $directory = Settings::defaultDirectory();
    $screen->widget('saveDirectory')->setPath($directory);
    $screen->widget('loadSelector')->setPath($directory);
    $screen->widget('saveFilename')->setValue(self::$path === null ? 'score.json' : basename(self::$path));
  }

  /** Select a directory and file name for a new named save. */
  public static function showSave(): void {
    $screen = Controller::screen('editor');
    $directory = self::browserDirectory();
    try {
      $screen->widget('saveDirectory')->setPath($directory);
    } catch (\Throwable $error) {
      Controller::status($error->getMessage());
      return;
    }
    self::$lastDirectory = $directory;
    $screen->widget('saveFilename')->setValue(self::$path === null ? 'score.json' : basename(self::$path));
    Controller::showFilePanel('savePanel');
  }

  /** Reopen the last directory and let Return on a file load it. */
  public static function showLoad(): void {
    $screen = Controller::screen('editor');
    $directory = self::browserDirectory();
    try {
      $screen->widget('loadSelector')->setPath($directory);
    } catch (\Throwable $error) {
      Controller::status($error->getMessage());
      return;
    }
    self::$lastDirectory = $directory;
    Controller::showFilePanel('loadPanel');
  }

  /** Remember directory navigation across both file selectors. */
  public static function directoryChanged(EventContext $event): void {
    $widget = $event->widget;
    if ($widget !== null && in_array($widget->id(), ['saveDirectory', 'loadSelector'], true)) {
      self::$lastDirectory = $widget->path();
    }
  }

  /** Use the preference once, then the last directory visited in this session. */
  private static function browserDirectory(): string {
    return self::$lastDirectory !== null && is_dir(self::$lastDirectory)
      ? self::$lastDirectory : Settings::defaultDirectory();
  }

  /** Write changes only to the file already associated with the score. */
  public static function write(EventContext $event): void {
    if (self::$path === null) {
      Controller::status('No current file. Use Ctrl+S to save first.');
      return;
    }
    self::writeScore(self::$path);
  }

  /** Save in the directory shown by SPTK's file selector. */
  public static function saveAs(EventContext $event): void {
    $screen = Controller::screen('editor');
    try {
      $name = trim((string)$screen->widget('saveFilename')->getValue());
      if ($name === '' || $name === '.' || $name === '..' || strpbrk($name, '/\\') !== false
        || preg_match('/[\x00-\x1f\x7f]/', $name)) {
        throw new \DomainException('Enter a file name without path separators or control characters.');
      }
      if (pathinfo($name, PATHINFO_EXTENSION) === '') {
        $name .= '.json';
      } else if (strtolower(pathinfo($name, PATHINFO_EXTENSION)) !== 'json') {
        throw new \DomainException('Use a .json score file name.');
      }
      $path = $screen->widget('saveDirectory')->path() . '/' . $name;
      if (is_dir($path)) {
        throw new \DomainException('The file name belongs to a directory.');
      }
      if (is_file($path) && $path !== self::$path) {
        $screen->statusBar?->confirm('Overwrite ' . $name . '?',
          static function() use ($path): void { self::finishSave($path); },
          static function(): void { Controller::status('Save cancelled.'); });
        return;
      }
      self::finishSave($path);
    } catch (\Throwable $error) {
      Controller::status($error->getMessage());
    }
  }

  private static function finishSave(string $path): void {
    if (self::writeScore($path)) {
      self::$path = $path;
      Controller::hideFilePanel('savePanel');
    }
  }

  /** Validate a selected score before replacing the editor document. */
  public static function open(EventContext $event): bool {
    $path = Controller::screen('editor')->widget('loadSelector')->activeValue();
    if ($path === null || !is_file($path)) {
      Controller::status('Select a score file to load.');
      return true;
    }
    try {
      if (strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'txt') {
        [$score, $warnings] = LegacyImporter::read($path);
        if ($warnings !== []) {
          Controller::screen('editor')->statusBar?->confirm(
            'Import and omit: ' . implode(', ', $warnings) . '?',
            static function() use ($score): void { self::finishOpen($score, null, 'Imported core notes. Save as JSON to keep editing.'); },
            static function(): void { Controller::status('Import cancelled.'); });
          return true;
        }
        self::finishOpen($score, null, 'Imported core notes. Save as JSON to keep editing.');
        return true;
      }
      $source = file_get_contents($path);
      if ($source === false) {
        throw new \RuntimeException('Could not read score: ' . $path);
      }
      $project = Project::fromData(json_decode($source, true, 512, JSON_THROW_ON_ERROR));
      self::finishOpenProject($project, $path, 'Opened ' . basename($path));
    } catch (\Throwable $error) {
      Controller::status($error->getMessage());
    }
    return true;
  }

  private static function finishOpen(Score $score, ?string $path, string $message): void {
    Controller::hideFilePanel('loadPanel');
    self::$path = $path;
    Controller::loadScore($score);
    Controller::status($message);
  }

  private static function finishOpenProject(Project $project, ?string $path, string $message): void {
    Controller::hideFilePanel('loadPanel');
    self::$path = $path;
    Controller::loadProject($project);
    Controller::status($message);
  }

  /** Start an unnamed, empty document from the Load panel. */
  public static function createNew(EventContext $event): void {
    self::finishOpen(new Score(), null, 'New empty score. Use Ctrl+S to save it.');
  }

  public static function cancel(EventContext $event): bool {
    Controller::hideFilePanel('savePanel');
    Controller::hideFilePanel('loadPanel');
    return true;
  }

  /** Let Escape cancel a file panel before editor navigation handles it. */
  public static function cancelShortcut(EventContext $event): bool {
    $screen = Controller::screen('editor');
    if ($screen->layout->findTile('savePanel')->overwriting() === null
      && $screen->layout->findTile('loadPanel')->overwriting() === null) {
      return false;
    }
    return self::cancel($event);
  }

  /** Preserve PDF export without a separate Files screen. */
  public static function exportPdf(EventContext $event): void {
    if (self::$path === null) {
      Controller::status('Save the score before exporting PDF.');
      return;
    }
    $path = self::exportPath('pdf');
    try {
      PdfWriter::write($path, Controller::score());
      Controller::status('Exported ' . basename($path));
    } catch (\Throwable $error) {
      Controller::status($error->getMessage());
    }
  }

  /** Export beside the named JSON score. */
  public static function exportMidi(EventContext $event): void {
    if (self::$path === null) {
      Controller::status('Save the score before exporting MIDI.');
      return;
    }
    $path = self::exportPath('mid');
    try {
      MidiWriter::write($path, Controller::score());
      Controller::status('Exported ' . basename($path));
    } catch (\Throwable $error) {
      Controller::status($error->getMessage());
    }
  }

  private static function exportPath(string $extension): string {
    return preg_match('/\.json$/i', self::$path) === 1
      ? substr(self::$path, 0, -5) . '.' . $extension
      : self::$path . '.' . $extension;
  }

  /** Atomically store the current JSON score. */
  private static function writeScore(string $path): bool {
    $temp = null;
    try {
      $encoded = json_encode(Controller::projectData(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
      $temp = $path . '.tmp-' . bin2hex(random_bytes(6));
      if (file_put_contents($temp, $encoded) !== strlen($encoded) || !rename($temp, $path)) {
        throw new \RuntimeException('Could not save score: ' . $path);
      }
      Controller::status('Saved ' . basename($path));
      return true;
    } catch (\Throwable $error) {
      Controller::status($error->getMessage());
      return false;
    } finally {
      if ($temp !== null && is_file($temp)) {
        unlink($temp);
      }
    }
  }
}
