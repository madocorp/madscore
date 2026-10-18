<?php

namespace MADSCORE\App;

use MADSCORE\Play\ScoreSource;
use MADSCORE\Render\PagePainter;
use MADSCORE\Score\Dynamics;
use MADSCORE\Score\MelodyRange;
use MADSCORE\Score\Pitch;
use MADSCORE\Score\Score;

use SPTK\App;
use SPTK\Core\{Screen, Window};
use SPTK\Events\EventContext;
use SPTK\Layout\{LayoutLeaf, LayoutNode};

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
  private static ?int $pendingDeleteMelody = null;
  private static ?MelodyHistory $history = null;

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
    self::$history = new MelodyHistory(self::$score);
    self::$view = 'editor';
    self::$columns = self::fitColumns();
    self::alignTimeline();
    self::installSheet('editor');
    self::installSheet('sheet');
    self::$sheet = self::$sheets[self::$view];
    self::screen('editor')->activateLeaf(self::$sheet);
    self::screen('editor')->widget('color')->setPalette(Score::COLORS);
    Files::initialize();
    self::refreshEditorFields();
    self::$window->refreshLayout();
    if ($restoreWarning !== null) {
      self::status($restoreWarning);
    }
  }

  /** Store the current score and editor position when the app closes. */
  public static function persistSession(EventContext $event): void {
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
      'selection' => self::selectionStateForSheet(),
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
      || !in_array($view, ['editor', 'sheet'], true)) {
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
      if (($savedSelection['melody'] ?? null) === $melody) {
        $selection->extend($savedSelection['anchor'], $savedSelection['caret'], $melody);
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
    self::$selection = $selection;
    self::$lastCursorEndBar = min(self::cursorEndBar(), count($score->bars) - 1);
    self::$displayedBar = -1;
    self::$displayedReadout = '';
  }

  /** Recalculate visible bar columns when the native window width changes. */
  public static function resize(EventContext $event): void {
    if (!isset(self::$window) || $event->input !== self::$window) {
      return;
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

  /** Undo the latest edit in the selected melody. */
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

  /** Apply one saved melody state and refresh its cursor and controls. */
  private static function changeMelodyHistory(bool $redo): void {
    self::$history ??= new MelodyHistory(self::$score);
    try {
      $changed = $redo
        ? self::$history->redo(self::$score, self::$melody)
        : self::$history->undo(self::$score, self::$melody);
    } catch (\DomainException $error) {
      self::status('Cannot restore this melody: ' . $error->getMessage());
      return;
    }
    if (!$changed) {
      self::status($redo ? 'Nothing to redo in this melody.' : 'Nothing to undo in this melody.');
      return;
    }
    self::$bar = min(self::$bar, count(self::$score->bars) - 1);
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
    self::$history = new MelodyHistory($score);
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
    self::focusCursor();
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
    self::focusCursor();
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

  /** Toggle a legato link to the next adjacent melody note. */
  public static function toggleSlur(): void {
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
    $index = self::currentNoteIndex();
    if ($index === null) {
      throw new \DomainException('Select a note to start a dynamic mark.');
    }
    $current = self::$score->notes[$index]['dynamicToNext'] ?? '';
    self::$score->setDynamicToNext($index, Dynamics::nextDirection($current));
    self::focusCursor();
    self::invalidateSheet();
  }

  /** Cycle the selected note through the four signed trill intervals. */
  public static function cycleTrill(): void {
    $index = self::currentNoteIndex();
    if ($index === null) {
      throw new \DomainException('Select a note to set its trill.');
    }
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
    self::focusCursor();
  }

  /** Jump to the first or last position in a visible edge bar, paging from that bar. */
  public static function moveToVisibleEdge(bool $end, bool $select = false): void {
    $from = self::$score->start(self::$bar) + self::$slot;
    $previousFirstBar = self::$firstBar;
    [self::$firstBar, self::$bar, self::$slot] = self::visibleEdgeDestination($end);
    if ($select) {
      self::selection()->extend($from, self::$score->start(self::$bar) + self::$slot, self::$melody);
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
    self::focusCursor();
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
    $barStart = self::$score->start($target);
    $barEndSlot = 2 * self::$score->beats($target);
    $lastNote = self::$score->noteAt(self::$melody, $target, $barEndSlot - 1);
    if ($lastNote !== null) {
      $note = self::$score->notes[$lastNote];
      return [$first, $target, $note['bar'] === $target ? $note['slot'] : 0];
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
    return [$first, $target, $lastEnd - $barStart];
  }

  /** Move only the pitch cursor to another octave band. */
  public static function moveOctave(int $direction): void {
    $octave = max(0, min(8, self::$octave - $direction));
    self::assertPreviewBand($octave, self::$pitchPosition);
    self::$octave = $octave;
    self::focusCursor();
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
    self::focusCursor();
    self::$window->refreshLayout();
    self::status('Melody ' . (self::$melody + 1));
  }

  /** Append an instrument to the score and select it. */
  public static function addMelody(EventContext $event): void {
    if (!self::isDesign() || !self::commitEditorFields()) {
      return;
    }
    self::$melody = self::$score->addMelody();
    self::$history?->addedMelody(self::$score, self::$melody);
    self::$octave = MelodyRange::bands(self::$score, self::$melody)[1];
    self::clearSelection();
    self::refreshMelodyFields();
    self::focusCursor();
    self::$window->refreshLayout();
    self::status('Added melody ' . (self::$melody + 1) . '.');
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
    self::$history?->removedMelody($deleted);
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
      'dynamic' => static fn () => self::cycleDynamic(),
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
    self::$previewPage = max(0, min(count(PagePainter::pages(self::$score)) - 1, self::$previewPage + $direction));
    self::invalidateSheet();
  }

  /** Jump to the first or last PDF preview page. */
  public static function previewEdge(bool $last): void {
    if (self::isDesign()) {
      return;
    }
    self::$previewPage = $last ? count(PagePainter::pages(self::$score)) - 1 : 0;
    self::invalidateSheet();
  }

  /** Keep preview navigation visible in its only status bar. */
  private static function showPreviewHelp(): void {
    self::screen('sheet')->statusBar?->display(
      'V/Esc/Return: back to editor   PgUp/PgDown: page   Home/End: first/last page', 'hint', 'continuous');
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
    if (self::currentScreenId() === 'files') {
      Files::syncPathFromFiles();
    }
    self::$window->setCurrentScreenId(self::$view);
    self::focusCursor();
  }

  /** Toggle playback of the score or active melody within the selected span. */
  public static function togglePlayback(bool $selectedMelody): void {
    $mode = $selectedMelody ? 'melody' : 'score';
    try {
      $audio = App::audio();
      if (self::$playbackMode === $mode && $audio->playingMusic()) {
        $audio->stopMusic(5);
        self::$playbackMode = null;
        self::status('Playback stopped.');
        return;
      }
      $selection = self::selectionStateForSheet();
      $source = new ScoreSource(self::$score, $selectedMelody ? self::$melody : null,
        $selection['start'] ?? null, $selection['end'] ?? null);
      $audio->playMusic($source);
      self::$playbackMode = $mode;
      self::status('Playing ' . ($selectedMelody ? 'melody' : 'score') . ($selection === null ? '.' : ' selection.'));
    } catch (\Throwable $error) {
      self::status('Playback failed: ' . $error->getMessage());
    }
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
      } else if ($field === 'dynamicLevel') {
        self::applyDynamicLevel();
      } else if (in_array($field, ['title', 'author', 'catalog'], true)) {
        self::$score->$field = str_replace("\t", ' ', $event->widget->getValue());
      }
    } catch (\Throwable $error) {
      if ($field === 'volume' || $field === 'color') {
        self::refreshMelodyFields();
      } else if ($field === 'finger') {
        $index = self::currentNoteIndex();
        self::screen('editor')->widget('finger')->setValue($index === null
          ? '' : (string)(self::$score->notes[$index]['finger'] ?? ''));
      } else if ($field === 'dynamicLevel') {
        $index = self::currentNoteIndex();
        self::screen('editor')->widget('dynamicLevel')->setValue($index === null
          ? '' : (string)(self::$score->notes[$index]['dynamicLevel'] ?? ''));
      }
      self::status($error->getMessage());
    }
  }

  /** Show the sound choices in place of the melody settings tile. */
  public static function showSoundPicker(EventContext $event): void {
    $screen = self::screen('editor');
    $screen->widget('soundPicker')->setValue(self::$score->melodies[self::$melody]['pattern']);
    if ($screen->show('soundPicker')) {
      self::$window->refreshLayout();
    }
  }

  /** Save the chosen sound pattern for the active melody. */
  public static function melodyPatternChanged(EventContext $event): void {
    $pattern = $event->widget?->getValue();
    if (is_string($pattern)) {
      self::$score->setMelodyPattern(self::$melody, $pattern);
      self::$history?->observe(self::$score, self::$melody);
      self::refreshMelodyFields();
    }
  }

  /** Open file actions. */
  public static function showFiles(EventContext $event): void {
    if (self::isDesign() && !self::commitEditorFields()) {
      return;
    }
    $path = self::screen('files')->layout->findTile('path');
    if ($path instanceof LayoutLeaf) {
      self::screen('files')->selectLeaf($path);
    }
    self::$window->setCurrentScreenId('files');
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
    self::refreshSelectedBarFields();
    self::refreshCursorReadout();
    self::invalidateSheet();
    self::$window->refreshLayout();
    self::status($leader ? 'Section starts at bar ' . ($bar + 1) . '.' : 'Bar ' . ($bar + 1) . ' joins the previous section.');
  }

  /** Save the score to its current path. */
  public static function save(EventContext $event): void {
    if (self::commitEditorFields()) {
      Files::save($event);
    }
  }

  /** Save the score to the path shown on the Files screen. */
  public static function saveAs(EventContext $event): void {
    Files::saveAs($event);
  }

  /** Open a JSON score. */
  public static function open(EventContext $event): void {
    Files::open($event);
  }

  /** Import an old web-editor text score. */
  public static function importLegacy(EventContext $event): void {
    Files::importLegacy($event);
  }

  /** Export the score as a paginated PDF. */
  public static function exportPdf(EventContext $event): void {
    if (self::currentScreenId() === 'files' || !self::isDesign() || self::commitEditorFields()) {
      Files::exportPdf($event);
    }
  }

  /** Install one retained canvas in each screen's board. */
  private static function installSheet(string $view): void {
    $screen = self::screen($view);
    $oldBoard = $screen->layout->findNode('board');
    if ($oldBoard === null) {
      throw new \LogicException('The score board is missing from the ' . $view . ' screen.');
    }
    $board = new LayoutNode('horizontal', '1*', $view === 'editor' ? '50%' : '1*', id: 'board');
    $canvas = new SheetCanvas();
    $canvas->setId('sheet');
    $canvas->setTips($view === 'editor' ? 'Score editor.' : 'PDF preview.', $view === 'editor' ? 'Return adds or changes a note; Space adds a note or follows its pitch.' : 'V/Esc/Return opens editor; Page Up and Down turn pages; Home and End jump to page edges.');
    $sheet = new LayoutLeaf('Canvas', '1*', '1*', $canvas, paintBackground: false);
    $board->addLeaf($sheet);
    if (!$screen->layout->replaceChild($oldBoard, $board)) {
      throw new \LogicException('The score board could not be installed on the ' . $view . ' screen.');
    }
    $screen->setLayout($screen->layout);
    self::$sheets[$view] = $sheet;
  }

  /** Keep the cursor visible and the sheet active. */
  private static function focusCursor(): void {
    self::$history?->observe(self::$score, self::$melody);
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
    self::$sheet->instance()->invalidate();
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
    $screen->widget('selectedMelody')->setText('[Y] Melody ' . (self::$melody + 1));
    $screen->widget('soundPicker')->setValue($settings['pattern']);
    foreach ($screen->widget('soundPicker')->items() as $item) {
      if ($item['value'] === $settings['pattern']) {
        $screen->widget('sound')->setValue($item['label']);
        break;
      }
    }
    $screen->widget('volume')->setValue((string)$settings['volume']);
    $screen->widget('color')->setValue($settings['color']);
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
    $readout = $title . "\n" . $cursorTitle . "\n" . $lengthText . "\n" . ($index === null ? '' : json_encode([
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
    self::screen('editor')->widget('dynamic')->setLabel('Volume: ' . ($index === null ? '—'
      : match ($note['dynamicToNext'] ?? '') {
        'crescendo' => '+',
        'decrescendo' => '-',
        default => '=',
      }));
    self::screen('editor')->widget('trill')->setLabel($index === null ? 'Trill: —'
      : 'Trill: ' . (($note['trill'] ?? 0) === 0 ? 'Off' : (($note['trill'] > 0 ? '+' : '') . $note['trill'])));
    self::screen('editor')->widget('finger')->setValue($index === null ? '' : (string)($note['finger'] ?? ''));
    self::screen('editor')->widget('dynamicLevel')->setValue($index === null ? '' : (string)($note['dynamicLevel'] ?? ''));
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
      self::applyDynamicLevel();
      $screen = self::screen('editor');
      foreach (['title', 'author', 'catalog'] as $field) {
        self::$score->$field = str_replace("\t", ' ', $screen->widget($field)->getValue());
      }
      return true;
    } catch (\Throwable $error) {
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

  /** Apply a dynamic level at the selected note. */
  private static function applyDynamicLevel(): void {
    $raw = strtolower(trim(self::screen('editor')->widget('dynamicLevel')->getValue()));
    $index = self::currentNoteIndex();
    if ($index === null) {
      if ($raw !== '') {
        throw new \DomainException('Select a note before setting its dynamic level.');
      }
      return;
    }
    if ($raw !== (string)(self::$score->notes[$index]['dynamicLevel'] ?? '')) {
      self::$score->setDynamicLevel($index, $raw);
      self::$history?->observe(self::$score, self::$melody);
      self::invalidateSheet();
    }
    self::screen('editor')->widget('dynamicLevel')->setValue($raw);
    self::refreshCursorReadout();
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
    self::$score->setMelodyVolume(self::$melody, (int)$raw);
    self::$history?->observe(self::$score, self::$melody);
  }

  /** Validate and apply the active melody's hex display color. */
  private static function applyMelodyColor(): void {
    $color = trim(self::screen('editor')->widget('color')->getValue());
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
    self::$history?->reset(self::$score);
    self::$slot = min(self::$slot, 2 * $beats - 1);
    if (self::fitColumns() !== self::$columns) {
      self::$columns = self::fitColumns();
      self::$window->refreshLayout();
    }
    self::focusCursor();
  }
}
