<?php

define('APP_DIR', dirname(__DIR__));
define('APP_NAMESPACE', 'MADSCORE');
require APP_DIR . '/SPTK/App.php';

function expectSession(bool $condition, string $message): void {
  if (!$condition) {
    throw new \RuntimeException($message);
  }
}

$score = new MADSCORE\Score\Score(12);
$score->title = 'Work in progress';
$score->ensureBar(3);
$score->setSectionMusic(0, ['scale' => 'Major', 'homeNote' => 'C']);
$score->place(1, 2, 3, 6, 5, 2);
$selection = new MADSCORE\App\MelodySelection();
$selection->extend(12, 19, 1);
MADSCORE\App\Files::restorePath(sys_get_temp_dir() . '/madscore-session-score.json');
foreach (['score' => $score, 'project' => MADSCORE\Score\Project::fromScore($score),
  'activeItemId' => 'main', 'bar' => 2, 'slot' => 3, 'octave' => 6, 'melody' => 1,
  'cursorLength' => 2, 'cursorLengthExplicit' => true, 'pitchPosition' => 5, 'firstBar' => 1,
  'firstRow' => 2, 'view' => 'sheet', 'selection' => $selection,
  'lastChordSettings' => ['main:0' => ['count' => 3, 'series' => 2,
    'degree' => null, 'inversion' => 1,
    'music' => ['scale' => 'Major', 'homeNote' => 'C']]]] as $name => $value) {
  (new \ReflectionProperty(MADSCORE\App\Controller::class, $name))->setValue(null, $value);
}
$capture = new \ReflectionMethod(MADSCORE\App\Controller::class, 'sessionState');
$restore = new \ReflectionMethod(MADSCORE\App\Controller::class, 'restoreSession');
$path = tempnam(sys_get_temp_dir(), 'madscore-session-');
try {
  $state = $capture->invoke(null);
  MADSCORE\App\Session::save($state, $path);
  expectSession(MADSCORE\App\Session::load($path) === $state
    && $state['project']['items'][0]['id'] === 'main'
    && !isset($state['project']['items'][0]['score']['title']),
    'The session file preserves the project and editor state.');
  (new \ReflectionProperty(MADSCORE\App\Controller::class, 'score'))->setValue(null, new MADSCORE\Score\Score(12));
  $restore->invoke(null, MADSCORE\App\Session::load($path));
  expectSession($capture->invoke(null) === $state, 'Restoring the session returns the score, cursor, viewport, and selection.');
  expectSession((new \ReflectionMethod(MADSCORE\App\Controller::class,
    'previousChordSettings'))->invoke(null)['series'] === 2,
    'The last chord settings remain available after session restore.');
  expectSession(MADSCORE\App\Files::currentPath() === $state['filePath'],
    'Restoring the session retains the named file for Write.');
  $whole = $state;
  $whole['selection']['scope'] = 'score';
  $restore->invoke(null, $whole);
  expectSession($capture->invoke(null)['selection']['scope'] === 'score',
    'A whole-score selection survives session restore.');
  $restore->invoke(null, $state);
  $invalid = $state;
  $invalid['cursor']['bar'] = 99;
  try {
    $restore->invoke(null, $invalid);
    throw new \RuntimeException('An invalid session cursor was accepted.');
  } catch (\DomainException $error) {
    expectSession($capture->invoke(null) === $state, 'A rejected session leaves the current editor state intact.');
  }
  $invalidChord = $state;
  $invalidChord['chordSettings']['main:0']['count'] = 8;
  try {
    $restore->invoke(null, $invalidChord);
    throw new \RuntimeException('Invalid saved chord settings were accepted.');
  } catch (\DomainException $error) {
    expectSession($capture->invoke(null) === $state,
      'Invalid saved chord settings leave the editor state intact.');
  }
  $oldFormat = $state;
  $oldFormat['score'] = $score->data();
  unset($oldFormat['project']);
  try {
    $restore->invoke(null, $oldFormat);
    throw new \RuntimeException('The old session format was accepted.');
  } catch (\DomainException $error) {
    expectSession($capture->invoke(null) === $state,
      'An old session format leaves the current editor state intact.');
  }
  $motifProject = MADSCORE\Score\Project::fromScore($score);
  $motifId = $motifProject->create();
  $motifScore = $motifProject->score($motifId);
  $motifScore->place(0, 0, 0, 4, 4, 2);
  $motifProject->setScore($motifId, $motifScore);
  $motifState = $state;
  $motifState['project'] = $motifProject->data();
  $motifState['activeItemId'] = $motifId;
  $motifState['cursor']['bar'] = 0;
  $motifState['cursor']['slot'] = 0;
  $motifState['cursor']['melody'] = 0;
  $motifState['cursor']['octave'] = 4;
  $motifState['cursor']['pitch'] = 4;
  $motifState['firstBar'] = 0;
  $motifState['selection'] = null;
  $restore->invoke(null, $motifState);
  expectSession($capture->invoke(null) === $motifState,
    'The session restores the active motif and its separate score.');
  $restore->invoke(null, $state);
  file_put_contents($path, '{broken');
  MADSCORE\App\Session::backupInvalid($path);
  $backups = glob($path . '.invalid-*');
  expectSession(count($backups) === 1 && file_get_contents($backups[0]) === '{broken', 'A damaged session is preserved before replacement.');
} finally {
  unlink($path);
  foreach (glob($path . '.invalid-*') as $backup) {
    unlink($backup);
  }
}

echo "Session checks passed\n";
