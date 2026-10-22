<?php

namespace MADSCORE\Score;

/** Selects four consecutive octave rows, including their lower G-sharp endpoint. */
final class MelodyRange {

  /** Suggest a pitch from the note under the cursor or its two nearest neighbors. */
  public static function cursorPitch(Score $score, int $melody, int $bar, int $slot): ?array {
    $index = $score->noteAt($melody, $bar, $slot);
    if ($index !== null) {
      $note = $score->notes[$index];
      return [$note['octave'], Pitch::position($note)];
    }
    $time = $score->start($bar) + $slot;
    $previous = null;
    $next = null;
    $previousEnd = -1;
    $nextStart = PHP_INT_MAX;
    foreach ($score->notes as $note) {
      if ($note['melody'] !== $melody) {
        continue;
      }
      $start = $score->start($note['bar']) + $note['slot'];
      $end = $start + $note['length'];
      if ($end <= $time && $end > $previousEnd) {
        $previous = $note;
        $previousEnd = $end;
      }
      if ($start > $time && $start < $nextStart) {
        $next = $note;
        $nextStart = $start;
      }
    }
    if ($previous === null && $next === null) {
      return null;
    }
    $before = $previous === null ? null : $previous['octave'] * 12 + Pitch::position($previous);
    $after = $next === null ? null : $next['octave'] * 12 + Pitch::position($next);
    $absolute = $before === null ? $after : ($after === null ? $before : intdiv($before + $after + 1, 2));
    return [intdiv($absolute - 1, 12), ($absolute - 1) % 12 + 1];
  }

  /** Return row starts that contain every pitch from lower G-sharp to upper G-sharp. */
  public static function possibleBottoms(array $absolutePitches): array {
    $bottoms = [];
    for ($bottom = 0; $bottom <= 5; $bottom++) {
      $low = 12 * $bottom;
      $high = $low + 48;
      if ($absolutePitches === [] || (min($absolutePitches) >= $low && max($absolutePitches) <= $high)) {
        $bottoms[] = $bottom;
      }
    }
    return $bottoms;
  }

  public static function bands(Score $score, int $melody, ?int $include = null, int $includePosition = 1): array {
    $counts = array_fill(0, 9, 0);
    $pitches = [];
    foreach ($score->notes as $note) {
      if ($note['melody'] === $melody) {
        $counts[$note['octave']]++;
        $pitches[] = 12 * $note['octave'] + Pitch::position($note);
      }
    }
    if ($include !== null) {
      $pitches[] = 12 * $include + $includePosition;
    }
    $bottoms = self::possibleBottoms($pitches);
    if ($bottoms === []) {
      throw new \DomainException('A melody must fit four octave rows from G-sharp to G-sharp.');
    }
    $dominant = Pitch::MIDDLE_C_BAND;
    for ($band = 0; $band <= 8; $band++) {
      if ($counts[$band] > $counts[$dominant]) {
        $dominant = $band;
      }
    }
    $best = null;
    $bestKey = null;
    foreach ($bottoms as $bottom) {
      $row = $bottom + 3 - $dominant;
      $key = [(int)($row === 1 || $row === 2), -abs($row - 1.5),
        -abs($bottom + 1.5 - Pitch::MIDDLE_C_BAND), -$bottom];
      if ($bestKey === null || $key > $bestKey) {
        $bestKey = $key;
        $best = $bottom;
      }
    }
    return range($best + 3, $best);
  }
}
