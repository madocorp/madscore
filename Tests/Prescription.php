<?php

define('APP_DIR', dirname(__DIR__));
define('APP_NAMESPACE', 'MADSCORE');
require APP_DIR . '/SPTK/App.php';

function expectPrescription(bool $condition, string $message): void {
  if (!$condition) {
    throw new \RuntimeException($message);
  }
}

$score = new MADSCORE\Score();
$score->ensureBar(2);
$score->place(0, 0, 0, 4, 4, 2);
$score->place(0, 0, 2, 4, 6, 2);
$score->place(1, 0, 0, 2, 4, 2);
$score->place(0, 1, 0, 7, 4, 2);
$states = MADSCORE\Prescription::states($score);
expectPrescription($states[0]['primary'] === 4 && in_array(2, $states[0]['bands'], true), 'The whole-score dominant octave is the first continuous staff.');
expectPrescription($states[0]['change'] && $states[1]['change'] && !$states[2]['change'], 'A new octave triggers a prescription change before its bar, then remains stable.');
expectPrescription(in_array(7, $states[1]['bands'], true), 'The changed prescription includes the new octave.');
$positions = MADSCORE\Prescription::positions([0, 1, 2], [100, 100, 100], $states, 12);
expectPrescription($positions[0]['markerLeft'] === 0 && $positions[1]['markerLeft'] === 112 && $positions[2]['markerLeft'] === null, 'Marker gaps appear at the line start and the mid-line change.');
expectPrescription($score->data() === MADSCORE\Score::fromData($score->data())->data(), 'Version 5 scores round trip.');

$previewScore = new MADSCORE\Score();
$previewStates = MADSCORE\Prescription::states($previewScore, 7, 9, 8);
expectPrescription(in_array(8, $previewStates[0]['bands'], true) && in_array(8, $previewStates[1]['bands'], true), 'An unplaced cursor spanning bars keeps its octave staff visible throughout its width.');

$separated = new MADSCORE\Score();
foreach ([0, 1, 7, 8] as $melody => $band) {
  $separated->place($melody, 0, 0, $band, 4, 2);
}
$state = MADSCORE\Prescription::states($separated)[0];
expectPrescription($state['bands'] === [8, 7, 1, 0], 'Separated hand ranges keep their absolute octave identity.');
$rows = MADSCORE\Prescription::rows($state, 480, 24);
expectPrescription($rows[1]['top'] - ($rows[7]['top'] + $rows[7]['height']) > 0, 'Separated ranges have a small vertical group gap.');
try {
  $separated->place(4, 0, 0, 4, 4, 2);
  throw new \RuntimeException('A fifth octave band was accepted in one bar.');
} catch (\DomainException $error) {
  expectPrescription(count($separated->notes) === 4, 'Rejecting a fifth band leaves the notes unchanged.');
}

$spanning = new MADSCORE\Score();
$spanning->place(0, 0, 7, 4, 4, 2);
foreach ([0, 1, 2] as $melody => $band) {
  $spanning->place($melody + 1, 1, 0, $band, 4, 2);
}
expectPrescription(in_array(4, MADSCORE\Prescription::soundingBands($spanning, 1), true), 'A continuing note occupies its octave band in the next bar.');
$crossing = new MADSCORE\Score();
$crossing->ensureBar(1);
$crossing->place(0, 0, 7, 4, 4, 2);
expectPrescription(MADSCORE\Prescription::bandContinues($crossing, MADSCORE\Prescription::states($crossing), 0, 4), 'A note spanning the boundary keeps its staff open.');
try {
  $spanning->place(4, 1, 0, 7, 4, 2);
  throw new \RuntimeException('A fifth band was accepted beside a continuing note.');
} catch (\DomainException $error) {
  expectPrescription(count($spanning->notes) === 4, 'A continuing note counts toward the four-band limit.');
}

$ending = new MADSCORE\Score();
$ending->ensureBar(1);
$ending->place(0, 0, 0, 4, 4, 2);
$ending->place(0, 1, 0, 4, 4, 2);
$ending->place(1, 0, 0, 6, 4, 2);
$endingStates = MADSCORE\Prescription::states($ending);
expectPrescription(MADSCORE\Prescription::bandContinues($ending, $endingStates, 0, 4), 'The primary staff continues through an empty octave in the next bar.');
expectPrescription(!MADSCORE\Prescription::bandContinues($ending, $endingStates, 0, 6), 'A secondary staff closes when its octave disappears in the next bar.');
expectPrescription(MADSCORE\Prescription::bandContinues($ending, $endingStates, 0, 6, ['start' => 8, 'end' => 9, 'octave' => 6]), 'A cursor preview in the next bar keeps its octave staff open.');

$legacy = (new MADSCORE\Score())->data();
$legacy['version'] = 1;
$legacy['tempo'] = '120';
$legacy['notes'] = [
  ['melody' => 0, 'bar' => 0, 'slot' => 0, 'octave' => 0, 'pitch' => 'C', 'alter' => 0, 'length' => 2],
  ['melody' => 0, 'bar' => 0, 'slot' => 2, 'octave' => 3, 'pitch' => 'A', 'alter' => -1, 'length' => 2],
];
$migrated = MADSCORE\Score::fromData($legacy);
expectPrescription($migrated->data()['version'] === 6 && $migrated->notes[0]['octave'] === 4, 'Version 1 octave zero becomes absolute A440 band four.');
expectPrescription($migrated->notes[1]['octave'] === 0 && $migrated->notes[1]['pitch'] === 'G' && $migrated->notes[1]['alter'] === 1, 'A-flat below the old lowest A becomes G-sharp in band zero.');
expectPrescription($migrated->bars[0]['tempo'] === '120', 'Version 1 tempo of 120 BPM is preserved.');

$oldScore = (new MADSCORE\Score())->data();
$oldScore['version'] = 2;
$oldScore['tempo'] = 'Allegro';
$oldScore['bars'] = [['beats' => 3], ['beats' => 5]];
$upgraded = MADSCORE\Score::fromData($oldScore);
expectPrescription($upgraded->bars[0]['tempo'] === '120' && $upgraded->bars[1]['tempo'] === '120', 'An older nonnumeric tempo receives the default in every bar.');
$upgraded->setTempo(1, '90');
expectPrescription($upgraded->bars[0]['tempo'] === '120' && $upgraded->bars[1]['tempo'] === '90', 'Editing tempo changes only the selected bar.');
$upgraded->ensureBar(2);
expectPrescription($upgraded->bars[2]['tempo'] === '90' && $upgraded->bars[2]['beats'] === 5, 'A new bar inherits tempo and notes per bar from its predecessor.');
expectPrescription(MADSCORE\Score::fromData($upgraded->data())->bars === $upgraded->bars, 'Per-bar tempo and notes per bar survive a save and load.');
try {
  $upgraded->setTempo(1, '');
  throw new \RuntimeException('An empty tempo was accepted.');
} catch (\DomainException $error) {
  expectPrescription($upgraded->bars[1]['tempo'] === '90', 'An empty tempo leaves the previous value unchanged.');
}

$pdf = tempnam(sys_get_temp_dir(), 'madscore-prescription-');
MADSCORE\PdfWriter::write($pdf, $score);
expectPrescription(str_starts_with(file_get_contents($pdf), '%PDF-') && filesize($pdf) > 1000, 'A score with a mid-line prescription change exports to PDF.');
unlink($pdf);

echo "Prescription checks passed\n";
