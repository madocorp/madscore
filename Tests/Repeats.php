<?php

define('APP_DIR', dirname(__DIR__));
define('APP_NAMESPACE', 'MADSCORE');
require APP_DIR . '/SPTK/App.php';

function expectRepeat(bool $condition, string $message): void {
  if (!$condition) {
    throw new RuntimeException($message);
  }
}

$score = new MADSCORE\Score\Score();
$score->ensureBar(2);
$score->setSectionLeader(2, true);
$score->setSectionRepeats(1, 2);
expectRepeat($score->bars[0]['repeats'] === 2 && $score->bars[1]['repeats'] === 0,
  'A repeat count belongs to the section, even when edited from a later bar.');
expectRepeat(MADSCORE\Score\Score::fromData($score->data())->data() === $score->data(), 'Repeat counts survive a save and load.');
$old = $score->data();
unset($old['bars'][0]['repeats']);
expectRepeat(MADSCORE\Score\Score::fromData($old)->sectionRepeats(1) === 0, 'Old scores default to no repeat.');
$invalid = $score->data();
$invalid['bars'][1]['repeats'] = 1;
try {
  MADSCORE\Score\Score::fromData($invalid);
  throw new RuntimeException('A repeat count on a nonleader bar was accepted.');
} catch (DomainException $error) {
  expectRepeat(true, 'Repeat counts require section leaders.');
}
$screens = (new SPTK\XmlParser\XmlParser())->windows[0]['screens'];
$window = (new ReflectionClass(SPTK\Core\Window::class))->newInstanceWithoutConstructor();
(new ReflectionProperty(SPTK\Core\Window::class, 'screens'))->setValue($window, $screens);
(new ReflectionProperty(MADSCORE\App\Controller::class, 'window'))->setValue(null, $window);
(new ReflectionProperty(MADSCORE\App\Controller::class, 'score'))->setValue(null, $score);
(new ReflectionProperty(MADSCORE\App\Controller::class, 'bar'))->setValue(null, 1);
(new ReflectionMethod(MADSCORE\App\ControllerSheet::class, 'refreshSelectedBarFields'))->invoke(null);
$field = $window->screen('editor')->widget('repeats');
expectRepeat($field->getValue() === '2', 'The Bar panel shows the selected section repeat count.');

$paint = new MADSCORE\Score\Score();
$paint->setSectionRepeats(0, 2);
$sheet = MADSCORE\Render\SheetPainter::render($paint, 0, 1, 500, 490);
$cairo = MADSCORE\Render\Cairo::library();
$surface = $cairo->cairo_image_surface_create(0, 1000, 1000);
$context = $cairo->cairo_create($surface);
try {
  MADSCORE\Render\PagePainter::drawPage($paint, $cairo, $context, MADSCORE\Render\PagePainter::pages($paint)[0]);
  $page = MADSCORE\Render\Cairo::raster($surface, 1000, 1000);
} finally {
  $cairo->cairo_destroy($context);
  $cairo->cairo_surface_destroy($surface);
}
$paint->setSectionRepeats(0, 0);
expectRepeat($sheet->pixels !== MADSCORE\Render\SheetPainter::render($paint, 0, 1, 500, 490)->pixels,
  'Repeat dots appear on the editing sheet.');
$surface = $cairo->cairo_image_surface_create(0, 1000, 1000);
$context = $cairo->cairo_create($surface);
try {
  MADSCORE\Render\PagePainter::drawPage($paint, $cairo, $context, MADSCORE\Render\PagePainter::pages($paint)[0]);
  $plain = MADSCORE\Render\Cairo::raster($surface, 1000, 1000);
} finally {
  $cairo->cairo_destroy($context);
  $cairo->cairo_surface_destroy($surface);
}
expectRepeat($page->pixels !== $plain->pixels, 'Repeat dots appear in print.');
$pixel = static function (\SPTK\Core\RasterImage $image, int $x, int $y): int {
  return (unpack('L', substr($image->pixels, ($y * $image->width + $x) * 4, 4))[1] >> 8) & 0xffffff;
};
$radius = MADSCORE\Render\Prescription::dotRadius(220);
$barWidth = (new ReflectionMethod(MADSCORE\Render\PagePainter::class, 'barWidth'))->invoke(null, $score, 0);
$firstDot = 75 + 44 + $barWidth - 4 - $radius;
$secondDot = $firstDot - 2 * $radius - max(2, $radius / 5);
$gap = (int)round(($firstDot - $radius + $secondDot + $radius) / 2);
expectRepeat($pixel($page, (int)round($firstDot), 262) === 0
  && $pixel($page, (int)round($secondDot), 262) === 0
  && $pixel($page, $gap, 262) !== 0,
  'Two repeat dots form a horizontal row with a clear gap.');

$audio = new MADSCORE\Score\Score();
$audio->ensureBar(1);
$audio->setSectionLeader(1, true);
$audio->setSectionRepeats(0, 2);
$audio->place(0, 0, 0, 4, 1, 2);
$source = new MADSCORE\Play\ScoreSource($audio);
$barFrames = 2 * MADSCORE\Play\ScoreSource::SAMPLE_RATE;
$first = $source->read($barFrames);
expectRepeat($source->read($barFrames) === $first && $source->read($barFrames) === $first,
  'Two dots play the section twice more.');
expectRepeat(strlen($source->read($barFrames)) === $barFrames * 4 && $source->read(1) === '',
  'Playback continues with the next section after repeats.');
$clip = new MADSCORE\Play\ScoreSource($audio, null, 0, 16);
expectRepeat(strlen($clip->read(4 * MADSCORE\Play\ScoreSource::SAMPLE_RATE)) === 4 * MADSCORE\Play\ScoreSource::SAMPLE_RATE * 4,
  'A selected playback range plays once.');

echo "Repeat checks passed\n";
