<?php

namespace MADSCORE;

/** Defines bounded harmonic recipes for the score's melody voices. */
final class Waveform {

  public const NAMES = ['organ', 'sine', 'triangle', 'square', 'sawtooth', 'kick', 'snare', 'clap', 'hat', 'open_hat'];
  private const MAX_HARMONIC = 9;

  /** Return normalized frequency multiples and gains below the audio limit. */
  public static function partials(string $name, float $frequency, int $sampleRate): array {
    if (!in_array($name, self::NAMES, true) || in_array($name, Drum::NAMES, true)) {
      throw new \InvalidArgumentException('Unknown melody pattern.');
    }
    if (!is_finite($frequency) || $frequency <= 0 || $sampleRate < 1 || $frequency >= $sampleRate / 2) {
      throw new \InvalidArgumentException('Pattern frequency is outside the audio range.');
    }
    $partials = [];
    $absoluteGain = 0.0;
    for ($multiple = 1; $multiple <= self::MAX_HARMONIC && $multiple * $frequency < $sampleRate / 2; $multiple++) {
      $gain = match ($name) {
        'sine' => $multiple === 1 ? 1.0 : 0.0,
        'organ' => match ($multiple) {
          1 => 1.0, 2 => 0.55, 3 => 0.30, default => 0.0,
        },
        'triangle' => $multiple % 2 === 1 ? (($multiple % 4 === 1 ? 1 : -1) / ($multiple * $multiple)) : 0.0,
        'square' => $multiple % 2 === 1 ? 1.0 / $multiple : 0.0,
        'sawtooth' => ($multiple % 2 === 1 ? 1 : -1) / $multiple,
      };
      if ($gain === 0.0) {
        continue;
      }
      $partials[] = [$multiple, $gain];
      $absoluteGain += abs($gain);
    }
    return array_map(static fn (array $partial): array => [$partial[0], $partial[1] / $absoluteGain], $partials);
  }

}
