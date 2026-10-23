<?php

define('APP_DIR', dirname(__DIR__));
define('APP_NAMESPACE', 'MADSCORE');
require APP_DIR . '/SPTK/App.php';

foreach (glob(APP_DIR . '/Layout/*.xml') as $layout) {
  preg_match_all('/action="([^"]+)"/', file_get_contents($layout), $matches);
  foreach ($matches[1] as $action) {
    if (str_contains($action, '::') && !is_callable($action)) {
      throw new RuntimeException(basename($layout) . ' has an invalid action: ' . $action);
    }
  }
}

$score = new MADSCORE\Score\Score();
(new ReflectionProperty(MADSCORE\App\Controller::class, 'score'))->setValue(null, $score);
$chordScore = (new ReflectionProperty(MADSCORE\App\ControllerChords::class, 'score'))->getValue();
$sheetScore = (new ReflectionProperty(MADSCORE\App\ControllerSheet::class, 'score'))->getValue();
if ($chordScore !== $score || $sheetScore !== $score) {
  throw new RuntimeException('Focused controllers must share the same score.');
}

echo "Controller binding checks passed\n";
