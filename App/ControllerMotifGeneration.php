<?php

namespace MADSCORE\App;

use MADSCORE\Score\MelodyRange;
use MADSCORE\Score\MotifScoreGenerator;
use MADSCORE\Score\MotifVersions;
use MADSCORE\Score\Pitch;
use SPTK\App;
use SPTK\Events\EventContext;

/** Motif versions, generation, and regeneration. */
final class ControllerMotifGeneration extends ControllerState {
  public static function showMotifVersionPicker(EventContext $event): void {
    if (!ControllerComposer::composerFocused()
      || self::$activeItemId === 'main') {
      return;
    }
    if (!isset(self::$score->melodies[self::$melody]['motif'])) {
      ControllerNavigation::status('Generate the original motif before adding a version.');
      return;
    }
    $screen = Controller::screen('editor');
    self::$motifVersionSource = self::$score->sectionNumber(self::$bar) - 1;
    $target = self::$score->sectionNumber(count(self::$score->bars) - 1);
    $screen->widget('motifVersionSourceTarget')->setText('Source: '
      . self::motifSectionLabel(self::$motifVersionSource)
      . ' → Target: ' . self::motifSectionLabel($target));
    $screen->restore('composerPanel');
    self::$motifVersionPickerOpen = true;
    Controller::showEditorPanel('motifVersionPicker');
    self::$window->refreshLayout();
  }

  public static function acceptMotifVersionAlgorithm(EventContext $event): void {
    if (!self::$motifVersionPickerOpen) {
      return;
    }
    $screen = Controller::screen('editor');
    $algorithm = $screen->widget('motifVersionList')->activeValue();
    if (!isset(MotifVersions::ALGORITHMS[$algorithm])) {
      return;
    }
    self::$motifVersionPickerOpen = false;
    self::$motifVersionAlgorithm = $algorithm;
    $screen->restore('motifVersionPicker');
    if (in_array($algorithm, ['invert', 'reverse'], true)) {
      self::appendMotifVersion([]);
      return;
    }
    $parameter = match ($algorithm) {
      'keep-opening' => ['Beats to keep', (string)max(1,
        intdiv(self::$score->melodies[self::$melody]['motif']['beats'], 2))],
      'new-ending' => ['Last notes to change', '2'],
      'transpose' => ['Scale steps (−3 to 3, except 0)', '1'],
      'rhythm' => ['Beats to shift splits', '1'],
    };
    $target = self::$score->sectionNumber(count(self::$score->bars) - 1);
    $screen->widget('motifVersionParamsSourceTarget')->setText('Source: '
      . self::motifSectionLabel(self::$motifVersionSource)
      . ' → Target: ' . self::motifSectionLabel($target));
    $screen->widget('motifVersionTitle')->setText(MotifVersions::ALGORITHMS[$algorithm]);
    $screen->widget('motifVersionValue')->setLabel($parameter[0]);
    $screen->widget('motifVersionValue')->setValue($parameter[1]);
    Controller::showEditorPanel('motifVersionParams');
    self::$window->refreshLayout();
  }

  public static function cancelMotifVersion(?EventContext $event = null): void {
    self::$motifVersionPickerOpen = false;
    self::$motifVersionAlgorithm = null;
    $screen = Controller::screen('editor');
    $screen->restore('motifVersionPicker');
    $screen->restore('motifVersionParams');
    if (self::$composerOpen) {
      Controller::showEditorPanel('composerPanel');
    }
    self::$window->refreshLayout();
  }

  public static function createMotifVersion(EventContext $event): void {
    $algorithm = self::$motifVersionAlgorithm;
    if ($algorithm === null) {
      return;
    }
    $raw = trim(Controller::screen('editor')->widget('motifVersionValue')->getValue());
    if (preg_match('/^-?[0-9]+$/D', $raw) !== 1) {
      ControllerNavigation::status('Enter a whole number for the version parameter.');
      return;
    }
    $key = match ($algorithm) {
      'keep-opening' => 'keepBeats',
      'new-ending' => 'replaceNotes',
      'transpose' => 'steps',
      'rhythm' => 'shiftBeats',
    };
    self::appendMotifVersion([$key => (int)$raw]);
  }

  private static function appendMotifVersion(array $parameters): void {
    $algorithm = self::$motifVersionAlgorithm;
    if ($algorithm === null) {
      return;
    }
    try {
      $result = MotifVersions::append(self::$score, self::$melody,
        ['algorithm' => $algorithm, 'source' => self::$motifVersionSource,
          'parameters' => $parameters]);
    } catch (\DomainException $error) {
      ControllerNavigation::status($error->getMessage());
      if ($parameters === []) {
        self::cancelMotifVersion();
      }
      return;
    }
    self::$score = $result['score'];
    $section = 0;
    foreach (self::$score->bars as $bar => $settings) {
      if ($settings['sectionLeader'] && $section++ === $result['version']) {
        self::$bar = $bar;
        break;
      }
    }
    self::$slot = 0;
    self::$columns = ControllerSheet::fitColumns();
    self::$firstBar = self::firstBarForNewSection(self::$firstBar, self::$bar,
      count(self::$score->bars) - 1, self::$columns);
    ControllerSheet::clearSelection();
    self::$history?->observe(self::$score);
    ControllerSheet::refreshSelectedBarFields();
    ControllerSheet::refreshMelodyFields();
    ControllerSheet::refreshCursorReadout();
    ControllerComposer::closeComposer();
    Controller::screen('editor')->activateLeaf(self::$sheets['editor']);
    ControllerSheet::invalidateSheet();
    self::$window->refreshLayout();
    ControllerNavigation::status('Created ' . self::$activeItemId . $result['version'] . ' with '
      . MotifVersions::ALGORITHMS[$algorithm] . '.');
  }

  /** Keep the new section where it is if visible; otherwise place its end at the right edge. */
  private static function firstBarForNewSection(int $firstBar, int $sectionStart,
    int $lastBar, int $columns): int {
    if ($sectionStart >= $firstBar && $lastBar < $firstBar + $columns) {
      return $firstBar;
    }
    return max(0, $lastBar - $columns + 1);
  }

  public static function motifSectionLabel(int $index): string {
    return self::$activeItemId . ($index === 0 ? '' : $index);
  }

  public static function contextNameAccepted(EventContext $event): void {
    if (self::$activeItemId === 'main') {
      return;
    }
    try {
      self::$project->setName(self::$activeItemId, (string)$event->widget?->getValue());
      ControllerComposer::refreshComposerItemField();
    } catch (\DomainException $error) {
      ControllerNavigation::status($error->getMessage());
    }
    self::refreshContextHeader();
  }

  public static function refreshContextHeader(): void {
    $screen = Controller::screen('editor');
    if (self::$activeItemId === 'main') {
      $screen->restore('contextHeader');
      return;
    }
    $item = self::$project->item(self::$activeItemId);
    $music = ControllerChords::currentMusic() ?? [];
    $screen->widget('contextId')->setText('Motif ' . $item['id']);
    $screen->widget('contextName')->setValue($item['name'] ?? $item['id']);
    $screen->widget('contextInfo')->setText(
      (isset($music['scale']) ? $music['scale'] : '')
      . (isset($music['homeNote']) ? ' · Home ' . $music['homeNote'] : ''));
    $screen->show('contextHeader', false);
  }

  public static function regenerateComposerFromSheet(bool $confirmed = false): void {
    if (!Controller::isDesign() || self::$activeItemId === 'main') {
      return;
    }
    if (!isset(self::$score->melodies[self::$melody]['motif'])) {
      ControllerNavigation::status('No generated motif in this melody. Use Composer to generate one.');
      return;
    }
    $section = self::$score->sectionNumber(self::$bar) - 1;
    if (!$confirmed) {
      $extras = MotifVersions::regenerationExtras(self::$score, self::$melody, $section);
      if ($extras !== []) {
        self::confirmRegeneration($extras,
          static function(): void { self::regenerateComposerFromSheet(true); });
        return;
      }
    }
    $barOffset = self::$bar - self::$score->sectionStart(self::$bar);
    $slot = self::$slot;
    try {
      $rebuilt = MotifVersions::regenerateSection(self::$score, self::$melody, $section);
    } catch (\DomainException $error) {
      ControllerNavigation::status($error->getMessage());
      return;
    }
    self::$score = $rebuilt;
    $number = 0;
    $sectionStart = 0;
    $sectionEnd = count($rebuilt->bars);
    foreach ($rebuilt->bars as $bar => $settings) {
      if ($settings['sectionLeader']) {
        if ($number === $section) {
          $sectionStart = $bar;
        } else if ($number === $section + 1) {
          $sectionEnd = $bar;
          break;
        }
        $number++;
      }
    }
    self::$bar = min($sectionStart + $barOffset, $sectionEnd - 1);
    self::$slot = min($slot, 2 * self::$score->beats(self::$bar));
    self::$lastCursorEndBar = self::$bar;
    self::$columns = ControllerSheet::fitColumns();
    ControllerSheet::clearSelection();
    ControllerSheet::syncNoteLength();
    $pitch = MelodyRange::cursorPitch(self::$score, self::$melody, self::$bar, self::$slot);
    if ($pitch !== null) {
      [self::$octave, self::$pitchPosition] = $pitch;
    }
    self::$history?->observe(self::$score);
    self::refreshContextHeader();
    ControllerSheet::refreshMelodyFields();
    ControllerSheet::focusCursor(false);
    self::$window->refreshLayout();
    ControllerNavigation::status('Regenerated ' . self::$activeItemId
      . ($section === 0 ? '' : $section) . ' and its derivations.');
  }

  public static function regenerateComposerShortcut(EventContext $event): bool {
    if (!Controller::isDesign() || self::$composerOpen) {
      return false;
    }
    if (!$event->input->key->repeat) {
      self::regenerateComposerFromSheet();
    }
    return true;
  }

  public static function composeMotif(): void {
    if (!self::$composerOpen) {
      return;
    }
    $screen = Controller::screen('editor');
    $raw = trim($screen->widget('composerBeats')->getValue());
    $rawNotes = trim($screen->widget('composerNotes')->getValue());
    if (!ctype_digit($raw)) {
      ControllerNavigation::status('Motif length must be a whole number from 3 to 32 beats.');
      return;
    }
    if (!ctype_digit($rawNotes)) {
      ControllerNavigation::status('Pitches to use must be a whole number from 1 to 7.');
      return;
    }
    $replace = isset(self::$score->melodies[self::$melody]['motif']) ? self::$melody : null;
    if ($replace === null) {
      foreach (self::$score->melodies as $index => $settings) {
        if (isset($settings['motif'])) {
          $replace = $index;
          break;
        }
      }
    }
    self::applyMotif((int)$raw, (int)$rawNotes, $replace,
      self::$composerScaleSelection === 'random' ? null : self::$composerScaleSelection,
      self::$composerHomeNoteSelection === 'random' ? null : self::$composerHomeNoteSelection,
      self::$composerTypeSelection, self::$composerGenerateTarget);
  }

  private static function applyMotif(int $beats, int $pitchCount, ?int $melody = null,
    ?string $scale = null, ?string $homeNote = null,
    string $type = 'random-step', int $target = 0, bool $confirmed = false): void {
    if (!$confirmed) {
      $extras = MotifVersions::regenerationExtras(self::$score,
        $target > 0 ? $melody ?? self::$melody : $melody, $target);
      if ($extras !== []) {
        self::confirmRegeneration($extras,
          static function() use ($beats, $pitchCount, $melody, $scale, $homeNote,
            $type, $target): void {
            self::applyMotif($beats, $pitchCount, $melody, $scale, $homeNote,
              $type, $target, true);
          });
        return;
      }
    }
    try {
      $result = $target > 0
        ? MotifVersions::generateAt(self::$score, $melody ?? self::$melody,
          $target, $beats, $pitchCount, $scale, $homeNote, $type)
        : MotifVersions::regenerate(self::$score, $melody, $beats, $pitchCount,
          $scale, $homeNote, type: $type);
    } catch (\DomainException $error) {
      ControllerNavigation::status($error->getMessage());
      return;
    }
    if ($target === 0) {
      $result['score']->melodies[$result['melody']]['motif']['label'] = self::$activeItemId;
      $result['label'] = self::$activeItemId;
      $motif = $result['score']->melodies[$result['melody']]['motif'];
      $result['score']->setSectionMusic(0, ['scale' => $motif['scale'],
        'homeNote' => $motif['homeNote']]);
    }
    if (self::$playbackMode !== null) {
      App::audio()->stopMusic();
      self::$playbackMode = null;
    }
    self::$score = $result['score'];
    self::$melody = $result['melody'];
    self::$bar = 0;
    if ($target > 0) {
      $section = 0;
      foreach (self::$score->bars as $bar => $settings) {
        if ($settings['sectionLeader'] && $section++ === $target) {
          self::$bar = $bar;
          break;
        }
      }
    }
    self::refreshContextHeader();
    self::$slot = 0;
    self::$octave = Pitch::MIDDLE_C_BAND;
    self::$pitchPosition = 4;
    self::$columns = ControllerSheet::fitColumns();
    $lastTargetBar = count(self::$score->bars) - 1;
    for ($bar = self::$bar + 1; $bar < count(self::$score->bars); $bar++) {
      if (self::$score->bars[$bar]['sectionLeader']) {
        $lastTargetBar = $bar - 1;
        break;
      }
    }
    self::$firstBar = $target === 0 ? 0 : self::firstBarForNewSection(
      self::$firstBar, self::$bar, $lastTargetBar, self::$columns);
    self::$cursorLength = 2;
    self::$cursorLengthExplicit = false;
    self::$lastCursorEndBar = self::$bar;
    ControllerSheet::clearSelection();
    self::$history?->observe(self::$score);
    ControllerSheet::refreshSelectedBarFields();
    ControllerSheet::refreshMelodyFields();
    ControllerSheet::refreshCursorReadout();
    if (self::$composerOpen) {
      ControllerComposer::closeComposer();
    }
    Controller::screen('editor')->activateLeaf(self::$sheets['editor']);
    ControllerSheet::invalidateSheet();
    self::$window->refreshLayout();
    ControllerNavigation::status('Generated ' . self::motifSectionLabel($target) . ' with '
      . MotifScoreGenerator::TYPES[$type] . '. Press M to play.');
  }

  /** Ask before regeneration replaces additions made after generating a motif. */
  private static function confirmRegeneration(array $extras, callable $regenerate): void {
    Controller::screen('editor')->statusBar->confirm(
      'Regenerate and discard ' . implode(', ', $extras) . '?', $regenerate,
      static function(): void { ControllerNavigation::status('Regeneration cancelled.'); });
  }
}
