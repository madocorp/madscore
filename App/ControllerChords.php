<?php

namespace MADSCORE\App;

use MADSCORE\Score\ChordGenerator;
use MADSCORE\Score\HarmonicContext;
use MADSCORE\Score\MelodyRange;
use MADSCORE\Score\MotifScoreGenerator;
use MADSCORE\Score\Score;
use SPTK\Events\EventContext;
use SPTK\Events\KeyNormalizer;
use SPTK\SDLWrapper\SDL;
use SPTK\Widgets\Input\Input;

/** Chord settings, placement, and rerolls. */
final class ControllerChords extends ControllerState {
  /** Use the motif's key or the current score section's explicit key. */
  public static function currentMusic(): ?array {
    if (self::$activeItemId === 'main') {
      return self::$score->sectionMusic(self::$bar);
    }
    return self::$score->sectionMusic(0);
  }

  /** Chord choices belong to one motif or one main-score section. */
  private static function chordSettingsKey(): string {
    return self::$activeItemId === 'main'
      ? 'main:' . self::$score->sectionStart(self::$bar)
      : self::$activeItemId;
  }

  private static function previousChordSettings(): ?array {
    $saved = self::$lastChordSettings[self::chordSettingsKey()] ?? null;
    return $saved !== null && $saved['music'] === self::currentMusic()
      ? $saved : null;
  }

  public static function openChordShortcut(EventContext $event): bool {
    if ($event->widget instanceof Input && $event->widget->editing()) {
      return false;
    }
    if (!$event->input->key->repeat) {
      self::openChord();
    }
    return true;
  }

  public static function rerollChordShortcut(EventContext $event): bool {
    if ($event->widget instanceof Input && $event->widget->editing()) {
      return false;
    }
    if (!$event->input->key->repeat) {
      self::rerollChord();
    }
    return true;
  }

  public static function openChord(): void {
    if (!Controller::isDesign() || self::$chordOpen || !ControllerSheet::commitEditorFields()) {
      return;
    }
    if (self::$composerOpen) {
      ControllerComposer::closeComposer();
    }
    $screen = Controller::screen('editor');
    $screen->restore('savePanel');
    $screen->restore('loadPanel');
    $screen->restore('exportPanel');
    $screen->restore('soundPicker');
    $music = self::currentMusic();
    $previous = self::previousChordSettings();
    $existing = ChordGenerator::groupAt(self::$score, self::$bar, self::$slot,
      self::$melody);
    $selected = self::$score->noteAt(self::$melody, self::$bar, self::$slot);
    $fixed = $selected !== null && $existing === null;
    if ($existing !== null) {
      foreach (self::$score->notes as $note) {
        if (($note['chord']['id'] ?? null) === $existing['id']
          && ($note['chord']['fixed'] ?? false) === true) {
          $fixed = true;
          break;
        }
      }
    }
    $screen->widget('chordTitle')->setText($fixed
      ? 'Chord generator · Keep melody note' : 'Chord generator');
    $count = $existing['count'] ?? $previous['count'] ?? 3;
    $screen->widget('chordNotes')->setValue((string)$count);
    $screen->widget('chordSeries')->setValue((string)($existing !== null
      ? 1 : ($previous['series'] ?? 1)));
    $screen->widget('chordDegree')->setValue((string)($previous['degree'] ?? 'random'));
    $inversion = $previous['inversion'] ?? null;
    $screen->widget('chordInversion')->setValue((string)($inversion !== null
      && $inversion < $count ? $inversion : 'random'));
    self::$chordScaleSelection = $music['scale'] ?? $existing['scale'] ?? '';
    self::$chordHomeNoteSelection = $music['homeNote'] ?? $existing['homeNote'] ?? '';
    self::refreshChordChoices();
    foreach (['chordNotes', 'chordSeries', 'chordDegree', 'chordInversion'] as $field) {
      $screen->widget($field)->setInputInterceptor(static function (mixed $input): bool {
        if ($input->type === SDL::SDL_EVENT_KEY_DOWN
          && KeyNormalizer::normalize((int)$input->key->key, (int)$input->key->mod)
            === SDL::KEY_ESCAPE) {
          self::closeChord();
          return true;
        }
        return false;
      });
    }
    self::$chordOpen = true;
    $screen->show('chordPanel');
    self::$window->refreshLayout();
  }

  private static function refreshChordChoices(): void {
    $screen = Controller::screen('editor');
    $screen->widget('chordScale')->setLabel('Scale: '
      . (self::$chordScaleSelection === '' ? 'Choose' : self::$chordScaleSelection));
    $screen->widget('chordHomeNote')->setLabel('Home note: '
      . (self::$chordHomeNoteSelection === '' ? 'Choose' : self::$chordHomeNoteSelection));
  }

  private static function showChordChoice(string $choice): void {
    if (!self::$chordOpen || self::$chordChoiceOpen !== null) {
      return;
    }
    $screen = Controller::screen('editor');
    $id = $choice === 'scale' ? 'chordScalePicker' : 'chordHomeNotePicker';
    $selected = $choice === 'scale'
      ? self::$chordScaleSelection : self::$chordHomeNoteSelection;
    $screen->widget($id)->setValue($selected !== '' ? $selected
      : ($choice === 'scale' ? 'Major' : 'C'));
    $screen->restore('chordPanel');
    self::$chordChoiceOpen = $choice;
    $screen->show($id);
    self::$window->refreshLayout();
  }

  public static function showChordScalePicker(EventContext $event): void {
    self::showChordChoice('scale');
  }

  public static function showChordHomeNotePicker(EventContext $event): void {
    self::showChordChoice('homeNote');
  }

  private static function cancelChordChoice(): void {
    if (self::$chordChoiceOpen === null) {
      return;
    }
    $screen = Controller::screen('editor');
    $screen->restore(self::$chordChoiceOpen === 'scale'
      ? 'chordScalePicker' : 'chordHomeNotePicker');
    self::$chordChoiceOpen = null;
    $screen->show('chordPanel');
    self::$window->refreshLayout();
  }

  public static function acceptChordScale(EventContext $event): void {
    if (self::$chordChoiceOpen !== 'scale') {
      return;
    }
    $value = Controller::screen('editor')->widget('chordScalePicker')->activeValue();
    if ($value === null || !isset(MotifScoreGenerator::SCALES[$value])) {
      return;
    }
    self::$chordScaleSelection = $value;
    self::refreshChordChoices();
    self::cancelChordChoice();
  }

  public static function acceptChordHomeNote(EventContext $event): void {
    if (self::$chordChoiceOpen !== 'homeNote') {
      return;
    }
    $value = Controller::screen('editor')->widget('chordHomeNotePicker')->activeValue();
    if ($value === null || !isset(MotifScoreGenerator::HOME_NOTES[$value])) {
      return;
    }
    self::$chordHomeNoteSelection = $value;
    self::refreshChordChoices();
    self::cancelChordChoice();
  }

  public static function closeChordShortcut(EventContext $event): bool {
    if (!self::$chordOpen) {
      return false;
    }
    if (self::$chordChoiceOpen !== null) {
      self::cancelChordChoice();
      return true;
    }
    self::closeChord();
    return true;
  }

  public static function closeChord(): void {
    self::$chordOpen = false;
    self::$chordChoiceOpen = null;
    $screen = Controller::screen('editor');
    $changed = $screen->restore('chordScalePicker');
    $changed = $screen->restore('chordHomeNotePicker') || $changed;
    $changed = $screen->restore('chordPanel') || $changed;
    if ($changed) {
      self::$window->refreshLayout();
    }
  }

  public static function generateChords(EventContext $event): void {
    if (!self::$chordOpen || !Controller::isDesign()) {
      return;
    }
    try {
      $count = self::chordNumber('chordNotes', 2, 7);
      $series = self::chordNumber('chordSeries', 1, 16);
      $degree = self::chordNumber('chordDegree', 1, 7, true);
      $inversion = self::chordNumber('chordInversion', 0, $count - 1, true);
      $music = HarmonicContext::validate([
        'scale' => self::$chordScaleSelection,
        'homeNote' => self::$chordHomeNoteSelection,
      ]);
    } catch (\DomainException $error) {
      ControllerNavigation::status($error->getMessage());
      return;
    }
    self::placeChord(['count' => $count, 'series' => $series,
      'degree' => $degree, 'inversion' => $inversion, 'music' => $music],
      $series);
  }

  /** Complete one chord edit and remember the settings for the current context. */
  private static function placeChord(array $settings, int $series): bool {
    try {
      $existing = ChordGenerator::groupAt(self::$score, self::$bar, self::$slot,
        self::$melody);
      $anchor = 20 + 12 * self::$octave + self::$pitchPosition;
      $next = ChordGenerator::place(self::$score, self::$bar, self::$slot,
        ControllerNoteEditing::cursorLength(), $settings['count'], $series, $settings['music'],
        $anchor, self::$melody, $settings['degree'], $settings['inversion'],
        replaceId: $existing['id'] ?? null);
      $next->setSectionMusic(self::$activeItemId === 'main' ? self::$bar : 0,
        $settings['music']);
    } catch (\DomainException $error) {
      ControllerNavigation::status($error->getMessage());
      return false;
    }
    self::$score = Score::fromData($next->data());
    self::$lastChordSettings[self::chordSettingsKey()] = $settings;
    self::followChordPitch();
    self::$history?->observe(self::$score);
    if (self::$chordOpen) {
      self::closeChord();
    }
    ControllerMotifGeneration::refreshContextHeader();
    ControllerSheet::refreshSelectedBarFields();
    ControllerSheet::refreshMelodyFields();
    ControllerSheet::focusCursor(false);
    self::$window->refreshLayout();
    ControllerNavigation::status('Placed ' . $series . ($series === 1 ? ' chord.' : ' chords.'));
    return true;
  }

  private static function chordNumber(string $field, int $minimum, int $maximum,
    bool $random = false): ?int {
    $raw = strtolower(trim(Controller::screen('editor')->widget($field)->getValue()));
    if ($random && $raw === 'random') {
      return null;
    }
    if (!ctype_digit($raw) || (int)$raw < $minimum || (int)$raw > $maximum) {
      $label = match ($field) {
        'chordNotes' => 'Notes per chord',
        'chordSeries' => 'Number of chords',
        'chordDegree' => 'Degree',
        'chordInversion' => 'Inversion',
        default => $field,
      };
      throw new \DomainException("$label must be a whole number from $minimum to $maximum"
        . ($random ? ', or random.' : '.'));
    }
    return (int)$raw;
  }

  private static function rerollChord(): void {
    if (!Controller::isDesign() || self::$chordOpen || self::$composerOpen
      || !ControllerSheet::commitEditorFields()) {
      return;
    }
    $existing = ChordGenerator::groupAt(self::$score, self::$bar, self::$slot,
      self::$melody);
    if ($existing === null) {
      $previous = self::previousChordSettings();
      if ($previous === null) {
        self::openChord();
        return;
      }
      self::placeChord($previous, 1);
      return;
    }
    try {
      $next = ChordGenerator::reroll(self::$score, self::$bar, self::$slot,
        melody: self::$melody);
    } catch (\DomainException $error) {
      ControllerNavigation::status($error->getMessage());
      return;
    }
    self::$score = $next;
    self::$lastChordSettings[self::chordSettingsKey()] ??= [
      'count' => $existing['count'], 'series' => 1,
      'degree' => null, 'inversion' => null,
      'music' => ['scale' => $existing['scale'], 'homeNote' => $existing['homeNote']],
    ];
    self::followChordPitch();
    self::$history?->observe(self::$score);
    ControllerSheet::focusCursor(false);
    self::$window->refreshLayout();
    ControllerNavigation::status('Changed the chord at the cursor.');
  }

  /** Keep the design cursor inside the active melody's visible pitch rows. */
  private static function followChordPitch(): void {
    $pitch = MelodyRange::cursorPitch(self::$score, self::$melody,
      self::$bar, self::$slot);
    if ($pitch !== null) {
      [self::$octave, self::$pitchPosition] = $pitch;
    }
  }
}
