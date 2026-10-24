<?php

define('APP_DIR', dirname(__DIR__));
define('APP_NAMESPACE', 'MADSCORE');
require APP_DIR . '/SPTK/App.php';

function expectMidi(bool $condition, string $message): void {
  if (!$condition) {
    throw new RuntimeException($message);
  }
}

/** Decode enough of SMF to check absolute ticks and event bytes. */
function midiEvents(string $track): array {
  $events = [];
  $offset = 0;
  $tick = 0;
  while ($offset < strlen($track)) {
    $delta = 0;
    do {
      $byte = ord($track[$offset++]);
      $delta = ($delta << 7) | ($byte & 0x7f);
    } while ($byte & 0x80);
    $tick += $delta;
    $status = ord($track[$offset++]);
    if ($status === 0xff) {
      $kind = ord($track[$offset++]);
      $length = ord($track[$offset++]);
      $data = substr($track, $offset, $length);
      $offset += $length;
      $events[] = [$tick, $status, $kind, $data];
      continue;
    }
    $length = ($status & 0xf0) === 0xc0 ? 1 : 2;
    $data = substr($track, $offset, $length);
    $offset += $length;
    $events[] = [$tick, $status, null, $data];
  }
  return $events;
}

function midiMelodyEvents(MADSCORE\Score\Score $score): array {
  $midi = MADSCORE\Play\MidiWriter::encode($score);
  $offset = 14;
  $conductorLength = unpack('N', substr($midi, $offset + 4, 4))[1];
  $offset += 8 + $conductorLength;
  $melodyLength = unpack('N', substr($midi, $offset + 4, 4))[1];
  return midiEvents(substr($midi, $offset + 8, $melodyLength));
}

function midiNoteExpressions(MADSCORE\Score\Score $score): array {
  $result = [];
  $expression = 127;
  foreach (midiMelodyEvents($score) as $event) {
    if (($event[1] & 0xf0) === 0xb0 && ord($event[3][0]) === 11) {
      $expression = ord($event[3][1]);
    } else if (($event[1] & 0xf0) === 0x90) {
      $result[] = $expression;
    }
  }
  return $result;
}

function midiNoteVelocities(MADSCORE\Score\Score $score): array {
  return array_map(static fn (array $event): int => ord($event[3][1]),
    array_values(array_filter(midiMelodyEvents($score),
      static fn (array $event): bool => ($event[1] & 0xf0) === 0x90)));
}

$screen = (new SPTK\XmlParser\XmlParser())->windows[0]['screens'][0];
expectMidi($screen->widget('exportButton')->hotkey() === 'ctrl+e', 'Ctrl+E opens export formats.');
$score = new MADSCORE\Score\Score(2);
$score->setBeats(0, 2);
$score->ensureBar(1);
$score->setTempo(1, '60');
$score->setSectionRepeats(0, 1);
$score->setMelodyPattern(1, 'kick');
$score->place(0, 0, 0, 4, 1, 2);
$score->place(0, 1, 0, 4, 4, 2);
$score->place(1, 0, 0, 4, 1, 1);
$stacked = new MADSCORE\Score\Score();
$stacked->place(0, 0, 0, 3, 4, 2);
$stacked->addChordTone(0, 0, 0, 3, 8, 2);
$stackedOnsets = array_values(array_filter(midiMelodyEvents($stacked),
  static fn (array $event): bool => ($event[1] & 0xf0) === 0x90));
expectMidi(count($stackedOnsets) === 2
  && $stackedOnsets[0][0] === 0 && $stackedOnsets[1][0] === 0,
  'Two tones in one melody start together in MIDI.');
$articulation = new MADSCORE\Score\Score();
$short = $articulation->place(0, 0, 0, 4, 2, 2);
$articulation->setStaccato($short, true);
$articulation->place(0, 0, 2, 4, 2, 2);
$articulation->place(0, 0, 4, 4, 2, 2);
$articulationEvents = midiMelodyEvents($articulation);
$noteOffs = array_values(array_filter($articulationEvents,
  static fn (array $event): bool => ($event[1] & 0xf0) === 0x80));
expectMidi(array_column($noteOffs, 0) === [240, 946, 1426],
  'MIDI shortens only marked staccato notes; repeated ordinary pitches keep their full duration.');
$staccatoStops = array_values(array_filter($articulationEvents,
  static fn (array $event): bool => ($event[1] & 0xf0) === 0xb0 && $event[3] === "\x78\x00"));
expectMidi(array_column($staccatoStops, 0) === [240],
  'MIDI damps a staccato voice at its shortened end.');
$midi = MADSCORE\Play\MidiWriter::encode($score);
expectMidi(substr($midi, 0, 14) === 'MThd' . pack('Nnnn', 6, 1, 3, 480),
  'Export is a type-1 MIDI file at 480 ticks per beat.');
$tracks = [];
$offset = 14;
while ($offset < strlen($midi)) {
  expectMidi(substr($midi, $offset, 4) === 'MTrk', 'Each MIDI chunk is a track.');
  $length = unpack('N', substr($midi, $offset + 4, 4))[1];
  $tracks[] = midiEvents(substr($midi, $offset + 8, $length));
  $offset += 8 + $length;
}
expectMidi(count($tracks) === 3 && $offset === strlen($midi), 'Track lengths cover the entire file.');
$tempos = array_values(array_filter($tracks[0], static fn (array $event): bool => $event[2] === 0x51));
expectMidi(array_column($tempos, 0) === [0, 960, 1920, 2880]
  && substr($tempos[0][3], 0, 3) === substr(pack('N', 500000), 1)
  && $tempos[1][3] === substr(pack('N', 1000000), 1), 'Tempo changes recur in the repeated section.');
$melodyOns = array_values(array_filter($tracks[1], static fn (array $event): bool => ($event[1] & 0xf0) === 0x90));
expectMidi(array_column($melodyOns, 0) === [0, 960, 1920, 2880]
  && array_map(static fn (array $event): int => ord($event[3][0]), $melodyOns) === [69, 72, 69, 72],
  'Melody notes retain pitch and placement through the repeat.');
$drumOns = array_values(array_filter($tracks[2], static fn (array $event): bool => $event[1] === 0x99));
expectMidi(array_column($drumOns, 0) === [0, 1920] && ord($drumOns[0][3][0]) === 36,
  'Kick notes use the GM drum channel and repeat.');

$clip = MADSCORE\Play\MidiWriter::encode($score, 0, 1, 6);
expectMidi(unpack('n', substr($clip, 10, 2))[1] === 2,
  'One melody and a selected span produce only a conductor and melody track.');
$clipConductorLength = unpack('N', substr($clip, 18, 4))[1];
$clipTempos = array_values(array_filter(midiEvents(substr($clip, 22, $clipConductorLength)),
  static fn (array $event): bool => $event[2] === 0x51));
$clipTrackAt = 22 + $clipConductorLength;
$clipTrackLength = unpack('N', substr($clip, $clipTrackAt + 4, 4))[1];
$clipOns = array_values(array_filter(midiEvents(substr($clip, $clipTrackAt + 8, $clipTrackLength)),
  static fn (array $event): bool => ($event[1] & 0xf0) === 0x90));
expectMidi(array_column($clipTempos, 0) === [0, 720]
  && array_column($clipOns, 0) === [0, 720],
  'Selected MIDI starts at zero, clips a crossing note, keeps tempo changes, and plays no repeats.');

$cursorMidi = MADSCORE\Play\MidiWriter::encode($score, 0, 1);
$cursorConductorLength = unpack('N', substr($cursorMidi, 18, 4))[1];
$cursorTrackAt = 22 + $cursorConductorLength;
$cursorTrackLength = unpack('N', substr($cursorMidi, $cursorTrackAt + 4, 4))[1];
$cursorOns = array_values(array_filter(midiEvents(substr($cursorMidi, $cursorTrackAt + 8, $cursorTrackLength)),
  static fn (array $event): bool => ($event[1] & 0xf0) === 0x90));
expectMidi(array_column($cursorOns, 0) === [0, 720, 1680, 2640],
  'MIDI begins on a crossing note at the cursor and keeps subsequent section repeats.');

$shared = new MADSCORE\Score\Score();
$shared->setMelodyInstrument(0, 'gm:16');
$shared->setMelodyVolume(0, 100);
$shared->ensureBar(1);
foreach ([0, 2, 4, 6] as $slot) {
  $shared->place(0, 0, $slot, 4, 1, 2);
}
$shared->place(0, 1, 0, 4, 1, 2);
$shared->setScoreDynamicLevel(0, 'mf');
$shared->setScoreDynamicWedge(2, 6, 'crescendo');
$shared->setScoreDynamicLevel(6, 'f');
expectMidi(midiNoteExpressions($shared) === [76, 76, 86, 95, 95],
  'A shared crescendo reaches its written f target and holds it afterward.');
$pianoDynamics = MADSCORE\Score\Score::fromData($shared->data());
$pianoDynamics->setMelodyInstrument(0, 'gm:0');
expectMidi(midiNoteVelocities($pianoDynamics) === [60, 60, 68, 75, 75]
  && !array_filter(midiMelodyEvents($pianoDynamics),
    static fn (array $event): bool => ($event[1] & 0xf0) === 0xb0 && ord($event[3][0]) === 11),
  'Piano dynamics change note velocity without changing the volume of ringing notes.');
expectMidi(MADSCORE\Score\Sound::dynamicControl('gm:16') === 'expression'
  && MADSCORE\Score\Sound::dynamicControl('gm:40') === 'expression'
  && MADSCORE\Score\Sound::dynamicControl('gm:73') === 'expression'
  && MADSCORE\Score\Sound::dynamicControl('gm:45') === 'velocity'
  && MADSCORE\Score\Sound::dynamicControl('gm:46') === 'velocity'
  && MADSCORE\Score\Sound::dynamicControl('drum:36') === 'velocity',
  'Organs, bowed strings, and winds use expression; plucked and struck sounds use velocity.');
$openingDynamics = new MADSCORE\Score\Score();
$openingDynamics->setMelodyInstrument(0, 'gm:16');
$openingDynamics->setMelodyVolume(0, 100);
$openingDynamics->place(0, 0, 0, 4, 1, 2);
$markedNote = $openingDynamics->place(0, 0, 2, 4, 1, 2);
$openingDynamics->place(0, 0, 4, 4, 1, 2);
$openingDynamics->setDynamicLevel($markedNote, 'p');
expectMidi(midiNoteExpressions($openingDynamics) === [70, 51, 51],
  'An organ starts between mp and mf, then holds its first written level.');
$openingDynamics->setMelodyInstrument(0, 'gm:0');
expectMidi(midiNoteVelocities($openingDynamics) === [55, 40, 40],
  'A piano starts between mp and mf, then holds its first written level.');
$unmarkedWedge = new MADSCORE\Score\Score();
$unmarkedWedge->setMelodyInstrument(0, 'gm:16');
$unmarkedWedge->place(0, 0, 0, 4, 1, 2);
$unmarkedWedge->place(0, 0, 4, 4, 1, 2);
$unmarkedWedge->setScoreDynamicWedge(0, 4, 'crescendo');
expectMidi(midiNoteExpressions($unmarkedWedge) === [70, 127],
  'An organ crescendo without an opening mark starts from the baseline.');
$unmarkedWedge->setMelodyInstrument(0, 'gm:0');
$unmarkedWedge->setMelodyVolume(0, 100);
expectMidi(midiNoteVelocities($unmarkedWedge) === [55, 100],
  'A piano crescendo without an opening mark starts from the baseline.');

$levelStep = new MADSCORE\Score\Score();
$levelStep->setMelodyInstrument(0, 'gm:16');
$levelStep->ensureBar(1);
$levelStep->place(0, 0, 6, 4, 1, 2);
$levelStep->place(0, 1, 0, 4, 3, 2);
$levelStep->setScoreDynamicLevel(0, 'f');
$levelStep->setScoreDynamicLevel(8, 'mp');
$stepAtBar = array_values(array_filter(midiMelodyEvents($levelStep),
  static fn (array $event): bool => $event[0] === 1920));
expectMidi(array_map(static fn (array $event): int => $event[1] & 0xf0, $stepAtBar) ===
  [0xb0, 0xb0, 0x90]
  && ord($stepAtBar[0][3][0]) === 11 && ord($stepAtBar[0][3][1]) === 64
  && midiNoteExpressions($levelStep) === [95, 64],
  'A score level changes at the bar boundary before its first note begins.');

$local = new MADSCORE\Score\Score();
$local->setMelodyInstrument(0, 'gm:16');
$local->ensureBar(1);
foreach ([0, 2, 4, 6] as $slot) {
  $local->place(0, 0, $slot, 4, 1, 2);
}
$local->place(0, 1, 0, 4, 1, 2);
$local->setDynamicLevel(0, 'mp');
$local->setDynamicToNext(0, 'crescendo');
$local->setDynamicToNext(1, 'crescendo');
expectMidi(midiNoteExpressions($local) === [64, 95, 127, 127, 127],
  'A note-to-note crescendo holds its closing level on later unmarked notes.');

$gliss = new MADSCORE\Score\Score();
$from = $gliss->place(0, 0, 0, 4, 1, 2);
$gliss->place(0, 0, 2, 4, 4, 2);
$gliss->setGlissandoToNext($from, true);
$glissOn = array_values(array_filter(midiMelodyEvents($gliss),
  static fn (array $event): bool => ($event[1] & 0xf0) === 0x90));
expectMidi(count($glissOn) === 3 && array_column($glissOn, 0) === [0, 320, 640],
  'A glissando plays each white key once, including its destination.');

$trill = new MADSCORE\Score\Score();
$trilled = $trill->place(0, 0, 0, 4, 1, 2);
$trill->setTrill($trilled, 1);
$trillOn = array_values(array_filter(midiMelodyEvents($trill),
  static fn (array $event): bool => ($event[1] & 0xf0) === 0x90));
expectMidi(array_column($trillOn, 0) === [0, 120, 240, 360]
  && array_map(static fn (array $event): int => ord($event[3][0]), $trillOn) === [69, 70, 69, 70],
  'A trill alternates both pitches in half-slot pulses.');

$fermata = new MADSCORE\Score\Score();
$held = $fermata->place(0, 0, 6, 4, 1, 2);
$fermata->setFermata($held, true);
$lastOff = array_values(array_filter(midiMelodyEvents($fermata),
  static fn (array $event): bool => ($event[1] & 0xf0) === 0x80));
expectMidi($lastOff[0][0] === 2160, 'A final fermata continues past the end of the last bar.');

$articulated = new MADSCORE\Score\Score();
$short = $articulated->place(0, 0, 0, 4, 1, 2);
$linked = $articulated->place(0, 0, 2, 4, 3, 2);
$articulated->place(0, 0, 4, 4, 4, 2);
$articulated->setStaccato($short, true);
$articulated->setSlurToNext($linked, true);
$articulatedOff = array_values(array_filter(midiMelodyEvents($articulated),
  static fn (array $event): bool => ($event[1] & 0xf0) === 0x80));
expectMidi(array_column($articulatedOff, 0) === [240, 965, 1426],
  'Staccato shortens its note and a slur overlaps the following onset.');

$repeated = new MADSCORE\Score\Score();
$repeated->setMelodyInstrument(0, 'gm:16');
$repeatedNotes = [];
foreach ([0, 2, 4, 6, 8, 10] as $slot) {
  if ($slot === 8) {
    $repeated->ensureBar(1);
  }
  $repeatedNotes[] = $repeated->place(0, intdiv($slot, 8), $slot % 8, 4, 2, 2);
}
foreach (array_slice($repeatedNotes, 0, -1) as $note) {
  $repeated->setSlurToNext($note, true);
}
$repeatedEvents = array_values(array_filter(midiMelodyEvents($repeated),
  static fn (array $event): bool => in_array($event[1] & 0xf0, [0x80, 0x90], true)));
expectMidi(array_column($repeatedEvents, 0) ===
  [0, 480, 480, 960, 960, 1440, 1440, 1920, 1920, 2400, 2400, 2866]
  && array_map(static fn (array $event): int => $event[1] & 0xf0, $repeatedEvents) ===
    [0x90, 0x80, 0x90, 0x80, 0x90, 0x80, 0x90, 0x80, 0x90, 0x80, 0x90, 0x80],
  'Linked repeated MIDI pitches keep all attacks, including across bars.');
$pedal = array_values(array_filter(midiMelodyEvents($repeated),
  static fn (array $event): bool => ($event[1] & 0xf0) === 0xb0 && ord($event[3][0]) === 64));
expectMidi(array_column($pedal, 0) === [480, 528, 960, 1008, 1440, 1488, 1920, 1968, 2400, 2448]
  && array_map(static fn (array $event): int => ord($event[3][1]), $pedal) ===
    [127, 0, 127, 0, 127, 0, 127, 0, 127, 0],
  'Same-pitch slurs carry sound briefly across each new attack.');
$firstJoin = array_values(array_filter(midiMelodyEvents($repeated),
  static fn (array $event): bool => $event[0] === 480));
expectMidi(array_map(static fn (array $event): int => $event[1] & 0xf0, $firstJoin) ===
  [0xb0, 0x80, 0xb0, 0x90],
  'The previous linked voice releases before the next same-pitch attack.');

$example = MADSCORE\Score\Project::fromData(json_decode(file_get_contents(APP_DIR . '/Assets/example.json'), true, 512, JSON_THROW_ON_ERROR))->score('main');
expectMidi(count($example->melodies) === 5 && count($example->notes) > 60
  && str_starts_with(MADSCORE\Play\MidiWriter::encode($example), 'MThd'),
  'The example score loads and exports with its notation features.');
if (MADSCORE\Play\MidiPlayback::unavailableReason() === null) {
  $wave = MADSCORE\Play\MidiPlayback::render($score, 0, 0, 2);
  try {
    expectMidi(str_starts_with(file_get_contents($wave), 'RIFF'),
      'Installed FluidSynth renders a selected melody to a playable WAV.');
  } finally {
    unlink($wave);
  }
}
$originalPath = getenv('PATH');
try {
  putenv('PATH=/nonexistent-madscore-tools');
  expectMidi(MADSCORE\Play\MidiPlayback::unavailableReason() === 'MIDI playback needs the fluidsynth command.',
    'MIDI playback reports when FluidSynth is unavailable.');
} finally {
  $originalPath === false ? putenv('PATH') : putenv('PATH=' . $originalPath);
}

echo "MIDI checks passed\n";
