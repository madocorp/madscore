<?php

namespace MADSCORE\Score;

/** Place a generated motif in its own editable melody. */
final class MotifScoreGenerator {

  public const TYPES = [
    'random-step' => 'Random step',
    'up-wave' => 'Up wave',
    'down-wave' => 'Down wave',
    'alternating' => 'Alternating low and high',
    'skipping' => 'Skipping notes',
  ];

  /** Read wave names saved before the pitch-count choice controlled both waves. */
  public static function canonicalType(string $type): string {
    $type = match ($type) {
      'base-up-wave', 'scale-up-wave' => 'up-wave',
      'base-down-wave', 'scale-down-wave' => 'down-wave',
      default => $type,
    };
    if (!isset(self::TYPES[$type])) {
      throw new \DomainException('Unknown motif generator type.');
    }
    return $type;
  }

  public const SCALES = [
    'Major' => [0, 2, 4, 5, 7, 9, 11],
    'Dorian' => [0, 2, 3, 5, 7, 9, 10],
    'Phrygian' => [0, 1, 3, 5, 7, 8, 10],
    'Lydian' => [0, 2, 4, 6, 7, 9, 11],
    'Mixolydian' => [0, 2, 4, 5, 7, 9, 10],
    'Minor' => [0, 2, 3, 5, 7, 8, 10],
    'Locrian' => [0, 1, 3, 5, 6, 8, 10],
  ];
  public const HOME_NOTES = ['C' => 0, 'C#' => 1, 'D' => 2, 'D#' => 3,
    'E' => 4, 'F' => 5, 'F#' => 6, 'G' => 7, 'G#' => 8, 'A' => 9,
    'A#' => 10, 'B' => 11];
  public const STEP_WEIGHTS = [
    -3 => 3, -2 => 8, -1 => 8, 0 => 3,
    1 => 8, 2 => 8, 3 => 3,
  ];

  /** Draw a small scale step, favoring moves of one or two degrees. */
  public static function step(?callable $random = null): int {
    $random ??= random_int(...);
    $draw = $random(1, array_sum(self::STEP_WEIGHTS));
    if (!is_int($draw) || $draw < 1 || $draw > array_sum(self::STEP_WEIGHTS)) {
      throw new \DomainException('Motif random draw is outside the weight range.');
    }
    foreach (self::STEP_WEIGHTS as $step => $weight) {
      $draw -= $weight;
      if ($draw <= 0) {
        return $step;
      }
    }
    throw new \LogicException('Motif weights do not cover the draw.');
  }

  /** Fit the motif into bars of three to six beats, preferring four on ties. */
  public static function meter(int $beats): int {
    if ($beats < 3 || $beats > 32) {
      throw new \DomainException('Motif length must be from 3 to 32 beats.');
    }
    if ($beats <= 6) {
      return $beats;
    }
    $best = 4;
    $bestKey = null;
    foreach (range(3, 6) as $size) {
      $padding = intdiv($beats + $size - 1, $size) * $size - $beats;
      $key = [$padding, abs($size - 4), intdiv($beats + $size - 1, $size)];
      if ($bestKey === null || $key < $bestKey) {
        $best = $size;
        $bestKey = $key;
      }
    }
    return $best;
  }

  public static function validateMetadata(mixed $value): array {
    if (is_array($value) && is_string($value['type'] ?? null)) {
      $value['type'] = self::canonicalType($value['type']);
    }
    if (!is_array($value) || ($value['version'] ?? null) !== 3
      || !is_string($value['label'] ?? null)
      || preg_match('/^[A-Z]+$/D', $value['label']) !== 1
      || !isset(self::SCALES[$value['scale'] ?? ''])
      || !isset(self::HOME_NOTES[$value['homeNote'] ?? ''])
      || (isset($value['randomScale']) && !is_bool($value['randomScale']))
      || (isset($value['randomHomeNote']) && !is_bool($value['randomHomeNote']))
      || !is_string($value['type'] ?? 'random-step')
      || !isset(self::TYPES[$value['type'] ?? 'random-step'])
      || !is_int($value['beats'] ?? null) || $value['beats'] < 3 || $value['beats'] > 32
      || !is_int($value['pitchCount'] ?? null) || $value['pitchCount'] < 1
      || $value['pitchCount'] > 7 || $value['pitchCount'] > $value['beats']) {
      throw new \DomainException('Invalid motif settings.');
    }
    return array_intersect_key($value,
      array_flip(['version', 'label', 'scale', 'homeNote', 'randomScale',
        'randomHomeNote', 'beats', 'pitchCount', 'type']));
  }

  private static function nextLabel(Score $score): string {
    $used = [];
    foreach ($score->melodies as $settings) {
      if (isset($settings['motif']['label'])) {
        $used[$settings['motif']['label']] = true;
      }
    }
    foreach (range('A', 'Z') as $label) {
      if (!isset($used[$label])) {
        return $label;
      }
    }
    throw new \DomainException('The score already has motifs A through Z.');
  }

  private static function pitchForDegree(int $degree, array $scale, int $home): int {
    $octave = (int)floor($degree / 7);
    $position = (($degree % 7) + 7) % 7;
    return 60 + $home + 12 * $octave + $scale[$position];
  }

  /** Select neighboring scale pitches around the home note so each is reachable. */
  private static function palette(int $pitchCount, callable $random): array {
    $start = -$random(0, $pitchCount - 1);
    return range($start, $start + $pitchCount - 1);
  }

  /** Check whether the remaining note positions can visit every selected pitch. */
  private static function canCover(int $degree, int $lastMove, int $seen, int $slots,
    array $palette, int $all, array &$memo): bool {
    if ($seen === $all) {
      return true;
    }
    if ($slots < count($palette) - substr_count(decbin($seen), '1')) {
      return false;
    }
    $key = "$degree:$lastMove:$seen:$slots";
    if (isset($memo[$key])) {
      return $memo[$key];
    }
    foreach ($palette as $next) {
      $move = $next - $degree;
      if (abs($move) > 3) {
        continue;
      }
      if (abs($lastMove) >= 3 && $move * $lastMove > 0) {
        continue;
      }
      if (self::canCover($next, $move, $seen | (1 << ($next - $palette[0])),
        $slots - 1, $palette, $all, $memo)) {
        return $memo[$key] = true;
      }
    }
    return $memo[$key] = false;
  }

  private static function closestAllowed(int $target, int $previous, int $direction,
    array $palette, int $recoverFrom, int $seen, int $slots, int $all,
    array &$memo): int {
    $best = null;
    $bestKey = null;
    foreach ($palette as $candidate) {
      if (abs($candidate - $previous) > 3) {
        continue;
      }
      if ($recoverFrom !== 0 && ($candidate - $previous) * $recoverFrom > 0) {
        continue;
      }
      $candidateSeen = $seen | (1 << ($candidate - $palette[0]));
      if (!self::canCover($candidate, $candidate - $previous, $candidateSeen,
        $slots, $palette, $all, $memo)) {
        continue;
      }
      $key = [$recoverFrom !== 0 && $candidate === $previous ? 1 : 0,
        abs($candidate - $target),
        $direction > 0 ? (int)($candidate < $previous)
          : ($direction < 0 ? (int)($candidate > $previous) : 0),
        abs($candidate - $previous)];
      if ($bestKey === null || $key < $bestKey) {
        $best = $candidate;
        $bestKey = $key;
      }
    }
    return $best ?? throw new \LogicException('Could not use every motif pitch.');
  }

  /** Begin with one note per beat, then split selected beats in half. */
  private static function rhythm(int $beats, callable $random): array {
    $free = range(0, $beats - 1);
    $splitCount = $random(0, min(4, $beats));
    $splits = [];
    for ($index = 0; $index < $splitCount; $index++) {
      $pick = $random(0, count($free) - 1);
      $splits[] = $free[$pick];
      array_splice($free, $pick, 1);
    }
    sort($splits);
    return ['splits' => $splits];
  }

  /** Visit every selected degree in a repeating, ordered cycle. */
  private static function orderedPattern(int $count, int $events, string $type): array {
    if ($count === 1) {
      return array_fill(0, $events, 0);
    }
    if ($type === 'alternating') {
      $cycle = [];
      for ($low = 0, $high = $count - 1; $low <= $high; $low++, $high--) {
        $cycle[] = $low;
        if ($low !== $high) {
          $cycle[] = $high;
        }
      }
    } else if ($type === 'skipping') {
      $cycle = [];
      foreach ([0, 1] as $start) {
        for ($degree = $start; $degree < $count; $degree += 2) {
          $cycle[] = $degree;
        }
      }
    } else {
      $cycle = $count === 2 ? [0, 1]
        : array_merge(range(0, $count - 1), range($count - 2, 1));
    }
    if ($type === 'down-wave') {
      $cycle = array_map(static fn (int $index): int => $count - 1 - $index, $cycle);
    }
    return array_map(static fn (int $index): int => $cycle[$index % count($cycle)],
      range(0, $events - 1));
  }

  /** Assign pitches after timing so every audible leap can affect the next move. */
  public static function generate(int $beats, int $pitchCount, ?callable $random = null,
    ?string $scale = null, ?string $homeNote = null,
    ?string $previousScale = null, ?string $previousHomeNote = null,
    string $type = 'random-step'): array {
    $type = self::canonicalType($type);
    if ($beats < 3 || $beats > 32 || $pitchCount < 1 || $pitchCount > 7) {
      throw new \DomainException('Use 3–32 beats and 1–7 distinct pitches.');
    }
    if ($pitchCount > $beats) {
      throw new \DomainException('The pitch count cannot exceed the motif length in beats.');
    }
    if (($scale !== null && !isset(self::SCALES[$scale]))
      || ($homeNote !== null && !isset(self::HOME_NOTES[$homeNote]))) {
      throw new \DomainException('Unknown motif scale or home note.');
    }
    $random ??= random_int(...);
    $palette = $type === 'random-step'
      ? self::palette($pitchCount, $random) : range(0, $pitchCount - 1);
    $rhythm = self::rhythm($beats, $random);
    $split = array_fill_keys($rhythm['splits'], true);
    $notes = [];
    for ($beat = 0; $beat < $beats; $beat++) {
      $notes[] = ['start' => 2 * $beat,
        'length' => isset($split[$beat]) ? 1 : 2];
      if (isset($split[$beat])) {
        $notes[] = ['start' => 2 * $beat + 1, 'length' => 1];
      }
    }
    if ($type === 'random-step') {
      $eventDegrees = [0];
      $seen = 1 << (0 - $palette[0]);
      $all = (1 << $pitchCount) - 1;
      $memo = [];
      for ($index = 1; $index < count($notes); $index++) {
        $previous = $eventDegrees[$index - 1];
        $lastMove = $index > 1 ? $previous - $eventDegrees[$index - 2] : 0;
        $requested = self::step($random);
        $recoverFrom = abs($lastMove) >= 3 ? $lastMove : 0;
        if ($recoverFrom !== 0) {
          $requested = ($lastMove > 0 ? -1 : 1) * min(2, max(1, abs($requested)));
        }
        $next = self::closestAllowed($previous + $requested, $previous,
          $requested, $palette, $recoverFrom, $seen,
          count($notes) - $index - 1, $all, $memo);
        $seen |= 1 << ($next - $palette[0]);
        $eventDegrees[] = $next;
      }
    } else {
      $positions = self::orderedPattern($pitchCount, count($notes), $type);
      $eventDegrees = array_map(static fn (int $position): int => $palette[$position],
        $positions);
    }
    $steps = [];
    for ($index = 1; $index < count($eventDegrees); $index++) {
      $steps[] = $eventDegrees[$index] - $eventDegrees[$index - 1];
    }
    $randomScale = $scale === null;
    $randomHomeNote = $homeNote === null;
    if ($randomScale) {
      $choices = array_values(array_diff(array_keys(self::SCALES), [$previousScale]));
      $scale = $choices[$random(0, count($choices) - 1)];
    }
    if ($randomHomeNote) {
      $choices = array_values(array_diff(array_keys(self::HOME_NOTES), [$previousHomeNote]));
      $homeNote = $choices[$random(0, count($choices) - 1)];
    }
    foreach ($eventDegrees as $index => $degree) {
      $notes[$index]['pitch'] = self::pitchForDegree($degree,
        self::SCALES[$scale], self::HOME_NOTES[$homeNote]);
    }
    return ['scale' => $scale, 'homeNote' => $homeNote,
      'randomScale' => $randomScale, 'randomHomeNote' => $randomHomeNote,
      'beats' => $beats, 'barBeats' => self::meter($beats),
      'pitchCount' => $pitchCount,
      'type' => $type,
      'palette' => $palette, 'eventCount' => count($notes),
      'rhythm' => 'beat-split',
      'splits' => $rhythm['splits'], 'steps' => $steps, 'notes' => $notes];
  }

  /** Add a new motif, or explicitly regenerate one existing motif by index. */
  public static function place(Score $score, int $beats, int $pitchCount,
    ?int $melody = null, ?callable $random = null,
    ?string $scale = null, ?string $homeNote = null,
    string $type = 'random-step'): array {
    $type = self::canonicalType($type);
    $previous = $melody === null ? null : ($score->melodies[$melody]['motif'] ?? null);
    $motif = self::generate($beats, $pitchCount, $random, $scale, $homeNote,
      $previous['scale'] ?? null, $previous['homeNote'] ?? null, $type);
    $next = clone $score;
    if ($melody === null) {
      $label = self::nextLabel($next);
      $melody = count($next->melodies) === 1 && $next->notes === []
        && !isset($next->melodies[0]['motif'])
        && !isset($next->melodies[0]['generation']) ? 0 : $next->addMelody();
    } else {
      $next->assertMelodyEditable($melody);
      $label = $next->melodies[$melody]['motif']['label'] ?? null;
      if ($label === null) {
        throw new \DomainException('The selected melody is not a motif.');
      }
      $next->notes = array_values(array_filter($next->notes,
        static fn (array $note): bool => $note['melody'] !== $melody));
    }
    for ($bar = 0; $bar < count($next->bars); $bar++) {
      $next->setBeats($bar, $motif['barBeats']);
    }
    foreach ($motif['notes'] as $note) {
      $position = $note['pitch'] - 20;
      $slot = $note['start'];
      $bar = 0;
      while ($slot >= 2 * $next->beats($bar)) {
        $slot -= 2 * $next->beats($bar);
        $bar++;
        $next->ensureBar($bar);
      }
      $next->place($melody, $bar, $slot,
        intdiv($position - 1, 12), ($position - 1) % 12 + 1, $note['length']);
    }
    $next->melodies[$melody]['motif'] = self::validateMetadata([
      'version' => 3, 'label' => $label, 'scale' => $motif['scale'],
      'homeNote' => $motif['homeNote'], 'randomScale' => $motif['randomScale'],
      'randomHomeNote' => $motif['randomHomeNote'],
      'beats' => $beats, 'pitchCount' => $motif['pitchCount'],
      'type' => $type,
    ]);
    return ['score' => $next, 'melody' => $melody, 'motif' => $motif, 'label' => $label];
  }
}
