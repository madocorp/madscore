<?php

namespace MADSCORE\App;

use MADSCORE\Score\MotifScoreGenerator;
use MADSCORE\Score\MotifArrangement;
use MADSCORE\Score\Score;
use SPTK\Events\EventContext;
use SPTK\Events\KeyNormalizer;
use SPTK\SDLWrapper\SDL;

/** Composer panels, motif switching, and score building. */
final class ControllerComposer extends ControllerState {
  public static function openComposerShortcut(EventContext $event): bool {
    if ($event->input->key->repeat) {
      return true;
    }
    self::openComposer();
    return true;
  }

  public static function openComposer(): void {
    if (!Controller::isDesign() || self::$composerOpen || !ControllerSheet::commitEditorFields()) {
      return;
    }
    if (self::$chordOpen) {
      ControllerChords::closeChord();
    }
    $screen = Controller::screen('editor');
    $screen->restore('savePanel');
    $screen->restore('loadPanel');
    $screen->restore('exportPanel');
    $screen->restore('soundPicker');
    $motif = self::$score->melodies[self::$melody]['motif'] ?? null;
    if (self::$activeItemId !== 'main') {
      if ($motif !== null) {
        $screen->widget('composerBeats')->setValue((string)$motif['beats']);
        $screen->widget('composerNotes')->setValue((string)$motif['pitchCount']);
      } else {
        foreach (array_reverse(self::$score->melodies) as $settings) {
          if (isset($settings['motif'])) {
            $motif = $settings['motif'];
            break;
          }
        }
      }
    }
    self::$composerScaleSelection = $motif === null || ($motif['randomScale'] ?? false)
      ? 'random' : $motif['scale'];
    self::$composerHomeNoteSelection = $motif === null || ($motif['randomHomeNote'] ?? false)
      ? 'random' : ($motif['homeNote'] ?? 'C');
    self::$composerTypeSelection = $motif['type'] ?? 'random-step';
    self::refreshComposerChoices();
    foreach (['composerBeats', 'composerNotes', 'motifVersionValue'] as $field) {
      $screen->widget($field)->setInputInterceptor(static function (mixed $input) use ($field): bool {
        if ($input->type !== SDL::SDL_EVENT_KEY_DOWN
          || KeyNormalizer::normalize((int)$input->key->key, (int)$input->key->mod)
            !== SDL::KEY_ESCAPE) {
          return false;
        }
        if ($field === 'motifVersionValue') {
          ControllerMotifGeneration::cancelMotifVersion();
        } else {
          self::cancelGenerateComposer();
        }
        return true;
      });
    }
    self::$composerOpen = true;
    self::refreshComposerItemField();
    if (self::$activeItemId === 'main') {
      $screen->widget('scorePattern')->setValue(
        self::$project->pattern() ?? self::SCORE_PATTERN_HELP);
    }
    if ($screen->show(self::composerPanelId())) {
      self::$window->refreshLayout();
    }
    ControllerSheet::invalidateSheet();
  }

  public static function closeComposer(?EventContext $event = null): void {
    self::saveScorePattern();
    self::$composerOpen = false;
    self::$composerListOpen = false;
    self::$composerGenerateOpen = false;
    self::$composerChoiceOpen = null;
    self::$motifVersionPickerOpen = false;
    self::$motifVersionAlgorithm = null;
    $screen = Controller::screen('editor');
    $changed = $screen->restore('composerItems');
    $changed = $screen->restore('composerGeneratePanel') || $changed;
    $changed = $screen->restore('motifVersionPicker') || $changed;
    $changed = $screen->restore('motifVersionParams') || $changed;
    $changed = $screen->restore('composerScalePicker') || $changed;
    $changed = $screen->restore('composerHomeNotePicker') || $changed;
    $changed = $screen->restore('composerTypePicker') || $changed;
    foreach (['composerPanel', 'composerScorePanel'] as $panel) {
      $changed = $screen->restore($panel) || $changed;
    }
    if ($changed) {
      self::$window->refreshLayout();
    }
    ControllerSheet::invalidateSheet();
  }

  public static function closeComposerShortcut(EventContext $event): bool {
    if (self::$motifVersionPickerOpen || self::$motifVersionAlgorithm !== null) {
      ControllerMotifGeneration::cancelMotifVersion();
      return true;
    }
    if (self::$composerChoiceOpen !== null) {
      self::cancelComposerChoice();
      return true;
    }
    if (self::$composerListOpen) {
      self::cancelComposerItems();
      return true;
    }
    if (self::$composerGenerateOpen) {
      self::cancelGenerateComposer();
      return true;
    }
    if (!self::$composerOpen) {
      return false;
    }
    self::closeComposer();
    return true;
  }

  public static function composerViewShortcut(EventContext $event): bool {
    if (!self::$composerOpen) {
      return false;
    }
    if (self::$activeItemId !== 'main' && !self::$composerGenerateOpen
      && self::composerFocused()) {
      return false;
    }
    if (!$event->input->key->repeat) {
      self::closeComposer();
      Controller::screen('editor')->activateLeaf(self::$sheets['editor']);
      ControllerNavigation::toggleView($event);
    }
    return true;
  }

  public static function generateComposer(EventContext $event): void {
    if (self::$activeItemId === 'main' || !self::$composerGenerateOpen) {
      return;
    }
    ControllerMotifGeneration::composeMotif();
  }

  public static function showGenerateComposer(EventContext $event): void {
    if (!self::composerFocused() || self::$activeItemId === 'main') {
      return;
    }
    $screen = Controller::screen('editor');
    self::$composerGenerateTarget = self::$score->sectionNumber(self::$bar) - 1;
    $label = ControllerMotifGeneration::motifSectionLabel(self::$composerGenerateTarget);
    $screen->widget('composerGenerateTitle')->setText('Source: none → Target: ' . $label);
    $sectionStart = self::$score->sectionStart(self::$bar);
    $nextStart = count(self::$score->bars);
    for ($bar = $sectionStart + 1; $bar < count(self::$score->bars); $bar++) {
      if (self::$score->bars[$bar]['sectionLeader']) {
        $nextStart = $bar;
        break;
      }
    }
    $beats = intdiv(self::$score->start($nextStart) - self::$score->start($sectionStart), 2);
    $screen->widget('composerBeats')->setValue((string)max(3, min(32, $beats)));
    $settings = self::$score->bars[$sectionStart]['motifVersion'] ?? null;
    $sectionMusic = self::$score->sectionMusic($sectionStart);
    if (self::$composerGenerateTarget > 0 && $sectionMusic !== null) {
      self::$composerScaleSelection = $sectionMusic['scale'];
      self::$composerHomeNoteSelection = $sectionMusic['homeNote'];
    }
    if (($settings['algorithm'] ?? null) === 'generate') {
      $screen->widget('composerBeats')->setValue((string)$settings['parameters']['beats']);
      $screen->widget('composerNotes')->setValue((string)$settings['parameters']['pitchCount']);
      self::$composerTypeSelection = $settings['parameters']['type'];
      self::$composerScaleSelection = $settings['parameters']['scale'] ?? 'random';
      self::$composerHomeNoteSelection = $settings['parameters']['homeNote'] ?? 'random';
    } else if (self::$composerGenerateTarget === 0) {
      self::$composerTypeSelection = self::$score->melodies[self::$melody]['motif']['type']
        ?? 'random-step';
    }
    self::refreshComposerChoices();
    $screen->restore('composerPanel');
    self::$composerGenerateOpen = true;
    $screen->show('composerGeneratePanel');
    self::$window->refreshLayout();
  }

  public static function cancelGenerateComposer(?EventContext $event = null): void {
    if (!self::$composerGenerateOpen) {
      return;
    }
    self::$composerGenerateOpen = false;
    $screen = Controller::screen('editor');
    $screen->restore('composerGeneratePanel');
    if (self::$composerOpen) {
      $screen->show('composerPanel');
    }
    self::$window->refreshLayout();
  }

  private static function composerPanelId(): string {
    return self::$activeItemId === 'main' ? 'composerScorePanel' : 'composerPanel';
  }

  public static function composerFocused(): bool {
    if (!self::$composerOpen || self::$composerListOpen || self::$composerChoiceOpen !== null
      || self::$motifVersionPickerOpen || self::$motifVersionAlgorithm !== null) {
      return false;
    }
    $leaf = Controller::screen('editor')->selectedLeaf();
    return $leaf !== null && in_array($leaf->instance()->id(), [
      'scoreToMotif', 'scoreBuild',
      'composerToScore', 'composerItem', 'composerOpenGenerate', 'composerBeats',
      'composerNotes', 'composerType', 'composerScale', 'composerHomeNote', 'composerGenerate',
      'composerAddVersion',
      'scorePattern',
    ], true);
  }

  private static function refreshComposerChoices(): void {
    $screen = Controller::screen('editor');
    $screen->widget('composerType')->setLabel('Type: '
      . MotifScoreGenerator::TYPES[self::$composerTypeSelection]);
    $screen->widget('composerScale')->setLabel('Scale: '
      . (self::$composerScaleSelection === 'random' ? 'Random' : self::$composerScaleSelection));
    $screen->widget('composerHomeNote')->setLabel('Home note: '
      . (self::$composerHomeNoteSelection === 'random' ? 'Random' : self::$composerHomeNoteSelection));
  }

  private static function showComposerChoice(string $choice): void {
    if (!self::$composerGenerateOpen || !self::composerFocused()
      || self::$activeItemId === 'main') {
      return;
    }
    $screen = Controller::screen('editor');
    $id = match ($choice) {
      'scale' => 'composerScalePicker',
      'homeNote' => 'composerHomeNotePicker',
      'type' => 'composerTypePicker',
    };
    $screen->widget($id)->setValue(match ($choice) {
      'scale' => self::$composerScaleSelection,
      'homeNote' => self::$composerHomeNoteSelection,
      'type' => self::$composerTypeSelection,
    });
    $screen->restore('composerGeneratePanel');
    self::$composerChoiceOpen = $choice;
    $screen->show($id);
    self::$window->refreshLayout();
  }

  public static function showComposerScalePicker(EventContext $event): void {
    self::showComposerChoice('scale');
  }

  public static function showComposerHomeNotePicker(EventContext $event): void {
    self::showComposerChoice('homeNote');
  }

  public static function showComposerTypePicker(EventContext $event): void {
    self::showComposerChoice('type');
  }

  private static function cancelComposerChoice(): void {
    if (self::$composerChoiceOpen === null) {
      return;
    }
    $screen = Controller::screen('editor');
    $screen->restore(match (self::$composerChoiceOpen) {
      'scale' => 'composerScalePicker',
      'homeNote' => 'composerHomeNotePicker',
      'type' => 'composerTypePicker',
    });
    self::$composerChoiceOpen = null;
    $screen->show('composerGeneratePanel');
    self::$window->refreshLayout();
  }

  public static function acceptComposerScale(EventContext $event): void {
    if (self::$composerChoiceOpen !== 'scale') {
      return;
    }
    $value = Controller::screen('editor')->widget('composerScalePicker')->activeValue();
    if ($value !== 'random' && !isset(MotifScoreGenerator::SCALES[$value])) {
      return;
    }
    self::$composerScaleSelection = $value;
    self::refreshComposerChoices();
    self::cancelComposerChoice();
  }

  public static function acceptComposerHomeNote(EventContext $event): void {
    if (self::$composerChoiceOpen !== 'homeNote') {
      return;
    }
    $value = Controller::screen('editor')->widget('composerHomeNotePicker')->activeValue();
    if ($value !== 'random' && !isset(MotifScoreGenerator::HOME_NOTES[$value])) {
      return;
    }
    self::$composerHomeNoteSelection = $value;
    self::refreshComposerChoices();
    self::cancelComposerChoice();
  }

  public static function acceptComposerType(EventContext $event): void {
    if (self::$composerChoiceOpen !== 'type') {
      return;
    }
    $value = Controller::screen('editor')->widget('composerTypePicker')->activeValue();
    if (!isset(MotifScoreGenerator::TYPES[$value])) {
      return;
    }
    self::$composerTypeSelection = $value;
    self::refreshComposerChoices();
    self::cancelComposerChoice();
  }

  public static function composerSwitchMotif(EventContext $event): void {
    if (self::composerFocused()) {
      self::switchComposerItem(false);
    }
  }

  public static function composerSwitchScore(EventContext $event): void {
    if (self::composerFocused()) {
      self::switchComposerItem(true);
    }
  }

  public static function requestBuildScore(EventContext $event): void {
    if (!self::composerFocused() || self::$activeItemId !== 'main'
      || !ControllerSheet::commitEditorFields()) {
      return;
    }
    self::saveScorePattern();
    self::$pendingBuiltScore = null;
    self::$project->setScore('main', self::$score);
    try {
      self::$pendingBuiltScore = MotifArrangement::build(self::$project,
        Controller::screen('editor')->widget('scorePattern')->getValue());
    } catch (\DomainException $error) {
      ControllerNavigation::status($error->getMessage());
      return;
    }
    Controller::screen('editor')->statusBar->confirm(
      'Replace the main score with the motif pattern? Ctrl+Z can restore it.',
      self::confirmBuildScore(...), self::cancelBuildScore(...));
  }

  private static function cancelBuildScore(): void {
    self::$pendingBuiltScore = null;
  }

  private static function confirmBuildScore(): void {
    $built = self::$pendingBuiltScore;
    self::$pendingBuiltScore = null;
    if ($built === null || self::$activeItemId !== 'main') {
      return;
    }
    self::$score = $built;
    self::$history?->observe(self::$score);
    self::$bar = self::$slot = self::$melody = 0;
    self::$firstBar = self::$firstRow = 0;
    ControllerSheet::clearSelection();
    ControllerSheet::syncNoteLength();
    self::closeComposer();
    Controller::screen('editor')->activateLeaf(self::$sheets['editor']);
    ControllerSheet::refreshEditorFields();
    ControllerSheet::focusCursor();
    self::$window->refreshLayout();
    ControllerNavigation::status('Score built from motifs. Ctrl+Z restores the previous score.');
  }

  private static function switchComposerItem(bool $main): void {
    if (!self::$composerOpen || !ControllerSheet::commitEditorFields()) {
      return;
    }
    self::$project->setScore(self::$activeItemId, self::$score);
    $id = $main ? 'main' : (self::$recentMotifId ?? self::$project->first());
    if ($id === null) {
      $id = self::$project->create();
    }
    self::switchProjectItem($id);
  }

  private static function switchProjectItem(string $id): void {
    self::$project->setScore(self::$activeItemId, self::$score);
    if ($id !== 'main') {
      self::$recentMotifId = $id;
    }
    self::closeComposer();
    ControllerNoteEditing::loadProject(self::$project, $id);
  }

  /** Keep score Composer edits with the project, including unbuilt drafts. */
  public static function saveScorePattern(): void {
    if (!self::$composerOpen || self::$activeItemId !== 'main') {
      return;
    }
    $pattern = Controller::screen('editor')->widget('scorePattern')->getValue();
    if ($pattern !== self::SCORE_PATTERN_HELP || self::$project->pattern() !== null) {
      self::$project->setPattern($pattern);
    }
  }

  public static function refreshComposerItemField(): void {
    $item = self::$project->item(self::$activeItemId);
    $label = $item['id'] . ' · ' . ($item['name'] ?? $item['id']);
    Controller::screen('editor')->widget('composerItem')->setValue($label);
  }

  public static function showComposerItems(EventContext $event): void {
    if (!self::composerFocused()) {
      return;
    }
    if (self::$activeItemId === 'main') {
      return;
    }
    $items = [];
    foreach (self::$project->items() as $item) {
      if ($item['id'] !== 'main') {
        $items[] = ['value' => $item['id'], 'label' => $item['id'] . ' · ' . ($item['name'] ?? $item['id'])];
      }
    }
    $items[] = ['value' => '__create__', 'label' => 'Create new item'];
    $screen = Controller::screen('editor');
    $screen->widget('composerItems')->setItems($items);
    $screen->widget('composerItems')->setValue(self::$activeItemId);
    $screen->restore(self::composerPanelId());
    self::$composerListOpen = true;
    $screen->show('composerItems');
    self::$window->refreshLayout();
  }

  private static function cancelComposerItems(): void {
    self::$composerListOpen = false;
    $screen = Controller::screen('editor');
    $screen->restore('composerItems');
    $screen->show(self::composerPanelId());
    self::$window->refreshLayout();
  }

  public static function acceptComposerItem(EventContext $event): void {
    if (!self::$composerListOpen) {
      return;
    }
    $id = Controller::screen('editor')->widget('composerItems')->activeValue();
    if ($id === null) {
      return;
    }
    if ($id === '__create__') {
      try {
        $id = self::$project->create();
      } catch (\DomainException $error) {
        ControllerNavigation::status($error->getMessage());
        return;
      }
    }
    self::$composerListOpen = false;
    self::switchProjectItem($id);
  }
}
