<?php

define('APP_DIR', dirname(__DIR__));
define('APP_NAMESPACE', 'MADSCORE');
require APP_DIR . '/SPTK/App.php';

function expectArticulation(bool $condition, string $message): void {
  if (!$condition) {
    throw new RuntimeException($message);
  }
}

function articulationPixel(SPTK\Core\RasterImage $image, int $x, int $y): int {
  return (unpack('L', substr($image->pixels, ($y * $image->width + $x) * 4, 4))[1] >> 8) & 0xffffff;
}

function articulationChangedRows(SPTK\Core\RasterImage $before, SPTK\Core\RasterImage $after): array {
  $rows = [];
  for ($y = 0; $y < $before->height; $y++) {
    for ($x = 0; $x < $before->width; $x++) {
      if (articulationPixel($before, $x, $y) !== articulationPixel($after, $x, $y)) {
        $rows[] = $y;
        break;
      }
    }
  }
  return $rows;
}

function articulationChangedColumn(SPTK\Core\RasterImage $before, SPTK\Core\RasterImage $after, int $x): int {
  $count = 0;
  for ($y = 0; $y < $before->height; $y++) {
    $count += (int)(articulationPixel($before, $x, $y) !== articulationPixel($after, $x, $y));
  }
  return $count;
}

function articulationWindow(MADSCORE\Score\Score $score, float $start, float $length = 0.01): float {
  $source = new MADSCORE\Play\ScoreSource($score);
  $source->read(max(1, (int)round($start * MADSCORE\Play\ScoreSource::SAMPLE_RATE)));
  $samples = unpack('g*', $source->read((int)round($length * MADSCORE\Play\ScoreSource::SAMPLE_RATE)));
  return sqrt(array_sum(array_map(static fn (float $sample): float => $sample * $sample, $samples)) / count($samples));
}

$score = new MADSCORE\Score\Score();
$first = $score->place(0, 0, 0, 4, 1, 2);
$second = $score->place(0, 0, 2, 4, 3, 2);
expectArticulation($score->nextAdjacent($first) === $second, 'The next note starts at the first note ending.');
expectArticulation(articulationWindow($score, 0.480) < 0.0001, 'Ordinary notes have a short silent gap.');
$score->setStaccato($first, true);
expectArticulation(articulationWindow($score, 0.300) < 0.0001, 'Staccato ends at half the written duration.');
expectArticulation(articulationWindow($score, 0.200) > 0.02, 'Staccato still sounds during its first half.');
$score->setSlurToNext($first, true);
expectArticulation($score->notes[$first]['articulation'] === 'ordinary', 'A slur clears conflicting staccato.');
expectArticulation(articulationWindow($score, 0.480) > 0.02, 'A slur carries sound through the ordinary gap.');
$score->setFermata($first, true);
expectArticulation($score->notes[$first]['articulation'] === 'ordinary' && !$score->notes[$first]['slurToNext'],
  'A fermata clears conflicting articulations.');
$isolated = new MADSCORE\Score\Score();
$held = $isolated->place(0, 0, 0, 4, 1, 2);
$isolated->setFermata($held, true);
expectArticulation(articulationWindow($isolated, 0.600) > 0.02, 'A fermata sustains a note past its written end.');
expectArticulation(articulationWindow($isolated, 0.800) < 0.0001, 'A fermata ends after 1.5 times its written duration.');
$parallel = new MADSCORE\Score\Score(2);
$long = $parallel->place(0, 0, 0, 4, 1, 8);
$short = $parallel->place(1, 0, 4, 4, 4, 4);
$parallel->setFermata($long, true);
$parallel->setFermata($short, true);
$parallelSource = new MADSCORE\Play\ScoreSource($parallel);
$parallelEvents = (new ReflectionProperty(MADSCORE\Play\ScoreSource::class, 'events'))->getValue($parallelSource);
expectArticulation(count($parallelEvents) === 2
  && $parallelEvents[0]['ending'] === $parallelEvents[1]['ending']
  && abs($parallelSource->durationSeconds() - 3.0) < 0.0001,
  'Parallel built-in fermatas release together at the longest note’s extended end.');
expectArticulation(MADSCORE\Score\Score::fromData($score->data())->data() === $score->data(), 'Articulation survives a score round trip.');
$score->setStaccato($first, true);
expectArticulation(!$score->notes[$first]['fermata'], 'Staccato clears a fermata.');
$score->setFermata($first, true);
$invalidFermata = $score->data();
$invalidFermata['notes'][0]['fermata'] = 'yes';
try {
  MADSCORE\Score\Score::fromData($invalidFermata);
  throw new RuntimeException('An invalid fermata was accepted.');
} catch (DomainException $error) {
  expectArticulation(true, 'Fermata is validated as a boolean.');
}
$invalidArticulation = $score->data();
$invalidArticulation['notes'][0]['articulation'] = 'unknown';
try {
  MADSCORE\Score\Score::fromData($invalidArticulation);
  throw new RuntimeException('An invalid articulation was accepted.');
} catch (DomainException $error) {
  expectArticulation(true, 'Known articulation fields are validated.');
}

$tempoScore = new MADSCORE\Score\Score();
$tempoNote = $tempoScore->place(0, 0, 7, 4, 1, 2);
$tempoScore->setTempo(1, '60');
$tempoScore->setStaccato($tempoNote, true);
$clip = new MADSCORE\Play\ScoreSource($tempoScore, null, 7, 9);
$clip->read((int)(0.30 * MADSCORE\Play\ScoreSource::SAMPLE_RATE));
$activeSamples = unpack('g*', $clip->read(480));
expectArticulation(max(array_map('abs', $activeSamples)) > 0.02,
  'A selected staccato note stays audible before half its duration across a tempo change.');
$clip->read((int)(0.14 * MADSCORE\Play\ScoreSource::SAMPLE_RATE));
$silentSamples = unpack('g*', $clip->read(480));
expectArticulation(max(array_map('abs', $silentSamples)) < 0.0001,
  'The selected note ends at half its real-time duration across a tempo change.');

$input = $score->data();
$input['version'] = 7;
$input['extra'] = 1;
$input['bars'][0]['extra'] = true;
$input['melodies'][0]['extra'] = 'ignored';
$input['notes'][0]['extra'] = ['ignored'];
unset($input['notes'][1]['articulation'], $input['notes'][1]['slurToNext'], $input['notes'][1]['fermata']);
$loaded = MADSCORE\Score\Score::fromData($input)->data();
expectArticulation(!array_key_exists('version', $loaded) && !array_key_exists('extra', $loaded) && !isset($loaded['bars'][0]['extra'])
  && !isset($loaded['melodies'][0]['extra']) && !isset($loaded['notes'][0]['extra']),
  'Unknown fields are omitted at every level.');
expectArticulation($loaded['notes'][1]['articulation'] === 'ordinary' && !$loaded['notes'][1]['slurToNext'] && !$loaded['notes'][1]['fermata'],
  'Missing articulation fields use ordinary defaults.');

$lastScore = new MADSCORE\Score\Score();
$last = $lastScore->place(0, 0, 6, 4, 4, 2);
$lastScore->setFermata($last, true);
$tail = new MADSCORE\Play\ScoreSource($lastScore);
expectArticulation(strlen($tail->read(3 * MADSCORE\Play\ScoreSource::SAMPLE_RATE)) === (int)(2.25 * MADSCORE\Play\ScoreSource::SAMPLE_RATE) * 4,
  'A final fermata extends playback past the written score end.');
$lastScore->ensureBar(1);
$selectedTail = new MADSCORE\Play\ScoreSource($lastScore, null, 8, 10);
$tailSamples = unpack('g*', $selectedTail->read(480));
expectArticulation(max(array_map('abs', $tailSamples)) > 0.02,
  'A range starting after the written note end still includes its fermata tail.');

$ordinary = new MADSCORE\Score\Score();
$note = $ordinary->place(0, 0, 0, 4, 4, 2);
$plainTile = MADSCORE\Render\BarPainter::render($ordinary, 0, 4, 240, 240);
expectArticulation(articulationPixel($plainTile, 30, 140) === 0xffffff, 'The hollow note center starts white.');
$ordinary->setStaccato($note, true);
$dotTile = MADSCORE\Render\BarPainter::render($ordinary, 0, 4, 240, 240);
expectArticulation(articulationPixel($dotTile, 30, 140) !== 0xffffff, 'Staccato marks the center of a hollow note.');
$black = new MADSCORE\Score\Score();
$blackNote = $black->place(0, 0, 0, 4, 5, 2);
$black->setStaccato($blackNote, true);
$blackTile = MADSCORE\Render\BarPainter::render($black, 0, 4, 240, 240);
expectArticulation(articulationPixel($blackTile, 30, 120) === 0xffffff, 'The mark contrasts with a filled note.');
$triangle = new MADSCORE\Score\Score();
$triangleNote = $triangle->place(0, 0, 0, 4, 4, 2);
$roundTile = MADSCORE\Render\BarPainter::render($triangle, 0, 4, 240, 240);
$triangle->setFermata($triangleNote, true);
$triangleTile = MADSCORE\Render\BarPainter::render($triangle, 0, 4, 240, 240);
expectArticulation($roundTile->pixels !== $triangleTile->pixels, 'A fermata changes the curved note end to a triangle.');

$across = new MADSCORE\Score\Score();
$across->ensureBar(1);
$linked = $across->place(0, 0, 6, 4, 1, 2);
$across->place(0, 1, 0, 4, 3, 2);
$before = MADSCORE\Render\SheetPainter::render($across, 0, 2, 500, 490);
$across->setSlurToNext($linked, true);
$after = MADSCORE\Render\SheetPainter::render($across, 0, 2, 500, 490);
expectArticulation($before->pixels !== $after->pixels, 'A slur spanning a bar is visible on the sheet.');
$phrase = new MADSCORE\Score\Score();
$phraseFirst = $phrase->place(0, 0, 0, 4, 1, 2);
$phraseMiddle = $phrase->place(0, 0, 2, 4, 1, 2);
$phraseLast = $phrase->place(0, 0, 4, 4, 1, 2);
$phrase->setSlurToNext($phraseFirst, true);
$phrase->setSlurToNext($phraseMiddle, true);
expectArticulation((new ReflectionMethod(MADSCORE\Render\SlurPainter::class, 'groups'))->invoke(null, $phrase)
  === [[$phraseFirst, $phraseMiddle, $phraseLast]], 'Successive links form one drawn phrase.');
$lowPlain = MADSCORE\Render\SheetPainter::render($phrase, 0, 1, 250, 490, design: true);
$phrase->setSlurToNext($phraseFirst, false);
$phrase->setSlurToNext($phraseMiddle, false);
$lowWithout = MADSCORE\Render\SheetPainter::render($phrase, 0, 1, 250, 490, design: true);
$lowRows = articulationChangedRows($lowWithout, $lowPlain);
expectArticulation($lowRows !== [] && min($lowRows) > 230, 'A phrase beginning below D curves downward.');
expectArticulation(articulationChangedColumn($lowWithout, $lowPlain, 115)
  > articulationChangedColumn($lowWithout, $lowPlain, 48), 'The curve is bolder in the middle.');
$upPhrase = new MADSCORE\Score\Score();
$upFirst = $upPhrase->place(0, 0, 0, 4, 6, 2);
$upPhrase->place(0, 0, 2, 4, 6, 2);
$upBefore = MADSCORE\Render\SheetPainter::render($upPhrase, 0, 1, 250, 490, design: true);
$upPhrase->setSlurToNext($upFirst, true);
$upAfter = MADSCORE\Render\SheetPainter::render($upPhrase, 0, 1, 250, 490, design: true);
$upRows = articulationChangedRows($upBefore, $upAfter);
expectArticulation($upRows !== [] && max($upRows) < 220, 'A phrase beginning on D curves upward.');
$lineBreak = new MADSCORE\Score\Score();
$lineBreak->ensureBar(14);
$lastOnLine = $lineBreak->place(0, 10, 6, 4, 1, 2);
$lineBreak->place(0, 11, 0, 4, 3, 2);
expectArticulation(end(MADSCORE\Render\PagePainter::pages($lineBreak)[0][0]) === 10,
  'The linked notes straddle a printed line boundary.');
$plainPage = MADSCORE\Render\PagePreview::render($lineBreak, 0, 1400, 1000);
$lineBreak->setSlurToNext($lastOnLine, true);
$linkedPage = MADSCORE\Render\PagePreview::render($lineBreak, 0, 1400, 1000);
$split = 250 * 1400 * 4;
expectArticulation(substr($plainPage->pixels, 0, $split) !== substr($linkedPage->pixels, 0, $split)
  && substr($plainPage->pixels, $split) !== substr($linkedPage->pixels, $split),
  'A slur continues at both sides of a printed line break.');
$pdf = tempnam(sys_get_temp_dir(), 'madscore-slur-');
try {
  MADSCORE\Render\PdfWriter::write($pdf, $across);
  expectArticulation(str_starts_with(file_get_contents($pdf), '%PDF-'), 'A linked score exports to PDF.');
} finally {
  unlink($pdf);
}
$across->setLength($linked, 1);
expectArticulation(!$across->notes[$linked]['slurToNext'], 'Moving the next note away clears the slur.');

echo "Articulation checks passed\n";
