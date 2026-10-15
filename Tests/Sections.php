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

$score = new MADSCORE\Score();
$score->ensureBar(2);
expectSection($score->sectionNumber(2) === 1, 'A new score starts with one section.');
expectSection($score->bars[0]['sectionLeader'] && !$score->bars[1]['sectionLeader'], 'New bars continue the first section.');
$score->setSectionLeader(1, true);
expectSection($score->sectionNumber(1) === 2 && $score->sectionNumber(2) === 2, 'A leader starts the next section.');
$score->ensureBar(3);
expectSection(!$score->bars[3]['sectionLeader'], 'Appending after a leader does not start another section.');
expectSection(MADSCORE\Score::fromData($score->data())->data() === $score->data(), 'Section leaders survive a save and load.');
$score->setSectionLeader(1, false);
expectSection($score->sectionNumber(3) === 1, 'Removing a leader joins adjacent bars.');
try {
  $score->setSectionLeader(0, false);
  throw new \RuntimeException('The first section leader was removed.');
} catch (\DomainException $error) {
  expectSection($score->bars[0]['sectionLeader'], 'The first section leader is required.');
}

$old = $score->data();
$old['version'] = 3;
$old['bars'] = array_map(static fn (array $bar): array => ['beats' => $bar['beats'], 'tempo' => $bar['tempo']], $old['bars']);
$migrated = MADSCORE\Score::fromData($old);
expectSection($migrated->bars[0]['sectionLeader'] && !$migrated->bars[1]['sectionLeader'], 'Older scores migrate into one section.');
$invalid = $score->data();
unset($invalid['bars'][1]['sectionLeader']);
try {
  MADSCORE\Score::fromData($invalid);
  throw new \RuntimeException('A missing section leader flag was accepted.');
} catch (\DomainException $error) {
  expectSection(true, 'Version 4 bars require a section leader flag.');
}

$drawing = new MADSCORE\Score();
$drawing->ensureBar(1);
$plain = MADSCORE\SheetPainter::render($drawing, 0, 2, 500, 490);
$rows = MADSCORE\Prescription::rows(MADSCORE\Prescription::states($drawing)[0], 480, 24);
$gapY = 5 + $rows[5]['top'] + $rows[5]['height'];
$boundaryX = 259;
expectSection(sectionRgb($plain, $boundaryX, $gapY) === 0xffffff, 'An ordinary bar edge leaves the octave gap open.');
$drawing->setSectionLeader(1, true);
$sectioned = MADSCORE\SheetPainter::render($drawing, 0, 2, 500, 490);
expectSection(sectionRgb($sectioned, $boundaryX, $gapY) !== 0xffffff, 'A section edge connects the octave rows.');
expectSection(sectionRgb($sectioned, 23, $gapY) !== 0xffffff, 'The first section has a left edge.');
expectSection(sectionRgb($sectioned, 495, $gapY) !== 0xffffff, 'The last section ends at the bar edge.');
expectSection(sectionRgb($sectioned, 492, $gapY) === 0xffffff, 'The closing edge is not inset from the bar boundary.');
$highestGSharpTop = 5 + $rows[5]['top'] - $rows[5]['height'] / 6;
$lowestABottom = 5 + $rows[2]['top'] + 11 * $rows[2]['height'] / 12;
expectSection(sectionRgb($sectioned, $boundaryX, (int)ceil($highestGSharpTop)) !== 0xffffff, 'The separator reaches the top of the highest G-sharp.');
expectSection(sectionRgb($sectioned, $boundaryX, (int)floor($highestGSharpTop) - 2) === 0xffffff, 'The separator does not extend above G-sharp.');
expectSection(sectionRgb($sectioned, $boundaryX, (int)floor($lowestABottom)) !== 0xffffff, 'The separator reaches the bottom of the lowest A.');
expectSection(sectionRgb($sectioned, $boundaryX, (int)ceil($lowestABottom) + 2) === 0xffffff, 'The separator does not extend below A.');

$single = new MADSCORE\Score();
$oneBarSheet = MADSCORE\SheetPainter::render($single, 0, 2, 500, 490);
$staffY = 5 + (int)round($rows[4]['top'] + $rows[4]['height'] / 12);
expectSection(sectionRgb($oneBarSheet, 380, $staffY) === 0xffffff, 'No octave staff continues past the final separator.');
$preview = ['bar' => 0, 'start' => 7, 'end' => 9, 'octave' => 4, 'pitch' => 4];
$oneBarPreview = MADSCORE\SheetPainter::render($single, 0, 2, 500, 490, $preview, 0);
$pitchY = 5 + (int)round($rows[4]['top'] + 7 * $rows[4]['height'] / 12);
expectSection(sectionRgb($oneBarPreview, 280, $pitchY) === 0xffffff, 'A cursor preview does not draw beyond the final separator.');
$single->ensureBar(1);
$twoBarSheet = MADSCORE\SheetPainter::render($single, 0, 2, 500, 490);
expectSection(sectionRgb($twoBarSheet, 380, $staffY) !== 0xffffff, 'Appending a bar makes its octave staff visible.');

$pdf = tempnam(sys_get_temp_dir(), 'madscore-sections-');
MADSCORE\PdfWriter::write($pdf, $drawing);
expectSection(str_starts_with(file_get_contents($pdf), '%PDF-'), 'Sections export to PDF.');
unlink($pdf);

echo "Section checks passed\n";
