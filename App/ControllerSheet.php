<?php

namespace MADSCORE\App;

use MADSCORE\Render\PagePainter;
use MADSCORE\Render\PagePreview;
use MADSCORE\Score\MelodyRange;
use MADSCORE\Score\Figure;
use MADSCORE\Score\Pitch;
use MADSCORE\Score\Sound;
use SPTK\Core\Style;
use SPTK\Layout\LayoutLeaf;
use SPTK\Layout\LayoutNode;
use SPTK\Widgets\Image\Image;

/** Sheet installation, cursor display, and field refresh. */
final class ControllerSheet extends ControllerState {
  /** Install one retained canvas in each screen's board. */
  public static function installSheet(string $view): void {
    $screen = Controller::screen($view);
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
      $widget->setTips('PDF preview.', 'Arrows and Page Up/Down scroll; + and - zoom; Space fits; B/N turn pages; F/L jump to first/last page; U/J change note size; H/K change beat width; V returns to editor.');
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
  public static function focusCursor(bool $record = true, bool $cursorOnly = false): void {
    if ($record) {
      self::$history?->observe(self::$score);
    }
    $barCount = count(self::$score->bars);
    self::syncTimelineBars();
    if (count(self::$score->bars) !== $barCount) {
      self::$history?->rebase(self::$score);
    }
    $barChanged = self::$displayedBar !== self::$bar;
    if ($barChanged) {
      self::refreshSelectedBarFields();
    }
    $readoutChanged = self::refreshCursorReadout();
    $previousFirstBar = self::$firstBar;
    self::alignTimeline();
    Controller::screen(self::$view)->activateLeaf(self::$sheet);
    self::invalidateSheet(!$cursorOnly || count(self::$score->bars) !== $barCount);
    if (self::$firstBar !== $previousFirstBar || $barChanged || $readoutChanged) {
      self::$window->refreshLayout();
    }
  }

  public static function focusWithoutHistory(): void {
    self::focusCursor(false, true);
  }

  /** Grow or trim trailing bars according to the cursor's final occupied position. */
  private static function syncTimelineBars(): void {
    if (!Controller::isDesign() || ControllerSelection::selectionStateForSheet() !== null) {
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

  /** Move by half a viewport when the cursor leaves it, avoiding a redraw per bar. */
  public static function alignTimeline(): void {
    $endBar = ControllerSelection::selectionStateForSheet() === null ? self::cursorEndBar() : self::$bar;
    $columns = max(1, self::$columns);
    if ($endBar < self::$firstBar || $endBar >= self::$firstBar + $columns) {
      self::$firstBar = max(0, $endBar - intdiv($columns, 2));
    }
  }

  /** Find the bar containing the last half-beat of the cursor. */
  public static function cursorEndBar(): int {
    $end = self::cursorState()['end'];
    $bar = self::$bar;
    while ($end > self::$score->start($bar + 1)) {
      $bar++;
    }
    return $bar;
  }

  /** Return the pitch and duration preview at the selected time. */
  public static function cursorState(): array {
    $index = self::currentNoteIndex();
    $start = $index === null ? self::$score->start(self::$bar) + self::$slot : self::$score->start(self::$score->notes[$index]['bar']) + self::$score->notes[$index]['slot'];
    return ['start' => $start, 'end' => $start + ControllerNoteEditing::cursorLength(), 'bar' => self::$bar, 'octave' => self::$octave, 'pitch' => self::$pitchPosition];
  }

  /** Return the selected melody's note beneath the time cursor. */
  public static function currentNoteIndex(): ?int {
    return self::$score->noteAt(self::$melody, self::$bar, self::$slot);
  }

  public static function selection(): MelodySelection {
    return self::$selection ??= new MelodySelection();
  }

  public static function clearSelection(): void {
    self::$selection?->clear();
  }

  /** Keep an edited spanning note selected at its true onset. */
  public static function selectNoteOnset(int $index): void {
    self::$bar = self::$score->notes[$index]['bar'];
    self::$slot = self::$score->notes[$index]['slot'];
  }

  /** Load a selected note's width without moving the pitch cursor. */
  public static function syncNoteLength(): void {
    self::$cursorLengthExplicit = false;
    $index = self::currentNoteIndex();
    if ($index === null) {
      return;
    }
    $note = self::$score->notes[$index];
    self::$cursorLength = $note['length'];
  }

  /** Keep an unplaced cursor inside this melody's four octave range. */
  public static function assertPreviewBand(int $octave, int $position): void {
    MelodyRange::bands(self::$score, self::$melody, $octave, $position);
  }

  /** Mark the retained sheet surface for repainting on the next frame. */
  public static function invalidateSheet(bool $content = true): void {
    if (Controller::isDesign()) {
      $canvas = self::$sheet->instance();
      if ($content && $canvas instanceof SheetCanvas) {
        $canvas->invalidateContent();
      } else {
        $canvas->invalidate();
      }
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
        (int)round(PagePainter::A4_WIDTH_POINTS * 2), (int)round(PagePainter::A4_HEIGHT_POINTS * 2),
        Controller::project());
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
  public static function fitColumns(): int {
    $beats = self::$score->bars[self::$bar]['beats'];
    $minimum = max(240, $beats * 38);
    return max(1, min(12, intdiv(max(1, self::$window->pixelWidth() - 80), $minimum)));
  }

  /** Populate the fields above the sheet from the loaded score. */
  public static function refreshEditorFields(): void {
    $screen = Controller::screen('editor');
    Controller::screen('settings')->widget('playbackSound')->setLabel('Playback: '
      . (self::$playbackSound === 'midi' ? 'MIDI' : 'Built-in'));
    foreach (['title', 'author', 'catalog'] as $field) {
      $screen->widget($field)->setValue(self::$score->$field);
    }
    self::refreshSelectedBarFields();
    self::refreshMelodyFields();
    self::refreshCursorReadout();
  }

  /** Show the settings of the bar under the score cursor. */
  public static function refreshSelectedBarFields(): void {
    $screen = Controller::screen('editor');
    $music = ControllerChords::currentMusic();
    $screen->widget('barSettingsTitle')->setText('[R] Bar ' . (self::$bar + 1)
      . ($music === null ? '' : ' (' . $music['scale'] . ' ' . $music['homeNote'] . ')'));
    $screen->widget('tempo')->setValue(self::$score->bars[self::$bar]['tempo']);
    $screen->widget('beats')->setValue((string)self::$score->beats(self::$bar));
    $screen->widget('barNote')->setValue(self::$score->bars[self::$bar]['note']);
    $screen->widget('repeats')->setValue((string)self::$score->sectionRepeats(self::$bar));
    $screen->widget('section')->setLabel('Section start: ' . (self::$score->bars[self::$bar]['sectionLeader'] ? 'Yes' : 'No'));
    self::$displayedBar = self::$bar;
  }

  /** Show the active melody's saved sound and display settings. */
  public static function refreshMelodyFields(): void {
    $screen = Controller::screen('editor');
    $settings = self::$score->melodies[self::$melody];
    $screen->widget('selectedMelody')->setText('[Y] Melody ' . (self::$melody + 1)
      . (isset($settings['motif']) ? ' · Motif ' . $settings['motif']['label'] : '')
      . (self::$score->melodyMuted(self::$melody) ? ' (muted)' : ''));
    self::prepareSoundPicker();
    $screen->widget('soundPicker')->setValue($settings['instrument']);
    $screen->widget('sound')->setValue(Sound::label($settings['instrument']));
    $screen->widget('volume')->setValue((string)$settings['volume']);
    $screen->widget('color')->setValue($settings['color']);
    $screen->widget('muteMelody')->setLabel('Mute: ' . (self::$score->melodyMuted(self::$melody) ? 'Yes' : 'No'));
  }

  public static function prepareSoundPicker(): void {
    $picker = Controller::screen('editor')->widget('soundPicker');
    if (count($picker->items()) !== 175) {
      $picker->setItems(Sound::items());
    }
  }

  /** Put note and cursor pitches in their headings, with cursor length below. */
  public static function refreshCursorReadout(): bool {
    $index = self::currentNoteIndex();
    [$letter, $alter] = Pitch::spelling(self::$pitchPosition);
    $cursorTitle = 'Cursor ' . $letter . ($alter === 1 ? '#' : '') . self::$octave;
    $title = 'Gap';
    if ($index !== null) {
      $note = self::$score->notes[$index];
      $count = count(self::$score->chordAt(self::$melody, self::$bar, self::$slot));
      $title = ($count > 1 ? 'Chord (' . $count . ') base ' : 'Note ')
        . $note['pitch'] . ($note['alter'] === 1 ? '#' : ($note['alter'] === -1 ? 'b' : '')) . $note['octave'];
    }
    $length = ControllerNoteEditing::cursorLength();
    $beats = intdiv($length, 2) . ($length % 2 ? '.5' : '');
    $lengthText = 'Length ' . $beats . ($length === 2 ? ' beat' : ' beats');
    $sharedLevel = ControllerNoteMarks::sharedDynamicLevel();
    $sharedWedge = ControllerNoteMarks::sharedWedgeDirection();
    $readout = $title . "\n" . $cursorTitle . "\n" . $lengthText . "\n" . $sharedLevel . "\n" . $sharedWedge . "\n" . ($index === null ? '' : json_encode([
      $note['articulation'] ?? 'ordinary', $note['slurToNext'] ?? false, $note['glissandoToNext'] ?? false,
      $note['fermata'] ?? false, $note['trill'] ?? 0, $note['finger'] ?? '', $note['dynamicToNext'] ?? '',
      $note['dynamicLevel'] ?? '', ...(isset($note['figure']) ? [$note['figure']] : []),
    ]));
    if ($readout === self::$displayedReadout) {
      return false;
    }
    Controller::screen('editor')->widget('noteTitle')->setText('[T] Note · [C] Composer · [O] Chord');
    Controller::screen('editor')->widget('currentNoteTitle')->setText($title);
    Controller::screen('editor')->widget('cursorTitle')->setText($cursorTitle);
    Controller::screen('editor')->widget('cursorLength')->setText($lengthText);
    Controller::screen('editor')->widget('staccato')->setLabel($index === null ? 'Staccato: —'
      : 'Staccato: ' . (($note['articulation'] ?? 'ordinary') === 'staccato' ? 'Yes' : 'No'));
    Controller::screen('editor')->widget('fermata')->setLabel($index === null ? 'Fermata: —'
      : 'Fermata: ' . ($note['fermata'] ? 'Yes' : 'No'));
    Controller::screen('editor')->widget('slur')->setLabel($index === null ? 'Link to next: —'
      : 'Link to next: ' . (($note['slurToNext'] ?? false) ? 'Yes' : 'No'));
    Controller::screen('editor')->widget('glissando')->setLabel($index === null ? 'Glissando: —'
      : 'Glissando: ' . (($note['glissandoToNext'] ?? false) ? 'Yes' : 'No'));
    Controller::screen('editor')->widget('dynamic')->setLabel('All wedge: ' . match ($sharedWedge) {
        'crescendo' => '+',
        'decrescendo' => '-',
        default => '=',
      });
    Controller::screen('editor')->widget('trill')->setLabel($index === null ? 'Trill: —'
      : 'Trill: ' . (($note['trill'] ?? 0) === 0 ? 'Off' : (($note['trill'] > 0 ? '+' : '') . $note['trill'])));
    Controller::screen('editor')->widget('finger')->setValue($index === null ? '' : (string)($note['finger'] ?? ''));
    Controller::screen('editor')->widget('figure')->setValue($index === null ? '' : Figure::format($note['figure'] ?? null));
    Controller::screen('editor')->widget('dynamicLevel')->setLabel('All levels: ' . ($sharedLevel === '' ? '—' : $sharedLevel));
    self::$displayedReadout = $readout;
    return true;
  }

  /** Apply the visible fields before a file or bar action. */
  public static function commitEditorFields(): bool {
    try {
      self::applyEditorBeats();
      self::applyEditorTempo();
      self::applyEditorBarNote();
      self::applyEditorRepeats();
      self::applyMelodyVolume();
      self::applyMelodyColor();
      self::applyFinger();
      self::applyFigure();
      $screen = Controller::screen('editor');
      foreach (['title', 'author', 'catalog'] as $field) {
        self::$score->$field = str_replace("\t", ' ', $screen->widget($field)->getValue());
      }
      self::$history?->observe(self::$score);
      return true;
    } catch (\Throwable $error) {
      if (self::$score->melodyMuted(self::$melody)) {
        self::refreshMelodyFields();
        $index = self::currentNoteIndex();
        Controller::screen('editor')->widget('finger')->setValue($index === null ? ''
          : (string)(self::$score->notes[$index]['finger'] ?? ''));
      }
      ControllerNavigation::status($error->getMessage());
      return false;
    }
  }

  /** Apply the current bar's tempo annotation. */
  public static function applyEditorTempo(): void {
    $tempo = str_replace("\t", ' ', Controller::screen('editor')->widget('tempo')->getValue());
    self::$score->setTempo(self::$bar, $tempo);
  }

  /** Apply the optional fingering in the selected note tile. */
  public static function applyFinger(): void {
    $raw = strtoupper(trim(Controller::screen('editor')->widget('finger')->getValue()));
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
      self::$history?->observe(self::$score);
    }
    Controller::screen('editor')->widget('finger')->setValue($raw);
    self::refreshCursorReadout();
    self::invalidateSheet();
  }

  /** Apply the visible ornament or tuplet field to the selected note. */
  public static function applyFigure(): void {
    $raw = strtoupper(trim(Controller::screen('editor')->widget('figure')->getValue()));
    $index = self::currentNoteIndex();
    $saved = $index === null ? '' : Figure::format(self::$score->notes[$index]['figure'] ?? null);
    if ($raw === $saved) {
      return;
    }
    if ($index === null) {
      throw new \DomainException('Select a note before setting its figure.');
    }
    self::$score->setFigure($index, Figure::parse($raw, self::$score->notes[$index]));
    self::$history?->observe(self::$score);
    Controller::screen('editor')->widget('figure')->setValue(Figure::format(self::$score->notes[$index]['figure'] ?? null));
    self::refreshCursorReadout();
    self::invalidateSheet();
  }

  /** Store the current bar's annotation for the editor and print view. */
  public static function applyEditorBarNote(): void {
    $note = str_replace("\t", ' ', Controller::screen('editor')->widget('barNote')->getValue());
    self::$score->setBarNote(self::$bar, $note);
  }

  /** Store the number of extra plays for the selected section. */
  public static function applyEditorRepeats(): void {
    $raw = trim(Controller::screen('editor')->widget('repeats')->getValue());
    if (!ctype_digit($raw) || (int)$raw > 8) {
      throw new \DomainException('Section repeats must be a whole number from 0 to 8.');
    }
    self::$score->setSectionRepeats(self::$bar, (int)$raw);
    self::invalidateSheet();
  }

  /** Validate and store a whole-number melody volume percentage. */
  public static function applyMelodyVolume(): void {
    $raw = trim(Controller::screen('editor')->widget('volume')->getValue());
    if (!ctype_digit($raw) || (int)$raw > 100) {
      throw new \DomainException('Melody volume must be a whole number from 0 to 100.');
    }
    if ((int)$raw === self::$score->melodies[self::$melody]['volume']) {
      return;
    }
    self::$score->setMelodyVolume(self::$melody, (int)$raw);
    self::$history?->observe(self::$score);
  }

  /** Validate and apply the active melody's hex display color. */
  public static function applyMelodyColor(): void {
    $color = trim(Controller::screen('editor')->widget('color')->getValue());
    if (strtolower($color) === self::$score->melodies[self::$melody]['color']) {
      return;
    }
    self::$score->setMelodyColor(self::$melody, $color);
    self::$history?->observe(self::$score);
    Controller::screen('editor')->widget('color')->setValue(self::$score->melodies[self::$melody]['color']);
    self::invalidateSheet();
  }

  /** Validate and apply the selected bar's beats-per-bar field. */
  public static function applyEditorBeats(): void {
    $raw = trim(Controller::screen('editor')->widget('beats')->getValue());
    if (!ctype_digit($raw) || (int)$raw < 1 || (int)$raw > 32) {
      throw new \DomainException('Beats per bar must be a whole number from 1 to 32.');
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
