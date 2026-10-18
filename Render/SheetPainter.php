<?php

namespace MADSCORE\Render;

use MADSCORE\Score\MelodyRange;
use MADSCORE\Score\Score;

use SPTK\Core\RasterImage;

/** Paints adaptive octave staffs on one continuous Cairo surface. */
final class SheetPainter {

  private const INSET = 5;

  public static function render(Score $score, int $firstBar, int $columns, int $width, int $height, ?array $cursor = null, ?int $melody = null, ?array $selection = null, bool $design = false, int $firstRow = 0): RasterImage {
    $width = max(1, $width);
    $height = max(1, $height);
    $columns = max(1, $columns);
    $insetX = min(self::INSET, intdiv($width - 1, 2));
    $insetY = min(self::INSET, intdiv($height - 1, 2));
    $sheetWidth = $width - 2 * $insetX;
    $sheetHeight = $height - 2 * $insetY;
    $topSpace = max(48, intdiv($sheetHeight, 10));
    $tileHeight = max(1, intdiv($sheetHeight - $topSpace, 4));
    $states = $design ? [] : Prescription::states($score, $cursor['start'] ?? null, $cursor['end'] ?? null, $cursor['octave'] ?? null);
    if ($design) {
      $bands = MelodyRange::bands($score, $melody ?? 0, $cursor['octave'] ?? null, $cursor['pitch'] ?? 1);
      for ($bar = 0; $bar < max(count($score->bars), $firstBar + $columns); $bar++) {
        $states[$bar] = ['bands' => $bands, 'primary' => $bands[1], 'change' => $bar === 0];
      }
    }
    for ($bar = count($states); $bar < $firstBar + $columns; $bar++) {
      $state = $states[$bar - 1] ?? ['bands' => [5, 4, 3, 2], 'primary' => 4];
      $state['change'] = false;
      $states[$bar] = $state;
    }
    for ($bar = count($score->bars); $bar < $firstBar + $columns; $bar++) {
      $states[$bar]['change'] = false;
    }
    $bars = range($firstBar, $firstBar + $columns - 1);
    $markerWidth = min(48, max(20, (int)ceil($tileHeight / 12 * 1.9 + 6)));
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
      $cairo->cairo_rectangle($context, -$insetX, 0, $width, $sheetHeight);
      $cairo->cairo_clip($context);
      $cairo->cairo_translate($context, 0, -($design ? 0 : max(0, min(5, $firstRow))) * $tileHeight);
      foreach ($positions as $position) {
        $bar = $position['bar'];
        if ($bar >= count($score->bars)) {
          continue;
        }
        $rows = self::rows($states[$bar], $tileHeight, $topSpace, $design);
        if ($score->bars[$bar]['sectionLeader']) {
          SectionPainter::edge($cairo, $context, $rows, $position['left']);
        }
        if ($bar === count($score->bars) - 1) {
          SectionPainter::edge($cairo, $context, $rows, $position['right'], right: true);
        }
      }
      foreach ($positions as $column => $position) {
        $bar = $position['bar'];
        if ($bar >= count($score->bars)) {
          continue;
        }
        $state = $states[$bar];
        $rows = self::rows($state, $tileHeight, $topSpace, $design);
        if ($bar === count($score->bars) - 1 || $score->bars[$bar + 1]['sectionLeader']) {
          RepeatPainter::draw($cairo, $context, $position['right'] - 2, $topSpace * 0.4,
            reset($rows)['height'], $score->sectionRepeats($bar));
        }
        if ($position['markerLeft'] !== null) {
          Prescription::drawMarkers($cairo, $context, $rows, $position['markerLeft'] + $markerWidth / 2);
        }
        $sounding = Prescription::soundingBands($score, $bar);
        $barStart = $score->start($bar);
        $previewHere = $cursor !== null && $cursor['start'] < $barStart + 2 * $score->beats($bar) && $cursor['end'] > $barStart;
        $nextVisible = $positions[$column + 1]['bar'] ?? null;
        $sectionAtRight = $bar === count($score->bars) - 1
          || ($nextVisible === $bar + 1 && ($score->bars[$nextVisible]['sectionLeader'] ?? false));
        foreach ($rows as $band => $row) {
          if (!$design && $band !== $state['primary'] && !in_array($band, $sounding, true)
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
            || (!$design && !Prescription::bandContinues($score, $states, $bar, $band, $cursor));
          BarPainter::draw($cairo, $context, $score, $bar, $band, $barWidth, $row['height'],
            endBar: $endBar && !$sectionAtRight, showSlots: true, paintBackground: false,
            activeMelody: $design ? ($melody ?? 0) : null,
            startBorder: !$score->bars[$bar]['sectionLeader']);
          $cairo->cairo_restore($context);
        }
      }
      $dynamicRows = self::rows($states[$positions[0]['bar']], $tileHeight, $topSpace, $design);
      $dynamicRowHeight = reset($dynamicRows)['height'];
      DynamicPainter::draw($cairo, $context,
        DynamicPainter::segments($score, $positions, $dynamicRowHeight), $topSpace * 0.45, $dynamicRowHeight);
      DynamicPainter::drawLabels($cairo, $context,
        DynamicPainter::labels($score, $positions, $dynamicRowHeight), $topSpace * 0.45, $dynamicRowHeight);
      SlurPainter::draw($cairo, $context, $score, $positions,
        static fn (int $bar): array => self::rows($states[$bar], $tileHeight, $topSpace, $design));
      if ($selection !== null) {
        self::drawSelection($cairo, $context, $score, $positions, $states, $tileHeight, $topSpace, $selection, $design);
      }
      if ($selection === null && $cursor !== null && $melody !== null) {
        self::drawCursor($cairo, $context, $score, $positions, $states, $tileHeight, $topSpace, $cursor, $melody, $design);
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

  private static function rows(array $state, int $tileHeight, int $topSpace, bool $design): array {
    if ($design) {
      $rows = [];
      foreach ($state['bands'] as $index => $band) {
        $rows[$band] = ['top' => $topSpace + $index * $tileHeight, 'height' => $tileHeight];
      }
      return $rows;
    }
    return Prescription::rows($state, $topSpace + max(4, count($state['bands'])) * $tileHeight, $topSpace);
  }

  /** Highlight the selected time range across all four octave rows. */
  private static function drawSelection(\FFI $cairo, \FFI\CData $context, Score $score, array $positions, array $states, int $tileHeight, int $topSpace, array $selection, bool $design): void {
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
      $rows = self::rows($states[$bar], $tileHeight, $topSpace, $design);
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

  private static function drawCursor(\FFI $cairo, \FFI\CData $context, Score $score, array $positions, array $states, int $tileHeight, int $topSpace, array $cursor, int $melody, bool $design): void {
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
      $rows = self::rows($states[$bar], $tileHeight, $topSpace, $design);
      $displayOctave = $cursor['octave'];
      if (!isset($rows[$displayOctave]) && $cursor['pitch'] === 12 && isset($rows[$displayOctave + 1])) {
        $displayOctave++;
      }
      $row = $rows[$displayOctave] ?? null;
      if ($row === null) {
        continue;
      }
      $step = $row['height'] / 12;
      $pitchY = $row['top'] + (($displayOctave - $cursor['octave']) * 12 + 11 - $cursor['pitch']) * $step;
      $spanTop = $row['top'] + ($displayOctave !== $cursor['octave'] ? 11 : -1) * $step;
      $spanBottom = $spanTop + 11 * $step;
      $unit = ($position['right'] - $position['left']) / (2 * $score->beats($bar));
      $left = $position['left'] + ($start - $barStart) * $unit;
      $right = $position['left'] + ($end - $barStart) * $unit;
      self::line($cairo, $context, $left, $pitchY, $right, $pitchY);
      if ($cursor['start'] >= $barStart && $cursor['start'] < $barEnd) {
        self::line($cairo, $context, $left, max(0, $spanTop), $left, $spanBottom);
      }
      if ($cursor['end'] > $barStart && $cursor['end'] <= $barEnd) {
        self::line($cairo, $context, $right, max(0, $spanTop), $right, $spanBottom);
      }
    }
  }

  private static function line(\FFI $cairo, \FFI\CData $context, float $x1, float $y1, float $x2, float $y2): void {
    $cairo->cairo_move_to($context, $x1, $y1);
    $cairo->cairo_line_to($context, $x2, $y2);
    $cairo->cairo_stroke($context);
  }
}
