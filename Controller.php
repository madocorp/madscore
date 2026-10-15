<?php

namespace MADSCORE;

use SPTK\App;
use SPTK\Core\{Screen, Window};
use SPTK\Events\EventContext;
use SPTK\Layout\{LayoutLeaf, LayoutNode};

/** Owns the score, visible sheet, and editor navigation. */
final class Controller {

  private static Score $score;
  private static Window $window;
  private static LayoutNode $board;
  private static LayoutLeaf $sheet;
  private static int $columns = 0;
  private static int $firstBar = 0;
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

  /** Bind the new score and build its first visible four-staff grid. */
  public static function initialize(EventContext $event): void {
    self::$window = App::eventLoop()->windows()[0];
    self::$score = new Score();
    self::$cursorLengthExplicit = false;
    self::$lastCursorEndBar = 0;
    self::$selection = new MelodySelection();
    self::$sessionLoadFailed = false;
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
    self::$board = self::screen('editor')->layout->findNode('board');
    self::rebuild();
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
      'version' => 1,
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
      'selection' => self::selectionStateForSheet(),
    ];
  }

  /** Validate persisted state before replacing the new editor defaults. */
  private static function restoreSession(array $state): void {
    if (($state['version'] ?? null) !== 1 || !is_array($state['score'] ?? null) || !is_array($state['cursor'] ?? null)) {
      throw new \DomainException('The previous editor session is invalid.');
    }
    $score = Score::fromData($state['score']);
    $cursor = $state['cursor'];
    $bar = $cursor['bar'] ?? null;
    $slot = $cursor['slot'] ?? null;
    $octave = $cursor['octave'] ?? null;
    $melody = $cursor['melody'] ?? null;
    $length = $cursor['length'] ?? null;
    $lengthExplicit = $cursor['lengthExplicit'] ?? false;
    $pitch = $cursor['pitch'] ?? null;
    $firstBar = $state['firstBar'] ?? null;
    if (!is_int($bar) || $bar < 0 || $bar >= count($score->bars)
      || !is_int($slot) || $slot < 0 || $slot > 2 * $score->beats($bar)
      || !is_int($octave) || $octave < 0 || $octave > 8
      || !is_int($melody) || $melody < 0 || $melody >= Score::MELODY_COUNT
      || !is_int($length) || $length < 1 || $length > 198
      || !is_bool($lengthExplicit)
      || !is_int($pitch) || $pitch < 1 || $pitch > 12
      || !is_int($firstBar) || $firstBar < 0 || $firstBar >= count($score->bars)) {
      throw new \DomainException('The previous editor position is invalid.');
    }
    $selection = new MelodySelection();
    if (($savedSelection = $state['selection'] ?? null) !== null) {
      $scoreEnd = $score->start(count($score->bars));
      if (!is_array($savedSelection)
        || !is_int($savedSelection['anchor'] ?? null) || $savedSelection['anchor'] < 0 || $savedSelection['anchor'] > $scoreEnd
        || !is_int($savedSelection['caret'] ?? null) || $savedSelection['caret'] < 0 || $savedSelection['caret'] > $scoreEnd
        || ($savedSelection['melody'] ?? null) !== $melody) {
        throw new \DomainException('The previous editor selection is invalid.');
      }
      $selection->extend($savedSelection['anchor'], $savedSelection['caret'], $melody);
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
    self::$selection = $selection;
    self::$lastCursorEndBar = min(self::cursorEndBar(), count($score->bars) - 1);
    self::$displayedBar = -1;
    self::$displayedReadout = '';
  }

  /** Rebuild bar columns when the native window width changes. */
  public static function resize(EventContext $event): void {
    if (!isset(self::$window) || $event->input !== self::$window) {
      return;
    }
    $columns = self::fitColumns();
    if ($columns !== self::$columns) {
      self::rebuild();
    }
  }

  /** Return the active score for renderers and commands. */
  public static function score(): Score {
    return self::$score;
  }

  /** Replace the document after a validated load or import. */
  public static function loadScore(Score $score): void {
    if (self::$playbackMode !== null) {
      App::audio()->stopMusic();
      self::$playbackMode = null;
    }
    self::$score = $score;
    self::$selection = new MelodySelection();
    self::$bar = 0;
    self::$slot = 0;
    self::$octave = Prescription::states($score)[0]['primary'];
    self::$melody = 0;
    self::$cursorLength = 2;
    self::$cursorLengthExplicit = false;
    self::$lastCursorEndBar = 0;
    self::$pitchPosition = 4;
    self::$firstBar = 0;
    self::rebuild();
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

  /** Move the preview or selected note by one semitone. */
  public static function stepPitch(int $direction): void {
    $index = self::currentNoteIndex();
    if ($index === null) {
      [$octave, $position] = Pitch::step(self::$octave, self::$pitchPosition, $direction);
      self::assertPreviewBand($octave);
      self::$octave = $octave;
      self::$pitchPosition = $position;
    } else {
      $oldNote = self::$score->notes[$index];
      self::$score->stepPitch($index, $direction);
      $note = self::$score->notes[$index];
      self::$octave = $note['octave'];
      self::$pitchPosition = Pitch::position($note);
      self::changedNote($oldNote, $note);
      self::selectNoteOnset($index);
    }
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

  /** Insert a one-beat note or an exact duplicate after the selected note. */
  public static function insertAfter(bool $duplicate): void {
    $index = self::currentNoteIndex();
    if ($index === null) {
      self::status('Select a note before inserting another.');
      return;
    }
    $copy = $duplicate ? self::$score->repeatAfter($index) : self::$score->baseAfter($index);
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
    self::syncNotePreview();
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
  public static function status(string $message, string $screen = 'editor'): void {
    self::screen($screen)->statusBar?->notice($message, 'background', 4500);
  }

  /** Repaint the sheet after a changed note. */
  public static function changedNote(?array $before, ?array $after): void {
    self::clearSelection();
    self::invalidateSheet();
  }

  /** Move through melody positions, extend a selection, or jump one bar. */
  public static function moveTime(int $direction, bool $jump, bool $select = false): void {
    $from = self::$score->start(self::$bar) + self::$slot;
    if ($jump) {
      self::$bar = max(0, self::$bar + $direction);
      self::$slot = 0;
      self::clearSelection();
    } else {
      [self::$bar, self::$slot] = self::$score->moveCursor(self::$melody, self::$bar, self::$slot, $direction, $select);
      if ($select) {
        self::selection()->extend($from, self::$score->start(self::$bar) + self::$slot, self::$melody);
      } else {
        self::clearSelection();
      }
    }
    self::syncNotePreview();
    self::focusCursor();
  }

  /** Jump to the first note in the visible edge bar, paging from that bar. */
  public static function moveToVisibleEdge(bool $end): void {
    $previousFirstBar = self::$firstBar;
    [self::$firstBar, self::$bar, self::$slot] = self::visibleEdgeDestination($end);
    self::clearSelection();
    self::syncNotePreview();
    if (self::$firstBar !== $previousFirstBar) {
      self::rebuild();
      self::$window->refreshLayout();
    }
    self::focusCursor();
  }

  /** Return the page, bar, and earliest active-melody onset for Home or End. */
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
    $slot = null;
    foreach (self::$score->notes as $note) {
      if ($note['melody'] === self::$melody && $note['bar'] === $target) {
        $slot = $slot === null ? $note['slot'] : min($slot, $note['slot']);
      }
    }
    return [$first, $target, $slot ?? 0];
  }

  /** Select one of the nine absolute octave bands. */
  public static function moveOctave(int $direction): void {
    $index = self::currentNoteIndex();
    $octave = max(0, min(8, self::$octave - $direction));
    if ($index !== null && $octave !== self::$octave) {
      $oldNote = self::$score->notes[$index];
      self::$score->setPitchPosition($index, $octave, Pitch::position($oldNote));
      self::changedNote($oldNote, self::$score->notes[$index]);
      self::selectNoteOnset($index);
    } else if ($index === null) {
      self::assertPreviewBand($octave);
    }
    self::$octave = $octave;
    self::focusCursor();
  }

  /** Cycle between the available melodies. */
  public static function moveMelody(int $direction): void {
    self::$melody = (self::$melody + $direction + Score::MELODY_COUNT) % Score::MELODY_COUNT;
    self::clearSelection();
    self::syncNotePreview();
    self::refreshMelodyFields();
    self::focusCursor();
    self::$window->refreshLayout();
    self::status('Melody ' . (self::$melody + 1), 'editor');
  }

  /** Show the score editing screen. */
  public static function showEditor(EventContext $event): void {
    if (self::currentScreenId() === 'files') {
      Files::syncPathFromFiles();
    }
    self::$window->setCurrentScreenId('editor');
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
      } else if ($field === 'volume') {
        self::applyMelodyVolume();
      } else if ($field === 'color') {
        self::applyMelodyColor();
      } else if ($field === 'filename') {
        Files::setFilename($event->widget->getValue());
      } else if (in_array($field, ['title', 'author', 'catalog'], true)) {
        self::$score->$field = str_replace("\t", ' ', $event->widget->getValue());
      }
    } catch (\Throwable $error) {
      if ($field === 'volume' || $field === 'color') {
        self::refreshMelodyFields();
      }
      self::status($error->getMessage());
    }
  }

  /** Save a changed sound pattern for the active melody. */
  public static function melodyPatternChanged(EventContext $event): void {
    $pattern = $event->widget?->getValue();
    if (is_string($pattern)) {
      self::$score->setMelodyPattern(self::$melody, $pattern);
    }
  }

  /** Open file actions. */
  public static function showFiles(EventContext $event): void {
    if (!self::commitEditorFields()) {
      return;
    }
    self::$window->setCurrentScreenId('files');
  }

  /** Add a bar at the end and move the cursor into it. */
  public static function newBar(EventContext $event): void {
    if (!self::commitEditorFields()) {
      return;
    }
    self::$bar = count(self::$score->bars);
    self::clearSelection();
    self::$score->ensureBar(self::$bar);
    self::$slot = 0;
    self::$cursorLengthExplicit = false;
    self::focusCursor();
  }

  /** Start or join a section at the selected bar. */
  public static function toggleSection(EventContext $event): void {
    $bar = self::$bar;
    if ($bar === 0) {
      self::status('The first bar always starts a section.');
      return;
    }
    $leader = !self::$score->bars[$bar]['sectionLeader'];
    self::$score->setSectionLeader($bar, $leader);
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

  /** Open a versioned JSON score. */
  public static function open(EventContext $event): void {
    Files::open($event);
  }

  /** Import an old web-editor text score. */
  public static function importLegacy(EventContext $event): void {
    Files::importLegacy($event);
  }

  /** Export the score as a paginated PDF. */
  public static function exportPdf(EventContext $event): void {
    if (self::currentScreenId() === 'files' || self::commitEditorFields()) {
      Files::exportPdf($event);
    }
  }

  /** Recreate the visible sheet when its bar range changes. */
  private static function rebuild(): void {
    self::$columns = self::fitColumns();
    self::alignTimeline();
    $board = new LayoutNode('horizontal', '1*', '50%');
    $canvas = new SheetCanvas();
    $canvas->setId('sheet');
    $canvas->setTips('Return enters the score editor.', 'Return places; Insert adds a base note; Shift+Insert duplicates; Esc leaves.');
    self::$sheet = new LayoutLeaf('Canvas', '1*', '1*', $canvas, paintBackground: false);
    $board->addLeaf(self::$sheet);
    $screen = self::screen('editor');
    $screen->layout->replaceChild(self::$board, $board);
    self::$board = $board;
    $screen->setLayout($screen->layout);
    $screen->activateLeaf(self::$sheet);
  }

  /** Keep the cursor visible and the sheet active. */
  private static function focusCursor(): void {
    self::syncTimelineBars();
    if (self::currentNoteIndex() === null) {
      $bands = Prescription::soundingBands(self::$score, self::$bar);
      if (count($bands) >= 4 && !in_array(self::$octave, $bands, true)) {
        self::$octave = Prescription::states(self::$score)[self::$bar]['primary'];
      }
    }
    $barChanged = self::$displayedBar !== self::$bar;
    if ($barChanged) {
      self::refreshSelectedBarFields();
    }
    $readoutChanged = self::refreshCursorReadout();
    $previousFirstBar = self::$firstBar;
    self::alignTimeline();
    if (self::$firstBar !== $previousFirstBar) {
      self::rebuild();
      self::$window->refreshLayout();
    } else {
      self::screen('editor')->activateLeaf(self::$sheet);
      self::invalidateSheet();
      if ($barChanged || $readoutChanged) {
        self::$window->refreshLayout();
      }
    }
  }

  /** Grow or trim trailing bars according to the cursor's final occupied position. */
  private static function syncTimelineBars(): void {
    if (self::selectionStateForSheet() !== null) {
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

  /** Load a selected note's pitch and width into the H cursor. */
  private static function syncNotePreview(): void {
    self::$cursorLengthExplicit = false;
    $index = self::currentNoteIndex();
    if ($index === null) {
      return;
    }
    $note = self::$score->notes[$index];
    self::$octave = $note['octave'];
    self::$pitchPosition = Pitch::position($note);
    self::$cursorLength = $note['length'];
  }

  /** Keep an empty preview within the four bands available in its bar. */
  private static function assertPreviewBand(int $octave): void {
    $bands = Prescription::soundingBands(self::$score, self::$bar);
    if (!in_array($octave, $bands, true) && count($bands) >= 4) {
      throw new \DomainException('This bar already uses four octave bands.');
    }
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
    self::refreshFilename();
    self::refreshSelectedBarFields();
    self::refreshMelodyFields();
    self::refreshCursorReadout();
  }

  /** Keep the top filename input aligned with the selected score path. */
  public static function refreshFilename(): void {
    self::screen('editor')->widget('filename')->setValue(Files::filename());
  }

  /** Show the settings of the bar under the score cursor. */
  private static function refreshSelectedBarFields(): void {
    $screen = self::screen('editor');
    $screen->widget('tempo')->setValue(self::$score->bars[self::$bar]['tempo']);
    $screen->widget('beats')->setValue((string)self::$score->beats(self::$bar));
    self::$displayedBar = self::$bar;
  }

  /** Show the active melody's saved sound and display settings. */
  private static function refreshMelodyFields(): void {
    $screen = self::screen('editor');
    $settings = self::$score->melodies[self::$melody];
    $screen->widget('selectedMelody')->setText('Melody ' . (self::$melody + 1) . ' settings');
    $screen->widget('pattern')->setValue($settings['pattern']);
    $screen->widget('volume')->setValue((string)$settings['volume']);
    $screen->widget('color')->setValue($settings['color']);
  }

  /** Update the compact bar, pitch, and length readout below the sheet. */
  private static function refreshCursorReadout(): bool {
    $index = self::currentNoteIndex();
    if ($index === null) {
      $pitchLine = 'Gap';
    } else {
      $note = self::$score->notes[$index];
      $pitchLine = 'Pitch ' . $note['pitch']
        . ($note['alter'] === 1 ? '#' : ($note['alter'] === -1 ? 'b' : ''))
        . $note['octave'];
    }
    $length = self::cursorLength();
    $beats = intdiv($length, 2) . ($length % 2 ? '.5' : '');
    $readout = 'Bar ' . (self::$bar + 1) . ' · Section ' . self::$score->sectionNumber(self::$bar) . "\n" . $pitchLine . "\n"
      . 'Length ' . $beats . ($length === 2 ? ' beat' : ' beats');
    if ($readout === self::$displayedReadout) {
      return false;
    }
    self::screen('editor')->widget('selectedBar')->setText($readout);
    self::$displayedReadout = $readout;
    return true;
  }

  /** Apply the visible fields before a file or bar action. */
  private static function commitEditorFields(): bool {
    try {
      self::applyEditorBeats();
      self::applyEditorTempo();
      self::applyMelodyVolume();
      self::applyMelodyColor();
      $screen = self::screen('editor');
      Files::setFilename($screen->widget('filename')->getValue());
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

  /** Validate and store a whole-number melody volume percentage. */
  private static function applyMelodyVolume(): void {
    $raw = trim(self::screen('editor')->widget('volume')->getValue());
    if (!ctype_digit($raw) || (int)$raw > 100) {
      throw new \DomainException('Melody volume must be a whole number from 0 to 100.');
    }
    self::$score->setMelodyVolume(self::$melody, (int)$raw);
  }

  /** Validate and apply the active melody's hex display color. */
  private static function applyMelodyColor(): void {
    $color = trim(self::screen('editor')->widget('color')->getValue());
    self::$score->setMelodyColor(self::$melody, $color);
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
      self::rebuild();
      self::$window->refreshLayout();
    }
    self::focusCursor();
  }
}
