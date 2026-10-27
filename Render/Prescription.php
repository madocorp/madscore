<?php

namespace MADSCORE\Render;

use MADSCORE\Score\MelodyRange;
use MADSCORE\Score\Pitch;
use MADSCORE\Score\Score;

/** Chooses readable octave bands and draws their identifying symbols. */
final class Prescription {

  public const REFERENCE_BAND = Pitch::MIDDLE_C_BAND;
  private const NORMAL_PRINT_ROW_HEIGHT = 220;

  /** Return the active prescription for every bar. */
  public static function states(Score $score, ?int $previewStart = null, ?int $previewEnd = null,
    ?int $previewBand = null, ?FigureCatalog $figures = null): array {
    $occupied = [];
    $counts = array_fill(0, 9, 0);
    $melodyRows = self::melodyRows($score);
    $barCount = count($score->bars);
    while ($previewEnd !== null && $score->start($barCount) < $previewEnd) {
      $barCount++;
    }
    for ($bar = 0; $bar < $barCount; $bar++) {
      $occupied[$bar] = self::soundingBands($score, $bar, $melodyRows, $figures);
      if ($previewStart !== null && $previewEnd !== null && $previewBand !== null
        && $score->start($bar) < $previewEnd && $score->start($bar) + 2 * $score->beats($bar) > $previewStart
        && !in_array($previewBand, $occupied[$bar], true)) {
        $occupied[$bar][] = $previewBand;
      }
    }
    foreach ($score->notes as $note) {
      $counts[self::printedBand($note, $melodyRows)]++;
    }
    $primary = self::bestBand(range(0, 8), $counts);
    $selected = [];
    $states = [];
    for ($bar = 0; $bar < $barCount; $bar++) {
      $required = $occupied[$bar];
      if ($selected === [] || array_diff($required, $selected) !== [] || (count($selected) > 4 && count($required) <= 4)) {
        $future = array_fill(0, 9, 0);
        foreach ($score->notes as $note) {
          if ($note['bar'] >= $bar) {
            $future[self::printedBand($note, $melodyRows)]++;
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
  public static function soundingBands(Score $score, int $bar, ?array $melodyRows = null,
    ?FigureCatalog $figures = null): array {
    $start = $score->start($bar);
    $end = $start + 2 * $score->beats($bar);
    $bands = [];
    $melodyRows ??= [];
    foreach ($score->notes as $note) {
      $noteStart = $score->start($note['bar']) + $note['slot'];
      if ($noteStart < $end && $noteStart + $note['length'] > $start) {
        if (Pitch::position($note) === 12 && !isset($melodyRows[$note['melody']])) {
          $melodyRows[$note['melody']] = MelodyRange::bands($score, $note['melody']);
        }
        $bands[self::printedBand($note, $melodyRows)] = true;
        $figure = $note['figure'] ?? null;
        if (($figure['type'] ?? null) === 'tuplet') {
          $firstPitch = 12 * $note['octave'] + Pitch::position($note);
          $offsets = isset($figure['last']) ? [$figure['middle'], $figure['last']]
            : array_column($figures?->reference($figure)['notes'] ?? [], 'offset');
          foreach ($offsets as $offset) {
            $bands[max(0, min(8, intdiv($firstPitch + $offset - 1, 12)))] = true;
          }
        }
      }
    }
    foreach ($score->notes as $index => $note) {
      if (!($note['glissandoToNext'] ?? false) || ($next = $score->nextAdjacent($index)) === null) {
        continue;
      }
      $target = $score->notes[$next];
      $glideStart = $score->start($note['bar']) + $note['slot'];
      $glideEnd = $score->start($target['bar']) + $target['slot'] + $target['length'];
      if ($glideStart >= $end || $glideEnd <= $start) {
        continue;
      }
      $sourcePitch = 12 * $note['octave'] + Pitch::position($note);
      $targetPitch = 12 * $target['octave'] + Pitch::position($target);
      $firstPitch = $sourcePitch + ($targetPitch - $sourcePitch) * (max($start, $glideStart) - $glideStart) / ($glideEnd - $glideStart);
      $lastPitch = $sourcePitch + ($targetPitch - $sourcePitch) * (min($end, $glideEnd) - $glideStart) / ($glideEnd - $glideStart);
      for ($band = max(0, (int)floor((min($firstPitch, $lastPitch) - 1) / 12));
        $band <= min(8, (int)floor((max($firstPitch, $lastPitch) - 1) / 12)); $band++) {
        $bands[$band] = true;
      }
    }
    return array_map('intval', array_keys($bands));
  }

  /** Use the bottom displayed staff for a melody's lower overflow G-sharp. */
  private static function printedBand(array $note, array $melodyRows): int {
    if (Pitch::position($note) !== 12) {
      return $note['octave'];
    }
    $rows = $melodyRows[$note['melody']];
    return !in_array($note['octave'], $rows, true)
      && in_array($note['octave'] + 1, $rows, true) ? $note['octave'] + 1 : $note['octave'];
  }

  private static function melodyRows(Score $score): array {
    $rows = [];
    foreach ($score->notes as $note) {
      if (Pitch::position($note) === 12 && !isset($rows[$note['melody']])) {
        $rows[$note['melody']] = MelodyRange::bands($score, $note['melody']);
      }
    }
    return $rows;
  }

  /** Whether this octave staff remains visible in the next bar. */
  public static function bandContinues(Score $score, array $states, int $bar, int $band,
    ?array $cursor = null, ?FigureCatalog $figures = null): bool {
    $next = $bar + 1;
    if (!isset($states[$next]) || $states[$next]['change']) {
      return false;
    }
    if ($band === $states[$next]['primary'] || in_array($band, self::soundingBands($score, $next,
      figures: $figures), true)) {
      return true;
    }
    $start = $score->start($next);
    return $cursor !== null && $cursor['octave'] === $band
      && $cursor['start'] < $start + 2 * $score->beats($next) && $cursor['end'] > $start;
  }

  /** Map selected bands to readable rows, with small gaps between groups. */
  public static function rows(array $state, int $height, int $topSpace, int $minimumRows = 4): array {
    $bands = $state['bands'];
    rsort($bands, SORT_NUMERIC);
    $breaks = 0;
    for ($index = 1; $index < count($bands); $index++) {
      if ($bands[$index - 1] - $bands[$index] > 1) {
        $breaks++;
      }
    }
    $gap = max(2, intdiv($height, 40));
    $rowHeight = max(1, intdiv(max(4, $height - $topSpace - $breaks * $gap), max($minimumRows, count($bands))));
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
  public static function drawMarkers(\FFI $cairo, \FFI\CData $context, array $rows,
    float $x, bool $print = false): void {
    $cairo->cairo_set_source_rgb($context, 0, 0, 0);
    foreach ($rows as $band => $row) {
      $step = $row['height'] / 12;
      $middle = $row['top'] + 5 * $step;
      $size = self::markerSize($row['height'], $print);
      if ($band === self::REFERENCE_BAND) {
        $cairo->cairo_arc($context, $x, $middle, $size / 2, 0, 2 * M_PI);
        $cairo->cairo_fill($context);
        continue;
      }
      $count = abs($band - self::REFERENCE_BAND);
      $up = $band > self::REFERENCE_BAND;
      for ($index = 0; $index < $count; $index++) {
        $y = $middle + ($index - ($count - 1) / 2) * $size * 1.1;
        $halfWidth = $size / sqrt(3);
        $cairo->cairo_move_to($context, $x, $y + ($up ? -$size / 2 : $size / 2));
        $cairo->cairo_line_to($context, $x - $halfWidth, $y + ($up ? $size / 2 : -$size / 2));
        $cairo->cairo_line_to($context, $x + $halfWidth, $y + ($up ? $size / 2 : -$size / 2));
        $cairo->cairo_close_path($context);
        $cairo->cairo_fill($context);
      }
    }
  }

  /** At small print sizes, keep octave symbols legible without exceeding a note head. */
  public static function markerSize(float $rowHeight, bool $print = false): float {
    $halfNoteSize = 2 * self::dotRadius($rowHeight);
    return $print ? min($rowHeight / 6,
      max(self::NORMAL_PRINT_ROW_HEIGHT / 12, $halfNoteSize)) : $halfNoteSize;
  }

  /** Match the filled octave prescription dot at this staff height. */
  public static function dotRadius(float $rowHeight): float {
    return max(7, $rowHeight / 12) / 2;
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
    usort($bands, static fn (int $a, int $b): int => ($counts[$b] <=> $counts[$a])
      ?: (abs($a - self::REFERENCE_BAND) <=> abs($b - self::REFERENCE_BAND)) ?: ($a <=> $b));
    return $bands[0];
  }

  private static function chooseBands(array $required, array $previous, int $primary, array $future): array {
    if (count($required) > 4) {
      rsort($required, SORT_NUMERIC);
      return $required;
    }
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
            $distance = array_sum(array_map(static fn (int $band): int => abs($band - self::REFERENCE_BAND), $candidate));
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
