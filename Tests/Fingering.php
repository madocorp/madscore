<?php

define('APP_DIR', dirname(__DIR__));
define('APP_NAMESPACE', 'MADSCORE');
require APP_DIR . '/SPTK/App.php';

function expectFinger(bool $condition, string $message): void {
  if (!$condition) {
    throw new RuntimeException($message);
  }
}

$score = new MADSCORE\Score\Score();
$note = $score->place(0, 0, 0, 4, 4, 2);
$plain = MADSCORE\Render\BarPainter::render($score, 0, 4, 120, 96)->pixels;
$images = [];
foreach (['L1', 'L2', 'L3', 'L4', 'L5', 'R1', 'R2', 'R3', 'R4', 'R5'] as $finger) {
  $score->setFinger($note, $finger);
  $images[] = MADSCORE\Render\BarPainter::render($score, 0, 4, 120, 96)->pixels;
  expectFinger(MADSCORE\Score\Score::fromData($score->data())->notes[$note]['finger'] === $finger,
    'The hand and finger survive a score round trip.');
}
expectFinger(count(array_unique($images)) === 10 && !in_array($plain, $images, true),
  'Each left- and right-hand finger has a distinct mark on the sheet.');
$sample = static fn (string $pixels, int $x, int $y): string => substr($pixels, ($y * 120 + $x) * 4, 4);
foreach ($images as $image) {
  for ($y = 49; $y <= 62; $y++) {
    for ($x = 8; $x <= 22; $x++) {
      expectFinger($sample($image, $x, $y) === $sample($plain, $x, $y),
        'A fingering label leaves the note head and outline unchanged.');
    }
  }
}
$changedLeft = false;
$changedRight = false;
for ($y = 63; $y <= 71; $y++) {
  for ($x = 0; $x < 15; $x++) {
    $changedLeft = $changedLeft || $sample($images[0], $x, $y) !== $sample($plain, $x, $y);
  }
  for ($x = 15; $x < 35; $x++) {
    $changedRight = $changedRight || $sample($images[5], $x, $y) !== $sample($plain, $x, $y);
  }
}
expectFinger($changedLeft && $changedRight, 'Hand labels appear below opposite sides of the note head.');
$leftPixels = [];
$rightPixels = [];
for ($y = 62; $y < 78; $y++) {
  for ($x = 0; $x < 40; $x++) {
    if ($sample($images[2], $x, $y) !== $sample($plain, $x, $y)) {
      $leftPixels[] = $x;
    }
    if ($sample($images[7], $x, $y) !== $sample($plain, $x, $y)) {
      $rightPixels[] = $x;
    }
  }
}
expectFinger($leftPixels !== [] && min($leftPixels) >= 5 && $rightPixels !== [] && max($rightPixels) <= 27,
  'Finger digits sit half a half-beat slot inward from the note edges.');
$joined = new MADSCORE\Score\Score();
$first = $joined->place(0, 0, 0, 4, 4, 2);
$joined->place(0, 0, 2, 4, 8, 2);
$withoutMark = MADSCORE\Render\BarPainter::render($joined, 0, 4, 120, 96)->pixels;
$joined->setFinger($first, 'R5');
$withMark = MADSCORE\Render\BarPainter::render($joined, 0, 4, 120, 96)->pixels;
for ($y = 0; $y < 96; $y++) {
  for ($x = 31; $x <= 40; $x++) {
    expectFinger($sample($withMark, $x, $y) === $sample($withoutMark, $x, $y),
      'A finger glyph does not leave a diagonal path into the following note.');
  }
}
$spanning = new MADSCORE\Score\Score();
$across = $spanning->place(0, 0, 7, 4, 4, 2);
$beforeStart = MADSCORE\Render\BarPainter::render($spanning, 0, 4, 120, 96)->pixels;
$beforeEnd = MADSCORE\Render\BarPainter::render($spanning, 1, 4, 120, 96)->pixels;
$spanning->setFinger($across, 'R2');
expectFinger(MADSCORE\Render\BarPainter::render($spanning, 0, 4, 120, 96)->pixels === $beforeStart
  && MADSCORE\Render\BarPainter::render($spanning, 1, 4, 120, 96)->pixels !== $beforeEnd,
  'A right-hand finger is printed at the final half beat across a bar line.');
$copy = $score->repeatAfter($note);
expectFinger($score->notes[$copy]['finger'] === 'R5', 'Duplicating a note preserves its fingering.');

$old = $score->data();
unset($old['notes'][0]['finger']);
expectFinger(MADSCORE\Score\Score::fromData($old)->notes[0]['finger'] === '',
  'A score saved before fingering support loads without a finger mark.');
$old['notes'][0]['finger'] = 3;
expectFinger(MADSCORE\Score\Score::fromData($old)->notes[0]['finger'] === '3',
  'A saved numeric mark remains unassigned to a hand.');
$old['notes'][0]['finger'] = 'X3';
try {
  MADSCORE\Score\Score::fromData($old);
  throw new RuntimeException('An invalid fingering was accepted.');
} catch (DomainException) {
  // Invalid saved numbers are rejected.
}

$definition = (new SPTK\XmlParser\XmlParser())->windows[0];
$window = (new ReflectionClass(SPTK\Core\Window::class))->newInstanceWithoutConstructor();
(new ReflectionProperty(SPTK\Core\Window::class, 'screens'))->setValue($window, $definition['screens']);
(new ReflectionProperty(MADSCORE\App\Controller::class, 'window'))->setValue(null, $window);
(new ReflectionProperty(MADSCORE\App\Controller::class, 'score'))->setValue(null, $score);
(new ReflectionProperty(MADSCORE\App\Controller::class, 'sheet'))->setValue(null,
  new SPTK\Layout\LayoutLeaf('Canvas', '1*', '1*', new MADSCORE\App\SheetCanvas()));
foreach (['bar' => 0, 'slot' => 0, 'octave' => 4, 'melody' => 0, 'pitchPosition' => 4,
  'cursorLength' => 2, 'displayedReadout' => ''] as $name => $value) {
  (new ReflectionProperty(MADSCORE\App\Controller::class, $name))->setValue(null, $value);
}
$field = $window->screen('editor')->widget('finger');
(new ReflectionMethod(MADSCORE\App\Controller::class, 'refreshCursorReadout'))->invoke(null);
expectFinger($field->getValue() === 'R5', 'The note tile shows the saved hand and finger.');
$field->setValue('l2');
(new ReflectionMethod(MADSCORE\App\Controller::class, 'applyFinger'))->invoke(null);
expectFinger($score->notes[$note]['finger'] === 'L2', 'The note tile accepts a left-hand finger.');
$field->setValue('6');
try {
  (new ReflectionMethod(MADSCORE\App\Controller::class, 'applyFinger'))->invoke(null);
  throw new RuntimeException('The note tile accepted finger 6.');
} catch (DomainException) {
  expectFinger($score->notes[$note]['finger'] === 'L2', 'An invalid note tile value leaves the note unchanged.');
}
$field->setValue('');
(new ReflectionMethod(MADSCORE\App\Controller::class, 'applyFinger'))->invoke(null);
expectFinger($score->notes[$note]['finger'] === '', 'Blank removes the finger label.');

echo "Fingering checks passed.\n";
