<?php

define('APP_DIR', dirname(__DIR__));
define('APP_NAMESPACE', 'MADSCORE');
require APP_DIR . '/SPTK/App.php';

use SPTK\SDLWrapper\SDL;

/** Check a sheet editor focus transition. */
function expectEditorFocus(bool $condition, string $message): void {
  if (!$condition) {
    throw new RuntimeException($message);
  }
}

/** Make an Escape key event for the screen dispatcher. */
function editorEscape(): object {
  return (object)['type' => SDL::SDL_EVENT_KEY_DOWN, 'key' => (object)['key' => SDL::KEY_ESCAPE, 'mod' => 0, 'repeat' => false]];
}

/** Make one SDL text event for an unbound printable key. */
function editorText(string $text): object {
  $buffer = FFI::new('char[64]');
  FFI::memcpy($buffer, $text, strlen($text));
  return (object)['type' => SDL::SDL_EVENT_TEXT_INPUT, 'text' => (object)['text' => $buffer]];
}

/** Make one arrow event for tile navigation. */
function editorArrow(int $key): object {
  return (object)['type' => SDL::SDL_EVENT_KEY_DOWN,
    'key' => (object)['key' => $key, 'mod' => 0, 'repeat' => false]];
}

$screens = (new SPTK\XmlParser\XmlParser())->windows[0]['screens'];
$window = (new ReflectionClass(SPTK\Core\Window::class))->newInstanceWithoutConstructor();
(new ReflectionProperty(SPTK\Core\Window::class, 'screens'))->setValue($window, $screens);
(new ReflectionProperty(MADSCORE\App\Controller::class, 'window'))->setValue(null, $window);
(new ReflectionProperty(MADSCORE\App\Controller::class, 'score'))->setValue(null, new MADSCORE\Score\Score());
$install = new ReflectionMethod(MADSCORE\App\Controller::class, 'installSheet');
$install->invoke(null, 'editor');
$sheet = (new ReflectionProperty(MADSCORE\App\Controller::class, 'sheets'))->getValue()['editor'];
$editor = $window->screen('editor');
$editor->measureGrid(new SPTK\Layout\Tile(0, 0, 120, 38));
$tempo = $editor->layout->findTile('tempo');
$tempo->instance()->setValue('120');
$editor->selectLeaf($tempo);
expectEditorFocus($editor->activeLeaf() === null, 'Selecting a field leaves the screen in navigation mode.');
expectEditorFocus($editor->handleEvent(editorEscape()), 'Escape is handled in editor navigation mode.');
expectEditorFocus($editor->activeLeaf() === $sheet, 'Escape activates the sheet editor.');
$editor->release();
$editor->activateLeaf($tempo);
$editor->handleEvent(editorEscape());
expectEditorFocus($editor->activeLeaf() === null, 'Escape from an active field releases it without reactivating the sheet.');
expectEditorFocus(!$editor->handleEvent(editorText('_')) && $editor->activeLeaf() === null,
  'Underscore has no Cursor panel shortcut in navigation mode.');
$editor->activateLeaf($sheet);
expectEditorFocus(!$editor->handleEvent(editorText('_')) && $editor->activeLeaf() === $sheet,
  'Underscore leaves the active score canvas alone.');
$project = MADSCORE\Score\Project::fromScore(new MADSCORE\Score\Score());
$motifId = $project->create();
(new ReflectionProperty(MADSCORE\App\Controller::class, 'project'))->setValue(null, $project);
(new ReflectionProperty(MADSCORE\App\Controller::class, 'activeItemId'))->setValue(null, $motifId);
$editor->statusBar->setScheduler(static function (int $durationMs, callable $dismiss): void {});
expectEditorFocus($editor->handleEvent((object)['type' => SDL::SDL_EVENT_KEY_DOWN,
    'key' => (object)['key' => ord('c'), 'mod' => SDL::MOD_SHIFT, 'repeat' => false]])
  && str_contains($editor->statusBar->text(), 'No generated motif'),
  'Shift+C reaches motif regeneration from the sheet.');
$editor->statusBar->clear();
$editor->selectLeaf($tempo);
expectEditorFocus($editor->handleEvent((object)['type' => SDL::SDL_EVENT_KEY_DOWN,
    'key' => (object)['key' => ord('c'), 'mod' => SDL::MOD_SHIFT, 'repeat' => false]])
  && str_contains($editor->statusBar->text(), 'No generated motif'),
  'Shift+C also reaches motif regeneration when the editor controls have focus.');
(new ReflectionProperty(MADSCORE\App\Controller::class, 'activeItemId'))->setValue(null, 'main');
$cursor = $editor->widget('cursorBox');
expectEditorFocus($cursor !== null && !$cursor->canActivate(),
  'Cursor is one selectable readout tile without an action.');
$editor->selectLeaf($editor->layout->findTile('finger'));
expectEditorFocus($editor->handleEvent(editorArrow(SDL::KEY_RIGHT))
  && $editor->selectedLeaf()?->instance() === $cursor,
  'Right from Note selects the Cursor tile.');
expectEditorFocus($editor->handleEvent(editorArrow(SDL::KEY_RIGHT))
  && $editor->selectedLeaf()?->instance()?->id() === 'saveButton',
  'Right from Cursor selects Actions.');
expectEditorFocus($editor->handleEvent(editorArrow(SDL::KEY_LEFT))
  && $editor->selectedLeaf()?->instance() === $cursor,
  'Left from Actions returns to Cursor.');
expectEditorFocus($editor->handleEvent(editorArrow(SDL::KEY_LEFT))
  && $editor->layout->findTile('noteBox')->pathTo($editor->selectedLeaf()) !== null,
  'Left from Cursor returns to Note controls.');
$editor->show('savePanel');
expectEditorFocus($editor->layout->findTile('savePanel')->overwriting() === $editor->layout->findTile('noteBox')
  && $editor->selectedLeaf()?->instance()?->id() === 'saveDirectory',
  'Save replaces Note and focuses its directory selector.');
$editor->restore('savePanel');
$editor->show('loadPanel');
expectEditorFocus($editor->layout->findTile('loadPanel')->overwriting() === $editor->layout->findTile('noteBox')
  && $editor->selectedLeaf()?->instance()?->id() === 'loadSelector',
  'Load replaces Note and focuses its file selector.');
$editor->restore('loadPanel');
echo "Editor focus checks passed\n";
