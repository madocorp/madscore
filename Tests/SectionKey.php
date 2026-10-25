<?php

define('APP_DIR', dirname(__DIR__));
define('APP_NAMESPACE', 'MADSCORE');
require APP_DIR . '/SPTK/App.php';

use MADSCORE\App\Controller;
use MADSCORE\App\ControllerComposer;
use MADSCORE\App\ScoreHistory;
use MADSCORE\Score\Project;
use MADSCORE\Score\Score;

function expectSectionKey(bool $condition, string $message): void {
  if (!$condition) {
    throw new RuntimeException($message);
  }
}

$screens = (new SPTK\XmlParser\XmlParser())->windows[0]['screens'];
$editor = array_values(array_filter($screens, static fn ($screen): bool => $screen->id === 'editor'))[0];
$editor->measureGrid(new SPTK\Layout\Tile(0, 0, 120, 38));
expectSectionKey($editor->widget('scoreSectionKey')->hotkey() === 'k'
  && $editor->widget('motifSectionKey')->hotkey() === 'k'
  && $editor->widget('sectionKeySet') !== null
  && $editor->widget('sectionKeyClear') !== null,
  'Both Composer views expose the same key editor.');
$scorePanel = $editor->layout->findTile('composerScorePanel');
expectSectionKey($scorePanel->pathTo($editor->layout->findTile('scoreBuild'))
    === $scorePanel->pathTo($editor->layout->findTile('scoreSectionKey'))
  && $scorePanel->pathTo($editor->layout->findTile('scoreToMotif'))
    === $scorePanel->pathTo($editor->layout->findTile('scoreSectionKey')),
  'The score Composer places Key beside Motif editor and Build score.');
$buttonLabel = new ReflectionProperty(SPTK\Widgets\Button\Button::class, 'label');
expectSectionKey($buttonLabel->getValue($editor->widget('scoreSectionKey')) === 'Key'
  && $buttonLabel->getValue($editor->widget('motifSectionKey')) === 'Key',
  'Both Composer buttons are named Key.');
expectSectionKey($editor->widget('sectionScalePicker')->values()
    === array_keys(MADSCORE\Score\MotifScoreGenerator::SCALES)
  && $editor->widget('sectionHomeNotePicker')->values()
    === array_keys(MADSCORE\Score\MotifScoreGenerator::HOME_NOTES),
  'Section key pickers offer every named scale and home note.');
expectSectionKey($editor->show('composerScorePanel') && $editor->restore('composerScorePanel')
  && $editor->show('sectionKeyPanel')
  && $editor->layout->findTile('sectionKeyPanel')->overwriting()
    === $editor->layout->findTile('noteBox')
  && $editor->restore('sectionKeyPanel') && $editor->show('composerPanel')
  && $editor->restore('composerPanel'),
  'The section key tile replaces either Composer view and can return to it.');

$score = new Score();
$score->ensureBar(4);
$score->setSectionLeader(2, true);
$project = Project::fromScore($score);
foreach (['score' => $score, 'project' => $project, 'activeItemId' => 'main',
  'composerKeyBar' => 1, 'history' => new ScoreHistory($score)] as $name => $value) {
  (new ReflectionProperty(Controller::class, $name))->setValue(null, $value);
}
$apply = new ReflectionMethod(ControllerComposer::class, 'applySectionKey');
$music = ['scale' => 'Dorian', 'homeNote' => 'F#'];
$apply->invoke(null, $music);
expectSectionKey($score->sectionMusic(0) === null && $score->sectionMusic(1) === $music
  && $score->sectionMusic(2) === $music && $score->sectionMusic(4) === $music
  && !$score->bars[1]['sectionLeader'] && isset($score->bars[1]['music']),
  'A key starts at its bar and continues across section boundaries.');
expectSectionKey(Project::fromData(MADSCORE\App\ControllerNoteEditing::projectData())
  ->score('main')->sectionMusic(1) === $music,
  'A bar key change is saved with the project.');
$history = (new ReflectionProperty(Controller::class, 'history'))->getValue();
expectSectionKey($history->undo($score) && $score->sectionMusic(1) === null,
  'A key change is one undoable edit.');
$apply->invoke(null, $music);
$score->setSectionMusic(3, $music);
expectSectionKey(!array_key_exists('music', $score->bars[3]),
  'Repeating the active key keeps only its first change.');
$other = ['scale' => 'Minor', 'homeNote' => 'A'];
$score->setSectionMusic(3, $other);
expectSectionKey($score->sectionMusic(2) === $music && $score->sectionMusic(3) === $other
  && $score->sectionMusic(4) === $other,
  'A different key ends the preceding key at its bar.');
$score->setSectionMusic(4, $other);
expectSectionKey(!array_key_exists('music', $score->bars[4]),
  'A second repeat of a changed key is omitted.');
$score->setSectionMusic(3, $music);
expectSectionKey(!array_key_exists('music', $score->bars[3])
  && $score->sectionMusic(4) === $music,
  'Replacing a change with the preceding key removes the redundant change.');
(new ReflectionProperty(Controller::class, 'composerKeyBar'))->setValue(null, 3);
$apply->invoke(null, null);
expectSectionKey($score->sectionMusic(2) === $music && $score->sectionMusic(3) === null
  && $score->sectionMusic(4) === null && array_key_exists('music', $score->bars[3]),
  'Clear key starts an unkeyed span without erasing the preceding key.');
expectSectionKey(Score::fromData($score->data())->data() === $score->data(),
  'Non-section key changes and clear markers survive save and load.');
$repeated = $score->data();
$repeated['bars'][4]['music'] = null;
expectSectionKey(!array_key_exists('music', Score::fromData($repeated)->bars[4]),
  'Loading an older repeated key keeps its first change only.');
$score->setSectionLeader(2, false);
expectSectionKey($score->sectionMusic(2) === $music,
  'Removing a section boundary leaves the key unchanged.');
$lastKey = new Score();
$lastKey->ensureBar(1);
$lastKey->setSectionMusic(1, $music);
expectSectionKey(!$lastKey->removeEmptyLastBar(1),
  'An otherwise empty last bar is kept when a key change begins there.');

$motifId = $project->create();
$motif = $project->score($motifId);
$motif->ensureBar(1);
$project->setScore($motifId, $motif);
(new ReflectionProperty(Controller::class, 'composerKeyBar'))->setValue(null, 1);
foreach (['score' => $motif, 'activeItemId' => $motifId,
  'history' => new ScoreHistory($motif)] as $name => $value) {
  (new ReflectionProperty(Controller::class, $name))->setValue(null, $value);
}
$apply->invoke(null, $music);
expectSectionKey($motif->sectionMusic(0) === null && $motif->sectionMusic(1) === $music
  && Project::fromData(MADSCORE\App\ControllerNoteEditing::projectData())
    ->score($motifId)->sectionMusic(1) === $music,
  'The motif Composer edits and saves the chosen motif section.');

echo "Section key checks passed\n";
