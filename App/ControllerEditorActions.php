<?php

namespace MADSCORE\App;

use SPTK\Events\EventContext;

/** Editor fields, instrument choices, and file actions. */
final class ControllerEditorActions extends ControllerState {
  /** Commit one editor field when its input is released. */
  public static function editorFieldAccepted(EventContext $event): void {
    $field = $event->widget?->id();
    try {
      if ($field === 'beats') {
        ControllerSheet::applyEditorBeats();
      } else if ($field === 'tempo') {
        ControllerSheet::applyEditorTempo();
      } else if ($field === 'barNote') {
        ControllerSheet::applyEditorBarNote();
      } else if ($field === 'repeats') {
        ControllerSheet::applyEditorRepeats();
      } else if ($field === 'volume') {
        ControllerSheet::applyMelodyVolume();
      } else if ($field === 'color') {
        ControllerSheet::applyMelodyColor();
      } else if ($field === 'finger') {
        ControllerSheet::applyFinger();
      } else if (in_array($field, ['title', 'author', 'catalog'], true)) {
        self::$score->$field = str_replace("\t", ' ', $event->widget->getValue());
      }
      self::$history?->observe(self::$score);
    } catch (\Throwable $error) {
      if ($field === 'volume' || $field === 'color') {
        ControllerSheet::refreshMelodyFields();
      } else if ($field === 'finger') {
        $index = ControllerSheet::currentNoteIndex();
        Controller::screen('editor')->widget('finger')->setValue($index === null
          ? '' : (string)(self::$score->notes[$index]['finger'] ?? ''));
      }
      ControllerNavigation::status($error->getMessage());
    }
  }

  /** Show the sound choices in place of the melody settings tile. */
  public static function showSoundPicker(EventContext $event): void {
    if (self::$composerOpen) {
      ControllerComposer::closeComposer();
    }
    if (self::$score->melodyMuted(self::$melody)) {
      ControllerNavigation::status('This melody is muted and read-only. Unmute it to edit.');
      return;
    }
    $screen = Controller::screen('editor');
    ControllerSheet::prepareSoundPicker();
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
        ControllerNavigation::status($error->getMessage());
      }
      ControllerSheet::refreshMelodyFields();
    }
  }

  /** Show one file form over the Note tile. */
  public static function showFilePanel(string $id): void {
    if (self::$composerOpen) {
      ControllerComposer::closeComposer();
    }
    $screen = Controller::screen('editor');
    $other = $id === 'savePanel' ? 'loadPanel' : 'savePanel';
    $screen->restore($other);
    if ($screen->show($id)) {
      self::$window->refreshLayout();
    }
  }

  public static function hideFilePanel(string $id): void {
    if (Controller::screen('editor')->restore($id)) {
      self::$window->refreshLayout();
    }
  }

  /** Start or join a section at the selected bar. */
  public static function toggleSection(): void {
    $bar = self::$bar;
    if ($bar === 0) {
      ControllerNavigation::status('The first bar always starts a section.');
      return;
    }
    $leader = !self::$score->bars[$bar]['sectionLeader'];
    self::$score->setSectionLeader($bar, $leader);
    self::$history?->observe(self::$score);
    ControllerSheet::refreshSelectedBarFields();
    ControllerSheet::refreshCursorReadout();
    ControllerSheet::invalidateSheet();
    self::$window->refreshLayout();
    ControllerNavigation::status($leader ? 'Section starts at bar ' . ($bar + 1) . '.' : 'Bar ' . ($bar + 1) . ' joins the previous section.');
  }

  /** Ctrl+S always chooses a directory and file name. */
  public static function saveDialog(EventContext $event): void {
    if (ControllerSheet::commitEditorFields()) {
      Files::showSave();
    }
  }

  /** Ctrl+W writes to the current file. */
  public static function write(EventContext $event): void {
    if (ControllerSheet::commitEditorFields()) {
      Files::write($event);
    }
  }

  /** Ctrl+O browses for a score. */
  public static function loadDialog(EventContext $event): void {
    if (ControllerSheet::commitEditorFields()) {
      Files::showLoad();
    }
  }

  /** Export the score as a paginated PDF. */
  public static function exportPdf(EventContext $event): void {
    if (!Controller::isDesign() || ControllerSheet::commitEditorFields()) {
      Files::exportPdf($event);
    }
  }

  /** Export the score as standard MIDI. */
  public static function exportMidi(EventContext $event): void {
    if (!Controller::isDesign() || ControllerSheet::commitEditorFields()) {
      Files::exportMidi($event);
    }
  }
}
