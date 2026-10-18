<?php

namespace MADSCORE\Score;

/** Converts written dynamic levels to an absolute percentage of instrument volume. */
final class DynamicLevel {

  private const LETTERS = ['ppp' => 15, 'pp' => 25, 'p' => 40, 'mp' => 55,
    'mf' => 70, 'f' => 85, 'ff' => 95, 'fff' => 100];

  public static function valid(string $mark): bool {
    return $mark === '' || isset(self::LETTERS[$mark])
      || (preg_match('/^(?:100|[1-9]?[0-9])$/D', $mark) === 1);
  }

  public static function percent(string $mark): int {
    if (!self::valid($mark) || $mark === '') {
      throw new \DomainException('Dynamic level must be ppp–fff or 0–100.');
    }
    return self::LETTERS[$mark] ?? (int)$mark;
  }

}
