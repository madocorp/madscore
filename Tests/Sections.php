<?php

define('APP_DIR', dirname(__DIR__));
define('APP_NAMESPACE', 'MADSCORE');
require APP_DIR . '/SPTK/App.php';

function expectSection(bool $condition, string $message): void {
  if (!$condition) {
    throw new \RuntimeException($message);
  }
}

function sectionRgb(\SPTK\Core\RasterImage $image, int $x, int $y): int {
  $offset = ($y * $image->width + $x) * 4;
  return (unpack('L', substr($image->pixels, $offset, 4))[1] >> 8) & 0xffffff;
}

function sectionPageImage(MADSCORE\Score\Score $score, int $width = 1000, int $height = 1000): \SPTK\Core\RasterImage {
  $cairo = MADSCORE\Render\Cairo::library();
  $surface = $cairo->cairo_image_surface_create(0, $width, $height);
  $context = $cairo->cairo_create($surface);
  try {
    MADSCORE\Render\PagePainter::drawPage($score, $cairo, $context, MADSCORE\Render\PagePainter::pages($score)[0]);
    return MADSCORE\Render\Cairo::raster($surface, $width, $height);
  } finally {
    $cairo->cairo_destroy($context);
    $cairo->cairo_surface_destroy($surface);
  }
}

$score = new MADSCORE\Score\Score(12);
$score->ensureBar(2);
expectSection($score->sectionNumber(2) === 1, 'A new score starts with one section.');
expectSection($score->bars[0]['sectionLeader'] && !$score->bars[1]['sectionLeader'], 'New bars continue the first section.');
$score->setSectionLeader(1, true);
expectSection($score->sectionNumber(1) === 2 && $score->sectionNumber(2) === 2, 'A leader starts the next section.');
$score->ensureBar(3);
expectSection(!$score->bars[3]['sectionLeader'], 'Appending after a leader does not start another section.');
expectSection(MADSCORE\Score\Score::fromData($score->data())->data() === $score->data(), 'Section leaders survive a save and load.');
$score->setSectionLeader(1, false);
expectSection($score->sectionNumber(3) === 1, 'Removing a leader joins adjacent bars.');
try {
  $score->setSectionLeader(0, false);
  throw new \RuntimeException('The first section leader was removed.');
} catch (\DomainException $error) {
  expectSection($score->bars[0]['sectionLeader'], 'The first section leader is required.');
}

$invalid = $score->data();
unset($invalid['bars'][1]['sectionLeader']);
try {
  MADSCORE\Score\Score::fromData($invalid);
  throw new \RuntimeException('A missing section leader flag was accepted.');
} catch (\DomainException $error) {
  expectSection(true, 'Bars require a section leader flag.');
}

$drawing = new MADSCORE\Score\Score(12);
$drawing->ensureBar(1);
$plain = MADSCORE\Render\SheetPainter::render($drawing, 0, 2, 500, 490);
$rows = MADSCORE\Render\Prescription::rows(MADSCORE\Render\Prescription::states($drawing)[0], 480, 48);
$gapY = 5 + $rows[4]['top'] + $rows[4]['height'];
$boundaryX = 262;
expectSection(sectionRgb($plain, $boundaryX, $gapY) === 0xffffff, 'An ordinary bar edge leaves the octave gap open.');
$drawing->setSectionLeader(1, true);
$sectioned = MADSCORE\Render\SheetPainter::render($drawing, 0, 2, 500, 490);
expectSection(sectionRgb($sectioned, $boundaryX, $gapY) !== 0xffffff, 'A section edge connects the octave rows.');
$crossing = new MADSCORE\Score\Score(12);
$crossing->ensureBar(1);
$crossing->setSectionLeader(1, true);
$crossing->place(0, 0, 6, 4, 4, 4);
$crossingSheet = MADSCORE\Render\SheetPainter::render($crossing, 0, 2, 500, 490);
expectSection(sectionRgb($crossingSheet, $boundaryX, $gapY) !== 0xffffff,
  'The section separator remains visible between rows when a note crosses it.');
expectSection(sectionRgb($sectioned, 30, $gapY) !== 0xffffff, 'The first section has a left edge.');
expectSection(sectionRgb($sectioned, 494, $gapY) !== 0xffffff && sectionRgb($sectioned, 495, $gapY) === 0xffffff,
  'The last section separator aligns with the inset closing bar line.');
expectSection(sectionRgb($sectioned, 492, $gapY) === 0xffffff, 'The closing edge is not inset from the bar boundary.');
$highestGSharpTop = 5 + $rows[4]['top'] - $rows[4]['height'] / 6;
$lowestGSharpBottom = 5 + $rows[1]['top'] + $rows[1]['height'];
expectSection(sectionRgb($sectioned, $boundaryX, (int)ceil($highestGSharpTop)) !== 0xffffff, 'The separator reaches the top of the highest G-sharp.');
expectSection(sectionRgb($sectioned, $boundaryX, (int)floor($highestGSharpTop) - 2) === 0xffffff, 'The separator does not extend above G-sharp.');
expectSection(sectionRgb($sectioned, $boundaryX, (int)floor($lowestGSharpBottom) - 1) !== 0xffffff,
  'The separator reaches the bottom of the lowest overflow G-sharp.');
expectSection(sectionRgb($sectioned, $boundaryX, (int)ceil($lowestGSharpBottom) + 2) === 0xffffff,
  'The separator does not extend below the overflow G-sharp.');

$single = new MADSCORE\Score\Score(12);
$oneBarSheet = MADSCORE\Render\SheetPainter::render($single, 0, 2, 500, 490);
$staffY = 5 + (int)round($rows[3]['top'] + $rows[3]['height'] / 12);
$staffInteriorY = 5 + (int)round($rows[3]['top'] + 3 * $rows[3]['height'] / 12);
expectSection(sectionRgb($oneBarSheet, 261, $staffInteriorY) !== 0xffffff,
  'The final bar stays closed through the middle of its staff.');
expectSection(sectionRgb($oneBarSheet, 380, $staffY) === 0xffffff, 'No octave staff continues past the final separator.');
$preview = ['bar' => 0, 'start' => 7, 'end' => 9, 'octave' => 4, 'pitch' => 4];
$oneBarPreview = MADSCORE\Render\SheetPainter::render($single, 0, 2, 500, 490, $preview, 0);
$pitchY = 5 + (int)round($rows[4]['top'] + 7 * $rows[4]['height'] / 12);
expectSection(sectionRgb($oneBarPreview, 280, $pitchY) === 0xffffff, 'A cursor preview does not draw beyond the final separator.');
$single->ensureBar(1);
$twoBarSheet = MADSCORE\Render\SheetPainter::render($single, 0, 2, 500, 490);
expectSection(sectionRgb($twoBarSheet, 380, $staffY) !== 0xffffff, 'Appending a bar makes its octave staff visible.');
$hiddenNextBar = new MADSCORE\Score\Score(12);
$hiddenNextBar->ensureBar(2);
$visiblePair = MADSCORE\Render\SheetPainter::render($hiddenNextBar, 0, 2, 500, 490);
expectSection(sectionRgb($visiblePair, 494, $staffInteriorY) !== 0xffffff,
  'The last visible bar closes when the following bar is outside the editor viewport.');

$printBorders = new MADSCORE\Score\Score();
$printBorders->ensureBar(2);
$printBorders->setSectionLeader(1, true);
$pageImage = sectionPageImage($printBorders);
$strip = static function (int $center) use ($pageImage): array {
  $pixels = [];
  for ($x = $center - 3; $x <= $center + 3; $x++) {
    $pixels[] = sectionRgb($pageImage, $x, 530);
  }
  return $pixels;
};
$barWidth = (new ReflectionMethod(MADSCORE\Render\PagePainter::class, 'barWidth'))->invoke(null, $printBorders, 0);
expectSection($strip(75 + 44 + $barWidth - 10) === $strip(75 + 44 + 2 * $barWidth - 10),
  'A section border and an ordinary bar border have the same ink strength in print.');
$endingBand = new MADSCORE\Score\Score(2);
$endingBand->ensureBar(1);
$endingBand->place(0, 0, 0, 4, 4, 2);
$endingBand->place(0, 1, 0, 4, 4, 2);
$endingBand->place(1, 0, 0, 6, 4, 2);
$endingBand->setSectionLeader(1, true);
$endingBandPage = sectionPageImage($endingBand);
$edge = 75 + 44 + (new ReflectionMethod(MADSCORE\Render\PagePainter::class, 'barWidth'))
  ->invoke(null, $endingBand, 0);
expectSection(sectionRgb($endingBandPage, $edge - 1, 325) === 0xffffff
  && sectionRgb($endingBandPage, $edge, 325) !== 0xffffff,
  'A staff ending before an octave hidden in the next section uses only the section border.');
$changedSection = new MADSCORE\Score\Score(6);
$changedSection->ensureBar(1);
$changedSection->setSectionLeader(1, true);
$changedSection->place(0, 0, 0, 8, 4, 2);
foreach (range(0, 4) as $melody) {
  $changedSection->place($melody + 1, 1, 0, $melody, 4, 2);
}
$changedStates = MADSCORE\Render\Prescription::states($changedSection);
$changedWidth = (new ReflectionMethod(MADSCORE\Render\PagePainter::class, 'barWidth'))
  ->invoke(null, $changedSection, 0);
$changedPositions = MADSCORE\Render\Prescription::positions([0, 1],
  [$changedWidth, $changedWidth], $changedStates, 44);
$changedImage = sectionPageImage($changedSection, 3508, 2480);
$closingX = 75 + $changedPositions[0]['right'];
expectSection($changedPositions[1]['markerLeft'] !== null
  && sectionRgb($changedImage, $closingX - 4, 600) === 0xffffff
  && sectionRgb($changedImage, $closingX - 2, 600) !== 0xffffff,
  'A section with an octave marker closes the preceding bar before the marker gap.');
$wrappedSection = new MADSCORE\Score\Score();
$wrappedSection->ensureBar(11);
$wrappedSection->setSectionLeader(11, true);
$wrappedLines = MADSCORE\Render\PagePainter::pages($wrappedSection)[0];
expectSection(count($wrappedLines) === 2 && end($wrappedLines[0]) === 10 && $wrappedLines[1][0] === 11,
  'The section leader falls exactly at a PDF line break.');
$wrappedPage = sectionPageImage($wrappedSection, 3508, 2480);
$firstLineRight = 75 + 44 + 11 * (new ReflectionMethod(MADSCORE\Render\PagePainter::class, 'barWidth'))
  ->invoke(null, $wrappedSection, 0);
expectSection(sectionRgb($wrappedPage, $firstLineRight - 2, 500) !== 0xffffff
  && sectionRgb($wrappedPage, 119, 800) !== 0xffffff,
  'A wrapped section separator appears at both the previous line end and the next line start.');

$grouped = new MADSCORE\Score\Score();
$grouped->ensureBar(12);
$grouped->setSectionLeader(4, true);
$groupedLines = MADSCORE\Render\PagePainter::pages($grouped)[0];
expectSection($groupedLines === [[0, 1, 2, 3], [4, 5, 6, 7, 8, 9, 10, 11, 12]],
  'A section that fits by itself moves intact to the next PDF line.');
$oneLongSection = new MADSCORE\Score\Score();
$oneLongSection->ensureBar(12);
expectSection(MADSCORE\Render\PagePainter::pages($oneLongSection)[0]
  === [[0, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10], [11, 12]],
  'A section wider than the line still wraps between bars.');
$rowHeight = new ReflectionMethod(MADSCORE\Render\PagePainter::class, 'rowHeight');
expectSection($rowHeight->invoke(null, 4) === 220,
  'The default PDF note size gives the original staff row height.');
MADSCORE\Render\PdfLayout::configure(70, 80);
try {
  expectSection($rowHeight->invoke(null, 4) === 154,
    'Changing PDF note size also changes staff row and bar height.');
  expectSection(MADSCORE\Render\PagePainter::pages($grouped)[0]
    === [[0, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12]],
    'Smaller beat width lets adjacent sections share one PDF line.');
  expectSection(MADSCORE\Render\PdfLayout::noteSize() === 70
    && MADSCORE\Render\PdfLayout::beatSize() === 80,
    'PDF note size and beat width can be set independently.');
  MADSCORE\Render\PdfLayout::change(20, 0);
  expectSection(MADSCORE\Render\PdfLayout::noteSize() === 90
    && MADSCORE\Render\PdfLayout::beatSize() === 90,
    'Increasing notes raises beat spacing when needed.');
  MADSCORE\Render\PdfLayout::change(0, -20);
  expectSection(MADSCORE\Render\PdfLayout::noteSize() === 70
    && MADSCORE\Render\PdfLayout::beatSize() === 70,
    'Decreasing beat spacing also reduces oversized notes.');
  MADSCORE\Render\PdfLayout::configure(120, 90);
  expectSection(MADSCORE\Render\PdfLayout::beatSize() === 120
    && $rowHeight->invoke(null, 9) <= 221,
    'Saved layout values keep beat spacing wide enough and tall staff lines inside the page.');
  MADSCORE\Render\PdfLayout::configure(140, 140);
  $wideBar = new MADSCORE\Score\Score();
  $wideBar->setBeats(0, 32);
  $wideBarWidth = (new ReflectionMethod(MADSCORE\Render\PagePainter::class, 'barWidth'))
    ->invoke(null, $wideBar, 0);
  expectSection($wideBarWidth / 64 + 0.02 >= $rowHeight->invoke(null, 4) / 6,
    'Even the widest bar keeps each beat slot at least one note diameter wide.');
  MADSCORE\Render\PdfLayout::configure(100, 195);
  MADSCORE\Render\PdfLayout::change(0, 5);
  expectSection(MADSCORE\Render\PdfLayout::noteSize() === 100
    && MADSCORE\Render\PdfLayout::beatSize() === 200,
    'PDF beat width reaches 200% without changing note size.');
  MADSCORE\Render\PdfLayout::change(0, 5);
  expectSection(MADSCORE\Render\PdfLayout::beatSize() === 200,
    'PDF beat width stops at 200%.');
  MADSCORE\Render\PdfLayout::configure(100, 250);
  expectSection(MADSCORE\Render\PdfLayout::beatSize() === 200,
    'Saved PDF beat width is capped at 200%.');
} finally {
  MADSCORE\Render\PdfLayout::configure(100, 100);
}

$pdf = tempnam(sys_get_temp_dir(), 'madscore-sections-');
MADSCORE\Render\PdfWriter::write($pdf, $drawing);
expectSection(str_starts_with(file_get_contents($pdf), '%PDF-'), 'Sections export to PDF.');
unlink($pdf);

echo "Section checks passed\n";
