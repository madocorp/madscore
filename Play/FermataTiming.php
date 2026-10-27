<?php

namespace MADSCORE\Play;

use MADSCORE\Score\Score;

/** Give parallel fermatas with the same written ending one release time. */
final class FermataTiming {

  /** @param callable(int): float $timeAt Maps a written half-beat position to playback time. */
  public static function ends(Score $score, callable $timeAt, ?int $melody = null): array {
    $groups = [];
    $writtenEnds = [];
    foreach ($score->notes as $index => $note) {
      if (!($note['fermata'] ?? false) || ($melody !== null && $note['melody'] !== $melody)) {
        continue;
      }
      $start = $score->start($note['bar']) + $note['slot'];
      $end = $start + $note['length'];
      $startTime = $timeAt($start);
      $endTime = $timeAt($end);
      $extended = $startTime + 1.5 * ($endTime - $startTime);
      $groups[$end] = max($groups[$end] ?? $extended, $extended);
      $writtenEnds[$index] = $end;
    }
    $result = [];
    foreach ($writtenEnds as $index => $end) {
      $result[$index] = $groups[$end];
    }
    return $result;
  }
}
