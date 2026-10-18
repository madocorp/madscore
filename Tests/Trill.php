<?php

define('APP_DIR', dirname(__DIR__));
define('APP_NAMESPACE', 'MADSCORE');
require APP_DIR . '/SPTK/App.php';

function expectTrill(bool $condition, string $message): void {
  if (!$condition) {
    throw new RuntimeException($message);
  }
}

/** Measure the strongest of the written or alternating pitch in one pulse. */
function trillPitch(MADSCORE\Score\Score $score, float $time, float $base, float $neighbor): float {
  $source = new MADSCORE\Play\ScoreSource($score);
  $source->read((int)round($time * MADSCORE\Play\ScoreSource::SAMPLE_RATE));
  $samples = unpack('g*', $source->read(2400));
  $strength = static function(float $frequency) use ($samples): float {
    $real = 0.0;
    $imaginary = 0.0;
    foreach ($samples as $index => $sample) {
      $angle = 2 * M_PI * $frequency * ($index - 1) / MADSCORE\Play\ScoreSource::SAMPLE_RATE;
      $real += $sample * cos($angle);
      $imaginary += $sample * sin($angle);
    }
    return hypot($real, $imaginary);
  };
  return $strength($neighbor) / max(0.00001, $strength($base));
}

$score = new MADSCORE\Score\Score();
$note = $score->place(0, 0, 0, 4, 1, 2);
$score->setMelodyPattern(0, 'sine');
foreach ([1, 2, -1, -2] as $interval) {
  $score->setTrill($note, $interval);
  $neighbor = 440 * 2 ** ($interval / 12);
  expectTrill(trillPitch($score, 0.035, 440, $neighbor) < 0.5, 'A trill starts on the written pitch.');
  expectTrill(trillPitch($score, 0.160, 440, $neighbor) > 2, 'The second pulse plays the signed neighboring pitch.');
  expectTrill(trillPitch($score, 0.285, 440, $neighbor) < 0.5, 'The third pulse returns to the written pitch.');
  expectTrill(MADSCORE\Score\Score::fromData($score->data())->notes[$note]['trill'] === $interval,
    'Every trill interval survives a score round trip.');
}

$across = new MADSCORE\Score\Score();
$crossing = $across->place(0, 0, 7, 4, 1, 2);
$across->setMelodyPattern(0, 'sine');
$across->setTempo(1, '60');
$across->setTrill($crossing, 2);
expectTrill(trillPitch($across, 2.035, 440, 440 * 2 ** (2 / 12)) < 0.5
  && trillPitch($across, 2.285, 440, 440 * 2 ** (2 / 12)) > 2,
  'A trill changes pulses on the beat grid across a tempo change.');

$score->setTrill($note, 0);
$plain = MADSCORE\Render\BarPainter::render($score, 0, 4, 120, 96)->pixels;
$marks = [];
foreach ([1, 2, -1, -2] as $interval) {
  $score->setTrill($note, $interval);
  $marks[] = MADSCORE\Render\BarPainter::render($score, 0, 4, 120, 96)->pixels;
}
expectTrill(count(array_unique($marks)) === 4 && !in_array($plain, $marks, true),
  'The four trill marks are distinct from each other and from an unmarked note.');

$saved = $score->data();
unset($saved['notes'][0]['trill']);
expectTrill(MADSCORE\Score\Score::fromData($saved)->notes[0]['trill'] === 0, 'Old scores load without a trill.');
$saved['notes'][0]['trill'] = 3;
try {
  MADSCORE\Score\Score::fromData($saved);
  throw new RuntimeException('An unsupported trill interval was accepted.');
} catch (DomainException) {
  // The score rejected the unsupported interval.
}

$low = new MADSCORE\Score\Score();
$edge = $low->place(0, 0, 0, 0, 1, 2);
try {
  $low->setTrill($edge, -1);
  throw new RuntimeException('A trill below the score range was accepted.');
} catch (DomainException) {
  // The lower edge is guarded.
}
$low->setTrill($edge, 1);
$low->setStaccato($edge, true);
expectTrill($low->notes[$edge]['trill'] === 0, 'Staccato clears a mark occupying the same note head.');

echo "Trill checks passed.\n";
