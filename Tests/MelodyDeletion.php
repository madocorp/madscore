<?php

define('APP_DIR', dirname(__DIR__));
define('APP_NAMESPACE', 'MADSCORE');
require APP_DIR . '/SPTK/App.php';

/** Fail a melody deletion regression check with a readable message. */
function expectMelodyDeletion(bool $condition, string $message): void {
  if (!$condition) {
    throw new RuntimeException($message);
  }
}

/** Let background status messages run without an event loop in this fixture. */
function ignoreMelodyDeletionTimer(int $delayMs, callable $callback): void {
  return;
}

$score = new MADSCORE\Score\Score(3);
$score->setMelodyColor(2, '#123abc');
$score->put(0, 0, 0, 1, 'C');
$score->put(1, 0, 2, 1, 'D');
$score->put(2, 0, 4, 1, 'E');
$score->removeMelody(1);
expectMelodyDeletion(count($score->melodies) === 2 && $score->melodies[1]['color'] === '#123abc', 'Later melody settings move into the deleted position.');
expectMelodyDeletion(count($score->notes) === 2 && $score->notes[0]['melody'] === 0 && $score->notes[1]['melody'] === 1, 'Deleted notes disappear and later notes are renumbered.');
expectMelodyDeletion(MADSCORE\Score\Score::fromData($score->data())->data() === $score->data(), 'A score saves and reloads after melody deletion.');
$score->removeMelody(0);
expectMelodyDeletion(count($score->melodies) === 1 && count($score->notes) === 1 && $score->notes[0]['melody'] === 0, 'Deleting the first melody keeps the remaining melody valid.');
try {
  $score->removeMelody(0);
  throw new RuntimeException('The final melody was deleted.');
} catch (DomainException $error) {
  expectMelodyDeletion(count($score->melodies) === 1, 'A rejected final deletion leaves the score untouched.');
}

$definition = (new SPTK\XmlParser\XmlParser())->windows[0];
$window = (new ReflectionClass(SPTK\Core\Window::class))->newInstanceWithoutConstructor();
(new ReflectionProperty(SPTK\Core\Window::class, 'screens'))->setValue($window, $definition['screens']);
$screen = $window->screen('editor');
$screen->statusBar->setScheduler(ignoreMelodyDeletionTimer(...));
$screen->widget('tempo')->setValue('120');
$screen->widget('beats')->setValue('4');
$screen->widget('volume')->setValue('100');
$screen->widget('color')->setValue(MADSCORE\Score\Score::COLORS[0]);
$score = new MADSCORE\Score\Score(2);
(new ReflectionProperty(MADSCORE\App\Controller::class, 'window'))->setValue(null, $window);
(new ReflectionProperty(MADSCORE\App\Controller::class, 'score'))->setValue(null, $score);
(new ReflectionProperty(MADSCORE\App\Controller::class, 'sheet'))->setValue(null,
  new SPTK\Layout\LayoutLeaf('Canvas', '1*', '1*', new MADSCORE\App\SheetCanvas()));
MADSCORE\App\Controller::requestDeleteMelody(new SPTK\Events\EventContext('accept', $screen->widget('deleteMelody')));
expectMelodyDeletion($screen->statusBar->confirming() && str_contains($screen->statusBar->text(), 'melody 1'), 'The button asks for status bar confirmation: ' . $screen->statusBar->text());
$screen->statusBar->handleBlockingInput((object)[
  'type' => SPTK\SDLWrapper\SDL::SDL_EVENT_KEY_DOWN,
  'key' => (object)['key' => SPTK\SDLWrapper\SDL::KEY_ESCAPE, 'mod' => 0],
]);
expectMelodyDeletion(!$screen->statusBar->confirming() && count($score->melodies) === 2, 'Escape cancels deletion without changing the score.');
$lastMelody = new MADSCORE\Score\Score();
(new ReflectionProperty(MADSCORE\App\Controller::class, 'score'))->setValue(null, $lastMelody);
MADSCORE\App\Controller::requestDeleteMelody(new SPTK\Events\EventContext('accept', $screen->widget('deleteMelody')));
expectMelodyDeletion(!$screen->statusBar->confirming() && count($lastMelody->melodies) === 1, 'The final melody cannot be offered for deletion.');

echo "Melody deletion checks passed\n";
