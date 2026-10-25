<?php

namespace MADSCORE\App;

use MADSCORE\Play\MidiWriter;
use MADSCORE\Play\WavWriter;
use MADSCORE\Render\PdfWriter;
use MADSCORE\Score\LegacyImporter;
use MADSCORE\Score\MusicXmlImporter;
use MADSCORE\Score\MusicXmlWriter;
use MADSCORE\Score\Project;
use MADSCORE\Score\Score;
use SPTK\Events\EventContext;

/** Named project files and the editor's temporary file panels. */
final class Files {

  private const PROJECT_EXTENSION = '.json.gz';
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
    $screen->widget('saveFilename')->setValue(self::suggestedName());
  }

  /** Select a directory and file name for a new named save. */
  public static function showSave(): void {
    $screen = Controller::screen('editor');
    $directory = self::browserDirectory();
    try {
      $screen->widget('saveDirectory')->setPath($directory);
    } catch (\Throwable $error) {
      ControllerNavigation::status($error->getMessage());
      return;
    }
    self::$lastDirectory = $directory;
    $screen->widget('saveFilename')->setValue(self::suggestedName());
    ControllerEditorActions::showFilePanel('savePanel');
  }

  /** Reopen the last directory and let Return on a file load it. */
  public static function showLoad(): void {
    $screen = Controller::screen('editor');
    $directory = self::browserDirectory();
    try {
      $screen->widget('loadSelector')->setPath($directory);
    } catch (\Throwable $error) {
      ControllerNavigation::status($error->getMessage());
      return;
    }
    self::$lastDirectory = $directory;
    ControllerEditorActions::showFilePanel('loadPanel');
  }

  /** Open the format list over the note tile. */
  public static function showExport(): void {
    ControllerEditorActions::showFilePanel('exportPanel');
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

  /** Offer a compressed name when saving an older plain JSON project as a new file. */
  private static function suggestedName(): string {
    if (self::$path === null) {
      return 'score' . self::PROJECT_EXTENSION;
    }
    $name = basename(self::$path);
    return preg_match('/\.json$/i', $name) === 1 ? $name . '.gz' : $name;
  }

  /** Write changes only to the file already associated with the score. */
  public static function write(EventContext $event): void {
    if (self::$path === null) {
      ControllerNavigation::status('No current file. Use Ctrl+S to save first.');
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
      $name = self::projectName($name);
      $path = $screen->widget('saveDirectory')->path() . '/' . $name;
      if (is_dir($path)) {
        throw new \DomainException('The file name belongs to a directory.');
      }
      if (is_file($path) && $path !== self::$path) {
        $screen->statusBar?->confirm('Overwrite ' . $name . '?',
          static function() use ($path): void { self::finishSave($path); },
          static function(): void { ControllerNavigation::status('Save cancelled.'); });
        return;
      }
      self::finishSave($path);
    } catch (\Throwable $error) {
      ControllerNavigation::status($error->getMessage());
    }
  }

  /** Give new projects a gzip suffix while accepting older .json names as input. */
  private static function projectName(string $name): string {
    if (preg_match('/\.json\.gz$/i', $name) === 1) {
      return $name;
    }
    if (preg_match('/\.json$/i', $name) === 1) {
      return $name . '.gz';
    }
    if (pathinfo($name, PATHINFO_EXTENSION) === '') {
      return $name . self::PROJECT_EXTENSION;
    }
    throw new \DomainException('Use a .json.gz project file name.');
  }

  private static function finishSave(string $path): void {
    if (self::writeScore($path)) {
      self::$path = $path;
      ControllerEditorActions::hideFilePanel('savePanel');
    }
  }

  /** Validate a selected score before replacing the editor document. */
  public static function open(EventContext $event): bool {
    $path = Controller::screen('editor')->widget('loadSelector')->activeValue();
    if ($path === null || !is_file($path)) {
      ControllerNavigation::status('Select a score file to load.');
      return true;
    }
    try {
      if (strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'txt') {
        [$score, $warnings] = LegacyImporter::read($path);
        if ($warnings !== []) {
          Controller::screen('editor')->statusBar?->confirm(
            'Import and omit: ' . implode(', ', $warnings) . '?',
            static function() use ($score): void { self::finishOpen($score, null, 'Imported core notes. Save as a project to keep editing.'); },
            static function(): void { ControllerNavigation::status('Import cancelled.'); });
          return true;
        }
        self::finishOpen($score, null, 'Imported core notes. Save as a project to keep editing.');
        return true;
      }
      if (in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), ['musicxml', 'xml', 'mxl'], true)) {
        [$score, $warnings] = MusicXmlImporter::read($path);
        if ($warnings !== []) {
          Controller::screen('editor')->statusBar?->confirm(
            'Import and omit: ' . implode(', ', $warnings) . '?',
            static function() use ($score): void { self::finishOpen($score, null, 'Imported MusicXML. Save as a project to keep editing.'); },
            static function(): void { ControllerNavigation::status('Import cancelled.'); });
          return true;
        }
        self::finishOpen($score, null, 'Imported MusicXML. Save as a project to keep editing.');
        return true;
      }
      $project = self::readProject($path);
      self::finishOpenProject($project, $path, 'Opened ' . basename($path));
    } catch (\Throwable $error) {
      ControllerNavigation::status($error->getMessage());
    }
    return true;
  }

  /** Decode compressed projects and older plain JSON projects. */
  private static function readProject(string $path): Project {
    $source = file_get_contents($path);
    if ($source === false) {
      throw new \RuntimeException('Could not read score: ' . $path);
    }
    if (preg_match('/\.json\.gz$/i', $path) === 1) {
      $source = @gzdecode($source);
      if ($source === false) {
        throw new \DomainException('The compressed project file is invalid.');
      }
    }
    return Project::fromData(json_decode($source, true, 512, JSON_THROW_ON_ERROR));
  }

  private static function finishOpen(Score $score, ?string $path, string $message): void {
    ControllerEditorActions::hideFilePanel('loadPanel');
    self::$path = $path;
    ControllerNoteEditing::loadScore($score);
    ControllerNavigation::status($message);
  }

  private static function finishOpenProject(Project $project, ?string $path, string $message): void {
    ControllerEditorActions::hideFilePanel('loadPanel');
    self::$path = $path;
    ControllerNoteEditing::loadProject($project);
    ControllerNavigation::status($message);
  }

  /** Start an unnamed, empty document from the Load panel. */
  public static function createNew(EventContext $event): void {
    self::finishOpen(new Score(), null, 'New empty score. Use Ctrl+S to save it.');
  }

  public static function cancel(EventContext $event): bool {
    ControllerEditorActions::hideFilePanel('savePanel');
    ControllerEditorActions::hideFilePanel('loadPanel');
    ControllerEditorActions::hideFilePanel('exportPanel');
    return true;
  }

  /** Let Escape cancel a file panel before editor navigation handles it. */
  public static function cancelShortcut(EventContext $event): bool {
    $screen = Controller::screen('editor');
    if ($screen->layout->findTile('savePanel')->overwriting() === null
      && $screen->layout->findTile('loadPanel')->overwriting() === null
      && $screen->layout->findTile('exportPanel')->overwriting() === null) {
      return false;
    }
    return self::cancel($event);
  }

  /** Export each checked format beside the named project. */
  public static function exportSelected(EventContext $event): void {
    if (self::$path === null) {
      ControllerNavigation::status('Save the score before exporting.');
      return;
    }
    $formats = Controller::screen('editor')->widget('exportFormats')->getValue();
    if ($formats === []) {
      ControllerNavigation::status('Select at least one export format.');
      return;
    }
    $exported = [];
    $failed = [];
    foreach ($formats as $format) {
      $path = self::exportPath($format === 'midi' ? 'mid' : $format);
      try {
        match ($format) {
          'midi' => MidiWriter::write($path, Controller::score(), Controller::project()),
          'musicxml' => MusicXmlWriter::write($path, Controller::score()),
          'pdf' => PdfWriter::write($path, Controller::score(), Controller::project()),
          'wav' => WavWriter::write($path, Controller::score(), Controller::project()),
          default => throw new \DomainException('Unknown export format: ' . $format),
        };
        $exported[] = basename($path);
      } catch (\Throwable $error) {
        $failed[] = $format . ': ' . $error->getMessage();
      }
    }
    if ($failed === []) {
      ControllerEditorActions::hideFilePanel('exportPanel');
    }
    ControllerNavigation::status(($exported === [] ? '' : 'Exported ' . implode(', ', $exported) . '.')
      . ($failed === [] ? '' : ($exported === [] ? '' : ' ') . 'Failed ' . implode('; ', $failed)));
  }

  private static function exportPath(string $extension): string {
    $path = preg_replace('/\.json(?:\.gz)?$/i', '.' . $extension, self::$path, 1, $count);
    return $count === 1 ? $path : self::$path . '.' . $extension;
  }

  /** Atomically store gzip JSON, retaining plain JSON for an existing old path. */
  private static function writeScore(string $path): bool {
    $temp = null;
    try {
      $compressed = preg_match('/\.json\.gz$/i', $path) === 1;
      $json = json_encode(ControllerNoteEditing::projectData(),
        ($compressed ? 0 : JSON_PRETTY_PRINT) | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
      $encoded = $compressed ? gzencode($json, 6) : $json . "\n";
      if ($encoded === false) {
        throw new \RuntimeException('Could not compress project: ' . $path);
      }
      $temp = $path . '.tmp-' . bin2hex(random_bytes(6));
      if (file_put_contents($temp, $encoded) !== strlen($encoded) || !rename($temp, $path)) {
        throw new \RuntimeException('Could not save score: ' . $path);
      }
      ControllerNavigation::status('Saved ' . basename($path));
      return true;
    } catch (\Throwable $error) {
      ControllerNavigation::status($error->getMessage());
      return false;
    } finally {
      if ($temp !== null && is_file($temp)) {
        unlink($temp);
      }
    }
  }
}
