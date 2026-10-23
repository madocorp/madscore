<?php

namespace MADSCORE\App;

use MADSCORE\Render\PagePainter;
use MADSCORE\Score\MelodyRange;
use SPTK\App;
use SPTK\Events\EventContext;
use SPTK\Layout\LayoutLeaf;

/** Cursor navigation, melodies, views, and settings. */
final class ControllerNavigation extends ControllerState {
  /** Return the visible screen identifier for file actions. */
  public static function currentScreenId(): string {
    return self::$window->currentScreenId();
  }

  /** Display a short status message without interrupting note entry. */
  public static function status(string $message, ?string $screen = null): void {
    Controller::screen($screen ?? self::$view)->statusBar?->notice($message, 'background', 4500);
  }

  /** Repaint the sheet after a changed note. */
  public static function changedNote(?array $before, ?array $after): void {
    ControllerSheet::clearSelection();
    ControllerSheet::invalidateSheet();
  }

  /** Move through melody positions or extend a selection. */
  public static function moveTime(int $direction, bool $select = false): void {
    $from = self::$score->start(self::$bar) + self::$slot;
    [self::$bar, self::$slot] = self::$score->moveCursor(self::$melody, self::$bar, self::$slot, $direction, $select || !Controller::isDesign());
    if ($select) {
      ControllerSheet::selection()->extend($from, self::$score->start(self::$bar) + self::$slot, self::$melody);
    } else {
      ControllerSheet::clearSelection();
    }
    ControllerSheet::syncNoteLength();
    ControllerSheet::focusWithoutHistory();
  }

  /** Jump to the first or last position in a visible edge bar, paging from that bar. */
  public static function moveToVisibleEdge(bool $end, bool $select = false, string $scope = 'melody'): void {
    $from = self::$score->start(self::$bar) + self::$slot;
    $previousFirstBar = self::$firstBar;
    [self::$firstBar, self::$bar, self::$slot] = self::visibleEdgeDestination($end);
    if ($select) {
      ControllerSheet::selection()->extend($from, self::$score->start(self::$bar) + self::$slot, self::$melody, $scope);
    } else {
      ControllerSheet::clearSelection();
    }
    ControllerSheet::syncNoteLength();
    if ($end && ControllerSheet::currentNoteIndex() === null) {
      self::$cursorLength = min(ControllerNoteEditing::cursorLength(), 2 * self::$score->beats(self::$bar) - self::$slot);
      self::$cursorLengthExplicit = true;
    }
    if (self::$firstBar !== $previousFirstBar) {
      ControllerSheet::alignTimeline();
      ControllerSheet::invalidateSheet();
      self::$window->refreshLayout();
    }
    ControllerSheet::focusWithoutHistory();
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
  public static function lastPositionInBar(int $target): int {
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
    ControllerSheet::assertPreviewBand($octave, self::$pitchPosition);
    self::$octave = $octave;
    ControllerSheet::focusWithoutHistory();
  }

  /** Move between existing melodies, wrapping at either end. */
  public static function moveMelody(int $direction): void {
    self::$melody = self::wrappedMelodyIndex(self::$melody, count(self::$score->melodies), $direction);
    ControllerSheet::clearSelection();
    ControllerSheet::syncNoteLength();
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
    ControllerSheet::refreshMelodyFields();
    ControllerMotifGeneration::refreshContextHeader();
    ControllerSheet::focusWithoutHistory();
    self::$window->refreshLayout();
    self::status('Melody ' . (self::$melody + 1));
  }

  /** Wrap an adjacent melody move at either end of the list. */
  private static function wrappedMelodyIndex(int $current, int $count, int $direction): int {
    return ($current + $direction + $count) % $count;
  }

  /** Append an instrument to the score and select it. */
  public static function addMelody(EventContext $event): void {
    if (!Controller::isDesign() || !ControllerSheet::commitEditorFields()) {
      return;
    }
    self::$melody = self::$score->addMelody();
    self::$history?->observe(self::$score);
    self::$octave = MelodyRange::bands(self::$score, self::$melody)[1];
    ControllerSheet::clearSelection();
    ControllerSheet::refreshMelodyFields();
    ControllerSheet::focusCursor();
    self::$window->refreshLayout();
    self::status('Added melody ' . (self::$melody + 1) . '.');
  }

  /** Switch the selected melody between audible/printed and hidden. */
  public static function toggleMelodyMute(EventContext $event): void {
    if (!Controller::isDesign()) {
      return;
    }
    if (self::$score->melodyMuted(self::$melody)) {
      ControllerSheet::refreshMelodyFields();
      $index = ControllerSheet::currentNoteIndex();
      Controller::screen('editor')->widget('finger')->setValue($index === null ? ''
        : (string)(self::$score->notes[$index]['finger'] ?? ''));
    }
    if (!ControllerSheet::commitEditorFields()) {
      return;
    }
    if (self::$playbackMode !== null) {
      App::audio()->stopMusic();
      self::$playbackMode = null;
    }
    $muted = !self::$score->melodyMuted(self::$melody);
    self::$score->setMelodyMuted(self::$melody, $muted);
    self::$history?->observe(self::$score);
    ControllerSheet::refreshMelodyFields();
    ControllerSheet::invalidateSheet();
    self::$previewPage = min(self::$previewPage, count(PagePainter::pages(self::$score)) - 1);
    self::$window->refreshLayout();
    self::status('Melody ' . (self::$melody + 1) . ($muted ? ' muted.' : ' unmuted.'));
  }

  /** Ask the status bar before deleting the selected melody and its notes. */
  public static function requestDeleteMelody(EventContext $event): void {
    if (!Controller::isDesign() || !ControllerSheet::commitEditorFields()) {
      return;
    }
    if (count(self::$score->melodies) === 1) {
      self::status('A score needs at least one melody.');
      return;
    }
    self::$pendingDeleteMelody = self::$melody;
    Controller::screen('editor')->statusBar->confirm(
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
    ControllerSheet::clearSelection();
    ControllerSheet::syncNoteLength();
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
    ControllerSheet::refreshMelodyFields();
    ControllerSheet::focusCursor();
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
    if (!Controller::isDesign() || !in_array($field, ['tempo', 'sound', 'staccato'], true)) {
      return;
    }
    $leaf = Controller::screen('editor')->layout->findTile($field);
    if ($leaf instanceof LayoutLeaf) {
      Controller::screen('editor')->selectLeaf($leaf);
    }
  }

  /** Run the editor's visible buttons through the same commands as its shortcuts. */
  public static function functionButton(EventContext $event): void {
    $id = $event->widget?->id();
    $actions = [
      'section' => static fn () => ControllerEditorActions::toggleSection(),
      'staccato' => static fn () => ControllerNoteMarks::toggleStaccato(),
      'fermata' => static fn () => ControllerNoteMarks::toggleFermata(),
      'slur' => static fn () => ControllerNoteMarks::toggleSlur(),
      'glissando' => static fn () => ControllerNoteMarks::toggleGlissando(),
      'dynamic' => static fn () => ControllerNoteMarks::cycleScoreDynamic(),
      'dynamicLevel' => static fn () => ControllerNoteMarks::cycleDynamicLevel(),
      'trill' => static fn () => ControllerNoteMarks::cycleTrill(),
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
    if (Controller::isDesign() && !ControllerSheet::commitEditorFields()) {
      return;
    }
    self::$view = Controller::isDesign() ? 'sheet' : 'editor';
    self::$firstRow = 0;
    if (!Controller::isDesign()) {
      foreach (PagePainter::pages(self::$score) as $index => $page) {
        if (in_array(self::$bar, array_merge(...$page), true)) {
          self::$previewPage = $index;
          break;
        }
      }
    }
    self::$sheet = self::$sheets[self::$view];
    self::$window->setCurrentScreenId(self::$view);
    if (Controller::isDesign()) {
      ControllerSheet::refreshEditorFields();
    } else {
      self::showPreviewHelp();
    }
    ControllerSheet::focusCursor();
    self::$window->refreshLayout();
  }

  /** Turn PDF preview pages. */
  public static function scrollRows(int $direction): void {
    if (Controller::isDesign()) {
      return;
    }
    $page = max(0, min(count(PagePainter::pages(self::$score)) - 1, self::$previewPage + $direction));
    if ($page !== self::$previewPage) {
      self::$previewPage = $page;
      ControllerSheet::invalidateSheet();
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
    if (Controller::isDesign()) {
      return;
    }
    $page = $last ? count(PagePainter::pages(self::$score)) - 1 : 0;
    if ($page !== self::$previewPage) {
      self::$previewPage = $page;
      ControllerSheet::invalidateSheet();
    }
  }

  /** Keep preview navigation visible in its only status bar. */
  private static function showPreviewHelp(): void {
    Controller::screen('sheet')->statusBar?->display(
      'V/Esc/Return: editor   B/N: page   F/L: first/last   Arrows/PgUp/PgDown: scroll   +/-: zoom   Space: fit', 'hint', 'continuous');
  }

  /** Return from tile navigation to the score editor with Escape. */
  public static function activateSheetEditor(EventContext $event): bool {
    if (!Controller::isDesign() || Controller::screen('editor')->activeLeaf() !== null) {
      return false;
    }
    Controller::screen('editor')->activateLeaf(self::$sheets['editor']);
    return true;
  }

  /** Show the score editing screen. */
  public static function showEditor(EventContext $event): void {
    self::$window->setCurrentScreenId(self::$view);
    ControllerSheet::focusCursor();
  }

  /** Open the application settings after applying edits in the score fields. */
  public static function openSettings(EventContext $event): bool {
    if (ControllerSheet::commitEditorFields()) {
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
}
