<?php

define('APP_DIR', dirname(__DIR__));
define('APP_NAMESPACE', 'MADSCORE');
require APP_DIR . '/SPTK/App.php';

function expectSession(bool $condition, string $message): void {
  if (!$condition) {
    throw new \RuntimeException($message);
  }
}

$score = new MADSCORE\Score();
$score->title = 'Work in progress';
$score->ensureBar(3);
$score->place(1, 2, 3, 6, 5, 2);
$selection = new MADSCORE\MelodySelection();
$selection->extend(12, 19, 1);
foreach (['score' => $score, 'bar' => 2, 'slot' => 3, 'octave' => 6, 'melody' => 1,
  'cursorLength' => 2, 'cursorLengthExplicit' => true, 'pitchPosition' => 5, 'firstBar' => 1, 'selection' => $selection] as $name => $value) {
  (new \ReflectionProperty(MADSCORE\Controller::class, $name))->setValue(null, $value);
}
$capture = new \ReflectionMethod(MADSCORE\Controller::class, 'sessionState');
$restore = new \ReflectionMethod(MADSCORE\Controller::class, 'restoreSession');
$path = tempnam(sys_get_temp_dir(), 'madscore-session-');
try {
  $state = $capture->invoke(null);
  MADSCORE\Session::save($state, $path);
  expectSession(MADSCORE\Session::load($path) === $state, 'The session file preserves the score and editor state.');
  (new \ReflectionProperty(MADSCORE\Controller::class, 'score'))->setValue(null, new MADSCORE\Score());
  $restore->invoke(null, MADSCORE\Session::load($path));
  expectSession($capture->invoke(null) === $state, 'Restoring the session returns the score, cursor, viewport, and selection.');
  $invalid = $state;
  $invalid['cursor']['bar'] = 99;
  try {
    $restore->invoke(null, $invalid);
    throw new \RuntimeException('An invalid session cursor was accepted.');
  } catch (\DomainException $error) {
    expectSession($capture->invoke(null) === $state, 'A rejected session leaves the current editor state intact.');
  }
  file_put_contents($path, '{broken');
  MADSCORE\Session::backupInvalid($path);
  $backups = glob($path . '.invalid-*');
  expectSession(count($backups) === 1 && file_get_contents($backups[0]) === '{broken', 'A damaged session is preserved before replacement.');
} finally {
  unlink($path);
  foreach (glob($path . '.invalid-*') as $backup) {
    unlink($backup);
  }
}

echo "Session checks passed\n";
