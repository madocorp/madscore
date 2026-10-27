<?php

define('APP_DIR', dirname(__DIR__));
define('APP_NAMESPACE', 'MADSCORE');
require APP_DIR . '/SPTK/App.php';

use MADSCORE\Play\MidiWriter;
use MADSCORE\Play\ScoreSource;
use MADSCORE\Render\FigureCatalog;
use MADSCORE\Render\FigureAppendix;
use MADSCORE\Render\BarPainter;
use MADSCORE\Render\PagePainter;
use MADSCORE\Render\PagePreview;
use MADSCORE\Render\PdfWriter;
use MADSCORE\Score\Figure;
use MADSCORE\Score\FigurePlayback;
use MADSCORE\Score\Project;
use MADSCORE\Score\Score;

function expectFigure(bool $condition, string $message): void {
  if (!$condition) {
    throw new RuntimeException($message);
  }
}

function figureHasColor(\SPTK\Core\RasterImage $image, string $color): bool {
  $wanted = hexdec(substr($color, 1));
  for ($offset = 0; $offset < strlen($image->pixels); $offset += 4) {
    if (((unpack('L', substr($image->pixels, $offset, 4))[1] >> 8) & 0xffffff) === $wanted) {
      return true;
    }
  }
  return false;
}

function figureHasColorIn(\SPTK\Core\RasterImage $image, string $color,
  int $left, int $top, int $right, int $bottom): bool {
  $wanted = hexdec(substr($color, 1));
  for ($y = $top; $y < $bottom; $y++) {
    for ($x = $left; $x < $right; $x++) {
      $offset = ($y * $image->width + $x) * 4;
      if (((unpack('L', substr($image->pixels, $offset, 4))[1] >> 8) & 0xffffff) === $wanted) {
        return true;
      }
    }
  }
  return false;
}

function figurePixel(\SPTK\Core\RasterImage $image, int $x, int $y): int {
  $offset = ($y * $image->width + $x) * 4;
  return (unpack('L', substr($image->pixels, $offset, 4))[1] >> 8) & 0xffffff;
}

function figureBar(Score $score, Project $project, bool $pdf = false): \SPTK\Core\RasterImage {
  $cairo = MADSCORE\Render\Cairo::library();
  $surface = $cairo->cairo_image_surface_create(0, 480, 120);
  $context = $cairo->cairo_create($surface);
  try {
    BarPainter::draw($cairo, $context, $score, 0, 4, 480, 120,
      showSlots: !$pdf, figures: new FigureCatalog($score, $project),
      printMarks: $pdf);
    return MADSCORE\Render\Cairo::raster($surface, 480, 120);
  } finally {
    $cairo->cairo_destroy($context);
    $cairo->cairo_surface_destroy($surface);
  }
}

function figureOnsets(string $midi): array {
  $offset = 14;
  $conductorLength = unpack('N', substr($midi, $offset + 4, 4))[1];
  $offset += 8 + $conductorLength;
  $length = unpack('N', substr($midi, $offset + 4, 4))[1];
  $track = substr($midi, $offset + 8, $length);
  $position = 0;
  $tick = 0;
  $result = [];
  while ($position < strlen($track)) {
    $delta = 0;
    do {
      $byte = ord($track[$position++]);
      $delta = ($delta << 7) | ($byte & 0x7f);
    } while ($byte & 0x80);
    $tick += $delta;
    $status = ord($track[$position++]);
    if ($status === 0xff) {
      $position++;
      $position += 1 + ord($track[$position]);
      continue;
    }
    $data = substr($track, $position, ($status & 0xf0) === 0xc0 ? 1 : 2);
    $position += strlen($data);
    if (($status & 0xf0) === 0x90 && ord($data[1]) > 0) {
      $result[] = [$tick, ord($data[0])];
    }
  }
  return $result;
}

$project = new Project();
$id = $project->create();
$motif = new Score();
$motif->place(0, 0, 0, 4, 4, 1);
$motif->place(0, 0, 1, 4, 5, 1);
$motif->place(0, 0, 2, 4, 6, 1);
$project->setScore($id, $motif);
$score = new Score();
$simple = $score->place(0, 0, 0, 4, 4, 2);
$ornament = $score->place(0, 0, 2, 4, 4, 2);
$complex = $score->place(0, 0, 4, 4, 4, 2);
$score->setFigure($simple, Figure::parse('+2,+0', $score->notes[$simple]));
$score->setFigure($ornament, Figure::parse('O:A', $score->notes[$ornament]));
$score->setFigure($complex, Figure::parse('T:A', $score->notes[$complex]));
$project->setScore('main', $score);
$plainMark = new Score();
$plainMark->place(0, 0, 0, 4, 4, 2);
$marked = Score::fromData($plainMark->data());
$marked->setFigure(0, Figure::parse('O:A', $marked->notes[0]));
$plainImage = figureBar($plainMark, $project);
$markedImage = figureBar($marked, $project);
expectFigure(!figureHasColorIn($plainImage, $plainMark->melodies[0]['color'], 28, 62, 34, 78)
  && figureHasColorIn($markedImage, $marked->melodies[0]['color'], 28, 62, 34, 78),
  'An ornament reference uses the square-ended special note body.');
expectFigure(figureHasColorIn($markedImage, $marked->melodies[0]['color'], 40, 64, 80, 76)
  && !figureHasColorIn($markedImage, $marked->melodies[0]['color'], 0, 0, 120, 58),
  'The editor reference appears inside the special note.');
$plainPdfTile = figureBar($plainMark, $project, true);
$markedPdfTile = figureBar($marked, $project, true);
expectFigure(MADSCORE\Render\PdfLayout::markFontSize() === 32.0
  && $plainPdfTile->pixels !== $markedPdfTile->pixels,
  'The PDF uses the page-number size for figure marks at the note.');
$singleFigure = new Score();
$singleFigure->place(0, 0, 0, 4, 5, 2);
$singleFigure->setFigure(0, Figure::parse('+2,+0', $singleFigure->notes[0]));
$figureTile = BarPainter::render($singleFigure, 0, 4, 240, 120);
expectFigure(figureHasColor($figureTile, $singleFigure->melodies[0]['color']),
  'The special note uses its melody color.');
expectFigure(Score::fromData($score->data())->data() === $score->data(),
  'Figures survive the score JSON round trip.');
expectFigure(Project::fromData($project->data())->data() === $project->data(),
  'Figures survive the project JSON round trip.');
expectFigure(Figure::format($score->notes[$simple]['figure']) === '+2+0'
  && FigurePlayback::segments($score->notes[$simple], $project)[1]['start'] === 1 / 3,
  'A compact three-note tuplet uses thirds of the written span.');
expectFigure(Figure::parse('+2-1', $score->notes[$simple])
    === Figure::parse('+2, -1', $score->notes[$simple])
  && Figure::parse('+2,, -1', $score->notes[$simple])
    === Figure::parse('+2-1', $score->notes[$simple])
  && Figure::format(Figure::parse('+2, -1', $score->notes[$simple])) === '+2-1',
  'Compact offsets and extra separators parse to the same canonical figure.');
expectFigure(Figure::parse('OA', $score->notes[$ornament])
    === Figure::parse('O:A', $score->notes[$ornament])
  && Figure::parse('T A', $score->notes[$complex])
    === Figure::parse('TA', $score->notes[$complex])
  && Figure::format($score->notes[$ornament]['figure']) === 'OA'
  && Figure::format($score->notes[$complex]['figure']) === 'TA',
  'Motif references accept optional separators and display compactly.');
expectFigure(Figure::parse('T:Z', $score->notes[$complex])
  === ['type' => 'tuplet', 'motif' => 'Z'],
  'Any single-letter motif from A through Z can be referenced.');
$catalog = new FigureCatalog($score, $project);
expectFigure(count($catalog->entries()) === 1 && $catalog->entries()[0]['number'] === 1
  && !isset($catalog->entries()[0]['rays'])
  && $catalog->entries()[0]['notes'][1]['black'] === true
  && $catalog->entries()[0]['ornamentError'] === null
  && isset($catalog->entries()[0]['types']['ornament'], $catalog->entries()[0]['types']['tuplet']),
  'Ornament and tuplet uses of motif A share one star and keep both appendix formats.');
$cairo = MADSCORE\Render\Cairo::library();
$surface = $cairo->cairo_image_surface_create(0, 1754, 1240);
$context = $cairo->cairo_create($surface);
try {
  $cairo->cairo_scale($context, 0.5, 0.5);
  FigureAppendix::drawPage($cairo, $context, FigureAppendix::pages($catalog)[0]);
  $appendix = MADSCORE\Render\Cairo::raster($surface, 1754, 1240);
} finally {
  $cairo->cairo_destroy($context);
  $cairo->cairo_surface_destroy($surface);
}
expectFigure(figurePixel($appendix, 170, 143) === 0xffffff
  && figurePixel($appendix, 184, 256) === 0x000000,
  'An ornament black-key interval stays hollow while the original tuplet key is filled.');
$wideMotif = new Score();
$wideMotif->place(0, 0, 0, 4, 4, 1);
$wideMotif->place(0, 0, 1, 4, 7, 1);
$wideProject = new Project();
$wideProject->create();
$wideProject->setScore('A', $wideMotif);
expectFigure(str_starts_with((new FigureCatalog($score, $wideProject))->entries()[0]['ornamentError'],
  'Invalid ornament:'), 'An ornament outside the two-semitone band gets an appendix error.');
$markerScore = new Score();
foreach ([['E', 'O'], ['F', 'O'], ['F', 'T'], ['G', 'T']] as [$id, $type]) {
  $index = $markerScore->place(0, 0, count($markerScore->notes) * 2, 4, 4, 2);
  $markerScore->setFigure($index, Figure::parse($type . ':' . $id, $markerScore->notes[$index]));
}
$markers = (new FigureCatalog($markerScore))->entries();
expectFigure(count($markers) === 3 && array_column($markers, 'number') === [5, 6, 7],
  'E, F, and G use their motif numbers after the same star.');
$lastMarker = new Score();
$lastMarker->place(0, 0, 0, 4, 4, 2);
$lastMarker->setFigure(0, Figure::parse('T:Z', $lastMarker->notes[0]));
expectFigure((new FigureCatalog($lastMarker))->entries()[0]['number'] === 26,
  'Motif Z uses star 26.');
expectFigure(PagePainter::pageCount($score, $project) === 1,
  'A short one-page score keeps its figure appendix on the score page.');
$figureStates = MADSCORE\Render\Prescription::states($score, figures: $catalog);
[$inlineTiles, $separatePages] = PagePainter::appendixPlacement(
  PagePainter::pages($score, $figureStates), $figureStates, $catalog);
expectFigure(count($inlineTiles) === 1 && $separatePages === []
  && $inlineTiles[0]['y'] >= 645 && $inlineTiles[0]['y'] < 1200,
  'The inline appendix starts below the score rather than covering it.');
$longScore = new Score();
$longNote = $longScore->place(0, 0, 0, 4, 4, 2);
$longScore->place(0, 80, 0, 4, 4, 2);
$longScore->setFigure($longNote, Figure::parse('TA', $longScore->notes[$longNote]));
$longCatalog = new FigureCatalog($longScore, $project);
$longStates = MADSCORE\Render\Prescription::states($longScore, figures: $longCatalog);
$longPages = PagePainter::pages($longScore, $longStates);
[$longInline, $longAppendix] = PagePainter::appendixPlacement(
  $longPages, $longStates, $longCatalog);
expectFigure(count($longPages) > 1 && $longInline === [] && count($longAppendix) === 1,
  'A multipage score keeps its appendix on a separate page.');
$packedProject = new Project();
$packedScore = new Score();
for ($index = 0; $index < 20; $index++) {
  $motifId = $packedProject->create();
  $tallMotif = new Score();
  foreach ([2, 3, 4, 5] as $row => $octave) {
    $tallMotif->place(0, 0, $row * 2, $octave, 4, 2);
  }
  $packedProject->setScore($motifId, $tallMotif);
  $note = $packedScore->place(0, intdiv($index, 4), ($index % 4) * 2, 4, 4, 2);
  $packedScore->setFigure($note,
    Figure::parse('T:' . $motifId, $packedScore->notes[$note]));
}
$packedProject->setScore('main', $packedScore);
$packedPages = FigureAppendix::pages(new FigureCatalog($packedScore, $packedProject));
expectFigure(count($packedPages) === 2,
  'Appendix tiles continue into columns before starting another page.');
expectFigure($packedPages[0][1]['x'] === $packedPages[0][0]['x']
  && $packedPages[0][1]['y'] > $packedPages[0][0]['y'],
  'Appendix tiles fill each column downward.');
$nextColumn = array_values(array_filter($packedPages[0],
  static fn (array $tile): bool => $tile['x'] > $packedPages[0][0]['x']));
expectFigure($nextColumn !== [] && $nextColumn[0]['y'] === $packedPages[0][0]['y'],
  'A new appendix column starts at the top.');
expectFigure($packedPages[1][0]['x'] === $packedPages[0][0]['x']
  && $packedPages[1][0]['y'] === $packedPages[0][0]['y']
  && PagePainter::pageCount($packedScore, $packedProject)
    === count(PagePainter::pages($packedScore)) + 2,
  'An overflow appendix page restarts at the top left and is counted in the PDF.');
$onsets = figureOnsets(MidiWriter::encode($score, project: $project));
expectFigure($onsets === [[0, 72], [160, 74], [320, 72],
  [480, 72], [640, 73], [800, 74], [960, 72], [1120, 73], [1280, 74]],
  'MIDI exports three correctly timed pitches for each figure.');
$source = new ScoreSource($score, project: $project);
$events = (new ReflectionProperty(ScoreSource::class, 'events'))->getValue($source);
expectFigure(count($events) === 9, 'The built-in audio source expands all three figures.');
$image = PagePreview::render($score, 0, 842, 595, $project);
expectFigure($image->width === 842 && $image->height === 595,
  'The PDF viewer renders a score page with an inline appendix.');
$path = tempnam(sys_get_temp_dir(), 'madscore-figures-');
try {
  PdfWriter::write($path, $score, $project);
  expectFigure(str_starts_with(file_get_contents($path), '%PDF-'),
    'PDF export includes the figure appendix.');
} finally {
  unlink($path);
}
$invalidMotif = $project->score('A');
$invalidMotif->addMelody();
$invalidMotif->place(1, 0, 0, 4, 4, 1);
$project->setScore('A', $invalidMotif);
expectFigure(str_starts_with((new FigureCatalog($score, $project))->entries()[0]['error'],
  'Invalid figure:'), 'A polyphonic motif gets an error entry.');
expectFigure(PagePreview::render($score, 1, 842, 595, $project)->width === 842,
  'The appendix prints an invalid figure message without failing.');
$score->setStaccato($ornament, true);
expectFigure(!isset($score->notes[$ornament]['figure']),
  'Changing to a conflicting mark clears the old figure.');
echo "Figure checks passed.\n";
