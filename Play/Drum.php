<?php

namespace MADSCORE\Play;

/** Generates short pitch-independent rhythm sounds from note onsets. */
final class Drum {

  public const NAMES = ['kick', 'snare', 'clap', 'hat', 'open_hat'];

  /** Return the fixed length of a rhythm hit in seconds. */
  public static function duration(string $name): float {
    return match ($name) {
      'kick' => 0.32,
      'snare' => 0.24,
      'clap' => 0.24,
      'hat' => 0.12,
      'open_hat' => 0.55,
      default => throw new \InvalidArgumentException('Unknown rhythm sound.'),
    };
  }

  /** Produce one normalized sample at the given age in frames. */
  public static function sample(string $name, int $age, int $sampleRate): float {
    $time = $age / $sampleRate;
    $attack = min(1.0, $age / max(1, (int)($sampleRate * 0.001)));
    $tail = min(1.0, max(0.0, (self::duration($name) - $time) / 0.008));
    if ($name === 'kick') {
      $phase = 2 * M_PI * (62 * $time + 100 * 0.025 * (1 - exp(-$time / 0.025)));
      $body = 0.75 * exp(-$time / 0.095) * sin($phase)
        + 0.46 * exp(-$time / 0.065) * sin(2 * $phase)
        + 0.24 * exp(-$time / 0.045) * sin(3 * $phase);
      $click = 0.38 * exp(-$time / 0.007) * self::noise($age);
      return 2.1 * $attack * $tail * ($body + $click);
    }
    $noise = self::noise($age);
    if ($name === 'snare') {
      $body = 0.55 * sin(2 * M_PI * 185 * $time) + 0.22 * sin(2 * M_PI * 330 * $time);
      return $attack * $tail * (exp(-$time / 0.055) * $body + 0.75 * exp(-$time / 0.075) * $noise);
    }
    if ($name === 'clap') {
      $bursts = 0.0;
      foreach ([0.0, 0.013, 0.029] as $delay) {
        if ($time >= $delay) {
          $bursts += exp(-($time - $delay) / 0.006);
        }
      }
      $hiss = 0.28 * exp(-$time / 0.07);
      return $attack * $tail * min(1.0, $bursts + $hiss) * (0.8 * $noise + 0.2 * ($noise - self::noise($age - 1)));
    }
    if ($name === 'hat' || $name === 'open_hat') {
      $decay = $name === 'hat' ? 0.038 : 0.19;
      $metal = sin(2 * M_PI * 3911 * $time) + sin(2 * M_PI * 5213 * $time)
        + sin(2 * M_PI * 6749 * $time);
      $bright = 0.5 * ($noise - 0.75 * self::noise($age - 1)) + 0.13 * $metal;
      return $attack * $tail * exp(-$time / $decay) * $bright;
    }
    throw new \InvalidArgumentException('Unknown rhythm sound.');
  }

  /** Create repeatable broadband noise without storing a sample buffer. */
  private static function noise(int $frame): float {
    $value = (($frame + 1) * 1103515245 + 12345) & 0x7fffffff;
    $value ^= $value << 13;
    $value ^= $value >> 17;
    $value ^= $value << 5;
    return ((($value >> 16) & 65535) / 32767.5) - 1;
  }

}
