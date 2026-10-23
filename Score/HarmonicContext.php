<?php

namespace MADSCORE\Score;

/** A diatonic scale and its home note, shared by a motif or score section. */
final class HarmonicContext {

  public static function validate(mixed $music): array {
    if (!is_array($music) || array_keys($music) !== ['scale', 'homeNote']
      || !is_string($music['scale']) || !is_string($music['homeNote'])
      || !isset(MotifScoreGenerator::SCALES[$music['scale'] ?? ''])
      || !isset(MotifScoreGenerator::HOME_NOTES[$music['homeNote'] ?? ''])) {
      throw new \DomainException('Choose a valid scale and home note.');
    }
    return $music;
  }
}
