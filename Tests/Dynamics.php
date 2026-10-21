<?php

define('APP_DIR', dirname(__DIR__));
define('APP_NAMESPACE', 'MADSCORE');
require APP_DIR . '/SPTK/App.php';

function expectDynamic(bool $condition, string $message): void {
  if (!$condition) {
    throw new RuntimeException($message);
  }
}

function dynamicStrength(string $pcm, int $startFrame, int $frames): float {
  $samples = unpack('g*', substr($pcm, $startFrame * 4, $frames * 4));
  $sum = 0.0;
  foreach ($samples as $sample) {
    $sum += $sample * $sample;
  }
  return sqrt($sum / count($samples));
}

/** Count melody wedge shortcuts dispatched by the keyboard regression checks. */
function consumeMelodyWedgeShortcut(SPTK\Events\EventContext $event): bool {
  $GLOBALS['melodyWedgeShortcutCount'] = ($GLOBALS['melodyWedgeShortcutCount'] ?? 0) + 1;
  return true;
}

$score = new MADSCORE\Score\Score();
$score->setMelodyPattern(0, 'sine');
$first = $score->place(0, 0, 0, 4, 1, 2);
$middle = $score->place(0, 0, 2, 4, 1, 2);
$last = $score->place(0, 0, 4, 4, 1, 2);
$following = $score->place(0, 0, 6, 4, 1, 2);
$score->setMelodyVolume(0, 70);
$score->setDynamicLevel($first, 'ppp');
$score->setDynamicLevel($last, 'fff');
expectDynamic(MADSCORE\Score\DynamicLevel::percent('ppp') === 20
  && MADSCORE\Score\DynamicLevel::percent('fff') === 100
  && MADSCORE\Score\DynamicLevel::next('') === 'ppp'
  && MADSCORE\Score\DynamicLevel::next('fff') === '',
  'The level button cycles through written levels and a clear state.');
$fitted = new MADSCORE\Score\Score();
$fitted->setMelodyVolume(0, 50);
$fittedMf = $fitted->place(0, 0, 0, 4, 1, 2);
$fittedF = $fitted->place(0, 0, 2, 4, 1, 2);
$fittedMp = $fitted->place(0, 0, 4, 4, 1, 2);
$fitted->setDynamicLevel($fittedMf, 'mf');
$fitted->setDynamicLevel($fittedF, 'f');
$fitted->setDynamicLevel($fittedMp, 'mp');
$fitted->setDynamicToNext($fittedMf, 'crescendo');
$fitted->setDynamicToNext($fittedF, 'decrescendo');
$fittedLevels = MADSCORE\Score\DynamicLevel::playbackLevels($fitted);
expectDynamic(abs($fittedLevels['f'] - 1.0) < 0.001
  && $fittedLevels['f'] / $fittedLevels['mf'] > 1.5
  && $fittedLevels['mf'] / $fittedLevels['mp'] > 1.5
  && $fittedLevels['mp'] >= 0.20,
  'The used mp–f range gives each adjacent level a clear change within the 20–100% bounds.');
$fittedAudio = (new MADSCORE\Play\ScoreSource($fitted))->read(2 * MADSCORE\Play\ScoreSource::SAMPLE_RATE);
$mfStrength = dynamicStrength($fittedAudio, (int)(0.04 * MADSCORE\Play\ScoreSource::SAMPLE_RATE), 2400);
$fStrength = dynamicStrength($fittedAudio, (int)(0.54 * MADSCORE\Play\ScoreSource::SAMPLE_RATE), 2400);
$mpStrength = dynamicStrength($fittedAudio, (int)(1.04 * MADSCORE\Play\ScoreSource::SAMPLE_RATE), 2400);
expectDynamic($fStrength > $mfStrength * 1.3 && $fStrength > $mpStrength * 2.0,
  'At 50% melody volume, an mf–f crescendo and f–mp decrescendo remain audible in rendered audio.');
$fitted->setDynamicLevel($fittedF, '');
$fitted->setDynamicLevel($fittedMp, '');
$fitted->setDynamicToNext($fittedMf, '');
$fitted->setDynamicToNext($fittedF, '');
expectDynamic(abs(MADSCORE\Score\DynamicLevel::playbackLevels($fitted)['mf'] - 0.60) < 0.001,
  'A score with only one written level keeps its familiar default loudness.');
$fitted->setDynamicLevel($fittedF, 'f');
$fitted->setScoreDynamicLevel(4, 'mp');
expectDynamic(abs(MADSCORE\Score\DynamicLevel::playbackLevels($fitted)['mp'] - $fittedLevels['mp']) < 0.001,
  'Shared and melody levels use one score-wide playback range.');
expectDynamic(MADSCORE\Score\Dynamics::nextDirection('') === 'crescendo'
  && MADSCORE\Score\Dynamics::nextDirection('crescendo') === 'decrescendo'
  && MADSCORE\Score\Dynamics::nextDirection('decrescendo') === '',
  'The Volume control cycles through plus, minus, and equals.');
try {
  $score->setDynamicLevel($first, 'loud');
  throw new RuntimeException('An invalid level was accepted.');
} catch (DomainException) {
  expectDynamic($score->notes[$first]['dynamicLevel'] === 'ppp', 'Invalid levels leave the note unchanged.');
}
try {
  $score->setDynamicLevel($first, '42');
  throw new RuntimeException('A new numeric level was accepted.');
} catch (DomainException) {
  expectDynamic($score->notes[$first]['dynamicLevel'] === 'ppp', 'New levels use written marks only.');
}
$score->setDynamicToNext($first, 'crescendo');
$score->setDynamicToNext($middle, 'crescendo');
$groups = MADSCORE\Score\Dynamics::groups($score);
expectDynamic(count($groups) === 1 && $groups[0]['indices'] === [$first, $middle, $last]
  && $groups[0]['start'] === 0 && $groups[0]['end'] === 6,
  'Consecutive crescendos form one span through the closing note.');
$positions = MADSCORE\Render\Prescription::positions([0], [267], MADSCORE\Render\Prescription::states($score), 44);
$segments = MADSCORE\Render\DynamicPainter::segments($score, $positions, 220);
$labels = MADSCORE\Render\DynamicPainter::labels($score, $positions, 220);
expectDynamic(count($segments) === 1 && $segments[0]['left'] < $segments[0]['right']
  && $segments[0]['leftHeight'] == 0 && $segments[0]['rightHeight'] == 1
  && $segments[0]['color'] === $score->melodies[0]['color'],
  'A crescendo rises from the first note edge to the closing note edge in the melody color.');
expectDynamic(count($labels) === 2 && $labels[0]['text'] === 'ppp' && $labels[1]['text'] === 'fff'
  && $labels[0]['x'] < $segments[0]['left'] && $labels[1]['x'] > $segments[0]['right'],
  'The starting level appears before the wedge and the closing level after it.');
$wrapped = new MADSCORE\Score\Score();
$wrapped->ensureBar(1);
$boundary = $wrapped->start(1);
$wrapped->setScoreDynamicLevel($boundary, 'mp');
$wrapped->setScoreDynamicWedge(0, $boundary, 'decrescendo');
$wrappedStates = MADSCORE\Render\Prescription::states($wrapped);
$previousRow = MADSCORE\Render\Prescription::positions([0], [267], $wrappedStates, 44);
$nextRow = MADSCORE\Render\Prescription::positions([1], [267], $wrappedStates, 44);
expectDynamic(MADSCORE\Render\DynamicPainter::labels($wrapped, $previousRow, 220) === []
  && count(MADSCORE\Render\DynamicPainter::labels($wrapped, $nextRow, 220)) === 1
  && abs(MADSCORE\Render\DynamicPainter::labels($wrapped, $nextRow, 220)[0]['x'] - $nextRow[0]['left']) < 0.01
  && count(MADSCORE\Render\DynamicPainter::segments($wrapped, $previousRow, 220)) === 1,
  'A level at a wrapped row start prints only on its own row while the preceding wedge still ends at the boundary.');
$tempoLevels = new MADSCORE\Score\Score();
$tempoLevels->setBeats(0, 1);
$tempoNote = $tempoLevels->place(0, 0, 0, 4, 1, 1);
$tempoLevels->setDynamicLevel($tempoNote, 'mf');
$tempoLevels->setScoreDynamicLevel(0, 'mp');
$tempoWidth = (new ReflectionMethod(MADSCORE\Render\PagePainter::class, 'barWidth'))->invoke(null, $tempoLevels, 0);
$tempoPositions = MADSCORE\Render\Prescription::positions([0], [$tempoWidth],
  MADSCORE\Render\Prescription::states($tempoLevels), 44);
$tempoEnd = $tempoPositions[0]['left'] + MADSCORE\Render\TempoPainter::width('120') + 8;
$tempoLabels = MADSCORE\Render\DynamicPainter::labels($tempoLevels, $tempoPositions, 220, [0 => $tempoEnd]);
expectDynamic(count($tempoLabels) === 2 && $tempoLabels[0]['x'] >= $tempoEnd
  && $tempoLabels[1]['x'] >= $tempoLabels[0]['x'] + strlen('mp') * max(12, 220 / 7.5) * 0.63 + 4
  && $tempoWidth >= MADSCORE\Render\TempoPainter::width('120') + 8
    + MADSCORE\Render\DynamicPainter::startLabelWidth($tempoLevels, 0, 220),
  'Tempo, shared level, and melody level print side by side with space reserved in a short bar.');
$tempoPreview = MADSCORE\Render\PagePreview::render($tempoLevels, 0, 842, 595);
expectDynamic($tempoPreview->width === 842 && $tempoPreview->height === 595,
  'The PDF preview renders a bar containing tempo and both kinds of level marks.');
$sheetMarked = MADSCORE\Render\SheetPainter::render($score, 0, 1, 500, 490);
$pageMarked = MADSCORE\Render\PagePreview::render($score, 0, 1200, 850);
$score->setDynamicToNext($first, '');
$score->setDynamicToNext($middle, '');
expectDynamic($sheetMarked->pixels !== MADSCORE\Render\SheetPainter::render($score, 0, 1, 500, 490)->pixels
  && $pageMarked->pixels !== MADSCORE\Render\PagePreview::render($score, 0, 1200, 850)->pixels,
  'Dynamic triangles appear on the editor sheet and PDF preview.');
$high = new MADSCORE\Score\Score();
$highFirst = $high->place(0, 0, 0, 8, 12, 2);
$highLast = $high->place(0, 0, 2, 8, 12, 2);
$plainHigh = MADSCORE\Score\Score::fromData($high->data());
$high->setDynamicToNext($highFirst, 'crescendo');
$high->setDynamicLevel($highFirst, 'ppp');
$high->setDynamicLevel($highLast, 'fff');
$high->setSectionRepeats(0, 2);
$clearance = static function (\SPTK\Core\RasterImage $plain, \SPTK\Core\RasterImage $marked, string $color): int {
  $noteColor = hexdec(substr($color, 1));
  $firstNote = $plain->height;
  $lastMark = -1;
  for ($y = 0; $y < $plain->height; $y++) {
    for ($x = 0; $x < $plain->width; $x++) {
      $offset = ($y * $plain->width + $x) * 4;
      $before = substr($plain->pixels, $offset, 4);
      if (((unpack('L', $before)[1] >> 8) & 0xffffff) === $noteColor) {
        $firstNote = min($firstNote, $y);
      }
      if ($before !== substr($marked->pixels, $offset, 4)) {
        $lastMark = max($lastMark, $y);
      }
    }
  }
  return $firstNote - $lastMark;
};
expectDynamic($clearance(
    MADSCORE\Render\SheetPainter::render($plainHigh, 0, 1, 500, 490, melody: 0, design: true),
    MADSCORE\Render\SheetPainter::render($high, 0, 1, 500, 490, melody: 0, design: true),
    $high->melodies[0]['color']) > 1
  && $clearance(
    MADSCORE\Render\PagePreview::render($plainHigh, 0, 842, 595),
    MADSCORE\Render\PagePreview::render($high, 0, 842, 595),
    $high->melodies[0]['color']) > 1,
  'Volume and repeat marks stay above the highest notes in the editor and PDF preview.');
$score->setDynamicToNext($first, 'crescendo');
$score->setDynamicToNext($middle, 'crescendo');
expectDynamic(MADSCORE\Score\Score::fromData($score->data())->data() === $score->data(),
  'Dynamic marks survive save and load.');
$old = $score->data();
unset($old['notes'][0]['dynamicToNext']);
unset($old['notes'][0]['dynamicLevel']);
expectDynamic(MADSCORE\Score\Score::fromData($old)->notes[0]['dynamicToNext'] === '',
  'Older scores default to no dynamic mark.');
expectDynamic(MADSCORE\Score\Score::fromData($old)->notes[0]['dynamicLevel'] === '',
  'Older scores default to no dynamic level.');
$legacyPercent = $score->data();
$legacyPercent['notes'][0]['dynamicLevel'] = '42';
expectDynamic(MADSCORE\Score\Score::fromData($legacyPercent)->notes[0]['dynamicLevel'] === '42',
  'Saved numeric levels remain readable for older scores.');
$invalid = $score->data();
$invalid['notes'][0]['dynamicToNext'] = 'loud';
try {
  MADSCORE\Score\Score::fromData($invalid);
  throw new RuntimeException('An invalid dynamic mark was accepted.');
} catch (DomainException) {
  expectDynamic(true, 'Unknown dynamic directions are rejected.');
}

$frames = (int)(2.0 * MADSCORE\Play\ScoreSource::SAMPLE_RATE);
$crescendo = (new MADSCORE\Play\ScoreSource($score))->read($frames);
$early = dynamicStrength($crescendo, (int)(0.12 * MADSCORE\Play\ScoreSource::SAMPLE_RATE), 2400);
$late = dynamicStrength($crescendo, (int)(1.12 * MADSCORE\Play\ScoreSource::SAMPLE_RATE), 2400);
expectDynamic($late > $early * 2.5, 'Crescendo reaches a clearly louder closing note.');
$held = dynamicStrength($crescendo, (int)(1.62 * MADSCORE\Play\ScoreSource::SAMPLE_RATE), 2400);
expectDynamic(abs($held / $late - 1) < 0.08,
  'The closing crescendo level persists on following unmarked notes.');
$score->setDynamicLevel($middle, 'mf');
$withMiddleLevel = (new MADSCORE\Play\ScoreSource($score))->read($frames);
$middleStrength = dynamicStrength($withMiddleLevel, (int)(0.62 * MADSCORE\Play\ScoreSource::SAMPLE_RATE), 2400);
$closingStrength = dynamicStrength($withMiddleLevel, (int)(1.12 * MADSCORE\Play\ScoreSource::SAMPLE_RATE), 2400);
expectDynamic($middleStrength > $closingStrength * 0.45 && $middleStrength < $closingStrength * 0.75,
  'A level inside a wedge becomes an audible intermediate point.');
$score->setDynamicLevel($middle, '');
$score->setDynamicLevel($first, 'fff');
$score->setDynamicLevel($last, 'ppp');
$score->setDynamicToNext($first, 'decrescendo');
$score->setDynamicToNext($middle, 'decrescendo');
$groups = MADSCORE\Score\Dynamics::groups($score);
$segments = MADSCORE\Render\DynamicPainter::segments($score, $positions, 220);
expectDynamic(count($groups) === 1 && $segments[0]['leftHeight'] == 1
  && $segments[0]['rightHeight'] == 0, 'A decrescendo narrows toward the closing note.');
$decrescendo = (new MADSCORE\Play\ScoreSource($score))->read($frames);
$early = dynamicStrength($decrescendo, (int)(0.12 * MADSCORE\Play\ScoreSource::SAMPLE_RATE), 2400);
$late = dynamicStrength($decrescendo, (int)(1.12 * MADSCORE\Play\ScoreSource::SAMPLE_RATE), 2400);
expectDynamic($early > $late * 2.5, 'Decrescendo reaches a clearly quieter closing note.');
$held = dynamicStrength($decrescendo, (int)(1.62 * MADSCORE\Play\ScoreSource::SAMPLE_RATE), 2400);
expectDynamic(abs($held / $late - 1) < 0.08,
  'The closing decrescendo level persists on following unmarked notes.');
$score->setMelodyPattern(0, 'kick');
$score->setDynamicLevel($first, 'ppp');
$score->setDynamicLevel($last, 'fff');
$score->setDynamicToNext($first, 'crescendo');
$score->setDynamicToNext($middle, 'crescendo');
$drums = (new MADSCORE\Play\ScoreSource($score))->read($frames);
$early = dynamicStrength($drums, (int)(0.01 * MADSCORE\Play\ScoreSource::SAMPLE_RATE), 1440);
$late = dynamicStrength($drums, (int)(1.01 * MADSCORE\Play\ScoreSource::SAMPLE_RATE), 1440);
expectDynamic($late > $early * 2.5, 'A crescendo also clearly changes drum-hit volume.');
$score->setDynamicToNext($first, '');
$score->setDynamicToNext($middle, '');
$score->setDynamicLevel($first, 'p');
$score->setDynamicLevel($last, '');
$score->setMelodyPattern(0, 'sine');
$standalone = (new MADSCORE\Play\ScoreSource($score))->read($frames);
$marked = dynamicStrength($standalone, (int)(0.12 * MADSCORE\Play\ScoreSource::SAMPLE_RATE), 2400);
$later = dynamicStrength($standalone, (int)(1.12 * MADSCORE\Play\ScoreSource::SAMPLE_RATE), 2400);
expectDynamic(abs($later / $marked - 1) < 0.08,
  'A standalone level persists without a wedge.');
$standalone = new MADSCORE\Score\Score();
$standalone->setMelodyPattern(0, 'sine');
$standalone->setMelodyVolume(0, 70);
$standalone->place(0, 0, 0, 4, 1, 2);
$levelNote = $standalone->place(0, 0, 2, 4, 1, 2);
$standalone->place(0, 0, 4, 4, 1, 2);
$standalone->setDynamicLevel($levelNote, 'p');
$standalonePositions = MADSCORE\Render\Prescription::positions([0], [267], MADSCORE\Render\Prescription::states($standalone), 44);
$standaloneLabels = MADSCORE\Render\DynamicPainter::labels($standalone, $standalonePositions, 220);
$onsetX = $standalonePositions[0]['left']
  + 2 * ($standalonePositions[0]['right'] - $standalonePositions[0]['left']) / 8;
expectDynamic(count($standaloneLabels) === 1 && abs($standaloneLabels[0]['x'] - $onsetX) < 0.01,
  'A standalone level starts at the marked note onset, not after its note head.');
$standaloneAudio = (new MADSCORE\Play\ScoreSource($standalone))->read($frames);
$beforeLevel = dynamicStrength($standaloneAudio, (int)(0.12 * MADSCORE\Play\ScoreSource::SAMPLE_RATE), 2400);
$atLevel = dynamicStrength($standaloneAudio, (int)(0.62 * MADSCORE\Play\ScoreSource::SAMPLE_RATE), 2400);
$afterLevel = dynamicStrength($standaloneAudio, (int)(1.12 * MADSCORE\Play\ScoreSource::SAMPLE_RATE), 2400);
expectDynamic($atLevel > $beforeLevel * 0.65 && $atLevel < $beforeLevel * 0.80
  && abs($afterLevel / $atLevel - 1) < 0.08,
  'The opening note uses the mp–mf baseline; a later p mark persists on the next note.');
$score->setDynamicToNext($first, 'crescendo');
$score->setDynamicToNext($middle, 'crescendo');
$score->setDynamicToNext($middle, '');
expectDynamic(count(MADSCORE\Score\Dynamics::groups($score)) === 1
  && MADSCORE\Score\Dynamics::groups($score)[0]['last'] === $middle,
  'Removing an outgoing mark closes the span at that note.');
try {
  $score->setDynamicToNext($following, 'crescendo');
  throw new RuntimeException('A dynamic mark with no next note was accepted.');
} catch (DomainException) {
  expectDynamic(true, 'A dynamic mark requires a following note.');
}
$withGap = new MADSCORE\Score\Score();
$gapStart = $withGap->place(0, 0, 0, 4, 1, 1);
$gapEnd = $withGap->place(0, 0, 4, 4, 1, 1);
$withGap->setDynamicToNext($gapStart, 'crescendo');
expectDynamic(MADSCORE\Score\Dynamics::groups($withGap)[0]['last'] === $gapEnd,
  'A dynamic mark reaches the next melody note across a rest.');

$shared = new MADSCORE\Score\Score(2);
$shared->setMelodyPattern(0, 'sine');
$shared->setMelodyPattern(1, 'sine');
$shared->setMelodyVolume(0, 50);
$shared->setMelodyVolume(1, 100);
$a = $shared->place(0, 0, 0, 4, 1, 2);
$shared->place(0, 0, 2, 4, 1, 2);
$shared->place(0, 0, 4, 4, 1, 2);
$b = $shared->place(1, 0, 0, 4, 1, 2);
$shared->place(1, 0, 2, 4, 1, 2);
$shared->place(1, 0, 4, 4, 1, 2);
$shared->setDynamicLevel($a, 'ppp');
$shared->setDynamicLevel($b, 'fff');
$shared->setScoreDynamicLevel(0, 'mf');
$shared->setScoreDynamicWedge(0, 4, 'crescendo');
$shared->setScoreDynamicLevel(4, 'fff');
expectDynamic(MADSCORE\Score\Score::fromData($shared->data())->data() === $shared->data(),
  'Shared levels and wedges survive a score round trip.');
$invalidShared = $shared->data();
$invalidShared['scoreDynamics'][0]['end'] = $invalidShared['scoreDynamics'][0]['time'];
try {
  MADSCORE\Score\Score::fromData($invalidShared);
  throw new RuntimeException('An invalid shared wedge was accepted.');
} catch (DomainException) {
  expectDynamic(true, 'Shared wedges require a later endpoint.');
}
$sharedPositions = MADSCORE\Render\Prescription::positions([0], [267], MADSCORE\Render\Prescription::states($shared), 44);
$sharedLabels = MADSCORE\Render\DynamicPainter::labels($shared, $sharedPositions, 220);
$sharedSegments = MADSCORE\Render\DynamicPainter::segments($shared, $sharedPositions, 220);
expectDynamic($sharedLabels[0]['filled'] && $sharedLabels[0]['color'] === '#000000'
  && !$sharedLabels[2]['filled'] && $sharedLabels[2]['color'] === $shared->melodies[0]['color']
  && $sharedSegments[0]['filled'] && $sharedSegments[0]['color'] === '#000000',
  'Shared marks are black and filled while melody marks are colored outlines.');
$withoutShared = MADSCORE\Score\Score::fromData($shared->data());
$withoutShared->scoreDynamics = [];
expectDynamic(MADSCORE\Render\SheetPainter::render($shared, 0, 1, 500, 490)->pixels
    !== MADSCORE\Render\SheetPainter::render($withoutShared, 0, 1, 500, 490)->pixels
  && MADSCORE\Render\PagePreview::render($shared, 0, 842, 595)->pixels
    !== MADSCORE\Render\PagePreview::render($withoutShared, 0, 842, 595)->pixels,
  'Shared marks appear on the editor sheet and PDF preview.');
$sharedAudio0 = (new MADSCORE\Play\ScoreSource($shared, 0))->read($frames);
$sharedAudio1 = (new MADSCORE\Play\ScoreSource($shared, 1))->read($frames);
$wholeSharedAudio = (new MADSCORE\Play\ScoreSource($shared))->read($frames);
$selectedSharedAudio = (new MADSCORE\Play\ScoreSource($shared, null, 1, 5))->read($frames);
$selectionOffset = (int)(0.12 * MADSCORE\Play\ScoreSource::SAMPLE_RATE);
$wholeOffset = (int)(0.25 * MADSCORE\Play\ScoreSource::SAMPLE_RATE) + $selectionOffset;
expectDynamic(substr($selectedSharedAudio, $selectionOffset * 4, 2400 * 4)
    === substr($wholeSharedAudio, $wholeOffset * 4, 2400 * 4),
  'Selected playback inherits earlier shared marks and a crescendo crossing its start.');
$sharedEarly0 = dynamicStrength($sharedAudio0, (int)(0.12 * MADSCORE\Play\ScoreSource::SAMPLE_RATE), 2400);
$sharedEarly1 = dynamicStrength($sharedAudio1, (int)(0.12 * MADSCORE\Play\ScoreSource::SAMPLE_RATE), 2400);
$sharedLate0 = dynamicStrength($sharedAudio0, (int)(1.12 * MADSCORE\Play\ScoreSource::SAMPLE_RATE), 2400);
expectDynamic(abs($sharedEarly0 / $sharedEarly1 - 0.5) < 0.03 && $sharedLate0 > $sharedEarly0 * 1.4,
  'A shared crescendo overrides different local marks while retaining melody volume balance.');
$priority = new MADSCORE\Score\Score();
$priority->setMelodyPattern(0, 'sine');
$priority->place(0, 0, 0, 4, 1, 2);
$localOverride = $priority->place(0, 0, 2, 4, 1, 2);
$priority->place(0, 0, 4, 4, 1, 2);
$priority->setScoreDynamicLevel(0, 'fff');
$priority->setDynamicLevel($localOverride, 'ppp');
$priority->setScoreDynamicLevel(4, 'fff');
$priorityAudio = (new MADSCORE\Play\ScoreSource($priority))->read($frames);
$firstStrength = dynamicStrength($priorityAudio, (int)(0.12 * MADSCORE\Play\ScoreSource::SAMPLE_RATE), 2400);
$middleStrength = dynamicStrength($priorityAudio, (int)(0.62 * MADSCORE\Play\ScoreSource::SAMPLE_RATE), 2400);
$lastStrength = dynamicStrength($priorityAudio, (int)(1.12 * MADSCORE\Play\ScoreSource::SAMPLE_RATE), 2400);
expectDynamic($firstStrength > $middleStrength * 3 && $lastStrength > $middleStrength * 3,
  'A later melody mark can override a shared level until the next shared mark.');
$shared->setScoreDynamicWedge(0, 4, '');
$shared->setScoreDynamicLevel(0, '');
expectDynamic(count($shared->scoreDynamics) === 1 && $shared->scoreDynamics[0]['time'] === 4,
  'Clearing shared marks preserves an independent later mark.');
$timed = new MADSCORE\Score\Score();
$timed->ensureBar(1);
$timed->setScoreDynamicLevel(8, 'mp');
expectDynamic(!$timed->removeEmptyLastBar(1), 'A shared mark keeps its otherwise empty bar.');
$timed->setBeats(0, 5);
expectDynamic($timed->scoreDynamics[0]['time'] === 10
  && MADSCORE\Score\Score::fromData($timed->data())->data() === $timed->data(),
  'Changing bar beats carries shared marks with the following bar.');

$screens = (new SPTK\XmlParser\XmlParser())->windows[0]['screens'];
$window = (new ReflectionClass(SPTK\Core\Window::class))->newInstanceWithoutConstructor();
(new ReflectionProperty(SPTK\Core\Window::class, 'screens'))->setValue($window, $screens);
$editor = $window->screen('editor');
foreach ([['ctrl+*', '*', SPTK\SDLWrapper\SDL::MOD_CTRL],
  ['ctrl+shift+*', '*', SPTK\SDLWrapper\SDL::MOD_CTRL | SPTK\SDLWrapper\SDL::MOD_SHIFT],
  ['ctrl+shift+8', '8', SPTK\SDLWrapper\SDL::MOD_CTRL | SPTK\SDLWrapper\SDL::MOD_SHIFT]] as [$chord, $key, $mod]) {
  $event = (object)['type' => SPTK\SDLWrapper\SDL::SDL_EVENT_KEY_DOWN,
    'key' => (object)['key' => ord($key), 'mod' => $mod]];
  expectDynamic((new SPTK\Events\EventDefinition('keyDown', $chord, ''))->matches($event),
    "The {$chord} shortcut accepts its keyboard symbol.");
}
$ctrlShiftEight = (object)['type' => SPTK\SDLWrapper\SDL::SDL_EVENT_KEY_DOWN,
  'key' => (object)['key' => ord('8'),
    'mod' => SPTK\SDLWrapper\SDL::MOD_CTRL | SPTK\SDLWrapper\SDL::MOD_SHIFT, 'repeat' => true]];
expectDynamic(MADSCORE\App\Controller::melodyWedgeShortcut(
  new SPTK\Events\EventContext('keyDown', $editor->widget('dynamic'), $ctrlShiftEight)),
  'Ctrl+Shift+8 reaches the melody wedge command when SDL reports the 8 key.');
$ctrlNumpadStar = (object)['type' => SPTK\SDLWrapper\SDL::SDL_EVENT_KEY_DOWN,
  'key' => (object)['key' => SPTK\SDLWrapper\SDL::KEY_KP_MULTIPLY,
    'mod' => SPTK\SDLWrapper\SDL::MOD_CTRL, 'repeat' => true]];
expectDynamic(MADSCORE\App\Controller::keypadMelodyWedgeShortcut(
  new SPTK\Events\EventContext('keyDown', $editor->widget('dynamic'), $ctrlNumpadStar)),
  'Ctrl+numpad star reaches the melody wedge command.');
expectDynamic($editor->widget('dynamic')->hotkey() === '*',
  'The Note panel exposes one volume direction shortcut.');
expectDynamic($editor->widget('dynamicLevel')->hotkey() === '/',
  'The Note panel has a level button with a slash hotkey.');
$screenInput = new SPTK\Events\ScreenInput([]);
$screenInput->register($editor->widget('dynamic'));
$starKey = (object)['type' => SPTK\SDLWrapper\SDL::SDL_EVENT_KEY_DOWN,
  'key' => (object)['key' => ord('*'), 'mod' => 0]];
expectDynamic(!$screenInput->dispatch('keyDown', null, $starKey),
  'A star keydown waits for text input, so it cannot cycle twice.');
$sharedInput = new SPTK\Events\ScreenInput([
  new SPTK\Events\EventDefinition('keyDown', 'ctrl+shift+*', 'consumeMelodyWedgeShortcut'),
]);
$sharedInput->register($editor->widget('dynamic'));
$ctrlStar = (object)['type' => SPTK\SDLWrapper\SDL::SDL_EVENT_KEY_DOWN,
  'key' => (object)['key' => ord('*'),
    'mod' => SPTK\SDLWrapper\SDL::MOD_CTRL | SPTK\SDLWrapper\SDL::MOD_SHIFT]];
$symbol = FFI::new('char[2]');
$symbol[0] = '*';
$textEvent = (object)['type' => SPTK\SDLWrapper\SDL::SDL_EVENT_TEXT_INPUT,
  'text' => (object)['text' => $symbol]];
expectDynamic($sharedInput->dispatch('keyDown', null, $ctrlStar)
  && $sharedInput->dispatch('textInput', null, $textEvent),
  'A melody wedge keydown consumes its following symbol instead of pressing the shared button.');
expectDynamic($GLOBALS['melodyWedgeShortcutCount'] === 1,
  'A matched melody wedge shortcut runs only once.');

$uiScore = new MADSCORE\Score\Score();
$uiNote = $uiScore->place(0, 0, 0, 4, 1, 2);
foreach (['window' => $window, 'score' => $uiScore, 'sheet' => new SPTK\Layout\LayoutLeaf('Canvas', '1*', '1*', new MADSCORE\App\SheetCanvas()),
  'bar' => 0, 'slot' => 0, 'octave' => 4, 'melody' => 0, 'pitchPosition' => 1,
  'cursorLength' => 2, 'displayedReadout' => ''] as $name => $value) {
  (new ReflectionProperty(MADSCORE\App\Controller::class, $name))->setValue(null, $value);
}
$uiScore->setDynamicLevel($uiNote, MADSCORE\Score\DynamicLevel::next(''));
expectDynamic($uiScore->notes[$uiNote]['dynamicLevel'] === 'ppp',
  'Cycling a note level stores a written mark.');

echo "Dynamic checks passed\n";
