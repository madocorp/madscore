<?php

namespace MADSCORE;

use SPTK\Core\RasterImage;

/** Paints adaptive octave staffs on one continuous Cairo surface. */
final class SheetPainter {

  private const INSET = 5;

  public static function render(Score $score, int $firstBar, int $columns, int $width, int $height, ?array $cursor = null, ?int $melody = null, ?array $selection = null): RasterImage {
    $width = max(1, $width);
    $height = max(1, $height);
    $columns = max(1, $columns);
    $insetX = min(self::INSET, intdiv($width - 1, 2));
    $insetY = min(self::INSET, intdiv($height - 1, 2));
    $sheetWidth = $width - 2 * $insetX;
    $sheetHeight = $height - 2 * $insetY;
    $topSpace = intdiv($sheetHeight, 20);
    $states = Prescription::states($score, $cursor['start'] ?? null, $cursor['end'] ?? null, $cursor['octave'] ?? null);
    for ($bar = count($states); $bar < $firstBar + $columns; $bar++) {
      $state = $states[$bar - 1] ?? ['bands' => [5, 4, 3, 2], 'primary' => 4];
      $state['change'] = false;
      $states[$bar] = $state;
    }
    for ($bar = count($score->bars); $bar < $firstBar + $columns; $bar++) {
      $states[$bar]['change'] = false;
    }
    $bars = range($firstBar, $firstBar + $columns - 1);
    $markerWidth = min(18, max(8, intdiv($sheetWidth, max(2, $columns * 8))));
    $markers = 1;
    foreach (array_slice($bars, 1) as $bar) {
      $markers += (int)$states[$bar]['change'];
    }
    $usable = max($columns, $sheetWidth - $markers * $markerWidth);
    $widths = [];
    for ($column = 0; $column < $columns; $column++) {
      $widths[] = intdiv($usable * ($column + 1), $columns) - intdiv($usable * $column, $columns);
    }
    $positions = Prescription::positions($bars, $widths, $states, $markerWidth);
    $cairo = Cairo::library();
    $surface = $cairo->cairo_image_surface_create(0, $width, $height);
    Cairo::check($surface);
    $context = $cairo->cairo_create($surface);
    try {
      $cairo->cairo_set_source_rgb($context, 1, 1, 1);
      $cairo->cairo_paint($context);
      $cairo->cairo_translate($context, $insetX, $insetY);
      foreach ($positions as $column => $position) {
        $bar = $position['bar'];
        if ($bar >= count($score->bars)) {
          continue;
        }
        $state = $states[$bar];
        $rows = Prescription::rows($state, $sheetHeight, $topSpace);
        if ($position['markerLeft'] !== null) {
          Prescription::drawMarkers($cairo, $context, $rows, $position['markerLeft'] + $markerWidth / 2);
        }
        if (isset($score->bars[$bar]) && $score->bars[$bar]['sectionLeader']) {
          SectionPainter::edge($cairo, $context, $rows, $position['left']);
        }
        if ($bar === count($score->bars) - 1) {
          SectionPainter::edge($cairo, $context, $rows, $position['right']);
        }
        $sounding = Prescription::soundingBands($score, $bar);
        $barStart = $score->start($bar);
        $previewHere = $cursor !== null && $cursor['start'] < $barStart + 2 * $score->beats($bar) && $cursor['end'] > $barStart;
        foreach ($rows as $band => $row) {
          if ($band !== $state['primary'] && !in_array($band, $sounding, true)
            && !($previewHere && $cursor['octave'] === $band)) {
            continue;
          }
          $cairo->cairo_save($context);
          $cairo->cairo_translate($context, $position['left'], $row['top']);
          $clipTop = -min($row['top'], 2 * $row['height'] / 12);
          $barWidth = $position['right'] - $position['left'];
          $cairo->cairo_rectangle($context, 0, $clipTop, $barWidth, $row['height'] - $clipTop);
          $cairo->cairo_clip($context);
          $endBar = $column === count($positions) - 1
            || !Prescription::bandContinues($score, $states, $bar, $band, $cursor);
          BarPainter::draw($cairo, $context, $score, $bar, $band, $barWidth, $row['height'], endBar: $endBar, showSlots: true, paintBackground: false);
          $cairo->cairo_restore($context);
        }
      }
      if ($selection !== null) {
        self::drawSelection($cairo, $context, $score, $positions, $states, $sheetHeight, $topSpace, $selection);
      }
      if ($selection === null && $cursor !== null && $melody !== null) {
        self::drawCursor($cairo, $context, $score, $positions, $states, $sheetHeight, $topSpace, $cursor, $melody);
      }
      if ($cairo->cairo_status($context) !== 0) {
        throw new \RuntimeException('Cairo could not draw the score sheet.');
      }
      return Cairo::raster($surface, $width, $height);
    } finally {
      $cairo->cairo_destroy($context);
      $cairo->cairo_surface_destroy($surface);
    }
  }

  /** Highlight the selected time range across all four octave rows. */
  private static function drawSelection(\FFI $cairo, \FFI\CData $context, Score $score, array $positions, array $states, int $height, int $topSpace, array $selection): void {
    $rgb = sscanf(BarPainter::color($score, $selection['melody']), '#%02x%02x%02x');
    $red = $rgb[0] / 255;
    $green = $rgb[1] / 255;
    $blue = $rgb[2] / 255;
    foreach ($positions as $position) {
      $bar = $position['bar'];
      if ($bar >= count($score->bars)) {
        continue;
      }
      $barStart = $score->start($bar);
      $barEnd = $barStart + 2 * $score->beats($bar);
      $start = max($barStart, $selection['start']);
      $end = min($barEnd, $selection['end']);
      if ($end <= $start) {
        continue;
      }
      $rows = Prescription::rows($states[$bar], $height, $topSpace);
      $top = min(array_column($rows, 'top'));
      $bottom = max(array_map(static fn (array $row): int => $row['top'] + $row['height'], $rows));
      $unit = ($position['right'] - $position['left']) / (2 * $score->beats($bar));
      $left = $position['left'] + ($start - $barStart) * $unit;
      $right = $position['left'] + ($end - $barStart) * $unit;
      $cairo->cairo_set_source_rgba($context, $red, $green, $blue, 0.12);
      $cairo->cairo_rectangle($context, $left, $top, $right - $left, $bottom - $top);
      $cairo->cairo_fill($context);
      $cairo->cairo_set_source_rgba($context, $red, $green, $blue, 0.85);
      $cairo->cairo_set_line_width($context, 2);
      self::line($cairo, $context, $left, $top, $right, $top);
      self::line($cairo, $context, $left, $bottom, $right, $bottom);
      if ($selection['start'] >= $barStart && $selection['start'] < $barEnd) {
        self::line($cairo, $context, $left, $top, $left, $bottom);
      }
      if ($selection['end'] > $barStart && $selection['end'] <= $barEnd) {
        self::line($cairo, $context, $right, $top, $right, $bottom);
      }
    }
  }

  private static function drawCursor(\FFI $cairo, \FFI\CData $context, Score $score, array $positions, array $states, int $height, int $topSpace, array $cursor, int $melody): void {
    $rgb = sscanf(BarPainter::color($score, $melody), '#%02x%02x%02x');
    $cairo->cairo_set_source_rgb($context, $rgb[0] / 255, $rgb[1] / 255, $rgb[2] / 255);
    $cairo->cairo_set_line_width($context, 3);
    foreach ($positions as $position) {
      $bar = $position['bar'];
      if ($bar >= count($score->bars)) {
        continue;
      }
      $barStart = $score->start($bar);
      $barEnd = $barStart + 2 * $score->beats($bar);
      $start = max($barStart, $cursor['start']);
      $end = min($barEnd, $cursor['end']);
      if ($end <= $start) {
        continue;
      }
      $rows = Prescription::rows($states[$bar], $height, $topSpace);
      $row = $rows[$cursor['octave']] ?? null;
      if ($row === null) {
        continue;
      }
      $step = $row['height'] / 12;
      $pitchY = $row['top'] + (11 - $cursor['pitch']) * $step;
      $spanTop = $row['top'] + ($cursor['pitch'] === 0 ? 11 : -1) * $step;
      $spanBottom = $spanTop + 11 * $step;
      $unit = ($position['right'] - $position['left']) / (2 * $score->beats($bar));
      $left = $position['left'] + ($start - $barStart) * $unit;
      $right = $position['left'] + ($end - $barStart) * $unit;
      self::line($cairo, $context, $left, $pitchY, $right, $pitchY);
      if ($cursor['start'] >= $barStart && $cursor['start'] < $barEnd) {
        self::line($cairo, $context, $left, max(0, $spanTop), $left, min($height, $spanBottom));
      }
      if ($cursor['end'] > $barStart && $cursor['end'] <= $barEnd) {
        self::line($cairo, $context, $right, max(0, $spanTop), $right, min($height, $spanBottom));
      }
    }
  }

  private static function line(\FFI $cairo, \FFI\CData $context, float $x1, float $y1, float $x2, float $y2): void {
    $cairo->cairo_move_to($context, $x1, $y1);
    $cairo->cairo_line_to($context, $x2, $y2);
    $cairo->cairo_stroke($context);
  }
}
