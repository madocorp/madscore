<?php

namespace MADSCORE\Play;

use MADSCORE\Score\Score;

/** Maps written beats to elapsed playback time, including section repeats. */
final class PlaybackTimeline {

  public readonly array $passes;
  public readonly array $beats;
  public readonly float $durationSeconds;

  public function __construct(Score $score, ?int $start = null, ?int $end = null) {
    $whole = $end === null;
    $scoreEnd = $score->start(count($score->bars));
    $start ??= 0;
    $end ??= $scoreEnd;
    if ($start < 0 || $end > $scoreEnd || $start >= $end) {
      throw new \InvalidArgumentException('Invalid playback range.');
    }
    $passes = [];
    if ($whole) {
      $leaders = [];
      foreach ($score->bars as $bar => $settings) {
        if ($settings['sectionLeader']) {
          $leaders[] = $bar;
        }
      }
      $leaders[] = count($score->bars);
      for ($section = 0; $section < count($leaders) - 1; $section++) {
        $first = $leaders[$section];
        $last = $leaders[$section + 1];
        for ($pass = 0; $pass <= $score->bars[$first]['repeats']; $pass++) {
          $passes[] = [$score->start($first), $score->start($last), $pass < $score->bars[$first]['repeats']];
        }
      }
    } else {
      $passes[] = [$start, $end, true];
    }
    if ($whole && $start > 0) {
      foreach ($passes as $index => [$from, $to]) {
        if ($start < $to) {
          $passes = array_slice($passes, $index);
          $passes[0][0] = $start;
          break;
        }
      }
    }
    $this->passes = $passes;
    $time = 0.0;
    $beats = [];
    foreach ($passes as [$from, $to]) {
      for ($bar = 0; $bar < count($score->bars); $bar++) {
        $barStart = $score->start($bar);
        $barEnd = $score->start($bar + 1);
        if ($barEnd <= $from || $barStart >= $to) {
          continue;
        }
        $secondsPerSlot = 30.0 / (float)$score->bars[$bar]['tempo'];
        for ($slot = max($from, $barStart); $slot < min($to, $barEnd); $slot = $next) {
          $next = min($to, $barEnd, $barStart + (intdiv($slot - $barStart, 2) + 1) * 2);
          $beats[] = ['time' => $time, 'bar' => $bar, 'slot' => intdiv($slot - $barStart, 2),
            'length' => $next - $slot];
          $time += ($next - $slot) * $secondsPerSlot;
        }
      }
    }
    $this->beats = $beats;
    $this->durationSeconds = $time;
  }

  /** Find the most recently started beat at the audio player's clock. */
  public function beatAt(float $seconds): int {
    $low = 0;
    $high = count($this->beats);
    while ($low < $high) {
      $middle = intdiv($low + $high, 2);
      if ($this->beats[$middle]['time'] <= $seconds) {
        $low = $middle + 1;
      } else {
        $high = $middle;
      }
    }
    return max(0, $low - 1);
  }
}
