<?php

define('APP_DIR', dirname(__DIR__));
define('APP_NAMESPACE', 'MADSCORE');
require APP_DIR . '/SPTK/App.php';

use MADSCORE\Score\MusicXmlWriter;
use MADSCORE\Score\Score;

/** Check that an independent notation program accepts an exported score. */
function interopCommand(string $command): void {
  $output = [];
  $status = 0;
  exec($command . ' 2>&1', $output, $status);
  if ($status !== 0) {
    throw new RuntimeException($command . " failed:\n" . implode("\n", $output));
  }
}

foreach (['musicxml2ly', 'lilypond'] as $program) {
  $found = [];
  $status = 0;
  exec('command -v ' . escapeshellarg($program), $found, $status);
  if ($status !== 0) {
    throw new RuntimeException('MusicXML interoperability test requires ' . $program . '.');
  }
}

$directory = sys_get_temp_dir() . '/madscore-musicxml-' . bin2hex(random_bytes(6));
if (!mkdir($directory)) {
  throw new RuntimeException('Could not create MusicXML test directory.');
}
try {
  $score = new Score(2);
  $score->title = 'MusicXML interoperability';
  $score->place(0, 0, 1, 3, 4, 3);
  $score->place(0, 0, 4, 3, 7, 6);
  $score->place(1, 0, 0, 2, 1, 2);
  $score->addChordTone(1, 0, 0, 2, 4, 2);
  $score->ensureBar(2);
  $score->setSectionMusic(1, ['scale' => 'Dorian', 'homeNote' => 'F#']);
  $score->bars[2]['tempo'] = '96';
  $xml = $directory . '/score.musicxml';
  $ly = $directory . '/score.ly';
  MusicXmlWriter::write($xml, $score);
  interopCommand('musicxml2ly -o ' . escapeshellarg($ly) . ' ' . escapeshellarg($xml));
  if (!is_file($ly) || filesize($ly) === 0) {
    throw new RuntimeException('LilyPond did not receive the MusicXML score.');
  }
  if (!str_contains(file_get_contents($ly), '\\clef "bass"')) {
    throw new RuntimeException('LilyPond did not read the deep melody in bass clef.');
  }
  interopCommand('lilypond -dno-print-pages -o ' . escapeshellarg($directory . '/render')
    . ' ' . escapeshellarg($ly));
} finally {
  foreach (glob($directory . '/*') ?: [] as $file) {
    unlink($file);
  }
  rmdir($directory);
}

echo "MusicXML interoperability checks passed\n";
