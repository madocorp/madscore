<?php

define('APP_DIR', dirname(__DIR__));
define('APP_NAMESPACE', 'MADSCORE');
require APP_DIR . '/SPTK/App.php';

function expectTopPitch(bool $condition, string $message): void {
  if (!$condition) {
    throw new \RuntimeException($message);
  }
}

expectTopPitch(MADSCORE\Score\Pitch::step(8, 11, 1) === [8, 12], 'The preview reaches G-sharp above the highest G.');
expectTopPitch(MADSCORE\Score\Pitch::step(8, 12, -1) === [8, 11], 'The preview steps back down to G.');
expectTopPitch(MADSCORE\Score\Pitch::step(4, 11, 1) === [4, 12] && MADSCORE\Score\Pitch::step(4, 12, 1) === [5, 1], 'G-sharp ends an octave before A in the next band.');
try {
  MADSCORE\Score\Pitch::step(8, 12, 1);
  throw new \RuntimeException('The preview moved above the supported range.');
} catch (\DomainException $error) {
}

$score = new MADSCORE\Score\Score(12);
$index = $score->place(0, 0, 0, 8, 11, 2);
$score->stepPitch($index, 1);
expectTopPitch($score->notes[$index]['pitch'] === 'G' && $score->notes[$index]['alter'] === 1, 'A placed top G steps to G-sharp.');
expectTopPitch($score->data() === MADSCORE\Score\Score::fromData($score->data())->data(), 'The top G-sharp survives a save and load round trip.');
$score->stepPitch($index, -1);
expectTopPitch($score->notes[$index]['pitch'] === 'G' && $score->notes[$index]['alter'] === 0, 'The note steps back down to G.');
$score->setPitchPosition($index, 4, 12);
expectTopPitch($score->notes[$index]['octave'] === 4 && $score->notes[$index]['alter'] === 1, 'G-sharp remains available in the A440 band.');

echo "Top pitch checks passed\n";
