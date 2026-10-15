<?php

namespace MADSCORE;

/** Chooses the four octave bands and draws their identifying symbols. */
final class Prescription {

  /** Return the active prescription for every bar. */
  public static function states(Score $score, ?int $previewStart = null, ?int $previewEnd = null, ?int $previewBand = null): array {
    $occupied = [];
    $counts = array_fill(0, 9, 0);
    $barCount = count($score->bars);
    while ($previewEnd !== null && $score->start($barCount) < $previewEnd) {
      $barCount++;
    }
    for ($bar = 0; $bar < $barCount; $bar++) {
      $occupied[$bar] = self::soundingBands($score, $bar);
      if ($previewStart !== null && $previewEnd !== null && $previewBand !== null
        && $score->start($bar) < $previewEnd && $score->start($bar) + 2 * $score->beats($bar) > $previewStart
        && !in_array($previewBand, $occupied[$bar], true) && count($occupied[$bar]) < 4) {
        $occupied[$bar][] = $previewBand;
      }
    }
    foreach ($score->notes as $note) {
      $counts[$note['octave']]++;
    }
    $primary = self::bestBand(range(0, 8), $counts);
    $selected = [];
    $states = [];
    for ($bar = 0; $bar < $barCount; $bar++) {
      $required = $occupied[$bar];
      if ($selected === [] || array_diff($required, $selected) !== []) {
        $future = array_fill(0, 9, 0);
        foreach ($score->notes as $note) {
          if ($note['bar'] >= $bar) {
            $future[$note['octave']]++;
          }
        }
        $selected = self::chooseBands($required, $selected, $primary, $future);
        if (!in_array($primary, $selected, true)) {
          $primary = self::bestBand($selected, $future);
        }
        $changed = true;
      } else {
        $changed = false;
      }
      $states[$bar] = ['bands' => $selected, 'primary' => $primary, 'change' => $changed];
    }
    return $states;
  }

  /** The octave bands that contain sounding notes anywhere in a bar. */
  public static function soundingBands(Score $score, int $bar): array {
    $start = $score->start($bar);
    $end = $start + 2 * $score->beats($bar);
    $bands = [];
    foreach ($score->notes as $note) {
      $noteStart = $score->start($note['bar']) + $note['slot'];
      if ($noteStart < $end && $noteStart + $note['length'] > $start) {
        $bands[$note['octave']] = true;
      }
    }
    return array_map('intval', array_keys($bands));
  }

  /** Whether this octave staff remains visible in the next bar. */
  public static function bandContinues(Score $score, array $states, int $bar, int $band, ?array $cursor = null): bool {
    $next = $bar + 1;
    if (!isset($states[$next]) || $states[$next]['change']) {
      return false;
    }
    if ($band === $states[$next]['primary'] || in_array($band, self::soundingBands($score, $next), true)) {
      return true;
    }
    $start = $score->start($next);
    return $cursor !== null && $cursor['octave'] === $band
      && $cursor['start'] < $start + 2 * $score->beats($next) && $cursor['end'] > $start;
  }

  /** Map selected bands to four readable rows, with small gaps between groups. */
  public static function rows(array $state, int $height, int $topSpace): array {
    $bands = $state['bands'];
    rsort($bands, SORT_NUMERIC);
    $breaks = 0;
    for ($index = 1; $index < count($bands); $index++) {
      if ($bands[$index - 1] - $bands[$index] > 1) {
        $breaks++;
      }
    }
    $gap = max(2, intdiv($height, 40));
    $rowHeight = max(1, intdiv(max(4, $height - $topSpace - $breaks * $gap), 4));
    $top = $topSpace;
    $rows = [];
    foreach ($bands as $index => $band) {
      if ($index > 0 && $bands[$index - 1] - $band > 1) {
        $top += $gap;
      }
      $rows[$band] = ['top' => $top, 'height' => $rowHeight];
      $top += $rowHeight;
    }
    return $rows;
  }

  /** Draw octave symbols on the middle staff line at a prescription boundary. */
  public static function drawMarkers(\FFI $cairo, \FFI\CData $context, array $rows, float $x): void {
    $cairo->cairo_set_source_rgb($context, 0, 0, 0);
    foreach ($rows as $band => $row) {
      $step = $row['height'] / 12;
      $middle = $row['top'] + 5 * $step;
      $size = max(2, $step / 2);
      if ($band === 4) {
        $cairo->cairo_arc($context, $x, $middle, $size / 2, 0, 2 * M_PI);
        $cairo->cairo_fill($context);
        continue;
      }
      $count = abs($band - 4);
      $up = $band > 4;
      for ($index = 0; $index < $count; $index++) {
        $y = $middle + ($index - ($count - 1) / 2) * $size;
        $halfWidth = $size / sqrt(3);
        $cairo->cairo_move_to($context, $x, $y + ($up ? -$size / 2 : $size / 2));
        $cairo->cairo_line_to($context, $x - $halfWidth, $y + ($up ? $size / 2 : -$size / 2));
        $cairo->cairo_line_to($context, $x + $halfWidth, $y + ($up ? $size / 2 : -$size / 2));
        $cairo->cairo_close_path($context);
        $cairo->cairo_fill($context);
      }
    }
  }

  /** Place bars after a marker at the start of a line and at each change. */
  public static function positions(array $bars, array $widths, array $states, int $markerWidth): array {
    $positions = [];
    $x = 0;
    foreach ($bars as $index => $bar) {
      $marker = $index === 0 || $states[$bar]['change'];
      $markerLeft = $marker ? $x : null;
      if ($marker) {
        $x += $markerWidth;
      }
      $positions[] = ['bar' => $bar, 'left' => $x, 'right' => $x + $widths[$index], 'markerLeft' => $markerLeft];
      $x += $widths[$index];
    }
    return $positions;
  }

  private static function bestBand(array $bands, array $counts): int {
    usort($bands, static fn (int $a, int $b): int => ($counts[$b] <=> $counts[$a]) ?: (abs($a - 4) <=> abs($b - 4)) ?: ($a <=> $b));
    return $bands[0];
  }

  private static function chooseBands(array $required, array $previous, int $primary, array $future): array {
    $best = [];
    $bestKey = null;
    for ($a = 0; $a <= 5; $a++) {
      for ($b = $a + 1; $b <= 6; $b++) {
        for ($c = $b + 1; $c <= 7; $c++) {
          for ($d = $c + 1; $d <= 8; $d++) {
            $candidate = [$a, $b, $c, $d];
            if (array_diff($required, $candidate) !== []) {
              continue;
            }
            $groups = 1 + (int)($b !== $a + 1) + (int)($c !== $b + 1) + (int)($d !== $c + 1);
            $retained = count(array_intersect($candidate, $previous));
            $weight = array_sum(array_map(static fn (int $band): int => $future[$band], $candidate));
            $distance = array_sum(array_map(static fn (int $band): int => abs($band - 4), $candidate));
            $key = [(int)in_array($primary, $candidate, true), -$groups, $retained, $weight, -$distance, -$a];
            if ($bestKey === null || $key > $bestKey) {
              $bestKey = $key;
              $best = $candidate;
            }
          }
        }
      }
    }
    rsort($best, SORT_NUMERIC);
    return $best;
  }
}
