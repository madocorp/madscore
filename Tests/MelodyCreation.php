<?php

define('APP_DIR', dirname(__DIR__));
define('APP_NAMESPACE', 'MADSCORE');
require APP_DIR . '/SPTK/App.php';

/** Check the editor commit that runs before adding a melody. */
function expectMelodyCreation(bool $condition, string $message): void {
  if (!$condition) {
    throw new RuntimeException($message);
  }
}

$definition = (new SPTK\XmlParser\XmlParser())->windows[0];
$window = (new ReflectionClass(SPTK\Core\Window::class))->newInstanceWithoutConstructor();
(new ReflectionProperty(SPTK\Core\Window::class, 'screens'))->setValue($window, $definition['screens']);
$score = new MADSCORE\Score\Score();
expectMelodyCreation($score->melodies[0]['instrument'] === 'gm:0'
  && $score->melodies[0]['pattern'] === MADSCORE\Score\Sound::fallback('gm:0')
  && $score->melodies[0]['volume'] === 50,
  'A new score starts with acoustic grand piano at 50% volume.');
$score->setMelodyColor(0, '#123abc');
$screen = $window->screen('editor');
$screen->widget('tempo')->setValue('120');
$screen->widget('beats')->setValue('4');
$screen->widget('repeats')->setValue('0');
$screen->widget('volume')->setValue('100');
$screen->widget('color')->setValue('#123abc');
(new ReflectionProperty(MADSCORE\App\Controller::class, 'window'))->setValue(null, $window);
(new ReflectionProperty(MADSCORE\App\Controller::class, 'score'))->setValue(null, $score);
(new ReflectionProperty(MADSCORE\App\Controller::class, 'sheet'))->setValue(null,
  new SPTK\Layout\LayoutLeaf('Canvas', '1*', '1*', new MADSCORE\App\SheetCanvas()));
$commit = new ReflectionMethod(MADSCORE\App\ControllerSheet::class, 'commitEditorFields');
expectMelodyCreation($commit->invoke(null), 'Adding a melody can commit the current editor fields.');
expectMelodyCreation($score->melodies[0]['color'] === '#123abc', 'The existing melody keeps its chosen color.');
for ($number = 2; $number <= 14; $number++) {
  $index = $score->addMelody();
  expectMelodyCreation($index === $number - 1
    && $score->melodies[$index]['color'] === MADSCORE\Score\Score::COLORS[$index % count(MADSCORE\Score\Score::COLORS)]
    && $score->melodies[$index]['instrument'] === 'gm:0'
    && $score->melodies[$index]['volume'] === 50,
    "Melody {$number} receives its default color, grand piano, and volume.");
}
echo "Melody creation checks passed\n";
