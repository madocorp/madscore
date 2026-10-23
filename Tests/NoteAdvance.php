<?php

define('APP_DIR', dirname(__DIR__));
define('APP_NAMESPACE', 'MADSCORE');
require APP_DIR . '/SPTK/App.php';

use MADSCORE\App\Controller;
use MADSCORE\Score\Score;

function expectNoteAdvance(bool $condition, string $message): void {
  if (!$condition) {
    throw new RuntimeException($message);
  }
}

$advance = new ReflectionMethod(\MADSCORE\App\ControllerNoteEditing::class, 'advanceAfterInsertedNote');
$setCursor = static function (Score $score): void {
  foreach (['score' => $score, 'melody' => 0, 'bar' => 0, 'slot' => 0,
    'cursorLength' => 2, 'cursorLengthExplicit' => false] as $name => $value) {
    (new ReflectionProperty(Controller::class, $name))->setValue(null, $value);
  }
};

$withGap = new Score();
$withGap->place(0, 0, 0, 4, 4, 2);
$withGap->place(0, 0, 4, 4, 6, 2);
$setCursor($withGap);
$advance->invoke(null);
expectNoteAdvance(Controller::currentBar() === 0 && \MADSCORE\App\ControllerNoteEditing::cursorSlot() === 2
  && \MADSCORE\App\ControllerNoteEditing::cursorLength() === 2,
  'After a new note, the cursor reaches the next editable gap.');

$adjacent = new Score();
$adjacent->place(0, 0, 0, 4, 4, 2);
$adjacent->place(0, 0, 2, 4, 6, 2);
$setCursor($adjacent);
$advance->invoke(null);
expectNoteAdvance(Controller::currentBar() === 0 && \MADSCORE\App\ControllerNoteEditing::cursorSlot() === 2,
  'After a new note, the cursor reaches an adjacent next note.');

$atEnd = new Score();
$atEnd->place(0, 0, 0, 4, 4, 8);
$setCursor($atEnd);
$advance->invoke(null);
expectNoteAdvance(Controller::currentBar() === 1 && \MADSCORE\App\ControllerNoteEditing::cursorSlot() === 0,
  'A new note at the score end advances into the next bar.');

echo "Note advance checks passed\n";
