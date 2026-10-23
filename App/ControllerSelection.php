<?php

namespace MADSCORE\App;

use MADSCORE\Score\RangeEdit;
use MADSCORE\Score\Score;

/** Time selection, copying, pasting, and transposition. */
final class ControllerSelection extends ControllerState {
  /** Return the cursor state for the whole sheet. */
  public static function cursorStateForSheet(): array {
    return ControllerSheet::cursorState();
  }

  /** Return the active melody's selected time span for the sheet overlay. */
  public static function selectionStateForSheet(): ?array {
    return self::$selection?->state();
  }

  /** Copy the selected notes and their relative timing inside this process. */
  public static function copySelection(): void {
    $range = self::selectionStateForSheet();
    if ($range === null) {
      ControllerNavigation::status('Select a range to copy.');
      return;
    }
    self::$musicClipboard = RangeEdit::copy(self::$score, $range);
    ControllerNavigation::status('Copied selection.');
  }

  /** Replace the selection or insert a clip at the cursor's left edge. */
  public static function pasteSelection(): void {
    if (self::$musicClipboard === null) {
      ControllerNavigation::status('Nothing copied yet.');
      return;
    }
    $range = self::selectionStateForSheet();
    $at = ControllerSheet::cursorState()['start'];
    $start = $range['start'] ?? $at;
    $duration = self::$musicClipboard['duration'];
    $next = RangeEdit::paste(self::$score, self::$musicClipboard, $range, self::$melody, $at);
    self::applyRangeScore($next, true, $start + $duration);
    ControllerNavigation::status('Pasted selection.');
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
    ControllerSheet::selection()->extend($from, self::$score->start(self::$bar) + self::$slot, self::$melody, 'score');
    ControllerSheet::syncNoteLength();
    ControllerSheet::focusWithoutHistory();
  }

  /** Select every bar and melody in the score. */
  public static function selectAll(): void {
    $end = self::$score->start(count(self::$score->bars));
    self::moveToTime($end);
    ControllerSheet::selection()->clear();
    ControllerSheet::selection()->extend(0, $end, self::$melody, 'score');
    ControllerSheet::syncNoteLength();
    ControllerSheet::focusWithoutHistory();
  }

  /** Jump to the first or last cursor position in the score. */
  public static function moveToScoreEdge(bool $end): void {
    [self::$bar, self::$slot] = self::scoreEdgeDestination($end);
    ControllerSheet::clearSelection();
    ControllerSheet::syncNoteLength();
    if ($end && ControllerSheet::currentNoteIndex() === null) {
      self::$cursorLength = min(ControllerNoteEditing::cursorLength(), 2 * self::$score->beats(self::$bar) - self::$slot);
      self::$cursorLengthExplicit = true;
    }
    ControllerSheet::focusWithoutHistory();
  }

  private static function scoreEdgeDestination(bool $end): array {
    $bar = $end ? count(self::$score->bars) - 1 : 0;
    return [$bar, $end ? ControllerNavigation::lastPositionInBar($bar) : 0];
  }

  private static function moveToTime(int $time): void {
    $bar = 0;
    while ($bar + 1 < count(self::$score->bars) && $time >= self::$score->start($bar + 1)) {
      $bar++;
    }
    self::$bar = $bar;
    self::$slot = $time - self::$score->start($bar);
  }

  public static function applyRangeScore(Score $next, bool $clear = false, ?int $cursorTime = null): void {
    self::$score = $next;
    if ($clear) {
      ControllerSheet::clearSelection();
    }
    if ($cursorTime !== null) {
      self::moveToTime($cursorTime);
    }
    ControllerSheet::syncNoteLength();
    ControllerSheet::focusCursor(false);
    self::$history?->observe(self::$score);
  }
}
