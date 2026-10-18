<?php

namespace MADSCORE\Score;

/** Groups consecutive note-to-note volume changes into score spans. */
final class Dynamics {

  /** Advance the Note panel's volume mark through louder, softer, and level. */
  public static function nextDirection(string $current): string {
    return match ($current) {
      'crescendo' => 'decrescendo',
      'decrescendo' => '',
      default => 'crescendo',
    };
  }

  public static function groups(Score $score): array {
    $incoming = [];
    foreach ($score->notes as $index => $note) {
      if (($note['dynamicToNext'] ?? '') !== '' && ($next = $score->nextMelodyNote($index)) !== null) {
        $incoming[$next] = $index;
      }
    }
    $groups = [];
    foreach ($score->notes as $index => $note) {
      $direction = $note['dynamicToNext'] ?? '';
      if ($direction === '') {
        continue;
      }
      $previous = $incoming[$index] ?? null;
      if ($previous !== null && ($score->notes[$previous]['dynamicToNext'] ?? '') === $direction) {
        continue;
      }
      $indices = [$index];
      $last = $index;
      while (($score->notes[$last]['dynamicToNext'] ?? '') === $direction
        && ($next = $score->nextMelodyNote($last)) !== null) {
        $indices[] = $next;
        $last = $next;
      }
      if (count($indices) < 2) {
        continue;
      }
      $endNote = $score->notes[$last];
      $groups[] = [
        'melody' => $note['melody'], 'direction' => $direction,
        'first' => $index, 'last' => $last, 'indices' => $indices,
        'start' => $score->start($note['bar']) + $note['slot'],
        'end' => $score->start($endNote['bar']) + $endNote['slot'] + $endNote['length'],
      ];
    }
    return $groups;
  }

}
