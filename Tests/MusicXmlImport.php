<?php

define('APP_DIR', dirname(__DIR__));
define('APP_NAMESPACE', 'MADSCORE');
require APP_DIR . '/SPTK/App.php';

use MADSCORE\Score\MusicXmlImporter;
use MADSCORE\Score\MusicXmlWriter;
use MADSCORE\Score\Score;

function expectImport(bool $condition, string $message): void {
  if (!$condition) {
    throw new RuntimeException($message);
  }
}

$path = sys_get_temp_dir() . '/madscore-import-' . bin2hex(random_bytes(6)) . '.musicxml';
$mxl = substr($path, 0, -9) . '.mxl';
try {
  $original = new Score(2);
  $original->title = 'A & B';
  $original->author = 'Test Composer';
  $original->place(0, 0, 1, 3, 4, 3);
  $original->place(0, 0, 4, 3, 7, 6);
  $original->place(1, 0, 0, 2, 1, 2);
  $original->addChordTone(1, 0, 0, 2, 4, 2);
  $original->ensureBar(2);
  $original->setSectionMusic(1, ['scale' => 'Dorian', 'homeNote' => 'F#']);
  $original->bars[2]['tempo'] = '96';
  MusicXmlWriter::write($path, $original);
  [$imported, $warnings] = MusicXmlImporter::read($path);
  expectImport($warnings === [] && $imported->title === $original->title
    && $imported->author === $original->author
    && count($imported->melodies) === 2 && count($imported->notes) === 4
    && $imported->sectionMusic(1) === ['scale' => 'Dorian', 'homeNote' => 'F#']
    && $imported->bars[2]['tempo'] === '96',
    'Exported melodies, metadata, keys, and tempo import without warnings.');
  foreach ($original->notes as $note) {
    expectImport(count(array_filter($imported->notes, static fn (array $item): bool =>
      array_intersect_key($item, array_flip(['melody', 'bar', 'slot', 'octave', 'pitch', 'alter', 'length']))
        === array_intersect_key($note, array_flip(['melody', 'bar', 'slot', 'octave', 'pitch', 'alter', 'length'])))) === 1,
      'An exported note returns with its pitch, onset, duration, and melody.');
  }

  $xml = '<?xml version="1.0"?><score-partwise version="4.0"><part-list>'
    . '<score-part id="P1"><part-name>Line</part-name></score-part></part-list>'
    . '<part id="P1"><measure number="1"><attributes><divisions>8</divisions>'
    . '<time><beats>4</beats><beat-type>4</beat-type></time></attributes>'
    . '<direction><direction-type><metronome><beat-unit>quarter</beat-unit>'
    . '<per-minute>120</per-minute></metronome></direction-type><sound tempo="120"/></direction>'
    . '<note><pitch><step>C</step><octave>4</octave></pitch><duration>2</duration><type>16th</type></note>'
    . '<note><rest/><duration>2</duration><type>16th</type></note>'
    . '<note><pitch><step>D</step><octave>4</octave></pitch><duration>4</duration><type>eighth</type></note>'
    . '<note><rest/><duration>24</duration><type>half</type></note></measure>'
    . '<measure number="2"><direction><direction-type><metronome><beat-unit>quarter</beat-unit>'
    . '<per-minute>90</per-minute></metronome></direction-type><sound tempo="90"/></direction>'
    . '<note><pitch><step>E</step><octave>4</octave></pitch><duration>8</duration><type>quarter</type></note>'
    . '<note><rest/><duration>24</duration><type>half</type></note></measure></part></score-partwise>';
  file_put_contents($path, $xml);
  [$scaled, $warnings] = MusicXmlImporter::read($path);
  expectImport($warnings === [] && array_column($scaled->bars, 'beats') === [8, 4]
    && array_column($scaled->bars, 'tempo') === ['240', '90']
    && array_map(static fn (array $note): array => [$note['bar'], $note['slot'], $note['length']], $scaled->notes)
      === [[0, 0, 1], [0, 2, 2], [1, 0, 2]],
    'A fast bar gets a finer grid and proportionate tempo without lengthening later bars.');

  $tied = '<?xml version="1.0"?><score-partwise version="4.0"><part-list>'
    . '<score-part id="P1"><part-name>Line</part-name></score-part></part-list>'
    . '<part id="P1"><measure number="1"><attributes><divisions>8</divisions>'
    . '<time><beats>4</beats><beat-type>4</beat-type></time></attributes>'
    . '<note><rest/><duration>30</duration></note>'
    . '<note><pitch><step>G</step><octave>3</octave></pitch><duration>2</duration><tie type="start"/></note>'
    . '</measure><measure number="2">'
    . '<note><pitch><step>G</step><octave>3</octave></pitch><duration>4</duration><tie type="stop"/></note>'
    . '<note><rest/><duration>28</duration></note></measure></part></score-partwise>';
  file_put_contents($path, $tied);
  [$joined] = MusicXmlImporter::read($path);
  expectImport(count($joined->notes) === 1 && $joined->notes[0]['bar'] === 0
    && $joined->notes[0]['slot'] === 15 && $joined->notes[0]['length'] === 2
    && array_column($joined->bars, 'beats') === [8, 4]
    && array_column($joined->bars, 'tempo') === ['240', '120'],
    'Ties join across bars even when their grids and adjusted tempos differ.');

  $voices = '<?xml version="1.0"?><score-partwise version="4.0"><part-list>'
    . '<score-part id="P1"><part-name>Two voices</part-name></score-part></part-list>'
    . '<part id="P1"><measure number="1"><attributes><divisions>2</divisions></attributes>'
    . '<note><pitch><step>C</step><octave>4</octave></pitch><duration>8</duration><voice>1</voice></note>'
    . '<backup><duration>8</duration></backup>'
    . '<note><pitch><step>G</step><octave>3</octave></pitch><duration>8</duration><voice>2</voice></note>'
    . '</measure></part></score-partwise>';
  file_put_contents($path, $voices);
  [$polyphonic] = MusicXmlImporter::read($path);
  expectImport(count($polyphonic->melodies) === 2 && count($polyphonic->notes) === 2
    && $polyphonic->notes[0]['melody'] !== $polyphonic->notes[1]['melody'],
    'Independent MusicXML voices become separate MadScore melodies.');

  $pickup = '<?xml version="1.0"?><score-partwise version="4.0"><part-list>'
    . '<score-part id="P1"><part-name>Pickup</part-name></score-part></part-list>'
    . '<part id="P1"><measure number="1" implicit="yes"><attributes><divisions>8</divisions>'
    . '<time><beats>4</beats><beat-type>4</beat-type></time></attributes>'
    . '<note><pitch><step>C</step><octave>4</octave></pitch><duration>1</duration></note></measure>'
    . '<measure number="2"><note><pitch><step>D</step><octave>4</octave></pitch>'
    . '<duration>8</duration></note><note><rest/><duration>24</duration></note>'
    . '</measure></part></score-partwise>';
  file_put_contents($path, $pickup);
  [$anacrusis] = MusicXmlImporter::read($path);
  expectImport(array_column($anacrusis->bars, 'beats') === [1, 4]
    && $anacrusis->notes[0]['length'] === 2
    && $anacrusis->notes[1]['bar'] === 1 && $anacrusis->notes[1]['slot'] === 0,
    'An implicit pickup keeps its actual duration before the first full bar.');

  $marked = str_replace('<duration>1</duration></note>', '<duration>1</duration>'
    . '<notations><articulations><staccato/></articulations></notations></note>', $pickup);
  file_put_contents($path, $marked);
  [, $markWarnings] = MusicXmlImporter::read($path);
  expectImport(in_array('note marks', $markWarnings, true),
    'Marks outside basic import are reported for confirmation.');

  $midbar = str_replace('<note><rest/><duration>2</duration><type>16th</type></note>',
    '<note><rest/><duration>2</duration><type>16th</type></note>'
      . '<direction><sound tempo="100"/></direction>', $xml);
  file_put_contents($path, $midbar);
  try {
    MusicXmlImporter::read($path);
    throw new RuntimeException('Mid-measure tempo change was accepted.');
  } catch (DomainException $error) {
    expectImport(str_contains($error->getMessage(), 'Mid-measure tempo'),
      'Tempo changes that cannot fit a MadScore bar are rejected.');
  }

  $zip = new ZipArchive();
  expectImport($zip->open($mxl, ZipArchive::CREATE) === true, 'Compressed test archive opens.');
  $zip->addFromString('META-INF/container.xml', '<?xml version="1.0"?><container>'
    . '<rootfiles><rootfile full-path="score.xml"/></rootfiles></container>');
  $zip->addFromString('score.xml', $voices);
  $zip->close();
  expectImport(count(MusicXmlImporter::read($mxl)[0]->melodies) === 2,
    'Compressed MusicXML reads its declared root file.');

  $dense = str_replace('<divisions>2</divisions>', '<divisions>32</divisions>', $voices);
  $dense = str_replace('<duration>8</duration>', '<duration>1</duration>', $dense);
  file_put_contents($path, $dense);
  try {
    MusicXmlImporter::read($path);
    throw new RuntimeException('Overdense MusicXML was accepted.');
  } catch (DomainException $error) {
    expectImport(str_contains($error->getMessage(), 'maximum 32'),
      'An impossible grid reports its limit instead of changing note timing.');
  }
} finally {
  foreach ([$path, $mxl] as $file) {
    if (is_file($file)) {
      unlink($file);
    }
  }
}

echo "MusicXML import checks passed\n";
