<?php

namespace MADSCORE\Render;

use MADSCORE\Score\Score;

/** Draws a compact hollow beat symbol and changed BPM on the print marks row. */
final class TempoPainter {

  /** The first bar states the starting tempo; later bars print only changes. */
  public static function label(Score $score, int $bar): ?string {
    $tempo = $score->bars[$bar]['tempo'];
    return $bar === 0 || (float)$tempo !== (float)$score->bars[$bar - 1]['tempo'] ? $tempo : null;
  }

  /** Reserve enough horizontal room for the circle and a normal BPM label. */
  public static function width(string $tempo, bool $print = false): int {
    $font = $print ? PdfLayout::markFontSize() : 22;
    return (int)ceil(5 + $font * 0.76 + $font * strlen('=' . $tempo) * 0.72);
  }

  public static function draw(\FFI $cairo, \FFI\CData $context, float $left,
    float $right, string $tempo, bool $print = false): void {
    $cairo->cairo_save($context);
    try {
      $font = $print ? PdfLayout::markFontSize() : 22;
      $cairo->cairo_select_font_face($context, 'Sans', 0, 0);
      $cairo->cairo_set_font_size($context, $font);
      $extents = $cairo->new('cairo_text_extents_t');
      $cairo->cairo_text_extents($context, '0', \FFI::addr($extents));
      $radius = $extents->height / 2;
      $centerY = 69 + $extents->y_bearing + $radius;
      $width = min($right - $left, self::width($tempo, $print));
      $cairo->cairo_rectangle($context, $left, 40, $width, $print ? 44 : 36);
      $cairo->cairo_clip($context);
      $cairo->cairo_set_source_rgb($context, 0, 0, 0);
      $cairo->cairo_set_line_width($context, 1.5);
      $cairo->cairo_arc($context, $left + $radius + 1, $centerY,
        $radius, 0, 2 * M_PI);
      $cairo->cairo_stroke($context);
      $cairo->cairo_move_to($context, $left + 2 * $radius + 5, 69);
      $cairo->cairo_show_text($context, '=' . $tempo);
    } finally {
      $cairo->cairo_restore($context);
    }
  }
}
