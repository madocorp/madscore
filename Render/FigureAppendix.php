<?php

namespace MADSCORE\Render;

use MADSCORE\Score\Score;

/** Pack black-ink figure explanations into appendix columns and pages. */
final class FigureAppendix {
  private const LEFT = 110;
  private const TOP = 225;
  private const RIGHT = 110;
  private const BOTTOM = 110;
  private const COLUMN_GAP = 45;
  private const TILE_GAP = 35;
  private const TILE_PAD = 14;
  private const MARK_WIDTH = 135;
  private const MARK_FONT_SIZE = 38;
  private const PANEL_GAP = 32;
  private const NORMAL_ROW = 220;

  /** Place each figure below the previous one, then move right or turn a page. */
  public static function pages(FigureCatalog $catalog, int $top = self::TOP): array {
    $entries = $catalog->entries();
    if ($entries === []) {
      return [];
    }
    $pages = [];
    $page = [];
    $x = self::LEFT;
    $y = $top;
    $columnWidth = 0;
    $right = PagePainter::WIDTH - self::RIGHT;
    $bottom = PagePainter::HEIGHT - self::BOTTOM;
    foreach ($entries as $entry) {
      $spec = self::measure($entry);
      if ($page !== [] && $y + $spec['height'] > $bottom) {
        $x += $columnWidth + self::COLUMN_GAP;
        $y = $top;
        $columnWidth = 0;
      }
      if ($page !== [] && $x + $spec['width'] > $right) {
        $pages[] = $page;
        $page = [];
        $x = self::LEFT;
        $y = $top;
        $columnWidth = 0;
      }
      $page[] = ['entry' => $entry, 'spec' => $spec, 'x' => $x, 'y' => $y];
      $columnWidth = max($columnWidth, $spec['width']);
      $y += $spec['height'] + self::TILE_GAP;
    }
    if ($page !== []) {
      $pages[] = $page;
    }
    return $pages;
  }

  public static function drawPage(\FFI $cairo, \FFI\CData $context, array $tiles): void {
    $cairo->cairo_save($context);
    try {
      $cairo->cairo_set_source_rgb($context, 1, 1, 1);
      $cairo->cairo_paint($context);
      self::drawTiles($cairo, $context, $tiles);
    } finally {
      $cairo->cairo_restore($context);
    }
  }

  /** Add tiles to an already painted score page. */
  public static function drawTiles(\FFI $cairo, \FFI\CData $context, array $tiles): void {
    foreach ($tiles as $tile) {
      self::drawTile($cairo, $context, $tile);
    }
  }

  private static function measure(array $entry): array {
    $ornament = null;
    $tuplet = null;
    $error = $entry['error'];
    if ($error === null && isset($entry['types']['ornament'])) {
      if ($entry['ornamentError'] !== null) {
        $ornament = ['width' => min(1500, max(660, strlen($entry['ornamentError']) * 17)),
          'height' => 130, 'error' => $entry['ornamentError']];
      } else {
        $duration = max(array_map(static fn (array $note): int => $note['time'] + $note['length'],
          $entry['notes']));
        $step = self::NORMAL_ROW * PdfLayout::noteSize() / 100 / 12;
        $unit = self::NORMAL_ROW / 6 * PdfLayout::beatSize() / 100;
        $ornament = ['width' => $duration * $unit + 2 * $step,
          'height' => max(130, 6 * $step + 16), 'step' => $step, 'unit' => $unit,
          'duration' => $duration];
      }
    }
    if ($error === null && isset($entry['types']['tuplet'])) {
      $tuplet = self::tupletMeasure($entry['score']);
    }
    $panels = array_values(array_filter([$ornament, $tuplet]));
    if ($error !== null) {
      $figureWidth = min(1600, max(680, strlen($error) * 18));
      $figureHeight = 120;
    } else {
      $figureWidth = max(array_column($panels, 'width'));
      $figureHeight = array_sum(array_column($panels, 'height'))
        + (count($panels) === 2 ? self::PANEL_GAP : 0);
    }
    $maxFigureWidth = PagePainter::WIDTH - self::LEFT - self::RIGHT
      - self::MARK_WIDTH - 2 * self::TILE_PAD;
    $maxFigureHeight = PagePainter::HEIGHT - self::TOP - self::BOTTOM
      - 2 * self::TILE_PAD;
    $scale = min(1, $maxFigureWidth / max(1, $figureWidth),
      $maxFigureHeight / max(1, $figureHeight));
    return ['width' => (int)ceil(self::MARK_WIDTH + $figureWidth * $scale + 2 * self::TILE_PAD),
      'height' => (int)ceil(max(100, $figureHeight * $scale + 2 * self::TILE_PAD)),
      'scale' => $scale, 'ornament' => $ornament, 'tuplet' => $tuplet,
      'figureHeight' => $figureHeight];
  }

  private static function tupletMeasure(Score $score): array {
    $bands = [];
    $lastTime = 0;
    foreach ($score->notes as $note) {
      foreach (BarPainter::visibleOctaves($note) as $band) {
        $bands[$band] = true;
      }
      $lastTime = max($lastTime, $score->start($note['bar']) + $note['slot'] + $note['length']);
    }
    $bands = array_map('intval', array_keys($bands));
    rsort($bands, SORT_NUMERIC);
    $barCount = 1;
    while ($barCount < count($score->bars) && $score->start($barCount) < $lastTime) {
      $barCount++;
    }
    $rowHeight = max(1, (int)round(self::NORMAL_ROW * PdfLayout::noteSize() / 100));
    $unit = self::NORMAL_ROW / 6 * PdfLayout::beatSize() / 100;
    $widths = [];
    for ($bar = 0; $bar < $barCount; $bar++) {
      $widths[] = max(1, (int)round(2 * $score->beats($bar) * $unit));
    }
    return ['width' => 45 + array_sum($widths), 'height' => count($bands) * $rowHeight,
      'bands' => $bands, 'widths' => $widths, 'rowHeight' => $rowHeight];
  }

  private static function drawTile(\FFI $cairo, \FFI\CData $context, array $tile): void {
    $entry = $tile['entry'];
    $spec = $tile['spec'];
    $x = $tile['x'];
    $y = $tile['y'];
    $markY = $y + $spec['height'] / 2;
    $starRadius = self::MARK_FONT_SIZE / 2;
    FigurePainter::star($cairo, $context, $x + 46, $markY, null, $starRadius);
    self::text($cairo, $context, (string)$entry['number'],
      $x + 46 + $starRadius + 2, $markY + self::MARK_FONT_SIZE * 0.32,
      self::MARK_FONT_SIZE, true);
    $figureX = $x + self::MARK_WIDTH + self::TILE_PAD;
    $figureY = $y + self::TILE_PAD
      + ($spec['height'] - 2 * self::TILE_PAD - $spec['figureHeight'] * $spec['scale']) / 2;
    if ($entry['error'] !== null) {
      self::text($cairo, $context, $entry['error'], $figureX,
        $y + $spec['height'] / 2 + 10, 30);
      return;
    }
    if ($spec['ornament'] !== null) {
      $cairo->cairo_save($context);
      $cairo->cairo_translate($context, $figureX, $figureY);
      $cairo->cairo_scale($context, $spec['scale'], $spec['scale']);
      if (isset($spec['ornament']['error'])) {
        self::text($cairo, $context, $spec['ornament']['error'], 0,
          $spec['ornament']['height'] / 2 + 10, 30);
      } else {
        self::drawOrnament($cairo, $context, $entry['notes'], $spec['ornament']);
      }
      $cairo->cairo_restore($context);
      $figureY += $spec['ornament']['height'] * $spec['scale']
        + ($spec['tuplet'] !== null ? self::PANEL_GAP * $spec['scale'] : 0);
    }
    if ($spec['tuplet'] !== null) {
      $cairo->cairo_save($context);
      $cairo->cairo_translate($context, $figureX, $figureY);
      $cairo->cairo_scale($context, $spec['scale'], $spec['scale']);
      self::drawTuplet($cairo, $context, $entry['score'], $spec['tuplet']);
      $cairo->cairo_restore($context);
    }
  }

  /** Draw only the marked-pitch line and hollow notes at relative semitone offsets. */
  private static function drawOrnament(\FFI $cairo, \FFI\CData $context, array $notes,
    array $panel): void {
    $step = $panel['step'];
    $unit = $panel['unit'];
    $referenceY = $panel['height'] / 2;
    $left = $step;
    $right = $left + $panel['duration'] * $unit;
    $cairo->cairo_set_source_rgb($context, 0, 0, 0);
    $cairo->cairo_set_line_width($context, 2);
    for ($x = 0; $x < $right + $step; $x += 1.5 * $step) {
      $cairo->cairo_move_to($context, $x, $referenceY);
      $cairo->cairo_line_to($context, min($right + $step, $x + 0.8 * $step), $referenceY);
      $cairo->cairo_stroke($context);
    }
    foreach ($notes as $note) {
      self::hollowNote($cairo, $context, $left, $unit, $step,
        $referenceY - $note['offset'] * $step, $note['time'], $note['length']);
    }
  }

  /** Match the main score's circle, half note, duration body, and beat ticks. */
  private static function hollowNote(\FFI $cairo, \FFI\CData $context, float $left,
    float $unit, float $step, float $y, int $time, int $length): void {
    $size = 2 * $step;
    $outline = max(1, $size / 8);
    $radius = max(0.25, ($size - $outline) / 2);
    $cairo->cairo_new_path($context);
    if ($length === 1) {
      $x = $left + ($time + 0.5) * $unit;
      $cairo->cairo_move_to($context, $x, $y - $radius);
      $cairo->cairo_arc($context, $x, $y, $radius, -M_PI / 2, M_PI / 2);
    } else {
      $x1 = $left + ($time + 1) * $unit;
      $x2 = $left + ($time + $length - 1) * $unit;
      if ($x1 === $x2) {
        $cairo->cairo_arc($context, $x1, $y, $radius, 0, 2 * M_PI);
      } else {
        $cairo->cairo_move_to($context, $x1, $y - $radius);
        $cairo->cairo_line_to($context, $x2, $y - $radius);
        $cairo->cairo_arc($context, $x2, $y, $radius, -M_PI / 2, M_PI / 2);
        $cairo->cairo_line_to($context, $x1, $y + $radius);
        $cairo->cairo_arc($context, $x1, $y, $radius, M_PI / 2, 3 * M_PI / 2);
      }
    }
    $cairo->cairo_close_path($context);
    $cairo->cairo_set_source_rgb($context, 1, 1, 1);
    $cairo->cairo_fill_preserve($context);
    $cairo->cairo_set_source_rgb($context, 0, 0, 0);
    $cairo->cairo_set_line_width($context, $outline);
    $cairo->cairo_stroke($context);
    if ($length > 1) {
      for ($tick = $time + 2; $tick < $time + $length; $tick += 2) {
        $x = $left + $tick * $unit;
        $cairo->cairo_move_to($context, $x, $y - $size / 3);
        $cairo->cairo_line_to($context, $x, $y + $size / 3);
        $cairo->cairo_stroke($context);
      }
    }
  }

  /** Render only motif octave rows, at their original pitches and rhythm. */
  private static function drawTuplet(\FFI $cairo, \FFI\CData $context, Score $motif,
    array $panel): void {
    $score = clone $motif;
    foreach ($score->melodies as &$melody) {
      $melody['color'] = '#000000';
    }
    unset($melody);
    $rowHeight = $panel['rowHeight'];
    $rows = [];
    foreach ($panel['bands'] as $index => $band) {
      $rows[$band] = ['top' => $index * $rowHeight, 'height' => $rowHeight];
    }
    Prescription::drawMarkers($cairo, $context, $rows, 20, true);
    $x = 45;
    foreach ($panel['widths'] as $bar => $barWidth) {
      foreach ($panel['bands'] as $index => $band) {
        $cairo->cairo_save($context);
        $cairo->cairo_translate($context, $x, $index * $rowHeight);
        $above = max(2 * $rowHeight / 12,
          PdfLayout::noteSize() < 100 ? 2 * PdfLayout::markFontSize() : 0);
        $cairo->cairo_rectangle($context, 0, -$above,
          $barWidth, $rowHeight + $above);
        $cairo->cairo_clip($context);
        BarPainter::draw($cairo, $context, $score, $bar, $band, $barWidth, $rowHeight,
          endBar: $bar === count($panel['widths']) - 1,
          paintBackground: false, staffColor: '#000000', printMarks: true);
        $cairo->cairo_restore($context);
      }
      $x += $barWidth;
    }
  }

  private static function text(\FFI $cairo, \FFI\CData $context, string $value,
    float $x, float $y, float $size, bool $bold = false): void {
    $cairo->cairo_set_source_rgb($context, 0, 0, 0);
    $cairo->cairo_select_font_face($context, 'Sans', 0, $bold ? 1 : 0);
    $cairo->cairo_set_font_size($context, max($size, PdfLayout::markFontSize()));
    $cairo->cairo_move_to($context, $x, $y);
    $cairo->cairo_show_text($context, $value);
  }
}
