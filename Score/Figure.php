<?php

namespace MADSCORE\Score;

/** One compact tuplet or a reference to a project motif. */
final class Figure {
  public static function parse(string $text, array $note): ?array {
    $text = strtoupper(trim($text));
    if ($text === '') {
      return null;
    }
    if (preg_match('/^([OT])[\s,;:_-]*([A-Z])$/D', $text, $match)) {
      return ['type' => $match[1] === 'O' ? 'ornament' : 'tuplet', 'motif' => $match[2]];
    }
    if (preg_match('/^([+-]\d{1,2})[\s,;:_]*([+-]\d{1,2})$/D', $text, $match)) {
      $first = 12 * $note['octave'] + Pitch::position($note);
      $middle = (int)$match[1];
      $last = (int)$match[2];
      if ($first + $middle >= 1 && $first + $middle <= 108
        && $first + $last >= 1 && $first + $last <= 108) {
        return ['type' => 'tuplet', 'middle' => $middle, 'last' => $last];
      }
    }
    throw new \DomainException('Figure must be OA–OZ, TA–TZ, or +middle+last semitone offsets.');
  }

  public static function format(?array $figure): string {
    if ($figure === null) {
      return '';
    }
    if (isset($figure['motif'])) {
      return ($figure['type'] === 'ornament' ? 'O' : 'T') . $figure['motif'];
    }
    return sprintf('%+d%+d', $figure['middle'], $figure['last']);
  }

  public static function validate(mixed $figure, array $note): ?array {
    if ($figure === null) {
      return null;
    }
    if (!is_array($figure) || !in_array($figure['type'] ?? null, ['ornament', 'tuplet'], true)) {
      throw new \DomainException('Invalid note figure.');
    }
    if (isset($figure['motif'])) {
      if (!is_string($figure['motif']) || !preg_match('/^[A-Z]$/D', $figure['motif'])) {
        throw new \DomainException('Invalid note figure.');
      }
    } else if ($figure['type'] !== 'tuplet' || !is_int($figure['middle'] ?? null)
      || !is_int($figure['last'] ?? null)) {
      throw new \DomainException('Invalid note figure.');
    }
    $parsed = self::parse(self::format($figure), $note);
    if ($parsed !== $figure) {
      throw new \DomainException('Invalid note figure.');
    }
    return $parsed;
  }
}
