<?php

namespace MADSCORE\App;

use MADSCORE\Play\{MidiPlayback, PlaybackTimeline, ScoreSource, SheetCache};
use MADSCORE\Render\{PagePainter, PagePreview, SheetPainter};
use MADSCORE\Score\Dynamics;
use MADSCORE\Score\DynamicLevel;
use MADSCORE\Score\MelodyRange;
use MADSCORE\Score\Pitch;
use MADSCORE\Score\RangeEdit;
use MADSCORE\Score\Score;
use MADSCORE\Score\Sound;

use SPTK\App;
use SPTK\Core\{Screen, Style, Window};
use SPTK\Events\EventContext;
use SPTK\Events\KeyNormalizer;
use SPTK\Layout\{LayoutLeaf, LayoutNode};
use SPTK\SDLWrapper\SDL;
use SPTK\Widgets\Image\Image;

/** Owns the score, visible sheet, and editor navigation. */
final class Controller {

  private static Score $score;
  private static Window $window;
  private static LayoutLeaf $sheet;
  /** @var array<string, LayoutLeaf> */
  private static array $sheets = [];
  private static int $columns = 0;
  private static int $firstBar = 0;
  private static int $firstRow = 0;
  private static int $previewPage = 0;
  private static ?string $previewImagePath = null;
  private static string $view = 'editor';
  private static int $bar = 0;
  private static int $slot = 0;
  private static int $octave = 4;
  private static int $melody = 0;
  private static int $cursorLength = 2;
  private static bool $cursorLengthExplicit = false;
  private static int $lastCursorEndBar = 0;
  private static int $pitchPosition = 4;
  private static int $displayedBar = -1;
  private static string $displayedReadout = '';
  private static ?MelodySelection $selection = null;
  private static bool $sessionLoadFailed = false;
  private static ?string $playbackMode = null;
  private static ?array $playbackFrame = null;
  private static ?PlaybackTimeline $playbackTimeline = null;
  private static ?SheetCache $playbackCache = null;
  private static ?int $playbackTimer = null;
  private static int $playbackBeatIndex = -1;
  private static float $playbackEndSeconds = 0.0;
  private static array $playbackHeldKeys = [];
  private static ?int $playbackDrainTimer = null;
  private static ?string $playbackDrainView = null;
  private static bool $playbackDrainBatch = false;
  private static bool $playbackSuppressText = false;
  private static ?array $playbackReturn = null;
  private static string $playbackSound = 'midi';
  private static ?int $pendingDeleteMelody = null;
  private static ?ScoreHistory $history = null;
  private static ?array $musicClipboard = null;

  /** Bind the new score and build its first visible four-staff grid. */
  public static function initialize(EventContext $event): void {
    self::$window = App::eventLoop()->windows()[0];
    self::$score = new Score();
    self::$cursorLengthExplicit = false;
    self::$lastCursorEndBar = 0;
    self::$selection = new MelodySelection();
    self::$sessionLoadFailed = false;
    self::$pendingDeleteMelody = null;
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
    self::$columns = self::fitColumns();
    self::alignTimeline();
    self::installSheet('editor');
    self::installSheet('sheet');
    self::$sheet = self::$sheets[self::$view];
    self::screen('editor')->activateLeaf(self::$sheet);
    self::screen('editor')->widget('color')->setPalette(Score::COLORS);
    Settings::initialize();
    Files::initialize();
    self::refreshEditorFields();
    self::$window->refreshLayout();
    if ($restoreWarning !== null) {
      self::status($restoreWarning);
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
    return [
      'score' => self::$score->data(),
      'cursor' => [
        'bar' => self::$bar,
        'slot' => self::$slot,
        'octave' => self::$octave,
        'melody' => self::$melody,
        'length' => self::$cursorLength,
        'lengthExplicit' => self::$cursorLengthExplicit,
        'pitch' => self::$pitchPosition,
      ],
      'firstBar' => self::$firstBar,
      'firstRow' => self::$firstRow,
      'previewPage' => self::$previewPage,
      'view' => self::$view,
      'filePath' => Files::currentPath(),
      'selection' => self::selectionStateForSheet(),
      'playbackSound' => self::$playbackSound,
    ];
  }

  /** Validate persisted state before replacing the new editor defaults. */
  private static function restoreSession(array $state): void {
    if (!is_array($state['score'] ?? null) || !is_array($state['cursor'] ?? null)) {
      throw new \DomainException('The previous editor session is invalid.');
    }
    $score = Score::fromData($state['score']);
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
    $firstRow = $state['firstRow'] ?? 0;
    $previewPage = $state['previewPage'] ?? 0;
    $view = $state['view'] ?? 'editor';
    $playbackSound = $state['playbackSound'] ?? 'midi';
    if (!is_int($bar) || $bar < 0 || $bar >= count($score->bars)
      || !is_int($slot) || $slot < 0 || $slot > 2 * $score->beats($bar)
      || !is_int($octave) || $octave < 0 || $octave > 8
      || !is_int($melody) || $melody < 0 || $melody >= count($score->melodies)
      || !is_int($length) || $length < 1 || $length > 198
      || !is_bool($lengthExplicit)
      || !is_int($pitch) || $pitch < 1 || $pitch > 12
      || !is_int($firstBar) || $firstBar < 0 || $firstBar >= count($score->bars)
      || !is_int($firstRow) || $firstRow < 0 || $firstRow > 5
      || !is_int($previewPage) || $previewPage < 0
      || !in_array($view, ['editor', 'sheet'], true)
      || !in_array($playbackSound, ['midi', 'built-in'], true)) {
      throw new \DomainException('The previous editor position is invalid.');
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
    self::$bar = $bar;
    self::$slot = $slot;
    self::$octave = $octave;
    self::$melody = $melody;
    self::$cursorLength = $length;
    self::$cursorLengthExplicit = $lengthExplicit;
    self::$pitchPosition = $pitch;
    self::$firstBar = $firstBar;
    self::$firstRow = $firstRow;
    self::$previewPage = min($previewPage, count(PagePainter::pages($score)) - 1);
    self::$view = $view;
    self::$playbackSound = $playbackSound;
    self::$selection = $selection;
    self::$lastCursorEndBar = min(self::cursorEndBar(), count($score->bars) - 1);
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
      self::stopPlayback('Playback stopped after window resize.');
    }
    $columns = self::fitColumns();
    if ($columns !== self::$columns) {
      self::$columns = $columns;
      self::alignTimeline();
      self::$sheets['editor']->instance()->invalidate();
    }
  }

  /** Return the active score for renderers and commands. */
  public static function score(): Score {
    return self::$score;
  }

  /** Undo the latest score edit. */
  public static function undoMelody(): void {
    self::changeMelodyHistory(false);
  }

  /** Redo the latest undone edit in the selected melody. */
  public static function redoMelody(): void {
    self::changeMelodyHistory(true);
  }

  /** Handle an editor screen shortcut outside the active sheet. */
  public static function undoMelodyShortcut(EventContext $event): bool {
    if (!$event->input->key->repeat) {
      self::undoMelody();
    }
    return true;
  }

  /** Handle an editor screen redo shortcut outside the active sheet. */
  public static function redoMelodyShortcut(EventContext $event): bool {
    if (!$event->input->key->repeat) {
      self::redoMelody();
    }
    return true;
  }

  public static function melodyWedgeShortcut(EventContext $event): bool {
    EditorInput::suppressNextWedgeText();
    if (!$event->input->key->repeat) {
      try {
        self::cycleDynamic();
      } catch (\DomainException $error) {
        self::status($error->getMessage());
      }
    }
    return true;
  }

  /** Recognize the numpad star, which SDL gives a separate keycode. */
  public static function keypadMelodyWedgeShortcut(EventContext $event): bool {
    $modifiers = (int)$event->input->key->mod;
    if ((int)$event->input->key->key !== SDL::KEY_KP_MULTIPLY
      || ($modifiers & SDL::MOD_CTRL) === 0
      || ($modifiers & (SDL::MOD_ALT | SDL::MOD_MODE)) !== 0) {
      return false;
    }
    return self::melodyWedgeShortcut($event);
  }

  /** Apply one saved score state and refresh its cursor and controls. */
  private static function changeMelodyHistory(bool $redo): void {
    self::$history ??= new ScoreHistory(self::$score);
    try {
      $changed = $redo
        ? self::$history->redo(self::$score, self::$melody)
        : self::$history->undo(self::$score, self::$melody);
    } catch (\DomainException $error) {
      self::status('Cannot restore the score: ' . $error->getMessage());
      return;
    }
    if (!$changed) {
      self::status($redo ? 'Nothing to redo.' : 'Nothing to undo.');
      return;
    }
    self::$bar = min(self::$bar, count(self::$score->bars) - 1);
    self::$melody = min(self::$melody, count(self::$score->melodies) - 1);
    self::$slot = min(self::$slot, 2 * self::$score->beats(self::$bar));
    $pitch = MelodyRange::cursorPitch(self::$score, self::$melody, self::$bar, self::$slot);
    if ($pitch !== null) {
      [self::$octave, self::$pitchPosition] = $pitch;
    } else {
      $bands = MelodyRange::bands(self::$score, self::$melody);
      if (!in_array(self::$octave, $bands, true)) {
        self::$octave = $bands[1];
      }
    }
    self::clearSelection();
    self::syncNoteLength();
    self::$previewPage = min(self::$previewPage, count(PagePainter::pages(self::$score)) - 1);
    self::refreshMelodyFields();
    self::$displayedReadout = '';
    self::focusCursor();
    self::$window->refreshLayout();
  }

  /** Replace the document after a validated load or import. */
  public static function loadScore(Score $score): void {
    self::$pendingDeleteMelody = null;
    if (self::$playbackMode !== null) {
      App::audio()->stopMusic();
      self::$playbackMode = null;
    }
    self::$score = $score;
    self::$history = new ScoreHistory($score);
    self::$selection = new MelodySelection();
    self::$bar = 0;
    self::$slot = 0;
    self::$octave = MelodyRange::bands($score, 0)[1];
    self::$melody = 0;
    self::$cursorLength = 2;
    self::$cursorLengthExplicit = false;
    self::$lastCursorEndBar = 0;
    self::$pitchPosition = 4;
    self::$firstBar = 0;
    self::$firstRow = 0;
    self::$previewPage = 0;
    self::$view = 'editor';
    self::$sheet = self::$sheets['editor'];
    self::$window->setCurrentScreenId('editor');
    self::$columns = self::fitColumns();
    self::screen('editor')->activateLeaf(self::$sheet);
    self::invalidateSheet();
    self::refreshEditorFields();
    self::$window->refreshLayout();
  }

  /** Return the selected colored melody index. */
  public static function melody(): int {
    return self::$melody;
  }

  /** Return the active half-beat position for note entry. */
  public static function cursorSlot(): int {
    return self::$slot;
  }

  /** Return the preview width in half-beat positions. */
  public static function cursorLength(): int {
    $index = self::currentNoteIndex();
    return $index === null
      ? (self::$cursorLengthExplicit ? self::$cursorLength : self::$score->freeLength(self::$melody, self::$bar, self::$slot, self::$cursorLength))
      : self::$score->notes[$index]['length'];
  }

  /** Change the preview width and repaint its old and new spans. */
  public static function setCursorLength(int $length): void {
    if ($length < 1 || $length > 198) {
      throw new \DomainException('Length must be between half a beat and 99 beats.');
    }
    $index = self::currentNoteIndex();
    if ($index !== null) {
      self::$score->resizeAndShift($index, $length);
      self::selectNoteOnset($index);
    } else {
      self::$score->resizeGapAndShift(self::$melody, self::$bar, self::$slot, self::cursorLength(), $length);
    }
    self::$cursorLength = $length;
    self::$cursorLengthExplicit = $index === null;
    self::clearSelection();
    self::focusCursor();
  }

  /** Choose a natural pitch, placing a note if the cursor is empty. */
  public static function setPitchLetter(string $letter): void {
    $position = Pitch::naturalPosition($letter);
    $index = self::currentNoteIndex();
    if ($index === null) {
      self::$pitchPosition = $position;
      self::dropNote();
      return;
    }
    $oldNote = self::$score->notes[$index];
    self::$score->setPitchPosition($index, $oldNote['octave'], $position);
    self::changedNote($oldNote, self::$score->notes[$index]);
    self::$octave = $oldNote['octave'];
    self::selectNoteOnset($index);
    self::$pitchPosition = $position;
    self::focusCursor();
  }

  /** Move only the pitch cursor by one semitone. */
  public static function stepPitch(int $direction): void {
    [$octave, $position] = Pitch::step(self::$octave, self::$pitchPosition, $direction);
    self::assertPreviewBand($octave, $position);
    self::$octave = $octave;
    self::$pitchPosition = $position;
    self::focusWithoutHistory();
  }

  /** Place the preview note at the current time position. */
  public static function dropNote(): void {
    $index = self::$score->noteAt(self::$melody, self::$bar, self::$slot);
    $before = $index === null ? null : self::$score->notes[$index];
    $bar = $before['bar'] ?? self::$bar;
    $slot = $before['slot'] ?? self::$slot;
    $index = self::$score->place(self::$melody, $bar, $slot, self::$octave, self::$pitchPosition, self::cursorLength());
    self::changedNote($before, self::$score->notes[$index]);
    self::selectNoteOnset($index);
    self::$cursorLengthExplicit = false;
    self::focusCursor();
  }

  /** Place a note in a gap, or move the pitch cursor to an existing note. */
  public static function followNotePitch(): void {
    $index = self::currentNoteIndex();
    if ($index === null) {
      self::dropNote();
      return;
    }
    $note = self::$score->notes[$index];
    self::$octave = $note['octave'];
    self::$pitchPosition = Pitch::position($note);
    self::focusWithoutHistory();
  }

  /** Toggle the selected note's short articulation. */
  public static function toggleStaccato(): void {
    $index = self::currentNoteIndex();
    if ($index === null) {
      throw new \DomainException('Select a note to change its articulation.');
    }
    $note = self::$score->notes[$index];
    self::$score->setStaccato($index, ($note['articulation'] ?? 'ordinary') !== 'staccato');
    self::focusCursor();
    self::invalidateSheet();
  }

  /** Toggle a hold on the selected note. */
  public static function toggleFermata(): void {
    $index = self::currentNoteIndex();
    if ($index === null) {
      throw new \DomainException('Select a note to change its fermata.');
    }
    self::$score->setFermata($index, !self::$score->notes[$index]['fermata']);
    self::focusCursor();
    self::invalidateSheet();
  }

  /** Toggle a legato link, or link the selected notes as one group. */
  public static function toggleSlur(): void {
    if (($range = self::selectionStateForSheet()) !== null) {
      self::applyRangeScore(RangeEdit::mark(self::$score, $range, 'link'));
      return;
    }
    $index = self::currentNoteIndex();
    if ($index === null) {
      throw new \DomainException('Select a note to start a slur.');
    }
    self::$score->setSlurToNext($index, !self::$score->notes[$index]['slurToNext']);
    self::focusCursor();
    self::invalidateSheet();
  }

  /** Toggle one sloped note between this note and its adjacent successor. */
  public static function toggleGlissando(): void {
    $index = self::currentNoteIndex();
    if ($index === null) {
      throw new \DomainException('Select a note to start a glissando.');
    }
    self::$score->setGlissandoToNext($index, !(self::$score->notes[$index]['glissandoToNext'] ?? false));
    self::focusCursor();
    self::invalidateSheet();
  }

  /** Cycle the volume change to the next note through louder, softer, and level. */
  public static function cycleDynamic(): void {
    if (($range = self::selectionStateForSheet()) !== null) {
      self::applyRangeScore(RangeEdit::mark(self::$score, $range, 'dynamic'));
      return;
    }
    $index = self::currentNoteIndex();
    if ($index === null) {
      throw new \DomainException('Select a note to start a dynamic mark.');
    }
    $current = self::$score->notes[$index]['dynamicToNext'] ?? '';
    self::$score->setDynamicToNext($index, Dynamics::nextDirection($current));
    self::focusCursor();
    self::invalidateSheet();
  }

  /** Cycle the level shared by every melody at the cursor time. */
  public static function cycleDynamicLevel(): void {
    $time = self::$score->start(self::$bar) + self::$slot;
    self::$score->setScoreDynamicLevel($time, DynamicLevel::next(self::sharedDynamicLevel()));
    self::$displayedReadout = '';
    self::focusCursor();
  }

  /** Set a shared level, or a level on the selected melody's note. */
  public static function setDynamicLevel(string $level, bool $shared): void {
    if ($shared) {
      self::$score->setScoreDynamicLevel(self::$score->start(self::$bar) + self::$slot, $level);
    } else {
      $index = self::currentNoteIndex();
      if ($index === null) {
        throw new \DomainException('Select a note before setting its dynamic level.');
      }
      self::$score->setDynamicLevel($index, $level);
    }
    self::$displayedReadout = '';
    self::focusCursor();
  }

  private static function sharedDynamicLevel(): string {
    $time = self::$score->start(self::$bar) + self::$slot;
    foreach (self::$score->scoreDynamics as $mark) {
      if ($mark['time'] === $time) {
        return $mark['level'];
      }
    }
    return '';
  }

  private static function sharedWedgeDirection(): string {
    $time = self::selectionStateForSheet()['start'] ?? self::$score->start(self::$bar) + self::$slot;
    foreach (self::$score->scoreDynamics as $mark) {
      if ($mark['time'] === $time) {
        return $mark['direction'];
      }
    }
    return '';
  }

  /** Cycle one shared wedge across the selected time or to the next score note. */
  public static function cycleScoreDynamic(): void {
    $range = self::selectionStateForSheet();
    if ($range === null) {
      $index = self::currentNoteIndex();
      $next = $index === null ? null : self::$score->nextMelodyNote($index);
      if ($next === null) {
        throw new \DomainException('A shared wedge needs a note followed by another note in this melody.');
      }
      $note = self::$score->notes[$index];
      $following = self::$score->notes[$next];
      $start = self::$score->start($note['bar']) + $note['slot'];
      $end = self::$score->start($following['bar']) + $following['slot'];
    } else {
      $start = $range['start'];
      $end = $range['end'];
    }
    self::$score->setScoreDynamicWedge($start, $end, Dynamics::nextDirection(self::sharedWedgeDirection()));
    self::$displayedReadout = '';
    self::focusCursor();
  }

  /** Cycle the selected note through the four signed trill intervals. */
  public static function cycleTrill(): void {
    $index = self::currentNoteIndex();
    if ($index === null) {
      throw new \DomainException('Select a note to set its trill.');
    }
    self::$score->assertMelodyEditable(self::$melody);
    $choices = [0, 1, 2, -1, -2];
    $current = self::$score->notes[$index]['trill'] ?? 0;
    $position = array_search($current, $choices, true);
    for ($step = 1; $step <= count($choices); $step++) {
      $next = $choices[($position + $step) % count($choices)];
      try {
        self::$score->setTrill($index, $next);
        break;
      } catch (\DomainException) {
        // Skip pitches beyond the supported octave range.
      }
    }
    self::focusCursor();
    self::invalidateSheet();
  }

  /** Insert an exact duplicate after the selected note. */
  public static function duplicateNote(): void {
    $index = self::currentNoteIndex();
    if ($index === null) {
      self::status('Select a note before inserting another.');
      return;
    }
    $copy = self::$score->repeatAfter($index);
    $note = self::$score->notes[$copy];
    self::$bar = $note['bar'];
    self::$slot = $note['slot'];
    self::$octave = $note['octave'];
    self::$pitchPosition = Pitch::position($note);
    self::$cursorLength = $note['length'];
    self::$cursorLengthExplicit = false;
    self::clearSelection();
    self::focusCursor();
  }

  /** Insert a one-beat note or gap after the cursor span. */
  public static function insertInverseBeat(): void {
    [self::$bar, self::$slot, $inserted] = self::$score->insertInverseBeat(
      self::$melody, self::$bar, self::$slot, self::cursorLength(), self::$octave, self::$pitchPosition);
    self::clearSelection();
    self::$cursorLength = 2;
    self::$cursorLengthExplicit = $inserted === null;
    self::focusCursor();
  }

  /** Delete the selected note, or close the visible empty span in this melody. */
  public static function deleteAtCursor(): void {
    if (($range = self::selectionStateForSheet()) !== null) {
      $containsNotes = RangeEdit::copy(self::$score, $range)['notes'] !== [];
      self::applyRangeScore($containsNotes ? RangeEdit::clear(self::$score, $range) : RangeEdit::close(self::$score, $range),
        !$containsNotes, $containsNotes ? null : $range['start']);
      return;
    }
    $index = self::currentNoteIndex();
    if ($index !== null) {
      $before = self::$score->notes[$index];
      self::$score->delete($index);
      self::changedNote($before, null);
      self::$cursorLengthExplicit = false;
      self::focusCursor();
      return;
    }
    self::$score->resizeGapAndShift(self::$melody, self::$bar, self::$slot, self::cursorLength(), 0);
    self::clearSelection();
    self::syncNoteLength();
    self::focusCursor();
  }

  /** Delete the preceding note and its following gap in the active melody. */
  public static function deleteBeforeCursor(): void {
    $current = self::currentNoteIndex();
    $boundary = $current === null
      ? self::$score->start(self::$bar) + self::$slot
      : self::$score->start(self::$score->notes[$current]['bar']) + self::$score->notes[$current]['slot'];
    $previous = null;
    $previousStart = -1;
    foreach (self::$score->notes as $index => $note) {
      if ($note['melody'] !== self::$melody) {
        continue;
      }
      $start = self::$score->start($note['bar']) + $note['slot'];
      if ($start < $boundary && $start > $previousStart) {
        $previous = $index;
        $previousStart = $start;
      }
    }
    if ($previous === null) {
      self::status('No note before the cursor.');
      return;
    }
    $note = self::$score->notes[$previous];
    self::$score->deleteAndClose($previous);
    self::$bar = $note['bar'];
    self::$slot = $note['slot'];
    self::changedNote($note, null);
    self::$cursorLength = 1;
    self::syncNoteLength();
    self::focusCursor();
  }

  /** Return the cursor state for the whole sheet. */
  public static function cursorStateForSheet(): array {
    return self::cursorState();
  }

  /** Return the active melody's selected time span for the sheet overlay. */
  public static function selectionStateForSheet(): ?array {
    return self::$selection?->state();
  }

  /** Copy the selected notes and their relative timing inside this process. */
  public static function copySelection(): void {
    $range = self::selectionStateForSheet();
    if ($range === null) {
      self::status('Select a range to copy.');
      return;
    }
    self::$musicClipboard = RangeEdit::copy(self::$score, $range);
    self::status('Copied selection.');
  }

  /** Replace the selection or insert a clip after the current note or gap. */
  public static function pasteSelection(): void {
    if (self::$musicClipboard === null) {
      self::status('Nothing copied yet.');
      return;
    }
    $range = self::selectionStateForSheet();
    $at = self::$score->start(self::$bar) + self::$slot + self::cursorLength();
    $start = $range['start'] ?? $at;
    $duration = self::$musicClipboard['duration'];
    $next = RangeEdit::paste(self::$score, self::$musicClipboard, $range, self::$melody, $at);
    self::applyRangeScore($next, true, $start + $duration);
    self::status('Pasted selection.');
  }

  /** Move selected pitches without moving their times. */
  public static function transposeSelection(int $semitones): void {
    $range = self::selectionStateForSheet();
    if ($range === null) {
      throw new \DomainException('Select notes to transpose.');
    }
    self::applyRangeScore(RangeEdit::transpose(self::$score, $range, $semitones));
  }

  /** Extend a whole-score selection by exactly one half-beat. */
  public static function moveScoreSelection(int $direction): void {
    $from = self::$score->start(self::$bar) + self::$slot;
    $end = self::$score->start(count(self::$score->bars));
    self::moveToTime(max(0, min($end, $from + $direction)));
    self::selection()->extend($from, self::$score->start(self::$bar) + self::$slot, self::$melody, 'score');
    self::syncNoteLength();
    self::focusWithoutHistory();
  }

  /** Select every bar and melody in the score. */
  public static function selectAll(): void {
    $end = self::$score->start(count(self::$score->bars));
    self::moveToTime($end);
    self::selection()->clear();
    self::selection()->extend(0, $end, self::$melody, 'score');
    self::syncNoteLength();
    self::focusWithoutHistory();
  }

  /** Jump to the first or last cursor position in the score. */
  public static function moveToScoreEdge(bool $end): void {
    [self::$bar, self::$slot] = self::scoreEdgeDestination($end);
    self::clearSelection();
    self::syncNoteLength();
    if ($end && self::currentNoteIndex() === null) {
      self::$cursorLength = min(self::cursorLength(), 2 * self::$score->beats(self::$bar) - self::$slot);
      self::$cursorLengthExplicit = true;
    }
    self::focusWithoutHistory();
  }

  private static function scoreEdgeDestination(bool $end): array {
    $bar = $end ? count(self::$score->bars) - 1 : 0;
    return [$bar, $end ? self::lastPositionInBar($bar) : 0];
  }

  private static function moveToTime(int $time): void {
    $bar = 0;
    while ($bar + 1 < count(self::$score->bars) && $time >= self::$score->start($bar + 1)) {
      $bar++;
    }
    self::$bar = $bar;
    self::$slot = $time - self::$score->start($bar);
  }

  private static function applyRangeScore(Score $next, bool $clear = false, ?int $cursorTime = null): void {
    self::$score = $next;
    if ($clear) {
      self::clearSelection();
    }
    if ($cursorTime !== null) {
      self::moveToTime($cursorTime);
    }
    self::syncNoteLength();
    self::focusCursor(false);
    self::$history?->observe(self::$score);
  }

  public static function firstVisibleBar(): int {
    return self::$firstBar;
  }

  public static function firstVisibleRow(): int {
    return self::$firstRow;
  }

  public static function previewPage(): int {
    return self::$previewPage;
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

  /** Return the editor screen. */
  public static function screen(string $id): Screen {
    return self::$window->screen($id) ?? throw new \RuntimeException('Missing screen: ' . $id);
  }

  /** Return the visible screen identifier for file actions. */
  public static function currentScreenId(): string {
    return self::$window->currentScreenId();
  }

  /** Display a short status message without interrupting note entry. */
  public static function status(string $message, ?string $screen = null): void {
    self::screen($screen ?? self::$view)->statusBar?->notice($message, 'background', 4500);
  }

  /** Repaint the sheet after a changed note. */
  public static function changedNote(?array $before, ?array $after): void {
    self::clearSelection();
    self::invalidateSheet();
  }

  /** Move through melody positions or extend a selection. */
  public static function moveTime(int $direction, bool $select = false): void {
    $from = self::$score->start(self::$bar) + self::$slot;
    [self::$bar, self::$slot] = self::$score->moveCursor(self::$melody, self::$bar, self::$slot, $direction, $select || !self::isDesign());
    if ($select) {
      self::selection()->extend($from, self::$score->start(self::$bar) + self::$slot, self::$melody);
    } else {
      self::clearSelection();
    }
    self::syncNoteLength();
    self::focusWithoutHistory();
  }

  /** Jump to the first or last position in a visible edge bar, paging from that bar. */
  public static function moveToVisibleEdge(bool $end, bool $select = false, string $scope = 'melody'): void {
    $from = self::$score->start(self::$bar) + self::$slot;
    $previousFirstBar = self::$firstBar;
    [self::$firstBar, self::$bar, self::$slot] = self::visibleEdgeDestination($end);
    if ($select) {
      self::selection()->extend($from, self::$score->start(self::$bar) + self::$slot, self::$melody, $scope);
    } else {
      self::clearSelection();
    }
    self::syncNoteLength();
    if ($end && self::currentNoteIndex() === null) {
      self::$cursorLength = min(self::cursorLength(), 2 * self::$score->beats(self::$bar) - self::$slot);
      self::$cursorLengthExplicit = true;
    }
    if (self::$firstBar !== $previousFirstBar) {
      self::alignTimeline();
      self::invalidateSheet();
      self::$window->refreshLayout();
    }
    self::focusWithoutHistory();
  }

  /** Return the page, bar, and first or last note-or-gap position for Home or End. */
  private static function visibleEdgeDestination(bool $end): array {
    $lastBar = count(self::$score->bars) - 1;
    $first = self::$firstBar;
    $lastVisible = min($lastBar, $first + self::$columns - 1);
    if ($end) {
      if (self::$bar === $lastVisible && $lastVisible < $lastBar) {
        $first += self::$columns;
      }
      $target = min($lastBar, $first + self::$columns - 1);
    } else {
      if (self::$bar === $first) {
        $first = max(0, $first - self::$columns);
      }
      $target = $first;
    }
    if (!$end) {
      return [$first, $target, 0];
    }
    return [$first, $target, self::lastPositionInBar($target)];
  }

  /** Return the last note or trailing gap in a bar. */
  private static function lastPositionInBar(int $target): int {
    $barStart = self::$score->start($target);
    $barEndSlot = 2 * self::$score->beats($target);
    $lastNote = self::$score->noteAt(self::$melody, $target, $barEndSlot - 1);
    if ($lastNote !== null) {
      $note = self::$score->notes[$lastNote];
      return $note['bar'] === $target ? $note['slot'] : 0;
    }
    $lastEnd = $barStart;
    foreach (self::$score->notes as $note) {
      if ($note['melody'] === self::$melody) {
        $noteEnd = self::$score->start($note['bar']) + $note['slot'] + $note['length'];
        if ($noteEnd > $barStart && $noteEnd < $barStart + $barEndSlot) {
          $lastEnd = max($lastEnd, $noteEnd);
        }
      }
    }
    return $lastEnd - $barStart;
  }

  /** Move only the pitch cursor to another octave band. */
  public static function moveOctave(int $direction): void {
    $octave = max(0, min(8, self::$octave - $direction));
    self::assertPreviewBand($octave, self::$pitchPosition);
    self::$octave = $octave;
    self::focusWithoutHistory();
  }

  /** Move between existing melodies without wrapping past either end. */
  public static function moveMelody(int $direction): void {
    $next = self::$melody + $direction;
    if ($next < 0) {
      $next = count(self::$score->melodies) - 1;
    }
    if ($next >= count(self::$score->melodies)) {
      $next = 0;
    }
    self::$melody = $next;
    self::clearSelection();
    self::syncNoteLength();
    $pitch = MelodyRange::cursorPitch(self::$score, self::$melody, self::$bar, self::$slot);
    if ($pitch !== null) {
      [self::$octave, self::$pitchPosition] = $pitch;
    } else {
      $bands = MelodyRange::bands(self::$score, self::$melody);
      if (!in_array(self::$octave, $bands, true)
        && !(self::$pitchPosition === 12 && in_array(self::$octave + 1, $bands, true))) {
        self::$octave = $bands[1];
      }
    }
    self::refreshMelodyFields();
    self::focusWithoutHistory();
    self::$window->refreshLayout();
    self::status('Melody ' . (self::$melody + 1));
  }

  /** Append an instrument to the score and select it. */
  public static function addMelody(EventContext $event): void {
    if (!self::isDesign() || !self::commitEditorFields()) {
      return;
    }
    self::$melody = self::$score->addMelody();
    self::$history?->observe(self::$score);
    self::$octave = MelodyRange::bands(self::$score, self::$melody)[1];
    self::clearSelection();
    self::refreshMelodyFields();
    self::focusCursor();
    self::$window->refreshLayout();
    self::status('Added melody ' . (self::$melody + 1) . '.');
  }

  /** Switch the selected melody between audible/printed and hidden. */
  public static function toggleMelodyMute(EventContext $event): void {
    if (!self::isDesign()) {
      return;
    }
    if (self::$score->melodyMuted(self::$melody)) {
      self::refreshMelodyFields();
      $index = self::currentNoteIndex();
      self::screen('editor')->widget('finger')->setValue($index === null ? ''
        : (string)(self::$score->notes[$index]['finger'] ?? ''));
    }
    if (!self::commitEditorFields()) {
      return;
    }
    if (self::$playbackMode !== null) {
      App::audio()->stopMusic();
      self::$playbackMode = null;
    }
    $muted = !self::$score->melodyMuted(self::$melody);
    self::$score->setMelodyMuted(self::$melody, $muted);
    self::$history?->observe(self::$score);
    self::refreshMelodyFields();
    self::invalidateSheet();
    self::$previewPage = min(self::$previewPage, count(PagePainter::pages(self::$score)) - 1);
    self::$window->refreshLayout();
    self::status('Melody ' . (self::$melody + 1) . ($muted ? ' muted.' : ' unmuted.'));
  }

  /** Ask the status bar before deleting the selected melody and its notes. */
  public static function requestDeleteMelody(EventContext $event): void {
    if (!self::isDesign() || !self::commitEditorFields()) {
      return;
    }
    if (count(self::$score->melodies) === 1) {
      self::status('A score needs at least one melody.');
      return;
    }
    self::$pendingDeleteMelody = self::$melody;
    self::screen('editor')->statusBar->confirm(
      'Delete melody ' . (self::$melody + 1) . ' and all its notes?',
      self::confirmDeleteMelody(...),
      self::cancelDeleteMelody(...)
    );
  }

  /** Remove the confirmed melody and select the one now at its position. */
  private static function confirmDeleteMelody(): void {
    $deleted = self::$pendingDeleteMelody;
    self::$pendingDeleteMelody = null;
    if ($deleted === null) {
      return;
    }
    if (self::$playbackMode !== null) {
      App::audio()->stopMusic();
      self::$playbackMode = null;
    }
    self::$score->removeMelody($deleted);
    self::$melody = min($deleted, count(self::$score->melodies) - 1);
    self::clearSelection();
    self::syncNoteLength();
    $pitch = MelodyRange::cursorPitch(self::$score, self::$melody, self::$bar, self::$slot);
    if ($pitch !== null) {
      [self::$octave, self::$pitchPosition] = $pitch;
    } else {
      $bands = MelodyRange::bands(self::$score, self::$melody);
      if (!in_array(self::$octave, $bands, true)
        && !(self::$pitchPosition === 12 && in_array(self::$octave + 1, $bands, true))) {
        self::$octave = $bands[1];
      }
    }
    self::refreshMelodyFields();
    self::focusCursor();
    self::$window->refreshLayout();
    self::status('Deleted melody ' . ($deleted + 1) . '.');
  }

  /** Leave the score untouched when deletion is declined. */
  private static function cancelDeleteMelody(): void {
    self::$pendingDeleteMelody = null;
    self::status('Melody deletion cancelled.');
  }

  /** Select the first control in a settings tile; Return activates it normally. */
  public static function selectSettings(string $field): void {
    if (!self::isDesign() || !in_array($field, ['tempo', 'sound', 'staccato'], true)) {
      return;
    }
    $leaf = self::screen('editor')->layout->findTile($field);
    if ($leaf instanceof LayoutLeaf) {
      self::screen('editor')->selectLeaf($leaf);
    }
  }

  /** Run the editor's visible buttons through the same commands as its shortcuts. */
  public static function functionButton(EventContext $event): void {
    $id = $event->widget?->id();
    $actions = [
      'section' => static fn () => self::toggleSection(),
      'staccato' => static fn () => self::toggleStaccato(),
      'fermata' => static fn () => self::toggleFermata(),
      'slur' => static fn () => self::toggleSlur(),
      'glissando' => static fn () => self::toggleGlissando(),
      'dynamic' => static fn () => self::cycleScoreDynamic(),
      'dynamicLevel' => static fn () => self::cycleDynamicLevel(),
      'trill' => static fn () => self::cycleTrill(),
    ];
    $action = $actions[$id] ?? null;
    if ($action === null) {
      return;
    }
    try {
      $action();
    } catch (\DomainException $error) {
      self::status($error->getMessage());
    }
  }

  /** Switch between the editor and the PDF page preview. */
  public static function toggleView(EventContext $event): void {
    if (self::isDesign() && !self::commitEditorFields()) {
      return;
    }
    self::$view = self::isDesign() ? 'sheet' : 'editor';
    self::$firstRow = 0;
    if (!self::isDesign()) {
      foreach (PagePainter::pages(self::$score) as $index => $page) {
        if (in_array(self::$bar, array_merge(...$page), true)) {
          self::$previewPage = $index;
          break;
        }
      }
    }
    self::$sheet = self::$sheets[self::$view];
    self::$window->setCurrentScreenId(self::$view);
    if (self::isDesign()) {
      self::refreshEditorFields();
    } else {
      self::showPreviewHelp();
    }
    self::focusCursor();
    self::$window->refreshLayout();
  }

  /** Turn PDF preview pages. */
  public static function scrollRows(int $direction): void {
    if (self::isDesign()) {
      return;
    }
    $page = max(0, min(count(PagePainter::pages(self::$score)) - 1, self::$previewPage + $direction));
    if ($page !== self::$previewPage) {
      self::$previewPage = $page;
      self::invalidateSheet();
    }
  }

  public static function previousPreviewPage(EventContext $event): void {
    self::scrollRows(-1);
  }

  public static function nextPreviewPage(EventContext $event): void {
    self::scrollRows(1);
  }

  public static function firstPreviewPage(EventContext $event): void {
    self::previewEdge(false);
  }

  public static function lastPreviewPage(EventContext $event): void {
    self::previewEdge(true);
  }

  /** Jump to the first or last PDF preview page. */
  public static function previewEdge(bool $last): void {
    if (self::isDesign()) {
      return;
    }
    $page = $last ? count(PagePainter::pages(self::$score)) - 1 : 0;
    if ($page !== self::$previewPage) {
      self::$previewPage = $page;
      self::invalidateSheet();
    }
  }

  /** Keep preview navigation visible in its only status bar. */
  private static function showPreviewHelp(): void {
    self::screen('sheet')->statusBar?->display(
      'V/Esc/Return: editor   B/N: page   F/L: first/last   Arrows/PgUp/PgDown: scroll   +/-: zoom   Space: fit', 'hint', 'continuous');
  }

  /** Return from tile navigation to the score editor with Escape. */
  public static function activateSheetEditor(EventContext $event): bool {
    if (!self::isDesign() || self::screen('editor')->activeLeaf() !== null) {
      return false;
    }
    self::screen('editor')->activateLeaf(self::$sheets['editor']);
    return true;
  }

  /** Show the score editing screen. */
  public static function showEditor(EventContext $event): void {
    self::$window->setCurrentScreenId(self::$view);
    self::focusCursor();
  }

  /** Open the application settings after applying edits in the score fields. */
  public static function openSettings(EventContext $event): bool {
    if (self::commitEditorFields()) {
      self::$window->setCurrentScreenId('settings');
    }
    return true;
  }

  /** Return from settings to the score editor. */
  public static function closeSettings(EventContext $event): bool {
    self::$window->setCurrentScreenId('editor');
    return true;
  }

  /** End the app through its normal close event and session save. */
  public static function quit(EventContext $event): void {
    App::eventLoop()->stop();
  }

  /** Toggle playback of the score or active melody from the selection or cursor. */
  public static function togglePlayback(bool $selectedMelody): void {
    $mode = $selectedMelody ? 'melody' : 'score';
    $cache = null;
    $return = null;
    try {
      $audio = App::audio();
      if (self::$playbackMode === $mode && $audio->playingMusic()) {
        self::stopPlayback('Playback stopped.');
        return;
      }
      if (self::$playbackMode !== null) {
        self::stopPlayback(null);
      }
      if ($selectedMelody && self::$score->melodyMuted(self::$melody)) {
        self::status('Melody ' . (self::$melody + 1) . ' is muted. Unmute it to play.');
        return;
      }
      $selection = self::selectionStateForSheet();
      $melody = $selectedMelody ? self::$melody : null;
      $start = $selection['start'] ?? self::$score->start(self::$bar) + self::$slot;
      $end = $selection['end'] ?? null;
      if (self::isDesign() && !self::commitEditorFields()) {
        return;
      }
      $timeline = new PlaybackTimeline(self::$score, $start, $end);
      $firstBeat = $timeline->beats[0];
      $firstPage = intdiv($firstBeat['bar'], self::$columns);
      $return = ['view' => self::$view, 'firstBar' => self::$firstBar];
      if (!self::isDesign()) {
        self::$view = 'editor';
        self::$sheet = self::$sheets['editor'];
        self::$window->setCurrentScreenId('editor');
      }
      self::$firstBar = $firstPage * self::$columns;
      self::$window->refreshLayout();
      [$width, $height] = self::$sheets['editor']->instance()->surfaceSize();
      if ($width < 1 || $height < 1) {
        throw new \RuntimeException('The score sheet has no drawable area.');
      }
      $cache = new SheetCache(self::$score->visibleCopy(), self::$columns, $width, $height,
        self::cursorStateForSheet(), self::$melody, $firstPage);
      $midiFailure = self::$playbackSound === 'midi' ? MidiPlayback::unavailableReason() : null;
      $midiPlayed = false;
      if (self::$playbackSound === 'midi' && $midiFailure === null) {
        try {
          $wave = MidiPlayback::render(self::$score, $melody, $start, $end);
          try {
            $audio->playMusic($wave);
          } finally {
            unlink($wave);
          }
          $midiPlayed = true;
        } catch (\Throwable $error) {
          $midiFailure = $error->getMessage();
        }
      }
      if ($midiPlayed) {
        self::beginPlayback($mode, $timeline, $cache, $return, $firstBeat, $timeline->durationSeconds);
        self::status('Playing ' . ($selectedMelody ? 'melody' : 'score')
          . ($selection === null ? '' : ' selection') . ' with MIDI.');
        return;
      }
      $source = new ScoreSource(self::$score, $melody, $start, $end);
      $audio->playMusic($source);
      self::beginPlayback($mode, $timeline, $cache, $return, $firstBeat, $source->durationSeconds());
      self::status('Playing ' . ($selectedMelody ? 'melody' : 'score')
        . ($selection === null ? '' : ' selection') . ' with built-in sound.'
        . ($midiFailure === null ? '' : ' MIDI: ' . $midiFailure));
    } catch (\Throwable $error) {
      if (self::$playbackFrame !== null) {
        self::stopPlayback(null);
      } else {
        $cache?->close();
        if (isset($audio)) {
          $audio->stopMusic();
        }
        if ($return !== null) {
          self::$firstBar = $return['firstBar'];
          self::$view = $return['view'];
          self::$sheet = self::$sheets[self::$view];
          self::$window->setCurrentScreenId(self::$view);
          self::invalidateSheet();
        }
      }
      self::status('Playback failed: ' . $error->getMessage());
    }
  }

  /** Expose the current prepared page and beat to the retained canvas. */
  public static function playbackFrame(): ?array {
    return self::$playbackFrame;
  }

  /** Install the playback input guard and show the first beat. */
  private static function beginPlayback(string $mode, PlaybackTimeline $timeline, SheetCache $cache,
    array $return, array $firstBeat, float $endSeconds): void {
    self::clearPlaybackInputDrain(false);
    self::$playbackMode = $mode;
    self::$playbackTimeline = $timeline;
    self::$playbackCache = $cache;
    self::$playbackReturn = $return;
    self::$playbackBeatIndex = 0;
    self::$playbackEndSeconds = $endSeconds;
    self::showPlaybackBeat($firstBeat);
    self::screen('editor')->setInputGuard(self::playbackInput(...));
    self::invalidateSheet();
    self::$playbackTimer = App::eventLoop()->after(25, self::playbackTick(...));
  }

  /** Move only the beat overlay, changing the page texture when needed. */
  private static function showPlaybackBeat(array $beat): void {
    $cache = self::$playbackCache;
    [$width, $height] = self::$sheets['editor']->instance()->surfaceSize();
    $page = intdiv($beat['bar'], self::$columns);
    self::$firstBar = $page * self::$columns;
    self::$playbackFrame = ['cache' => $cache, 'page' => $page, 'width' => $width,
      'height' => $height, 'rect' => SheetPainter::beatRect(self::$score, self::$firstBar,
        self::$columns, $width, $height, $beat['bar'], $beat['slot'])];
  }

  /** Follow the sound player's consumed position, allowing small output latency. */
  private static function playbackTick(): void {
    self::$playbackTimer = null;
    if (self::$playbackFrame === null) {
      return;
    }
    try {
      $position = App::audio()->musicPositionSeconds();
      if ($position === null) {
        self::stopPlayback('Playback finished.');
        return;
      }
      if ($position >= self::$playbackEndSeconds) {
        self::stopPlayback('Playback finished.');
        return;
      }
      $beatIndex = self::$playbackTimeline->beatAt($position);
      if ($beatIndex === self::$playbackBeatIndex) {
        self::$playbackTimer = App::eventLoop()->after(25, self::playbackTick(...));
        return;
      }
      $beat = self::$playbackTimeline->beats[$beatIndex];
      $page = intdiv($beat['bar'], self::$columns);
      if ($page !== self::$playbackFrame['page'] && self::$playbackCache->page($page) === null) {
        if (($failure = self::$playbackCache->failure()) !== null) {
          self::stopPlayback('Playback stopped: ' . $failure);
          return;
        }
      } else {
        $before = self::$playbackFrame;
        self::showPlaybackBeat($beat);
        self::$playbackBeatIndex = $beatIndex;
        if ($before['page'] !== self::$playbackFrame['page'] || $before['rect'] !== self::$playbackFrame['rect']) {
          self::invalidateSheet();
        }
      }
    } catch (\Throwable $error) {
      self::stopPlayback('Playback stopped: ' . $error->getMessage());
      return;
    }
    self::$playbackTimer = App::eventLoop()->after(25, self::playbackTick(...));
  }

  /** Consume editor input while playing, except stop and sound-mode switches. */
  private static function playbackInput(mixed $event): bool {
    if ($event->type === SDL::SDL_EVENT_KEY_UP) {
      unset(self::$playbackHeldKeys[(int)$event->key->key]);
      return true;
    }
    if ($event->type !== SDL::SDL_EVENT_KEY_DOWN) {
      return true;
    }
    self::$playbackHeldKeys[(int)$event->key->key] = true;
    if ($event->key->repeat) {
      return true;
    }
    $key = KeyNormalizer::normalize((int)$event->key->key, (int)$event->key->mod);
    $modifiers = KeyNormalizer::normalizeModifiers((int)$event->key->mod);
    if ($modifiers === 0 && $key === SDL::KEY_ESCAPE) {
      self::stopPlayback('Playback stopped.');
    } else if ($modifiers === 0 && in_array(($name = KeyNormalizer::keyName($key, 0)), ['p', 'm'], true)) {
      self::togglePlayback($name === 'm');
    }
    return true;
  }

  /** Restore the editor state and release prepared playback pages. */
  private static function stopPlayback(?string $message): void {
    if (self::$playbackFrame === null) {
      return;
    }
    App::audio()->stopMusic();
    if (self::$playbackTimer !== null) {
      App::eventLoop()->removeTimer(self::$playbackTimer);
      self::$playbackTimer = null;
    }
    self::screen('editor')->setInputGuard(null);
    self::$sheets['editor']->instance()->resetPlayback();
    self::$playbackFrame = null;
    self::$playbackTimeline = null;
    self::$playbackBeatIndex = -1;
    self::$playbackEndSeconds = 0.0;
    self::$playbackMode = null;
    self::$playbackCache?->close();
    self::$playbackCache = null;
    self::$firstBar = self::$playbackReturn['firstBar'];
    $view = self::$playbackReturn['view'];
    self::$playbackReturn = null;
    self::$view = $view;
    self::$sheet = self::$sheets[$view];
    self::$window->setCurrentScreenId($view);
    self::drainPlaybackInput($view);
    self::invalidateSheet();
    if ($message !== null) {
      self::status($message);
    }
  }

  /** Consume input already queued at the stop boundary and held-key repeats. */
  private static function drainPlaybackInput(string $view): void {
    self::clearPlaybackInputDrain(false);
    self::$playbackDrainView = $view;
    self::$playbackDrainBatch = true;
    self::screen($view)->setInputGuard(self::playbackDrainGuard(...));
    self::$playbackDrainTimer = App::eventLoop()->after(1, self::finishPlaybackInputDrain(...));
  }

  /** Release a held playback key without forwarding its repeat or text. */
  private static function playbackDrainGuard(mixed $event): bool {
    if ($event->type === SDL::SDL_EVENT_KEY_DOWN) {
      $key = (int)$event->key->key;
      if (self::$playbackDrainBatch) {
        self::$playbackHeldKeys[$key] = true;
        return true;
      }
      if (isset(self::$playbackHeldKeys[$key])) {
        self::$playbackSuppressText = true;
        return true;
      }
      self::$playbackSuppressText = false;
      return false;
    }
    if ($event->type === SDL::SDL_EVENT_KEY_UP && isset(self::$playbackHeldKeys[(int)$event->key->key])) {
      unset(self::$playbackHeldKeys[(int)$event->key->key]);
      if (self::$playbackHeldKeys === [] && !self::$playbackDrainBatch) {
        self::drainPlaybackInput(self::$playbackDrainView);
      }
      return true;
    }
    if ($event->type === SDL::SDL_EVENT_TEXT_INPUT && self::$playbackSuppressText) {
      self::$playbackSuppressText = false;
      return true;
    }
    return self::$playbackDrainBatch;
  }

  /** Let fresh editor input through after SDL's current event batch is drained. */
  private static function finishPlaybackInputDrain(): void {
    self::$playbackDrainTimer = null;
    self::$playbackDrainBatch = false;
    self::$playbackSuppressText = false;
    if (self::$playbackHeldKeys === []) {
      self::clearPlaybackInputDrain();
    }
  }

  /** Remove an old drain guard before another playback starts. */
  private static function clearPlaybackInputDrain(bool $clearHeld = true): void {
    if (self::$playbackDrainTimer !== null) {
      App::eventLoop()->removeTimer(self::$playbackDrainTimer);
      self::$playbackDrainTimer = null;
    }
    if (self::$playbackDrainView !== null) {
      self::screen(self::$playbackDrainView)->setInputGuard(null);
      self::$playbackDrainView = null;
    }
    self::$playbackDrainBatch = false;
    if ($clearHeld) {
      self::$playbackHeldKeys = [];
    }
  }

  /** Let the user compare the built-in synthesis with MIDI SoundFont playback. */
  public static function togglePlaybackSound(EventContext $event): void {
    if (self::$playbackMode !== null) {
      self::stopPlayback(null);
    }
    self::$playbackSound = self::$playbackSound === 'midi' ? 'built-in' : 'midi';
    self::screen('settings')->widget('playbackSound')->setLabel('Playback: '
      . (self::$playbackSound === 'midi' ? 'MIDI' : 'Built-in'));
    self::$window->refreshLayout();
    self::status('Playback sound: ' . (self::$playbackSound === 'midi' ? 'MIDI' : 'built-in') . '.', 'settings');
  }

  /** Start or stop all melodies from an editor screen shortcut. */
  public static function toggleScorePlayback(EventContext $event): bool {
    if (!$event->input->key->repeat) {
      self::togglePlayback(false);
    }
    return true;
  }

  /** Start or stop the active melody from an editor screen shortcut. */
  public static function toggleMelodyPlayback(EventContext $event): bool {
    if (!$event->input->key->repeat) {
      self::togglePlayback(true);
    }
    return true;
  }

  /** Commit one editor field when its input is released. */
  public static function editorFieldAccepted(EventContext $event): void {
    $field = $event->widget?->id();
    try {
      if ($field === 'beats') {
        self::applyEditorBeats();
      } else if ($field === 'tempo') {
        self::applyEditorTempo();
      } else if ($field === 'barNote') {
        self::applyEditorBarNote();
      } else if ($field === 'repeats') {
        self::applyEditorRepeats();
      } else if ($field === 'volume') {
        self::applyMelodyVolume();
      } else if ($field === 'color') {
        self::applyMelodyColor();
      } else if ($field === 'finger') {
        self::applyFinger();
      } else if (in_array($field, ['title', 'author', 'catalog'], true)) {
        self::$score->$field = str_replace("\t", ' ', $event->widget->getValue());
      }
      self::$history?->observe(self::$score);
    } catch (\Throwable $error) {
      if ($field === 'volume' || $field === 'color') {
        self::refreshMelodyFields();
      } else if ($field === 'finger') {
        $index = self::currentNoteIndex();
        self::screen('editor')->widget('finger')->setValue($index === null
          ? '' : (string)(self::$score->notes[$index]['finger'] ?? ''));
      }
      self::status($error->getMessage());
    }
  }

  /** Show the sound choices in place of the melody settings tile. */
  public static function showSoundPicker(EventContext $event): void {
    if (self::$score->melodyMuted(self::$melody)) {
      self::status('This melody is muted and read-only. Unmute it to edit.');
      return;
    }
    $screen = self::screen('editor');
    self::prepareSoundPicker();
    $screen->widget('soundPicker')->setValue(self::$score->melodies[self::$melody]['instrument']);
    if ($screen->show('soundPicker')) {
      self::$window->refreshLayout();
    }
  }

  /** Save the chosen instrument for the active melody. */
  public static function melodyPatternChanged(EventContext $event): void {
    $instrument = $event->widget?->getValue();
    if (is_string($instrument)) {
      try {
        self::$score->setMelodyInstrument(self::$melody, $instrument);
        self::$history?->observe(self::$score, self::$melody);
      } catch (\DomainException $error) {
        self::status($error->getMessage());
      }
      self::refreshMelodyFields();
    }
  }

  /** Show one file form over the Note tile. */
  public static function showFilePanel(string $id): void {
    $screen = self::screen('editor');
    $other = $id === 'savePanel' ? 'loadPanel' : 'savePanel';
    $screen->restore($other);
    if ($screen->show($id)) {
      self::$window->refreshLayout();
    }
  }

  public static function hideFilePanel(string $id): void {
    if (self::screen('editor')->restore($id)) {
      self::$window->refreshLayout();
    }
  }

  /** Start or join a section at the selected bar. */
  public static function toggleSection(): void {
    $bar = self::$bar;
    if ($bar === 0) {
      self::status('The first bar always starts a section.');
      return;
    }
    $leader = !self::$score->bars[$bar]['sectionLeader'];
    self::$score->setSectionLeader($bar, $leader);
    self::$history?->observe(self::$score);
    self::refreshSelectedBarFields();
    self::refreshCursorReadout();
    self::invalidateSheet();
    self::$window->refreshLayout();
    self::status($leader ? 'Section starts at bar ' . ($bar + 1) . '.' : 'Bar ' . ($bar + 1) . ' joins the previous section.');
  }

  /** Ctrl+S always chooses a directory and file name. */
  public static function saveDialog(EventContext $event): void {
    if (self::commitEditorFields()) {
      Files::showSave();
    }
  }

  /** Ctrl+W writes to the current file. */
  public static function write(EventContext $event): void {
    if (self::commitEditorFields()) {
      Files::write($event);
    }
  }

  /** Ctrl+O browses for a score. */
  public static function loadDialog(EventContext $event): void {
    if (self::commitEditorFields()) {
      Files::showLoad();
    }
  }

  /** Export the score as a paginated PDF. */
  public static function exportPdf(EventContext $event): void {
    if (!self::isDesign() || self::commitEditorFields()) {
      Files::exportPdf($event);
    }
  }

  /** Export the score as standard MIDI. */
  public static function exportMidi(EventContext $event): void {
    if (!self::isDesign() || self::commitEditorFields()) {
      Files::exportMidi($event);
    }
  }

  /** Install one retained canvas in each screen's board. */
  private static function installSheet(string $view): void {
    $screen = self::screen($view);
    $oldBoard = $screen->layout->findNode('board');
    if ($oldBoard === null) {
      throw new \LogicException('The score board is missing from the ' . $view . ' screen.');
    }
    $board = new LayoutNode('horizontal', $oldBoard->width(), $oldBoard->height(), id: 'board');
    if ($view === 'editor') {
      $widget = new SheetCanvas();
      $widget->setTips('Score editor.', 'Return adds or changes a note; Space adds a note or follows its pitch.');
    } else {
      $widget = new Image(APP_DIR . '/Assets/preview-blank.png', bg: (new Style())->background,
        fit: 'contain', padding: true);
      $widget->setTips('PDF preview.', 'Arrows and Page Up/Down scroll; + and - zoom; Space fits; B/N turn pages; F/L jump to first/last page; V returns to editor.');
    }
    $widget->setId('sheet');
    $sheet = new LayoutLeaf($view === 'editor' ? 'Canvas' : 'Image', '1*', '1*', $widget,
      paintBackground: $view !== 'editor');
    $board->addLeaf($sheet);
    if (!$screen->layout->replaceChild($oldBoard, $board)) {
      throw new \LogicException('The score board could not be installed on the ' . $view . ' screen.');
    }
    $screen->setLayout($screen->layout);
    self::$sheets[$view] = $sheet;
  }

  /** Keep the cursor visible and the sheet active. */
  private static function focusCursor(bool $record = true): void {
    if ($record) {
      self::$history?->observe(self::$score, self::$melody);
    }
    self::syncTimelineBars();
    self::$firstRow = 0;
    $barChanged = self::$displayedBar !== self::$bar;
    if ($barChanged) {
      self::refreshSelectedBarFields();
    }
    $readoutChanged = self::refreshCursorReadout();
    $previousFirstBar = self::$firstBar;
    self::alignTimeline();
    self::screen(self::$view)->activateLeaf(self::$sheet);
    self::invalidateSheet();
    if (self::$firstBar !== $previousFirstBar || $barChanged || $readoutChanged) {
      self::$window->refreshLayout();
    }
  }

  private static function focusWithoutHistory(): void {
    self::focusCursor(false);
    self::$history?->rebase(self::$score);
  }

  /** Grow or trim trailing bars according to the cursor's final occupied position. */
  private static function syncTimelineBars(): void {
    if (!self::isDesign() || self::selectionStateForSheet() !== null) {
      return;
    }
    $endBar = self::cursorEndBar();
    self::$score->ensureBar($endBar);
    for ($bar = self::$lastCursorEndBar; $bar > $endBar; $bar--) {
      if (!self::$score->removeEmptyLastBar($bar)) {
        break;
      }
    }
    self::$lastCursorEndBar = $endBar;
  }

  /** Shift the visible bars when the cursor's end leaves the sheet. */
  private static function alignTimeline(): void {
    $endBar = self::selectionStateForSheet() === null ? self::cursorEndBar() : self::$bar;
    if ($endBar < self::$firstBar) {
      self::$firstBar = $endBar;
    } else if ($endBar >= self::$firstBar + self::$columns) {
      self::$firstBar = $endBar - self::$columns + 1;
    }
  }

  /** Find the bar containing the last half-beat of the cursor. */
  private static function cursorEndBar(): int {
    $end = self::cursorState()['end'];
    $bar = self::$bar;
    while ($end > self::$score->start($bar + 1)) {
      $bar++;
    }
    return $bar;
  }

  /** Return the pitch and duration preview at the selected time. */
  private static function cursorState(): array {
    $index = self::currentNoteIndex();
    $start = $index === null ? self::$score->start(self::$bar) + self::$slot : self::$score->start(self::$score->notes[$index]['bar']) + self::$score->notes[$index]['slot'];
    return ['start' => $start, 'end' => $start + self::cursorLength(), 'bar' => self::$bar, 'octave' => self::$octave, 'pitch' => self::$pitchPosition];
  }

  /** Return the selected melody's note beneath the time cursor. */
  private static function currentNoteIndex(): ?int {
    return self::$score->noteAt(self::$melody, self::$bar, self::$slot);
  }

  private static function selection(): MelodySelection {
    return self::$selection ??= new MelodySelection();
  }

  private static function clearSelection(): void {
    self::$selection?->clear();
  }

  /** Keep an edited spanning note selected at its true onset. */
  private static function selectNoteOnset(int $index): void {
    self::$bar = self::$score->notes[$index]['bar'];
    self::$slot = self::$score->notes[$index]['slot'];
  }

  /** Load a selected note's width without moving the pitch cursor. */
  private static function syncNoteLength(): void {
    self::$cursorLengthExplicit = false;
    $index = self::currentNoteIndex();
    if ($index === null) {
      return;
    }
    $note = self::$score->notes[$index];
    self::$cursorLength = $note['length'];
  }

  /** Keep an unplaced cursor inside this melody's four octave range. */
  private static function assertPreviewBand(int $octave, int $position): void {
    MelodyRange::bands(self::$score, self::$melody, $octave, $position);
  }

  /** Mark the retained sheet surface for repainting on the next frame. */
  private static function invalidateSheet(): void {
    if (self::isDesign()) {
      self::$sheet->instance()->invalidate();
    } else {
      self::refreshPreviewImage();
    }
  }

  /** Replace the viewer source with a crisp raster of the selected PDF page. */
  private static function refreshPreviewImage(): void {
    $path = tempnam(sys_get_temp_dir(), 'madscore-preview-');
    if ($path === false) {
      throw new \RuntimeException('Could not create a PDF preview image.');
    }
    try {
      PagePreview::writePng(self::$score, self::$previewPage, $path,
        (int)round(PagePainter::A4_WIDTH_POINTS * 2), (int)round(PagePainter::A4_HEIGHT_POINTS * 2));
      self::$sheets['sheet']->instance()->setSource($path);
    } catch (\Throwable $error) {
      @unlink($path);
      throw $error;
    }
    if (self::$previewImagePath !== null) {
      @unlink(self::$previewImagePath);
    }
    self::$previewImagePath = $path;
  }

  /** Choose a bar count that leaves room for its beat positions. */
  private static function fitColumns(): int {
    $beats = self::$score->bars[self::$bar]['beats'];
    $minimum = max(240, $beats * 38);
    return max(1, min(12, intdiv(max(1, self::$window->pixelWidth() - 80), $minimum)));
  }

  /** Populate the fields above the sheet from the loaded score. */
  private static function refreshEditorFields(): void {
    $screen = self::screen('editor');
    self::screen('settings')->widget('playbackSound')->setLabel('Playback: '
      . (self::$playbackSound === 'midi' ? 'MIDI' : 'Built-in'));
    foreach (['title', 'author', 'catalog'] as $field) {
      $screen->widget($field)->setValue(self::$score->$field);
    }
    self::refreshSelectedBarFields();
    self::refreshMelodyFields();
    self::refreshCursorReadout();
  }

  /** Show the settings of the bar under the score cursor. */
  private static function refreshSelectedBarFields(): void {
    $screen = self::screen('editor');
    $screen->widget('barSettingsTitle')->setText('[R] Bar ' . (self::$bar + 1));
    $screen->widget('tempo')->setValue(self::$score->bars[self::$bar]['tempo']);
    $screen->widget('beats')->setValue((string)self::$score->beats(self::$bar));
    $screen->widget('barNote')->setValue(self::$score->bars[self::$bar]['note']);
    $screen->widget('repeats')->setValue((string)self::$score->sectionRepeats(self::$bar));
    $screen->widget('section')->setLabel('Section start: ' . (self::$score->bars[self::$bar]['sectionLeader'] ? 'Yes' : 'No'));
    self::$displayedBar = self::$bar;
  }

  /** Show the active melody's saved sound and display settings. */
  private static function refreshMelodyFields(): void {
    $screen = self::screen('editor');
    $settings = self::$score->melodies[self::$melody];
    $screen->widget('selectedMelody')->setText('[Y] Melody ' . (self::$melody + 1)
      . (self::$score->melodyMuted(self::$melody) ? ' (muted)' : ''));
    self::prepareSoundPicker();
    $screen->widget('soundPicker')->setValue($settings['instrument']);
    $screen->widget('sound')->setValue(Sound::label($settings['instrument']));
    $screen->widget('volume')->setValue((string)$settings['volume']);
    $screen->widget('color')->setValue($settings['color']);
    $screen->widget('muteMelody')->setLabel('Mute: ' . (self::$score->melodyMuted(self::$melody) ? 'Yes' : 'No'));
  }

  private static function prepareSoundPicker(): void {
    $picker = self::screen('editor')->widget('soundPicker');
    if (count($picker->items()) !== 175) {
      $picker->setItems(Sound::items());
    }
  }

  /** Put note and cursor pitches in their headings, with cursor length below. */
  private static function refreshCursorReadout(): bool {
    $index = self::currentNoteIndex();
    [$letter, $alter] = Pitch::spelling(self::$pitchPosition);
    $cursorTitle = 'Cursor ' . $letter . ($alter === 1 ? '#' : '') . self::$octave;
    $title = '[T] Gap';
    if ($index !== null) {
      $note = self::$score->notes[$index];
      $title = '[T] Note ' . $note['pitch'] . ($note['alter'] === 1 ? '#' : ($note['alter'] === -1 ? 'b' : '')) . $note['octave'];
    }
    $length = self::cursorLength();
    $beats = intdiv($length, 2) . ($length % 2 ? '.5' : '');
    $lengthText = 'Length ' . $beats . ($length === 2 ? ' beat' : ' beats');
    $sharedLevel = self::sharedDynamicLevel();
    $sharedWedge = self::sharedWedgeDirection();
    $readout = $title . "\n" . $cursorTitle . "\n" . $lengthText . "\n" . $sharedLevel . "\n" . $sharedWedge . "\n" . ($index === null ? '' : json_encode([
      $note['articulation'] ?? 'ordinary', $note['slurToNext'] ?? false, $note['glissandoToNext'] ?? false,
      $note['fermata'] ?? false, $note['trill'] ?? 0, $note['finger'] ?? '', $note['dynamicToNext'] ?? '',
      $note['dynamicLevel'] ?? '',
    ]));
    if ($readout === self::$displayedReadout) {
      return false;
    }
    self::screen('editor')->widget('noteTitle')->setText($title);
    self::screen('editor')->widget('cursorTitle')->setText($cursorTitle);
    self::screen('editor')->widget('cursorLength')->setText($lengthText);
    self::screen('editor')->widget('staccato')->setLabel($index === null ? 'Staccato: —'
      : 'Staccato: ' . (($note['articulation'] ?? 'ordinary') === 'staccato' ? 'Yes' : 'No'));
    self::screen('editor')->widget('fermata')->setLabel($index === null ? 'Fermata: —'
      : 'Fermata: ' . ($note['fermata'] ? 'Yes' : 'No'));
    self::screen('editor')->widget('slur')->setLabel($index === null ? 'Link to next: —'
      : 'Link to next: ' . (($note['slurToNext'] ?? false) ? 'Yes' : 'No'));
    self::screen('editor')->widget('glissando')->setLabel($index === null ? 'Glissando: —'
      : 'Glissando: ' . (($note['glissandoToNext'] ?? false) ? 'Yes' : 'No'));
    self::screen('editor')->widget('dynamic')->setLabel('All wedge: ' . match ($sharedWedge) {
        'crescendo' => '+',
        'decrescendo' => '-',
        default => '=',
      });
    self::screen('editor')->widget('trill')->setLabel($index === null ? 'Trill: —'
      : 'Trill: ' . (($note['trill'] ?? 0) === 0 ? 'Off' : (($note['trill'] > 0 ? '+' : '') . $note['trill'])));
    self::screen('editor')->widget('finger')->setValue($index === null ? '' : (string)($note['finger'] ?? ''));
    self::screen('editor')->widget('dynamicLevel')->setLabel('All levels: ' . ($sharedLevel === '' ? '—' : $sharedLevel));
    self::$displayedReadout = $readout;
    return true;
  }

  /** Apply the visible fields before a file or bar action. */
  private static function commitEditorFields(): bool {
    try {
      self::applyEditorBeats();
      self::applyEditorTempo();
      self::applyEditorBarNote();
      self::applyEditorRepeats();
      self::applyMelodyVolume();
      self::applyMelodyColor();
      self::applyFinger();
      $screen = self::screen('editor');
      foreach (['title', 'author', 'catalog'] as $field) {
        self::$score->$field = str_replace("\t", ' ', $screen->widget($field)->getValue());
      }
      self::$history?->observe(self::$score);
      return true;
    } catch (\Throwable $error) {
      if (self::$score->melodyMuted(self::$melody)) {
        self::refreshMelodyFields();
        $index = self::currentNoteIndex();
        self::screen('editor')->widget('finger')->setValue($index === null ? ''
          : (string)(self::$score->notes[$index]['finger'] ?? ''));
      }
      self::status($error->getMessage());
      return false;
    }
  }

  /** Apply the current bar's tempo annotation. */
  private static function applyEditorTempo(): void {
    $tempo = str_replace("\t", ' ', self::screen('editor')->widget('tempo')->getValue());
    self::$score->setTempo(self::$bar, $tempo);
  }

  /** Apply the optional fingering in the selected note tile. */
  private static function applyFinger(): void {
    $raw = strtoupper(trim(self::screen('editor')->widget('finger')->getValue()));
    $index = self::currentNoteIndex();
    $saved = $index === null ? '' : (string)(self::$score->notes[$index]['finger'] ?? '');
    if ($raw !== '' && preg_match('/^[LR][1-5]$/D', $raw) !== 1 && $raw !== $saved) {
      throw new \DomainException('Finger must be L1–L5, R1–R5, or blank.');
    }
    if ($index === null) {
      if ($raw !== '') {
        throw new \DomainException('Select a note before setting its finger.');
      }
      return;
    }
    if ($raw !== $saved) {
      self::$score->setFinger($index, $raw);
      self::$history?->observe(self::$score, self::$melody);
    }
    self::screen('editor')->widget('finger')->setValue($raw);
    self::refreshCursorReadout();
    self::invalidateSheet();
  }

  /** Store the current bar's print-only annotation. */
  private static function applyEditorBarNote(): void {
    $note = str_replace("\t", ' ', self::screen('editor')->widget('barNote')->getValue());
    self::$score->setBarNote(self::$bar, $note);
  }

  /** Store the number of extra plays for the selected section. */
  private static function applyEditorRepeats(): void {
    $raw = trim(self::screen('editor')->widget('repeats')->getValue());
    if (!ctype_digit($raw) || (int)$raw > 8) {
      throw new \DomainException('Section repeats must be a whole number from 0 to 8.');
    }
    self::$score->setSectionRepeats(self::$bar, (int)$raw);
    self::invalidateSheet();
  }

  /** Validate and store a whole-number melody volume percentage. */
  private static function applyMelodyVolume(): void {
    $raw = trim(self::screen('editor')->widget('volume')->getValue());
    if (!ctype_digit($raw) || (int)$raw > 100) {
      throw new \DomainException('Melody volume must be a whole number from 0 to 100.');
    }
    if ((int)$raw === self::$score->melodies[self::$melody]['volume']) {
      return;
    }
    self::$score->setMelodyVolume(self::$melody, (int)$raw);
    self::$history?->observe(self::$score, self::$melody);
  }

  /** Validate and apply the active melody's hex display color. */
  private static function applyMelodyColor(): void {
    $color = trim(self::screen('editor')->widget('color')->getValue());
    if (strtolower($color) === self::$score->melodies[self::$melody]['color']) {
      return;
    }
    self::$score->setMelodyColor(self::$melody, $color);
    self::$history?->observe(self::$score, self::$melody);
    self::screen('editor')->widget('color')->setValue(self::$score->melodies[self::$melody]['color']);
    self::invalidateSheet();
  }

  /** Validate and apply the selected bar's notes-per-bar field. */
  private static function applyEditorBeats(): void {
    $raw = trim(self::screen('editor')->widget('beats')->getValue());
    if (!ctype_digit($raw) || (int)$raw < 1 || (int)$raw > 32) {
      throw new \DomainException('Notes per bar must be a whole number from 1 to 32.');
    }
    $beats = (int)$raw;
    if ($beats === self::$score->beats(self::$bar)) {
      return;
    }
    self::$score->setBeats(self::$bar, $beats);
    self::$slot = min(self::$slot, 2 * $beats - 1);
    if (self::fitColumns() !== self::$columns) {
      self::$columns = self::fitColumns();
      self::$window->refreshLayout();
    }
    self::focusCursor();
  }
}
