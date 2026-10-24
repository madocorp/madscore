<?php

define('APP_DIR', dirname(__DIR__));
define('APP_NAMESPACE', 'MADSCORE');
require APP_DIR . '/SPTK/App.php';

function expectPrescription(bool $condition, string $message): void {
  if (!$condition) {
    throw new \RuntimeException($message);
  }
}

$score = new MADSCORE\Score\Score(12);
$score->ensureBar(2);
$score->place(0, 0, 0, 4, 4, 2);
$score->place(0, 0, 2, 4, 6, 2);
$score->place(1, 0, 0, 2, 4, 2);
$score->place(0, 1, 0, 7, 4, 2);
$states = MADSCORE\Render\Prescription::states($score);
expectPrescription($states[0]['primary'] === 4 && in_array(2, $states[0]['bands'], true), 'The whole-score dominant octave is the first continuous staff.');
expectPrescription($states[0]['change'] && $states[1]['change'] && !$states[2]['change'], 'A new octave triggers a prescription change before its bar, then remains stable.');
expectPrescription(in_array(7, $states[1]['bands'], true), 'The changed prescription includes the new octave.');
$positions = MADSCORE\Render\Prescription::positions([0, 1, 2], [100, 100, 100], $states, 12);
expectPrescription($positions[0]['markerLeft'] === 0 && $positions[1]['markerLeft'] === 112 && $positions[2]['markerLeft'] === null, 'Marker gaps appear at the line start and the mid-line change.');
$markerSize = static fn (float $row): float => MADSCORE\Render\Prescription::markerSize($row, true);
expectPrescription(abs($markerSize(220) - 220 / 12) < 0.001
  && abs($markerSize(110) - 110 / 6) < 0.001
  && abs($markerSize(330) - 330 / 12) < 0.001
  && $markerSize(70) <= 70 / 6,
  'Printed octave symbols grow from half a note head to at most one note head.');
expectPrescription($score->data() === MADSCORE\Score\Score::fromData($score->data())->data(), 'Current scores round trip.');

$previewScore = new MADSCORE\Score\Score(12);
$previewStates = MADSCORE\Render\Prescription::states($previewScore, 7, 9, 8);
expectPrescription(in_array(8, $previewStates[0]['bands'], true) && in_array(8, $previewStates[1]['bands'], true), 'An unplaced cursor spanning bars keeps its octave staff visible throughout its width.');

$separated = new MADSCORE\Score\Score(12);
foreach ([0, 1, 7, 8] as $melody => $band) {
  $separated->place($melody, 0, 0, $band, 4, 2);
}
$state = MADSCORE\Render\Prescription::states($separated)[0];
expectPrescription($state['bands'] === [8, 7, 1, 0], 'Separated hand ranges keep their absolute octave identity.');
$rows = MADSCORE\Render\Prescription::rows($state, 480, 24);
expectPrescription($rows[1]['top'] - ($rows[7]['top'] + $rows[7]['height']) > 0, 'Separated ranges have a small vertical group gap.');
$separated->place(4, 0, 0, 4, 4, 2);
expectPrescription(count($separated->notes) === 5 && count(MADSCORE\Render\Prescription::states($separated)[0]['bands']) === 5, 'Five sounding bands remain available to the sheet.');

$spanning = new MADSCORE\Score\Score(12);
$spanning->place(0, 0, 7, 4, 4, 2);
foreach ([0, 1, 2] as $melody => $band) {
  $spanning->place($melody + 1, 1, 0, $band, 4, 2);
}
expectPrescription(in_array(4, MADSCORE\Render\Prescription::soundingBands($spanning, 1), true), 'A continuing note occupies its octave band in the next bar.');
$crossing = new MADSCORE\Score\Score(12);
$crossing->ensureBar(1);
$crossing->place(0, 0, 7, 4, 4, 2);
expectPrescription(MADSCORE\Render\Prescription::bandContinues($crossing, MADSCORE\Render\Prescription::states($crossing), 0, 4), 'A note spanning the boundary keeps its staff open.');
$spanning->place(4, 1, 0, 7, 4, 2);
expectPrescription(count($spanning->notes) === 5 && count(MADSCORE\Render\Prescription::states($spanning)[1]['bands']) === 5, 'A continuing note is included in an expanded sheet measure.');

$ending = new MADSCORE\Score\Score(12);
$ending->ensureBar(1);
$ending->place(0, 0, 0, 4, 4, 2);
$ending->place(0, 1, 0, 4, 4, 2);
$ending->place(1, 0, 0, 6, 4, 2);
$endingStates = MADSCORE\Render\Prescription::states($ending);
expectPrescription(MADSCORE\Render\Prescription::bandContinues($ending, $endingStates, 0, 4), 'The primary staff continues through an empty octave in the next bar.');
expectPrescription(!MADSCORE\Render\Prescription::bandContinues($ending, $endingStates, 0, 6), 'A secondary staff closes when its octave disappears in the next bar.');
expectPrescription(MADSCORE\Render\Prescription::bandContinues($ending, $endingStates, 0, 6, ['start' => 8, 'end' => 9, 'octave' => 6]), 'A cursor preview in the next bar keeps its octave staff open.');

$upgraded = new MADSCORE\Score\Score(12);
$upgraded->ensureBar(1);
$upgraded->setBeats(0, 3);
$upgraded->setBeats(1, 5);
$upgraded->setTempo(1, '90');
expectPrescription($upgraded->bars[0]['tempo'] === '120' && $upgraded->bars[1]['tempo'] === '90', 'Editing tempo leaves earlier bars unchanged.');
$upgraded->ensureBar(2);
expectPrescription($upgraded->bars[2]['tempo'] === '90' && $upgraded->bars[2]['beats'] === 5, 'A new bar inherits tempo and notes per bar from its predecessor.');
expectPrescription(MADSCORE\Score\Score::fromData($upgraded->data())->bars === $upgraded->bars, 'Per-bar tempo and notes per bar survive a save and load.');
try {
  $upgraded->setTempo(1, '');
  throw new \RuntimeException('An empty tempo was accepted.');
} catch (\DomainException $error) {
  expectPrescription($upgraded->bars[1]['tempo'] === '90', 'An empty tempo leaves the previous value unchanged.');
}

$tempoRun = new MADSCORE\Score\Score();
$tempoRun->ensureBar(5);
$tempoRun->setTempo(0, '100');
$tempoRun->setTempo(3, '110');
$tempoRun->setTempo(4, '100');
$tempoRun->setTempo(2, '100.0');
$tempoRun->setTempo(1, '120');
expectPrescription(array_column($tempoRun->bars, 'tempo') === ['100', '120', '120', '110', '100', '100'],
  'A tempo edit updates only the following run with the old tempo, stopping at the first different bar.');
$tempoRun->setTempo(4, '100.0');
expectPrescription($tempoRun->bars[5]['tempo'] === '100', 'Changing only the spelling of a tempo does not propagate.');

$pdf = tempnam(sys_get_temp_dir(), 'madscore-prescription-');
MADSCORE\Render\PdfWriter::write($pdf, $score);
expectPrescription(str_starts_with(file_get_contents($pdf), '%PDF-') && filesize($pdf) > 1000, 'A score with a mid-line prescription change exports to PDF.');
unlink($pdf);

echo "Prescription checks passed\n";
