<?php

define('APP_DIR', dirname(__DIR__));
define('APP_NAMESPACE', 'MADSCORE');
require APP_DIR . '/SPTK/App.php';

function expectBarNote(bool $condition, string $message): void {
  if (!$condition) {
    throw new RuntimeException($message);
  }
}

$score = new MADSCORE\Score\Score();
expectBarNote($score->bars[0]['note'] === '', 'A new score starts without a bar note.');
$score->setBarNote(0, 'Dolce');
$score->ensureBar(1);
expectBarNote($score->bars[1]['note'] === '' && $score->bars[1]['tempo'] === $score->bars[0]['tempo'],
  'A new bar inherits tempo but not the preceding text.');
$tempoScore = new MADSCORE\Score\Score();
$tempoScore->ensureBar(2);
expectBarNote(MADSCORE\Render\TempoPainter::label($tempoScore, 0) === '120'
  && MADSCORE\Render\TempoPainter::label($tempoScore, 1) === null,
  'The first bar prints its tempo and an unchanged following bar omits it.');
$unchangedTempoPage = MADSCORE\Render\PagePreview::render($tempoScore, 0, 842, 595);
$tempoScore->setTempo(1, '120.0');
expectBarNote(MADSCORE\Render\TempoPainter::label($tempoScore, 1) === null,
  'Equivalent numeric tempos do not create a change mark.');
$tempoScore->setTempo(2, '90');
expectBarNote(MADSCORE\Render\TempoPainter::label($tempoScore, 2) === '90'
  && MADSCORE\Render\PagePreview::render($tempoScore, 0, 842, 595)->pixels !== $unchangedTempoPage->pixels,
  'A changed bar tempo appears in the PDF preview.');
$cairo = MADSCORE\Render\Cairo::library();
$surface = $cairo->cairo_image_surface_create(0, 1000, 1000);
$context = $cairo->cairo_create($surface);
try {
  $startingTempo = new MADSCORE\Score\Score();
  MADSCORE\Render\PagePainter::drawPage($startingTempo, $cairo, $context,
    MADSCORE\Render\PagePainter::pages($startingTempo)[0]);
  $tempoImage = MADSCORE\Render\Cairo::raster($surface, 1000, 1000);
} finally {
  $cairo->cairo_destroy($context);
  $cairo->cairo_surface_destroy($surface);
}
$tempoPixel = static fn (int $x, int $y): int =>
  (unpack('L', substr($tempoImage->pixels, ($y * 1000 + $x) * 4, 4))[1] >> 8) & 0xffffff;
expectBarNote($tempoPixel(132, 258) === 0xffffff && $tempoPixel(120, 258) < 0x808080,
  'The printed tempo circle matches the height of its digits.');
$wedgeScore = new MADSCORE\Score\Score();
$wedgeScore->ensureBar(1);
$wedgeStart = $wedgeScore->place(0, 1, 0, 4, 1, 2);
$wedgeScore->place(0, 1, 2, 4, 1, 2);
$wedgeScore->setDynamicToNext($wedgeStart, 'crescendo');
$renderPrintPage = static function (MADSCORE\Score\Score $score) use ($cairo): \SPTK\Core\RasterImage {
  $surface = $cairo->cairo_image_surface_create(0, 1000, 1000);
  $context = $cairo->cairo_create($surface);
  try {
    MADSCORE\Render\PagePainter::drawPage($score, $cairo, $context, MADSCORE\Render\PagePainter::pages($score)[0]);
    return MADSCORE\Render\Cairo::raster($surface, 1000, 1000);
  } finally {
    $cairo->cairo_destroy($context);
    $cairo->cairo_surface_destroy($surface);
  }
};
$plainWedge = $renderPrintPage($wedgeScore);
$wedgeScore->setTempo(1, '90');
$markedWedge = $renderPrintPage($wedgeScore);
$printPixel = static fn (\SPTK\Core\RasterImage $image, int $x, int $y): int =>
  (unpack('L', substr($image->pixels, ($y * 1000 + $x) * 4, 4))[1] >> 8) & 0xffffff;
expectBarNote($printPixel($plainWedge, 464, 267) !== $printPixel($markedWedge, 464, 267)
  && $printPixel($plainWedge, 475, 267) === $printPixel($markedWedge, 475, 267)
  && $printPixel($markedWedge, 475, 267) !== 0xffffff,
  'A changed tempo prints over a crescendo while the background remains transparent.');
$score->setBarNote(1, 'Second bar');
expectBarNote(MADSCORE\Score\Score::fromData($score->data())->data() === $score->data(),
  'Bar notes survive a score round trip.');
$old = $score->data();
unset($old['bars'][1]['note']);
expectBarNote(MADSCORE\Score\Score::fromData($old)->bars[1]['note'] === '',
  'An older bar without text loads as an empty note.');
$invalid = $score->data();
$invalid['bars'][1]['note'] = "two\nlines";
try {
  MADSCORE\Score\Score::fromData($invalid);
  throw new RuntimeException('A multiline bar note was accepted.');
} catch (DomainException $error) {
  expectBarNote(true, 'Bar notes are single-line text.');
}
$score->setBarNote(0, '');
$score->setBarNote(1, 'Visible note');
expectBarNote(!$score->removeEmptyLastBar(1), 'A bar with an annotation is not removed as empty.');
$sheet = MADSCORE\Render\SheetPainter::render($score, 0, 2, 500, 490, melody: 0, design: true);
$preview = MADSCORE\Render\PagePreview::render($score, 0, 1200, 850);
$score->setBarNote(1, '');
expectBarNote(MADSCORE\Render\SheetPainter::render($score, 0, 2, 500, 490,
  melody: 0, design: true)->pixels !== $sheet->pixels,
  'Bar notes appear above their bars in the editor.');
expectBarNote(MADSCORE\Render\PagePreview::render($score, 0, 1200, 850)->pixels !== $preview->pixels,
  'Bar notes appear in the PDF preview.');

$screens = (new SPTK\XmlParser\XmlParser())->windows[0]['screens'];
$window = (new ReflectionClass(SPTK\Core\Window::class))->newInstanceWithoutConstructor();
(new ReflectionProperty(SPTK\Core\Window::class, 'screens'))->setValue($window, $screens);
(new ReflectionProperty(MADSCORE\App\Controller::class, 'window'))->setValue(null, $window);
(new ReflectionProperty(MADSCORE\App\Controller::class, 'score'))->setValue(null, $score);
(new ReflectionProperty(MADSCORE\App\Controller::class, 'bar'))->setValue(null, 1);
(new ReflectionMethod(MADSCORE\App\ControllerSheet::class, 'installSheet'))->invoke(null, 'editor');
$sheetLeaf = (new ReflectionProperty(MADSCORE\App\Controller::class, 'sheets'))->getValue()['editor'];
(new ReflectionProperty(MADSCORE\App\Controller::class, 'sheet'))->setValue(null, $sheetLeaf);
$contentDirty = new ReflectionProperty(MADSCORE\App\SheetCanvas::class, 'contentDirty');
$contentDirty->setValue($sheetLeaf->instance(), false);
(new ReflectionMethod(MADSCORE\App\ControllerSheet::class, 'refreshSelectedBarFields'))->invoke(null);
$field = $window->screen('editor')->widget('barNote');
expectBarNote($field->getValue() === '', 'The bar settings show the selected bar note.');
$field->setValue('Con moto');
(new ReflectionMethod(MADSCORE\App\ControllerSheet::class, 'applyEditorBarNote'))->invoke(null);
expectBarNote($score->bars[1]['note'] === 'Con moto' && $contentDirty->getValue($sheetLeaf->instance()),
  'Editing a bar note saves it and redraws the editor sheet.');

echo "Bar note checks passed\n";
