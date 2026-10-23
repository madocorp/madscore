<?php

namespace MADSCORE\App;

use MADSCORE\Score\Dynamics;
use MADSCORE\Score\DynamicLevel;
use MADSCORE\Score\Pitch;
use MADSCORE\Score\RangeEdit;

/** Articulation, dynamics, and note insertion or removal. */
final class ControllerNoteMarks extends ControllerState {
  /** Toggle the selected note's short articulation. */
  public static function toggleStaccato(): void {
    $index = ControllerSheet::currentNoteIndex();
    if ($index === null) {
      throw new \DomainException('Select a note to change its articulation.');
    }
    $note = self::$score->notes[$index];
    self::$score->setStaccato($index, ($note['articulation'] ?? 'ordinary') !== 'staccato');
    ControllerSheet::focusCursor();
    ControllerSheet::invalidateSheet();
  }

  /** Toggle a hold on the selected note. */
  public static function toggleFermata(): void {
    $index = ControllerSheet::currentNoteIndex();
    if ($index === null) {
      throw new \DomainException('Select a note to change its fermata.');
    }
    self::$score->setFermata($index, !self::$score->notes[$index]['fermata']);
    ControllerSheet::focusCursor();
    ControllerSheet::invalidateSheet();
  }

  /** Toggle a legato link, or link the selected notes as one group. */
  public static function toggleSlur(): void {
    if (($range = ControllerSelection::selectionStateForSheet()) !== null) {
      ControllerSelection::applyRangeScore(RangeEdit::mark(self::$score, $range, 'link'));
      return;
    }
    $index = ControllerSheet::currentNoteIndex();
    if ($index === null) {
      throw new \DomainException('Select a note to start a slur.');
    }
    self::$score->setSlurToNext($index, !self::$score->notes[$index]['slurToNext']);
    ControllerSheet::focusCursor();
    ControllerSheet::invalidateSheet();
  }

  /** Toggle one sloped note between this note and its adjacent successor. */
  public static function toggleGlissando(): void {
    $index = ControllerSheet::currentNoteIndex();
    if ($index === null) {
      throw new \DomainException('Select a note to start a glissando.');
    }
    self::$score->setGlissandoToNext($index, !(self::$score->notes[$index]['glissandoToNext'] ?? false));
    ControllerSheet::focusCursor();
    ControllerSheet::invalidateSheet();
  }

  /** Cycle the volume change to the next note through louder, softer, and level. */
  public static function cycleDynamic(): void {
    if (($range = ControllerSelection::selectionStateForSheet()) !== null) {
      ControllerSelection::applyRangeScore(RangeEdit::mark(self::$score, $range, 'dynamic'));
      return;
    }
    $index = ControllerSheet::currentNoteIndex();
    if ($index === null) {
      throw new \DomainException('Select a note to start a dynamic mark.');
    }
    $current = self::$score->notes[$index]['dynamicToNext'] ?? '';
    self::$score->setDynamicToNext($index, Dynamics::nextDirection($current));
    ControllerSheet::focusCursor();
    ControllerSheet::invalidateSheet();
  }

  /** Cycle the level shared by every melody at the cursor time. */
  public static function cycleDynamicLevel(): void {
    $time = self::$score->start(self::$bar) + self::$slot;
    self::$score->setScoreDynamicLevel($time, DynamicLevel::next(self::sharedDynamicLevel()));
    self::$displayedReadout = '';
    ControllerSheet::focusCursor();
  }

  /** Set a shared level, or a level on the selected melody's note. */
  public static function setDynamicLevel(string $level, bool $shared): void {
    if ($shared) {
      self::$score->setScoreDynamicLevel(self::$score->start(self::$bar) + self::$slot, $level);
    } else {
      $index = ControllerSheet::currentNoteIndex();
      if ($index === null) {
        throw new \DomainException('Select a note before setting its dynamic level.');
      }
      self::$score->setDynamicLevel($index, $level);
    }
    self::$displayedReadout = '';
    ControllerSheet::focusCursor();
  }

  public static function sharedDynamicLevel(): string {
    $time = self::$score->start(self::$bar) + self::$slot;
    foreach (self::$score->scoreDynamics as $mark) {
      if ($mark['time'] === $time) {
        return $mark['level'];
      }
    }
    return '';
  }

  public static function sharedWedgeDirection(): string {
    $time = ControllerSelection::selectionStateForSheet()['start'] ?? self::$score->start(self::$bar) + self::$slot;
    foreach (self::$score->scoreDynamics as $mark) {
      if ($mark['time'] === $time) {
        return $mark['direction'];
      }
    }
    return '';
  }

  /** Cycle one shared wedge across the selected time or to the next score note. */
  public static function cycleScoreDynamic(): void {
    $range = ControllerSelection::selectionStateForSheet();
    if ($range === null) {
      $index = ControllerSheet::currentNoteIndex();
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
    ControllerSheet::focusCursor();
  }

  /** Cycle the selected note through the four signed trill intervals. */
  public static function cycleTrill(): void {
    $index = ControllerSheet::currentNoteIndex();
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
    ControllerSheet::focusCursor();
    ControllerSheet::invalidateSheet();
  }

  /** Insert an exact duplicate after the selected note. */
  public static function duplicateNote(): void {
    $index = ControllerSheet::currentNoteIndex();
    if ($index === null) {
      ControllerNavigation::status('Select a note before inserting another.');
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
    ControllerSheet::clearSelection();
    ControllerSheet::focusCursor();
  }

  /** Insert a one-beat note or gap at the cursor's left edge. */
  public static function insertInverseBeat(): void {
    [self::$bar, self::$slot, $inserted] = self::$score->insertInverseBeat(
      self::$melody, self::$bar, self::$slot, self::$octave, self::$pitchPosition);
    ControllerSheet::clearSelection();
    self::$cursorLength = 2;
    self::$cursorLengthExplicit = $inserted === null;
    ControllerSheet::focusCursor();
  }

  /** Delete the selected note, or close the visible empty span in this melody. */
  public static function deleteAtCursor(): void {
    if (($range = ControllerSelection::selectionStateForSheet()) !== null) {
      $containsNotes = RangeEdit::copy(self::$score, $range)['notes'] !== [];
      ControllerSelection::applyRangeScore($containsNotes ? RangeEdit::clear(self::$score, $range) : RangeEdit::close(self::$score, $range),
        !$containsNotes, $containsNotes ? null : $range['start']);
      return;
    }
    $index = ControllerSheet::currentNoteIndex();
    if ($index !== null) {
      $before = self::$score->notes[$index];
      self::$score->delete($index);
      ControllerNavigation::changedNote($before, null);
      self::$cursorLengthExplicit = false;
      ControllerSheet::focusCursor();
      return;
    }
    self::$score->resizeGapAndShift(self::$melody, self::$bar, self::$slot, ControllerNoteEditing::cursorLength(), 0);
    ControllerSheet::clearSelection();
    ControllerSheet::syncNoteLength();
    ControllerSheet::focusCursor();
  }

  /** Delete the preceding note and its following gap in the active melody. */
  public static function deleteBeforeCursor(): void {
    $current = ControllerSheet::currentNoteIndex();
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
      ControllerNavigation::status('No note before the cursor.');
      return;
    }
    $note = self::$score->notes[$previous];
    self::$score->deleteAndClose($previous);
    self::$bar = $note['bar'];
    self::$slot = $note['slot'];
    ControllerNavigation::changedNote($note, null);
    self::$cursorLength = 1;
    ControllerSheet::syncNoteLength();
    ControllerSheet::focusCursor();
  }
}
