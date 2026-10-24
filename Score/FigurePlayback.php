<?php

namespace MADSCORE\Score;

/** Timed note slices represented by one printed figure span. */
final class FigurePlayback {
  public static function segments(array $note, ?Project $project): ?array {
    $figure = $note['figure'] ?? null;
    if ($figure === null) {
      return null;
    }
    if (!isset($figure['motif'])) {
      return [
        ['start' => 0.0, 'end' => 1 / 3, 'offset' => 0],
        ['start' => 1 / 3, 'end' => 2 / 3, 'offset' => $figure['middle']],
        ['start' => 2 / 3, 'end' => 1.0, 'offset' => $figure['last']],
      ];
    }
    if ($project === null) {
      return null;
    }
    try {
      $notes = FigurePattern::notes($project, $figure['motif']);
    } catch (\DomainException) {
      return null;
    }
    $basePitch = 12 * $note['octave'] + Pitch::position($note);
    foreach ($notes as $part) {
      if ($basePitch + $part['offset'] < 1 || $basePitch + $part['offset'] > 108) {
        return null;
      }
    }
    $duration = max(array_map(static fn (array $part): int => $part['time'] + $part['length'], $notes));
    return array_map(static fn (array $part): array => [
      'start' => $part['time'] / $duration,
      'end' => ($part['time'] + $part['length']) / $duration,
      'offset' => $part['offset'],
    ], $notes);
  }
}
