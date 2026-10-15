<?php

namespace MADSCORE;

/** Converts written notes to chromatic positions and back. */
final class Pitch {

  private const NATURAL = ['A' => 1, 'B' => 3, 'C' => 4, 'D' => 6, 'E' => 8, 'F' => 9, 'G' => 11];
  private const SPELLING = [
    ['G', 1], ['A', 0], ['A', 1], ['B', 0],
    ['C', 0], ['C', 1], ['D', 0], ['D', 1],
    ['E', 0], ['F', 0], ['F', 1], ['G', 0],
  ];
  private const BLACK_KEYS = [0, 2, 5, 7, 10];

  /** Return the semitone position of a written note within its octave band. */
  public static function position(array $note): int {
    return self::NATURAL[$note['pitch']] + $note['alter'];
  }

  /** Return a natural note's position within an octave band. */
  public static function naturalPosition(string $pitch): int {
    return self::NATURAL[$pitch];
  }

  /** Move a position by one semitone across the nine A-to-G-sharp bands. */
  public static function step(int $octave, int $position, int $direction): array {
    if ($direction !== -1 && $direction !== 1) {
      throw new \InvalidArgumentException('Pitch direction must be up or down.');
    }
    $global = $octave * 12 + $position + $direction;
    if ($global < 1 || $global > 108) {
      throw new \DomainException('This pitch is outside the nine octave bands.');
    }
    if ($global % 12 === 0) {
      return [intdiv($global, 12) - 1, 12];
    }
    return [intdiv($global, 12), $global % 12];
  }

  /** Return one consistent spelling for a position inside an octave band. */
  public static function spelling(int $position): array {
    if ($position === 12) {
      return ['G', 1];
    }
    return self::SPELLING[$position];
  }

  /** Return whether a chromatic position is a black piano key. */
  public static function isBlack(int $position): bool {
    return in_array($position % 12, self::BLACK_KEYS, true);
  }
}
