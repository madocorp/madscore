<?php

namespace MADSCORE;

/** Arranges adaptive vector score bars on landscape A4 pages. */
final class PagePainter {

  public const WIDTH = 3508;
  public const HEIGHT = 2480;
  private const TILE_HEIGHT = 200;
  private const MARKER_WIDTH = 32;
  private const LINE_HEIGHT = 960;

  /** Draw every score page into a Cairo PDF context. */
  public static function drawPages(Score $score, \FFI $cairo, \FFI\CData $context): void {
    $states = Prescription::states($score);
    $lines = self::lines($score, $states);
    $line = 0;
    while ($line < count($lines)) {
      $cairo->cairo_save($context);
      $cairo->cairo_scale($context, 842 / self::WIDTH, 595 / self::HEIGHT);
      $cairo->cairo_set_source_rgb($context, 1, 1, 1);
      $cairo->cairo_paint($context);
      $y = 150;
      while ($line < count($lines) && $y + self::LINE_HEIGHT < self::HEIGHT - 125) {
        $bars = $lines[$line];
        $widths = array_map(static fn (int $bar): int => self::barWidth($score, $bar), $bars);
        $positions = Prescription::positions($bars, $widths, $states, self::MARKER_WIDTH);
        foreach ($positions as $index => $position) {
          $bar = $position['bar'];
          $state = $states[$bar];
          $rows = Prescription::rows($state, 880, 40);
          $cairo->cairo_save($context);
          $cairo->cairo_translate($context, 150, $y);
          if ($position['markerLeft'] !== null) {
            Prescription::drawMarkers($cairo, $context, $rows, $position['markerLeft'] + self::MARKER_WIDTH / 2);
          }
          if ($score->bars[$bar]['sectionLeader']) {
            SectionPainter::edge($cairo, $context, $rows, $position['left']);
          }
          if ($bar === count($score->bars) - 1) {
            SectionPainter::edge($cairo, $context, $rows, $position['right']);
          }
          $sounding = Prescription::soundingBands($score, $bar);
          foreach ($rows as $band => $row) {
            if ($band !== $state['primary'] && !in_array($band, $sounding, true)) {
              continue;
            }
            $cairo->cairo_save($context);
            $cairo->cairo_translate($context, $position['left'], $row['top']);
            $clipTop = -min($row['top'], 2 * $row['height'] / 12);
            $width = $position['right'] - $position['left'];
            $cairo->cairo_rectangle($context, 0, $clipTop, $width, $row['height'] - $clipTop);
            $cairo->cairo_clip($context);
            $endBar = $index === count($positions) - 1
              || !Prescription::bandContinues($score, $states, $bar, $band);
            BarPainter::draw($cairo, $context, $score, $bar, $band, $width, $row['height'], endBar: $endBar, paintBackground: false);
            $cairo->cairo_restore($context);
          }
          $cairo->cairo_restore($context);
        }
        $y += self::LINE_HEIGHT;
        $line++;
      }
      $cairo->cairo_restore($context);
      $cairo->cairo_show_page($context);
    }
  }

  /** Group bars into printable lines, allowing room for prescription symbols. */
  private static function lines(Score $score, array $states): array {
    $lines = [];
    $line = [];
    $width = 0;
    foreach ($score->bars as $bar => $_) {
      $barWidth = self::barWidth($score, $bar);
      $marker = $line === [] || $states[$bar]['change'] ? self::MARKER_WIDTH : 0;
      if ($line !== [] && $width + $barWidth + $marker > self::WIDTH - 300) {
        $lines[] = $line;
        $line = [];
        $width = 0;
        $marker = self::MARKER_WIDTH;
      }
      $line[] = $bar;
      $width += $barWidth + $marker;
    }
    if ($line !== []) {
      $lines[] = $line;
    }
    return $lines;
  }

  /** Give each half-beat position twice the vertical note size. */
  private static function barWidth(Score $score, int $bar): int {
    $slotWidth = 2 * (self::TILE_HEIGHT / 12);
    return min(self::WIDTH - 300 - self::MARKER_WIDTH, (int)round(2 * $score->bars[$bar]['beats'] * $slotWidth));
  }
}
