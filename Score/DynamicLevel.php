<?php

namespace MADSCORE\Score;

/** Converts written dynamic levels to a percentage of melody volume. */
final class DynamicLevel {

  private const LETTERS = ['ppp' => 20, 'pp' => 30, 'p' => 40, 'mp' => 50,
    'mf' => 60, 'f' => 75, 'ff' => 90, 'fff' => 100];
  private const MAX_STEP_DB = 4.0;

  public static function marks(): array {
    return array_keys(self::LETTERS);
  }

  /** Cycle the button through written levels, with one blank step to clear it. */
  public static function next(string $mark): string {
    $marks = ['', ...array_keys(self::LETTERS)];
    $index = array_search($mark, $marks, true);
    return $marks[$index === false ? 0 : ($index + 1) % count($marks)];
  }

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

  /** An unmarked note sits halfway between mezzo-piano and mezzo-forte. */
  public static function baseline(array $levels): float {
    return ($levels['mp'] + $levels['mf']) / 2;
  }

  /** Fit the levels used in a score to an audible range without exceeding 20–100%. */
  public static function playbackLevels(Score $score): array {
    $used = [];
    foreach ($score->notes as $note) {
      $mark = $note['dynamicLevel'] ?? '';
      if ($mark !== '' && !isset(self::LETTERS[$mark])) {
        return self::defaultLevels();
      }
      if ($mark !== '') {
        $used[$mark] = true;
      }
    }
    $sharedByTime = [];
    foreach ($score->scoreDynamics as $mark) {
      $sharedByTime[$mark['time']] = $mark;
      if ($mark['level'] !== '') {
        $used[$mark['level']] = true;
      }
    }
    foreach (Dynamics::groups($score) as $group) {
      if (($score->notes[$group['last']]['dynamicLevel'] ?? '') === '') {
        $used[$group['direction'] === 'crescendo' ? 'fff' : 'ppp'] = true;
      }
    }
    foreach ($score->scoreDynamics as $mark) {
      if ($mark['direction'] !== '' && ($sharedByTime[$mark['end']]['level'] ?? '') === '') {
        $used[$mark['direction'] === 'crescendo' ? 'fff' : 'ppp'] = true;
      }
    }
    $marks = array_keys(self::LETTERS);
    $ranks = [];
    foreach ($marks as $rank => $mark) {
      if (isset($used[$mark])) {
        $ranks[] = $rank;
      }
    }
    if (count($ranks) < 2) {
      return self::defaultLevels();
    }
    $first = min($ranks);
    $last = max($ranks);
    $stepDb = min(self::MAX_STEP_DB, 20 * log10(5) / ($last - $first));
    $levels = [];
    foreach ($marks as $rank => $mark) {
      $levels[$mark] = pow(10, -($last - $rank) * $stepDb / 20);
    }
    return $levels;
  }

  /** Keep the original mapping for scores with one level or legacy numeric levels. */
  private static function defaultLevels(): array {
    return array_map(static fn (int $percent): float => $percent / 100, self::LETTERS);
  }

}
