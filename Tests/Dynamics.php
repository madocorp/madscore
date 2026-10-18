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

$score = new MADSCORE\Score\Score();
$score->setMelodyPattern(0, 'sine');
$first = $score->place(0, 0, 0, 4, 1, 2);
$middle = $score->place(0, 0, 2, 4, 1, 2);
$last = $score->place(0, 0, 4, 4, 1, 2);
$following = $score->place(0, 0, 6, 4, 1, 2);
$score->setMelodyVolume(0, 70);
$score->setDynamicLevel($first, 'ppp');
$score->setDynamicLevel($last, 'fff');
expectDynamic(MADSCORE\Score\DynamicLevel::percent('ppp') === 15
  && MADSCORE\Score\DynamicLevel::percent('fff') === 100
  && MADSCORE\Score\DynamicLevel::percent('70') === 70,
  'Letters and exact percentages select predictable volume levels.');
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
expectDynamic($middleStrength > $closingStrength * 0.65 && $middleStrength < $closingStrength * 0.90,
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
$score->setDynamicLevel($first, '40');
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
expectDynamic($atLevel < $beforeLevel * 0.65 && abs($afterLevel / $atLevel - 1) < 0.08,
  'A standalone level changes playback on its note and holds on the next note.');
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

$screens = (new SPTK\XmlParser\XmlParser())->windows[0]['screens'];
$window = (new ReflectionClass(SPTK\Core\Window::class))->newInstanceWithoutConstructor();
(new ReflectionProperty(SPTK\Core\Window::class, 'screens'))->setValue($window, $screens);
$editor = $window->screen('editor');
expectDynamic($editor->widget('dynamic')->hotkey() === '*',
  'The Note panel exposes one volume direction shortcut.');
expectDynamic($editor->widget('dynamicLevel')->getValue() === '',
  'The Note panel has an editable level field.');
$screenInput = new SPTK\Events\ScreenInput([]);
$screenInput->register($editor->widget('dynamic'));
$starKey = (object)['type' => SPTK\SDLWrapper\SDL::SDL_EVENT_KEY_DOWN,
  'key' => (object)['key' => ord('*'), 'mod' => 0]];
expectDynamic(!$screenInput->dispatch('keyDown', null, $starKey),
  'A star keydown waits for text input, so it cannot cycle twice.');

$uiScore = new MADSCORE\Score\Score();
$uiNote = $uiScore->place(0, 0, 0, 4, 1, 2);
foreach (['window' => $window, 'score' => $uiScore, 'sheet' => new SPTK\Layout\LayoutLeaf('Canvas', '1*', '1*', new MADSCORE\App\SheetCanvas()),
  'bar' => 0, 'slot' => 0, 'octave' => 4, 'melody' => 0, 'pitchPosition' => 1,
  'cursorLength' => 2, 'displayedReadout' => ''] as $name => $value) {
  (new ReflectionProperty(MADSCORE\App\Controller::class, $name))->setValue(null, $value);
}
$levelField = $editor->widget('dynamicLevel');
$levelField->setValue('MF');
(new ReflectionMethod(MADSCORE\App\Controller::class, 'applyDynamicLevel'))->invoke(null);
expectDynamic($uiScore->notes[$uiNote]['dynamicLevel'] === 'mf' && $levelField->getValue() === 'mf',
  'The Note panel saves a letter level in normalized form.');
$levelField->setValue('42');
(new ReflectionMethod(MADSCORE\App\Controller::class, 'applyDynamicLevel'))->invoke(null);
expectDynamic($uiScore->notes[$uiNote]['dynamicLevel'] === '42',
  'The Note panel also saves an exact percentage.');

echo "Dynamic checks passed\n";
