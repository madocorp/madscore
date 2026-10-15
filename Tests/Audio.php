<?php

define('APP_DIR', dirname(__DIR__));
define('APP_NAMESPACE', 'MADSCORE');
require APP_DIR . '/SPTK/App.php';

/** Fail one score-audio expectation with its reason. */
function expectScoreAudio(bool $condition, string $message): void {
  if (!$condition) {
    throw new RuntimeException($message);
  }
}

/** Read all PCM from a source without opening an audio device. */
function readScoreAudio(MADSCORE\ScoreSource $source): string {
  $pcm = '';
  while (($chunk = $source->read(4096)) !== '') {
    $pcm .= $chunk;
  }
  return $pcm;
}

/** Measure the strength of one frequency in a PCM sample window. */
function sineAmplitude(array $samples, float $frequency): float {
  $sine = 0.0;
  $cosine = 0.0;
  $index = 0;
  foreach ($samples as $sample) {
    $phase = 2 * M_PI * $frequency * $index / MADSCORE\ScoreSource::SAMPLE_RATE;
    $sine += $sample * sin($phase);
    $cosine += $sample * cos($phase);
    $index++;
  }
  return 2 * hypot($sine, $cosine) / $index;
}

$score = new MADSCORE\Score();
$score->place(0, 0, 0, 4, 1, 2);
$score->ensureBar(1);
$score->setTempo(1, '60');
$score->place(0, 1, 0, 4, 1, 2);
$source = new MADSCORE\ScoreSource($score);
$pcm = readScoreAudio($source);
expectScoreAudio(strlen($pcm) === 6 * MADSCORE\ScoreSource::SAMPLE_RATE * 4, 'Bar tempo changes set the total sample count.');
expectScoreAudio($source->read(1) === '', 'A finished score returns no further frames.');
$source->rewind();
expectScoreAudio($source->read(100) === substr($pcm, 0, 400), 'Rewind reproduces the same samples.');
$samples = unpack('g*', substr($pcm, 1000 * 4, 4800 * 4));
$fundamental = sineAmplitude($samples, 440.0);
expectScoreAudio($fundamental > 0.1 && sineAmplitude($samples, 430.0) < $fundamental * 0.02, 'A4 keeps its 440 Hz fundamental.');
expectScoreAudio(abs(sineAmplitude($samples, 880.0) / $fundamental - 0.55) < 0.02, 'The second harmonic is present at its intended strength.');
expectScoreAudio(abs(sineAmplitude($samples, 1320.0) / $fundamental - 0.30) < 0.02, 'The third harmonic is present at its intended strength.');
$low = new MADSCORE\Score();
$low->place(0, 0, 0, 2, 1, 2);
$lowPcm = readScoreAudio(new MADSCORE\ScoreSource($low));
$lowSamples = unpack('g*', substr($lowPcm, 1000 * 4, 4800 * 4));
$lowFundamental = sineAmplitude($lowSamples, 110.0);
expectScoreAudio(sineAmplitude($lowSamples, 220.0) > $lowFundamental * 0.5
  && sineAmplitude($lowSamples, 330.0) > $lowFundamental * 0.25,
  'Low notes retain audible upper harmonics.');
$patterns = new MADSCORE\Score();
$patterns->place(0, 0, 0, 4, 1, 2);
$patternWindow = static function(MADSCORE\Score $score): array {
  $pcm = readScoreAudio(new MADSCORE\ScoreSource($score));
  return unpack('g*', substr($pcm, 1000 * 4, 4800 * 4));
};
$patterns->setMelodyPattern(0, 'sine');
$sine = $patternWindow($patterns);
expectScoreAudio(sineAmplitude($sine, 880) < sineAmplitude($sine, 440) * 0.02, 'Sine has no second harmonic.');
$patterns->setMelodyPattern(0, 'triangle');
$triangle = $patternWindow($patterns);
expectScoreAudio(sineAmplitude($triangle, 880) < sineAmplitude($triangle, 440) * 0.02
  && abs(sineAmplitude($triangle, 1320) / sineAmplitude($triangle, 440) - 1 / 9) < 0.02,
  'Triangle has weak odd harmonics.');
$patterns->setMelodyPattern(0, 'square');
$square = $patternWindow($patterns);
expectScoreAudio(sineAmplitude($square, 880) < sineAmplitude($square, 440) * 0.02
  && abs(sineAmplitude($square, 1320) / sineAmplitude($square, 440) - 1 / 3) < 0.02,
  'Square has stronger odd harmonics.');
$patterns->setMelodyPattern(0, 'sawtooth');
$sawtooth = $patternWindow($patterns);
expectScoreAudio(abs(sineAmplitude($sawtooth, 880) / sineAmplitude($sawtooth, 440) - 0.5) < 0.02,
  'Sawtooth includes the second harmonic.');
$patterns->setMelodyVolume(0, 50);
$halfVolume = $patternWindow($patterns);
expectScoreAudio(abs(sineAmplitude($halfVolume, 440) / sineAmplitude($sawtooth, 440) - 0.5) < 0.02,
  'Melody volume scales its PCM.');
$patterns->setMelodyVolume(0, 0);
expectScoreAudio(trim(readScoreAudio(new MADSCORE\ScoreSource($patterns)), "\0") === '', 'Zero volume mutes the melody.');
$patterns->place(1, 0, 0, 4, 1, 2);
$patterns->setMelodyPattern(1, 'square');
$activeMelody = readScoreAudio(new MADSCORE\ScoreSource($patterns, 1));
expectScoreAudio(trim($activeMelody, "\0") !== '', 'A muted melody does not silence another melody.');
expectScoreAudio(readScoreAudio(new MADSCORE\ScoreSource($patterns)) === $activeMelody,
  'Score playback keeps the other melody sound when one is muted.');
expectScoreAudio(MADSCORE\Score::fromData($patterns->data())->data() === $patterns->data(), 'Pattern and volume survive a score round trip.');
$invalid = $patterns->data();
$invalid['melodies'][1]['volume'] = 101;
$rejected = false;
try {
  MADSCORE\Score::fromData($invalid);
} catch (DomainException $error) {
  $rejected = true;
}
expectScoreAudio($rejected, 'Invalid melody volume is rejected.');
$old = $patterns->data();
$old['version'] = 4;
unset($old['melodies']);
expectScoreAudio(MADSCORE\Score::fromData($old)->melodies[0] === ['pattern' => 'organ', 'volume' => 100, 'color' => '#ff9000'],
  'The current saved session gains default melody settings.');
$versionFive = $patterns->data();
$versionFive['version'] = 5;
$versionFive['melodies'] = array_map(static fn (array $settings): array => ['pattern' => $settings['pattern'], 'volume' => $settings['volume']], array_slice($versionFive['melodies'], 0, 6));
$restoredFive = MADSCORE\Score::fromData($versionFive);
expectScoreAudio(count($restoredFive->melodies) === MADSCORE\Score::MELODY_COUNT
  && $restoredFive->melodies[1]['pattern'] === 'square' && $restoredFive->melodies[1]['color'] === '#0050ff',
  'Version five scores retain six voices and gain new melody slots and colors.');
$drums = new MADSCORE\Score();
$drums->setMelodyPattern(9, 'kick');
$drums->place(9, 0, 0, 0, 1, 1);
$kick = readScoreAudio(new MADSCORE\ScoreSource($drums, 9));
$kickPeak = max(array_map('abs', unpack('g*', substr($kick, 0, 15000 * 4))));
expectScoreAudio($kickPeak > 0.6 && $kickPeak < 0.95, 'Kick plays loudly without clipping when heard alone.');
$repeatedKicks = new MADSCORE\Score();
$repeatedKicks->setMelodyPattern(0, 'kick');
$repeatedKicks->place(0, 0, 0, 4, 1, 1);
$repeatedKicks->place(0, 0, 2, 4, 1, 1);
$repeatedPcm = readScoreAudio(new MADSCORE\ScoreSource($repeatedKicks));
$hitBytes = (int)(MADSCORE\Drum::duration('kick') * MADSCORE\ScoreSource::SAMPLE_RATE) * 4;
expectScoreAudio(substr($repeatedPcm, 0, $hitBytes) === substr($repeatedPcm, MADSCORE\ScoreSource::SAMPLE_RATE * 2, $hitBytes),
  'The opening kick has the same attack as later kicks.');
$drums->place(9, 0, 0, 8, 12, 1);
expectScoreAudio($kick === readScoreAudio(new MADSCORE\ScoreSource($drums, 9)), 'Drum pitch and octave do not affect playback.');
$drums->resizeAndShift(0, 4);
expectScoreAudio($kick === readScoreAudio(new MADSCORE\ScoreSource($drums, 9)), 'Written drum length does not affect its sound.');
$drums->setMelodyPattern(9, 'snare');
$snare = readScoreAudio(new MADSCORE\ScoreSource($drums, 9));
$drums->setMelodyPattern(9, 'clap');
$clap = readScoreAudio(new MADSCORE\ScoreSource($drums, 9));
$drums->setMelodyPattern(9, 'hat');
$hat = readScoreAudio(new MADSCORE\ScoreSource($drums, 9));
$drums->setMelodyPattern(9, 'open_hat');
$openHat = readScoreAudio(new MADSCORE\ScoreSource($drums, 9));
expectScoreAudio(count(array_unique([$kick, $snare, $clap, $hat, $openHat])) === 5 && trim($openHat, "\0") !== '',
  'Each rhythm sound produces a distinct hit.');
expectScoreAudio(MADSCORE\Drum::duration('open_hat') > MADSCORE\Drum::duration('hat')
  && MADSCORE\Drum::sample('hat', 12000, 48000) === 0.0
  && abs(MADSCORE\Drum::sample('open_hat', 12000, 48000)) > 0.001,
  'Open hat continues hissing after the closed hat stops.');
$endingHit = new MADSCORE\Score();
$endingHit->setTempo(0, '600');
$endingHit->setMelodyPattern(0, 'kick');
$endingHit->place(0, 0, 7, 4, 1, 1);
expectScoreAudio(strlen(readScoreAudio(new MADSCORE\ScoreSource($endingHit))) > 0.4 * MADSCORE\ScoreSource::SAMPLE_RATE * 4,
  'Whole-score playback lets a final drum hit finish after a fast bar.');
$drums->setMelodyColor(9, '#abcdef');
expectScoreAudio(MADSCORE\Score::fromData($drums->data())->melodies[9]['color'] === '#abcdef', 'Extra melody settings survive saving.');
$invalidColor = $drums->data();
$invalidColor['melodies'][9]['color'] = 'blue';
try {
  MADSCORE\Score::fromData($invalidColor);
  throw new RuntimeException('An invalid melody color was accepted.');
} catch (DomainException $error) {
  expectScoreAudio($drums->melodies[9]['color'] === '#abcdef', 'Invalid color data leaves the score unchanged.');
}
$high = new MADSCORE\Score();
$high->place(0, 0, 0, 8, 12, 2);
$highPcm = readScoreAudio(new MADSCORE\ScoreSource($high));
$highSamples = unpack('g*', substr($highPcm, 1000 * 4, 4800 * 4));
$highFrequency = 440.0 * 2 ** (59 / 12);
$highFundamental = sineAmplitude($highSamples, $highFrequency);
expectScoreAudio(sineAmplitude($highSamples, 48000 - 2 * $highFrequency) < $highFundamental * 0.05
  && sineAmplitude($highSamples, 48000 - 3 * $highFrequency) < $highFundamental * 0.05,
  'High notes skip harmonics above the Nyquist limit.');
$chord = clone $score;
$chord->place(1, 0, 0, 4, 1, 2);
$chordPcm = readScoreAudio(new MADSCORE\ScoreSource($chord));
$singleSample = unpack('g', substr($pcm, 1000 * 4, 4))[1];
$chordSample = unpack('g', substr($chordPcm, 1000 * 4, 4))[1];
expectScoreAudio(abs($chordSample) > abs($singleSample) * 1.3, 'Two melodies sound together.');
$selection = new MADSCORE\ScoreSource($score, 0, 1, 3);
expectScoreAudio(strlen(readScoreAudio($selection)) === (int)(MADSCORE\ScoreSource::SAMPLE_RATE / 2) * 4, 'A two-slot selection lasts half a second at 120 BPM.');
$other = new MADSCORE\ScoreSource($score, 1);
expectScoreAudio(trim(readScoreAudio($other), "\0") === '', 'An empty melody produces silence.');
$sustain = new MADSCORE\Score();
$sustain->place(0, 0, 0, 4, 4, 12);
$sustain->setTempo(1, '60');
$clip = new MADSCORE\ScoreSource($sustain, null, 7, 9);
expectScoreAudio(strlen(readScoreAudio($clip)) === (int)round((0.25 + 0.5) * MADSCORE\ScoreSource::SAMPLE_RATE) * 4, 'Selection across a tempo change uses both bar tempi.');
echo "Score audio checks passed\n";
