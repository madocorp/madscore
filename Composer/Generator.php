<?php

namespace MADSCORE\Composer;

use MADSCORE\Play\Drum;
use MADSCORE\Score\Pitch;
use MADSCORE\Score\Score;

/** Add independent, muted countermelodies against the audible pitched parts. */
final class Generator {

  public static function generate(Score $score, int $start, int $end, int $versions = 3,
    string $density = 'balanced', string $register = 'middle'): Score {
    $scoreEnd = $score->start(count($score->bars));
    if ($start < 0 || $start >= $end || $end > $scoreEnd || $versions < 1 || $versions > 5
      || !in_array($density, ['sparse', 'balanced', 'busy'], true)
      || !in_array($register, ['low', 'middle', 'high'], true)) {
      throw new \DomainException('Invalid composer settings or time range.');
    }
    $sources = [];
    foreach ($score->notes as $note) {
      if ($score->melodyMuted($note['melody'])
        || in_array($score->melodies[$note['melody']]['pattern'], Drum::NAMES, true)) {
        continue;
      }
      $time = $score->start($note['bar']) + $note['slot'];
      if ($time < $end && $time + $note['length'] > $start) {
        $sources[] = ['start' => $time, 'end' => $time + $note['length'],
          'pitch' => 12 * $note['octave'] + Pitch::position($note)];
      }
    }
    if ($sources === []) {
      throw new \DomainException('Composer needs an unmuted pitched melody in this span.');
    }
    $pitches = array_column($sources, 'pitch');
    sort($pitches);
    $middle = $pitches[intdiv(count($pitches), 2)];
    $middle += ['low' => -12, 'middle' => 0, 'high' => 12][$register];
    $middle = max(8, min(100, $middle));
    $histogram = array_fill(0, 12, 0);
    foreach ($sources as $source) {
      $histogram[$source['pitch'] % 12] += min($end, $source['end']) - max($start, $source['start']);
    }
    [$root, $minor] = self::key($histogram);
    $next = Score::fromData($score->data());
    $seen = [];
    for ($version = 0; $version < $versions; $version++) {
      $notes = [];
      for ($attempt = 0; $attempt < 100; $attempt++) {
        $notes = self::compose($score, $sources, $start, $end, $middle, $root, $minor,
          $density, $version * 23 + $attempt);
        $signature = json_encode($notes);
        if (!isset($seen[$signature])) {
          $seen[$signature] = true;
          break;
        }
      }
      if ($notes === [] || $attempt === 100) {
        throw new \DomainException('Could not create distinct versions in this span.');
      }
      $melody = $next->addMelody();
      foreach ($notes as [$time, $length, $pitch]) {
        [$bar, $slot] = self::barSlot($score, $time);
        $next->place($melody, $bar, $slot, intdiv($pitch - 1, 12), ($pitch - 1) % 12 + 1, $length);
      }
      $next->setMelodyMuted($melody, true);
    }
    return $next;
  }

  private static function key(array $histogram): array {
    $best = [-INF, 0, false];
    foreach ([false, true] as $minor) {
      $scale = $minor ? [0, 2, 3, 5, 7, 8, 10] : [0, 2, 4, 5, 7, 9, 11];
      foreach (range(0, 11) as $root) {
        $score = 0;
        foreach ($histogram as $class => $weight) {
          $degree = ($class - $root + 12) % 12;
          $score += $weight * (in_array($degree, $scale, true) ? 1 : -1);
          if ($degree === 0 || $degree === 7) {
            $score += $weight * 0.15;
          }
        }
        if ($score > $best[0]) {
          $best = [$score, $root, $minor];
        }
      }
    }
    return [$best[1], $best[2]];
  }

  private static function compose(Score $score, array $sources, int $start, int $end, int $middle,
    int $root, bool $minor, string $density, int $variant): array {
    $scale = $minor ? [0, 2, 3, 5, 7, 8, 10] : [0, 2, 4, 5, 7, 9, 11];
    $stride = ['sparse' => 4, 'balanced' => 2, 'busy' => 1][$density];
    $state = (int)sprintf('%u', crc32(json_encode([$score->notes, $start, $end, $variant, $density, $middle])));
    $roll = static function() use (&$state): float {
      $state = (int)(($state * 1664525 + 1013904223) % 4294967296);
      return $state / 4294967296;
    };
    $notes = [];
    $previous = null;
    for ($time = $start; $time < $end;) {
      [$bar, $slot] = self::barSlot($score, $time);
      $beat = $slot % 2 === 0;
      $length = min($end - $time, $stride);
      if ($density === 'busy' && $beat && $roll() < 0.46) {
        $length = min($end - $time, 2);
      }
      if ($density === 'sparse' && $notes !== [] && $roll() < 0.23) {
        $time += $length;
        continue;
      }
      if ($density === 'balanced' && $notes !== [] && $roll() < 0.13) {
        $time += $length;
        continue;
      }
      $sounding = [];
      foreach ($sources as $source) {
        if ($source['start'] <= $time && $source['end'] > $time) {
          $sounding[] = $source['pitch'];
        }
      }
      $bestPitch = null;
      $bestScore = -INF;
      for ($pitch = max(1, $middle - 9); $pitch <= min(108, $middle + 9); $pitch++) {
        $degree = (($pitch % 12) - $root + 12) % 12;
        if (!in_array($degree, $scale, true)) {
          continue;
        }
        if (count($sounding) === 1
          && in_array(abs($pitch - $sounding[0]) % 12, [1, 2, 6, 10, 11], true)) {
          continue;
        }
        $value = -abs($pitch - $middle) * 0.12 + ($beat ? 0.25 : 0);
        if ($previous !== null) {
          $leap = abs($pitch - $previous);
          $value -= $leap <= 2 ? 0 : ($leap <= 5 ? 0.55 : 2 + ($leap - 5) * 0.5);
          if ($pitch === $previous) {
            $value -= 0.5;
          }
        }
        foreach ($sounding as $other) {
          $interval = abs($pitch - $other) % 12;
          $value += match ($interval) {
            0, 3, 4, 7, 8, 9 => 1.1,
            5 => 0.35,
            1, 2, 6, 10, 11 => -1.5,
            default => 0,
          };
          if ($pitch === $other) {
            $value -= 1.3;
          }
        }
        if ($time + $length === $end && $degree === 0) {
          $value += 2;
        }
        $value += $roll() * 4.5;
        if ($value > $bestScore) {
          $bestScore = $value;
          $bestPitch = $pitch;
        }
      }
      if ($bestPitch !== null) {
        $notes[] = [$time, $length, $bestPitch];
        $previous = $bestPitch;
      }
      $time += $length;
    }
    return $notes;
  }

  private static function barSlot(Score $score, int $time): array {
    for ($bar = 0; $bar < count($score->bars); $bar++) {
      $barStart = $score->start($bar);
      if ($time < $barStart + 2 * $score->beats($bar)) {
        return [$bar, $time - $barStart];
      }
    }
    throw new \DomainException('Composer position is outside the score.');
  }
}
