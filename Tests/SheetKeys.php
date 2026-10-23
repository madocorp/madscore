<?php

define('APP_DIR', dirname(__DIR__));
define('APP_NAMESPACE', 'MADSCORE');
require APP_DIR . '/SPTK/App.php';

use SPTK\SDLWrapper\SDL;

/** Check one Space or Return sheet-editing result. */
function expectSheetKey(bool $condition, string $message): void {
  if (!$condition) {
    throw new RuntimeException($message);
  }
}

/** Make a key event for the active sheet canvas. */
function sheetKey(int $key, int $mod = 0): object {
  return (object)['type' => SDL::SDL_EVENT_KEY_DOWN, 'key' => (object)['key' => $key, 'mod' => $mod, 'repeat' => false]];
}

/** Set one controller field in the headless editor fixture. */
function sheetState(string $name, mixed $value): void {
  (new ReflectionProperty(MADSCORE\App\Controller::class, $name))->setValue(null, $value);
}

$screens = (new SPTK\XmlParser\XmlParser())->windows[0]['screens'];
$window = (new ReflectionClass(SPTK\Core\Window::class))->newInstanceWithoutConstructor();
(new ReflectionProperty(SPTK\Core\Window::class, 'screens'))->setValue($window, $screens);
$score = new MADSCORE\Score\Score();
foreach (['window' => $window, 'score' => $score, 'bar' => 0, 'slot' => 0, 'octave' => 3,
  'melody' => 0, 'pitchPosition' => 2, 'cursorLength' => 2, 'cursorLengthExplicit' => false,
  'firstBar' => 0, 'columns' => 1, 'displayedBar' => 0, 'lastCursorEndBar' => 0,
  'selection' => new MADSCORE\App\MelodySelection()] as $name => $value) {
  sheetState($name, $value);
}
(new ReflectionMethod(MADSCORE\App\ControllerSheet::class, 'installSheet'))->invoke(null, 'editor');
$sheet = (new ReflectionProperty(MADSCORE\App\Controller::class, 'sheets'))->getValue()['editor'];
sheetState('sheet', $sheet);
$canvas = $sheet->instance();
sheetState('displayedReadout', "[T] Gap\nCursor A#3\nLength 1 beat\n\n\n");
expectSheetKey($canvas->handleInput(sheetKey(SDL::KEY_RETURN)), 'Return handles an empty cursor.');
expectSheetKey(count($score->notes) === 1 && MADSCORE\Score\Pitch::position($score->notes[0]) === 2,
  'Return places the cursor pitch in a gap.');
sheetState('slot', 2);
sheetState('pitchPosition', 4);
sheetState('displayedReadout', "[T] Gap\nCursor C3\nLength 1 beat\n\n\n");
expectSheetKey($canvas->handleInput(sheetKey(SDL::KEY_SPACE)), 'Space handles an empty cursor.');
expectSheetKey(count($score->notes) === 2 && MADSCORE\Score\Pitch::position($score->notes[1]) === 4,
  'Space places the cursor pitch in a gap.');
sheetState('slot', 0);
sheetState('pitchPosition', 5);
sheetState('displayedReadout', "[T] Note C#3\nCursor C#3\nLength 1 beat\n\n\n[\"ordinary\",false,false,false,0,\"\",\"\",\"\"]");
expectSheetKey($canvas->handleInput(sheetKey(SDL::KEY_RETURN)), 'Return handles an existing note.');
expectSheetKey(count($score->notes) === 2 && MADSCORE\Score\Pitch::position($score->notes[0]) === 5,
  'Return changes the note to the cursor pitch.');
sheetState('pitchPosition', 2);
sheetState('displayedReadout', "[T] Note C#3\nCursor C#3\nLength 1 beat\n\n\n[\"ordinary\",false,false,false,0,\"\",\"\",\"\"]");
$before = $score->notes;
expectSheetKey($canvas->handleInput(sheetKey(SDL::KEY_SPACE)), 'Space handles an existing note.');
expectSheetKey($score->notes === $before && MADSCORE\App\ControllerSelection::cursorStateForSheet()['pitch'] === 5,
  'Space moves the pitch cursor to the note without changing the note.');
expectSheetKey($canvas->handleInput(sheetKey(ord('t')))
  && $window->screen('editor')->selectedLeaf()?->instance()?->id() === 'staccato',
  'T selects the Note tile rather than changing the note.');
expectSheetKey(!$canvas->handleInput(sheetKey(ord('w'), SDL::MOD_MODE)) && $score->notes[0]['trill'] === 0,
  'AltGr+W keydown does not trigger the W trill shortcut.');
$window->screen('editor')->statusBar->setScheduler(static function (): void {});
expectSheetKey((new ReflectionMethod(MADSCORE\App\EditorInput::class, 'text'))->invoke(null, '|')
  && $score->bars[0]['sectionLeader'],
  'The pipe shortcut reaches Section start and preserves the required first section.');
expectSheetKey($canvas->handleInput(sheetKey(ord('z'), SDL::MOD_CTRL))
  && str_contains($window->screen('editor')->statusBar->text(), 'Nothing to undo'),
  'Ctrl+Z reaches the selected melody undo command.');
expectSheetKey($canvas->handleInput(sheetKey(ord('y'), SDL::MOD_CTRL))
  && str_contains($window->screen('editor')->statusBar->text(), 'Nothing to redo'),
  'Ctrl+Y reaches the selected melody redo command.');
echo "Sheet key checks passed\n";
