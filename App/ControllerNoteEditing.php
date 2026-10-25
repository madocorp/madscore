<?php

namespace MADSCORE\App;

use MADSCORE\Render\PagePainter;
use MADSCORE\Render\PdfLayout;
use MADSCORE\Score\MelodyRange;
use MADSCORE\Score\Pitch;
use MADSCORE\Score\Project;
use MADSCORE\Score\Score;
use SPTK\App;
use SPTK\Events\EventContext;
use SPTK\SDLWrapper\SDL;

/** Undo, score loading, and note placement. */
final class ControllerNoteEditing extends ControllerState {
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
        ControllerNoteMarks::cycleDynamic();
      } catch (\DomainException $error) {
        ControllerNavigation::status($error->getMessage());
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
      ControllerNavigation::status('Cannot restore the score: ' . $error->getMessage());
      return;
    }
    if (!$changed) {
      ControllerNavigation::status($redo ? 'Nothing to redo.' : 'Nothing to undo.');
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
    ControllerSheet::clearSelection();
    ControllerSheet::syncNoteLength();
    self::$previewPage = min(self::$previewPage, PagePainter::pageCount(self::$score, Controller::project()) - 1);
    ControllerSheet::refreshMelodyFields();
    self::$displayedReadout = '';
    ControllerSheet::focusCursor();
    self::$window->refreshLayout();
  }

  /** Replace the document after a validated load or import. */
  public static function loadScore(Score $score): void {
    self::loadProject(Project::fromScore($score));
  }

  public static function loadProject(Project $project, string $itemId = 'main'): void {
    if (isset(self::$project) && self::$project === $project) {
      self::$itemCursors[self::$activeItemId] = self::cursorState();
    }
    self::$pendingDeleteMelody = null;
    self::$pendingBuiltScore = null;
    self::$composerOpen = false;
    self::$chordOpen = false;
    self::$chordChoiceOpen = null;
    Controller::screen('editor')->restore('chordScalePicker');
    Controller::screen('editor')->restore('chordHomeNotePicker');
    Controller::screen('editor')->restore('chordPanel');
    self::$composerListOpen = false;
    self::$composerGenerateOpen = false;
    self::$composerChoiceOpen = null;
    self::$composerKeyOpen = false;
    self::$composerKeyChoiceOpen = null;
    self::$composerGenerateTarget = 0;
    self::$motifVersionPickerOpen = false;
    self::$motifVersionAlgorithm = null;
    if (!isset(self::$project) || self::$project !== $project) {
      self::$recentMotifId = null;
      self::$lastChordSettings = [];
      self::$itemCursors = [];
    }
    if (self::$playbackMode !== null) {
      App::audio()->stopMusic();
      self::$playbackMode = null;
    }
    $score = $project->score($itemId);
    $layout = $project->pdfLayout();
    PdfLayout::configure($layout['noteSize'], $layout['beatSize']);
    self::$project = $project;
    self::$activeItemId = $itemId;
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
    if (isset(self::$itemCursors[$itemId])) {
      $cursor = self::$itemCursors[$itemId];
      self::$bar = min($cursor['bar'], count($score->bars) - 1);
      self::$slot = min($cursor['slot'], 2 * $score->beats(self::$bar));
      self::$melody = min($cursor['melody'], count($score->melodies) - 1);
      self::$octave = $cursor['octave'];
      self::$pitchPosition = $cursor['pitch'];
      self::$cursorLength = $cursor['length'];
      self::$cursorLengthExplicit = $cursor['lengthExplicit'];
      try {
        MelodyRange::bands($score, self::$melody, self::$octave, self::$pitchPosition);
      } catch (\DomainException) {
        self::$octave = MelodyRange::bands($score, self::$melody)[1];
      }
    }
    self::$firstBar = 0;
    self::$firstRow = 0;
    self::$previewPage = 0;
    self::$view = 'editor';
    self::$sheet = self::$sheets['editor'];
    self::$window->setCurrentScreenId('editor');
    self::$columns = ControllerSheet::fitColumns();
    ControllerSheet::alignTimeline();
    self::$lastCursorEndBar = min(ControllerSheet::cursorEndBar(), count($score->bars) - 1);
    Controller::screen('editor')->activateLeaf(self::$sheet);
    ControllerSheet::invalidateSheet();
    ControllerSheet::refreshEditorFields();
    ControllerMotifGeneration::refreshContextHeader();
    self::$window->refreshLayout();
  }

  public static function cursorState(): array {
    return ['bar' => self::$bar, 'slot' => self::$slot, 'octave' => self::$octave,
      'melody' => self::$melody, 'length' => self::$cursorLength,
      'lengthExplicit' => self::$cursorLengthExplicit, 'pitch' => self::$pitchPosition];
  }

  public static function validateCursorState(array $cursor, Score $score): void {
    $bar = $cursor['bar'] ?? null;
    if (!is_int($bar) || $bar < 0 || $bar >= count($score->bars)
      || !is_int($cursor['slot'] ?? null) || $cursor['slot'] < 0
      || $cursor['slot'] > 2 * $score->beats($bar)
      || !is_int($cursor['octave'] ?? null) || $cursor['octave'] < 0 || $cursor['octave'] > 8
      || !is_int($cursor['melody'] ?? null) || $cursor['melody'] < 0
      || $cursor['melody'] >= count($score->melodies)
      || !is_int($cursor['length'] ?? null) || $cursor['length'] < 1 || $cursor['length'] > 198
      || !is_bool($cursor['lengthExplicit'] ?? null)
      || !is_int($cursor['pitch'] ?? null) || $cursor['pitch'] < 1 || $cursor['pitch'] > 12) {
      throw new \DomainException('The previous item cursor is invalid.');
    }
  }

  public static function projectData(): array {
    ControllerComposer::saveScorePattern();
    self::$project->setScore(self::$activeItemId, self::$score);
    self::$project->setPdfLayout(PdfLayout::noteSize(), PdfLayout::beatSize());
    return self::$project->data();
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
    $index = ControllerSheet::currentNoteIndex();
    return $index === null
      ? (self::$cursorLengthExplicit ? self::$cursorLength : self::$score->freeLength(self::$melody, self::$bar, self::$slot, self::$cursorLength))
      : self::$score->notes[$index]['length'];
  }

  /** Change the preview width and repaint its old and new spans. */
  public static function setCursorLength(int $length): void {
    if ($length < 1 || $length > 198) {
      throw new \DomainException('Length must be between half a beat and 99 beats.');
    }
    $index = ControllerSheet::currentNoteIndex();
    if ($index !== null) {
      self::$score->resizeAndShift($index, $length);
      ControllerSheet::selectNoteOnset($index);
    } else {
      self::$score->resizeGapAndShift(self::$melody, self::$bar, self::$slot, self::cursorLength(), $length);
    }
    self::$cursorLength = $length;
    self::$cursorLengthExplicit = $index === null;
    ControllerSheet::clearSelection();
    ControllerSheet::focusCursor();
  }

  /** Choose a natural pitch, placing a note if the cursor is empty. */
  public static function setPitchLetter(string $letter): void {
    $position = Pitch::naturalPosition($letter);
    $index = ControllerSheet::currentNoteIndex();
    if ($index === null) {
      self::$pitchPosition = $position;
      self::dropNote();
      return;
    }
    $oldNote = self::$score->notes[$index];
    self::$score->setPitchPosition($index, $oldNote['octave'], $position);
    ControllerNavigation::changedNote($oldNote, self::$score->notes[$index]);
    self::$octave = $oldNote['octave'];
    ControllerSheet::selectNoteOnset($index);
    self::$pitchPosition = $position;
    ControllerSheet::focusCursor();
  }

  /** Move only the pitch cursor by one semitone. */
  public static function stepPitch(int $direction): void {
    [$octave, $position] = Pitch::step(self::$octave, self::$pitchPosition, $direction);
    ControllerSheet::assertPreviewBand($octave, $position);
    self::$octave = $octave;
    self::$pitchPosition = $position;
    ControllerSheet::focusWithoutHistory();
  }

  /** Place the preview note and optionally advance after a new insertion. */
  public static function dropNote(bool $advanceAfterInsert = false, bool $addTone = false): void {
    $index = self::$score->noteAt(self::$melody, self::$bar, self::$slot);
    $before = $index === null ? null : self::$score->notes[$index];
    $bar = $before['bar'] ?? self::$bar;
    $slot = $before['slot'] ?? self::$slot;
    $adding = $before !== null && ($addTone
      || count(self::$score->chordAt(self::$melody, $bar, $slot)) > 1);
    if ($adding) {
      $count = count(self::$score->notes);
      $index = self::$score->addChordTone(self::$melody, $bar, $slot,
        self::$octave, self::$pitchPosition, $before['length']);
      if (count(self::$score->notes) === $count) {
        return;
      }
      self::$score->forgetGeneratedChord($before['chord']['id'] ?? null);
      ControllerNavigation::changedNote(null, self::$score->notes[$index]);
    } else {
      $index = self::$score->place(self::$melody, $bar, $slot,
        self::$octave, self::$pitchPosition, self::cursorLength());
      ControllerNavigation::changedNote($before, self::$score->notes[$index]);
    }
    ControllerSheet::selectNoteOnset($index);
    self::$cursorLengthExplicit = false;
    if ($advanceAfterInsert && ($before === null || $addTone)) {
      self::advanceAfterInsertedNote();
    }
    ControllerSheet::focusCursor();
  }

  private static function advanceAfterInsertedNote(): void {
    [self::$bar, self::$slot] = self::$score->moveCursor(
      self::$melody, self::$bar, self::$slot, 1);
    ControllerSheet::syncNoteLength();
  }

  /** Place a note in a gap, or move the pitch cursor to an existing note. */
  public static function followNotePitch(): void {
    $index = ControllerSheet::currentNoteIndex();
    if ($index === null) {
      self::dropNote(true);
      return;
    }
    $note = self::$score->notes[$index];
    self::$octave = $note['octave'];
    self::$pitchPosition = Pitch::position($note);
    ControllerSheet::focusWithoutHistory();
  }
}
