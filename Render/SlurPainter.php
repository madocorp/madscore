<?php

namespace MADSCORE\Render;

use MADSCORE\Score\Pitch;
use MADSCORE\Score\Score;

/** Draws one tapered curve for each continuous group of linked notes. */
final class SlurPainter {

  /** @param callable(int): array $rowsForBar */
  public static function draw(\FFI $cairo, \FFI\CData $context, Score $score, array $positions, callable $rowsForBar): void {
    if ($positions === []) {
      return;
    }
    $byBar = [];
    foreach ($positions as $position) {
      $byBar[$position['bar']] = $position;
    }
    $firstBar = min(array_keys($byBar));
    $lastBar = max(array_keys($byBar));
    $lineStart = $score->start($firstBar);
    $lineEnd = $score->start($lastBar) + 2 * $score->beats($lastBar);
    foreach (self::groups($score) as $indices) {
      $firstIndex = $indices[0];
      $lastIndex = $indices[count($indices) - 1];
      $first = $score->notes[$firstIndex];
      $last = $score->notes[$lastIndex];
      $groupStart = $score->start($first['bar']) + $first['slot'];
      $groupEnd = $score->start($last['bar']) + $last['slot'] + $last['length'];
      if ($groupEnd <= $lineStart || $groupStart >= $lineEnd) {
        continue;
      }
      $visible = [];
      foreach ($indices as $index) {
        $note = $score->notes[$index];
        if (isset($byBar[$note['bar']]) && ($row = self::noteRow($rowsForBar($note['bar']), $note)) !== null) {
          $visible[] = [$note, $row];
        }
      }
      $firstRow = $visible[0][1] ?? self::noteRow($rowsForBar($firstBar), $first);
      $lastRow = $visible[count($visible) - 1][1] ?? self::noteRow($rowsForBar($lastBar), $last);
      if ($firstRow === null && $lastRow === null) {
        continue;
      }
      $firstRow ??= $lastRow;
      $lastRow ??= $firstRow;
      $step = min($firstRow['height'] ?? $lastRow['height'], $lastRow['height'] ?? $firstRow['height']) / 12;
      $radius = max(0.25, $step - max(1, $step / 4) / 2);
      $up = Pitch::position($first) >= Pitch::naturalPosition('D');
      $side = $up ? -1 : 1;
      $left = !isset($byBar[$first['bar']]) ? $byBar[$firstBar]['left'] + 4
        : self::leftEdge($score, $byBar[$first['bar']], $first, $radius);
      $right = !isset($byBar[$last['bar']]) ? $byBar[$lastBar]['right'] - 4
        : self::rightEdge($score, $byBar, $last, $radius, $lastBar);
      if ($right <= $left + 2) {
        continue;
      }
      $sourceY = self::headY(isset($byBar[$first['bar']]) ? $first : ($visible[0][0] ?? $last), $firstRow);
      $targetY = self::headY(isset($byBar[$last['bar']]) ? $last : ($visible[count($visible) - 1][0] ?? $first), $lastRow);
      $sourceY += $side * ($radius + max(2, $step * 0.25));
      $targetY += $side * ($radius + max(2, $step * 0.25));
      $rgb = sscanf(BarPainter::color($score, $first['melody']), '#%02x%02x%02x');
      $cairo->cairo_set_source_rgb($context, $rgb[0] / 255, $rgb[1] / 255, $rgb[2] / 255);
      $span = $right - $left;
      $bend = min(4 * $step, max(1.2 * $step, $span * 0.12));
      $thickness = max(1.5, $step * 0.32);
      $outer = $side * ($bend + $thickness) * 4 / 3;
      $inner = $side * $bend * 4 / 3;
      $cairo->cairo_move_to($context, $left, $sourceY);
      $cairo->cairo_curve_to($context, $left + $span / 3, $sourceY + $outer,
        $right - $span / 3, $targetY + $outer, $right, $targetY);
      $cairo->cairo_curve_to($context, $right - $span / 3, $targetY + $inner,
        $left + $span / 3, $sourceY + $inner, $left, $sourceY);
      $cairo->cairo_close_path($context);
      $cairo->cairo_fill($context);
    }
  }

  /** Return maximal chains of adjacent notes linked by outgoing slur flags. */
  private static function groups(Score $score): array {
    $destinations = [];
    foreach ($score->notes as $index => $note) {
      if (($note['slurToNext'] ?? false) && ($next = $score->nextAdjacent($index)) !== null) {
        $destinations[$next] = true;
      }
    }
    $groups = [];
    foreach ($score->notes as $index => $note) {
      if (!($note['slurToNext'] ?? false) || isset($destinations[$index])) {
        continue;
      }
      $chain = [$index];
      $last = $index;
      while (($score->notes[$last]['slurToNext'] ?? false) && ($next = $score->nextAdjacent($last)) !== null) {
        $chain[] = $next;
        $last = $next;
      }
      if (count($chain) > 1) {
        $groups[] = $chain;
      }
    }
    return $groups;
  }

  private static function leftEdge(Score $score, array $position, array $note, float $radius): float {
    $unit = ($position['right'] - $position['left']) / (2 * $score->beats($note['bar']));
    $head = $position['left'] + ($note['slot'] + ($note['length'] === 1 ? 0.5 : 1)) * $unit;
    return $head - ($note['length'] === 1 ? 0 : $radius);
  }

  private static function rightEdge(Score $score, array $byBar, array $note, float $radius, int $lastBar): float {
    $end = $score->start($note['bar']) + $note['slot'] + $note['length'];
    $headPosition = $end - ($note['length'] === 1 ? 0.5 : 1);
    for ($bar = $note['bar']; $bar <= $lastBar; $bar++) {
      if (!isset($byBar[$bar])) {
        continue;
      }
      $barStart = $score->start($bar);
      if ($headPosition >= $barStart && $headPosition < $barStart + 2 * $score->beats($bar)) {
        $position = $byBar[$bar];
        $unit = ($position['right'] - $position['left']) / (2 * $score->beats($bar));
        return $position['left'] + ($headPosition - $barStart) * $unit + $radius;
      }
    }
    return $byBar[$lastBar]['right'] - 4;
  }

  private static function headY(array $note, array $row): float {
    return $row['top'] + (($row['band'] - $note['octave']) * 12 + 11 - Pitch::position($note)) * $row['height'] / 12;
  }

  private static function noteRow(array $rows, array $note): ?array {
    $band = $note['octave'];
    if (!isset($rows[$band]) && Pitch::position($note) === 12 && isset($rows[$band + 1])) {
      $band++;
    }
    if (!isset($rows[$band])) {
      return null;
    }
    return $rows[$band] + ['band' => $band];
  }
}
