<?php

define('APP_DIR', dirname(__DIR__));
define('APP_NAMESPACE', 'MADSCORE');
require APP_DIR . '/SPTK/App.php';

use MADSCORE\Score\MotifScoreGenerator;
use MADSCORE\Score\Score;

function expectMotifScore(bool $condition, string $message): void {
  if (!$condition) {
    throw new RuntimeException($message);
  }
}

$draw = static fn (int $low, int $high): int => intdiv($low + $high, 2);
$high = static fn (int $low, int $high): int => $high;
$low = static fn (int $low, int $high): int => $low;
expectMotifScore(MotifScoreGenerator::generate(4, 2, $low)['scale'] === 'Major'
  && MotifScoreGenerator::generate(4, 2, $low)['homeNote'] === 'C'
  && MotifScoreGenerator::generate(4, 2, $high)['scale'] === 'Locrian'
  && MotifScoreGenerator::generate(4, 2, $high)['homeNote'] === 'B',
  'Unspecified scale and home note are selected by the random source.');
$randomFirst = MotifScoreGenerator::place(new Score(), 4, 2, random: $low);
$randomSecond = MotifScoreGenerator::place($randomFirst['score'], 4, 2, 0, $low);
$randomThird = MotifScoreGenerator::place($randomSecond['score'], 4, 2, 0, $low);
expectMotifScore($randomFirst['motif']['scale'] !== $randomSecond['motif']['scale']
  && $randomFirst['motif']['homeNote'] !== $randomSecond['motif']['homeNote']
  && $randomSecond['motif']['scale'] !== $randomThird['motif']['scale']
  && $randomSecond['motif']['homeNote'] !== $randomThird['motif']['homeNote']
  && $randomSecond['score']->melodies[0]['motif']['randomScale'] === true
  && $randomSecond['score']->melodies[0]['motif']['randomHomeNote'] === true
  && Score::fromData($randomSecond['score']->data())->melodies[0]['motif']['randomScale'] === true,
  'Random selections remain random and change on each regeneration.');
$mixedFirst = MotifScoreGenerator::place(new Score(), 4, 2, random: $low,
  scale: 'Dorian');
$mixedSecond = MotifScoreGenerator::place($mixedFirst['score'], 4, 2, 0, $low,
  scale: 'Dorian');
expectMotifScore($mixedFirst['motif']['scale'] === $mixedSecond['motif']['scale']
  && $mixedFirst['motif']['homeNote'] !== $mixedSecond['motif']['homeNote']
  && $mixedSecond['score']->melodies[0]['motif']['randomScale'] === false
  && $mixedSecond['score']->melodies[0]['motif']['randomHomeNote'] === true,
  'A fixed scale stays fixed while a Random home note changes.');
foreach (MotifScoreGenerator::SCALES as $scale => $intervals) {
  foreach (MotifScoreGenerator::HOME_NOTES as $homeNote => $semitones) {
    $chosen = MotifScoreGenerator::generate(8, 7, $draw, $scale, $homeNote);
    $expected = array_map(static fn (int $degree): int => 60 + $semitones
      + 12 * (int)floor($degree / 7) + $intervals[(($degree % 7) + 7) % 7],
      $chosen['palette']);
    expectMotifScore($chosen['scale'] === $scale && $chosen['homeNote'] === $homeNote
      && $chosen['notes'][0]['pitch'] === 60 + $semitones
      && array_diff(array_column($chosen['notes'], 'pitch'), $expected) === []
      && count(array_unique(array_column($chosen['notes'], 'pitch'))) === 7,
      'Every scale and home note produce exactly the chosen seven pitches.');
  }
}
foreach ([3 => 3, 4 => 4, 5 => 5, 6 => 6, 7 => 4, 8 => 4,
  9 => 3, 10 => 5, 11 => 4, 12 => 4] as $beats => $meter) {
  expectMotifScore(MotifScoreGenerator::meter($beats) === $meter,
    "{$beats} beats choose a {$meter}-beat bar.");
  $placed = MotifScoreGenerator::place(new Score(), $beats, min(4, $beats), random: $draw);
  expectMotifScore($placed['motif']['barBeats'] === $meter
    && count($placed['score']->bars) === intdiv($beats + $meter - 1, $meter)
    && array_unique(array_column($placed['score']->bars, 'beats')) === [$meter],
    'Motif placement applies the chosen bar length throughout its score.');
}
foreach ([3, 4, 8, 32] as $beats) {
  foreach (range(1, 7) as $pitchCount) {
    if ($pitchCount > $beats) {
      continue;
    }
    foreach ([$low, $draw, $high] as $random) {
      $generated = MotifScoreGenerator::generate($beats, $pitchCount, $random);
      $nextStart = 0;
      foreach ($generated['notes'] as $note) {
        expectMotifScore($note['start'] === $nextStart
          && in_array($note['length'], [1, 2], true),
          'Generated notes are contiguous and last no longer than one beat.');
        $nextStart += $note['length'];
      }
      expectMotifScore($nextStart === 2 * $beats
        && $generated['eventCount'] === $beats + count($generated['splits'])
        && max(array_map('abs', $generated['steps'])) <= 3
        && count(array_unique(array_map(static fn (array $note): int =>
          $note['pitch'], $generated['notes']))) === $pitchCount,
        'Every beat is filled, and every requested pitch sounds within the step limit.');
    }
  }
}
foreach (range(1, 12) as $seed) {
  $state = $seed;
  $random = static function (int $low, int $high) use (&$state): int {
    $state = ($state * 1103515245 + 12345) & 0x7fffffff;
    return $low + $state % ($high - $low + 1);
  };
  foreach ([3, 4, 8, 32] as $beats) {
    foreach (range(1, min(7, $beats)) as $pitchCount) {
      $generated = MotifScoreGenerator::generate($beats, $pitchCount, $random);
      expectMotifScore(count(array_unique(array_column($generated['notes'], 'pitch')))
        === $pitchCount && max(array_map('abs', $generated['steps'])) <= 3,
        'Varied draws use exactly the requested pitches within the step limit.');
    }
  }
}
$first = MotifScoreGenerator::place(new Score(), 4, 4, random: $high,
  scale: 'Major', homeNote: 'C');
$score = $first['score'];
$motif = $first['motif'];
$lastNote = $motif['notes'][$motif['eventCount'] - 1];
expectMotifScore($first['label'] === 'A' && $first['melody'] === 0
  && count($score->melodies) === 1 && count($score->notes) === $motif['eventCount'],
  'The initial empty melody becomes motif A.');
expectMotifScore($motif['pitchCount'] === 4 && count($motif['palette']) === 4
  && $motif['eventCount'] === 4 + count($motif['splits'])
  && $motif['notes'][0]['start'] === 0 && $motif['notes'][0]['pitch'] === 60
  && count($motif['splits']) === 4
  && $lastNote['start'] + $lastNote['length'] === 8,
  'Four beats contain at least four notes and fill the requested duration.');
$splitStart = 2 * $motif['splits'][0];
$halfNotes = array_values(array_filter($motif['notes'],
  static fn (array $note): bool => $note['start'] === $splitStart
    || $note['start'] === $splitStart + 1));
expectMotifScore(count($halfNotes) === 2
  && array_column($halfNotes, 'length') === [1, 1]
  && array_diff(array_column($motif['notes'], 'length'), [1, 2]) === [],
  'A split makes an adjacent pair of half-beat notes; no note exceeds one beat.');
$firstDraw = true;
$up = static function (int $low, int $high) use (&$firstDraw): int {
  if ($firstDraw) {
    $firstDraw = false;
    return $low;
  }
  return $high;
};
$largeLeapMotif = MotifScoreGenerator::generate(4, 4, $up);
$largeMove = false;
foreach ($largeLeapMotif['steps'] as $index => $step) {
  if (abs($step) >= 3) {
    $largeMove = true;
    if (isset($largeLeapMotif['steps'][$index + 1])) {
      expectMotifScore($step * $largeLeapMotif['steps'][$index + 1] <= 0,
        'A large audible leap is never followed by another move in its direction.');
    }
  }
}
expectMotifScore($largeMove, 'The recovery check exercises a large pitch leap.');
$loaded = Score::fromData($score->data());
expectMotifScore($loaded->melodies[0]['motif']['label'] === 'A'
  && $loaded->melodies[0]['motif']['beats'] === 4
  && $loaded->melodies[0]['motif']['pitchCount'] === 4
  && $loaded->melodies[0]['motif']['scale'] === 'Major'
  && $loaded->melodies[0]['motif']['homeNote'] === 'C'
  && $loaded->melodies[0]['motif']['randomScale'] === false
  && $loaded->melodies[0]['motif']['randomHomeNote'] === false,
  'The motif label and timing survive save and load.');
$obsolete = $score->data();
$obsolete['melodies'][0]['motif']['version'] = 2;
try {
  Score::fromData($obsolete);
  throw new RuntimeException('Obsolete motif settings were accepted.');
} catch (DomainException $error) {
  expectMotifScore($error->getMessage() === 'Invalid motif settings.',
    'Obsolete motif settings are rejected.');
}
$invalid = $score->data();
$invalid['melodies'][0]['motif']['beats'] = 40;
try {
  Score::fromData($invalid);
  throw new RuntimeException('Invalid motif metadata was accepted.');
} catch (DomainException $error) {
  expectMotifScore($error->getMessage() === 'Invalid motif settings.',
    'Loading rejects invalid motif settings.');
}

$score->place(0, 0, 0, 4, 6, $motif['notes'][0]['length']);
$edited = Score::fromData($score->data());
expectMotifScore($edited->melodies[0]['motif']['label'] === 'A'
  && $edited->notes[0]['pitch'] === 'D',
  'Free editing keeps the motif identity.');
$second = MotifScoreGenerator::place($edited, 8, 7, random: $draw);
expectMotifScore($second['label'] === 'B' && $second['melody'] === 1
  && $second['motif']['pitchCount'] === 7
  && $second['motif']['eventCount'] >= 7
  && count($second['score']->notes) === $motif['eventCount'] + $second['motif']['eventCount']
  && count($second['score']->melodies) === 2,
  'Seven distinct pitches work in eight beats; B keeps its own melody.');
$restricted = MotifScoreGenerator::generate(8, 2, $draw, 'Major', 'C');
$scaleSemitones = [0, 2, 4, 5, 7, 9, 11];
$allowedPitches = array_map(static fn (int $degree): int =>
  60 + 12 * (int)floor($degree / 7) + $scaleSemitones[(($degree % 7) + 7) % 7],
  $restricted['palette']);
expectMotifScore(count($restricted['palette']) === 2
  && $restricted['eventCount'] >= 8
  && array_diff(array_column($restricted['notes'], 'pitch'), $allowedPitches) === []
  && count(array_unique(array_column($restricted['notes'], 'pitch'))) === 2,
  'Every selected sounding pitch appears, with no extra pitches.');
$beforeB = array_values(array_filter($second['score']->notes,
  static fn (array $note): bool => $note['melody'] === 1));
$regenerated = MotifScoreGenerator::place($second['score'], 4, 4, 0, $draw)['score'];
expectMotifScore($regenerated->melodies[0]['motif']['label'] === 'A'
  && array_values(array_filter($regenerated->notes,
    static fn (array $note): bool => $note['melody'] === 1)) === $beforeB,
  'Regeneration replaces only the selected motif and preserves its letter.');
$resized = MotifScoreGenerator::place($second['score'], 9, 4, 0, $draw)['score'];
expectMotifScore(array_unique(array_column($resized->bars, 'beats')) === [3]
  && count(array_filter($resized->notes,
    static fn (array $note): bool => $note['melody'] === 1)) === count($beforeB),
  'Regeneration applies a new meter while retaining the other melody.');

$single = MotifScoreGenerator::place(new Score(), 32, 1, random: $draw);
expectMotifScore($single['motif']['eventCount'] > 1
  && $single['motif']['eventCount'] >= 32
  && count(array_unique(array_map(static fn (array $note): int => $note['pitch'] % 12,
    $single['motif']['notes']))) === 1
  && count($single['score']->bars) >= 8,
  'One available pitch may sound repeatedly over the longest motif.');
try {
  MotifScoreGenerator::generate(3, 8);
  throw new RuntimeException('Eight available pitches were accepted.');
} catch (DomainException $error) {
  expectMotifScore(str_contains($error->getMessage(), 'distinct pitches'),
    'The palette limit is seven pitches.');
}
try {
  MotifScoreGenerator::generate(3, 4);
  throw new RuntimeException('Four distinct pitches were accepted for three beats.');
} catch (DomainException $error) {
  expectMotifScore(str_contains($error->getMessage(), 'cannot exceed'),
    'The requested pitch count cannot exceed the beat count.');
}

echo "Motif score checks passed\n";
