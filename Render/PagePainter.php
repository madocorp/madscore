<?php

namespace MADSCORE\Render;

use MADSCORE\Score\Score;
use MADSCORE\Score\Project;

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
  public static function drawPages(Score $score, \FFI $cairo, \FFI\CData $context, ?Project $project = null): void {
    $score = $score->visibleCopy()->renderCopy();
    $figures = new FigureCatalog($score, $project);
    $states = Prescription::states($score, figures: $figures);
    $pages = self::pages($score, $states, figures: $figures);
    [$inlineTiles, $appendixPages] = self::appendixPlacement($pages, $states, $figures);
    $pageCount = count($pages);
    foreach ($pages as $index => $page) {
      self::drawPage($score, $cairo, $context, $page, $states, $index + 1, $pageCount,
        $figures, $index === 0 ? $inlineTiles : []);
      $cairo->cairo_show_page($context);
    }
    foreach ($appendixPages as $index => $tiles) {
      FigureAppendix::drawPage($cairo, $context, $tiles);
      self::drawHeader($score, $cairo, $context,
        count($pages) + $index + 1, $pageCount, $index + 1);
      $cairo->cairo_show_page($context);
    }
  }

  /** Draw a single PDF page into a context scaled to the page bounds. */
  public static function drawPage(Score $score, \FFI $cairo, \FFI\CData $context, array $page, ?array $states = null, int $pageNumber = 1, int $pageCount = 1, ?FigureCatalog $figures = null, array $inlineTiles = []): void {
    $score = $score->visibleCopy()->renderCopy();
    $states ??= Prescription::states($score);
    $cairo->cairo_save($context);
    try {
      $cairo->cairo_set_source_rgb($context, 1, 1, 1);
      $cairo->cairo_paint($context);
      self::drawHeader($score, $cairo, $context, $pageNumber, $pageCount);
      $y = self::FIRST_LINE_Y;
      foreach ($page as $bars) {
        $bands = self::lineBands($score, $bars, $states, $figures);
        $lineRows = count($bands);
        $rowHeight = self::rowHeight($lineRows);
        $lineHeight = self::TOP_SPACE + self::LINE_PADDING + $lineRows * $rowHeight;
        $rows = Prescription::rows(['bands' => $bands], self::TOP_SPACE + $lineRows * $rowHeight,
          self::TOP_SPACE, 1);
        $widths = array_map(static fn (int $bar): int => self::barWidth($score, $bar), $bars);
        $positions = Prescription::positions($bars, $widths, $states, self::MARKER_WIDTH);
        $dynamicSegments = DynamicPainter::segments($score, $positions, $rowHeight);
        $tempoReservations = [];
        foreach ($positions as $position) {
          if (($tempo = TempoPainter::label($score, $position['bar'])) !== null) {
            $tempoReservations[$score->start($position['bar'])] = $position['left']
              + TempoPainter::width($tempo, true) + self::TEMPO_LEVEL_GAP;
          }
        }
        $cairo->cairo_save($context);
        $cairo->cairo_translate($context, self::SIDE_MARGIN, $y);
        $lastPrintedBar = $positions[count($positions) - 1]['bar'];
        foreach ($positions as $index => $position) {
          $bar = $position['bar'];
          if ($score->bars[$bar]['sectionLeader']) {
            SectionPainter::edge($cairo, $context, $rows, $position['left']);
          }
          $nextPosition = $positions[$index + 1] ?? null;
          if ($nextPosition !== null && $score->bars[$nextPosition['bar']]['sectionLeader']
            && $nextPosition['markerLeft'] !== null) {
            SectionPainter::edge($cairo, $context, $rows, $position['right'], right: true);
          }
          if ($bar === count($score->bars) - 1
            || ($bar === $lastPrintedBar && ($score->bars[$bar + 1]['sectionLeader'] ?? false))) {
            SectionPainter::edge($cairo, $context, $rows, $position['right'], right: true);
          }
        }
        $cairo->cairo_restore($context);
        foreach ($positions as $index => $position) {
          $bar = $position['bar'];
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
            $cairo->cairo_set_font_size($context, PdfLayout::markFontSize());
            $cairo->cairo_move_to($context, $position['left'], 33);
            $cairo->cairo_show_text($context, $score->bars[$bar]['note']);
            $cairo->cairo_restore($context);
          }
          if ($repeats > 0) {
            RepeatPainter::draw($cairo, $context, $position['right'] - 4, 62, $rowHeight, $repeats);
          }
          if ($position['markerLeft'] !== null) {
            Prescription::drawMarkers($cairo, $context, $rows,
              $position['markerLeft'] + self::MARKER_WIDTH / 2, true);
          }
          $sectionAtRight = $bar === count($score->bars) - 1
            || ($score->bars[$bar + 1]['sectionLeader'] ?? false);
          foreach ($rows as $band => $row) {
            $cairo->cairo_save($context);
            $cairo->cairo_translate($context, $position['left'], $row['top']);
            $clipTop = -min($row['top'], max(2 * $row['height'] / 12,
              PdfLayout::noteSize() < 100 ? 2 * PdfLayout::markFontSize() : 0));
            $width = $position['right'] - $position['left'];
            $cairo->cairo_rectangle($context, 0, $clipTop, $width, $row['height'] - $clipTop);
            $cairo->cairo_clip($context);
            $endBar = $index === count($positions) - 1;
            BarPainter::draw($cairo, $context, $score, $bar, $band, $width, $row['height'],
              endBar: $endBar && !$sectionAtRight, paintBackground: false,
              startBorder: !$score->bars[$bar]['sectionLeader'], figures: $figures,
              printMarks: true);
            $cairo->cairo_restore($context);
          }
          $cairo->cairo_restore($context);
        }
        $cairo->cairo_save($context);
        $cairo->cairo_translate($context, self::SIDE_MARGIN, $y);
        DynamicPainter::draw($cairo, $context, $dynamicSegments, 68, $rowHeight);
        DynamicPainter::drawLabels($cairo, $context,
          DynamicPainter::labels($score, $positions, $rowHeight, $tempoReservations, true),
          68, $rowHeight, true);
        SlurPainter::draw($cairo, $context, $score, $positions,
          static fn (int $bar): array => $rows);
        foreach ($positions as $position) {
          if (($tempo = TempoPainter::label($score, $position['bar'])) !== null) {
            TempoPainter::draw($cairo, $context, $position['left'], $position['right'],
              $tempo, true);
          }
        }
        $cairo->cairo_restore($context);
        $y += $lineHeight;
      }
      FigureAppendix::drawTiles($cairo, $context, $inlineTiles);
    } finally {
      $cairo->cairo_restore($context);
    }
  }

  /** Print title and author on the left, catalog and page count on the right. */
  public static function drawHeader(Score $score, \FFI $cairo, \FFI\CData $context,
    int $pageNumber, int $pageCount, ?int $appendixPage = null): void {
    $cairo->cairo_set_source_rgb($context, 0.094, 0.125, 0.11);
    self::headerText($cairo, $context, $score->title, self::SIDE_MARGIN, 110, 48, 2000);
    self::headerText($cairo, $context, $score->author, self::SIDE_MARGIN, 155, 32, 2000);
    self::headerText($cairo, $context, $score->catalog, self::WIDTH - self::SIDE_MARGIN, 110, 32, 1050, true);
    self::headerText($cairo, $context, $appendixPage === null
      ? '- ' . $pageNumber . ' / ' . $pageCount . ' -'
      : 'Appendix - ' . $appendixPage,
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
      $cairo->cairo_set_font_size($context,
        max(PdfLayout::markFontSize(), $size * $maxWidth / $extents->x_advance));
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
  public static function pages(Score $score, ?array $states = null, ?Project $project = null,
    ?FigureCatalog $figures = null): array {
    if ($project !== null) {
      return PdfLayout::withProject($project, static function () use ($score, $states, $project): array {
        $visible = $score->visibleCopy()->renderCopy();
        $catalog = new FigureCatalog($visible, $project);
        $states ??= Prescription::states($visible, figures: $catalog);
        return self::pages($visible, $states, figures: $catalog);
      });
    }
    $score = $score->visibleCopy()->renderCopy();
    $states ??= Prescription::states($score);
    $figures ??= new FigureCatalog($score);
    $pages = [];
    $page = [];
    $y = self::FIRST_LINE_Y;
    foreach (self::lines($score, $states) as $bars) {
      $rows = count(self::lineBands($score, $bars, $states, $figures));
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

  public static function pageCount(Score $score, ?Project $project = null): int {
    return PdfLayout::withProject($project, static function () use ($score, $project): int {
      $score = $score->visibleCopy()->renderCopy();
      $figures = new FigureCatalog($score, $project);
      $states = Prescription::states($score, figures: $figures);
      $pages = self::pages($score, $states, figures: $figures);
      [, $appendixPages] = self::appendixPlacement($pages, $states, $figures);
      return count($pages) + count($appendixPages);
    });
  }

  /** Keep all appendix tiles below a single score page when they fit. */
  public static function appendixPlacement(array $pages, array $states,
    FigureCatalog $figures): array {
    $separatePages = FigureAppendix::pages($figures);
    if (count($pages) !== 1 || $separatePages === []) {
      return [[], $separatePages];
    }
    $bottom = self::FIRST_LINE_Y;
    foreach ($pages[0] as $bars) {
      $rows = count(self::lineBands($figures->score(), $bars, $states, $figures));
      $bottom += self::TOP_SPACE + self::LINE_PADDING + $rows * self::rowHeight($rows);
    }
    $top = max(225, $bottom + 65);
    $candidate = FigureAppendix::pages($figures, $top);
    if (count($candidate) !== 1) {
      return [[], $separatePages];
    }
    foreach ($candidate[0] as $tile) {
      if ($tile['y'] + $tile['spec']['height'] > self::HEIGHT - 110) {
        return [[], $separatePages];
      }
    }
    return [$candidate[0], []];
  }

  /** Grow staff rows with notes, but keep one full line inside the page. */
  private static function rowHeight(int $rows): int {
    $requested = (int)round(self::ROW_HEIGHT * PdfLayout::noteSize() / 100);
    $available = self::HEIGHT - self::FIRST_LINE_Y - 125 - self::TOP_SPACE - self::LINE_PADDING;
    return min($requested, intdiv($available, $rows));
  }

  /** Include every octave sounded anywhere on a printed line. */
  private static function lineBands(Score $score, array $bars, array $states,
    ?FigureCatalog $figures = null): array {
    $bands = [];
    foreach ($bars as $bar) {
      foreach (Prescription::soundingBands($score, $bar, figures: $figures) as $band) {
        $bands[$band] = true;
      }
    }
    if ($bands === []) {
      $bands[$states[$bars[0]]['primary']] = true;
    }
    $bands = array_map('intval', array_keys($bands));
    rsort($bands, SORT_NUMERIC);
    return $bands;
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
        self::ROW_HEIGHT * PdfLayout::noteSize() / 100, true);
      $width = max($width, (int)ceil(TempoPainter::width($tempo, true)
        + ($levelWidth > 0 ? self::TEMPO_LEVEL_GAP + $levelWidth : 0)));
    }
    if ($bar === count($score->bars) - 1 || $score->bars[$bar + 1]['sectionLeader']) {
      $width = max($width, (int)ceil(RepeatPainter::width($score->sectionRepeats($bar),
        self::ROW_HEIGHT * PdfLayout::noteSize() / 100) + 16));
    }
    return min(self::WIDTH - 2 * self::SIDE_MARGIN - self::MARKER_WIDTH, $width);
  }
}
