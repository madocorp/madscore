<?php

namespace MADSCORE\App;

use MADSCORE\Render\PagePainter;
use MADSCORE\Render\PdfLayout;
use MADSCORE\Score\HarmonicContext;
use MADSCORE\Score\MelodyRange;
use MADSCORE\Score\Project;
use MADSCORE\Score\PhraseGenerator;
use MADSCORE\Score\Score;
use SPTK\App;
use SPTK\Core\Screen;
use SPTK\Events\EventContext;
use SPTK\Layout\LayoutNode;

/** Owns the score, visible sheet, and editor navigation. */
final class Controller extends ControllerState {

  /** Bind the new score and build its first visible four-staff grid. */
  public static function initialize(EventContext $event): void {
    self::$window = App::eventLoop()->windows()[0];
    self::$score = new Score();
    self::$project = Project::fromScore(self::$score);
    self::$activeItemId = 'main';
    self::$cursorLengthExplicit = false;
    self::$lastCursorEndBar = 0;
    self::$selection = new MelodySelection();
    self::$sessionLoadFailed = false;
    self::$pendingDeleteMelody = null;
    self::$composerOpen = false;
    self::$chordOpen = false;
    self::$chordChoiceOpen = null;
    self::$lastChordSettings = [];
    self::$composerListOpen = false;
    self::$composerGenerateOpen = false;
    self::$composerChoiceOpen = null;
    self::$composerKeyOpen = false;
    self::$composerKeyChoiceOpen = null;
    self::$composerGenerateTarget = 0;
    self::$motifVersionPickerOpen = false;
    self::$motifVersionAlgorithm = null;
    self::$recentMotifId = null;
    self::$itemCursors = [];
    self::$pendingBuiltScore = null;
    $restoreWarning = null;
    try {
      $state = Session::load();
      if ($state !== null) {
        self::restoreSession($state);
      }
    } catch (\Throwable $error) {
      $restoreWarning = 'Could not restore the previous session; it was backed up.';
      try {
        Session::backupInvalid();
      } catch (\Throwable $backupError) {
        self::$sessionLoadFailed = true;
        $restoreWarning = 'Could not restore or back up the previous session; it was kept.';
      }
    }
    self::$history = new ScoreHistory(self::$score);
    self::$view = 'editor';
    self::$columns = ControllerSheet::fitColumns();
    ControllerSheet::alignTimeline();
    ControllerSheet::installSheet('editor');
    ControllerSheet::installSheet('sheet');
    self::$sheet = self::$sheets[self::$view];
    self::screen('editor')->activateLeaf(self::$sheet);
    self::screen('editor')->widget('color')->setPalette(Score::COLORS);
    Settings::initialize();
    if (!self::$project->hasPdfLayout() && ($legacy = Settings::legacyPdfLayout()) !== null) {
      PdfLayout::configure(...$legacy);
      self::$project->setPdfLayout(PdfLayout::noteSize(), PdfLayout::beatSize());
    }
    $layout = self::$project->pdfLayout();
    PdfLayout::configure($layout['noteSize'], $layout['beatSize']);
    self::$previewPage = min(self::$previewPage, PagePainter::pageCount(self::$score, self::$project) - 1);
    Files::initialize();
    ControllerSheet::refreshEditorFields();
    ControllerMotifGeneration::refreshContextHeader();
    self::$window->refreshLayout();
    if ($restoreWarning !== null) {
      ControllerNavigation::status($restoreWarning);
    }
  }

  /** Store the current score and editor position when the app closes. */
  public static function persistSession(EventContext $event): void {
    if (self::$previewImagePath !== null) {
      @unlink(self::$previewImagePath);
      self::$previewImagePath = null;
    }
    if (self::$playbackFrame !== null) {
      self::$playbackCache?->close();
      self::$playbackCache = null;
      self::$firstBar = self::$playbackReturn['firstBar'];
      self::$view = self::$playbackReturn['view'];
      self::$playbackFrame = null;
    }
    if (isset(self::$score) && !self::$sessionLoadFailed) {
      Session::save(self::sessionState());
    }
  }

  private static function sessionState(): array {
    ControllerComposer::saveScorePattern();
    self::$project->setScore(self::$activeItemId, self::$score);
    self::$itemCursors[self::$activeItemId] = ControllerNoteEditing::cursorState();
    return [
      'project' => self::$project->data(),
      'activeItemId' => self::$activeItemId,
      'cursor' => self::$itemCursors[self::$activeItemId],
      'itemCursors' => self::$itemCursors,
      'firstBar' => self::$firstBar,
      'previewPage' => self::$previewPage,
      'filePath' => Files::currentPath(),
      'selection' => ControllerSelection::selectionStateForSheet(),
      'playbackSound' => self::$playbackSound,
      'chordSettings' => self::$lastChordSettings,
    ];
  }

  /** Validate persisted state before replacing the new editor defaults. */
  private static function restoreSession(array $state): void {
    if (!is_array($state['project'] ?? null) || !is_array($state['cursor'] ?? null)) {
      throw new \DomainException('The previous editor session is invalid.');
    }
    $project = Project::fromData($state['project']);
    $layout = $project->pdfLayout();
    PdfLayout::configure($layout['noteSize'], $layout['beatSize']);
    $activeItemId = $state['activeItemId'] ?? null;
    if (!is_string($activeItemId)) {
      throw new \DomainException('The previous editor item is invalid.');
    }
    $score = $project->score($activeItemId);
    $itemCursors = $state['itemCursors'] ?? [];
    if (!is_array($itemCursors)) {
      throw new \DomainException('The previous item cursors are invalid.');
    }
    foreach ($itemCursors as $id => $position) {
      if (!is_string($id) || !is_array($position)) {
        throw new \DomainException('The previous item cursors are invalid.');
      }
      ControllerNoteEditing::validateCursorState($position, $project->score($id));
    }
    $cursor = $state['cursor'];
    $bar = $cursor['bar'] ?? null;
    $slot = $cursor['slot'] ?? null;
    $octave = $cursor['octave'] ?? null;
    $savedMelody = $cursor['melody'] ?? null;
    $melody = $savedMelody;
    $length = $cursor['length'] ?? null;
    $lengthExplicit = $cursor['lengthExplicit'] ?? false;
    $pitch = $cursor['pitch'] ?? null;
    $firstBar = $state['firstBar'] ?? null;
    $previewPage = $state['previewPage'] ?? 0;
    $playbackSound = $state['playbackSound'] ?? 'midi';
    $chordSettings = $state['chordSettings'] ?? [];
    if (!is_int($bar) || $bar < 0 || $bar >= count($score->bars)
      || !is_int($slot) || $slot < 0 || $slot > 2 * $score->beats($bar)
      || !is_int($octave) || $octave < 0 || $octave > 8
      || !is_int($melody) || $melody < 0 || $melody >= count($score->melodies)
      || !is_int($length) || $length < 1 || $length > 198
      || !is_bool($lengthExplicit)
      || !is_int($pitch) || $pitch < 1 || $pitch > 12
      || !is_int($firstBar) || $firstBar < 0 || $firstBar >= count($score->bars)
      || !is_int($previewPage) || $previewPage < 0
      || !in_array($playbackSound, ['midi', 'built-in'], true)) {
      throw new \DomainException('The previous editor position is invalid.');
    }
    if (!is_array($chordSettings)) {
      throw new \DomainException('The previous chord settings are invalid.');
    }
    foreach ($chordSettings as $key => $settings) {
      if (!is_string($key)
        || preg_match('/^(?:main:[0-9]+|[A-Z]+)$/D', $key) !== 1
        || !is_array($settings)
        || !in_array(array_keys($settings), [
          ['count', 'series', 'degree', 'inversion', 'music'],
          ['count', 'series', 'degree', 'inversion', 'music', 'mode'],
        ], true)
        || !is_int($settings['count']) || $settings['count'] < 2
        || $settings['count'] > 7
        || !is_int($settings['series']) || $settings['series'] < 1
        || $settings['series'] > 16
        || ($settings['degree'] !== null && (!is_int($settings['degree'])
          || $settings['degree'] < 1 || $settings['degree'] > 7))
        || (array_key_exists('mode', $settings)
          && !in_array($settings['mode'], ['separate', 'current'], true))
        || ($settings['inversion'] !== null && (!is_int($settings['inversion'])
          || $settings['inversion'] < 0
          || $settings['inversion'] >= $settings['count']))) {
        throw new \DomainException('The previous chord settings are invalid.');
      }
      HarmonicContext::validate($settings['music']);
    }
    $selection = new MelodySelection();
    if (($savedSelection = $state['selection'] ?? null) !== null) {
      $scoreEnd = $score->start(count($score->bars));
      if (!is_array($savedSelection)
        || !is_int($savedSelection['anchor'] ?? null) || $savedSelection['anchor'] < 0 || $savedSelection['anchor'] > $scoreEnd
        || !is_int($savedSelection['caret'] ?? null) || $savedSelection['caret'] < 0 || $savedSelection['caret'] > $scoreEnd
        || ($savedSelection['melody'] ?? null) !== $savedMelody) {
        throw new \DomainException('The previous editor selection is invalid.');
      }
      $scope = $savedSelection['scope'] ?? 'melody';
      if (!in_array($scope, ['melody', 'score'], true)) {
        throw new \DomainException('The previous editor selection scope is invalid.');
      }
      if (($savedSelection['melody'] ?? null) === $melody) {
        $selection->extend($savedSelection['anchor'], $savedSelection['caret'], $melody, $scope);
      }
    }
    try {
      MelodyRange::bands($score, $melody, $octave, $pitch);
    } catch (\DomainException $error) {
      $octave = MelodyRange::bands($score, $melody)[1];
    }
    self::$score = $score;
    self::$project = $project;
    self::$activeItemId = $activeItemId;
    self::$recentMotifId = $activeItemId === 'main' ? null : $activeItemId;
    self::$itemCursors = $itemCursors;
    self::$itemCursors[$activeItemId] = $cursor;
    self::$bar = $bar;
    self::$slot = $slot;
    self::$octave = $octave;
    self::$melody = $melody;
    self::$cursorLength = $length;
    self::$cursorLengthExplicit = $lengthExplicit;
    self::$pitchPosition = $pitch;
    self::$firstBar = $firstBar;
    self::$previewPage = $previewPage;
    self::$playbackSound = $playbackSound;
    self::$lastChordSettings = $chordSettings;
    self::$selection = $selection;
    self::$lastCursorEndBar = min(ControllerSheet::cursorEndBar(), count($score->bars) - 1);
    self::$displayedBar = -1;
    self::$displayedReadout = '';
    Files::restorePath($state['filePath'] ?? null);
  }

  /** Recalculate visible bar columns when the native window width changes. */
  public static function resize(EventContext $event): void {
    if (!isset(self::$window) || $event->input !== self::$window) {
      return;
    }
    if (self::$playbackFrame !== null) {
      ControllerPlayback::stopPlayback('Playback stopped after window resize.');
    }
    $columns = ControllerSheet::fitColumns();
    if ($columns !== self::$columns) {
      self::$columns = $columns;
      ControllerSheet::alignTimeline();
      self::$sheets['editor']->instance()->invalidate();
    }
  }

  /** Return the active score for renderers and commands. */
  public static function score(): Score {
    return self::$score;
  }

  /** Give renderers the project with the active score's latest edits. */
  public static function project(): Project {
    self::$project->setScore(self::$activeItemId, self::$score);
    return self::$project;
  }

  public static function firstVisibleBar(): int {
    return self::$firstBar;
  }

  public static function isDesign(): bool {
    return self::$view === 'editor';
  }

  public static function visibleColumns(): int {
    return self::$columns;
  }

  public static function currentBar(): int {
    return self::$bar;
  }

  /** Return the screen with the given ID. */
  public static function screen(string $id): Screen {
    return self::$window->screen($id) ?? throw new \RuntimeException('Missing screen: ' . $id);
  }

  /** Open a grouped Note panel with focus inside its controls. */
  public static function showEditorPanel(string $id): bool {
    $screen = self::screen('editor');
    if (!$screen->show($id)) {
      return false;
    }
    $panel = $screen->layout->findTile($id);
    if ($panel instanceof LayoutNode && $panel->entryFocus() !== null) {
      $first = $panel->childMovementLeaves()[0] ?? null;
      if ($first !== null) {
        $screen->selectLeaf($first);
      }
    }
    return true;
  }

  /** Whether a picker or panel currently occupies the Note tile. */
  public static function editorPanelOpen(): bool {
    return self::screen('editor')->layout->findTile('noteBox')->overwrittenBy() !== null;
  }

  /** Rebuild the selected melody's saved curve for the editor sheet. */
  public static function composerPreview(): ?array {
    $settings = self::$score->melodies[self::$melody]['generation'] ?? null;
    return is_array($settings) ? PhraseGenerator::fromMetadata($settings) : null;
  }

}
