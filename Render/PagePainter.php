<?php

namespace MADSCORE\Render;

use MADSCORE\Score\Score;

/** Arranges adaptive vector score bars on landscape A4 pages. */
final class PagePainter {

  public const WIDTH = 3508;
  public const HEIGHT = 2480;
  public const A4_WIDTH_POINTS = 297 * 72 / 25.4;
  public const A4_HEIGHT_POINTS = 210 * 72 / 25.4;
  private const MARKER_WIDTH = 44;
  private const ROW_HEIGHT = 220;
  private const TOP_SPACE = 120;
  private const LINE_PADDING = 40;
  private const FIRST_LINE_Y = 200;
  private const SIDE_MARGIN = 75;
  private const TEMPO_LEVEL_GAP = 8;

  /** Draw every score page into a Cairo PDF context. */
  public static function drawPages(Score $score, \FFI $cairo, \FFI\CData $context): void {
    $score = $score->visibleCopy()->renderCopy();
    $states = Prescription::states($score);
    $pages = self::pages($score, $states);
    foreach ($pages as $index => $page) {
      self::drawPage($score, $cairo, $context, $page, $states, $index + 1, count($pages));
      $cairo->cairo_show_page($context);
    }
  }

  /** Draw a single PDF page into a context scaled to the page bounds. */
  public static function drawPage(Score $score, \FFI $cairo, \FFI\CData $context, array $page, ?array $states = null, int $pageNumber = 1, int $pageCount = 1): void {
    $score = $score->visibleCopy()->renderCopy();
    $states ??= Prescription::states($score);
    $cairo->cairo_save($context);
    try {
      $cairo->cairo_set_source_rgb($context, 1, 1, 1);
      $cairo->cairo_paint($context);
      self::drawHeader($score, $cairo, $context, $pageNumber, $pageCount);
      $y = self::FIRST_LINE_Y;
      foreach ($page as $bars) {
        $lineRows = max(4, ...array_map(static fn (int $bar): int => count($states[$bar]['bands']), $bars));
        $rowHeight = self::rowHeight($lineRows);
        $lineHeight = self::TOP_SPACE + self::LINE_PADDING + $lineRows * $rowHeight;
        $widths = array_map(static fn (int $bar): int => self::barWidth($score, $bar), $bars);
        $positions = Prescription::positions($bars, $widths, $states, self::MARKER_WIDTH);
        $dynamicSegments = DynamicPainter::segments($score, $positions, $rowHeight);
        $tempoReservations = [];
        foreach ($positions as $position) {
          if (($tempo = TempoPainter::label($score, $position['bar'])) !== null) {
            $tempoReservations[$score->start($position['bar'])] = $position['left']
              + TempoPainter::width($tempo) + self::TEMPO_LEVEL_GAP;
          }
        }
        $cairo->cairo_save($context);
        $cairo->cairo_translate($context, self::SIDE_MARGIN, $y);
        $lastPrintedBar = $positions[count($positions) - 1]['bar'];
        foreach ($positions as $position) {
          $bar = $position['bar'];
          $rows = Prescription::rows($states[$bar], self::TOP_SPACE + max(4, count($states[$bar]['bands'])) * $rowHeight, self::TOP_SPACE);
          if ($score->bars[$bar]['sectionLeader']) {
            SectionPainter::edge($cairo, $context, $rows, $position['left']);
          }
          if ($bar === count($score->bars) - 1
            || ($bar === $lastPrintedBar && ($score->bars[$bar + 1]['sectionLeader'] ?? false))) {
            SectionPainter::edge($cairo, $context, $rows, $position['right'], right: true);
          }
        }
        $cairo->cairo_restore($context);
        foreach ($positions as $index => $position) {
          $bar = $position['bar'];
          $state = $states[$bar];
          $rows = Prescription::rows($state, self::TOP_SPACE + max(4, count($state['bands'])) * $rowHeight, self::TOP_SPACE);
          $cairo->cairo_save($context);
          $cairo->cairo_translate($context, self::SIDE_MARGIN, $y);
          $repeats = ($bar === count($score->bars) - 1 || $score->bars[$bar + 1]['sectionLeader'])
            ? $score->sectionRepeats($bar) : 0;
          if ($score->bars[$bar]['note'] !== '') {
            $cairo->cairo_save($context);
            $noteWidth = $position['right'] - $position['left'];
            $cairo->cairo_rectangle($context, $position['left'], 0, $noteWidth, 40);
            $cairo->cairo_clip($context);
            $cairo->cairo_set_source_rgb($context, 0.094, 0.125, 0.11);
            $cairo->cairo_select_font_face($context, 'Sans', 0, 0);
            $cairo->cairo_set_font_size($context, 30);
            $cairo->cairo_move_to($context, $position['left'], 33);
            $cairo->cairo_show_text($context, $score->bars[$bar]['note']);
            $cairo->cairo_restore($context);
          }
          if ($repeats > 0) {
            RepeatPainter::draw($cairo, $context, $position['right'] - 4, 62, $rowHeight, $repeats);
          }
          if ($position['markerLeft'] !== null) {
            Prescription::drawMarkers($cairo, $context, $rows, $position['markerLeft'] + self::MARKER_WIDTH / 2);
          }
          $sounding = Prescription::soundingBands($score, $bar);
          $sectionAtRight = $bar === count($score->bars) - 1
            || ($score->bars[$bar + 1]['sectionLeader'] ?? false);
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
            BarPainter::draw($cairo, $context, $score, $bar, $band, $width, $row['height'],
              endBar: $endBar && !$sectionAtRight, paintBackground: false,
              startBorder: !$score->bars[$bar]['sectionLeader'], outsideMarks: $rowHeight < self::ROW_HEIGHT * 0.8);
            $cairo->cairo_restore($context);
          }
          $cairo->cairo_restore($context);
        }
        $cairo->cairo_save($context);
        $cairo->cairo_translate($context, self::SIDE_MARGIN, $y);
        DynamicPainter::draw($cairo, $context, $dynamicSegments, 68, $rowHeight);
        DynamicPainter::drawLabels($cairo, $context,
          DynamicPainter::labels($score, $positions, $rowHeight, $tempoReservations), 68, $rowHeight);
        SlurPainter::draw($cairo, $context, $score, $positions,
          static fn (int $bar): array => Prescription::rows($states[$bar],
            self::TOP_SPACE + max(4, count($states[$bar]['bands'])) * $rowHeight, self::TOP_SPACE));
        foreach ($positions as $position) {
          if (($tempo = TempoPainter::label($score, $position['bar'])) !== null) {
            TempoPainter::draw($cairo, $context, $position['left'], $position['right'], $tempo);
          }
        }
        $cairo->cairo_restore($context);
        $y += $lineHeight;
      }
    } finally {
      $cairo->cairo_restore($context);
    }
  }

  /** Print title and author on the left, catalog and page count on the right. */
  private static function drawHeader(Score $score, \FFI $cairo, \FFI\CData $context, int $pageNumber, int $pageCount): void {
    $cairo->cairo_set_source_rgb($context, 0.094, 0.125, 0.11);
    self::headerText($cairo, $context, $score->title, self::SIDE_MARGIN, 110, 48, 2000);
    self::headerText($cairo, $context, $score->author, self::SIDE_MARGIN, 155, 30, 2000);
    self::headerText($cairo, $context, $score->catalog, self::WIDTH - self::SIDE_MARGIN, 110, 32, 1050, true);
    self::headerText($cairo, $context, '- ' . $pageNumber . ' / ' . $pageCount . ' -',
      self::WIDTH - self::SIDE_MARGIN, 155, 32, 1050, true);
  }

  /** Keep a header label inside its column and align it to the chosen edge. */
  private static function headerText(\FFI $cairo, \FFI\CData $context, string $value, float $edge, float $baseline, float $size, float $maxWidth, bool $right = false): void {
    if ($value === '') {
      return;
    }
    $cairo->cairo_select_font_face($context, 'Sans', 0, 0);
    $cairo->cairo_set_font_size($context, $size);
    $extents = $cairo->new('cairo_text_extents_t');
    $cairo->cairo_text_extents($context, $value, \FFI::addr($extents));
    if ($extents->x_advance > $maxWidth) {
      $cairo->cairo_set_font_size($context, max(18, $size * $maxWidth / $extents->x_advance));
      $cairo->cairo_text_extents($context, $value, \FFI::addr($extents));
    }
    $cairo->cairo_save($context);
    if ($right) {
      $cairo->cairo_rectangle($context, $edge - $maxWidth, 0, $maxWidth, self::FIRST_LINE_Y);
      $cairo->cairo_clip($context);
      $cairo->cairo_move_to($context, $edge - $extents->x_advance, $baseline);
    } else {
      $cairo->cairo_rectangle($context, $edge, 0, $maxWidth, self::FIRST_LINE_Y);
      $cairo->cairo_clip($context);
      $cairo->cairo_move_to($context, $edge, $baseline);
    }
    $cairo->cairo_show_text($context, $value);
    $cairo->cairo_restore($context);
  }

  /** Return the exact line groups used by PDF pagination. */
  public static function pages(Score $score, ?array $states = null): array {
    $score = $score->visibleCopy()->renderCopy();
    $states ??= Prescription::states($score);
    $pages = [];
    $page = [];
    $y = self::FIRST_LINE_Y;
    foreach (self::lines($score, $states) as $bars) {
      $rows = max(4, ...array_map(static fn (int $bar): int => count($states[$bar]['bands']), $bars));
      $height = self::TOP_SPACE + self::LINE_PADDING + $rows * self::rowHeight($rows);
      if ($page !== [] && $y + $height > self::HEIGHT - 125) {
        $pages[] = $page;
        $page = [];
        $y = self::FIRST_LINE_Y;
      }
      $page[] = $bars;
      $y += $height;
    }
    if ($page !== []) {
      $pages[] = $page;
    }
    return $pages;
  }

  /** Grow staff rows with notes, but keep one full line inside the page. */
  private static function rowHeight(int $rows): int {
    $requested = (int)round(self::ROW_HEIGHT * PdfLayout::noteSize() / 100);
    $available = self::HEIGHT - self::FIRST_LINE_Y - 125 - self::TOP_SPACE - self::LINE_PADDING;
    return min($requested, intdiv($available, $rows));
  }

  /** Keep a section together when it fits; split longer sections at bar edges. */
  private static function lines(Score $score, array $states): array {
    $lines = [];
    $line = [];
    $width = 0;
    $limit = self::WIDTH - 2 * self::SIDE_MARGIN;
    $count = count($score->bars);
    for ($first = 0; $first < $count; $first = $last) {
      $last = $first + 1;
      while ($last < $count && !$score->bars[$last]['sectionLeader']) {
        $last++;
      }
      $sectionWidth = 0;
      for ($bar = $first; $bar < $last; $bar++) {
        $sectionWidth += self::barWidth($score, $bar)
          + ($bar === $first || $states[$bar]['change'] ? self::MARKER_WIDTH : 0);
      }
      $widthHere = $sectionWidth - ($line !== [] && !$states[$first]['change'] ? self::MARKER_WIDTH : 0);
      if ($line !== [] && ($sectionWidth > $limit || $width + $widthHere > $limit)) {
        $lines[] = $line;
        $line = [];
        $width = 0;
      }
      for ($bar = $first; $bar < $last; $bar++) {
        $needed = self::barWidth($score, $bar)
          + ($line === [] || $states[$bar]['change'] ? self::MARKER_WIDTH : 0);
        if ($line !== [] && $width + $needed > $limit) {
          $lines[] = $line;
          $line = [];
          $width = 0;
          $needed = self::barWidth($score, $bar) + self::MARKER_WIDTH;
        }
        $line[] = $bar;
        $width += $needed;
      }
    }
    if ($line !== []) {
      $lines[] = $line;
    }
    return $lines;
  }

  /** Size a bar from its beat count and the configured horizontal spacing. */
  private static function barWidth(Score $score, int $bar): int {
    $slotWidth = (self::ROW_HEIGHT / 6) * PdfLayout::beatSize() / 100;
    $width = (int)round(2 * $score->bars[$bar]['beats'] * $slotWidth);
    if (($tempo = TempoPainter::label($score, $bar)) !== null) {
      $levelWidth = DynamicPainter::startLabelWidth($score, $bar,
        self::ROW_HEIGHT * PdfLayout::noteSize() / 100);
      $width = max($width, (int)ceil(TempoPainter::width($tempo)
        + ($levelWidth > 0 ? self::TEMPO_LEVEL_GAP + $levelWidth : 0)));
    }
    if ($bar === count($score->bars) - 1 || $score->bars[$bar + 1]['sectionLeader']) {
      $width = max($width, (int)ceil(RepeatPainter::width($score->sectionRepeats($bar),
        self::ROW_HEIGHT * PdfLayout::noteSize() / 100) + 16));
    }
    return min(self::WIDTH - 2 * self::SIDE_MARGIN - self::MARKER_WIDTH, $width);
  }
}
